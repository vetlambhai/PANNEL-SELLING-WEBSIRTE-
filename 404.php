<?php
// Send 404 status header
header("HTTP/1.1 404 Not Found");
require_once 'config/database.php';
?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <link rel="canonical" href="https://keysmaster.shop/">
    <title>404 Page Not Found - <?php echo SITE_NAME . ' ' . SITE_SUBTITLE; ?></title>
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
    </style>
</head>
<body class="bg-brand-bg text-brand-text font-sans antialiased min-h-screen flex items-center justify-center p-4">

<div class="w-full max-w-md text-center">
    <!-- Brand Logo -->
    <div class="flex flex-col items-center mb-8">
        <div class="w-14 h-14 rounded-2xl bg-gradient-to-tr from-brand-primary to-brand-secondary flex items-center justify-center shadow-glow mb-4">
            <i class="fa-solid fa-gem text-white text-2xl animate-bounce"></i>
        </div>
        <h1 class="text-2xl font-extrabold tracking-tight bg-gradient-to-r from-brand-primary to-brand-secondary bg-clip-text text-transparent"><?php echo SITE_NAME; ?></h1>
        <p class="text-[10px] text-brand-muted uppercase font-bold tracking-wider mt-1"><?php echo SITE_SUBTITLE; ?></p>
    </div>

    <!-- 404 Glass Card -->
    <div class="bg-white border border-gray-200 rounded-xl shadow-sm p-6 dark:bg-neutral-900 dark:border-neutral-700">
        <h2 class="text-6xl font-black text-glow-primary tracking-wider text-brand-primary mb-2">404</h2>
        <h3 class="text-lg font-bold tracking-tight text-brand-text mb-2">Page Not Found</h3>
        <p class="text-xs text-brand-muted leading-relaxed mb-8">
            The page you are looking for might have been removed, had its name changed, or is temporarily unavailable.
        </p>

        <a href="/index.php" class="w-full py-3 bg-gradient-to-r from-brand-primary to-brand-secondary hover:opacity-90 active:scale-[0.98] transition-all duration-300 rounded-xl text-sm font-bold text-white shadow-glow flex items-center justify-center space-x-2">
            <i class="fa-solid fa-house"></i>
            <span>Back to Safety</span>
        </a>
    </div>
</div>

</body>
</html>
