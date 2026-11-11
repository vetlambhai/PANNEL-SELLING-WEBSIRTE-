<?php
require_once 'includes/auth.php';
require_once 'includes/functions.php';
require_once 'includes/reseller_provider.php';

requireAdmin();

$success = '';
$error = '';

$pdo = null;
try {
    $pdo = getDBConnection();
    $stmt = $pdo->query("SELECT * FROM users WHERE role = 'user' ORDER BY username");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $error = 'Failed to fetch users: ' . $e->getMessage();
    $users = [];
}

// Pre-select user if provided in URL
$selectedUserId = $_GET['user_id'] ?? '';

// Get user statistics
try {
    if ($pdo) {
        $stmt = $pdo->query("SELECT 
            COUNT(*) as total_users,
            SUM(balance) as total_balance,
            COUNT(CASE WHEN balance > 0 THEN 1 END) as users_with_balance,
            AVG(balance) as avg_balance
            FROM users WHERE role = 'user'");
        $userStats = $stmt->fetch(PDO::FETCH_ASSOC);
    } else {
        $userStats = [
            'total_users' => 0,
            'total_balance' => 0,
            'users_with_balance' => 0,
            'avg_balance' => 0
        ];
    }
} catch (Exception $e) {
    $userStats = [
        'total_users' => 0,
        'total_balance' => 0,
        'users_with_balance' => 0,
        'avg_balance' => 0
    ];
}

if ($_POST) {
    verify_csrf();

    try {
        $userId = $_POST['user_id'] ?? '';
        $amount = (float)($_POST['amount'] ?? 0);
        $reference = trim($_POST['reference'] ?? '');
        
        if (empty($userId) || $amount <= 0) {
            $error = 'Please select a user and enter a valid amount';
        } else {
            if (updateBalance($userId, $amount, 'balance_add', $reference)) {
                $success = 'Balance added successfully!';
                $selectedUserId = $userId; // Keep selected user
                
                // ============================================================
                // AUTO-RESELLER: Admin ne balance add kiya (deposit).
                // Total spend check karke user ko automatically reseller
                // promote karo agar 500rs threshold pahunch gaya.
                // ============================================================
                try {
                    checkAndPromoteReseller($userId);
                } catch (Exception $e) {}
                
                // Refresh user stats
                if ($pdo) {
                    $stmt = $pdo->query("SELECT 
                        COUNT(*) as total_users,
                        SUM(balance) as total_balance,
                        COUNT(CASE WHEN balance > 0 THEN 1 END) as users_with_balance,
                        AVG(balance) as avg_balance
                        FROM users WHERE role = 'user'");
                    $userStats = $stmt->fetch(PDO::FETCH_ASSOC);
                }
            } else {
                $error = 'Failed to add balance. Please try again.';
            }
        }
    } catch (Exception $e) {
        $error = 'An error occurred: ' . $e->getMessage();
    }
}

$page_title = "Add User Balance - " . SITE_NAME . " " . SITE_SUBTITLE;
require_once 'includes/header.php';
?>

<!-- Header -->
<div class="mb-8 flex items-center justify-between">
    <div>
        <h2 class="text-2xl font-bold tracking-tight text-glow-primary">Add User Balance</h2>
        <p class="text-sm text-brand-muted mt-1 font-medium">Add credits to any registered user's wallet.</p>
    </div>
    <a href="users" class="inline-flex items-center justify-center px-3.5 py-2 bg-brand-surface border border-brand-border text-xs font-semibold rounded-xl hover:text-brand-primary hover:border-brand-primary/40 transition-colors space-x-1.5">
        <i class="fa-solid fa-arrow-left-long"></i>
        <span>Back to Users</span>
    </a>
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

<!-- Form Card -->
<div class="max-w-xl bg-white border border-gray-200 rounded-xl shadow-sm p-6 dark:bg-neutral-900 dark:border-neutral-700">
    <h3 class="text-base font-bold mb-4 flex items-center space-x-2">
        <i class="fa-solid fa-wallet text-brand-primary"></i>
        <span>Transaction details</span>
    </h3>
    
    <form method="POST" class="space-y-4">
    <?php echo csrf_field(); ?>
        <div>
            <label for="user_id" class="block text-xs font-semibold text-brand-muted mb-1.5">Select User *</label>
            <select id="user_id" name="user_id" required class="w-full px-4 py-2.5 bg-brand-bg/50 border border-brand-border rounded-xl text-sm focus:outline-none focus:border-brand-primary">
                <option value="">Choose a user...</option>
                <?php foreach ($users as $u): ?>
                    <option value="<?php echo $u['id']; ?>" <?php echo $selectedUserId == $u['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($u['username']); ?> (Current Balance: ₹<?php echo number_format($u['balance'], 2); ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label for="amount" class="block text-xs font-semibold text-brand-muted mb-1.5">Amount (₹) *</label>
                <input type="number" step="0.01" id="amount" name="amount" min="0.01" required
                       class="w-full px-4 py-2.5 bg-brand-bg/50 border border-brand-border rounded-xl text-sm focus:outline-none focus:border-brand-primary"
                       placeholder="e.g. 500.00">
            </div>

            <div>
                <label for="reference" class="block text-xs font-semibold text-brand-muted mb-1.5">Reference / Remark</label>
                <input type="text" id="reference" name="reference"
                       class="w-full px-4 py-2.5 bg-brand-bg/50 border border-brand-border rounded-xl text-sm focus:outline-none focus:border-brand-primary placeholder-brand-muted/40"
                       placeholder="e.g. PhonePe receipt #123">
            </div>
        </div>

        <button type="submit" class="w-full py-3 bg-gradient-to-r from-brand-primary to-brand-secondary hover:opacity-90 active:scale-[0.98] transition-all duration-300 rounded-xl text-sm font-bold text-white shadow-glow flex items-center justify-center space-x-2">
            <i class="fa-solid fa-circle-dollar-to-slot"></i>
            <span>Add Balance</span>
        </button>
    </form>
</div>

<?php
require_once 'includes/footer.php';
?>
