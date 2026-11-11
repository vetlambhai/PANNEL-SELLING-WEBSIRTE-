<?php
require_once 'config/database.php';
require_once 'includes/reseller_provider.php';

// Check if user is logged in
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

// Check if user is admin (redirect to admin panel)
if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin') {
    header('Location: admin.php');
    exit();
}

$pdo = getDBConnection();

// Get user data
$userId = $_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// Determine if this user is a reseller (resellers bhi role='user' hote hain, is_reseller=1 se identify)
$isReseller = isUserReseller($userId);

// ---------------------------------------------------------------------------
// TODAY SALES  +  MONTHLY SALES
// "Sales" = keys sold by THIS user (license_keys.status='sold' AND sold_to = userId)
// Sub-stats: U: = keys sold to normal users, Me: = keys sold to me (resold by others) 
//   Per requirement screenshot: U: (cyan) and Me: (purple) indicators.
//   We interpret: U = unique users (distinct buyers), Me = my own repurchased keys count.
// ---------------------------------------------------------------------------

// Today sales count + revenue
$todaySales = ['count' => 0, 'revenue' => 0, 'u' => 0, 'me' => 0];
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) AS cnt, COALESCE(SUM(lk.price),0) AS rev
                           FROM license_keys lk
                           WHERE lk.status = 'sold' AND lk.sold_to = ?
                             AND DATE(lk.sold_at) = CURDATE()");
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $todaySales['count']   = (int)$row['cnt'];
        $todaySales['revenue'] = (float)$row['rev'];
    }
    // Sub-stats mapping (per screenshot): U = units/keys count, Me = money/revenue
    $todaySales['u']  = $todaySales['count'];
    $todaySales['me'] = $todaySales['revenue'];
} catch (Exception $e) {
    error_log("Dashboard today sales error: " . $e->getMessage());
}

// Monthly sales count + revenue (current month)
$monthlySales = ['count' => 0, 'revenue' => 0, 'u' => 0, 'me' => 0];
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) AS cnt, COALESCE(SUM(lk.price),0) AS rev
                           FROM license_keys lk
                           WHERE lk.status = 'sold' AND lk.sold_to = ?
                             AND YEAR(lk.sold_at) = YEAR(CURDATE())
                             AND MONTH(lk.sold_at) = MONTH(CURDATE())");
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $monthlySales['count']   = (int)$row['cnt'];
        $monthlySales['revenue'] = (float)$row['rev'];
    }
    $monthlySales['u']  = $monthlySales['count'];
    $monthlySales['me'] = $monthlySales['revenue'];
} catch (Exception $e) {
    error_log("Dashboard monthly sales error: " . $e->getMessage());
}

// ---------------------------------------------------------------------------
// 5 TOP RESELLERS  (admin-managed or automatic)
// ---------------------------------------------------------------------------
$topResellers = getDisplayTopResellers(5);

// ---------------------------------------------------------------------------
// USER-ONLY: Reseller progress [x/500]
// Normal user ke liye: kitna spend karne ke baad reseller milega.
// total_spent (deposits + purchases) vs auto_threshold (default 500).
// ---------------------------------------------------------------------------
$resellerProgress = ['spent' => 0, 'threshold' => 500, 'remaining' => 500, 'is_reseller' => false];
try {
    $cfg = getResellerProviderConfig();
    $resellerProgress['threshold'] = (float)($cfg['auto_threshold'] ?? 500);
    $resellerProgress['spent']     = getUserTotalSpent($userId);
    $resellerProgress['remaining'] = max(0, $resellerProgress['threshold'] - $resellerProgress['spent']);
    $resellerProgress['is_reseller'] = $isReseller;
} catch (Exception $e) {
    // fail silently
}

// ---------------------------------------------------------------------------
// Build last 7 days template for analytics chart (kept for compatibility)
// ---------------------------------------------------------------------------
$chartData = [];
for ($i = 6; $i >= 0; $i--) {
    $dateStr = date('Y-m-d', strtotime("-$i days"));
    $labelStr = date('D d', strtotime("-$i days"));
    $chartData[$dateStr] = [
        'label' => $labelStr,
        'sales_count' => 0,
        'revenue' => 0
    ];
}

try {
    $stmt = $pdo->prepare("SELECT DATE(sold_at) as date_grp, COUNT(*) as count 
                           FROM license_keys 
                           WHERE status = 'sold' AND sold_to = ? AND sold_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) 
                           GROUP BY DATE(sold_at)");
    $stmt->execute([$userId]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $dVal = $row['date_grp'];
        if (isset($chartData[$dVal])) {
            $chartData[$dVal]['sales_count'] = (int)$row['count'];
        }
    }
} catch (Exception $e) {}

