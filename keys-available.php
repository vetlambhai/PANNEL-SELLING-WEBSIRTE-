<?php
require_once 'includes/auth.php';
require_once 'includes/functions.php';

requireAdmin();

try {
    $pdo = getDBConnection();
} catch (Exception $e) {
    $error = 'Database connection failed: ' . $e->getMessage();
    $pdo = null;
}

// Get statistics
try {
    $stats = getModStats();
} catch (Exception $e) {
    $stats = [
        'total_mods' => 0,
        'total_keys' => 0,
        'available_keys' => 0,
        'sold_keys' => 0
    ];
}

// Get available keys grouped by mod
try {
    $stmt = $pdo->query("SELECT lk.*, m.name as mod_name, 
                        COUNT(*) as total_keys,
                        SUM(CASE WHEN lk.status = 'available' THEN 1 ELSE 0 END) as available_keys,
                        SUM(CASE WHEN lk.status = 'sold' THEN 1 ELSE 0 END) as sold_keys,
                        MIN(lk.price) as min_price,
                        MAX(lk.price) as max_price
                        FROM license_keys lk 
                        LEFT JOIN mods m ON lk.mod_id = m.id 
                        GROUP BY lk.mod_id, m.name
                        ORDER BY m.name");
    $modStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $modStats = [];
}

// Get detailed available keys
try {
    $availableKeys = getAvailableKeys();
} catch (Exception $e) {
    $availableKeys = [];
}

$page_title = "Available Keys Summary - " . SITE_NAME . " " . SITE_SUBTITLE;
require_once 'includes/header.php';
?>

<!-- Header -->
<div class="mb-8 flex items-center justify-between">
    <div>
        <h2 class="text-2xl font-bold tracking-tight text-glow-primary">Available Keys Overview</h2>
        <p class="text-sm text-brand-muted mt-1 font-medium">Verify store inventories and generated key counts.</p>
    </div>
</div>

<!-- Stats Grid -->
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
    <div class="bg-white border border-gray-200 rounded-xl shadow-sm p-6 dark:bg-neutral-900 dark:border-neutral-700 flex items-center space-x-4">
        <div class="w-12 h-12 rounded-xl bg-brand-primary/10 border border-brand-primary/20 flex items-center justify-center text-brand-primary shadow-glow">
            <i class="fa-solid fa-gamepad text-xl"></i>
        </div>
        <div>
            <p class="text-xs font-semibold text-brand-muted uppercase tracking-wider">Total Mods</p>
            <h3 class="text-xl font-bold mt-1 text-brand-text"><?php echo $stats['total_mods'] ?: 0; ?></h3>
        </div>
    </div>

    <div class="bg-white border border-gray-200 rounded-xl shadow-sm p-6 dark:bg-neutral-900 dark:border-neutral-700 flex items-center space-x-4">
        <div class="w-12 h-12 rounded-xl bg-brand-secondary/10 border border-brand-secondary/20 flex items-center justify-center text-brand-secondary shadow-glow-cyan">
            <i class="fa-solid fa-key text-xl"></i>
        </div>
        <div>
            <p class="text-xs font-semibold text-brand-muted uppercase tracking-wider">Total Keys</p>
            <h3 class="text-xl font-bold mt-1 text-brand-text"><?php echo $stats['total_keys'] ?: 0; ?></h3>
        </div>
    </div>

    <div class="bg-white border border-gray-200 rounded-xl shadow-sm p-6 dark:bg-neutral-900 dark:border-neutral-700 flex items-center space-x-4">
        <div class="w-12 h-12 rounded-xl bg-brand-success/10 border border-brand-success/20 flex items-center justify-center text-brand-success">
            <i class="fa-solid fa-check text-xl"></i>
        </div>
        <div>
            <p class="text-xs font-semibold text-brand-muted uppercase tracking-wider">Available Keys</p>
            <h3 class="text-xl font-bold mt-1 text-brand-text"><?php echo $stats['available_keys'] ?: 0; ?></h3>
        </div>
    </div>

    <div class="bg-white border border-gray-200 rounded-xl shadow-sm p-6 dark:bg-neutral-900 dark:border-neutral-700 flex items-center space-x-4">
        <div class="w-12 h-12 rounded-xl bg-brand-warning/10 border border-brand-warning/20 flex items-center justify-center text-brand-warning">
            <i class="fa-solid fa-shopping-bag text-xl"></i>
        </div>
        <div>
            <p class="text-xs font-semibold text-brand-muted uppercase tracking-wider">Sold Keys</p>
            <h3 class="text-xl font-bold mt-1 text-brand-text"><?php echo $stats['sold_keys'] ?: 0; ?></h3>
        </div>
    </div>
</div>

<!-- Grouped Stats Table -->
<div class="bg-white border border-gray-200 rounded-xl shadow-sm p-6 dark:bg-neutral-900 dark:border-neutral-700 mb-8">
    <h3 class="text-base font-bold mb-6 flex items-center space-x-2">
        <i class="fa-solid fa-table-list text-brand-primary"></i>
        <span>Inventory status by Mod</span>
    </h3>
    
    <div class="overflow-x-auto">
        <table class="w-full text-left border-collapse">
            <thead>
                <tr class="border-b border-brand-border/60 text-brand-muted text-xs uppercase font-semibold">
                    <th class="pb-3">Mod Name</th>
                    <th class="pb-3 text-center">Total Keys</th>
                    <th class="pb-3 text-center">Available</th>
                    <th class="pb-3 text-center">Sold</th>
                    <th class="pb-3 text-right">Price Range</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-brand-border/40 text-sm">
                <?php if (empty($modStats)): ?>
                <tr>
                    <td colspan="5" class="text-center text-brand-muted py-12">
                        No keys generated yet.
                    </td>
                </tr>
                <?php else: ?>
                    <?php foreach ($modStats as $mod): ?>
                    <tr>
                        <td class="py-3.5 font-semibold text-brand-text"><?php echo htmlspecialchars($mod['mod_name'] ?: 'Unknown Mod'); ?></td>
                        <td class="py-3.5 text-center font-mono text-xs text-brand-text"><?php echo $mod['total_keys']; ?></td>
                        <td class="py-3.5 text-center">
                            <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-brand-success/10 text-brand-success">
                                <?php echo $mod['available_keys']; ?> available
                            </span>
                        </td>
                        <td class="py-3.5 text-center">
                            <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-brand-muted/20 text-brand-muted">
                                <?php echo $mod['sold_keys']; ?> sold
                            </span>
                        </td>
                        <td class="py-3.5 text-right font-bold text-xs text-brand-success">
                            ₹<?php echo number_format($mod['min_price'], 2); ?> - ₹<?php echo number_format($mod['max_price'], 2); ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
require_once 'includes/footer.php';
?>
