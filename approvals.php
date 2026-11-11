<?php
require_once 'includes/auth.php';
require_once 'includes/reseller.php';
require_once 'includes/reseller_provider.php';

// Check if user is admin
requireAdmin();

$pdo = getDBConnection();

$success = '';
$error = '';

// Handle Approval/Rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (isset($_POST['action']) && isset($_POST['transaction_id'])) {
        $transactionId = isset($_POST['transaction_id']) ? (int)$_POST['transaction_id'] : 0;
        if ($transactionId <= 0) { $error = 'Invalid transaction ID.'; } else {
            $action = $_POST['action'];

            try {
                // Get transaction info (outside lock first to read provider details)
                $stmt = $pdo->prepare("SELECT * FROM transactions WHERE id = ? AND status = 'pending' AND type = 'purchase'");
                $stmt->execute([$transactionId]);
                $transaction = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$transaction) {
                    throw new Exception("Invalid or already processed transaction.");
                }

                if ($transaction['plan_id']) {
                    // This is a new Plan purchase
                    $planId = $transaction['plan_id'];
                    $stmt = $pdo->prepare("SELECT p.*, m.name as mod_name, m.provider_pid, m.requires_device_id FROM mod_plans p LEFT JOIN mods m ON p.mod_id = m.id WHERE p.id = ?");
                    $stmt->execute([$planId]);
                    $plan = $stmt->fetch(PDO::FETCH_ASSOC);

                    if (!$plan) {
                        throw new Exception("Plan not found for this transaction.");
                    }

                    if ($action === 'approve') {
                        // Purchase a REAL key from the reseller API (no random generation)
                        $providerPid = trim($plan['provider_pid'] ?? '');
                        $providerDuration = trim($plan['provider_duration'] ?? '');
                        $requiresDeviceId = (int)($plan['requires_device_id'] ?? 0);

                        if (empty($providerPid)) {
                            throw new Exception("This product has no Provider PID configured. Set it in Edit Mod first.");
                        }
                        if (empty($providerDuration)) {
                            throw new Exception("This plan has no Provider Duration string configured. Set it in Manage Plans first.");
                        }
                        if (!isResellerEnabled()) {
                            throw new Exception("Reseller API is not enabled. Configure it in Settings first.");
                        }

                        // Try to fetch device_id from payment_orders linked to this transaction
                        $deviceId = '';
                        $stmtPo = $pdo->prepare("SELECT device_id FROM payment_orders WHERE transaction_id = ? OR user_id = ? ORDER BY id DESC LIMIT 1");
                        $stmtPo->execute([$transactionId, $transaction['user_id']]);
                        $poRow = $stmtPo->fetch(PDO::FETCH_ASSOC);
                        if ($poRow && !empty($poRow['device_id'])) {
                            $deviceId = trim($poRow['device_id']);
                        }

                        if ($requiresDeviceId === 1 && empty($deviceId)) {
                            throw new Exception("Device ID is required for " . htmlspecialchars($plan['mod_name']) . " but none was found for this order. Use Send Key page to manually provide a device ID.");
                        }

                        // Call the reseller API to buy a REAL key
                        $apiResult = buyKeyFromReseller($providerPid, $providerDuration, $deviceId);
                        if (!$apiResult['success']) {
                            throw new Exception("Reseller API error: " . $apiResult['message']);
                        }
                        $keyString = $apiResult['key'];
                        $providerKey = $apiResult['key'];

                        $pdo->beginTransaction();
                        // Mark transaction completed
                        $stmt = $pdo->prepare("UPDATE transactions SET status = 'completed' WHERE id = ?");
                        $stmt->execute([$transactionId]);

                        // Insert the REAL key from the API as sold
                        $stmt = $pdo->prepare("INSERT INTO license_keys (mod_id, license_key, provider_key, duration, duration_type, price, status, sold_to, sold_at, device_id)
                                                VALUES (?, ?, ?, ?, ?, ?, 'sold', ?, NOW(), ?)");
                        $stmt->execute([
                            $plan['mod_id'],
                            $keyString,
                            $providerKey,
                            $plan['duration'],
                            $plan['duration_type'],
                            $plan['price'],
                            $transaction['user_id'],
                            $deviceId ?: null
                        ]);
                        $pdo->commit();

                        $success = "Order #{$transactionId} approved successfully. Real key purchased from reseller and assigned to user.";

                        // ============================================================
                        // AUTO-RESELLER: Order approve hone ke baad total spend
                        // check karke user ko automatically reseller promote karo.
                        // ============================================================
                        try {
                            checkAndPromoteReseller($transaction['user_id']);
                        } catch (Exception $e) {}
                    } elseif ($action === 'reject') {
                        $pdo->beginTransaction();
                        // Mark transaction failed
                        $stmt = $pdo->prepare("UPDATE transactions SET status = 'failed' WHERE id = ?");
                        $stmt->execute([$transactionId]);
                        $pdo->commit();

                        $success = "Order #{$transactionId} rejected.";
                    }
                } else {
                    // Old flow (Fallback for existing pending orders)
                    $keyId = (int)str_replace("License purchase #", "", $transaction['reference']);
                    $pdo->beginTransaction();

                    if ($action === 'approve') {
                        $stmt = $pdo->prepare("UPDATE transactions SET status = 'completed' WHERE id = ?");
                        $stmt->execute([$transactionId]);

                        $stmt = $pdo->prepare("UPDATE license_keys SET sold_at = NOW() WHERE id = ?");
                        $stmt->execute([$keyId]);

                        $success = "Order #{$transactionId} approved successfully. Key assigned to user.";
                    } elseif ($action === 'reject') {
                        $stmt = $pdo->prepare("UPDATE transactions SET status = 'failed' WHERE id = ?");
                        $stmt->execute([$transactionId]);

                        $stmt = $pdo->prepare("UPDATE license_keys SET status = 'available', sold_to = NULL WHERE id = ?");
                        $stmt->execute([$keyId]);

                        $success = "Order #{$transactionId} rejected. Key returned to available pool.";
                    }
                    $pdo->commit();
                }

            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = $e->getMessage();
            }
        }
    }
}

