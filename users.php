<?php
require_once 'includes/auth.php';
require_once 'includes/functions.php';
require_once 'includes/reseller_provider.php';

requireAdmin();

$success = '';
$error = '';

try {
    $pdo = getDBConnection();
} catch (Exception $e) {
    $error = 'Database connection failed: ' . $e->getMessage();
    $pdo = null;
}

// Ensure reseller columns exist
if ($pdo) {
    try { ensureResellerProviderColumns(); } catch (Exception $e) {}
}

// Handle edit user (balance / password)
if (isset($_POST['edit_user_id']) && is_numeric($_POST['edit_user_id']) && $pdo) {
    verify_csrf();
    try {
        $editId = (int)$_POST['edit_user_id'];
        $newBalance = isset($_POST['balance']) ? (float)$_POST['balance'] : 0;
        $newPassword = isset($_POST['new_password']) ? trim($_POST['new_password']) : '';

        if ($newPassword !== '') {
            if (strlen($newPassword) < 6) {
                $error = 'Password must be at least 6 characters.';
            } else {
                $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET balance = ?, password = ? WHERE id = ? AND role = 'user'");
                $stmt->execute([$newBalance, $hashedPassword, $editId]);
                $success = 'User balance and password updated successfully!';
            }
        } else {
            $stmt = $pdo->prepare("UPDATE users SET balance = ? WHERE id = ? AND role = 'user'");
            $stmt->execute([$newBalance, $editId]);
            $success = 'User balance updated successfully!';
        }
    } catch (Exception $e) {
        $error = 'Error updating user: ' . $e->getMessage();
    }
}

// Handle delete user
if (isset($_POST['delete']) && is_numeric($_POST['delete']) && $pdo) {
    verify_csrf();
    try {
        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ? AND role = 'user'");
        if ($stmt->execute([$_POST['delete']])) {
            $success = 'User deleted successfully!';
        } else {
            $error = 'Failed to delete user.';
        }
    } catch (Exception $e) {
        $error = 'Error deleting user: ' . $e->getMessage();
    }
}

// ============================================================
// RESELLER MANAGEMENT: Admin manually kisi user ko reseller
// bana sakta hai ya remove kar sakta hai.
// ============================================================
if (isset($_POST['toggle_reseller']) && is_numeric($_POST['toggle_reseller']) && $pdo) {
    verify_csrf();
    try {
        $targetId = (int)$_POST['toggle_reseller'];
        // Check current status and toggle it
        $currentlyReseller = isUserReseller($targetId);
        $makeReseller = !$currentlyReseller;
        setResellerStatus($targetId, $makeReseller);
        if ($makeReseller) {
            $success = 'User promoted to Reseller successfully! Their API key is ready.';
        } else {
            $success = 'Reseller status removed from user.';
        }
    } catch (Exception $e) {
        $error = 'Error updating reseller status: ' . $e->getMessage();
    }
}

// Regenerate reseller API key
if (isset($_POST['regen_reseller_key']) && is_numeric($_POST['regen_reseller_key']) && $pdo) {
    verify_csrf();
    try {
        $targetId = (int)$_POST['regen_reseller_key'];
        if (isUserReseller($targetId)) {
            generateResellerApiKey($targetId);
            $success = 'Reseller API key regenerated successfully!';
        } else {
            $error = 'User is not a reseller.';
        }
    } catch (Exception $e) {
        $error = 'Error regenerating API key: ' . $e->getMessage();
    }
}

// Get all users
try {
    if ($pdo) {
        $stmt = $pdo->query("SELECT * FROM users WHERE role = 'user' ORDER BY created_at DESC");
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $users = [];
    }
} catch (Exception $e) {
    $users = [];
    $error = 'Failed to fetch users: ' . $e->getMessage();
}

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

$page_title = "Manage Users - " . SITE_NAME . " " . SITE_SUBTITLE;
require_once 'includes/header.php';
?>

<!-- Header -->
<div class="mb-8 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
    <div>
        <h2 class="text-2xl font-bold tracking-tight text-glow-primary">Manage Users</h2>
        <p class="text-sm text-brand-muted mt-1 font-medium">Add, remove, and manage user accounts.</p>
    </div>
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

