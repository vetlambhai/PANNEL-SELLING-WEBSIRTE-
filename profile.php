<?php
require_once 'includes/auth.php';
require_once 'includes/functions.php';

requireLogin();

$success = '';
$error = '';

$pdo = getDBConnection();

// Get current user data
$user = getUserData();

if ($_POST) {
    verify_csrf();

    if (isset($_POST['reset_device'])) {
        $password = $_POST['password'] ?? '';
        
        if (empty($password)) {
            $error = 'Please enter your password to reset device';
        } else {
            $resetResult = resetDevice($user['username'], $password);
            if ($resetResult === 'success') {
                $success = 'Successfully logged out from all devices. You will be redirected to login page.';
                header('refresh:3;url=logout.php');
            } else if ($resetResult === 'invalid_password') {
                $error = 'Invalid password';
            } else {
                $error = 'Failed to reset device';
            }
        }
    } elseif (isset($_POST['update_profile'])) {
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        
        if (empty($username) || empty($email)) {
            $error = 'Please fill in all fields';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address';
        } else {
            // Check if username or email already exists (excluding current user)
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
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        
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
    }
}

$page_title = "User Settings - " . SITE_NAME . " " . SITE_SUBTITLE;
require_once 'includes/header.php';
?>

<!-- ===== Settings Page with Enhanced Border Effects (Like Wallet) ===== -->
<div class="max-w-6xl mx-auto space-y-5 animate-fade-in">

    <!-- Header with cyan neon border (like wallet current balance) -->
    <div class="relative rounded-2xl p-5 bg-neutral-900 border-2 border-cyan-400/70 shadow-[0_0_20px_rgba(34,211,238,0.25)] dark:bg-neutral-900">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <h2 class="text-2xl font-bold tracking-tight text-white text-glow-cyan">My Settings</h2>
                <p class="text-sm text-cyan-300/70 mt-1">Configure profile settings, change security passwords, or reset device logs.</p>
            </div>
            <span class="text-xs font-bold text-cyan-300 bg-cyan-500/10 px-4 py-2 rounded-full border border-cyan-400/30 shadow-[0_0_15px_rgba(34,211,238,0.15)]">
                <i class="fa-solid fa-user-gear mr-1"></i>
                <?php echo htmlspecialchars($user['username']); ?>
            </span>
        </div>
    </div>

    <!-- Alerts with neon borders -->
    <?php if ($success): ?>
        <div class="relative rounded-xl bg-green-900/20 border-2 border-green-400/50 shadow-[0_0_20px_rgba(34,197,94,0.2)] text-green-400 text-sm p-4 flex items-center space-x-2.5">
            <i class="fa-solid fa-circle-check text-base"></i>
            <span><?php echo htmlspecialchars($success); ?></span>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="relative rounded-xl bg-red-900/20 border-2 border-red-500/50 shadow-[0_0_20px_rgba(239,68,68,0.2)] text-red-400 text-sm p-4 flex items-center space-x-2.5">
            <i class="fa-solid fa-circle-exclamation text-base"></i>
            <span><?php echo htmlspecialchars($error); ?></span>
        </div>
    <?php endif; ?>

    <!-- Three Cards Grid with Different Colored Borders -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">
        
        <!-- Profile Card - Cyan Border (like wallet balance) -->
        <div class="relative rounded-2xl bg-neutral-900 border-2 border-cyan-400/70 shadow-[0_0_22px_rgba(34,211,238,0.22)] p-5 transition-all duration-300 hover:shadow-[0_0_35px_rgba(34,211,238,0.35)] hover:scale-[1.01]">
            <h3 class="text-base font-bold mb-4 flex items-center space-x-2 text-white">
                <i class="fa-solid fa-user-gear text-cyan-400"></i>
                <span>Update Profile</span>
            </h3>
            
            <form method="POST" class="space-y-4">
                <?php echo csrf_field(); ?>
                <div>
                    <label for="username" class="block text-[10px] font-bold uppercase tracking-wider text-cyan-300/70 mb-1.5">Username *</label>
                    <input type="text" id="username" name="username" required
                           value="<?php echo htmlspecialchars($user['username']); ?>"
                           class="w-full px-4 py-2.5 bg-neutral-800 border-2 border-cyan-500/30 rounded-xl text-sm focus:outline-none focus:border-cyan-400 text-white placeholder-neutral-500">
                </div>

                <div>
                    <label for="email" class="block text-[10px] font-bold uppercase tracking-wider text-cyan-300/70 mb-1.5">Email Address *</label>
                    <input type="email" id="email" name="email" required
                           value="<?php echo htmlspecialchars($user['email']); ?>"
                           class="w-full px-4 py-2.5 bg-neutral-800 border-2 border-cyan-500/30 rounded-xl text-sm focus:outline-none focus:border-cyan-400 text-white placeholder-neutral-500">
                </div>

                <button type="submit" name="update_profile" class="w-full py-3 rounded-xl bg-gradient-to-r from-cyan-600 to-cyan-700 hover:from-cyan-700 hover:to-cyan-800 active:scale-[0.98] transition-all duration-300 text-sm font-bold text-white shadow-[0_0_22px_rgba(34,211,238,0.4)] flex items-center justify-center gap-2">
                    <i class="fa-solid fa-floppy-disk"></i>
                    <span>Save Profile</span>
                </button>
            </form>
        </div>

        <!-- Password Card - Purple Border (like wallet form) -->
        <div class="relative rounded-2xl bg-neutral-900 border-2 border-purple-500/70 shadow-[0_0_22px_rgba(168,85,247,0.22)] p-5 transition-all duration-300 hover:shadow-[0_0_35px_rgba(168,85,247,0.35)] hover:scale-[1.01]">
            <h3 class="text-base font-bold mb-4 flex items-center space-x-2 text-white">
                <i class="fa-solid fa-lock text-purple-400"></i>
                <span>Change Password</span>
            </h3>
            
            <form method="POST" class="space-y-4">
                <?php echo csrf_field(); ?>
                <div>
                    <label for="current_password" class="block text-[10px] font-bold uppercase tracking-wider text-purple-300/70 mb-1.5">Current Password *</label>
                    <input type="password" id="current_password" name="current_password" required
                           class="w-full px-4 py-2.5 bg-neutral-800 border-2 border-purple-500/30 rounded-xl text-sm focus:outline-none focus:border-purple-400 text-white placeholder-neutral-500">
                </div>

                <div>
                    <label for="new_password" class="block text-[10px] font-bold uppercase tracking-wider text-purple-300/70 mb-1.5">New Password *</label>
                    <input type="password" id="new_password" name="new_password" required
                           class="w-full px-4 py-2.5 bg-neutral-800 border-2 border-purple-500/30 rounded-xl text-sm focus:outline-none focus:border-purple-400 text-white placeholder-neutral-500"
                           placeholder="Min 6 characters">
                </div>

                <div>
                    <label for="confirm_password" class="block text-[10px] font-bold uppercase tracking-wider text-purple-300/70 mb-1.5">Confirm New Password *</label>
                    <input type="password" id="confirm_password" name="confirm_password" required
                           class="w-full px-4 py-2.5 bg-neutral-800 border-2 border-purple-500/30 rounded-xl text-sm focus:outline-none focus:border-purple-400 text-white placeholder-neutral-500">
                </div>

                <button type="submit" name="change_password" class="w-full py-3 rounded-xl bg-gradient-to-r from-purple-600 to-purple-700 hover:from-purple-700 hover:to-purple-800 active:scale-[0.98] transition-all duration-300 text-sm font-bold text-white shadow-[0_0_22px_rgba(168,85,247,0.4)] flex items-center justify-center gap-2">
                    <i class="fa-solid fa-key"></i>
                    <span>Update Password</span>
                </button>
            </form>
        </div>

        <!-- Device Reset Card - Red Border (like danger/alert) -->
        <div class="relative rounded-2xl bg-neutral-900 border-2 border-red-500/70 shadow-[0_0_22px_rgba(239,68,68,0.22)] p-5 transition-all duration-300 hover:shadow-[0_0_35px_rgba(239,68,68,0.35)] hover:scale-[1.01]">
            <h3 class="text-base font-bold mb-4 flex items-center space-x-2 text-white">
                <i class="fa-solid fa-rotate text-red-400"></i>
                <span>Device Authorization</span>
            </h3>
            <p class="text-xs text-neutral-400 leading-relaxed mb-4">Reset device logs if you are unable to login due to device locked restrictions.</p>
            
            <form method="POST" class="space-y-4">
                <?php echo csrf_field(); ?>
                <div>
                    <label for="password" class="block text-[10px] font-bold uppercase tracking-wider text-red-300/70 mb-1.5">Confirm Password *</label>
                    <input type="password" id="password" name="password" required
                           class="w-full px-4 py-2.5 bg-neutral-800 border-2 border-red-500/30 rounded-xl text-sm focus:outline-none focus:border-red-400 text-white placeholder-neutral-500"
                           placeholder="Enter account password">
                </div>

                <button type="submit" name="reset_device" class="w-full py-3 rounded-xl bg-red-600/20 border-2 border-red-500/50 text-red-400 hover:bg-red-600/30 active:scale-[0.98] transition-all duration-300 text-sm font-bold shadow-[0_0_22px_rgba(239,68,68,0.2)] flex items-center justify-center gap-2">
                    <i class="fa-solid fa-arrows-rotate"></i>
                    <span>Reset Authorized Device</span>
                </button>
            </form>
        </div>
    </div>
</div>

<style>
.text-glow-cyan {
    text-shadow: 0 0 20px rgba(34,211,238,0.3);
}

/* Animation */
@keyframes fadeInUp {
    from {
        opacity: 0;
        transform: translateY(20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.animate-fade-in {
    animation: fadeInUp 0.5s ease-out forwards;
}

/* Smooth hover transitions */
.rounded-2xl {
    transition: all 0.3s ease-in-out;
}

/* Input focus glow */
input:focus {
    box-shadow: 0 0 20px rgba(34,211,238,0.1);
}

/* Card-specific glow on hover */
.border-cyan-400\/70:hover {
    box-shadow: 0 0 35px rgba(34,211,238,0.35);
}
.border-purple-500\/70:hover {
    box-shadow: 0 0 35px rgba(168,85,247,0.35);
}
.border-red-500\/70:hover {
    box-shadow: 0 0 35px rgba(239,68,68,0.35);
}
</style>

<?php
require_once 'includes/footer.php';
?>