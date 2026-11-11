<?php
require_once 'includes/auth.php';
require_once 'includes/functions.php';

requireAdmin();

$success = '';
$error = '';

$pdo = null;
try {
    $pdo = getDBConnection();
    $stmt = $pdo->query("SELECT * FROM mods WHERE status = 'active' ORDER BY name");
    $mods = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $error = 'Database connection failed: ' . $e->getMessage();
    $mods = [];
}

if ($_POST) {
    verify_csrf();

    try {
        if (!$pdo) {
            $error = 'Database connection is not available.';
        } else {
            $modId = $_POST['mod_id'] ?? '';
            $keyType = $_POST['key_type'] ?? 'single';
            $duration = (int)($_POST['duration'] ?? 0);
            $durationType = $_POST['duration_type'] ?? 'days';
            $price = (float)($_POST['price'] ?? 0);
    
        if ($keyType === 'single') {
            $licenseKey = trim($_POST['license_key'] ?? '');
            
            if (empty($modId) || empty($licenseKey) || $duration <= 0 || $price <= 0) {
                $error = 'Please fill in all required fields';
            } else {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM license_keys WHERE license_key = ?");
                $stmt->execute([$licenseKey]);
                
                if ($stmt->fetchColumn() > 0) {
                    $error = 'License key already exists';
                } else {
                    $stmt = $pdo->prepare("INSERT INTO license_keys (mod_id, license_key, duration, duration_type, price) VALUES (?, ?, ?, ?, ?)");
                    if ($stmt->execute([$modId, $licenseKey, $duration, $durationType, $price])) {
                        $success = 'License key added successfully!';
                    } else {
                        $error = 'Failed to add license key';
                    }
                }
            }
        } else { // Bulk keys
            $licenseKeys = trim($_POST['bulk_keys'] ?? '');
            
            if (empty($modId) || empty($licenseKeys) || $duration <= 0 || $price <= 0) {
                $error = 'Please fill in all required fields';
            } else {
                $keys = array_filter(array_map('trim', explode("\n", $licenseKeys)));
                $addedCount = 0;
                $duplicateCount = 0;
                
                $pdo->beginTransaction();
                
                try {
                    foreach ($keys as $key) {
                        if (empty($key)) continue;
                        
                        $stmt = $pdo->prepare("SELECT COUNT(*) FROM license_keys WHERE license_key = ?");
                        $stmt->execute([$key]);
                        
                        if ($stmt->fetchColumn() > 0) {
                            $duplicateCount++;
                            continue;
                        }
                        
                        $stmt = $pdo->prepare("INSERT INTO license_keys (mod_id, license_key, duration, duration_type, price) VALUES (?, ?, ?, ?, ?)");
                        if ($stmt->execute([$modId, $key, $duration, $durationType, $price])) {
                            $addedCount++;
                        }
                    }
                    
                    $pdo->commit();
                    $success = "Added $addedCount license keys successfully!";
                    if ($duplicateCount > 0) {
                        $success .= " $duplicateCount duplicate keys were skipped.";
                    }
                    } catch (Exception $e) {
                        $pdo->rollBack();
                        $error = 'Transaction failed: ' . $e->getMessage();
                    }
                }
            }
        }
    } catch (Exception $e) {
        $error = 'Error processing request: ' . $e->getMessage();
    }
}

$page_title = "Add License - " . SITE_NAME . " " . SITE_SUBTITLE;
require_once 'includes/header.php';
?>

<!-- Header -->
<div class="mb-8 flex items-center justify-between">
    <div>
        <h2 class="text-2xl font-bold tracking-tight text-glow-primary">Add License Keys</h2>
        <p class="text-sm text-brand-muted mt-1 font-medium">Add single or bulk license keys for active mod packages.</p>
    </div>
    <a href="licenses" class="inline-flex items-center justify-center px-3.5 py-2 bg-brand-surface border border-brand-border text-xs font-semibold rounded-xl hover:text-brand-primary hover:border-brand-primary/40 transition-colors space-x-1.5">
        <i class="fa-solid fa-arrow-left-long"></i>
        <span>View Keys List</span>
    </a>
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