try {
    $stmt = $pdo->prepare("SELECT DATE(created_at) as date_grp, SUM(ABS(amount)) as total 
                           FROM transactions 
                           WHERE user_id = ? AND type = 'purchase' AND status = 'completed' AND created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) 
                           GROUP BY DATE(created_at)");
    $stmt->execute([$userId]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $dVal = $row['date_grp'];
        if (isset($chartData[$dVal])) {
            $chartData[$dVal]['revenue'] = (float)$row['total'];
        }
    }
} catch (Exception $e) {}

// Helper functions
function formatCurrency($amount) {
    return '&#8377;' . number_format($amount, 2);
}

function formatDate($date) {
    if (empty($date)) return 'N/A';
    $timestamp = strtotime($date);
    if ($timestamp === false) return 'Invalid Date';
    return date('d M Y H:i', $timestamp);
}

$page_title = "Dashboard - " . SITE_NAME . " " . SITE_SUBTITLE;
require_once 'includes/header.php';

// Progress percent for reseller progress bar
$progressPct = 0;
if ($resellerProgress['threshold'] > 0) {
    $progressPct = min(100, ($resellerProgress['spent'] / $resellerProgress['threshold']) * 100);
}
?>

<!-- ============================================================ -->
<!-- Wallet Balance Box (centered, at top - magenta border)       -->
<!-- ============================================================ -->
<div class="flex justify-center mb-6">
    <div class="relative rounded-2xl p-6 bg-neutral-900 border-2 border-pink-500/80 shadow-[0_0_25px_rgba(236,72,153,0.30)] dark:bg-neutral-900 text-center w-full max-w-md">
        <div class="flex items-center justify-center gap-2 text-pink-300 mb-1">
            <i class="fa-solid fa-wallet text-sm"></i>
            <span class="text-[10px] font-bold uppercase tracking-wider">Total Current Balance</span>
        </div>
        <p class="text-3xl font-black text-white text-glow-primary"><?php echo formatCurrency($user['balance'] ?: 0); ?></p>
    </div>
</div>

<?php if (!$isReseller): ?>
<!-- ============================================================ -->
<!-- USER ONLY: Reseller Progress [x/500] below Wallet Balance    -->
<!-- Normal user kitna spend karne ke baad reseller milega        -->
<!-- ============================================================ -->
<div class="flex justify-center mb-6">
    <div class="relative rounded-2xl p-5 bg-neutral-900 border-2 border-purple-400/70 shadow-[0_0_20px_rgba(168,85,247,0.25)] dark:bg-neutral-900 w-full max-w-md">
        <div class="flex items-center justify-between mb-3">
            <div class="flex items-center gap-2 text-purple-300">
                <i class="fa-solid fa-user-shield text-sm"></i>
                <span class="text-[10px] font-bold uppercase tracking-wider">Reseller Unlock Progress</span>
            </div>
            <span class="text-sm font-black text-white tabular-nums">
                <span class="text-purple-300">[<?php echo number_format($resellerProgress['spent'], 0); ?>/<?php echo number_format($resellerProgress['threshold'], 0); ?>]</span>
            </span>
        </div>
        <!-- Progress bar -->
        <div class="w-full h-3 rounded-full bg-neutral-800 border border-neutral-700 overflow-hidden">
            <div class="h-full rounded-full bg-gradient-to-r from-purple-500 to-pink-500 transition-all duration-500" style="width: <?php echo $progressPct; ?>%"></div>
        </div>
        <?php if ($resellerProgress['remaining'] > 0): ?>
            <p class="text-[11px] text-neutral-400 mt-2.5 text-center">
                Spend <span class="font-bold text-pink-400"><?php echo formatCurrency($resellerProgress['remaining']); ?></span> more to become a Reseller
            </p>
        <?php else: ?>
            <p class="text-[11px] text-green-400 mt-2.5 text-center font-bold">
                <i class="fa-solid fa-circle-check"></i> Eligible! You will become a Reseller shortly.
            </p>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- ============================================================ -->
