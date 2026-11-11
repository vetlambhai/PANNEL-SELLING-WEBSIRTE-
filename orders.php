<?php
require_once 'includes/auth.php';
require_once 'includes/functions.php';

requireLogin();

$userId = $_SESSION['user_id'];
$error = '';
$success = '';

try {
    $pdo = getDBConnection();
    
    // Get all user transactions (orders)
    $stmt = $pdo->prepare("SELECT * FROM transactions WHERE user_id = ? ORDER BY created_at DESC");
    $stmt->execute([$userId]);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    $error = 'Failed to fetch orders: ' . $e->getMessage();
}

$page_title = "My Orders - " . SITE_NAME;
require_once 'includes/header.php';
?>

<!-- ===== My Orders Page with Enhanced Border Effects (Like Wallet) ===== -->
<div class="max-w-6xl mx-auto space-y-5 animate-fade-in">

    <!-- Header with cyan neon border (like wallet current balance) -->
    <div class="relative rounded-2xl p-5 bg-neutral-900 border-2 border-cyan-400/70 shadow-[0_0_20px_rgba(34,211,238,0.25)] dark:bg-neutral-900">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <h2 class="text-2xl font-bold tracking-tight text-white text-glow-cyan">My Orders</h2>
                <p class="text-sm text-cyan-300/70 mt-1">View and track the status of your purchases.</p>
            </div>
            <span class="text-xs font-bold text-cyan-300 bg-cyan-500/10 px-4 py-2 rounded-full border border-cyan-400/30 shadow-[0_0_15px_rgba(34,211,238,0.15)]">
                <i class="fa-solid fa-receipt mr-1"></i>
                <?php echo count($orders); ?> Orders
            </span>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="relative rounded-xl bg-red-900/20 border-2 border-red-500/50 shadow-[0_0_20px_rgba(239,68,68,0.2)] text-red-400 text-sm p-4 flex items-center space-x-2">
            <i class="fa-solid fa-circle-exclamation"></i>
            <span><?php echo htmlspecialchars($error); ?></span>
        </div>
    <?php endif; ?>

    <!-- Orders Table with purple neon border (like wallet form) -->
    <div class="relative rounded-2xl bg-neutral-900 border-2 border-purple-500/70 shadow-[0_0_22px_rgba(168,85,247,0.22)] p-5">
        <h3 class="text-base font-bold mb-4 flex items-center space-x-2 text-white">
            <i class="fa-solid fa-clock-rotate-left text-purple-400"></i>
            <span>Order History</span>
        </h3>

        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse">
                <thead>
                    <tr class="border-b border-purple-500/20 text-cyan-300 text-xs uppercase font-semibold">
                        <th class="px-6 py-4">Order Details</th>
                        <th class="px-6 py-4">Date</th>
                        <th class="px-6 py-4 text-center">Status</th>
                        <th class="px-6 py-4 text-right">Amount</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-purple-500/10 text-sm">
                    <?php if (empty($orders)): ?>
                    <tr>
                        <td colspan="4" class="px-6 py-12 text-center">
                            <div class="flex flex-col items-center justify-center">
                                <i class="fa-solid fa-box-open text-4xl mb-3 text-cyan-400/30"></i>
                                <p class="text-neutral-400">You haven't made any orders yet.</p>
                                <a href="keys" class="mt-4 inline-flex items-center gap-2 px-5 py-2.5 bg-purple-600 hover:bg-purple-700 active:scale-[0.98] transition-all text-white text-xs font-bold rounded-xl shadow-[0_0_22px_rgba(168,85,247,0.5)]">
                                    Go to Store <i class="fa-solid fa-arrow-right"></i>
                                </a>
                            </div>
                        </td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($orders as $order): ?>
                        <tr class="hover:bg-white/5 transition-colors">
                            <td class="px-6 py-4">
                                <div class="font-bold text-white">
                                    <?php echo htmlspecialchars($order['reference'] ?: 'Order #' . $order['id']); ?>
                                </div>
                                <div class="text-[10px] text-cyan-400/60 font-mono">#<?php echo $order['id']; ?></div>
                            </td>
                            <td class="px-6 py-4">
                                <div class="text-xs text-neutral-400">
                                    <?php echo formatDate($order['created_at']); ?>
                                </div>
                            </td>
                            <!-- ===== IMPROVED STATUS UI WITH BETTER COMPLETED STYLE ===== -->
                            <td class="px-6 py-4 text-center">
                                <?php 
                                $status = strtolower($order['status'] ?? '');
                                if ($status === 'pending'): ?>
                                    <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-[11px] font-bold uppercase bg-yellow-500/10 text-yellow-400 border border-yellow-400/30 shadow-[0_0_15px_rgba(234,179,8,0.15)]">
                                        <i class="fa-regular fa-clock"></i> PENDING
                                    </span>
                                <?php elseif ($status === 'approved' || $status === 'completed'): ?>
                                    <!-- IMPROVED COMPLETED BADGE - Better gradient & glow -->
                                    <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-[11px] font-bold uppercase bg-gradient-to-r from-emerald-600/20 to-green-600/20 text-emerald-300 border border-emerald-400/50 shadow-[0_0_25px_rgba(16,185,129,0.3)] hover:shadow-[0_0_35px_rgba(16,185,129,0.5)] transition-all duration-300">
                                        <i class="fa-solid fa-circle-check text-emerald-400 text-xs"></i> COMPLETED
                                    </span>
                                <?php elseif ($status === 'rejected' || $status === 'failed'): ?>
                                    <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-[11px] font-bold uppercase bg-red-500/10 text-red-400 border border-red-400/30 shadow-[0_0_15px_rgba(239,68,68,0.15)]">
                                        <i class="fa-regular fa-circle-xmark"></i> FAILED
                                    </span>
                                <?php else: ?>
                                    <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-[11px] font-bold uppercase bg-neutral-500/10 text-neutral-400 border border-neutral-400/30">
                                        <i class="fa-regular fa-circle-question"></i> <?php echo strtoupper(htmlspecialchars($order['status'] ?: 'UNKNOWN')); ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <!-- ===== END IMPROVED STATUS UI ===== -->
                            <td class="px-6 py-4 text-right font-bold text-sm <?php echo $order['amount'] < 0 ? 'text-red-400' : 'text-emerald-400'; ?>">
                                <?php echo formatCurrency(abs($order['amount'])); ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<style>
