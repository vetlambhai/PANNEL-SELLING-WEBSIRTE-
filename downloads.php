<?php
require_once 'includes/auth.php';
require_once 'includes/functions.php';

requireUser();

$pdo = getDBConnection();
$user = getUserData();

// Get user's purchased keys with mod APK information
$stmt = $pdo->prepare("SELECT lk.*, m.name as mod_name, m.description, ma.file_name, ma.file_path, ma.uploaded_at
                      FROM license_keys lk 
                      LEFT JOIN mods m ON lk.mod_id = m.id 
                      LEFT JOIN mod_apks ma ON m.id = ma.mod_id
                      WHERE lk.sold_to = ? 
                      ORDER BY lk.sold_at DESC");
$stmt->execute([$user['id']]);
$applications = $stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = "Applications - " . SITE_NAME . " " . SITE_SUBTITLE;
require_once 'includes/header.php';
?>

<!-- Header -->
<div class="mb-8">
    <h2 class="text-2xl font-bold tracking-tight text-glow-primary">My Applications</h2>
    <p class="text-sm text-brand-muted mt-1 font-medium">Download APK installations for your active, purchased mod packages.</p>
</div>

<!-- Layout Cards -->
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
    <?php if (empty($applications)): ?>
        <div class="col-span-full bg-brand-surface border border-brand-border rounded-xl rounded-2xl p-12 text-center border border-brand-border/60">
            <i class="fa-solid fa-cloud-arrow-down text-4xl mb-3 text-brand-primary opacity-50 block"></i>
            <h4 class="text-base font-bold text-brand-text mb-1">No Applications Available</h4>
            <p class="text-xs text-brand-muted max-w-sm mx-auto mb-4">You must first purchase a license key to unlock access to mod installations.</p>
            <a href="keys" class="inline-flex items-center justify-center px-4 py-2 bg-brand-primary text-white text-xs font-bold rounded-xl hover:opacity-90 active:scale-[0.98] transition-all space-x-1.5 shadow-glow">
                <i class="fa-solid fa-cart-shopping"></i>
                <span>Get License Keys</span>
            </a>
        </div>
    <?php else: ?>
        <?php foreach ($applications as $app): ?>
            <div class="bg-white border border-gray-200 rounded-xl shadow-sm p-6 dark:bg-neutral-900 dark:border-neutral-700 flex flex-col justify-between space-y-4">
                <div>
                    <!-- Icon and Name -->
                    <div class="flex items-start justify-between">
                        <div class="flex items-center space-x-3">
                            <div class="w-10 h-10 rounded-xl bg-brand-primary/10 border border-brand-primary/20 flex items-center justify-center text-brand-primary">
                                <i class="fa-solid fa-mobile-screen-button text-lg"></i>
                            </div>
                            <div>
                                <h4 class="text-sm font-bold text-brand-text"><?php echo htmlspecialchars($app['mod_name']); ?></h4>
                                <span class="text-[9px] font-bold uppercase tracking-wider text-brand-muted">Vault Profile</span>
                            </div>
                        </div>
                        <span class="px-2 py-0.5 rounded text-[8px] font-bold uppercase bg-brand-success/10 text-brand-success">
                            Active
                        </span>
                    </div>

                    <!-- Description -->
                    <p class="text-xs text-brand-muted leading-relaxed mt-4">
                        <?php echo htmlspecialchars($app['description'] ?: 'No details provided for this application profile.'); ?>
                    </p>

                    <!-- Key Details -->
                    <div class="mt-4 p-3 bg-brand-bg/50 rounded-xl border border-brand-border/60 space-y-1.5 font-mono text-[10px] text-brand-muted">
                        <div class="flex justify-between">
                            <span>Key:</span>
                            <span class="text-brand-text font-bold select-all"><?php echo htmlspecialchars($app['license_key']); ?></span>
                        </div>
                        <div class="flex justify-between">
                            <span>Unlocked At:</span>
                            <span><?php echo $app['sold_at'] ? date('d M Y H:i', strtotime($app['sold_at'])) : 'N/A'; ?></span>
                        </div>
                    </div>
                </div>

                <!-- Downloader -->
                <div class="pt-2">
                    <?php if ($app['file_path'] && file_exists($app['file_path'])): ?>
                        <a href="<?php echo htmlspecialchars($app['file_path']); ?>" download
                           class="w-full py-2.5 bg-brand-primary text-white text-xs font-bold rounded-xl hover:opacity-90 active:scale-[0.98] transition-all flex items-center justify-center space-x-2 shadow-glow">
                            <i class="fa-solid fa-download"></i>
                            <span>Download APK</span>
                        </a>
                    <?php else: ?>
                        <button disabled class="w-full py-2.5 bg-brand-border text-brand-muted text-xs font-bold rounded-xl cursor-not-allowed flex items-center justify-center space-x-2">
                            <i class="fa-solid fa-triangle-exclamation"></i>
                            <span>APK Not Available</span>
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php
require_once 'includes/footer.php';
?>
