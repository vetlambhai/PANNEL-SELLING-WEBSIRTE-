<?php
require_once 'includes/auth.php';

// Check if user is logged in and is admin
requireAdmin();

$pdo = getDBConnection();
$success = '';
$error = '';

// Get all mods for dropdown
$mods = [];
try {
    $stmt = $pdo->query("SELECT * FROM mods WHERE status = 'active' ORDER BY name");
    $mods = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $error = 'Failed to load mods';
}

// Get uploaded APKs
$uploadedApks = [];
try {
    $stmt = $pdo->query("SELECT ma.*, m.name as mod_name FROM mod_apks ma 
                        LEFT JOIN mods m ON ma.mod_id = m.id 
                        ORDER BY ma.uploaded_at DESC");
    $uploadedApks = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Ignore errors for uploaded APKs
}

// Handle file upload
if ($_POST && isset($_FILES['apk_file'])) {

    $modId = $_POST['mod_id'] ?? '';
    $file = $_FILES['apk_file'];
    
    if (empty($modId)) {
        $error = 'Please select a mod';
    } elseif ($file['error'] !== UPLOAD_ERR_OK) {
        $error = 'File upload error: ' . $file['error'];
    } elseif ($file['size'] > 100 * 1024 * 1024) { // 100MB limit
        $error = 'File size too large. Maximum 100MB allowed.';
    } else {
        // Check file extension
        $fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        // Verify MIME type to prevent malicious code execution
        $isValidMime = true;
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            
            $allowedMimes = [
                'application/vnd.android.package-archive',
                'application/octet-stream',
                'application/zip',
                'application/x-zip-compressed'
            ];
            if (!in_array($mime, $allowedMimes)) {
                $isValidMime = false;
            }
        }

        if ($fileExtension !== 'apk' || !$isValidMime) {
            $error = 'Please upload a valid APK file (.apk extension and valid android package format required)';
        } else {
            // Create uploads directory if it doesn't exist
            $uploadDir = 'uploads/apks/';
            if (!file_exists($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            
            // Generate unique filename
            $fileName = uniqid() . '_' . $file['name'];
            $filePath = $uploadDir . $fileName;
            
            // Move uploaded file
            if (move_uploaded_file($file['tmp_name'], $filePath)) {
                try {
                    $stmt = $pdo->prepare("INSERT INTO mod_apks (mod_id, file_name, file_path, file_size) VALUES (?, ?, ?, ?)");
                    if ($stmt->execute([$modId, $file['name'], $filePath, $file['size']])) {
                        $success = 'APK uploaded successfully!';
                        // Refresh the uploaded APKs list
                        $stmt = $pdo->query("SELECT ma.*, m.name as mod_name FROM mod_apks ma 
                                            LEFT JOIN mods m ON ma.mod_id = m.id 
                                            ORDER BY ma.uploaded_at DESC");
                        $uploadedApks = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    } else {
                        $error = 'Failed to save APK information to database';
                        unlink($filePath); // Delete uploaded file
                    }
                } catch (Exception $e) {
                    $error = 'Database error: ' . $e->getMessage();
                    unlink($filePath); // Delete uploaded file
                }
            } else {
                $error = 'Failed to upload file. Please check directory permissions.';
            }
        }
    }
}

// Helper function
function formatDate($date) {
    if (empty($date)) return 'N/A';
    $timestamp = strtotime($date);
    if ($timestamp === false) return 'Invalid Date';
    return date('d M Y H:i', $timestamp);
}

function formatBytes($bytes, $precision = 2) {
    $units = array('B', 'KB', 'MB', 'GB', 'TB');
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}

$page_title = "Upload Mod APK - " . SITE_NAME . " " . SITE_SUBTITLE;
require_once 'includes/header.php';
?>

<!-- Header -->
<div class="mb-8 flex items-center justify-between">
    <div>
        <h2 class="text-2xl font-bold tracking-tight text-glow-primary">Upload Mod APK</h2>
        <p class="text-sm text-brand-muted mt-1 font-medium">Distribute APK updates for active mod packages.</p>
    </div>
</div>

<!-- Alerts -->
<?php if ($success): ?>
    <div class="bg-brand-success/10 border border-brand-success/20 text-brand-success text-xs rounded-xl p-3.5 mb-6 flex items-center space-x-2.5">
        <i class="fa-solid fa-circle-check text-base"></i>
        <span><?php echo htmlspecialchars($success); ?></span>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="bg-brand-error/10 border border-brand-error/20 text-brand-error text-xs rounded-xl p-3.5 mb-6 flex items-center space-x-2.5">
        <i class="fa-solid fa-circle-exclamation text-base"></i>
        <span><?php echo htmlspecialchars($error); ?></span>
    </div>
<?php endif; ?>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
    <!-- Form Card -->
    <div class="bg-white border border-gray-200 rounded-xl shadow-sm p-6 dark:bg-neutral-900 dark:border-neutral-700 h-fit">
        <h3 class="text-base font-bold mb-4 flex items-center space-x-2">
            <i class="fa-solid fa-cloud-arrow-up text-brand-primary"></i>
            <span>Upload File</span>
        </h3>
        
        <form method="POST" enctype="multipart/form-data" class="space-y-4">
    <?php echo csrf_field(); ?>
            <div>
                <label for="mod_id" class="block text-xs font-semibold text-brand-muted mb-1.5">Select Mod *</label>
                <select id="mod_id" name="mod_id" required class="w-full px-4 py-2.5 bg-brand-bg/50 border border-brand-border rounded-xl text-sm focus:outline-none focus:border-brand-primary">
                    <option value="">Choose a mod...</option>
                    <?php foreach ($mods as $mod): ?>
                        <option value="<?php echo $mod['id']; ?>"><?php echo htmlspecialchars($mod['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label for="apk_file" class="block text-xs font-semibold text-brand-muted mb-1.5">APK File *</label>
                <input type="file" id="apk_file" name="apk_file" accept=".apk" required
                       class="w-full text-xs text-brand-muted file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-brand-primary/10 file:text-brand-primary hover:file:bg-brand-primary/20 cursor-pointer">
            </div>

            <button type="submit" class="w-full py-3 bg-gradient-to-r from-brand-primary to-brand-secondary hover:opacity-90 active:scale-[0.98] transition-all duration-300 rounded-xl text-sm font-bold text-white shadow-glow flex items-center justify-center space-x-2">
                <i class="fa-solid fa-upload"></i>
                <span>Upload APK</span>
            </button>
        </form>
    </div>

    <!-- Uploaded List Card -->
    <div class="lg:col-span-2 bg-white border border-gray-200 rounded-xl shadow-sm p-6 dark:bg-neutral-900 dark:border-neutral-700">
        <h3 class="text-base font-bold mb-6 flex items-center space-x-2">
            <i class="fa-solid fa-rectangle-list text-brand-secondary"></i>
            <span>Uploaded APK History</span>
        </h3>
        
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse">
                <thead>
                    <tr class="border-b border-brand-border/60 text-brand-muted text-xs uppercase font-semibold">
                        <th class="pb-3">Mod Name</th>
                        <th class="pb-3">File Name</th>
                        <th class="pb-3">Size</th>
                        <th class="pb-3 text-right">Upload Date</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-brand-border/40 text-sm">
                    <?php if (empty($uploadedApks)): ?>
                    <tr>
                        <td colspan="4" class="text-center text-brand-muted py-12">
                            <i class="fa-solid fa-circle-info text-3xl mb-2 opacity-50 block"></i>
                            No APKs uploaded yet.
                        </td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($uploadedApks as $apk): ?>
                        <tr>
                            <td class="py-3.5 font-semibold text-brand-text"><?php echo htmlspecialchars($apk['mod_name']); ?></td>
                            <td class="py-3.5 font-mono text-xs text-brand-muted max-w-xs truncate"><?php echo htmlspecialchars($apk['file_name']); ?></td>
                            <td class="py-3.5 text-xs text-brand-text"><?php echo formatBytes($apk['file_size']); ?></td>
                            <td class="py-3.5 text-right text-brand-muted text-xs"><?php echo formatDate($apk['uploaded_at']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
require_once 'includes/footer.php';
?>
