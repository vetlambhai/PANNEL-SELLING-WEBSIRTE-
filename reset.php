<?php
require_once 'includes/auth.php';

// Redirect if already logged in
if (isLoggedIn()) {
    if (isAdmin()) {
        header('Location: admin.php');
    } else {
        header('Location: dashboard.php');
    }
    exit();
}

$error = '';
$success = '';

if ($_POST) {
    verify_csrf();
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    
    if (empty($username) || empty($password)) {
        $error = 'Please fill in all fields';
    } else {
        $resetResult = resetDevice($username, $password);
        if ($resetResult === 'success') {
            $success = 'Successfully logged out from all devices. You can now login from any device.';
        } else if ($resetResult === 'user_not_found') {
            $error = 'Username or email not found';
        } else if ($resetResult === 'invalid_password') {
            $error = 'Invalid password';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Authorized Device - <?php echo SITE_NAME . ' ' . SITE_SUBTITLE; ?></title>
    <!-- Fonts & Icons -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
      tailwind.config = {
        darkMode: 'class',
        theme: {
          extend: {
            colors: {
              brand: {
                primary: '#6366f1',
                secondary: '#06b6d4',
                bg: '#0b0f19',
                surface: '#111827',
                border: '#1f2937',
                text: '#f9fafb',
                muted: '#9ca3af',
                success: '#10b981',
                warning: '#f59e0b',
                error: '#ef4444'
              }
            },
            fontFamily: {
              sans: ['Inter', 'sans-serif'],
            },
            boxShadow: {
              glow: '0 0 15px rgba(99, 102, 241, 0.15)',
              'glow-cyan': '0 0 15px rgba(6, 182, 212, 0.15)'
            }
          }
        }
      }
    </script>
    <style type="text/tailwindcss">
      .bg-brand-surface border border-brand-border rounded-xl {
        background-color: rgba(17, 24, 39, 0.6);
        border: 1px solid rgba(31, 41, 55, 0.5);
        backdrop-filter: blur(8px);
        box-shadow: 0 0 15px rgba(99, 102, 241, 0.15);
      }

      .text-glow-primary {
        text-shadow: 0 0 10px rgba(99, 102, 241, 0.4);
      }

      body {
        background-color: #0b0f19;
        color: #f9fafb;
        font-family: 'Inter', sans-serif;
        min-height: 100vh;
        background-image: 
          radial-gradient(circle at 10% 20%, rgba(99, 102, 241, 0.05) 0%, transparent 40%),
          radial-gradient(circle at 90% 80%, rgba(6, 182, 212, 0.05) 0%, transparent 40%);
      }

      /* Dropdown option styles */
      select option {
        background-color: #111827;
        color: #f9fafb;
      }
    </style>
</head>
<body class="bg-brand-bg text-brand-text font-sans antialiased min-h-screen flex items-center justify-center p-4">

<div class="w-full max-w-md">
    <!-- Brand Logo -->
    <div class="flex flex-col items-center mb-8">
        <div class="w-14 h-14 rounded-2xl bg-gradient-to-tr from-brand-primary to-brand-secondary flex items-center justify-center shadow-glow mb-4">
            <i class="fa-solid fa-gem text-white text-2xl animate-pulse"></i>
        </div>
        <h1 class="text-2xl font-extrabold tracking-tight bg-gradient-to-r from-brand-primary to-brand-secondary bg-clip-text text-transparent"><?php echo SITE_NAME; ?></h1>
        <p class="text-xs text-brand-muted uppercase font-bold tracking-wider mt-1"><?php echo SITE_SUBTITLE; ?> Reset Vault</p>
    </div>

    <!-- Reset Card -->
    <div class="bg-white border border-gray-200 rounded-xl shadow-sm p-6 dark:bg-neutral-900 dark:border-neutral-700">
        <div class="mb-6 text-center">
            <h2 class="text-xl font-bold tracking-tight">Reset Authorized Device</h2>
            <p class="text-xs text-brand-muted mt-1.5 leading-relaxed">Confirm your credentials to unlock and reset device logs.</p>
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

        <form method="POST" class="space-y-4">
    <?php echo csrf_field(); ?>
            <div>
                <label for="username" class="block text-xs font-semibold text-brand-muted mb-1.5">Username or Email</label>
                <div class="relative">
                    <span class="absolute inset-y-0 left-0 flex items-center pl-3 text-brand-muted/60">
                        <i class="fa-regular fa-user"></i>
                    </span>
                    <input type="text" id="username" name="username" required
                           value="<?php echo htmlspecialchars($username ?? ''); ?>"
                           class="w-full pl-10 pr-4 py-2.5 bg-brand-bg/50 border border-brand-border rounded-xl text-sm focus:outline-none focus:border-brand-primary text-brand-text placeholder-brand-muted/30"
                           placeholder="Enter username">
                </div>
            </div>

            <div>
                <label for="password" class="block text-xs font-semibold text-brand-muted mb-1.5">Account Password</label>
                <div class="relative">
                    <span class="absolute inset-y-0 left-0 flex items-center pl-3 text-brand-muted/60">
                        <i class="fa-solid fa-lock"></i>
                    </span>
                    <input type="password" id="password" name="password" required
                           class="w-full pl-10 pr-4 py-2.5 bg-brand-bg/50 border border-brand-border rounded-xl text-sm focus:outline-none focus:border-brand-primary text-brand-text placeholder-brand-muted/30"
                           placeholder="••••••••">
                </div>
            </div>

            <button type="submit" class="w-full py-3 bg-brand-error hover:opacity-90 active:scale-[0.98] transition-all duration-300 rounded-xl text-sm font-bold text-white shadow-glow flex items-center justify-center space-x-2">
                <i class="fa-solid fa-arrows-rotate"></i>
                <span>Reset Authorized Device</span>
            </button>
        </form>

        <div class="mt-6 pt-6 border-t border-brand-border/60 text-center">
            <a href="login.php" class="text-xs text-brand-primary font-bold hover:opacity-90 flex items-center justify-center space-x-1.5">
                <i class="fa-solid fa-arrow-left-long"></i>
                <span>Return to Login</span>
            </a>
        </div>
    </div>
</div>

</body>
</html>