<!-- Stats Row: Today Sales (blue) + Monthly Sales (cyan)         -->
<!-- ============================================================ -->
<div class="grid grid-cols-2 gap-3 mb-6">
    <!-- Today Sales - Left -->
    <div class="relative rounded-2xl p-5 bg-neutral-900 border-2 border-blue-400/70 shadow-[0_0_20px_rgba(59,130,246,0.25)] dark:bg-neutral-900 text-center">
        <div class="flex items-center justify-center gap-2 mb-2">
            <i class="fa-solid fa-cart-shopping text-blue-300 text-sm"></i>
            <p class="text-[10px] font-bold uppercase tracking-wider text-blue-300">Today Sales</p>
        </div>
        <h3 class="text-3xl font-black text-white"><?php echo $todaySales['count']; ?></h3>
        <div class="flex items-center justify-center gap-4 mt-2 text-[11px]">
            <span class="text-cyan-400 font-bold">U: <?php echo $todaySales['u']; ?></span>
            <span class="text-purple-400 font-bold">Me: <?php echo formatCurrency($todaySales['me']); ?></span>
        </div>
    </div>

    <!-- Monthly Sales - Right -->
    <div class="relative rounded-2xl p-5 bg-neutral-900 border-2 border-cyan-400/70 shadow-[0_0_20px_rgba(34,211,238,0.25)] dark:bg-neutral-900 text-center">
        <div class="flex items-center justify-center gap-2 mb-2">
            <i class="fa-solid fa-calendar-days text-cyan-300 text-sm"></i>
            <p class="text-[10px] font-bold uppercase tracking-wider text-cyan-300">Monthly Sales</p>
        </div>
        <h3 class="text-3xl font-black text-white"><?php echo $monthlySales['count']; ?></h3>
        <div class="flex items-center justify-center gap-4 mt-2 text-[11px]">
            <span class="text-cyan-400 font-bold">U: <?php echo $monthlySales['u']; ?></span>
            <span class="text-purple-400 font-bold">Me: <?php echo formatCurrency($monthlySales['me']); ?></span>
        </div>
    </div>
</div>

