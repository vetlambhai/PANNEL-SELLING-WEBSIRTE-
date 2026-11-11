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

// Get filter parameters
$filters = [
    'user_id' => $_GET['user_id'] ?? '',
    'type' => $_GET['type'] ?? '',
    'status' => $_GET['status'] ?? '',
    'search' => $_GET['search'] ?? ''
];

// Get all users for filter dropdown
try {
    if ($pdo) {
        $stmt = $pdo->query("SELECT id, username FROM users WHERE role = 'user' ORDER BY username");
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $users = [];
    }
} catch (Exception $e) {
    $users = [];
    $error = 'Failed to fetch users: ' . $e->getMessage();
}

// Get transactions
try {
    if ($pdo) {
        $transactions = getAllTransactions($filters);
    } else {
        $transactions = [];
    }
} catch (Exception $e) {
    $transactions = [];
    $error = 'Failed to fetch transactions: ' . $e->getMessage();
}

// Get transaction statistics
try {
    if ($pdo) {
        $stmt = $pdo->query("SELECT 
            COUNT(*) as total_transactions,
            SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END) as total_income,
            SUM(CASE WHEN amount < 0 THEN ABS(amount) ELSE 0 END) as total_expenses,
            COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed_transactions,
            COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_transactions,
            COUNT(CASE WHEN status = 'failed' THEN 1 END) as failed_transactions
            FROM transactions");
        $transactionStats = $stmt->fetch(PDO::FETCH_ASSOC);
    } else {
        $transactionStats = [
            'total_transactions' => 0,
            'total_income' => 0,
            'total_expenses' => 0,
            'completed_transactions' => 0,
            'pending_transactions' => 0,
            'failed_transactions' => 0
        ];
    }
} catch (Exception $e) {
    $transactionStats = [
        'total_transactions' => 0,
        'total_income' => 0,
        'total_expenses' => 0,
        'completed_transactions' => 0,
        'pending_transactions' => 0,
        'failed_transactions' => 0
    ];
}

$page_title = "Transactions - " . SITE_NAME . " " . SITE_SUBTITLE;
require_once 'includes/header.php';
?>

<!-- Header -->
<div class="mb-8 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
    <div>
        <h2 class="text-2xl font-bold tracking-tight text-glow-primary">Transactions Ledger</h2>
        <p class="text-sm text-brand-muted mt-1 font-medium">Review and audit all billing statements, top-ups, and purchases.</p>
    </div>
</div>

<!-- Alerts -->
<?php if (isset($error) && $error): ?>
    <div class="bg-brand-error/10 border border-brand-error/20 text-brand-error text-xs rounded-xl p-3.5 mb-6 flex items-center space-x-2.5">
        <i class="fa-solid fa-circle-exclamation text-base"></i>
        <span><?php echo htmlspecialchars($error); ?></span>
    </div>
<?php endif; ?>

<!-- Stats Grid -->
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
    <div class="bg-white border border-gray-200 rounded-xl shadow-sm p-6 dark:bg-neutral-900 dark:border-neutral-700 flex items-center space-x-4">
        <div class="w-12 h-12 rounded-xl bg-brand-primary/10 border border-brand-primary/20 flex items-center justify-center text-brand-primary shadow-glow">
            <i class="fa-solid fa-calculator text-xl"></i>
        </div>
        <div>
            <p class="text-xs font-semibold text-brand-muted uppercase tracking-wider">Total Transactions</p>
            <h3 class="text-xl font-bold mt-1 text-brand-text"><?php echo $transactionStats['total_transactions'] ?: 0; ?></h3>
        </div>
    </div>

    <div class="bg-white border border-gray-200 rounded-xl shadow-sm p-6 dark:bg-neutral-900 dark:border-neutral-700 flex items-center space-x-4">
        <div class="w-12 h-12 rounded-xl bg-brand-success/10 border border-brand-success/20 flex items-center justify-center text-brand-success">
            <i class="fa-solid fa-plus text-xl"></i>
        </div>
        <div>
            <p class="text-xs font-semibold text-brand-muted uppercase tracking-wider">Deposits / Income</p>
            <h3 class="text-xl font-bold mt-1 text-brand-text">₹<?php echo number_format((float)$transactionStats['total_income'], 2); ?></h3>
        </div>
    </div>

    <div class="bg-white border border-gray-200 rounded-xl shadow-sm p-6 dark:bg-neutral-900 dark:border-neutral-700 flex items-center space-x-4">
        <div class="w-12 h-12 rounded-xl bg-brand-warning/10 border border-brand-warning/20 flex items-center justify-center text-brand-warning">
            <i class="fa-solid fa-minus text-xl"></i>
        </div>
        <div>
            <p class="text-xs font-semibold text-brand-muted uppercase tracking-wider">Purchases / Expenses</p>
            <h3 class="text-xl font-bold mt-1 text-brand-text">₹<?php echo number_format((float)$transactionStats['total_expenses'], 2); ?></h3>
        </div>
    </div>

    <div class="bg-white border border-gray-200 rounded-xl shadow-sm p-6 dark:bg-neutral-900 dark:border-neutral-700 flex items-center space-x-4">
        <div class="w-12 h-12 rounded-xl bg-brand-success/15 border border-brand-success/20 flex items-center justify-center text-brand-success">
            <i class="fa-solid fa-circle-check text-xl"></i>
        </div>
        <div>
            <p class="text-xs font-semibold text-brand-muted uppercase tracking-wider">Completed Status</p>
            <h3 class="text-xl font-bold mt-1 text-brand-text"><?php echo $transactionStats['completed_transactions'] ?: 0; ?></h3>
        </div>
    </div>
