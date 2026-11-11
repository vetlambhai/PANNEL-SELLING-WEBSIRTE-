<?php
require_once 'includes/auth.php';
require_once 'includes/functions.php';
require_once 'includes/reseller_provider.php';

requireAdmin();

$pdo = getDBConnection();

// Get stats directly
$stats = [
    'total_mods' => 0,
    'total_keys' => 0,
    'available_keys' => 0,
    'sold_keys' => 0,
    'total_users' => 0
];

try {
    // Get mod count
    $stmt = $pdo->query("SELECT COUNT(*) FROM mods");
    $stats['total_mods'] = $stmt->fetchColumn();
    
    // Get key counts
    $stmt = $pdo->query("SELECT COUNT(*) FROM license_keys");
    $stats['total_keys'] = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM license_keys WHERE status = 'available'");
    $stats['available_keys'] = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM license_keys WHERE status = 'sold'");
    $stats['sold_keys'] = $stmt->fetchColumn();
    
    // Get user count
    $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'user'");
    $stats['total_users'] = $stmt->fetchColumn();
    
} catch (Exception $e) {
    error_log("Admin stats error: " . $e->getMessage());
}

// Get recent data
$recentMods = [];
$recentUsers = [];

try {
    $stmt = $pdo->query("SELECT * FROM mods ORDER BY created_at DESC LIMIT 5");
    $recentMods = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stmt = $pdo->query("SELECT * FROM users WHERE role = 'user' ORDER BY created_at DESC LIMIT 5");
    $recentUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Admin recent data fetch error: " . $e->getMessage());
}

// Build last 7 days template for real analytics data
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

// Fetch top 5 resellers for admin dashboard display
// (admin-managed if set, otherwise automatic by keys sold + revenue)
$topResellers = getDisplayTopResellers(5);

// Fetch sales counts (sold keys) for all users
try {
    $stmt = $pdo->prepare("SELECT DATE(sold_at) as date_grp, COUNT(*) as count 
                           FROM license_keys 
                           WHERE status = 'sold' AND sold_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) 
                           GROUP BY DATE(sold_at)");
    $stmt->execute();
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $dVal = $row['date_grp'];
        if (isset($chartData[$dVal])) {
            $chartData[$dVal]['sales_count'] = (int)$row['count'];
        }
    }
} catch (Exception $e) {
    // Fail silently
}

// Fetch revenue (purchases) from transactions for all users
try {
    $stmt = $pdo->prepare("SELECT DATE(created_at) as date_grp, SUM(ABS(amount)) as total 
                           FROM transactions 
                           WHERE type = 'purchase' AND status = 'completed' AND created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) 
                           GROUP BY DATE(created_at)");
    $stmt->execute();
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $dVal = $row['date_grp'];
        if (isset($chartData[$dVal])) {
            $chartData[$dVal]['revenue'] = (float)$row['total'];
        }
    }
} catch (Exception $e) {
    // Fail silently
}

// Format arrays for Javascript Chart
$labelsArr = [];
$salesCountArr = [];
$revenueArr = [];
foreach ($chartData as $dateKey => $data) {
    $labelsArr[] = $data['label'];
    $salesCountArr[] = $data['sales_count'];
    $revenueArr[] = $data['revenue'];
}

$page_title = "Admin Dashboard - " . SITE_NAME . " " . SITE_SUBTITLE;
require_once 'includes/header.php';
?>

<!-- Dashboard Header -->
<div class="mb-8">
    <h2 class="text-2xl font-bold tracking-tight text-glow-primary">Dashboard Overview</h2>
    <p class="text-sm text-brand-muted mt-1">Welcome back! Here's your system overview.</p>
</div>

