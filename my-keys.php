<?php
require_once 'includes/auth.php';
require_once 'includes/functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

// Check if user is admin
if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin') {
    header('Location: admin.php');
    exit();
}

$pdo = getDBConnection();
$userId = $_SESSION['user_id'];

// Get user data
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// Get all purchased keys for this user (full history)
$stmt = $pdo->prepare("SELECT lk.*, m.name as mod_name, m.image_url, m.video_url
                      FROM license_keys lk
                      LEFT JOIN mods m ON lk.mod_id = m.id
                      WHERE lk.sold_to = ? AND lk.sold_at IS NOT NULL
                      ORDER BY lk.sold_at DESC");
$stmt->execute([$userId]);
$purchasedKeys = $stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = "My Keys - " . SITE_NAME . " " . SITE_SUBTITLE;
require_once 'includes/header.php';
?>

<!-- ===== My Keys Page with Enhanced Border Effects (Like Wallet) ===== -->
<div class="max-w-6xl mx-auto space-y-5 animate-fade-in">

    <!-- Header with neon border effect -->
    <div class="relative rounded-2xl p-5 bg-neutral-900 border-2 border-cyan-400/70 shadow-[0_0_20px_rgba(34,211,238,0.25)] dark:bg-neutral-900">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
            <div>
                <h2 class="text-2xl font-bold tracking-tight text-white text-glow-cyan">My Keys</h2>
            </div>
            <span class="text-xs font-bold text-cyan-300 bg-cyan-500/10 px-4 py-2 rounded-full border border-cyan-400/30 shadow-[0_0_15px_rgba(34,211,238,0.15)]">
                <i class="fa-solid fa-key mr-1"></i>
                <?php echo count($purchasedKeys); ?> Keys
            </span>
        </div>
    </div>

    <!-- My Keys List with purple neon border (like wallet form) -->
    <div class="relative rounded-2xl bg-neutral-900 border-2 border-purple-500/70 shadow-[0_0_22px_rgba(168,85,247,0.22)] p-5">
        <h3 class="text-base font-bold mb-4 flex items-center space-x-2 text-white">
            <i class="fa-solid fa-vault text-purple-400"></i>
            <span>Purchased Keys History</span>
        </h3>

        <div class="space-y-4">
            <?php if (empty($purchasedKeys)): ?>
                <div class="text-center py-12">
                    <i class="fa-solid fa-box-open text-4xl mb-3 text-cyan-400/50 block"></i>
                    <h4 class="text-base font-bold text-white mb-1">No Keys Purchased Yet</h4>
                    <p class="text-xs text-neutral-400 max-w-sm mx-auto">You have not purchased any keys yet. Visit the store to buy keys.</p>
                    <a href="keys" class="inline-flex items-center justify-center gap-2 mt-5 h-10 px-5 bg-purple-600 hover:bg-purple-700 active:scale-[0.98] transition-all text-white text-xs font-bold rounded-xl shadow-[0_0_22px_rgba(168,85,247,0.5)]">
                        <i class="fa-solid fa-store"></i>
                        <span>Go to Buy Keys</span>
                    </a>
                </div>
            <?php else: ?>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <?php 
                    $borderColors = ['border-green-400/70 shadow-[0_0_20px_rgba(34,197,94,0.22)]', 
                                    'border-cyan-400/70 shadow-[0_0_20px_rgba(34,211,238,0.25)]',
                                    'border-purple-500/70 shadow-[0_0_22px_rgba(168,85,247,0.22)]',
                                    'border-yellow-400/70 shadow-[0_0_20px_rgba(234,179,8,0.22)]',
                                    'border-pink-400/70 shadow-[0_0_20px_rgba(236,72,153,0.22)]',
                                    'border-blue-400/70 shadow-[0_0_20px_rgba(96,165,250,0.22)]'];
                    $colorIndex = 0;
                    foreach ($purchasedKeys as $key): 
                        $colorClass = $borderColors[$colorIndex % count($borderColors)];
                        $colorIndex++;
                    ?>
                        <div class="relative rounded-2xl bg-neutral-900 border-2 <?php echo $colorClass; ?> p-4 flex flex-col justify-between transition-all duration-300 hover:scale-[1.02]">
                            <!-- Animated pulse effect -->
                            <div class="absolute inset-0 rounded-2xl bg-gradient-to-r from-transparent via-white/5 to-transparent animate-pulse"></div>
                            
                            <div class="relative z-10">
                                <div class="flex justify-between items-start mb-2">
                                    <span class="text-sm font-bold text-white"><?php echo htmlspecialchars($key['mod_name']); ?></span>
                                    <span class="px-2 py-1 rounded-lg text-[9px] font-bold uppercase bg-green-500/10 text-green-400 border border-green-400/30">
                                        <?php echo $key['duration'] . ' ' . $key['duration_type']; ?>
                                    </span>
                                </div>

                                <!-- Key display with cyan glow -->
                                <div class="flex items-center space-x-2 bg-neutral-800 border border-cyan-400/30 px-3 py-2 rounded-lg mb-2 shadow-[0_0_15px_rgba(34,211,238,0.1)]">
                                    <?php $fullKey = displayKey($key); ?>
                                    <span class="font-mono text-xs text-white truncate flex-1" title="<?php echo htmlspecialchars($fullKey); ?>"><?php echo htmlspecialchars($fullKey); ?></span>
                                    <button onclick="copyToClipboard('<?php echo htmlspecialchars($fullKey, ENT_QUOTES); ?>', this)" class="text-cyan-400 hover:text-cyan-300 transition-colors p-1 hover:scale-110">
                                        <i class="fa-regular fa-copy"></i>
                                    </button>
                                </div>

                                <?php if (!empty($key['device_id'])): ?>
                                    <div class="flex items-center justify-between text-[9px] text-neutral-400 mb-1">
                                        <span><i class="fa-solid fa-mobile-screen-button mr-1 text-cyan-400"></i>Device ID:</span>
                                        <span class="font-mono truncate ml-2 text-cyan-300/70"><?php echo htmlspecialchars($key['device_id']); ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="relative z-10 flex items-center justify-between text-[9px] text-neutral-400 border-t border-neutral-700/50 pt-2 mt-2">
                                <span class="font-bold text-green-400"><i class="fa-solid fa-indian-rupee-sign mr-0.5"></i><?php echo formatCurrency($key['price']); ?></span>
                                <span><i class="fa-regular fa-calendar mr-1 text-cyan-400"></i><?php echo formatDate($key['sold_at']); ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function copyToClipboard(text, btn) {
    navigator.clipboard.writeText(text).then(() => {
        const icon = btn.querySelector('i');
        icon.className = 'fa-solid fa-check text-green-400';
        setTimeout(() => {
            icon.className = 'fa-regular fa-copy text-cyan-400';
        }, 2000);
    });
}
</script>

<style>
.text-glow-cyan {
    text-shadow: 0 0 20px rgba(34,211,238,0.3);
}

/* Smooth animation for key cards */
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

/* Glass effect for cards */
.glass-card {
    background: rgba(23, 23, 23, 0.8);
    backdrop-filter: blur(10px);
}
</style>

<?php
require_once 'includes/footer.php';
?>