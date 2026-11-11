<?php
require_once 'config/database.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';
require_once 'includes/payment.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$pdo = getDBConnection();
$userId = $_SESSION['user_id'];

// Load payment gateway config
$pgConfig = getPaymentConfig();
$pgEnabled = ($pgConfig['enabled'] === '1' && !empty($pgConfig['user_token']));

// Get user data
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// Get user transactions
$stmt = $pdo->prepare("SELECT * FROM transactions WHERE user_id = ? ORDER BY created_at DESC LIMIT 50");
$stmt->execute([$userId]);
$transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate today's total deposit (sum of positive balance_add transactions today)
$todayDeposit = 0.0;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM transactions
                           WHERE user_id = ? AND type = 'balance_add' AND amount > 0
                             AND status = 'completed' AND DATE(created_at) = CURDATE()");
    $stmt->execute([$userId]);
    $todayDeposit = (float)$stmt->fetchColumn();
} catch (Exception $e) {
    // ignore
}

// Admin-configured "How To Deposit" YouTube video
$depositVideoEmbed = getDepositVideoUrl();
$showHowToButton   = ($depositVideoEmbed !== '');

// Current user balance (fresh from DB)
$currentBalance = isset($user['balance']) ? (float)$user['balance'] : 0.0;

// Pre-fill mobile number from session if available (after a recharge attempt)
$prefilledMobile = isset($_SESSION['recharge_mobile']) ? htmlspecialchars($_SESSION['recharge_mobile']) : '';


$page_title = "Deposit - " . SITE_NAME . " " . SITE_SUBTITLE;
require_once 'includes/header.php';
?>