<!-- Stats Grid -->
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6 mb-8">
    <!-- Stat Card 1 -->
    <div class="bg-white border border-gray-200 rounded-xl shadow-sm p-6 dark:bg-neutral-900 dark:border-neutral-700 flex items-center space-x-4">
        <div class="w-12 h-12 rounded-xl bg-brand-primary/10 border border-brand-primary/20 flex items-center justify-center text-brand-primary shadow-glow">
            <i class="fa-solid fa-box text-xl"></i>
        </div>
        <div>
            <p class="text-xs font-semibold text-brand-muted uppercase tracking-wider">Total Mods</p>
            <h3 class="text-2xl font-bold mt-1 text-brand-text"><?php echo $stats['total_mods']; ?></h3>
        </div>
    </div>

    <!-- Stat Card 2 -->
    <div class="bg-white border border-gray-200 rounded-xl shadow-sm p-6 dark:bg-neutral-900 dark:border-neutral-700 flex items-center space-x-4">
        <div class="w-12 h-12 rounded-xl bg-brand-secondary/10 border border-brand-secondary/20 flex items-center justify-center text-brand-secondary shadow-glow-cyan">
            <i class="fa-solid fa-key text-xl"></i>
        </div>
        <div>
            <p class="text-xs font-semibold text-brand-muted uppercase tracking-wider">License Keys</p>
            <h3 class="text-2xl font-bold mt-1 text-brand-text"><?php echo $stats['total_keys']; ?></h3>
        </div>
    </div>



    <!-- Stat Card 4 -->
    <div class="bg-white border border-gray-200 rounded-xl shadow-sm p-6 dark:bg-neutral-900 dark:border-neutral-700 flex items-center space-x-4">
        <div class="w-12 h-12 rounded-xl bg-brand-warning/10 border border-brand-warning/20 flex items-center justify-center text-brand-warning">
            <i class="fa-solid fa-cart-shopping text-xl"></i>
        </div>
        <div>
            <p class="text-xs font-semibold text-brand-muted uppercase tracking-wider">Sold Licenses</p>
            <h3 class="text-2xl font-bold mt-1 text-brand-text"><?php echo $stats['sold_keys']; ?></h3>
        </div>
    </div>
</div>

<!-- Analytics Chart Card -->
<div class="bg-white border border-gray-200 rounded-xl shadow-sm p-6 dark:bg-neutral-900 dark:border-neutral-700 mb-8">
        <div class="flex items-center justify-between mb-6 flex-wrap gap-4">
            <div class="flex items-center space-x-3">
                <div class="w-8 h-8 rounded-lg bg-brand-primary/10 flex items-center justify-center text-brand-primary shadow-glow">
                    <i class="fa-solid fa-chart-line text-sm"></i>
                </div>
                <div>
                    <h4 class="text-base font-bold">System Sales & Revenue Analytics</h4>
                    <p class="text-[11px] text-brand-muted font-medium mt-0.5">Key sales & revenue over the last 7 days</p>
                </div>
            </div>
            <div class="flex items-center space-x-4 text-xs font-semibold">
                <span class="flex items-center space-x-1.5 text-brand-primary"><span class="w-2.5 h-2.5 rounded-full bg-brand-primary"></span><span>Keys Sold</span></span>
                <span class="flex items-center space-x-1.5 text-brand-secondary"><span class="w-2.5 h-2.5 rounded-full bg-brand-secondary"></span><span>Revenue (₹)</span></span>
            </div>
        </div>
        <div class="h-[240px] w-full relative">
            <canvas id="analyticsChart"></canvas>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const ctx = document.getElementById('analyticsChart').getContext('2d');
        
        const labels = <?php echo json_encode($labelsArr); ?>;
        const validationsData = <?php echo json_encode($salesCountArr); ?>;
        const salesData = <?php echo json_encode($revenueArr); ?>;

    const isDark = document.documentElement.classList.contains('dark');
    const textColor = isDark ? '#9ca3af' : '#475569';
    const gridColor = isDark ? 'rgba(31, 41, 55, 0.4)' : 'rgba(226, 232, 240, 0.8)';
    
    const primaryGradient = ctx.createLinearGradient(0, 0, 0, 240);
    primaryGradient.addColorStop(0, 'rgba(99, 102, 241, 0.2)');
    primaryGradient.addColorStop(1, 'rgba(99, 102, 241, 0)');

    const secondaryGradient = ctx.createLinearGradient(0, 0, 0, 240);
    secondaryGradient.addColorStop(0, 'rgba(6, 182, 212, 0.2)');
    secondaryGradient.addColorStop(1, 'rgba(6, 182, 212, 0)');

    const myChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'Validations',
                    data: validationsData,
                    borderColor: '#6366f1',
                    borderWidth: 3,
                    backgroundColor: primaryGradient,
                    fill: true,
                    tension: 0.4,
                    pointBackgroundColor: '#6366f1',
                    pointHoverRadius: 7
                },
                {
                    label: 'Key Sales',
                    data: salesData,
                    borderColor: '#06b6d4',
                    borderWidth: 3,
                    backgroundColor: secondaryGradient,
                    fill: true,
                    tension: 0.4,
                    pointBackgroundColor: '#06b6d4',
                    pointHoverRadius: 7
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: false
                }
            },
            scales: {
                x: {
                    grid: {
                        display: false
                    },
                    ticks: {
                        color: textColor,
                        font: {
                            family: 'Inter',
                            size: 11
                        }
                    }
                },
                y: {
                    grid: {
                        color: gridColor
                    },
                    ticks: {
                        color: textColor,
                        font: {
                            family: 'Inter',
                            size: 11
                        }
                    }
                }
            }
        }
    });

    window.addEventListener('click', function(e) {
        setTimeout(() => {
            const dark = document.documentElement.classList.contains('dark');
            myChart.options.scales.x.ticks.color = dark ? '#9ca3af' : '#475569';
            myChart.options.scales.y.ticks.color = dark ? '#9ca3af' : '#475569';
            myChart.options.scales.y.grid.color = dark ? 'rgba(31, 41, 55, 0.4)' : 'rgba(226, 232, 240, 0.8)';
            myChart.update();
        }, 50);
    });
});
</script>