<!-- ============================================================ -->
<!-- 5 TOP RESELLERS (gold/yellow border, crown icon, table)      -->
<!-- Data: admin-managed OR automatic top 5 by keys+revenue       -->
<!-- ============================================================ -->
<div class="relative rounded-2xl p-6 bg-neutral-900 border-2 border-yellow-400/80 shadow-[0_0_25px_rgba(250,204,21,0.25)] dark:bg-neutral-900 mb-6">
    <div class="flex items-center justify-between mb-5 flex-wrap gap-2">
        <div class="flex items-center gap-2.5">
            <div class="w-8 h-8 rounded-lg bg-yellow-500/15 border border-yellow-500/30 flex items-center justify-center text-yellow-400">
                <i class="fa-solid fa-crown text-sm"></i>
            </div>
            <h4 class="text-base font-bold text-white">5 TOP RESELLERS</h4>
        </div>
        <span class="text-[10px] text-yellow-300/70 font-semibold uppercase tracking-wider">Leaderboard</span>
    </div>

    <div class="overflow-x-auto">
        <table class="w-full text-left border-collapse">
            <thead>
                <tr class="border-b border-yellow-500/30 text-yellow-300 text-[10px] uppercase font-bold tracking-wider">
                    <th class="pb-3 w-12">Rank</th>
                    <th class="pb-3">Username</th>
                    <th class="pb-3 text-center">Role</th>
                    <th class="pb-3 text-center">Keys</th>
                    <th class="pb-3 text-right">Buy</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-yellow-500/15 text-sm">
                <?php if (empty($topResellers)): ?>
                <tr>
                    <td colspan="5" class="text-center text-yellow-300/50 py-8">
                        <i class="fa-solid fa-crown text-2xl mb-2 opacity-40 block"></i>
                        No resellers yet
                    </td>
                </tr>
                <?php else: ?>
                    <?php
                    $rankStyles = [
                        1 => 'text-yellow-400',
                        2 => 'text-gray-300',
                        3 => 'text-amber-600',
                        4 => 'text-neutral-400',
                        5 => 'text-neutral-400',
                    ];
                    foreach ($topResellers as $r):
                        $rStyle = $rankStyles[$r['rank']] ?? 'text-neutral-400';
                    ?>
                    <tr class="hover:bg-yellow-500/5 transition-colors">
                        <td class="py-3 font-black <?php echo $rStyle; ?>">#<?php echo $r['rank']; ?></td>
                        <td class="py-3">
                            <span class="font-bold text-white">@<?php echo htmlspecialchars($r['username']); ?></span>
                        </td>
                        <td class="py-3 text-center">
                            <span class="px-2 py-0.5 rounded-full bg-yellow-500/15 text-yellow-400 border border-yellow-500/30 text-[10px] font-bold uppercase tracking-wider">RESELLER</span>
                        </td>
                        <td class="py-3 text-center font-bold text-cyan-400"><?php echo number_format($r['keys']); ?> Keys</td>
                        <td class="py-3 text-right font-bold text-green-400 text-xs"><?php echo formatCurrency($r['buy']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ============================================================ -->
<!-- QUICK BUTTONS - 2 rows x 3 buttons                           -->
<!-- Row 1: DEPOSIT, BUY KEYS, MY KEYS                            -->
<!-- Row 2: HISTORY, REFERRAL (disabled), PROFILE                 -->
<!-- ============================================================ -->
<div class="grid grid-cols-3 gap-3 mb-6">
    <!-- Row 1 -->
    <a href="wallet" class="group relative rounded-2xl p-4 bg-neutral-900 border-2 border-teal-400/70 shadow-[0_0_18px_rgba(20,184,166,0.22)] dark:bg-neutral-900 text-center hover:border-teal-400 hover:shadow-[0_0_28px_rgba(20,184,166,0.4)] transition-all duration-300 active:scale-95">
        <i class="fa-solid fa-wallet text-2xl text-teal-400 mb-2 block"></i>
        <span class="text-[11px] font-bold uppercase tracking-wider text-teal-300">Deposit</span>
    </a>
    <a href="keys" class="group relative rounded-2xl p-4 bg-neutral-900 border-2 border-red-400/70 shadow-[0_0_18px_rgba(248,113,113,0.22)] dark:bg-neutral-900 text-center hover:border-red-400 hover:shadow-[0_0_28px_rgba(248,113,113,0.4)] transition-all duration-300 active:scale-95">
        <i class="fa-solid fa-key text-2xl text-red-400 mb-2 block"></i>
        <span class="text-[11px] font-bold uppercase tracking-wider text-red-300">Buy Keys</span>
    </a>
    <a href="my-keys" class="group relative rounded-2xl p-4 bg-neutral-900 border-2 border-orange-400/70 shadow-[0_0_18px_rgba(251,146,60,0.22)] dark:bg-neutral-900 text-center hover:border-orange-400 hover:shadow-[0_0_28px_rgba(251,146,60,0.4)] transition-all duration-300 active:scale-95">
        <i class="fa-solid fa-box-open text-2xl text-orange-400 mb-2 block"></i>
        <span class="text-[11px] font-bold uppercase tracking-wider text-orange-300">My Keys</span>
    </a>

    <!-- Row 2 -->
    <a href="history" class="group relative rounded-2xl p-4 bg-neutral-900 border-2 border-teal-400/70 shadow-[0_0_18px_rgba(20,184,166,0.22)] dark:bg-neutral-900 text-center hover:border-teal-400 hover:shadow-[0_0_28px_rgba(20,184,166,0.4)] transition-all duration-300 active:scale-95">
        <i class="fa-solid fa-clock-rotate-left text-2xl text-teal-400 mb-2 block"></i>
        <span class="text-[11px] font-bold uppercase tracking-wider text-teal-300">History</span>
    </a>
    <!-- REFERRAL button - ENABLED (links to Refer and Earn page) -->
    <a href="refer-earn" class="group relative rounded-2xl p-4 bg-neutral-900 border-2 border-emerald-400/70 shadow-[0_0_18px_rgba(16,185,129,0.22)] dark:bg-neutral-900 text-center hover:border-emerald-400 hover:shadow-[0_0_28px_rgba(16,185,129,0.4)] transition-all duration-300 active:scale-95">
        <i class="fa-solid fa-gift text-2xl text-emerald-400 mb-2 block"></i>
        <span class="text-[11px] font-bold uppercase tracking-wider text-emerald-300">Referral</span>
    </a>
    <a href="profile" class="group relative rounded-2xl p-4 bg-neutral-900 border-2 border-purple-400/70 shadow-[0_0_18px_rgba(168,85,247,0.22)] dark:bg-neutral-900 text-center hover:border-purple-400 hover:shadow-[0_0_28px_rgba(168,85,247,0.4)] transition-all duration-300 active:scale-95">
        <i class="fa-solid fa-user text-2xl text-purple-400 mb-2 block"></i>
        <span class="text-[11px] font-bold uppercase tracking-wider text-purple-300">Profile</span>
    </a>
</div>

<?php
require_once 'includes/footer.php';
?>