<!-- ===== Deposit Page (mobile-first neon UI matching the reference screenshot) ===== -->
<div class="max-w-md mx-auto space-y-5 animate-fade-in">

    <!-- Top: Current Balance + Today Deposit (cyan-bordered boxes, stacked one per row like reference) -->
    <div class="space-y-4">
        <div class="relative rounded-2xl p-4 sm:p-5 bg-neutral-900 border-2 border-cyan-400/70 shadow-[0_0_20px_rgba(34,211,238,0.25)] dark:bg-neutral-900 text-center">
            <div class="text-cyan-300 mb-1">
                <span class="text-[10px] font-bold uppercase tracking-wider">Current Balance</span>
            </div>
            <p class="text-xl sm:text-2xl font-black text-white text-glow-cyan"><?php echo formatCurrency($currentBalance); ?></p>
        </div>
        <div class="relative rounded-2xl p-4 sm:p-5 bg-neutral-900 border-2 border-cyan-400/70 shadow-[0_0_20px_rgba(34,211,238,0.25)] dark:bg-neutral-900 text-center">
            <div class="text-cyan-300 mb-1">
                <span class="text-[10px] font-bold uppercase tracking-wider">Today Deposit</span>
            </div>
            <p class="text-xl sm:text-2xl font-black text-white text-glow-cyan"><?php echo formatCurrency($todayDeposit); ?></p>
        </div>
    </div>

    <!-- Middle: Instant UPI Deposit box (green border) + HOW TO DEPOSIT button (red) -->
    <div class="relative rounded-2xl bg-neutral-900 border-2 border-green-400/70 shadow-[0_0_22px_rgba(34,197,94,0.22)] p-5 text-center">
        <h3 class="text-base sm:text-lg font-extrabold text-white tracking-wide flex items-center justify-center gap-2">
            <i class="fa-solid fa-bolt text-green-400"></i>
            Instant UPI Deposit
        </h3>

        <?php if ($showHowToButton): ?>
            <button type="button" id="how-to-deposit-btn"
                    class="mt-3 inline-flex items-center justify-center gap-2 px-6 py-2.5 rounded-xl bg-red-600 hover:bg-red-700 active:scale-[0.98] transition-all text-white text-sm font-extrabold shadow-[0_0_18px_rgba(239,68,68,0.45)]">
                <i class="fa-brands fa-youtube"></i>
                <span>HOW TO DEPOSIT?</span>
            </button>
        <?php else: ?>
            <p class="mt-3 text-[11px] text-brand-muted">Enter an amount below and pay securely via UPI. Your balance will be credited instantly.</p>
        <?php endif; ?>
    </div>

    <!-- Bottom: Deposit form (Amount + Mobile Number + PAY INSTANTLY) -->
    <?php if ($pgEnabled): ?>
        <form method="POST" action="wallet-recharge" class="space-y-4 rounded-2xl bg-neutral-900 border-2 border-purple-500/70 shadow-[0_0_22px_rgba(168,85,247,0.22)] p-5">
            <?php echo csrf_field(); ?>

            <!-- Amount -->
            <div>
                <label for="recharge_amount" class="block text-[10px] font-bold uppercase tracking-wider text-brand-muted mb-1.5">Amount (&#8377;)</label>
                <input type="number" step="1" min="1" id="recharge_amount" name="amount" required
                       class="w-full px-4 py-3 bg-neutral-800 border-2 border-purple-500/60 rounded-xl text-base font-bold text-white focus:outline-none focus:border-purple-400 placeholder-neutral-500"
                       placeholder="Enter amount">
            </div>

            <!-- Mobile Number -->
            <div>
                <label for="recharge_mobile" class="block text-[10px] font-bold uppercase tracking-wider text-brand-muted mb-1.5">Mobile Number</label>
                <input type="tel" id="recharge_mobile" name="mobile_number" pattern="[0-9+\s\-]{10,15}" maxlength="15"
                       value="<?php echo $prefilledMobile; ?>"
                       class="w-full px-4 py-3 bg-neutral-800 border-2 border-neutral-600 rounded-xl text-base font-medium text-white focus:outline-none focus:border-purple-400 placeholder-neutral-500"
                       placeholder="Your Mobile Number">
            </div>

            <!-- Pay Instantly (purple) -->
            <button type="submit" class="w-full py-3.5 rounded-xl bg-purple-600 hover:bg-purple-700 active:scale-[0.98] transition-all text-white text-base font-extrabold shadow-[0_0_22px_rgba(168,85,247,0.5)] flex items-center justify-center gap-2">
                <i class="fa-solid fa-bolt"></i>
                <span>PAY INSTANTLY</span>
            </button>
        </form>
    <?php else: ?>
        <!-- Payment gateway disabled: show admin contact instead -->
        <div class="rounded-2xl bg-neutral-900 border-2 border-brand-border/60 p-5 text-center">
            <p class="text-xs text-brand-muted leading-relaxed mb-4">Auto payment is currently unavailable. To top up your wallet balance, please contact the administrator directly with your registered username and payment receipt.</p>
            <div class="p-3.5 bg-neutral-800 border border-brand-border rounded-xl flex items-center gap-3 text-left">
                <div class="w-8 h-8 rounded-lg bg-brand-secondary/15 flex items-center justify-center text-brand-secondary">
                    <i class="fa-regular fa-envelope text-sm"></i>
                </div>
                <div class="truncate">
                    <p class="text-[10px] text-brand-muted uppercase tracking-wider font-bold">Admin Support</p>
                    <a href="mailto:<?php echo htmlspecialchars(SUPPORT_EMAIL); ?>" class="text-xs font-bold text-brand-secondary hover:underline truncate block"><?php echo htmlspecialchars(SUPPORT_EMAIL); ?></a>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Transaction History -->
    <div class="bg-white border border-gray-200 rounded-2xl shadow-sm p-5 dark:bg-neutral-900 dark:border-neutral-700">
        <h3 class="text-base font-bold mb-4 flex items-center gap-2">
            <i class="fa-solid fa-receipt text-brand-primary"></i>
            <span>Transaction History</span>
        </h3>

        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse">
                <thead>
                    <tr class="border-b border-brand-border/60 text-brand-muted text-xs uppercase font-semibold">
                        <th class="pb-3">Transaction ID</th>
                        <th class="pb-3">Type</th>
                        <th class="pb-3">Reference / Details</th>
                        <th class="pb-3">Date</th>
                        <th class="pb-3 text-right">Amount</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-brand-border/40 text-sm">
                    <?php if (empty($transactions)): ?>
                    <tr>
                        <td colspan="5" class="text-center text-brand-muted py-12">
                            <i class="fa-solid fa-receipt text-3xl mb-2 opacity-50 block"></i>
                            No transaction history available.
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
                            <td class="py-3.5 text-xs text-brand-muted"><?php echo formatDate($tx['created_at']); ?></td>
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
</div>

<!-- ===== YouTube "How To Deposit" Modal ===== -->
<?php if ($showHowToButton): ?>
<div id="how-to-modal" class="fixed inset-0 z-[120] hidden items-center justify-center bg-black/80 backdrop-blur-sm p-4">
    <div class="relative w-full max-w-2xl bg-neutral-900 border-2 border-red-500/60 rounded-2xl shadow-[0_0_40px_rgba(239,68,68,0.4)] overflow-hidden">
        <!-- Modal header -->
        <div class="flex items-center justify-between px-4 py-3 border-b border-neutral-700">
            <h4 class="text-sm font-bold text-white flex items-center gap-2">
                <i class="fa-brands fa-youtube text-red-500"></i>
                <span>HOW TO DEPOSIT?</span>
            </h4>
            <button type="button" id="how-to-close" class="w-8 h-8 inline-flex items-center justify-center rounded-lg text-neutral-400 hover:text-white hover:bg-neutral-800 transition-colors" aria-label="Close">
                <i class="fa-solid fa-xmark text-lg"></i>
            </button>
        </div>
        <!-- Video frame -->
        <div class="aspect-video w-full bg-black">
            <iframe id="how-to-iframe" class="w-full h-full" src="" title="How To Deposit" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" allowfullscreen></iframe>
        </div>
    </div>
</div>

<script>
(function () {
    var btn = document.getElementById('how-to-deposit-btn');
    var modal = document.getElementById('how-to-modal');
    var closeBtn = document.getElementById('how-to-close');
    var iframe = document.getElementById('how-to-iframe');
    var videoSrc = "<?php echo htmlspecialchars($depositVideoEmbed, ENT_QUOTES); ?>";

    function openModal() {
        if (!modal) return;
        iframe.src = videoSrc + (videoSrc.indexOf('?') === -1 ? '?autoplay=1' : '&autoplay=1');
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        document.body.style.overflow = 'hidden';
    }
    function closeModal() {
        if (!modal) return;
        iframe.src = '';
        modal.classList.add('hidden');
        modal.classList.remove('flex');
        document.body.style.overflow = '';
    }

    if (btn) { btn.addEventListener('click', openModal); }
    if (closeBtn) { closeBtn.addEventListener('click', closeModal); }
    if (modal) {
        modal.addEventListener('click', function (e) {
            if (e.target === modal) { closeModal(); }
        });
    }
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && modal && !modal.classList.contains('hidden')) { closeModal(); }
    });
})();
</script>
<?php endif; ?>
<!-- ===== End Deposit Page ===== -->

<?php
require_once 'includes/footer.php';
?>