<!-- Stats Grid -->
<div class="grid grid-cols-1 mb-8">
    <div class="bg-white border border-gray-200 rounded-xl shadow-sm p-6 dark:bg-neutral-900 dark:border-neutral-700 flex items-center space-x-4">
        <div class="w-12 h-12 rounded-xl bg-brand-primary/10 border border-brand-primary/20 flex items-center justify-center text-brand-primary shadow-glow">
            <i class="fa-solid fa-users text-xl"></i>
        </div>
        <div>
            <p class="text-xs font-semibold text-brand-muted uppercase tracking-wider">Total Users</p>
            <h3 class="text-xl font-bold mt-1 text-brand-text"><?php echo $userStats['total_users'] ?: 0; ?></h3>
        </div>
    </div>
</div>

<!-- Users List Card -->
<div class="bg-white border border-gray-200 rounded-xl shadow-sm p-6 dark:bg-neutral-900 dark:border-neutral-700">
    <div class="flex items-center space-x-3 mb-6">
        <div class="w-8 h-8 rounded-lg bg-brand-primary/10 flex items-center justify-center text-brand-primary">
            <i class="fa-solid fa-circle-user text-sm"></i>
        </div>
        <h4 class="text-base font-bold">Registered Users</h4>
    </div>
    
    <div class="overflow-x-auto">
        <table class="w-full text-left border-collapse">
            <thead>
                <tr class="border-b border-brand-border/60 text-brand-muted text-xs uppercase font-semibold">
                    <th class="pb-3">Username</th>
                    <th class="pb-3">Email</th>
                    <th class="pb-3">Balance</th>
                    <th class="pb-3">Spent</th>
                    <th class="pb-3">Type</th>
                    <th class="pb-3">Joined</th>
                    <th class="pb-3 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-brand-border/40 text-sm">
                <?php if (empty($users)): ?>
                <tr>
                    <td colspan="7" class="text-center text-brand-muted py-12">
                        <i class="fa-solid fa-user-slash text-3xl mb-2 opacity-50 block"></i>
                        No users registered yet.
                    </td>
                </tr>
                <?php else: ?>
                    <?php foreach ($users as $u):
                        $uTotalSpent = getUserTotalSpent($u['id']);
                        $uIsReseller = !empty($u['is_reseller']);
                    ?>
                    <tr>
                        <td class="py-3.5 flex items-center space-x-3">
                            <div class="w-8 h-8 rounded-full bg-brand-primary/20 border border-brand-primary/30 flex items-center justify-center text-xs font-bold text-brand-primary uppercase">
                                <?php echo substr($u['username'], 0, 2); ?>
                            </div>
                            <span class="font-semibold text-brand-text"><?php echo htmlspecialchars($u['username']); ?></span>
                        </td>
                        <td class="py-3.5 text-brand-muted text-xs"><?php echo htmlspecialchars($u['email']); ?></td>
                        <td class="py-3.5 text-brand-text text-xs font-semibold">₹<?php echo number_format($u['balance'], 2); ?></td>
                        <td class="py-3.5 text-brand-muted text-xs">₹<?php echo number_format($uTotalSpent, 2); ?></td>
                        <td class="py-3.5 text-xs">
                            <?php if ($uIsReseller): ?>
                                <span class="px-2 py-0.5 rounded-full bg-emerald-500/15 text-emerald-400 border border-emerald-500/30 font-bold">Reseller</span>
                            <?php else: ?>
                                <span class="px-2 py-0.5 rounded-full bg-brand-primary/10 text-brand-muted border border-brand-border font-semibold">User</span>
                            <?php endif; ?>
                        </td>
                        <td class="py-3.5 text-brand-muted text-xs"><?php echo date('d M Y', strtotime($u['created_at'])); ?></td>
                        <td class="py-3.5 text-right space-x-1.5 whitespace-nowrap">
                            <button type="button"
                               class="p-2 rounded-lg bg-brand-surface border border-brand-border text-xs font-bold text-brand-primary hover:border-brand-primary/40 transition-colors inline-block"
                               onclick="openEditModal(<?php echo $u['id']; ?>, '<?php echo htmlspecialchars($u['username'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($u['balance'], ENT_QUOTES); ?>')">
                                <i class="fa-solid fa-pen text-brand-primary"></i>
                            </button>
                            <form method="POST" style="display:inline;">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="toggle_reseller" value="<?php echo $u['id']; ?>">
                                <button type="submit"
                                   class="p-2 rounded-lg bg-brand-surface border border-brand-border text-xs font-bold <?php echo $uIsReseller ? 'text-emerald-400 hover:border-emerald-500/40' : 'text-amber-400 hover:border-amber-500/40'; ?> transition-colors inline-block"
                                   onclick="return confirm('<?php echo $uIsReseller ? 'Remove reseller status from this user?' : 'Make this user a reseller (API access enabled)?'; ?>')"
                                   title="<?php echo $uIsReseller ? 'Remove Reseller' : 'Make Reseller'; ?>">
                                    <i class="fa-solid <?php echo $uIsReseller ? 'fa-handshake-simple' : 'fa-user-plus'; ?>"></i>
                                </button>
                            </form>
                            <?php if ($uIsReseller): ?>
                            <form method="POST" style="display:inline;">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="regen_reseller_key" value="<?php echo $u['id']; ?>">
                                <button type="submit"
                                   class="p-2 rounded-lg bg-brand-surface border border-brand-border text-xs font-bold text-sky-400 hover:border-sky-500/40 transition-colors inline-block"
                                   onclick="return confirm('Regenerate API key for this reseller? The old key will stop working immediately.')"
                                   title="Regenerate API Key">
                                    <i class="fa-solid fa-rotate"></i>
                                </button>
                            </form>
                            <?php endif; ?>
                            <form method="POST" style="display:inline;">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="delete" value="<?php echo $u['id']; ?>">
                                <button type="submit" 
                                   class="p-2 rounded-lg bg-brand-surface border border-brand-border text-xs font-bold text-brand-error hover:border-brand-error/40 transition-colors inline-block"
                                   onclick="return confirm('Are you sure you want to delete this user? All their transactions and purchased keys will be permanently deleted.')">
                                    <i class="fa-regular fa-trash-can text-brand-error"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Edit User Modal -->