// Get pending orders
$stmt = $pdo->query("SELECT t.*, u.username, 
                        COALESCE(p.plan_name, lk.license_key) as item_details,
                        COALESCE(p.duration, lk.duration) as duration, 
                        COALESCE(p.duration_type, lk.duration_type) as duration_type, 
                        COALESCE(pm.name, lm.name) as mod_name
                    FROM transactions t 
                    JOIN users u ON t.user_id = u.id 
                    LEFT JOIN license_keys lk ON (t.plan_id IS NULL AND CAST(REPLACE(t.reference, 'License purchase #', '') AS UNSIGNED) = lk.id)
                    LEFT JOIN mods lm ON lk.mod_id = lm.id
                    LEFT JOIN mod_plans p ON t.plan_id = p.id
                    LEFT JOIN mods pm ON p.mod_id = pm.id
                    WHERE t.status = 'pending' AND t.type = 'purchase'
                    ORDER BY t.created_at DESC");
$pendingOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = "Pending Orders - " . SITE_NAME;
require_once 'includes/header.php';
?>

<!-- Header -->
<div class="mb-8 flex flex-col md:flex-row md:items-center md:justify-between gap-4">
    <div>
        <h2 class="text-2xl font-bold tracking-tight text-glow-primary">Pending Orders</h2>
        <p class="text-sm text-brand-muted mt-1">Review and approve UPI payments for keys.</p>
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

<!-- Pending Orders Table -->
<div class="bg-brand-surface border border-brand-border rounded-xl rounded-2xl border border-brand-border/60 overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full text-left border-collapse">
            <thead>
                <tr class="bg-brand-surface/40 border-b border-brand-border/60 text-xs uppercase tracking-wider text-brand-muted">
                    <th class="p-4 font-semibold">User</th>
                    <th class="p-4 font-semibold">Mod & Key</th>
                    <th class="p-4 font-semibold">UPI Txn ID</th>
                    <th class="p-4 font-semibold">Amount</th>
                    <th class="p-4 font-semibold">Date</th>
                    <th class="p-4 font-semibold text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-brand-border/30 text-sm">
                <?php if (empty($pendingOrders)): ?>
                <tr>
                    <td colspan="5" class="p-8 text-center text-brand-muted">
                        <i class="fa-solid fa-check-double text-3xl mb-3 opacity-50 block"></i>
                        No pending orders to approve.
                    </td>
                </tr>
                <?php else: ?>
                    <?php foreach ($pendingOrders as $order): ?>
                    <tr class="hover:bg-brand-surface/30 transition-colors">
                        <td class="p-4">
                            <span class="font-semibold text-brand-text"><?php echo htmlspecialchars($order['username']); ?></span>
                        </td>
                        <td class="p-4">
                            <div class="font-bold text-brand-text mb-1"><?php echo htmlspecialchars($order['mod_name']); ?> <span class="text-[10px] bg-brand-primary/10 text-brand-primary px-1.5 py-0.5 rounded ml-1"><?php echo $order['duration'] . ' ' . $order['duration_type']; ?></span></div>
                            <div class="font-mono text-xs text-brand-muted truncate w-48"><?php echo htmlspecialchars($order['item_details']); ?></div>
                        </td>
                        <td class="p-4">
                            <?php if (!empty($order['upi_txn_id'])): ?>
                                <span class="font-mono text-xs text-brand-text bg-brand-bg/60 border border-brand-border px-2 py-1 rounded"><?php echo htmlspecialchars($order['upi_txn_id']); ?></span>
                            <?php else: ?>
                                <span class="text-xs text-brand-muted italic">Not provided</span>
                            <?php endif; ?>
                        </td>
                        <td class="p-4 font-bold text-brand-success">
                            &#8377;<?php echo number_format($order['amount'], 2); ?>
                        </td>
                        <td class="p-4 text-xs text-brand-muted">
                            <?php echo date('d M Y, h:i A', strtotime($order['created_at'])); ?>
                        </td>
                        <td class="p-4 text-right space-x-2">
                            <form method="POST" class="inline-block">
    <?php echo csrf_field(); ?>
                                <input type="hidden" name="transaction_id" value="<?php echo $order['id']; ?>">
                                <button type="submit" name="action" value="approve" class="px-3 py-1.5 bg-brand-success/20 text-brand-success hover:bg-brand-success/30 rounded-lg text-xs font-bold transition-colors">
                                    <i class="fa-solid fa-check mr-1"></i> Approve
                                </button>
                                <button type="submit" name="action" value="reject" class="px-3 py-1.5 bg-brand-error/20 text-brand-error hover:bg-brand-error/30 rounded-lg text-xs font-bold transition-colors ml-2">
                                    <i class="fa-solid fa-xmark mr-1"></i> Reject
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

<?php
require_once 'includes/footer.php';
?>


