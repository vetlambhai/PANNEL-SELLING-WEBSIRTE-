<?php
require_once 'includes/auth.php';
require_once 'includes/functions.php';
require_once 'includes/payment.php';
require_once 'includes/reseller.php';
require_once 'includes/reseller_provider.php';
require_once 'includes/refer_system.php';

// This page is PUBLIC: anyone (logged in or not) can browse the store,
// view mod prices, descriptions, and videos. Purchasing requires login.

// Detect login state (no forced redirect — guests can view the store)
$isLoggedIn = isset($_SESSION['user_id']);
$isAdmin = $isLoggedIn && (isset($_SESSION['role']) && $_SESSION['role'] === 'admin');

// Ensure reseller provider columns exist (e.g. mod_plans.reseller_price)
try { ensureResellerProviderColumns(); } catch (Exception $e) {}

// Load payment gateway config for the UI
$pgConfig = getPaymentConfig();
$pgEnabled = ($pgConfig['enabled'] === '1' && !empty($pgConfig['user_token']));

// Load reseller config for the UI
$resellerEnabled = isResellerEnabled();

$pdo = getDBConnection();
$userId = $isLoggedIn ? $_SESSION['user_id'] : 0;

// Get user data (only for logged-in users)
$user = ['balance' => 0];
$isReseller = false;
if ($isLoggedIn) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        $user = ['balance' => 0];
    }
    // Detect reseller status (resellers see & pay reseller prices)
    $isReseller = isUserReseller($userId);
}

$success = '';
$error = '';
$purchasedKey = ''; // holds the newly purchased key (for display box + auto copy)

