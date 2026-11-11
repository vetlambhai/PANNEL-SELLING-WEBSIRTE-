<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'includes/auth.php';
require_once 'includes/functions.php';
require_once 'includes/payment.php';
require_once 'includes/reseller.php';
require_once 'includes/reseller_provider.php';

requireAdmin();

$success = '';
$error = '';

if (isset($_GET['msg']) && $_GET['msg'] === 'site_updated') {
    $success = 'Site details updated successfully!';
}
if (isset($_GET['msg']) && $_GET['msg'] === 'google_updated') {
    $success = 'Google Login settings updated successfully!';
}
if (isset($_GET['msg']) && $_GET['msg'] === 'deposit_video_updated') {
    $success = 'Deposit How-To video updated successfully!';
}

try {
    $pdo = getDBConnection();
    
    // Get current user data
    $user = getUserData();
    
    if ($_POST) {
        if (isset($_POST['update_profile'])) {
            $username = trim($_POST['username']);
            $email = trim($_POST['email']);
            
            if (empty($username) || empty($email)) {
                $error = 'Please fill in all fields';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Please enter a valid email address';
            } else {
                // Check if username or email already exists
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE (username = ? OR email = ?) AND id != ?");
                $stmt->execute([$username, $email, $user['id']]);
                
                if ($stmt->fetchColumn() > 0) {
                    $error = 'Username or email already exists';
                } else {
                    $stmt = $pdo->prepare("UPDATE users SET username = ?, email = ? WHERE id = ?");
                    if ($stmt->execute([$username, $email, $user['id']])) {
                        $_SESSION['username'] = $username;
                        $_SESSION['email'] = $email;
                        $success = 'Profile updated successfully!';
                        $user = getUserData(); // Refresh user data
                    } else {
                        $error = 'Failed to update profile';
                    }
                }
            }
        } elseif (isset($_POST['change_password'])) {
            $currentPassword = $_POST['current_password'];
            $newPassword = $_POST['new_password'];
            $confirmPassword = $_POST['confirm_password'];
            
            if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
                $error = 'Please fill in all password fields';
            } elseif ($newPassword !== $confirmPassword) {
                $error = 'New passwords do not match';
            } elseif (strlen($newPassword) < 6) {
                $error = 'New password must be at least 6 characters long';
            } elseif (!password_verify($currentPassword, $user['password'])) {
                $error = 'Current password is incorrect';
            } else {
                $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                if ($stmt->execute([$hashedPassword, $user['id']])) {
                    $success = 'Password changed successfully!';
                } else {
                    $error = 'Failed to change password';
                }
            }
        } elseif (isset($_POST['update_site'])) {
            // Load existing settings so we don't overwrite logo_path etc.
            $existingSettings = [];
            if (file_exists('config/settings.json')) {
                $existingSettings = json_decode(file_get_contents('config/settings.json'), true) ?: [];
            }
            $siteSettings = array_merge($existingSettings, [
                'site_name' => trim($_POST['site_name'] ?? 'Aryanispe'),
                'site_subtitle' => trim($_POST['site_subtitle'] ?? 'Multipanel'),
                'telegram_link' => trim($_POST['telegram_link'] ?? ''),
                'show_telegram_icon' => isset($_POST['show_telegram_icon']) ? "1" : "0",
                'support_email' => trim($_POST['support_email'] ?? '')
            ]);
            
            if (!is_dir('config')) {
                mkdir('config', 0755, true);
            }
            if (file_put_contents('config/settings.json', json_encode($siteSettings, JSON_PRETTY_PRINT))) {
                header("Location: settings?msg=site_updated");
                exit;
            } else {
                $error = 'Failed to update site details. Please check folder permissions.';
            }
        } elseif (isset($_POST['update_google'])) {
            // Save Google OAuth credentials (Client ID + Client Secret)
            $existingSettings = [];
            if (file_exists('config/settings.json')) {
                $existingSettings = json_decode(file_get_contents('config/settings.json'), true) ?: [];
            }
            $googleSettings = array_merge($existingSettings, [
                'google_client_id'     => trim($_POST['google_client_id'] ?? ''),
                'google_client_secret' => trim($_POST['google_client_secret'] ?? '')
            ]);

            if (!is_dir('config')) {
                mkdir('config', 0755, true);
            }
            if (file_put_contents('config/settings.json', json_encode($googleSettings, JSON_PRETTY_PRINT))) {
                header("Location: settings?msg=google_updated");
                exit;
            } else {
                $error = 'Failed to update Google settings. Please check folder permissions.';
            }
        } elseif (isset($_POST['update_deposit_video'])) {
            // Save the "How To Deposit" YouTube video URL + enable toggle
            $existingSettings = [];
            if (file_exists('config/settings.json')) {
                $existingSettings = json_decode(file_get_contents('config/settings.json'), true) ?: [];
            }
            $depositSettings = array_merge($existingSettings, [
                'deposit_video_url'     => trim($_POST['deposit_video_url'] ?? ''),
                'deposit_video_enabled' => isset($_POST['deposit_video_enabled']) ? '1' : '0',
            ]);

            if (!is_dir('config')) {
                mkdir('config', 0755, true);
            }
            if (file_put_contents('config/settings.json', json_encode($depositSettings, JSON_PRETTY_PRINT))) {
                header("Location: settings?msg=deposit_video_updated");
                exit;
            } else {
                $error = 'Failed to update deposit video settings. Please check folder permissions.';
            }
        } elseif (isset($_POST['upload_logo'])) {
            // Handle logo image upload
            if (isset($_FILES['logo_file']) && $_FILES['logo_file']['error'] === UPLOAD_ERR_OK) {
                $allowedTypes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime = finfo_file($finfo, $_FILES['logo_file']['tmp_name']);
                finfo_close($finfo);

                if (!array_key_exists($mime, $allowedTypes)) {
                    $error = 'Invalid logo file type. Allowed: JPG, PNG, GIF, WEBP.';
                } elseif ($_FILES['logo_file']['size'] > 2 * 1024 * 1024) {
                    $error = 'Logo file is too large. Max 2MB.';
                } else {
                    // Ensure uploads/logos dir exists
                    if (!is_dir('uploads/logos')) {
                        mkdir('uploads/logos', 0755, true);
                    }
                    $ext = $allowedTypes[$mime];
                    $logoName = 'logo_' . time() . '.' . $ext;
                    $destPath = 'uploads/logos/' . $logoName;

                    if (move_uploaded_file($_FILES['logo_file']['tmp_name'], $destPath)) {
                        // Load existing settings, update logo_path, save
                        $existingSettings = [];
                        if (file_exists('config/settings.json')) {
                            $existingSettings = json_decode(file_get_contents('config/settings.json'), true) ?: [];
                        }
                        // Remove old logo file if different
                        $oldLogo = $existingSettings['logo_path'] ?? '';
                        if (!empty($oldLogo) && $oldLogo !== $destPath && file_exists($oldLogo)) {
                            @unlink($oldLogo);
                        }
                        $existingSettings['logo_path'] = $destPath;
                        file_put_contents('config/settings.json', json_encode($existingSettings, JSON_PRETTY_PRINT));
                        $success = 'Logo uploaded successfully! Header will now show your logo.';
                    } else {
                        $error = 'Failed to save logo file. Please check uploads/ folder permissions.';
                    }
                }
            } else {
                $error = 'Please choose a logo file to upload.';
            }
        } elseif (isset($_POST['remove_logo'])) {
            // Remove logo
            $existingSettings = [];
            if (file_exists('config/settings.json')) {
                $existingSettings = json_decode(file_get_contents('config/settings.json'), true) ?: [];
            }
            $oldLogo = $existingSettings['logo_path'] ?? '';
            if (!empty($oldLogo) && file_exists($oldLogo)) {
                @unlink($oldLogo);
            }
            $existingSettings['logo_path'] = '';
            file_put_contents('config/settings.json', json_encode($existingSettings, JSON_PRETTY_PRINT));
            $success = 'Logo removed. Header will now show the default icon.';
        } elseif (isset($_POST['update_upi'])) {
            $upiId = trim($_POST['upi_id']);
            if (empty($upiId)) {
                $error = 'Please enter a UPI ID';
            } else {
                $upiData = ['upi_id' => $upiId];
                if (!is_dir('config')) {
                    mkdir('config', 0755, true);
                }
                if (file_put_contents('config/upi.json', json_encode($upiData, JSON_PRETTY_PRINT))) {
                    $success = 'UPI ID updated successfully!';
                } else {
                    $error = 'Failed to update UPI ID. Please check folder permissions.';
                }
            }
        } elseif (isset($_POST['update_payment'])) {
            $paymentCfg = [
                'user_token'  => trim($_POST['pg_user_token'] ?? ''),
                'gateway_url' => 'https://pay.zapupi.com',
                'site_base'   => trim($_POST['pg_site_base'] ?? ''),
                'enabled'     => isset($_POST['pg_enabled']) ? '1' : '0',
            ];
            if (empty($paymentCfg['user_token'])) {
                $error = 'Please enter your ZapUPI Zap Key.';
            } else {
                if (savePaymentConfig($paymentCfg)) {
                    $success = 'Payment gateway settings updated successfully!';
                } else {
                    $error = 'Failed to save payment settings. Please check folder permissions.';
                }
            }
        } elseif (isset($_POST['update_reseller'])) {
            $resellerCfg = [
                'api_key'     => trim($_POST['reseller_api_key'] ?? ''),
                'master_key'  => trim($_POST['reseller_master_key'] ?? ''),
                'endpoint'    => trim($_POST['reseller_endpoint'] ?? 'https://keysmaster.shop/reseller_api.php'),
                'enabled'     => isset($_POST['reseller_enabled']) ? '1' : '0',
            ];
            if (empty($resellerCfg['api_key']) || empty($resellerCfg['master_key'])) {
                $error = 'Please enter both Reseller API Key and Master Key.';
            } elseif (empty($resellerCfg['endpoint'])) {
                $error = 'Please enter the Reseller API endpoint URL.';
            } else {
                if (saveResellerConfig($resellerCfg)) {
                    $success = 'Reseller API settings updated successfully!';
                } else {
                    $error = 'Failed to save reseller settings. Please check folder permissions.';
                }
            }
        } elseif (isset($_POST['update_reseller_provider'])) {
            $providerCfg = [
                'master_key'     => trim($_POST['provider_master_key'] ?? ''),
                'auto_threshold' => trim($_POST['provider_auto_threshold'] ?? '500'),
                'discount_pct'   => trim($_POST['provider_discount_pct'] ?? '10'),
                'endpoint_path'  => trim($_POST['provider_endpoint_path'] ?? 'reseller_api.php'),
                'enabled'        => isset($_POST['provider_enabled']) ? '1' : '0',
            ];
            if (empty($providerCfg['master_key'])) {
                $error = 'Master Key is required for the Reseller Provider.';
            } elseif (!is_numeric($providerCfg['auto_threshold']) || $providerCfg['auto_threshold'] < 0) {
                $error = 'Auto-promotion threshold must be a positive number.';
            } elseif (!is_numeric($providerCfg['discount_pct']) || $providerCfg['discount_pct'] < 0 || $providerCfg['discount_pct'] > 100) {
                $error = 'Discount percentage must be between 0 and 100.';
            } else {
                if (saveResellerProviderConfig($providerCfg)) {
                    $success = 'Reseller Provider settings updated successfully!';
                } else {
                    $error = 'Failed to save reseller provider settings. Please check folder permissions.';
                }
            }
        } elseif (isset($_POST['generate_master_key'])) {
            $newMaster = bin2hex(random_bytes(16));
            $cfg = getResellerProviderConfig();
            $cfg['master_key'] = $newMaster;
            if (saveResellerProviderConfig($cfg)) {
                $success = 'New Master Key generated! All resellers must update their x-master-key header.';
            } else {
                $error = 'Failed to generate new master key.';
            }
        } elseif (isset($_POST['save_top_resellers'])) {
            verify_csrf();
            // Admin manually selected top resellers (up to 5 user IDs, in order)
            $selectedIds = [];
            if (isset($_POST['top_reseller_ids']) && is_array($_POST['top_reseller_ids'])) {
                foreach ($_POST['top_reseller_ids'] as $id) {
                    $id = (int)$id;
                    if ($id > 0) $selectedIds[] = $id;
                }
            }
            // preserve order + unique, max 5
            $selectedIds = array_values(array_unique($selectedIds));
            $selectedIds = array_slice($selectedIds, 0, 5);
            if (empty($selectedIds)) {
                clearTopResellers();
                $success = 'Top Resellers cleared. Dashboard will now show automatic top 5.';
            } else {
                if (saveTopResellers($selectedIds)) {
                    $success = 'Top Resellers updated successfully!';
                } else {
                    $error = 'Failed to save top resellers. Please check folder permissions.';
                }
            }
        } elseif (isset($_POST['clear_top_resellers'])) {
            verify_csrf();
            clearTopResellers();
            $success = 'Top Resellers cleared. Dashboard will now show automatic top 5.';
        }
    }
} catch (Exception $e) {
    $error = 'Error processing settings: ' . $e->getMessage();
}

