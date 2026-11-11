<?php
require_once 'includes/auth.php';
require_once 'includes/functions.php';
require_once 'includes/refer_system.php';

// Both normal users AND resellers can use this page.
requireLogin();

// Make sure tables/columns exist (idempotent)
ensureReferSystemTables();

$userId = (int)$_SESSION['user_id'];

// Build referral link + fetch earnings + history
$referralLink = getUserReferralLink($userId);
$stats        = getReferralStats($userId);
$totalEarn    = (float)$stats['total_earnings'];
$commissions  = getReferralCommissions($userId, 200);

$page_title = "Refer and Earn - " . SITE_NAME . " " . SITE_SUBTITLE;

// SEO defaults (lightweight)
$page_meta = [
    'title'       => 'Refer and Earn | ' . SITE_NAME,
    'description' => 'Refer friends and earn 5% lifetime commission on every key purchase. Refer and Earn at ' . SITE_NAME . '.',
    'keywords'    => 'refer and earn, referral commission, refer friend, lifetime commission, ' . SITE_NAME,
    'canonical'   => '',
    'robots'      => 'noindex, follow',
    'schema'      => 'login'
];
require_once 'includes/header.php';
?>

<!-- ===== Header ===== -->
<div class="mb-8">
    <h2 class="text-2xl font-bold tracking-tight text-glow-primary flex items-center gap-2">
        <i class="fa-solid fa-gift text-brand-secondary"></i>
        Refer and Earn
    </h2>
    <p class="text-sm text-brand-muted mt-1 font-medium">Refer your friends & resellers. Earn <span class="text-brand-success font-bold">5% lifetime commission</span> on every key they buy — auto added to your wallet.</p>
</div>

<!-- ===== TOP: Total Referral Earning ===== -->
<div class="relative rounded-2xl p-6 mb-6 bg-gradient-to-br from-brand-surface via-brand-surface to-emerald-500/5 border-2 border-emerald-400/70 shadow-[0_0_25px_rgba(16,185,129,0.2)] hover:shadow-[0_0_35px_rgba(16,185,129,0.3)] transition-all duration-300">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <p class="text-xs font-bold uppercase tracking-wider text-emerald-400 mb-1"><i class="fa-solid fa-sack-dollar mr-1"></i>Total Referral Earning</p>
            <p class="text-4xl font-extrabold text-emerald-400" style="text-shadow:0 0 15px rgba(16,185,129,0.4);">
                &#8377;<?php echo number_format($totalEarn, 2); ?>
            </p>
        </div>
        <div class="grid grid-cols-2 gap-3 sm:gap-4">
            <div class="bg-brand-bg/50 p-3 rounded-xl border border-emerald-400/20 text-center min-w-[110px]">
                <p class="text-[10px] text-brand-muted font-semibold uppercase">Commissions</p>
                <p class="text-xl font-bold text-brand-text mt-1"><?php echo (int)$stats['total_commissions']; ?></p>
            </div>
            <div class="bg-brand-bg/50 p-3 rounded-xl border border-emerald-400/20 text-center min-w-[110px]">
                <p class="text-[10px] text-brand-muted font-semibold uppercase">Referred Buyers</p>
                <p class="text-xl font-bold text-brand-text mt-1"><?php echo (int)$stats['referred_buyers']; ?></p>
            </div>
        </div>
    </div>
</div>