// Handle wallet-based key purchase (pay from wallet balance) — LOGGED-IN USERS ONLY
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['buy_from_wallet'])) {
    // Guests cannot purchase — send them to login
    if (!$isLoggedIn) {
        header('Location: login.php');
        exit();
    }
    verify_csrf();
    $planId = (int)($_POST['plan_id'] ?? 0);
    $deviceId = trim($_POST['device_id'] ?? '');

    if ($planId <= 0) {
        $error = 'Invalid plan selected.';
    } elseif (!isResellerEnabled()) {
        $error = 'Reseller API is not configured. Keys cannot be purchased at this time. Please contact admin.';
    } else {
        try {
            // Validate plan and get provider details (only active plans are purchasable; stock_out/inactive blocked server-side)
            $stmt = $pdo->prepare("SELECT p.*, m.name as mod_name, m.provider_pid, m.requires_device_id, m.status as mod_status
                                   FROM mod_plans p
                                   LEFT JOIN mods m ON p.mod_id = m.id
                                   WHERE p.id = ? AND p.status = 'active'");
            $stmt->execute([$planId]);
            $plan = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$plan) {
                throw new Exception('Selected plan is not available for purchase.');
            }

            // Block purchase if the parent mod is inactive or under maintenance
            if (isset($plan['mod_status']) && $plan['mod_status'] !== 'active') {
                if ($plan['mod_status'] === 'under_maintenance') {
                    throw new Exception('This product is currently under maintenance. Please try again later.');
                }
                throw new Exception('This product is not available for purchase.');
            }

            // Validate provider config
            if (empty($plan['provider_pid'])) {
                throw new Exception('This product does not have a Provider PID configured. Please contact admin.');
            }
            if (empty($plan['provider_duration'])) {
                throw new Exception('This plan does not have a provider duration configured. Please contact admin.');
            }

            // Device ID validation
            $requiresDeviceId = (int)$plan['requires_device_id'];
            if ($requiresDeviceId && empty($deviceId)) {
                throw new Exception('Device ID is required for this product. Please enter your Android Device ID.');
            }

            $price = (float)$plan['price'];
            // Resellers pay their reseller price (reseller_price or auto-discount)
            if ($isReseller) {
                $price = (float)getEffectivePrice($plan, true);
            }

            // Check balance first (before calling API)
            $stmt = $pdo->prepare("SELECT balance FROM users WHERE id = ? FOR UPDATE");
            $stmt->execute([$userId]);
            $userRow = $stmt->fetch(PDO::FETCH_ASSOC);
            $currentBalance = (float)$userRow['balance'];

            if ($currentBalance < $price) {
                throw new Exception('Insufficient wallet balance. Please recharge your wallet first.');
            }

            // Call the reseller API to get a REAL key (outside transaction to avoid lock timeout)
            $apiResult = buyKeyFromReseller($plan['provider_pid'], $plan['provider_duration'], $deviceId);

            if (!$apiResult['success'] || empty($apiResult['key'])) {
                throw new Exception('Failed to generate key from provider: ' . ($apiResult['error'] ?? 'Unknown error'));
            }

            $realKey = $apiResult['key'];

            // Now deduct balance and store the key in a transaction
            $pdo->beginTransaction();

            // Deduct balance
            $stmt = $pdo->prepare("UPDATE users SET balance = balance - ? WHERE id = ?");
            $stmt->execute([$price, $userId]);

            // Store the REAL key from the reseller API (NO random/fake keys)
            $stmt = $pdo->prepare("INSERT INTO license_keys (mod_id, license_key, provider_key, duration, duration_type, price, status, sold_to, sold_at, device_id)
                                   VALUES (?, ?, ?, ?, ?, ?, 'sold', ?, NOW(), ?)");
            $stmt->execute([
                $plan['mod_id'],
                $realKey,
                $realKey,
                $plan['duration'],
                $plan['duration_type'],
                $price,
                $userId,
                $deviceId ?: null,
            ]);

            // Record transaction
            $reference = "Plan purchase: " . $plan['plan_name'] . " (Wallet)";
            $stmt = $pdo->prepare("INSERT INTO transactions (user_id, amount, type, reference, status, plan_id) VALUES (?, ?, 'purchase', ?, 'completed', ?)");
            $stmt->execute([$userId, -$price, $reference, $planId]);

            $pdo->commit();
            $success = 'Key purchased successfully from wallet!';
            $purchasedKey = $realKey; // hold the newly generated key for display + auto-copy

            // Award 5% lifetime referral commission to the buyer's referrer (auto-credited to referrer wallet)
            try {
                addReferralCommission($userId, $price, 'wallet', $planId, 'Wallet purchase: ' . ($plan['plan_name'] ?? ''));
            } catch (Throwable $re) {
                // Commission failure must never break the successful purchase
                error_log('Referral commission error (keys.php): ' . $re->getMessage());
            }

            // Refresh user data
            $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            // Refresh purchased keys
            $stmt = $pdo->prepare("SELECT lk.*, m.name as mod_name, m.image_url, m.video_url
                                  FROM license_keys lk
                                  LEFT JOIN mods m ON lk.mod_id = m.id
                                  WHERE lk.sold_to = ? AND lk.sold_at IS NOT NULL
                                  ORDER BY lk.sold_at DESC");
            $stmt->execute([$userId]);
            $purchasedKeys = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = $e->getMessage();
        }
    }
}

// Auto payment flow is handled by pay.php + payment-success.php / payment-webhook.php
// (Legacy manual UPI order submission removed in favor of auto gateway.)

// Get filter parameters
$modId = $_GET['mod_id'] ?? '';

// Auto payment gateway is now used instead of manual UPI QR.
$adminUpiId = '';

// Get all mods for filter dropdown (active + under_maintenance are visible; inactive hidden)
$stmt = $pdo->query("SELECT * FROM mods WHERE status IN ('active', 'under_maintenance') ORDER BY name");
$mods = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get available plans (active + stock_out are visible; inactive hidden)
$where = "p.status IN ('active', 'stock_out')";
$params = [];

if ($modId) {
    $where .= " AND p.mod_id = ?";
    $params[] = $modId;
}

$sql = "SELECT p.*, m.name as mod_name, m.image_url, m.video_url, m.provider_pid, m.requires_device_id, m.features, m.description, m.btn1_label, m.btn1_link, m.btn2_label, m.btn2_link, m.status as mod_status
        FROM mod_plans p
        LEFT JOIN mods m ON p.mod_id = m.id
        WHERE $where
        ORDER BY m.name ASC, p.price ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$planRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Group plans by mod (product) so each product shows ONE card with all its plans inline
$groupedProducts = [];
foreach ($planRows as $row) {
    $modId = $row['mod_id'];
    if (!isset($groupedProducts[$modId])) {
        // Seed product-level info from the first plan row of this mod
        $groupedProducts[$modId] = [
            'mod_id'            => $modId,
            'mod_name'          => $row['mod_name'],
            'image_url'         => $row['image_url'],
            'video_url'         => $row['video_url'],
            'provider_pid'      => $row['provider_pid'],
            'requires_device_id'=> $row['requires_device_id'],
            'features'          => $row['features'],
            'description'       => $row['description'],
            'btn1_label'        => $row['btn1_label'],
            'btn1_link'         => $row['btn1_link'],
            'btn2_label'        => $row['btn2_label'],
            'btn2_link'         => $row['btn2_link'],
            'plans'             => [],
        ];
    }
    $groupedProducts[$modId]['plans'][] = $row;
}
// Re-index to a simple numeric array
$groupedProducts = array_values($groupedProducts);

// Keep a flat count for the "Plans" badge
$availablePlans = $planRows;

// Get user's purchased keys (only for logged-in users; guests see none)
$purchasedKeys = [];
if ($isLoggedIn) {
    $stmt = $pdo->prepare("SELECT lk.*, m.name as mod_name, m.image_url, m.video_url
                          FROM license_keys lk
                          LEFT JOIN mods m ON lk.mod_id = m.id
                          WHERE lk.sold_to = ? AND lk.sold_at IS NOT NULL
                          ORDER BY lk.sold_at DESC");
    $stmt->execute([$userId]);
    $purchasedKeys = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// formatCurrency() & formatDate() are provided by includes/functions.php

// ---- SEO (consumed by includes/seo.php via includes/header.php) ----
$page_meta = [
    'title'       => 'SG Panel | Free Fire Panel & FF Key Store - Buy FF Panel Keys',
    'description' => 'Buy Free Fire panel keys instantly at SG Panel. FF Multi Panel Key Manager with auto key delivery, secure wallet, FF Reseller API. Browse FF mods, prices, previews and demo videos.',
    'keywords'    => 'Free Fire Panel, SG Panel, FF Multi Panel Key Manager, Free Fire Key Store, Free Fire Panel Store, Buy FF Panel Keys, FF Reseller API, FF Panel Reseller API, Free Fire License Key, SG Panel Login, SG Panel Register, FF Panel Key, Free Fire Mod Panel',
    'canonical'   => 'https://keysmaster.shop/keys',
    'robots'      => 'index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1',
    'schema'      => 'store'
];
$page_title = "Store - " . SITE_NAME . " " . SITE_SUBTITLE;
require_once 'includes/header.php';
?>

<!-- ===== CURRENT BALANCE BOX - LARGE SIZE WITH BIG BOLD TEXT ===== -->
<?php if ($isLoggedIn): ?>
<div class="relative rounded-2xl px-6 py-5 mb-5 bg-gradient-to-br from-brand-surface via-brand-surface to-cyan-500/5 border-2 border-cyan-400/70 shadow-[0_0_25px_rgba(34,211,238,0.2)] hover:shadow-[0_0_35px_rgba(34,211,238,0.3)] transition-all duration-300">
    <div class="flex items-center justify-between">
        <span class="text-lg font-bold text-brand-text tracking-wide">Current Balance</span>
        <span class="text-lg font-bold text-cyan-400 tracking-wide">₹<?php echo number_format((float)$user['balance'], 2); ?></span>
    </div>
</div>
<?php endif; ?>

<!-- ===== HEADER BOX WITH ENHANCED LIGHT BLUE BORDER ===== -->
<div class="relative rounded-2xl p-5 bg-gradient-to-br from-brand-surface via-brand-surface to-cyan-500/5 border-2 border-cyan-400 shadow-[0_0_25px_rgba(34,211,238,0.25)] hover:shadow-[0_0_40px_rgba(34,211,238,0.35)] transition-all duration-300 mb-8">
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
        <div class="min-w-0">
            <h2 class="text-xl font-bold tracking-tight text-glow-primary">SELECT YOUR MOD</h2>
        </div>
        
        <!-- Filter with cyan border -->
        <form method="GET" class="flex items-center space-x-3 w-full md:w-auto shrink-0">
            <select name="mod_id" onchange="this.form.submit()" class="px-4 py-2 bg-brand-surface border-2 border-cyan-400/50 rounded-xl text-sm focus:outline-none focus:border-cyan-400 focus:shadow-[0_0_20px_rgba(34,211,238,0.2)] w-full md:w-56 max-w-full transition-all duration-300">
                <option value="">All Applications</option>
                <?php foreach ($mods as $mod): ?>
                    <option value="<?php echo $mod['id']; ?>" <?php echo $modId == $mod['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($mod['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>
</div>

<!-- Alerts -->
<?php if ($error): ?>
    <div class="bg-brand-error/10 border border-brand-error/20 text-brand-error text-xs rounded-xl p-3.5 mb-6 flex items-center space-x-2.5">
        <i class="fa-solid fa-circle-exclamation text-base"></i>
        <span><?php echo htmlspecialchars($error); ?></span>
    </div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="bg-brand-success/10 border border-brand-success/20 text-brand-success text-xs rounded-xl p-3.5 mb-6">
        <div class="flex items-center space-x-2.5 mb-0">
            <i class="fa-solid fa-circle-check text-base shrink-0"></i>
            <span><?php echo htmlspecialchars($success); ?></span>
        </div>

        <?php if (!empty($purchasedKey)): ?>
            <!-- Dedicated key box -->
            <div class="mt-3 bg-brand-bg/60 border-2 border-cyan-400/50 rounded-xl p-3 shadow-[0_0_15px_rgba(34,211,238,0.15)]">
                <div class="flex items-center justify-between mb-2">
                    <span class="text-[10px] font-bold uppercase tracking-wider text-cyan-400"><i class="fa-solid fa-key mr-1"></i>Your License Key</span>
                </div>
                <div class="flex items-start gap-2">
                    <span class="font-mono text-xs text-brand-text flex-1 break-all leading-relaxed select-all" id="purchasedKeyValue"><?php echo htmlspecialchars($purchasedKey); ?></span>
                    <button type="button" onclick="copyPurchasedKey(this)" class="shrink-0 p-2 rounded-lg bg-cyan-500/15 border-2 border-cyan-400/50 text-cyan-400 hover:bg-cyan-500/25 active:scale-95 transition-all" title="Copy Key">
                        <i class="fa-regular fa-copy"></i>
                    </button>
                </div>
                <p class="text-[10px] text-cyan-400/70 mt-2" id="keyCopyHint"><i class="fa-solid fa-circle-info mr-1"></i>Key auto-copied to clipboard.</p>
            </div>
            <!-- Auto-copy the key to clipboard immediately after purchase -->
            <script>
            (function(){
                var keyText = <?php echo json_encode($purchasedKey); ?>;
                function doCopy(t){
                    if (navigator.clipboard && navigator.clipboard.writeText) {
                        navigator.clipboard.writeText(t).then(function(){}, function(){
                            fallbackCopy(t);
                        });
                    } else {
                        fallbackCopy(t);
                    }
                }
                function fallbackCopy(t){
                    try {
                        var ta = document.createElement('textarea');
                        ta.value = t;
                        ta.setAttribute('readonly','');
                        ta.style.position = 'fixed';
                        ta.style.top = '-9999px';
                        document.body.appendChild(ta);
                        ta.select();
                        document.execCommand('copy');
                        document.body.removeChild(ta);
                    } catch(e){}
                }
                if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', function(){ doCopy(keyText); });
                } else {
                    doCopy(keyText);
                }
            })();
            function copyPurchasedKey(btn){
                var keyEl = document.getElementById('purchasedKeyValue');
                var t = keyEl ? keyEl.textContent : '';
                function done(){
                    var icon = btn.querySelector('i');
                    if (icon) icon.className = 'fa-solid fa-check';
                    var hint = document.getElementById('keyCopyHint');
                    if (hint) hint.textContent = 'Copied! Key saved to clipboard.';
                    setTimeout(function(){
                        if (icon) icon.className = 'fa-regular fa-copy';
                        if (hint) hint.innerHTML = '<i class="fa-solid fa-circle-info mr-1"></i>Key auto-copied to clipboard.';
                    }, 2000);
                }
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(t).then(done, function(){
                        var ta = document.createElement('textarea');
                        ta.value = t; ta.style.position='fixed'; ta.style.top='-9999px';
                        document.body.appendChild(ta); ta.select(); document.execCommand('copy');
                        document.body.removeChild(ta); done();
                    });
                } else {
                    var ta = document.createElement('textarea');
                    ta.value = t; ta.style.position='fixed'; ta.style.top='-9999px';
                    document.body.appendChild(ta); ta.select(); document.execCommand('copy');
                    document.body.removeChild(ta); done();
                }
            }
            </script>
        <?php endif; ?>
    </div>
<?php endif; ?>

<!-- Available Plans -->
<div class="mb-12">
    <div class="flex items-center justify-between mb-6">
        <h3 class="text-lg font-bold text-brand-text flex items-center">
            <i class="fa-solid fa-store text-brand-primary mr-2"></i>
            Available Plans
        </h3>
        <span class="text-xs font-bold text-cyan-400 bg-cyan-500/10 px-3 py-1 rounded-full border-2 border-cyan-400/50 shadow-[0_0_15px_rgba(34,211,238,0.15)]">
            <?php echo count($availablePlans); ?> Plans
        </span>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
        <?php if (empty($groupedProducts)): ?>
            <div class="col-span-full bg-brand-surface border-2 border-cyan-400/40 rounded-2xl p-12 text-center shadow-[0_0_20px_rgba(34,211,238,0.1)]">
                <i class="fa-solid fa-box-open text-4xl mb-3 text-cyan-400/50 block"></i>
                <h4 class="text-base font-bold text-brand-text mb-1">No Products Available</h4>
                <p class="text-xs text-brand-muted max-w-sm mx-auto">There are currently no products with active plans available for purchase in the store.</p>
            </div>
        <?php else: ?>
            <?php foreach ($groupedProducts as $product): ?>
                <!-- ===== MOD CARD WITH ENHANCED LIGHT BLUE BORDER - REDUCED BOTTOM SPACING ===== -->
                <div class="relative bg-gradient-to-br from-brand-surface via-brand-surface to-cyan-500/5 border-2 border-cyan-400/70 rounded-2xl overflow-hidden shadow-[0_0_20px_rgba(34,211,238,0.15)] flex flex-col group hover:shadow-[0_0_35px_rgba(34,211,238,0.35)] hover:border-cyan-400 transition-all duration-300">
                    <!-- Glow overlay -->
                    <div class="absolute inset-0 bg-gradient-to-b from-transparent via-cyan-500/5 to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-300 pointer-events-none"></div>
                    
                    <!-- Card Header - ENLARGED VIDEO/IMAGE BOX -->
                    <div class="h-64 bg-brand-bg/80 relative flex items-center justify-center overflow-hidden border-b-2 border-cyan-400/20">
                        <?php if (!empty($product['video_url'])): ?>
                            <?php
                            // SSL FIX: force the stored video URL onto HTTPS so the
                            // page never loads an http:// resource over an https:// page
                            // (which triggers Chrome's "Connection is not fully secure"
                            // mixed-content warning). Works even if the DB still holds
                            // an http:// value from older seed/import data.
                            $safeVideoUrl = preg_replace('#^http://#i', 'https://', $product['video_url']);
                            ?>
                            <video controls preload="metadata" class="w-full h-full object-cover bg-black">
                                <source src="<?php echo htmlspecialchars($safeVideoUrl); ?>" type="video/mp4">
                                Your browser does not support the video tag.
                            </video>
                            <div class="absolute top-3 left-3 px-2 py-0.5 bg-black/60 text-white text-[10px] font-bold rounded-md backdrop-blur-sm flex items-center gap-1 pointer-events-none border border-cyan-400/50 shadow-[0_0_10px_rgba(34,211,238,0.2)]">
                                <i class="fa-solid fa-play text-cyan-400"></i> Demo
                            </div>
                        <?php elseif ($product['image_url'] && file_exists($product['image_url'])): ?>
                            <?php
                            // SSL FIX: relative paths are served by this site over the
                            // page's own protocol, so they are safe. Only absolute http://
                            // URLs are a mixed-content risk — upgrade those to https://.
                            $rawImg = $product['image_url'];
                            $safeImgUrl = (strpos($rawImg, 'http://') === 0 || strpos($rawImg, 'https://') === 0)
                                ? preg_replace('#^http://#i', 'https://', $rawImg)
                                : $rawImg;
                            ?>
                            <img src="<?php echo htmlspecialchars($safeImgUrl); ?>" alt="<?php echo htmlspecialchars($product['mod_name']); ?>" class="w-full h-full object-contain filter drop-shadow-2xl group-hover:scale-110 transition-transform duration-500">
                        <?php else: ?>
                            <i class="fa-solid fa-gamepad text-5xl text-brand-muted/30 group-hover:text-cyan-400/40 transition-colors"></i>
                        <?php endif; ?>
                    </div>

                    <!-- Card Body - WITH REDUCED BOTTOM PADDING -->
                    <div class="p-5 pb-3 flex-1 flex flex-col relative z-10">
                        <!-- Product name -->
                        <div class="mb-2">
                            <h4 class="text-base font-bold text-brand-text group-hover:text-cyan-400 transition-colors text-center"><?php echo htmlspecialchars($product['mod_name']); ?></h4>
                        </div>

                        <?php if (!empty($product['description'])): ?>
                        <div class="mb-3 mod-description text-xs text-brand-muted leading-relaxed text-center">
                            <?php echo $product['description']; ?>
                        </div>
                        <?php endif; ?>

                        <?php
                        $showBtn1 = !empty($product['btn1_link']);
                        $showBtn2 = !empty($product['btn2_link']);
                        if ($showBtn1 || $showBtn2):
                        ?>
                        <div class="mb-3 grid grid-cols-2 gap-2.5">
                            <?php if ($showBtn1):
                                $b1label = !empty($product['btn1_label']) ? $product['btn1_label'] : 'CHECK UPDATE FILE';
                            ?>
                            <a href="<?php echo htmlspecialchars($product['btn1_link']); ?>" target="_blank" rel="noopener noreferrer"
                               class="flex items-center justify-center gap-1.5 h-10 px-2 bg-brand-secondary/10 border border-brand-secondary/30 text-brand-secondary text-[11px] font-bold rounded-xl hover:bg-brand-secondary/20 active:scale-95 transition-all text-center whitespace-nowrap overflow-hidden">
                                <i class="fa-solid fa-file-arrow-down text-[11px]"></i>
                                <span class="truncate"><?php echo htmlspecialchars($b1label); ?></span>
                            </a>
                            <?php endif; ?>
                            <?php if ($showBtn2):
                                $b2label = !empty($product['btn2_label']) ? $product['btn2_label'] : 'CHECK VIDEO / FEEDBACK';
                            ?>
                            <a href="<?php echo htmlspecialchars($product['btn2_link']); ?>" target="_blank" rel="noopener noreferrer"
                               class="flex items-center justify-center gap-1.5 h-10 px-2 bg-brand-secondary/10 border border-brand-secondary/30 text-brand-secondary text-[11px] font-bold rounded-xl hover:bg-brand-secondary/20 active:scale-95 transition-all text-center whitespace-nowrap overflow-hidden">
                                <i class="fa-solid fa-play text-[11px]"></i>
                                <span class="truncate"><?php echo htmlspecialchars($b2label); ?></span>
                            </a>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>

                        <!-- Purchase Key toggle button - SLIM, WIDE, WITH PROPER MARGIN -->
                        <?php $priceListId = 'priceList_' . (int)$product['mod_id']; ?>
                        <?php $modUnderMaintenance = (isset($product['mod_status']) && $product['mod_status'] === 'under_maintenance'); ?>
                        <div class="mb-3 flex justify-center mt-auto">
                            <?php if ($modUnderMaintenance): ?>
                            <button type="button" disabled class="h-8 px-12 bg-gray-500/60 text-white text-xs font-bold rounded-xl cursor-not-allowed flex items-center justify-center gap-2 whitespace-nowrap w-full max-w-[220px] opacity-70">
                                <i class="fa-solid fa-screwdriver-wrench"></i>
                                <span>UNDER MAINTENANCE</span>
                            </button>
                            <?php else: ?>
                            <button type="button" onclick="togglePriceList('<?php echo $priceListId; ?>', this)" class="h-8 px-12 bg-brand-primary text-white text-xs font-bold rounded-xl hover:opacity-90 active:scale-95 transition-all flex items-center justify-center gap-2 shadow-glow whitespace-nowrap w-full max-w-[220px]">
                                <span>PURCHASE KEY</span>
                            </button>
                            <?php endif; ?>
                        </div>

                        <!-- All plans inline -->
                        <div id="<?php echo $priceListId; ?>" class="space-y-2.5 pt-3 border-t-2 border-cyan-400/20" style="display:none">
                            <?php foreach ($product['plans'] as $plan): ?>
                                <?php
                                // Resellers see & pay their reseller price; normal users see the normal price
                                $displayPrice = $isReseller ? (float)getEffectivePrice($plan, true) : (float)$plan['price'];
                                $planForModal = $plan;
                                $planForModal['price'] = $displayPrice; // modal/JS uses plan.price
                                ?>
                                <div class="flex items-center justify-between gap-2 py-1 hover:bg-cyan-500/5 rounded-lg px-2 transition-colors">
                                    <span class="px-2.5 py-1 bg-brand-primary/10 border border-brand-primary/20 text-brand-primary text-xs font-bold rounded uppercase tracking-wider whitespace-nowrap">
                                        <?php echo $plan['duration'] . ' ' . ucfirst($plan['duration_type']); ?>
                                    </span>
                                    <span class="flex-1 text-center text-lg font-extrabold text-glow-primary whitespace-nowrap"><?php echo formatCurrency($displayPrice); ?></span>
                                    <?php $planStockOut = (isset($plan['status']) && $plan['status'] === 'stock_out'); ?>
                                    <?php if ($planStockOut): ?>
                                    <button type="button" disabled class="h-9 px-4 bg-gray-500/60 text-white text-[11px] font-bold rounded-xl cursor-not-allowed flex items-center justify-center gap-1.5 whitespace-nowrap opacity-70">
                                        <i class="fa-solid fa-ban text-[10px]"></i>
                                        <span>Stock Out</span>
                                    </button>
                                    <?php elseif ($isLoggedIn): ?>
                                    <button type="button" onclick="openPurchaseModal(<?php echo htmlspecialchars(json_encode($planForModal)); ?>)" class="h-9 px-4 bg-brand-primary text-white text-[11px] font-bold rounded-xl hover:opacity-90 active:scale-95 transition-all flex items-center justify-center gap-1.5 shadow-glow whitespace-nowrap">
                                        <i class="fa-solid fa-cart-shopping text-[10px]"></i>
                                        <span>Buy Now</span>
                                    </button>
                                    <?php else: ?>
                                    <a href="login.php" class="h-9 px-4 bg-brand-primary text-white text-[11px] font-bold rounded-xl hover:opacity-90 active:scale-95 transition-all flex items-center justify-center gap-1.5 shadow-glow whitespace-nowrap">
                                        <i class="fa-solid fa-cart-shopping text-[10px]"></i>
                                        <span>Buy Now</span>
                                    </a>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<script>
function togglePriceList(id, btn) {
    var el = document.getElementById(id);
    if (!el) return;
    var isHidden = (el.style.display === 'none' || el.style.display === '');
    el.style.display = isHidden ? 'block' : 'none';
    if (btn) {
        var span = btn.querySelector('span');
        if (span) span.textContent = isHidden ? 'PURCHASE KEY' : 'PURCHASE KEY';
    }
}
</script>

<!-- Purchase Modal (logged-in users only) -->
<?php if ($isLoggedIn): ?>
<div id="purchaseModal" class="fixed inset-0 z-50 items-center justify-center bg-black/70 backdrop-blur-sm px-4" style="display:none">
    <div class="bg-brand-surface border-2 border-cyan-400/70 rounded-2xl w-full max-w-md p-6 shadow-[0_0_40px_rgba(34,211,238,0.25)] relative animate-fade-in mx-auto">
        <button type="button" onclick="closePurchaseModal()" class="absolute top-4 right-4 text-brand-muted hover:text-brand-text transition-colors w-8 h-8 flex items-center justify-center rounded-lg hover:bg-brand-bg border border-cyan-400/20">
            <i class="fa-solid fa-xmark text-lg"></i>
        </button>
        
        <div class="text-center mb-6">
            <div class="w-14 h-14 mx-auto rounded-2xl bg-cyan-500/10 border-2 border-cyan-400/50 flex items-center justify-center text-cyan-400 mb-3 shadow-[0_0_20px_rgba(34,211,238,0.15)]">
                <i class="fa-solid fa-bolt text-2xl"></i>
            </div>
            <h3 class="text-xl font-bold text-glow-primary mb-2">Complete Purchase</h3>
            <p class="text-sm text-brand-muted">Review your order and pay securely via the auto payment gateway.</p>
        </div>

        <div class="bg-brand-bg/50 border-2 border-cyan-400/30 rounded-xl p-4 mb-4 shadow-[0_0_15px_rgba(34,211,238,0.1)]">
            <div class="flex justify-between items-center mb-2">
                <span class="text-xs font-bold text-brand-muted">Plan:</span>
                <span class="text-sm font-bold text-brand-text" id="modalPlanName">-</span>
            </div>
            <div class="flex justify-between items-center mb-2">
                <span class="text-xs font-bold text-brand-muted">Duration:</span>
                <span class="text-sm font-bold text-cyan-400" id="modalPlanDuration">-</span>
            </div>
            <div class="flex justify-between items-center mb-2">
                <span class="text-xs font-bold text-brand-muted">Amount to Pay:</span>
                <span class="text-lg font-bold text-brand-success" id="modalPlanPrice">-</span>
            </div>
            <div class="flex justify-between items-center pt-2 border-t-2 border-cyan-400/20">
                <span class="text-xs font-bold text-brand-muted">Wallet Balance:</span>
                <span class="text-sm font-bold text-cyan-400" id="modalWalletBalance"><?php echo formatCurrency($user['balance']); ?></span>
            </div>
        </div>

        <!-- Device ID Input -->
        <div id="deviceIdWrapper" class="mb-4">
            <label for="deviceIdInput" class="block text-xs font-bold text-brand-muted mb-2">
                <i class="fa-solid fa-mobile-screen-button mr-1"></i>
                Device ID (Android ID) <span id="deviceIdRequiredStar" class="text-brand-error">*</span>
            </label>
            <input type="text" id="deviceIdInput" name="device_id" placeholder="e.g. a1b2c3d4e5f67890" class="w-full h-11 px-4 bg-brand-bg border-2 border-cyan-400/30 rounded-xl text-sm text-brand-text focus:outline-none focus:border-cyan-400 focus:shadow-[0_0_20px_rgba(34,211,238,0.15)] transition-all" autocomplete="off">
            <p id="deviceIdHint" class="text-[10px] text-brand-muted mt-1.5">Enter your Android Device ID. This is required to bind the key to your device.</p>
        </div>

        <!-- Pay from Wallet -->
        <form method="POST" action="keys" id="walletPurchaseForm" class="mb-3">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="plan_id" id="modalPlanIdWallet" value="">
            <input type="hidden" name="buy_from_wallet" value="1">
            <input type="hidden" name="device_id" id="walletDeviceId" value="">
            <button type="submit" id="walletPayBtn" class="w-full h-12 bg-gradient-to-r from-brand-success to-brand-secondary text-white text-sm font-bold rounded-xl hover:opacity-90 active:scale-[0.98] transition-all flex items-center justify-center gap-2 shadow-glow disabled:opacity-50 disabled:cursor-not-allowed">
                <i class="fa-solid fa-wallet"></i>
                <span>Pay from Wallet</span>
            </button>
            <p class="text-[10px] text-brand-muted text-center mt-2" id="walletPayHint">Pay instantly using your wallet balance.</p>
        </form>

        <!-- Gateway section -->
        <?php if ($pgEnabled): ?>
        <div id="gatewaySectionWrapper" style="display:none">
            <div class="flex items-center gap-3 mb-3">
                <div class="flex-1 h-px bg-cyan-400/20"></div>
                <span class="text-[10px] text-brand-muted font-bold uppercase">or</span>
                <div class="flex-1 h-px bg-cyan-400/20"></div>
            </div>

            <form method="POST" action="pay" id="purchaseForm">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="plan_id" id="modalPlanId" value="">
                <input type="hidden" name="device_id" id="gatewayDeviceId" value="">
                <div class="mb-3">
                    <label for="gatewayMobile" class="block text-xs font-bold text-brand-muted mb-2">
                        <i class="fa-solid fa-mobile-screen-button mr-1"></i>
                        Mobile Number
                    </label>
                    <input type="tel" id="gatewayMobile" name="mobile_number" required pattern="[0-9+\s\-]{10,15}" maxlength="15"
                           value="<?php echo isset($_SESSION['recharge_mobile']) ? htmlspecialchars($_SESSION['recharge_mobile']) : ''; ?>"
                           class="w-full h-11 px-4 bg-brand-bg border-2 border-cyan-400/30 rounded-xl text-sm text-brand-text focus:outline-none focus:border-cyan-400 focus:shadow-[0_0_20px_rgba(34,211,238,0.15)] transition-all"
                           placeholder="Your mobile number">
                </div>
                <button type="submit" name="pay_now" class="w-full h-12 bg-brand-surface border-2 border-cyan-400/50 text-cyan-400 text-sm font-bold rounded-xl hover:bg-cyan-500/10 active:scale-[0.98] transition-all flex items-center justify-center gap-2 shadow-[0_0_20px_rgba(34,211,238,0.15)]">
                    <i class="fa-solid fa-credit-card"></i>
                    <span>Pay via Payment Gateway</span>
                </button>
                <p class="text-[10px] text-brand-muted text-center mt-3">Pay securely online. Your key is delivered instantly after a successful payment.</p>
            </form>
        </div>
        <?php else: ?>
            <div class="text-center text-brand-warning bg-brand-warning/10 border-2 border-yellow-500/30 p-4 rounded-xl mb-2">
                <i class="fa-solid fa-triangle-exclamation mb-2 text-xl"></i>
                <p class="text-sm font-bold">Gateway payment is not enabled.</p>
                <p class="text-xs mt-1">Use wallet balance or contact admin.</p>
            </div>
        <?php endif; ?>

        <a href="wallet" class="block text-center text-xs text-cyan-400 hover:text-cyan-300 mt-3">Recharge your wallet &rarr;</a>
    </div>
</div>

<script>
function openPurchaseModal(plan) {
    document.getElementById('modalPlanId').value = plan.id;
    document.getElementById('modalPlanIdWallet').value = plan.id;
    document.getElementById('modalPlanName').textContent = plan.mod_name;
    document.getElementById('modalPlanDuration').textContent = plan.duration + ' ' + (plan.duration_type.charAt(0).toUpperCase() + plan.duration_type.slice(1));
    document.getElementById('modalPlanPrice').innerHTML = '&#8377;' + parseFloat(plan.price).toFixed(2);

    // Device ID handling
    var requiresDevice = parseInt(plan.requires_device_id) === 1;
    var deviceIdWrapper = document.getElementById('deviceIdWrapper');
    var deviceIdInput = document.getElementById('deviceIdInput');
    var deviceIdRequiredStar = document.getElementById('deviceIdRequiredStar');
    var deviceIdHint = document.getElementById('deviceIdHint');
    deviceIdInput.value = '';
    document.getElementById('walletDeviceId').value = '';
    document.getElementById('gatewayDeviceId').value = '';

    if (requiresDevice) {
        deviceIdWrapper.style.display = 'block';
        deviceIdInput.required = true;
        deviceIdRequiredStar.style.display = 'inline';
        deviceIdHint.textContent = 'This product is device-bound. You MUST enter your Android Device ID \u2014 the key will only work on this device.';
        deviceIdHint.classList.remove('text-brand-muted');
        deviceIdHint.classList.add('text-brand-warning');
    } else {
        deviceIdWrapper.style.display = 'none';
        deviceIdInput.required = false;
        deviceIdInput.value = '';
        document.getElementById('walletDeviceId').value = '';
        document.getElementById('gatewayDeviceId').value = '';
    }

    deviceIdInput.oninput = function() {
        document.getElementById('walletDeviceId').value = this.value.trim();
        document.getElementById('gatewayDeviceId').value = this.value.trim();
    };

    var walletForm = document.getElementById('walletPurchaseForm');
    walletForm.onsubmit = function(e) {
        var devId = document.getElementById('deviceIdInput').value.trim();
        if (requiresDevice && devId === '') {
            e.preventDefault();
            alert('Device ID is required for this product. Please enter your Android Device ID.');
            deviceIdInput.focus();
            return false;
        }
        document.getElementById('walletDeviceId').value = devId;
    };

    var gatewayForm = document.getElementById('purchaseForm');
    if (gatewayForm) {
        gatewayForm.onsubmit = function(e) {
            var devId = document.getElementById('deviceIdInput').value.trim();
            if (requiresDevice && devId === '') {
                e.preventDefault();
                alert('Device ID is required for this product. Please enter your Android Device ID.');
                deviceIdInput.focus();
                return false;
            }
            document.getElementById('gatewayDeviceId').value = devId;
        };
    }

    var walletBalance = <?php echo (float)$user['balance']; ?>;
    var planPrice = parseFloat(plan.price);
    var walletBtn = document.getElementById('walletPayBtn');
    var walletHint = document.getElementById('walletPayHint');
    var gatewaySection = document.getElementById('gatewaySectionWrapper');
    if (walletBalance >= planPrice) {
        walletBtn.disabled = false;
        walletHint.textContent = 'Pay instantly using your wallet balance. ₹' + planPrice.toFixed(2) + ' will be debited.';
        walletHint.classList.remove('text-brand-error');
        walletHint.classList.add('text-brand-muted');
        if (gatewaySection) gatewaySection.style.display = 'none';
    } else {
        walletBtn.disabled = true;
        var shortfall = (planPrice - walletBalance).toFixed(2);
        walletHint.textContent = 'Insufficient balance. Short by ₹' + shortfall + '. Please recharge your wallet.';
        walletHint.classList.remove('text-brand-muted');
        walletHint.classList.add('text-brand-error');
        if (gatewaySection) gatewaySection.style.display = 'block';
    }

    const modal = document.getElementById('purchaseModal');
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function closePurchaseModal() {
    const modal = document.getElementById('purchaseModal');
    modal.style.display = 'none';
    document.body.style.overflow = '';
}

document.getElementById('purchaseModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closePurchaseModal();
    }
});

function copyToClipboard(text, btn) {
    navigator.clipboard.writeText(text).then(() => {
        const icon = btn.querySelector('i');
        icon.className = 'fa-solid fa-check text-brand-success';
        setTimeout(() => {
            icon.className = 'fa-regular fa-copy';
        }, 2000);
    });
}
</script>
<?php endif; ?>

<?php
require_once 'includes/footer.php';
?>