<div id="editUserModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center p-4">
    <div class="bg-white dark:bg-neutral-900 border border-gray-200 dark:border-neutral-700 rounded-xl shadow-lg w-full max-w-md p-6">
        <div class="flex items-center justify-between mb-5">
            <h4 class="text-base font-bold">Edit User: <span id="editUserName" class="text-brand-primary"></span></h4>
            <button type="button" onclick="closeEditModal()" class="text-brand-muted hover:text-brand-error">
                <i class="fa-solid fa-xmark text-lg"></i>
            </button>
        </div>
        <form method="POST">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="edit_user_id" id="editUserId" value="">

            <label class="block text-xs font-semibold text-brand-muted uppercase tracking-wider mb-1.5">Balance</label>
            <input type="number" step="0.01" name="balance" id="editUserBalance"
                   class="w-full mb-4 px-3 py-2.5 rounded-lg bg-brand-surface border border-brand-border text-sm text-brand-text focus:outline-none focus:border-brand-primary"
                   required>

            <label class="block text-xs font-semibold text-brand-muted uppercase tracking-wider mb-1.5">New Password <span class="normal-case font-normal">(leave blank to keep unchanged)</span></label>
            <input type="text" name="new_password" id="editUserPassword" minlength="6"
                   placeholder="Leave blank to keep current password"
                   class="w-full mb-5 px-3 py-2.5 rounded-lg bg-brand-surface border border-brand-border text-sm text-brand-text focus:outline-none focus:border-brand-primary">

            <div class="flex justify-end space-x-2.5">
                <button type="button" onclick="closeEditModal()"
                        class="px-4 py-2.5 rounded-lg bg-brand-surface border border-brand-border text-xs font-bold text-brand-muted hover:text-brand-text transition-colors">
                    Cancel
                </button>
                <button type="submit"
                        class="px-4 py-2.5 rounded-lg bg-brand-primary text-xs font-bold text-white hover:opacity-90 transition-opacity">
                    Save Changes
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openEditModal(id, username, balance) {
    document.getElementById('editUserId').value = id;
    document.getElementById('editUserName').innerText = username;
    document.getElementById('editUserBalance').value = balance;
    document.getElementById('editUserPassword').value = '';
    const modal = document.getElementById('editUserModal');
    modal.classList.remove('hidden');
    modal.classList.add('flex');
}
function closeEditModal() {
    const modal = document.getElementById('editUserModal');
    modal.classList.add('hidden');
    modal.classList.remove('flex');
}
</script>

<?php
require_once 'includes/footer.php';
?>
