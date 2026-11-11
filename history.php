<?php
require_once 'config/database.php';

// Check if user is logged in
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$pdo = getDBConnection();
$userId = $_SESSION['user_id'];

// Get user data
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// Get user transactions
$stmt = $pdo->prepare("SELECT * FROM transactions WHERE user_id = ? ORDER BY created_at DESC LIMIT 100");
$stmt->execute([$userId]);
$transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Helper functions
function formatCurrency($amount) {
    return '₹' . number_format($amount, 2);
}

function formatDate($date) {
    return date('d M Y H:i', strtotime($date));
}

$page_title = "Transactions - " . SITE_NAME . " " . SITE_SUBTITLE;
require_once 'includes/header.php';
?>

<!-- Header -->
<div class="mb-8">
    <h2 class="text-2xl font-bold tracking-tight text-glow-primary">Transactions Ledger</h2>
    <p class="text-sm text-brand-muted mt-1">Review all your purchase records and wallet top-ups.</p>
</div>

<!-- Main Table Card -->
<div class="bg-white border border-gray-200 rounded-xl shadow-sm p-6 dark:bg-neutral-900 dark:border-neutral-700">
    <div class="flex items-center space-x-3 mb-6">
        <div class="w-8 h-8 rounded-lg bg-brand-primary/10 flex items-center justify-center text-brand-primary">
            <i class="fa-solid fa-file-invoice-dollar text-sm"></i>
        </div>
        <h4 class="text-base font-bold">All Transactions</h4>
    </div>

    <div class="overflow-x-auto">
        <table class="w-full text-left border-collapse">
            <thead>
                <tr class="border-b border-brand-border/60 text-brand-muted text-xs uppercase font-semibold">
                    <th class="pb-3">Transaction ID</th>
                    <th class="pb-3">Type</th>
                    <th class="pb-3">Reference / Description</th>
                    <th class="pb-3">Status</th>
                    <th class="pb-3">Date</th>
                    <th class="pb-3 text-right">Amount</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-brand-border/40 text-sm">
                <?php if (empty($transactions)): ?>
                <tr>
                    <td colspan="6" class="text-center text-brand-muted py-12">
                        <i class="fa-solid fa-receipt text-3xl mb-2 opacity-50 block"></i>
                        No transactions registered yet.
                    </td>
                </tr>
                <?php else: ?>
                    <?php foreach ($transactions as $tx): ?>
                    <tr>
                        <td class="py-3.5 font-mono text-xs text-brand-muted">#<?php echo $tx['id']; ?></td>
                        <td class="py-3.5">
                            <span class="px-2 py-0.5 rounded text-[9px] font-bold uppercase <?php echo $tx['type'] === 'purchase' ? 'bg-brand-primary/10 text-brand-primary' : 'bg-brand-success/10 text-brand-success'; ?>">
                                <?php echo htmlspecialchars($tx['type']); ?>
                            </span>
                        </td>
                        <td class="py-3.5 text-xs text-brand-text font-medium"><?php echo htmlspecialchars($tx['reference'] ?: 'Top-up Balance'); ?></td>
                        <td class="py-3.5">
                            <span class="px-2 py-0.5 rounded text-[9px] font-bold uppercase <?php echo $tx['status'] === 'completed' ? 'bg-brand-success/10 text-brand-success' : ($tx['status'] === 'failed' ? 'bg-brand-error/10 text-brand-error' : 'bg-brand-warning/10 text-brand-warning'); ?>">
                                <?php echo htmlspecialchars($tx['status'] ?: 'completed'); ?>
                            </span>
                        </td>
                        <td class="py-3.5 text-xs text-brand-muted"><?php echo formatDate($tx['created_at']); ?></td>
                        <td class="py-3.5 text-right font-bold text-xs <?php echo $tx['amount'] < 0 ? 'text-brand-error' : 'text-brand-success'; ?>">
                            <?php echo $tx['amount'] < 0 ? '-' . formatCurrency(abs($tx['amount'])) : '+' . formatCurrency($tx['amount']); ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <?php endif; ?>
            </table>
        </div>
    </div>
</div>

<?php
require_once 'includes/footer.php';
?>