.text-glow-cyan {
    text-shadow: 0 0 20px rgba(34,211,238,0.3);
}

/* Animation */
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

/* Custom scrollbar for table */
.overflow-x-auto::-webkit-scrollbar {
    height: 6px;
}
.overflow-x-auto::-webkit-scrollbar-track {
    background: rgba(23, 23, 23, 0.5);
    border-radius: 10px;
}
.overflow-x-auto::-webkit-scrollbar-thumb {
    background: rgba(168, 85, 247, 0.3);
    border-radius: 10px;
}
.overflow-x-auto::-webkit-scrollbar-thumb:hover {
    background: rgba(168, 85, 247, 0.5);
}

/* Improved pulse animation for COMPLETED badge */
@keyframes completed-pulse {
    0%, 100% { 
        box-shadow: 0 0 25px rgba(16, 185, 129, 0.3);
    }
    50% { 
        box-shadow: 0 0 45px rgba(16, 185, 129, 0.6);
    }
}

.border-emerald-400\/50 {
    animation: completed-pulse 2.5s ease-in-out infinite;
}

/* Other status glow animations */
@keyframes pulse-glow {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.7; }
}

.border-yellow-400\/30 {
    animation: pulse-glow 2s infinite;
}
.border-red-400\/30 {
    animation: pulse-glow 2s infinite;
}
</style>

<?php
require_once 'includes/footer.php';
?>