<!-- ===== REFERRAL LINK ===== -->
<div class="glass-card rounded-2xl p-6 mb-6 border border-brand-border/60">
    <h3 class="text-base font-bold mb-1 flex items-center space-x-2">
        <i class="fa-solid fa-link text-brand-primary"></i>
        <span>Your Referral Link</span>
    </h3>
    <p class="text-xs text-brand-muted mb-4">Share this link. When someone registers through it and buys a key, you earn 5% — forever.</p>

    <?php if (!empty($referralLink)): ?>
    <div class="flex flex-col sm:flex-row items-stretch gap-3">
        <div class="flex-1 relative">
            <input type="text" id="referralLinkInput" readonly value="<?php echo htmlspecialchars($referralLink); ?>"
                   class="w-full px-4 py-3 bg-brand-bg/60 border-2 border-emerald-400/40 rounded-xl text-sm font-mono text-brand-text focus:outline-none focus:border-emerald-400 select-all pr-12">
            <i class="fa-solid fa-share-nodes absolute right-4 top-1/2 -translate-y-1/2 text-emerald-400/60"></i>
        </div>
        <button type="button" onclick="copyRefLink(this)" class="px-5 py-3 bg-gradient-to-r from-emerald-500 to-teal-500 text-white text-sm font-bold rounded-xl hover:opacity-90 active:scale-[0.98] transition-all flex items-center justify-center gap-2 shadow-[0_0_15px_rgba(16,185,129,0.25)] whitespace-nowrap">
            <i class="fa-regular fa-copy"></i>
            <span>Copy Link</span>
        </button>
    </div>

    <!-- Social share -->
    <div class="flex flex-wrap items-center gap-2 mt-4">
        <span class="text-[10px] text-brand-muted font-semibold uppercase tracking-wider mr-1">Share:</span>
        <a href="https://wa.me/?text=<?php echo rawurlencode('Join ' . SITE_NAME . ' and buy FF panel keys! Use my referral link: ' . $referralLink); ?>" target="_blank" rel="noopener" class="px-3 py-2 bg-green-500/10 border border-green-500/30 text-green-400 text-xs font-bold rounded-lg hover:bg-green-500/20 transition-all flex items-center gap-1.5">
            <i class="fa-brands fa-whatsapp"></i> WhatsApp
        </a>
        <a href="https://t.me/share/url?url=<?php echo rawurlencode($referralLink); ?>&text=<?php echo rawurlencode('Join ' . SITE_NAME . ' — buy FF panel keys!'); ?>" target="_blank" rel="noopener" class="px-3 py-2 bg-sky-500/10 border border-sky-500/30 text-sky-400 text-xs font-bold rounded-lg hover:bg-sky-500/20 transition-all flex items-center gap-1.5">
            <i class="fa-brands fa-telegram"></i> Telegram
        </a>
        <a href="https://twitter.com/intent/tweet?url=<?php echo rawurlencode($referralLink); ?>&text=<?php echo rawurlencode('Join ' . SITE_NAME . ' — buy FF panel keys!'); ?>" target="_blank" rel="noopener" class="px-3 py-2 bg-neutral-500/10 border border-neutral-500/30 text-brand-text text-xs font-bold rounded-lg hover:bg-neutral-500/20 transition-all flex items-center gap-1.5">
            <i class="fa-brands fa-x-twitter"></i> X
        </a>
    </div>
    <?php else: ?>
    <div class="bg-brand-warning/10 border-2 border-yellow-500/30 p-4 rounded-xl text-center">
        <i class="fa-solid fa-triangle-exclamation text-brand-warning text-xl mb-2"></i>
        <p class="text-sm font-bold">Could not generate your referral link.</p>
        <p class="text-xs text-brand-muted mt-1">Please try again later or contact admin.</p>
    </div>
    <?php endif; ?>
</div>