<!-- 5 TOP RESELLERS Card -->
<div class="bg-white border border-gray-200 rounded-xl shadow-sm p-6 dark:bg-neutral-900 dark:border-neutral-700 mb-8">
    <div class="flex items-center justify-between mb-5 flex-wrap gap-2">
        <div class="flex items-center space-x-3">
            <div class="w-8 h-8 rounded-lg bg-yellow-500/15 border border-yellow-500/30 flex items-center justify-center text-yellow-400">
                <i class="fa-solid fa-crown text-sm"></i>
            </div>
            <div>
                <h4 class="text-base font-bold text-brand-text">5 TOP RESELLERS</h4>
                <p class="text-[11px] text-brand-muted mt-0.5">Top performing resellers by keys &amp; revenue</p>
            </div>
        </div>
        <a href="settings.php#top-resellers" class="text-xs font-bold text-brand-secondary hover:text-brand-secondary/80 hover:underline flex items-center gap-1 transition-colors">
            <span>Manage</span>
            <i class="fa-solid fa-arrow-right text-[10px]"></i>
        </a>
    </div>

    <div class="overflow-x-auto">
        <table class="w-full text-left border-collapse">
            <thead>
                <tr class="border-b border-brand-border/60 text-brand-muted text-[10px] uppercase font-bold tracking-wider">
                    <th class="pb-3 w-12">Rank</th>
                    <th class="pb-3">Username</th>
                    <th class="pb-3 text-center">Role</th>
                    <th class="pb-3 text-center">Keys</th>
                    <th class="pb-3 text-right">Buy</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-brand-border/40 text-sm">
                <?php if (empty($topResellers)): ?>
                <tr>
                    <td colspan="5" class="text-center text-brand-muted py-8">
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
                    <tr class="hover:bg-brand-surface/50 transition-colors">
                        <td class="py-3 font-black <?php echo $rStyle; ?>">#<?php echo $r['rank']; ?></td>
                        <td class="py-3">
                            <span class="font-bold text-brand-text">@<?php echo htmlspecialchars($r['username']); ?></span>
                        </td>
                        <td class="py-3 text-center">
                            <span class="px-2 py-0.5 rounded-full bg-yellow-500/15 text-yellow-500 border border-yellow-500/30 text-[10px] font-bold uppercase tracking-wider dark:text-yellow-400">RESELLER</span>
                        </td>
                        <td class="py-3 text-center font-bold text-brand-secondary"><?php echo number_format($r['keys']); ?> Keys</td>
                        <td class="py-3 text-right font-bold text-emerald-500 text-xs">&#8377;<?php echo number_format($r['buy'], 2); ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Lists Row -->
<div class="grid grid-cols-1 lg:grid-cols-1 gap-8">
    <!-- Recent Mods Card -->
    <div class="bg-white border border-gray-200 rounded-xl shadow-sm p-6 dark:bg-neutral-900 dark:border-neutral-700">
        <div class="flex items-center space-x-3 mb-6">
            <div class="w-8 h-8 rounded-lg bg-brand-primary/10 flex items-center justify-center text-brand-primary">
                <i class="fa-solid fa-gamepad text-sm"></i>
            </div>
            <h4 class="text-base font-bold">Recent Mods</h4>
        </div>
        
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse">
                <thead>
                    <tr class="border-b border-brand-border/60 text-brand-muted text-xs uppercase font-semibold">
                        <th class="pb-3">Mod Name</th>
                        <th class="pb-3 text-right">Upload Date</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-brand-border/40 text-sm">
                    <?php if (empty($recentMods)): ?>
                    <tr>
                        <td colspan="2" class="text-center text-brand-muted py-8">
                            <i class="fa-solid fa-box-open text-3xl mb-2 opacity-50 block"></i>
                            No mods uploaded yet
                        </td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($recentMods as $mod): ?>
                        <tr>
                            <td class="py-3.5 flex items-center space-x-3">
                                <div class="w-8 h-8 rounded-full bg-brand-surface border border-brand-border flex items-center justify-center text-brand-primary">
                                    <i class="fa-solid fa-mobile-screen"></i>
                                </div>
                                <span class="font-semibold text-brand-text"><?php echo htmlspecialchars($mod['name']); ?></span>
                            </td>
                            <td class="py-3.5 text-right text-brand-muted text-xs"><?php echo formatDate($mod['created_at']); ?></td>
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