</div>

<!-- Search & Filters -->
<div class="bg-white border border-gray-200 rounded-xl shadow-sm p-6 dark:bg-neutral-900 dark:border-neutral-700 mb-6">
    <form method="GET" class="grid grid-cols-1 sm:grid-cols-4 gap-4">
    <?php echo csrf_field(); ?>
        <div>
            <label for="search" class="block text-[10px] font-bold uppercase text-brand-muted mb-1.5">Search</label>
            <input type="text" id="search" name="search" value="<?php echo htmlspecialchars($filters['search']); ?>"
                   class="w-full px-4 py-2 bg-brand-bg/50 border border-brand-border rounded-xl text-xs focus:outline-none focus:border-brand-primary text-brand-text"
                   placeholder="Receipt, reference...">
        </div>
        
        <div>
            <label for="user_id" class="block text-[10px] font-bold uppercase text-brand-muted mb-1.5">User</label>
            <select id="user_id" name="user_id" class="w-full px-4 py-2 bg-brand-bg/50 border border-brand-border rounded-xl text-xs focus:outline-none focus:border-brand-primary text-brand-text">
                <option value="">All Users</option>
                <?php foreach ($users as $u): ?>
                    <option value="<?php echo $u['id']; ?>" <?php echo $filters['user_id'] == $u['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($u['username']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label for="type" class="block text-[10px] font-bold uppercase text-brand-muted mb-1.5">Type</label>
            <select id="type" name="type" class="w-full px-4 py-2 bg-brand-bg/50 border border-brand-border rounded-xl text-xs focus:outline-none focus:border-brand-primary text-brand-text">
                <option value="">All Types</option>
                <option value="purchase" <?php echo $filters['type'] == 'purchase' ? 'selected' : ''; ?>>Purchase</option>
                <option value="balance_add" <?php echo $filters['type'] == 'balance_add' ? 'selected' : ''; ?>>Balance Add</option>
            </select>
        </div>

        <div>
            <label for="status" class="block text-[10px] font-bold uppercase text-brand-muted mb-1.5">Status</label>
            <div class="flex items-center space-x-2">
                <select id="status" name="status" class="w-full px-4 py-2 bg-brand-bg/50 border border-brand-border rounded-xl text-xs focus:outline-none focus:border-brand-primary text-brand-text">
                    <option value="">All Statuses</option>
                    <option value="completed" <?php echo $filters['status'] == 'completed' ? 'selected' : ''; ?>>Completed</option>
                    <option value="pending" <?php echo $filters['status'] == 'pending' ? 'selected' : ''; ?>>Pending</option>
                    <option value="failed" <?php echo $filters['status'] == 'failed' ? 'selected' : ''; ?>>Failed</option>
                </select>
                <button type="submit" class="px-4 py-2 bg-brand-primary text-white text-xs font-bold rounded-xl hover:opacity-90 active:scale-[0.98] transition-all">
                    Apply
                </button>
            </div>
        </div>
    </form>
</div>

<!-- Table Card -->
<div class="bg-white border border-gray-200 rounded-xl shadow-sm p-6 dark:bg-neutral-900 dark:border-neutral-700">
    <div class="overflow-x-auto">
        <table class="w-full text-left border-collapse">
            <thead>
                <tr class="border-b border-brand-border/60 text-brand-muted text-xs uppercase font-semibold">
                    <th class="pb-3">Transaction ID</th>
                    <th class="pb-3">User</th>
                    <th class="pb-3">Type</th>
                    <th class="pb-3">Reference</th>
                    <th class="pb-3">Status</th>
                    <th class="pb-3">Date</th>
                    <th class="pb-3 text-right">Amount</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-brand-border/40 text-sm">
                <?php if (empty($transactions)): ?>
                <tr>
                    <td colspan="7" class="text-center text-brand-muted py-12">
                        <i class="fa-solid fa-receipt text-3xl mb-2 opacity-50 block"></i>
                        No transactions matching filters.
                    </td>
                </tr>
                <?php else: ?>
                    <?php foreach ($transactions as $tx): ?>
                    <tr>
                        <td class="py-3.5 font-mono text-xs text-brand-muted">#<?php echo $tx['id']; ?></td>
                        <td class="py-3.5 font-semibold text-brand-text"><?php echo htmlspecialchars($tx['username']); ?></td>
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
                        <td class="py-3.5 text-xs text-brand-muted"><?php echo date('d M Y H:i', strtotime($tx['created_at'])); ?></td>
                        <td class="py-3.5 text-right font-bold text-xs <?php echo $tx['amount'] < 0 ? 'text-brand-error' : 'text-brand-success'; ?>">
                            <?php echo $tx['amount'] < 0 ? '-' . formatCurrency(abs($tx['amount'])) : '+' . formatCurrency($tx['amount']); ?>
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
