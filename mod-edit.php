<?php
require_once 'includes/auth.php';
require_once 'includes/functions.php';
require_once 'includes/reseller.php';

requireAdmin();

ensureVideoUrlColumn();

$success = '';
$error = '';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) {
    header('Location: mods.php');
    exit;
}

$pdo = getDBConnection();

// Fetch existing mod
$stmt = $pdo->prepare("SELECT * FROM mods WHERE id = ?");
$stmt->execute([$id]);
$mod = $stmt->fetch();

if (!$mod) {
    header('Location: mods.php');
    exit;
}

if ($_POST) {
    verify_csrf();
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $version = trim($_POST['version'] ?? '');
    $features = trim($_POST['features'] ?? '');
    $purchase_link = trim($_POST['purchase_link'] ?? '');
    $video_url = trim($_POST['video_url'] ?? '');
    $provider_pid = trim($_POST['provider_pid'] ?? '');
    $requires_device_id = isset($_POST['requires_device_id']) ? 1 : 0;
    $btn1_label = trim($_POST['btn1_label'] ?? '');
    $btn1_link = trim($_POST['btn1_link'] ?? '');
    $btn2_label = trim($_POST['btn2_label'] ?? '');
    $btn2_link = trim($_POST['btn2_link'] ?? '');

    if (empty($name)) {
        $error = 'Mod name is required';
    } elseif (empty($video_url)) {
        $error = 'Demo Video URL is required. Please paste a direct video link (.mp4).';
    } elseif (empty($provider_pid)) {
        $error = 'Provider PID is required. This is the Product ID from the KeysMaster reseller API.';
    } else {
        $stmt = $pdo->prepare("UPDATE mods SET name = ?, description = ?, image_url = ?, video_url = ?, version = ?, features = ?, purchase_link = ?, provider_pid = ?, requires_device_id = ?, btn1_label = ?, btn1_link = ?, btn2_label = ?, btn2_link = ? WHERE id = ?");

        if ($stmt->execute([$name, $description, null, $video_url, $version, $features, $purchase_link, $provider_pid, $requires_device_id, $btn1_label, $btn1_link, $btn2_label, $btn2_link, $id])) {
            $success = 'Mod updated successfully!';

            // Refresh data
            $stmt = $pdo->prepare("SELECT * FROM mods WHERE id = ?");
            $stmt->execute([$id]);
            $mod = $stmt->fetch();
        } else {
            $error = 'Failed to update mod. Please try again.';
        }
    }
}

$page_title = "Edit Mod - " . SITE_NAME . " " . SITE_SUBTITLE;
require_once 'includes/header.php';
?>