<!-- Form Card -->
<div class="max-w-xl bg-white border border-gray-200 rounded-xl shadow-sm p-6 dark:bg-neutral-900 dark:border-neutral-700">
    <h3 class="text-base font-bold mb-4 flex items-center space-x-2">
        <i class="fa-solid fa-key text-brand-primary"></i>
        <span>License Configuration</span>
    </h3>
    
    <form method="POST" class="space-y-4">
    <?php echo csrf_field(); ?>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
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
                <label for="key_type" class="block text-xs font-semibold text-brand-muted mb-1.5">Input Mode *</label>
                <select id="key_type" name="key_type" onchange="toggleInputMode(this.value)" required class="w-full px-4 py-2.5 bg-brand-bg/50 border border-brand-border rounded-xl text-sm focus:outline-none focus:border-brand-primary">
                    <option value="single">Single Key</option>
                    <option value="bulk">Bulk Import</option>
                </select>
            </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div>
                <label for="duration" class="block text-xs font-semibold text-brand-muted mb-1.5">Duration *</label>
                <input type="number" id="duration" name="duration" min="1" required
                       class="w-full px-4 py-2.5 bg-brand-bg/50 border border-brand-border rounded-xl text-sm focus:outline-none focus:border-brand-primary"
                       placeholder="e.g. 7">
            </div>

            <div>
                <label for="duration_type" class="block text-xs font-semibold text-brand-muted mb-1.5">Type *</label>
                <select id="duration_type" name="duration_type" required class="w-full px-4 py-2.5 bg-brand-bg/50 border border-brand-border rounded-xl text-sm focus:outline-none focus:border-brand-primary">
                    <option value="minutes">Minutes</option>
                    <option value="hours">Hours</option>
                    <option value="days" selected>Days</option>
                    <option value="months">Months</option>
                    <option value="lifetime">Lifetime</option>
                </select>
            </div>

            <div>
                <label for="price" class="block text-xs font-semibold text-brand-muted mb-1.5">Retail Price (Display Only) *</label>
                <input type="number" step="0.01" id="price" name="price" min="0" required
                       class="w-full px-4 py-2.5 bg-brand-bg/50 border border-brand-border rounded-xl text-sm focus:outline-none focus:border-brand-primary"
                       placeholder="e.g. 350.00">
            </div>
        </div>

        <!-- Single Key Input -->
        <div id="single-key-container">
            <label for="license_key" class="block text-xs font-semibold text-brand-muted mb-1.5">License Key *</label>
            <input type="text" id="license_key" name="license_key"
                   class="w-full px-4 py-2.5 bg-brand-bg/50 border border-brand-border rounded-xl text-sm focus:outline-none focus:border-brand-primary placeholder-brand-muted/40 font-mono"
                   placeholder="e.g. KEY-A1B2-C3D4">
        </div>

        <!-- Bulk Keys Input -->
        <div id="bulk-keys-container" class="hidden">
            <label for="bulk_keys" class="block text-xs font-semibold text-brand-muted mb-1.5">Bulk License Keys * (One key per line)</label>
            <textarea id="bulk_keys" name="bulk_keys" rows="6"
                      class="w-full px-4 py-2.5 bg-brand-bg/50 border border-brand-border rounded-xl text-sm focus:outline-none focus:border-brand-primary placeholder-brand-muted/40 font-mono"
                      placeholder="KEY-1111-2222&#10;KEY-3333-4444&#10;KEY-5555-6666"></textarea>
        </div>

        <button type="submit" class="w-full py-3 bg-gradient-to-r from-brand-primary to-brand-secondary hover:opacity-90 active:scale-[0.98] transition-all duration-300 rounded-xl text-sm font-bold text-white shadow-glow flex items-center justify-center space-x-2">
            <i class="fa-solid fa-key"></i>
            <span>Add Key(s)</span>
        </button>
    </form>
</div>

<script>
function toggleInputMode(value) {
    const singleContainer = document.getElementById('single-key-container');
    const bulkContainer = document.getElementById('bulk-keys-container');
    const singleInput = document.getElementById('license_key');
    const bulkInput = document.getElementById('bulk_keys');
    
    if (value === 'single') {
        singleContainer.classList.remove('hidden');
        bulkContainer.classList.add('hidden');
        singleInput.setAttribute('required', 'required');
        bulkInput.removeAttribute('required');
    } else {
        singleContainer.classList.add('hidden');
        bulkContainer.classList.remove('hidden');
        singleInput.removeAttribute('required');
        bulkInput.setAttribute('required', 'required');
    }
}
</script>

<?php
require_once 'includes/footer.php';
?>