$page_title = "Admin Settings - " . SITE_NAME . " " . SITE_SUBTITLE;
require_once 'includes/header.php';
?>

<!-- Header -->
<div class="mb-8 flex items-center justify-between">
    <div>
        <h2 class="text-2xl font-bold tracking-tight text-glow-primary">System Settings</h2>
        <p class="text-sm text-brand-muted mt-1 font-medium">Configure administrator credentials, passwords, and profile details.</p>
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

<div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
    <!-- Profile Card -->
    <div class="glass-card rounded-2xl p-6 border border-brand-border/60">
        <h3 class="text-base font-bold mb-4 flex items-center space-x-2">
            <i class="fa-solid fa-user-gear text-brand-primary"></i>
            <span>Update Profile</span>
        </h3>
        
        <form method="POST" class="space-y-4">
            <div>
                <label for="username" class="block text-xs font-semibold text-brand-muted mb-1.5">Username *</label>
                <input type="text" id="username" name="username" required
                       value="<?php echo htmlspecialchars($user['username']); ?>"
                       class="w-full px-4 py-2.5 bg-brand-bg/50 border border-brand-border rounded-xl text-sm focus:outline-none focus:border-brand-primary">
            </div>

            <div>
                <label for="email" class="block text-xs font-semibold text-brand-muted mb-1.5">Email Address *</label>
                <input type="email" id="email" name="email" required
                       value="<?php echo htmlspecialchars($user['email']); ?>"
                       class="w-full px-4 py-2.5 bg-brand-bg/50 border border-brand-border rounded-xl text-sm focus:outline-none focus:border-brand-primary">
            </div>

            <button type="submit" name="update_profile" class="w-full py-3 bg-gradient-to-r from-brand-primary to-brand-secondary hover:opacity-90 active:scale-[0.98] transition-all duration-300 rounded-xl text-sm font-bold text-white shadow-glow flex items-center justify-center space-x-2">
                <i class="fa-solid fa-floppy-disk"></i>
                <span>Save Profile</span>
            </button>
        </form>
    </div>

    <!-- Password Card -->
    <div class="glass-card rounded-2xl p-6 border border-brand-border/60">
        <h3 class="text-base font-bold mb-4 flex items-center space-x-2">
            <i class="fa-solid fa-lock text-brand-secondary"></i>
            <span>Change Password</span>
        </h3>
        
        <form method="POST" class="space-y-4">
            <div>
                <label for="current_password" class="block text-xs font-semibold text-brand-muted mb-1.5">Current Password *</label>
                <input type="password" id="current_password" name="current_password" required
                       class="w-full px-4 py-2.5 bg-brand-bg/50 border border-brand-border rounded-xl text-sm focus:outline-none focus:border-brand-primary">
            </div>

            <div>
                <label for="new_password" class="block text-xs font-semibold text-brand-muted mb-1.5">New Password *</label>
                <input type="password" id="new_password" name="new_password" required
                       class="w-full px-4 py-2.5 bg-brand-bg/50 border border-brand-border rounded-xl text-sm focus:outline-none focus:border-brand-primary"
                       placeholder="Min 6 characters">
            </div>

            <div>
                <label for="confirm_password" class="block text-xs font-semibold text-brand-muted mb-1.5">Confirm New Password *</label>
                <input type="password" id="confirm_password" name="confirm_password" required
                       class="w-full px-4 py-2.5 bg-brand-bg/50 border border-brand-border rounded-xl text-sm focus:outline-none focus:border-brand-primary">
            </div>

            <button type="submit" name="change_password" class="w-full py-3 bg-gradient-to-r from-brand-primary to-brand-secondary hover:opacity-90 active:scale-[0.98] transition-all duration-300 rounded-xl text-sm font-bold text-white shadow-glow flex items-center justify-center space-x-2">
                <i class="fa-solid fa-key"></i>
                <span>Update Password</span>
            </button>
        </form>
    </div>
    <!-- Site Details Card -->
    <div class="glass-card rounded-2xl p-6 border border-brand-border/60">
        <h3 class="text-base font-bold mb-4 flex items-center space-x-2">
            <i class="fa-solid fa-globe text-brand-primary"></i>
            <span>Site Details</span>
        </h3>
        
        <?php
        $currentSettings = [
            'site_name' => 'Aryanispe',
            'site_subtitle' => 'Multipanel',
            'telegram_link' => 'https://t.me/ARYANISPE',
            'show_telegram_icon' => '0',
            'support_email' => 'admin@example.com',
            'logo_path' => '',
            'google_client_id' => '',
            'google_client_secret' => '',
            'deposit_video_url' => '',
            'deposit_video_enabled' => '0'
        ];
        if (file_exists('config/settings.json')) {
            $settingsData = json_decode(file_get_contents('config/settings.json'), true);
            if ($settingsData) {
                $currentSettings = array_merge($currentSettings, $settingsData);
            }
        }
        $logoExists = !empty($currentSettings['logo_path']) && file_exists($currentSettings['logo_path']);
        ?>

        <!-- Logo Upload -->
        <div class="bg-brand-bg/40 border border-brand-border rounded-xl p-4 space-y-3">
            <div class="flex items-center justify-between">
                <label class="text-xs font-semibold text-brand-muted">Site Logo</label>
                <?php if ($logoExists): ?>
                    <form method="POST" class="inline" onsubmit="return confirm('Remove current logo? Header will show the default icon.');">
                        <?php echo csrf_field(); ?>
                        <button type="submit" name="remove_logo" class="text-[11px] font-bold text-brand-error hover:underline">
                            <i class="fa-solid fa-trash-can mr-1"></i>Remove
                        </button>
                    </form>
                <?php endif; ?>
            </div>

            <div class="flex items-center gap-4">
                <!-- Current logo / fallback preview -->
                <div class="w-16 h-16 rounded-xl bg-brand-surface border border-brand-border flex items-center justify-center overflow-hidden shrink-0">
                    <?php if ($logoExists): ?>
                        <img src="<?php echo htmlspecialchars($currentSettings['logo_path']); ?>?v=<?php echo filemtime($currentSettings['logo_path']); ?>" alt="Logo" class="w-full h-full object-contain">
                    <?php else: ?>
                        <div class="w-10 h-10 rounded-lg bg-gradient-to-tr from-brand-primary to-brand-secondary flex items-center justify-center">
                            <i class="fa-solid fa-gem text-white text-base"></i>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="flex-1 min-w-0">
                    <?php if ($logoExists): ?>
                        <p class="text-[11px] text-brand-success font-semibold mb-1"><i class="fa-solid fa-circle-check mr-1"></i>Logo set</p>
                        <p class="text-[10px] text-brand-muted truncate"><?php echo htmlspecialchars($currentSettings['logo_path']); ?></p>
                    <?php else: ?>
                        <p class="text-[11px] text-brand-muted mb-1">No logo set — using default icon.</p>
                    <?php endif; ?>
                    <p class="text-[10px] text-brand-muted mt-1">Shown next to site name in the header. Recommended: square, max 2MB (JPG/PNG/GIF/WEBP).</p>
                </div>
            </div>

            <form method="POST" enctype="multipart/form-data" class="flex items-center gap-3">
                <?php echo csrf_field(); ?>
                <input type="file" name="logo_file" accept="image/png,image/jpeg,image/gif,image/webp" required
                       class="block w-full text-xs text-brand-muted file:mr-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-xs file:font-bold file:bg-brand-primary file:text-white hover:file:opacity-90 cursor-pointer">
                <button type="submit" name="upload_logo" class="shrink-0 px-4 py-2 bg-brand-primary text-white text-xs font-bold rounded-xl hover:opacity-90 active:scale-95 transition-all whitespace-nowrap">
                    <i class="fa-solid fa-upload mr-1"></i>Upload
                </button>
            </form>
        </div>

        <form method="POST" class="space-y-4">
            <?php echo csrf_field(); ?>
            <div>
                <label for="site_name" class="block text-xs font-semibold text-brand-muted mb-1.5">Site Name *</label>
                <input type="text" id="site_name" name="site_name" required
                       value="<?php echo htmlspecialchars($currentSettings['site_name']); ?>"
                       class="w-full px-4 py-2.5 bg-brand-bg/50 border border-brand-border rounded-xl text-sm focus:outline-none focus:border-brand-primary">
            </div>

            <div>
                <label for="site_subtitle" class="block text-xs font-semibold text-brand-muted mb-1.5">Site Subtitle *</label>
                <input type="text" id="site_subtitle" name="site_subtitle" required
                       value="<?php echo htmlspecialchars($currentSettings['site_subtitle']); ?>"
                       class="w-full px-4 py-2.5 bg-brand-bg/50 border border-brand-border rounded-xl text-sm focus:outline-none focus:border-brand-primary">
            </div>
            
            <div>
                <label for="support_email" class="block text-xs font-semibold text-brand-muted mb-1.5">Support Email</label>
                <input type="email" id="support_email" name="support_email"
                       value="<?php echo htmlspecialchars($currentSettings['support_email']); ?>"
                       class="w-full px-4 py-2.5 bg-brand-bg/50 border border-brand-border rounded-xl text-sm focus:outline-none focus:border-brand-primary">
            </div>

            <div>
                <label class="flex items-center space-x-3 cursor-pointer">
                    <div class="relative">
                        <input type="checkbox" name="show_telegram_icon" value="1" class="sr-only peer" <?php echo $currentSettings['show_telegram_icon'] === '1' ? 'checked' : ''; ?>>
                        <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none rounded-full peer dark:bg-gray-700 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-gray-600 peer-checked:bg-brand-primary"></div>
                    </div>
                    <span class="text-sm font-semibold text-brand-muted">Show Telegram Icon</span>
                </label>
            </div>
            
            <div>
                <label for="telegram_link" class="block text-xs font-semibold text-brand-muted mb-1.5">Telegram Link</label>
                <input type="url" id="telegram_link" name="telegram_link"
                       value="<?php echo htmlspecialchars($currentSettings['telegram_link']); ?>"
                       class="w-full px-4 py-2.5 bg-brand-bg/50 border border-brand-border rounded-xl text-sm focus:outline-none focus:border-brand-primary">
            </div>

            <button type="submit" name="update_site" class="w-full py-3 bg-gradient-to-r from-brand-primary to-brand-secondary hover:opacity-90 active:scale-[0.98] transition-all duration-300 rounded-xl text-sm font-bold text-white shadow-glow flex items-center justify-center space-x-2">
                <i class="fa-solid fa-floppy-disk"></i>
                <span>Save Site Details</span>
            </button>
        </form>
    </div>

    <!-- Google OAuth Settings Card -->
    <div class="glass-card rounded-2xl p-6 border border-brand-border/60">
        <h3 class="text-base font-bold mb-1 flex items-center space-x-2">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 48 48" class="shrink-0" aria-hidden="true">
                <path fill="#FFC107" d="M43.611 20.083H42V20H24v8h11.303c-1.649 4.657-6.08 8-11.303 8c-6.627 0-12-5.373-12-12s5.373-12 12-12c3.059 0 5.842 1.154 7.961 3.039l5.657-5.657C34.046 6.053 29.268 4 24 4C12.955 4 4 12.955 4 24s8.955 20 20 20s20-8.955 20-20c0-1.341-.138-2.65-.389-3.917z"/>
                <path fill="#FF3D00" d="M6.306 14.691l6.571 4.819C14.655 15.108 18.961 12 24 12c3.059 0 5.842 1.154 7.961 3.039l5.657-5.657C34.046 6.053 29.268 4 24 4C16.318 4 9.656 8.337 6.306 14.691z"/>
                <path fill="#4CAF50" d="M24 44c5.166 0 9.86-1.977 13.409-5.192l-6.19-5.238A11.91 11.91 0 0 1 24 36c-5.202 0-9.619-3.317-11.283-7.946l-6.522 5.025C9.505 39.556 16.227 44 24 44z"/>
                <path fill="#1976D2" d="M43.611 20.083H42V20H24v8h11.303a12.04 12.04 0 0 1-4.087 5.571l.003-.002l6.19 5.238C36.971 39.205 44 34 44 24c0-1.341-.138-2.65-.389-3.917z"/>
            </svg>
            <span>Google Login</span>
        </h3>
        <p class="text-[11px] text-brand-muted mb-4">Enable "Sign in with Google" on the login &amp; register pages. Set the Client ID and Secret from your Google Cloud Console. The redirect URI is auto-detected for this domain.</p>

        <form method="POST" class="space-y-4">
            <?php echo csrf_field(); ?>
            <div>
                <label for="google_client_id" class="block text-xs font-semibold text-brand-muted mb-1.5">Client ID</label>
                <input type="text" id="google_client_id" name="google_client_id"
                       value="<?php echo htmlspecialchars($currentSettings['google_client_id'] ?? ''); ?>"
                       placeholder="xxxxx.apps.googleusercontent.com"
                       class="w-full px-4 py-2.5 bg-brand-bg/50 border border-brand-border rounded-xl text-sm focus:outline-none focus:border-brand-primary font-mono">
            </div>
            <div>
                <label for="google_client_secret" class="block text-xs font-semibold text-brand-muted mb-1.5">Client Secret</label>
                <input type="password" id="google_client_secret" name="google_client_secret"
                       value="<?php echo htmlspecialchars($currentSettings['google_client_secret'] ?? ''); ?>"
                       placeholder="GOCSPX-xxxxxxxxxxxxxxxxxxxxx"
                       class="w-full px-4 py-2.5 bg-brand-bg/50 border border-brand-border rounded-xl text-sm focus:outline-none focus:border-brand-primary font-mono">
            </div>
            <div class="bg-brand-bg/40 border border-brand-border rounded-xl p-3">
                <p class="text-[10px] text-brand-muted leading-relaxed">
                    <i class="fa-solid fa-circle-info mr-1 text-brand-secondary"></i>
                    <strong>Redirect URI</strong> (add this in Google Console &rarr; Authorized redirect URIs):
                    <code class="block mt-1.5 px-2 py-1.5 bg-brand-surface rounded text-brand-text break-all"><?php echo htmlspecialchars((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'yourdomain.com') . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\') . '/google-callback.php'); ?></code>
                </p>
            </div>
            <button type="submit" name="update_google" class="w-full py-3 bg-gradient-to-r from-brand-primary to-brand-secondary hover:opacity-90 active:scale-[0.98] transition-all duration-300 rounded-xl text-sm font-bold text-white shadow-glow flex items-center justify-center space-x-2">
                <i class="fa-solid fa-floppy-disk"></i>
                <span>Save Google Settings</span>
            </button>
        </form>
    </div>

    <!-- Deposit How-To Video Settings Card -->
    <div class="glass-card rounded-2xl p-6 border border-brand-border/60">
        <h3 class="text-base font-bold mb-1 flex items-center space-x-2">
            <i class="fa-brands fa-youtube text-red-500"></i>
            <span>Deposit How-To Video (YouTube)</span>
        </h3>
        <p class="text-[11px] text-brand-muted mb-4">Set the YouTube video that plays when users click the red <strong>&ldquo;HOW TO DEPOSIT?&rdquo;</strong> button on the Deposit (wallet) page. Paste any YouTube link &mdash; it will be converted to an embed automatically.</p>

        <?php
        $depositVideoEnabled = $currentSettings['deposit_video_enabled'] === '1';
        $depositVideoUrl = $currentSettings['deposit_video_url'] ?? '';
        ?>

        <form method="POST" class="space-y-4">
            <?php echo csrf_field(); ?>
            <div>
                <label for="deposit_video_url" class="block text-xs font-semibold text-brand-muted mb-1.5">YouTube Video URL</label>
                <input type="url" id="deposit_video_url" name="deposit_video_url"
                       value="<?php echo htmlspecialchars($depositVideoUrl); ?>"
                       class="w-full px-4 py-2.5 bg-brand-bg/50 border border-brand-border rounded-xl text-sm focus:outline-none focus:border-brand-primary"
                       placeholder="https://www.youtube.com/watch?v=xxxxxxxxxxx">
                <p class="text-[10px] text-brand-muted mt-2">Works with: <code>youtube.com/watch?v=</code>, <code>youtu.be/</code>, <code>youtube.com/shorts/</code> and direct embed links.</p>
            </div>

            <div>
                <label class="flex items-center space-x-3 cursor-pointer">
                    <div class="relative">
                        <input type="checkbox" name="deposit_video_enabled" value="1" class="sr-only peer" <?php echo $depositVideoEnabled ? 'checked' : ''; ?>>
                        <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none rounded-full peer dark:bg-gray-700 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-gray-600 peer-checked:bg-brand-primary"></div>
                    </div>
                    <span class="text-sm font-semibold text-brand-muted">Show &ldquo;HOW TO DEPOSIT?&rdquo; Button</span>
                </label>
            </div>

            <?php if ($depositVideoEnabled && !empty($depositVideoUrl)): ?>
                <div class="bg-brand-success/10 border border-brand-success/20 text-brand-success text-[11px] rounded-xl p-3 flex items-center space-x-2">
                    <i class="fa-solid fa-circle-check"></i>
                    <span>Video is enabled and will pop up on the Deposit page.</span>
                </div>
            <?php elseif ($depositVideoEnabled && empty($depositVideoUrl)): ?>
                <div class="bg-brand-warning/10 border border-brand-warning/20 text-brand-warning text-[11px] rounded-xl p-3 flex items-center space-x-2">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    <span>Button is enabled but no video URL is set. Please paste a YouTube link above.</span>
                </div>
            <?php else: ?>
                <div class="bg-brand-bg/40 border border-brand-border text-brand-muted text-[11px] rounded-xl p-3 flex items-center space-x-2">
                    <i class="fa-solid fa-circle-info"></i>
                    <span>The &ldquo;HOW TO DEPOSIT?&rdquo; button is currently hidden on the Deposit page.</span>
                </div>
            <?php endif; ?>

            <button type="submit" name="update_deposit_video" class="w-full py-3 bg-gradient-to-r from-brand-primary to-brand-secondary hover:opacity-90 active:scale-[0.98] transition-all duration-300 rounded-xl text-sm font-bold text-white shadow-glow flex items-center justify-center space-x-2">
                <i class="fa-solid fa-floppy-disk"></i>
                <span>Save Video Settings</span>
            </button>
        </form>
    </div>
    <!-- UPI Settings Card -->
    <div class="glass-card rounded-2xl p-6 border border-brand-border/60">
        <h3 class="text-base font-bold mb-4 flex items-center space-x-2">
            <i class="fa-solid fa-qrcode text-brand-primary"></i>
            <span>Payment Settings (UPI)</span>
        </h3>
        
        <?php
        $currentUpi = '';
        if (file_exists('config/upi.json')) {
            $upiData = json_decode(file_get_contents('config/upi.json'), true);
            if ($upiData && isset($upiData['upi_id'])) {
                $currentUpi = $upiData['upi_id'];
            }
        }
        ?>
        
        <form method="POST" class="space-y-4">
            <?php echo csrf_field(); ?>
            <div>
                <label for="upi_id" class="block text-xs font-semibold text-brand-muted mb-1.5">UPI ID *</label>
                <input type="text" id="upi_id" name="upi_id" required
                       value="<?php echo htmlspecialchars($currentUpi); ?>"
                       class="w-full px-4 py-2.5 bg-brand-bg/50 border border-brand-border rounded-xl text-sm focus:outline-none focus:border-brand-primary"
                       placeholder="e.g. yourname@ybl">
                <p class="text-[10px] text-brand-muted mt-2">This UPI ID will be used to generate QR codes for users to pay.</p>
            </div>

            <button type="submit" name="update_upi" class="w-full py-3 bg-gradient-to-r from-brand-primary to-brand-secondary hover:opacity-90 active:scale-[0.98] transition-all duration-300 rounded-xl text-sm font-bold text-white shadow-glow flex items-center justify-center space-x-2">
                <i class="fa-solid fa-floppy-disk"></i>
                <span>Save UPI ID</span>
            </button>
        </form>
    </div>
    <!-- Payment Gateway (Auto) Settings Card -->
    <div class="glass-card rounded-2xl p-6 border border-brand-border/60">
        <h3 class="text-base font-bold mb-1 flex items-center space-x-2">
            <i class="fa-solid fa-credit-card text-brand-secondary"></i>
            <span>Payment Gateway (Auto)</span>
        </h3>
        <p class="text-[11px] text-brand-muted mb-4">Configure your ZapUPI Zap Key. When enabled, users can pay via UPI and successful payments are verified server-side before wallet credit or license delivery.</p>

        <?php
        $pgConfig = getPaymentConfig();
        $pgEnabled = $pgConfig['enabled'] === '1';
        ?>

        <form method="POST" class="space-y-4">
            <?php echo csrf_field(); ?>
            <div>
                <label for="pg_user_token" class="block text-xs font-semibold text-brand-muted mb-1.5">ZapUPI Zap Key *</label>
                <input type="text" id="pg_user_token" name="pg_user_token" required
                       value="<?php echo htmlspecialchars($pgConfig['user_token']); ?>"
                       class="w-full px-4 py-2.5 bg-brand-bg/50 border border-brand-border rounded-xl text-sm focus:outline-none focus:border-brand-primary font-mono"
                       placeholder="e.g. 4f4f2d5860edb2ee76ba899d3b63bd02">
                <p class="text-[10px] text-brand-muted mt-1">Your ZapUPI key is stored on the server and sent securely as <b>zap_key</b> to the official ZapUPI API.</p>
            </div>

            <div>
                <label for="pg_gateway_url" class="block text-xs font-semibold text-brand-muted mb-1.5">Gateway API</label>
                <input type="text" id="pg_gateway_url" name="pg_gateway_url"
                       value="https://pay.zapupi.com" readonly
                       class="w-full px-4 py-2.5 bg-brand-bg/50 border border-brand-border rounded-xl text-sm focus:outline-none focus:border-brand-primary"
                       placeholder="https://pay.zapupi.com">
            </div>

            <div>
                <label for="pg_site_base" class="block text-xs font-semibold text-brand-muted mb-1.5">Site URL (optional)</label>
                <input type="text" id="pg_site_base" name="pg_site_base"
                       value="<?php echo htmlspecialchars($pgConfig['site_base'] ?? ''); ?>"
                       class="w-full px-4 py-2.5 bg-brand-bg/50 border border-brand-border rounded-xl text-sm focus:outline-none focus:border-brand-primary"
                       placeholder="https://yourdomain.com">
                <p class="text-[10px] text-brand-muted mt-1">If your site has multiple domains, enter the one the gateway should redirect back to after payment (e.g. https://yourdomain.com). Leave empty to auto-detect.</p>
            </div>

            <div>
                <label class="flex items-center space-x-3 cursor-pointer">
                    <div class="relative">
                        <input type="checkbox" name="pg_enabled" value="1" class="sr-only peer" <?php echo $pgEnabled ? 'checked' : ''; ?>>
                        <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none rounded-full peer dark:bg-gray-700 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-gray-600 peer-checked:bg-brand-primary"></div>
                    </div>
                    <span class="text-sm font-semibold text-brand-muted">Enable Auto Payment Gateway</span>
                </label>
            </div>

            <button type="submit" name="update_payment" class="w-full py-3 bg-gradient-to-r from-brand-primary to-brand-secondary hover:opacity-90 active:scale-[0.98] transition-all duration-300 rounded-xl text-sm font-bold text-white shadow-glow flex items-center justify-center space-x-2">
                <i class="fa-solid fa-floppy-disk"></i>
                <span>Save Payment Settings</span>
            </button>
        </form>
    </div>

    <!-- Reseller API Settings Card -->
    <div class="glass-card rounded-2xl p-6 border border-brand-border/60">
        <h3 class="text-base font-bold mb-1 flex items-center space-x-2">
            <i class="fa-solid fa-cloud-arrow-down text-brand-success"></i>
            <span>Reseller API (Key Provider)</span>
        </h3>
        <p class="text-[11px] text-brand-muted mb-4">Configure the KeysMaster reseller API. When enabled, ALL keys are purchased from the reseller provider — no random or local key generation.</p>

        <?php
        $resellerConfig = getResellerConfig();
        $resellerEnabled = $resellerConfig['enabled'] === '1';
        ?>

        <form method="POST" class="space-y-4">
            <?php echo csrf_field(); ?>
            <div>
                <label for="reseller_api_key" class="block text-xs font-semibold text-brand-muted mb-1.5">API Key *</label>
                <input type="text" id="reseller_api_key" name="reseller_api_key" required
                       value="<?php echo htmlspecialchars($resellerConfig['api_key']); ?>"
                       class="w-full px-4 py-2.5 bg-brand-bg/50 border border-brand-border rounded-xl text-sm focus:outline-none focus:border-brand-primary font-mono"
                       placeholder="0554f7cdf5b5a4a68088c4be7f41f9d0">
            </div>

            <div>
                <label for="reseller_master_key" class="block text-xs font-semibold text-brand-muted mb-1.5">Master Key *</label>
                <input type="text" id="reseller_master_key" name="reseller_master_key" required
                       value="<?php echo htmlspecialchars($resellerConfig['master_key']); ?>"
                       class="w-full px-4 py-2.5 bg-brand-bg/50 border border-brand-border rounded-xl text-sm focus:outline-none focus:border-brand-primary font-mono"
                       placeholder="a7f3e8b2c9d1f4a6b8c2d5e9f1a3b6c8">
            </div>

            <div>
                <label for="reseller_endpoint" class="block text-xs font-semibold text-brand-muted mb-1.5">API Endpoint URL</label>
                <input type="text" id="reseller_endpoint" name="reseller_endpoint"
                       value="<?php echo htmlspecialchars($resellerConfig['endpoint']); ?>"
                       class="w-full px-4 py-2.5 bg-brand-bg/50 border border-brand-border rounded-xl text-sm focus:outline-none focus:border-brand-primary"
                       placeholder="https://keysmaster.shop/reseller_api.php">
            </div>

            <div>
                <label class="flex items-center space-x-3 cursor-pointer">
                    <div class="relative">
                        <input type="checkbox" name="reseller_enabled" value="1" class="sr-only peer" <?php echo $resellerEnabled ? 'checked' : ''; ?>>
                        <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none rounded-full peer dark:bg-gray-700 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-gray-600 peer-checked:bg-brand-success"></div>
                    </div>
                    <span class="text-sm font-semibold text-brand-muted">Enable Reseller API (Real Keys)</span>
                </label>
            </div>

            <?php if (!$resellerEnabled): ?>
                <div class="bg-brand-warning/10 border border-brand-warning/20 text-brand-warning text-[11px] rounded-xl p-3 flex items-center space-x-2">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    <span>Reseller API is currently DISABLED. Key purchases will fail until this is enabled.</span>
                </div>
            <?php else: ?>
                <div class="bg-brand-success/10 border border-brand-success/20 text-brand-success text-[11px] rounded-xl p-3 flex items-center space-x-2">
                    <i class="fa-solid fa-circle-check"></i>
                    <span>Reseller API is ENABLED. All keys will be purchased from the provider.</span>
                </div>
            <?php endif; ?>

            <button type="submit" name="update_reseller" class="w-full py-3 bg-gradient-to-r from-brand-success to-brand-secondary hover:opacity-90 active:scale-[0.98] transition-all duration-300 rounded-xl text-sm font-bold text-white shadow-glow flex items-center justify-center space-x-2">
                <i class="fa-solid fa-floppy-disk"></i>
                <span>Save Reseller Settings</span>
            </button>
        </form>
    </div>

    <!-- Reseller PROVIDER Settings Card -->
    <div class="glass-card rounded-2xl p-6 border border-brand-border/60">
        <h3 class="text-base font-bold mb-1 flex items-center space-x-2">
            <i class="fa-solid fa-cloud-arrow-up text-sky-400"></i>
            <span>Reseller Provider (Your Own Reseller API)</span>
        </h3>
        <p class="text-[11px] text-brand-muted mb-4">Make THIS site a reseller provider so other websites can connect to you and buy keys through your API \u2014 exactly like you connect to KeysMaster. Users who spend \u20b9500+ auto-become resellers. Each reseller gets a unique API Key; all resellers share the same Master Key.</p>

        <?php
        $providerCfg = getResellerProviderConfig();
        $providerEnabled = ($providerCfg['enabled'] ?? '0') === '1';
        $providerEndpoint = getResellerEndpointUrl();

        // Top Resellers management data
        $managedTopIds = getManagedTopResellerIds();
        // All resellers (for selection dropdown)
        $allResellers = [];
        try {
            $stmt = $pdo->query("SELECT u.id, u.username, u.balance,
                                    COALESCE(k.keys_sold, 0) AS keys_sold,
                                    COALESCE(t.total_buy, 0)  AS total_buy
                                 FROM users u
                                 LEFT JOIN (SELECT sold_to, COUNT(*) AS keys_sold FROM license_keys WHERE status='sold' AND sold_to IS NOT NULL GROUP BY sold_to) k ON k.sold_to=u.id
                                 LEFT JOIN (SELECT user_id, SUM(ABS(amount)) AS total_buy FROM transactions WHERE type='purchase' AND status IN ('completed','approved') GROUP BY user_id) t ON t.user_id=u.id
                                 WHERE u.is_reseller = 1 AND u.role = 'user'
                                 ORDER BY keys_sold DESC, total_buy DESC, u.username ASC");
            $allResellers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $allResellers = [];
        }
        ?>

        <div class="bg-sky-500/10 border border-sky-500/20 rounded-xl p-3 mb-4 text-[11px] text-sky-300">
            <div class="flex items-center space-x-2 mb-1 font-semibold">
                <i class="fa-solid fa-link"></i>
                <span>Your Reseller API Endpoint</span>
            </div>
            <code class="text-sky-200 break-all"><?php echo htmlspecialchars($providerEndpoint); ?></code>
            <p class="text-sky-400/70 mt-1">Other websites send POST requests here with their <b>api_key</b> and the <b>x-master-key</b> header below.</p>
        </div>

        <form method="POST" class="space-y-4">
            <?php echo csrf_field(); ?>

            <div>
                <label for="provider_master_key" class="block text-xs font-semibold text-brand-muted mb-1.5">Master Key (shared by ALL resellers) *</label>
                <div class="flex space-x-2">
                    <input type="text" id="provider_master_key" name="provider_master_key" required
                           value="<?php echo htmlspecialchars($providerCfg['master_key'] ?? ''); ?>"
                           class="flex-1 px-4 py-2.5 bg-brand-bg/50 border border-brand-border rounded-xl text-sm focus:outline-none focus:border-brand-primary font-mono"
                           placeholder="a7f3e8b2c9d1f4a6b8c2d5e9f1a3b6c8">
                    <button type="submit" name="generate_master_key"
                            class="px-3 py-2.5 bg-sky-500/20 border border-sky-500/40 text-sky-300 rounded-xl text-xs font-bold hover:bg-sky-500/30 transition-colors whitespace-nowrap"
                            onclick="return confirm('Generate a new Master Key? All connected reseller websites will need to update their x-master-key header immediately.')">
                        <i class="fa-solid fa-wand-magic-sparkles mr-1"></i>Generate
                    </button>
                </div>
                <p class="text-[10px] text-brand-muted mt-1">This is sent as the <code>x-master-key</code> header by every reseller connecting to you.</p>
            </div>

            <div>
                <label for="provider_auto_threshold" class="block text-xs font-semibold text-brand-muted mb-1.5">Auto-Promotion Threshold (\u20b9)</label>
                <input type="number" id="provider_auto_threshold" name="provider_auto_threshold" min="0" step="1"
                       value="<?php echo htmlspecialchars($providerCfg['auto_threshold'] ?? '500'); ?>"
                       class="w-full px-4 py-2.5 bg-brand-bg/50 border border-brand-border rounded-xl text-sm focus:outline-none focus:border-brand-primary"
                       placeholder="500">
                <p class="text-[10px] text-brand-muted mt-1">When a user's total spend (deposits + purchases) reaches this amount, they auto-become a reseller.</p>
            </div>

            <div>
                <label for="provider_discount_pct" class="block text-xs font-semibold text-brand-muted mb-1.5">Default Reseller Discount (%)</label>
                <input type="number" id="provider_discount_pct" name="provider_discount_pct" min="0" max="100" step="1"
                       value="<?php echo htmlspecialchars($providerCfg['discount_pct'] ?? '10'); ?>"
                       class="w-full px-4 py-2.5 bg-brand-bg/50 border border-brand-border rounded-xl text-sm focus:outline-none focus:border-brand-primary"
                       placeholder="10">
                <p class="text-[10px] text-brand-muted mt-1">Resellers pay this % less than normal price (only if a plan has no explicit Reseller Price set). You can override per-plan in <b>Plans</b>.</p>
            </div>

            <div>
                <label for="provider_endpoint_path" class="block text-xs font-semibold text-brand-muted mb-1.5">API Endpoint File Name</label>
                <input type="text" id="provider_endpoint_path" name="provider_endpoint_path"
                       value="<?php echo htmlspecialchars($providerCfg['endpoint_path'] ?? 'reseller_api.php'); ?>"
                       class="w-full px-4 py-2.5 bg-brand-bg/50 border border-brand-border rounded-xl text-sm focus:outline-none focus:border-brand-primary font-mono"
                       placeholder="reseller_api.php">
                <p class="text-[10px] text-brand-muted mt-1">The file resellers POST to (relative to your site root). Default: <code>reseller_api.php</code></p>
            </div>

            <div>
                <label class="flex items-center space-x-3 cursor-pointer">
                    <div class="relative">
                        <input type="checkbox" name="provider_enabled" value="1" class="sr-only peer" <?php echo $providerEnabled ? 'checked' : ''; ?>>
                        <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none rounded-full peer dark:bg-gray-700 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-gray-600 peer-checked:bg-sky-500"></div>
                    </div>
                    <span class="text-sm font-semibold text-brand-muted">Enable Reseller Provider API</span>
                </label>
            </div>

            <?php if (!$providerEnabled): ?>
                <div class="bg-brand-warning/10 border border-brand-warning/20 text-brand-warning text-[11px] rounded-xl p-3 flex items-center space-x-2">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    <span>Reseller Provider API is DISABLED. No external websites can buy keys from you until this is enabled.</span>
                </div>
            <?php else: ?>
                <div class="bg-sky-500/10 border border-sky-500/20 text-sky-300 text-[11px] rounded-xl p-3 flex items-center space-x-2">
                    <i class="fa-solid fa-circle-check"></i>
                    <span>Reseller Provider API is ENABLED. Resellers can connect and buy keys from your site.</span>
                </div>
            <?php endif; ?>

            <button type="submit" name="update_reseller_provider" class="w-full py-3 bg-gradient-to-r from-sky-500 to-brand-primary hover:opacity-90 active:scale-[0.98] transition-all duration-300 rounded-xl text-sm font-bold text-white shadow-glow flex items-center justify-center space-x-2">
                <i class="fa-solid fa-floppy-disk"></i>
                <span>Save Provider Settings</span>
            </button>
        </form>
    </div>
</div>

<!-- ============================================================ -->
<!-- TOP RESELLERS MANAGEMENT                                     -->
<!-- Admin manually top 5 resellers select kar sakta hai.         -->
<!-- Agar koi select nahi kiya toh automatic top 5 show hote hain -->
<!-- ============================================================ -->
<div id="top-resellers" class="bg-white border border-gray-200 rounded-xl shadow-sm p-6 dark:bg-neutral-900 dark:border-neutral-700 mb-8">
    <div class="flex items-center space-x-3 mb-2">
        <div class="w-8 h-8 rounded-lg bg-yellow-500/15 border border-yellow-500/30 flex items-center justify-center text-yellow-400">
            <i class="fa-solid fa-crown text-sm"></i>
        </div>
        <div>
            <h4 class="text-base font-bold text-brand-text">Top 5 Resellers</h4>
            <p class="text-[11px] text-brand-muted mt-0.5">Manually select which resellers appear in the dashboard leaderboard (up to 5, in order).</p>
        </div>
    </div>

    <div class="bg-brand-primary/10 border border-brand-primary/20 rounded-xl p-3 mb-4 text-[11px] text-brand-primary dark:text-indigo-300">
        <i class="fa-solid fa-circle-info mr-1"></i>
        Leave empty to use <b>automatic</b> top 5 (ranked by keys sold + revenue). Only resellers appear in the list.
    </div>

    <form method="POST" class="space-y-4" id="topResellersForm">
        <?php echo csrf_field(); ?>

        <?php
        // Build 5 select slots. Pre-fill with managed IDs in order; rest empty.
        $slots = [];
        for ($i = 0; $i < 5; $i++) {
            $slots[$i] = $managedTopIds[$i] ?? 0;
        }
        ?>
        <?php for ($i = 0; $i < 5; $i++): ?>
        <div>
            <label class="block text-xs font-semibold text-brand-muted mb-1.5">Rank #<?php echo $i + 1; ?></label>
            <select name="top_reseller_ids[]" class="w-full px-4 py-2.5 bg-brand-bg/50 border border-brand-border rounded-xl text-sm focus:outline-none focus:border-brand-primary top-reseller-select">
                <option value="0">— None (automatic) —</option>
                <?php foreach ($allResellers as $r): ?>
                    <option value="<?php echo (int)$r['id']; ?>" <?php echo ((int)$slots[$i] === (int)$r['id']) ? 'selected' : ''; ?>>
                        @<?php echo htmlspecialchars($r['username']); ?> — <?php echo number_format($r['keys_sold']); ?> keys / &#8377;<?php echo number_format($r['total_buy'], 2); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endfor; ?>

        <?php if (empty($allResellers)): ?>
            <div class="bg-brand-warning/10 border border-brand-warning/20 text-brand-warning text-[11px] rounded-xl p-3 flex items-center space-x-2">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <span>No resellers found. Promote users to reseller in the Users page first.</span>
            </div>
        <?php endif; ?>

        <div class="flex flex-col sm:flex-row gap-3">
            <button type="submit" name="save_top_resellers" class="flex-1 py-3 bg-gradient-to-r from-yellow-500 to-amber-500 hover:opacity-90 active:scale-[0.98] transition-all duration-300 rounded-xl text-sm font-bold text-white shadow-glow flex items-center justify-center space-x-2">
                <i class="fa-solid fa-floppy-disk"></i>
                <span>Save Top Resellers</span>
            </button>
            <button type="submit" name="clear_top_resellers" class="px-5 py-3 bg-brand-error/15 border border-brand-error/30 text-brand-error hover:bg-brand-error/25 transition-all duration-300 rounded-xl text-sm font-bold flex items-center justify-center space-x-2"
                    onclick="return confirm('Clear manually selected top resellers? Dashboard will switch back to automatic top 5.')">
                <i class="fa-solid fa-eraser"></i>
                <span>Clear (Auto)</span>
            </button>
        </div>
    </form>
</div>

<script>
// Prevent same reseller being selected in multiple rank slots
document.addEventListener('DOMContentLoaded', function() {
    const selects = document.querySelectorAll('.top-reseller-select');
    selects.forEach(function(sel) {
        sel.addEventListener('change', function() {
            const chosen = this.value;
            selects.forEach(function(other) {
                if (other === sel) return;
                if (other.value === chosen && chosen !== '0') {
                    other.value = '0';
                }
            });
        });
    });
});
</script>

<?php
require_once 'includes/footer.php';
?>