<!-- ===== COMMISSION HISTORY ===== -->
<div class="glass-card rounded-2xl overflow-hidden border border-brand-border/60">
    <div class="p-5 border-b border-brand-border/60 flex items-center justify-between bg-brand-surface/40">
        <h3 class="text-base font-bold flex items-center space-x-2">
            <i class="fa-solid fa-clock-rotate-left text-brand-secondary"></i>
            <span>Commission History</span>
        </h3>
        <span class="text-xs font-bold text-brand-muted"><?php echo count($commissions); ?> records</span>
    </div>

    <div class="overflow-x-auto">
        <table class="w-full text-left border-collapse">
            <thead>
                <tr class="border-b border-brand-border/60 text-brand-muted text-xs uppercase font-semibold">
                    <th class="px-5 py-3">#</th>
                    <th class="px-5 py-3">Referred User</th>
                    <th class="px-5 py-3 hidden sm:table-cell">Order Amount</th>
                    <th class="px-5 py-3 hidden md:table-cell">Rate</th>
                    <th class="px-5 py-3">Commission</th>
                    <th class="px-5 py-3 hidden sm:table-cell">Source</th>
                    <th class="px-5 py-3">Date</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-brand-border/40 text-sm">
                <?php if (empty($commissions)): ?>
                <tr>
                    <td colspan="7" class="text-center text-brand-muted py-12">
                        <i class="fa-solid fa-inbox text-3xl mb-2 opacity-50 block"></i>
                        No commission earned yet. Share your referral link to start earning!
                    </td>
                </tr>
                <?php else: ?>
                    <?php foreach ($commissions as $i => $c): ?>
                    <tr class="hover:bg-emerald-500/5 transition-colors">
                        <td class="px-5 py-3.5 text-xs text-brand-muted font-mono"><?php echo $i + 1; ?></td>
                        <td class="px-5 py-3.5 text-xs text-brand-text font-medium">
                            <?php echo htmlspecialchars($c['referred_username'] ?? ('User #' . (int)$c['referred_user_id'])); ?>
                        </td>
                        <td class="px-5 py-3.5 text-xs text-brand-muted hidden sm:table-cell">&#8377;<?php echo number_format((float)$c['order_amount'], 2); ?></td>
                        <td class="px-5 py-3.5 text-xs text-brand-muted hidden md:table-cell"><?php echo number_format((float)$c['commission_rate'], 2); ?>%</td>
                        <td class="px-5 py-3.5 text-sm font-bold text-emerald-400">+&#8377;<?php echo number_format((float)$c['commission_amount'], 2); ?></td>
                        <td class="px-5 py-3.5 text-xs hidden sm:table-cell">
                            <?php
                            $srcLabel = $c['source'] === 'reseller_api' ? 'Reseller API' : 'Wallet';
                            $srcClass = $c['source'] === 'reseller_api' ? 'bg-sky-500/10 text-sky-400' : 'bg-brand-primary/10 text-brand-primary';
                            ?>
                            <span class="px-2 py-0.5 rounded text-[9px] font-bold uppercase <?php echo $srcClass; ?>"><?php echo htmlspecialchars($srcLabel); ?></span>
                        </td>
                        <td class="px-5 py-3.5 text-xs text-brand-muted"><?php echo date('d M Y, H:i', strtotime($c['created_at'])); ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ===== How it works ===== -->
<div class="mt-6 grid grid-cols-1 sm:grid-cols-3 gap-4">
    <div class="bg-brand-surface/40 border border-brand-border rounded-2xl p-5 text-center">
        <div class="w-11 h-11 mx-auto rounded-xl bg-brand-primary/10 border border-brand-primary/20 flex items-center justify-center text-brand-primary mb-3">
            <i class="fa-solid fa-share-nodes"></i>
        </div>
        <p class="text-sm font-bold text-brand-text mb-1">1. Share Link</p>
        <p class="text-[11px] text-brand-muted">Send your referral link to friends & customers.</p>
    </div>
    <div class="bg-brand-surface/40 border border-brand-border rounded-2xl p-5 text-center">
        <div class="w-11 h-11 mx-auto rounded-xl bg-brand-secondary/10 border border-brand-secondary/20 flex items-center justify-center text-brand-secondary mb-3">
            <i class="fa-solid fa-user-plus"></i>
        </div>
        <p class="text-sm font-bold text-brand-text mb-1">2. They Register</p>
        <p class="text-[11px] text-brand-muted">They sign up using your link and buy keys.</p>
    </div>
    <div class="bg-brand-surface/40 border border-brand-border rounded-2xl p-5 text-center">
        <div class="w-11 h-11 mx-auto rounded-xl bg-emerald-500/10 border border-emerald-500/20 flex items-center justify-center text-emerald-400 mb-3">
            <i class="fa-solid fa-coins"></i>
        </div>
        <p class="text-sm font-bold text-brand-text mb-1">3. You Earn 5%</p>
        <p class="text-[11px] text-brand-muted">5% of every purchase — lifetime, auto in wallet.</p>
    </div>
</div>

<script>
function copyRefLink(btn) {
    var input = document.getElementById('referralLinkInput');
    if (!input) return;
    var text = input.value;
    function done() {
        var span = btn.querySelector('span');
        var icon = btn.querySelector('i');
        var prevSpan = span ? span.textContent : '';
        var prevIcon = icon ? icon.className : '';
        if (span) span.textContent = 'Copied!';
        if (icon) icon.className = 'fa-solid fa-check';
        setTimeout(function() {
            if (span) span.textContent = prevSpan;
            if (icon) icon.className = prevIcon;
        }, 2000);
    }
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(done, function() {
            input.select();
            try { document.execCommand('copy'); } catch (e) {}
            done();
        });
    } else {
        input.select();
        try { document.execCommand('copy'); } catch (e) {}
        done();
    }
}
</script>

<?php
require_once 'includes/footer.php';
?>