<!-- Header -->
<div class="mb-8 flex items-center justify-between">
    <div>
        <h2 class="text-2xl font-bold tracking-tight text-glow-primary">Edit Mod</h2>
        <p class="text-sm text-brand-muted mt-1 font-medium">Update the details for <?php echo htmlspecialchars($mod['name']); ?>.</p>
    </div>
    <a href="mods.php" class="inline-flex items-center justify-center px-3.5 py-2 bg-brand-surface border border-brand-border text-xs font-semibold rounded-xl hover:text-brand-primary hover:border-brand-primary/40 transition-colors space-x-1.5">
        <i class="fa-solid fa-arrow-left-long"></i>
        <span>Back to Mods</span>
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
        <i class="fa-solid fa-pen text-brand-primary"></i>
        <span>Mod Details</span>
    </h3>

    <form method="POST" class="space-y-4">
    <?php echo csrf_field(); ?>
        <div>
            <label for="name" class="block text-xs font-semibold text-brand-muted mb-1.5">Mod Name *</label>
            <input type="text" id="name" name="name" required
                   value="<?php echo htmlspecialchars($_POST['name'] ?? $mod['name'] ?? ''); ?>"
                   class="w-full px-4 py-2.5 bg-brand-bg/50 border border-brand-border rounded-xl text-sm focus:outline-none focus:border-brand-primary focus:ring-1 focus:ring-brand-primary transition-all duration-300 placeholder-brand-muted/40">
        </div>

        <div>
            <label for="provider_pid" class="block text-xs font-semibold text-brand-muted mb-1.5">Provider PID (Reseller Product ID) *</label>
            <input type="text" id="provider_pid" name="provider_pid" required
                   value="<?php echo htmlspecialchars($_POST['provider_pid'] ?? $mod['provider_pid'] ?? ''); ?>"
                   class="w-full px-4 py-2.5 bg-brand-bg/50 border border-brand-border rounded-xl text-sm focus:outline-none focus:border-brand-primary focus:ring-1 focus:ring-brand-primary transition-all duration-300 placeholder-brand-muted/40"
                   placeholder="e.g. 133">
            <p class="text-[10px] text-brand-muted mt-1.5">The Product ID from the KeysMaster reseller API. Keys are purchased from the provider using this PID. <span class="text-brand-error font-semibold">Required.</span></p>
        </div>

        <div class="flex items-start gap-3 p-3.5 bg-brand-bg/50 border border-brand-border rounded-xl">
            <input type="checkbox" id="requires_device_id" name="requires_device_id" value="1" <?php echo (isset($_POST['requires_device_id']) || (int)($mod['requires_device_id'] ?? 0) === 1) ? 'checked' : ''; ?> class="mt-0.5 w-4 h-4 accent-brand-primary">
            <div>
                <label for="requires_device_id" class="text-xs font-semibold text-brand-text block">Requires Device ID (Android ID)</label>
                <p class="text-[10px] text-brand-muted mt-0.5">Check this for BALA_MOD / device-bound products. When enabled, users MUST enter their Android Device ID at purchase time — the key will be bound to that device.</p>
            </div>
        </div>

        <div>
            <label for="version" class="block text-xs font-semibold text-brand-muted mb-1.5">Version</label>
            <input type="text" id="version" name="version"
                   value="<?php echo htmlspecialchars($_POST['version'] ?? $mod['version'] ?? ''); ?>"
                   class="w-full px-4 py-2.5 bg-brand-bg/50 border border-brand-border rounded-xl text-sm focus:outline-none focus:border-brand-primary focus:ring-1 focus:ring-brand-primary transition-all duration-300 placeholder-brand-muted/40">
        </div>

        <div>
            <label for="video_url" class="block text-xs font-semibold text-brand-muted mb-1.5">Demo Video URL *</label>
            <?php $current_video = $_POST['video_url'] ?? $mod['video_url'] ?? ''; ?>
            <input type="url" id="video_url" name="video_url" required
                   value="<?php echo htmlspecialchars($current_video); ?>"
                   class="w-full px-4 py-2.5 bg-brand-bg/50 border border-brand-border rounded-xl text-sm focus:outline-none focus:border-brand-primary focus:ring-1 focus:ring-brand-primary transition-all duration-300 placeholder-brand-muted/40"
                   placeholder="https://adminpanels.shop/uploads/videos/demo.mp4">
            <?php if (!empty($current_video)): ?>
                <div class="mt-3 rounded-xl overflow-hidden border border-brand-border bg-brand-bg/30">
                    <video controls preload="metadata" class="w-full max-h-40">
                        <source src="<?php echo htmlspecialchars($current_video); ?>" type="video/mp4">
                        Your browser does not support the video tag.
                    </video>
                </div>
                <p class="text-[10px] text-brand-muted mt-1.5">Current demo video preview above. Change the URL to update. <span class="text-brand-error font-semibold">Required.</span></p>
            <?php else: ?>
                <p class="text-[10px] text-brand-muted mt-1.5">Paste a direct video link (.mp4, .webm). This demo video will be shown on the store page. <span class="text-brand-error font-semibold">Required.</span></p>
            <?php endif; ?>
        </div>

        <div>
            <label for="description" class="block text-xs font-semibold text-brand-muted mb-1.5">Product Description (HTML code)</label>
            <textarea id="description" name="description" rows="8"
                      class="w-full px-4 py-2.5 bg-brand-bg/50 border border-brand-border rounded-xl text-sm font-mono focus:outline-none focus:border-brand-primary focus:ring-1 focus:ring-brand-primary transition-all duration-300 placeholder-brand-muted/40"
                      placeholder="Enter HTML code for the product description. e.g.&#10;<p>This mod includes <b>ESP</b>, <b>Aimbot</b> and <b>Anti-Ban</b>.</p>&#10;<ul><li>Feature one</li><li>Feature two</li></ul>"><?php echo htmlspecialchars($_POST['description'] ?? $mod['description'] ?? ''); ?></textarea>
            <p class="text-[10px] text-brand-muted mt-1.5">Yahan <span class="font-semibold text-brand-primary">HTML code</span> daalo (product description). Jo HTML yahan likhoge wahi user website par description ke roop mein dikhega.</p>
        </div>

        <div>
            <label for="features" class="block text-xs font-semibold text-brand-muted mb-1.5">Key Features (Comma or Newline separated)</label>
            <textarea id="features" name="features" rows="4"
                      class="w-full px-4 py-2.5 bg-brand-bg/50 border border-brand-border rounded-xl text-sm focus:outline-none focus:border-brand-primary focus:ring-1 focus:ring-brand-primary transition-all duration-300 placeholder-brand-muted/40"><?php echo htmlspecialchars($_POST['features'] ?? $mod['features'] ?? ''); ?></textarea>
        </div>

        <div>
            <label for="purchase_link" class="block text-xs font-semibold text-brand-muted mb-1.5">Custom Purchase Link (Optional)</label>
            <input type="url" id="purchase_link" name="purchase_link"
                   value="<?php echo htmlspecialchars($_POST['purchase_link'] ?? $mod['purchase_link'] ?? ''); ?>"
                   class="w-full px-4 py-2.5 bg-brand-bg/50 border border-brand-border rounded-xl text-sm focus:outline-none focus:border-brand-primary focus:ring-1 focus:ring-brand-primary transition-all duration-300 placeholder-brand-muted/40"
                   placeholder="e.g. https://t.me/YOUR_USERNAME or Sellix Link">
        </div>

        <!-- Configurable Telegram Buttons (shown under mod description on store) -->
        <div class="pt-2 border-t border-brand-border/60">
            <div class="flex items-center gap-2 mb-3">
                <i class="fa-solid fa-paper-plane text-brand-primary"></i>
                <span class="text-xs font-bold text-brand-text">Telegram Buttons (shown under description)</span>
            </div>

            <div class="space-y-3">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label for="btn1_label" class="block text-[10px] font-semibold text-brand-muted mb-1.5">Button 1 Name</label>
                        <input type="text" id="btn1_label" name="btn1_label"
                               value="<?php echo htmlspecialchars($_POST['btn1_label'] ?? $mod['btn1_label'] ?? 'CHECK UPDATE FILE'); ?>"
                               class="w-full px-4 py-2.5 bg-brand-bg/50 border border-brand-border rounded-xl text-sm focus:outline-none focus:border-brand-primary focus:ring-1 focus:ring-brand-primary transition-all duration-300 placeholder-brand-muted/40"
                               placeholder="e.g. CHECK UPDATE FILE">
                    </div>
                    <div>
                        <label for="btn1_link" class="block text-[10px] font-semibold text-brand-muted mb-1.5">Button 1 Telegram Link</label>
                        <input type="url" id="btn1_link" name="btn1_link"
                               value="<?php echo htmlspecialchars($_POST['btn1_link'] ?? $mod['btn1_link'] ?? ''); ?>"
                               class="w-full px-4 py-2.5 bg-brand-bg/50 border border-brand-border rounded-xl text-sm focus:outline-none focus:border-brand-primary focus:ring-1 focus:ring-brand-primary transition-all duration-300 placeholder-brand-muted/40"
                               placeholder="e.g. https://t.me/yourchannel">
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label for="btn2_label" class="block text-[10px] font-semibold text-brand-muted mb-1.5">Button 2 Name</label>
                        <input type="text" id="btn2_label" name="btn2_label"
                               value="<?php echo htmlspecialchars($_POST['btn2_label'] ?? $mod['btn2_label'] ?? 'CHECK VIDEO / FEEDBACK'); ?>"
                               class="w-full px-4 py-2.5 bg-brand-bg/50 border border-brand-border rounded-xl text-sm focus:outline-none focus:border-brand-primary focus:ring-1 focus:ring-brand-primary transition-all duration-300 placeholder-brand-muted/40"
                               placeholder="e.g. CHECK VIDEO / FEEDBACK">
                    </div>
                    <div>
                        <label for="btn2_link" class="block text-[10px] font-semibold text-brand-muted mb-1.5">Button 2 Telegram Link</label>
                        <input type="url" id="btn2_link" name="btn2_link"
                               value="<?php echo htmlspecialchars($_POST['btn2_link'] ?? $mod['btn2_link'] ?? ''); ?>"
                               class="w-full px-4 py-2.5 bg-brand-bg/50 border border-brand-border rounded-xl text-sm focus:outline-none focus:border-brand-primary focus:ring-1 focus:ring-brand-primary transition-all duration-300 placeholder-brand-muted/40"
                               placeholder="e.g. https://t.me/yourchannel">
                    </div>
                </div>
                <p class="text-[10px] text-brand-muted">In dono buttons ka naam aur telegram link yahan change karo. Store page par mod description ke neeche dikhenge — link blank chhodne par button show nahi hoga.</p>
            </div>
        </div>

        <button type="submit" class="w-full py-3 bg-gradient-to-r from-brand-primary to-brand-secondary hover:opacity-90 active:scale-[0.98] transition-all duration-300 rounded-xl text-sm font-bold text-white shadow-glow flex items-center justify-center space-x-2">
            <i class="fa-solid fa-save"></i>
            <span>Save Changes</span>
        </button>
    </form>
</div>

<?php
require_once 'includes/footer.php';
?>
