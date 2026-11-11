<?php
/**
 * test-payment.php - Diagnostic page to verify payment gateway integration.
 *
 * This page helps you verify that:
 *   1. The payment gateway URL is correct
 *   2. The API token (user_token) is valid
 *   3. Order creation works
 *   4. Order status check works
 *
 * DELETE THIS FILE after testing on a live site!
 */
require_once 'includes/auth.php';
require_once 'includes/functions.php';
require_once 'includes/payment.php';

// Only admins can run diagnostics
if (!isLoggedIn() || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    die('Admin access required. Please <a href="login.php">login</a> as admin.');
}

$cfg = getPaymentConfig();
$results = [];

// Test 1: Configuration check
$results['config'] = [
    'gateway_url' => $cfg['gateway_url'],
    'user_token'  => $cfg['user_token'] !== ''
        ? (substr($cfg['user_token'], 0, 10) . '...' . substr($cfg['user_token'], -6))
        : '(not set)',
    'site_base'   => $cfg['site_base'] !== '' ? $cfg['site_base'] : '(auto-detect)',
    'enabled'     => $cfg['enabled'],
];

// Test 2: Gateway connectivity
$gateway = rtrim($cfg['gateway_url'], '/');
$ch = curl_init($gateway);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_NOBODY, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
curl_exec($ch);
$gwCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$gwErr = curl_error($ch);
curl_close($ch);
$results['connectivity'] = [
    'url' => $gateway,
    'http_code' => $gwCode,
    'error' => $gwErr ?: null,
    'ok' => $gwCode > 0 && $gwCode < 400,
];

// Test 3: API token validation (try creating a test order)
$testOrderId = generateGatewayOrderId(0);
$testResult = createPaymentOrder(
    1.00,
    'TEST - Diagnostic check (ignore)',
    paymentSiteBase() . '/payment-success.php?oid=' . urlencode($testOrderId),
    '9999999999',
    $testOrderId,
    'TEST - Diagnostic check (ignore)',
    ''
);
$results['api_token'] = [
    'ok' => !empty($testResult['success']),
    'order_id' => $testOrderId,
    'response' => $testResult,
];

// Test 4: Check if the test order can be verified
if (!empty($testResult['success'])) {
    $verifyResult = checkPaymentStatus($testOrderId);
    $results['verify'] = [
        'order_id' => $testOrderId,
        'ok' => !empty($verifyResult['success']),
        'response' => $verifyResult,
    ];
}

// Test 5: Redirect / callback URL reachability (self-test via POST)
$webhookUrl = paymentSiteBase() . '/payment-webhook.php';
$results['webhook_url'] = $webhookUrl;

// Actually POST a test callback to verify the endpoint accepts POST
// (a 301 redirect or 405 would indicate .htaccess or server config issue)
$ch = curl_init($webhookUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['order_id' => 'DIAG_TEST_' . time()]));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
curl_setopt($ch, CURLOPT_HEADER, true);
curl_setopt($ch, CURLOPT_NOBODY, false);
$whResp = curl_exec($ch);
$whCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$whErr = curl_error($ch);
curl_close($ch);
$results['webhook_post_test'] = [
    'http_code' => $whCode,
    'error' => $whErr ?: null,
    'ok' => $whCode === 200,
    'redirected' => $whCode === 301 || $whCode === 302,
];

// Test 6: payment_orders table check
ensurePaymentOrdersTable();
try {
    $pdo = getDBConnection();
    $stmt = $pdo->query("SELECT COUNT(*) as cnt FROM payment_orders");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $results['payment_orders_table'] = [
        'exists' => true,
        'total_orders' => $row['cnt'],
    ];

    // Count pending vs paid
    $stmt = $pdo->query("SELECT status, COUNT(*) as cnt FROM payment_orders GROUP BY status");
    $statusCounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $results['payment_orders_table']['by_status'] = $statusCounts;
} catch (Exception $e) {
    $results['payment_orders_table'] = [
        'exists' => false,
        'error' => $e->getMessage(),
    ];
}

$page_title = "Payment Diagnostics - " . SITE_NAME . " " . SITE_SUBTITLE;
require_once 'includes/header.php';
?>

<div class="max-w-3xl mx-auto mt-8">
    <div class="bg-brand-surface border border-brand-border rounded-2xl p-8 shadow-sm">
        <h2 class="text-2xl font-bold text-brand-text mb-2">
            <i class="fa-solid fa-stethoscope text-brand-primary mr-2"></i>Payment Gateway Diagnostics
        </h2>
        <p class="text-sm text-brand-muted mb-6">
            This page tests your payment gateway integration. If any test fails, that's where the problem is.
            <strong class="text-brand-warning">⚠️ Delete this file (test-payment.php) after testing!</strong>
        </p>

        <!-- Test 1: Configuration -->
        <div class="mb-6 p-4 bg-brand-bg/60 border border-brand-border rounded-xl">
            <h3 class="text-sm font-bold text-brand-text mb-3">
                <i class="fa-solid fa-cog text-brand-muted mr-2"></i>1. Configuration
            </h3>
            <div class="space-y-1 text-xs font-mono text-brand-muted">
                <div>Gateway URL: <span class="text-brand-text"><?php echo htmlspecialchars($results['config']['gateway_url']); ?></span></div>
                <div>API Token: <span class="text-brand-text"><?php echo htmlspecialchars($results['config']['user_token']); ?></span></div>
                <div>Site URL: <span class="text-brand-text"><?php echo htmlspecialchars($results['config']['site_base']); ?></span></div>
                <div>Enabled: <span class="text-brand-text"><?php echo htmlspecialchars($results['config']['enabled']); ?></span></div>
            </div>
            <?php if ($results['config']['gateway_url'] !== 'https://sgvip.site'): ?>
                <div class="mt-2 text-xs text-brand-warning">
                    <i class="fa-solid fa-triangle-exclamation mr-1"></i>Gateway URL is normally <code>https://sgvip.site</code>.
                </div>
            <?php endif; ?>
        </div>

        <!-- Test 2: Connectivity -->
        <div class="mb-6 p-4 bg-brand-bg/60 border border-brand-border rounded-xl">
            <h3 class="text-sm font-bold text-brand-text mb-3">
                <i class="fa-solid fa-wifi text-brand-muted mr-2"></i>2. Gateway Connectivity
            </h3>
            <?php if ($results['connectivity']['ok']): ?>
                <div class="text-sm text-brand-success">
                    <i class="fa-solid fa-circle-check mr-1"></i>Gateway is reachable (HTTP <?php echo $results['connectivity']['http_code']; ?>)
                </div>
            <?php else: ?>
                <div class="text-sm text-brand-error">
                    <i class="fa-solid fa-circle-xmark mr-1"></i>Cannot reach gateway. HTTP code: <?php echo $results['connectivity']['http_code']; ?>
                    <?php if ($results['connectivity']['error']): ?>
                        <br>Error: <?php echo htmlspecialchars($results['connectivity']['error']); ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Test 3: API Token -->
        <div class="mb-6 p-4 bg-brand-bg/60 border border-brand-border rounded-xl">
            <h3 class="text-sm font-bold text-brand-text mb-3">
                <i class="fa-solid fa-key text-brand-muted mr-2"></i>3. API Token Validation
            </h3>
            <?php if ($results['api_token']['ok']): ?>
                <div class="text-sm text-brand-success">
                    <i class="fa-solid fa-circle-check mr-1"></i>API token is VALID! Order creation works.
                </div>
                <div class="mt-2 text-xs font-mono text-brand-muted">
                    Order ID: <?php echo htmlspecialchars($results['api_token']['order_id']); ?><br>
                    Payment URL: <?php echo htmlspecialchars($results['api_token']['response']['payment_url'] ?? ''); ?>
                </div>
            <?php else: ?>
                <div class="text-sm text-brand-error">
                    <i class="fa-solid fa-circle-xmark mr-1"></i>API token is INVALID or order creation failed!
                </div>
                <div class="mt-2 text-xs font-mono text-brand-error bg-brand-error/10 p-2 rounded">
                    <?php echo htmlspecialchars($results['api_token']['response']['error'] ?? json_encode($results['api_token']['response'])); ?>
                </div>
                <div class="mt-3 text-xs text-brand-warning bg-brand-warning/10 p-3 rounded border border-brand-warning/20">
                    <strong>HOW TO FIX:</strong><br>
                    1. Login to the payment gateway at <a href="https://sgvip.site" target="_blank" class="text-brand-primary underline">https://sgvip.site</a><br>
                    2. Copy your API Token (user_token)<br>
                    3. Come back to this site → Settings → Payment Gateway → paste the token<br>
                    4. Set Gateway URL to <code>https://sgvip.site</code><br>
                    5. Save and re-run this test
                </div>
            <?php endif; ?>
        </div>

        <!-- Test 4: Verify -->
        <?php if (isset($results['verify'])): ?>
        <div class="mb-6 p-4 bg-brand-bg/60 border border-brand-border rounded-xl">
            <h3 class="text-sm font-bold text-brand-text mb-3">
                <i class="fa-solid fa-magnifying-glass text-brand-muted mr-2"></i>4. Order Status Check
            </h3>
            <?php if ($results['verify']['ok']): ?>
                <div class="text-sm text-brand-success">
                    <i class="fa-solid fa-circle-check mr-1"></i>Status check endpoint works! Status: <?php echo htmlspecialchars($results['verify']['response']['status'] ?? ''); ?>
                </div>
            <?php else: ?>
                <div class="text-sm text-brand-error">
                    <i class="fa-solid fa-circle-xmark mr-1"></i>Status check failed: <?php echo htmlspecialchars($results['verify']['response']['error'] ?? 'Unknown'); ?>
                </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Test 5: Callback URL -->
        <div class="mb-6 p-4 bg-brand-bg/60 border border-brand-border rounded-xl">
            <h3 class="text-sm font-bold text-brand-text mb-3">
                <i class="fa-solid fa-bell text-brand-muted mr-2"></i>5. Callback URL & POST Test
            </h3>
            <div class="text-xs font-mono text-brand-muted mb-2">
                <?php echo htmlspecialchars($results['webhook_url']); ?>
            </div>
            <?php if (!empty($results['webhook_post_test'])): ?>
                <?php $wpt = $results['webhook_post_test']; ?>
                <?php if ($wpt['ok']): ?>
                    <div class="text-sm text-brand-success">
                        <i class="fa-solid fa-circle-check mr-1"></i>Callback endpoint accepts POST (HTTP <?php echo $wpt['http_code']; ?>)
                    </div>
                <?php elseif ($wpt['redirected']): ?>
                    <div class="text-sm text-brand-error">
                        <i class="fa-solid fa-circle-xmark mr-1"></i>Callback POST gets REDIRECTED (HTTP <?php echo $wpt['http_code']; ?>) — POST body is LOST!
                    </div>
                    <div class="mt-2 text-xs text-brand-error bg-brand-error/10 p-2 rounded">
                        The .htaccess clean-URL rule is redirecting /payment-webhook.php to /payment-webhook, which strips the POST body.
                        Fix: exclude POST requests from the redirect (already fixed in the updated .htaccess).
                    </div>
                <?php elseif ($wpt['http_code'] === 405): ?>
                    <div class="text-sm text-brand-error">
                        <i class="fa-solid fa-circle-xmark mr-1"></i>Callback POST returns 405 Method Not Allowed — this domain blocks POST!
                    </div>
                    <div class="mt-2 text-xs text-brand-error bg-brand-error/10 p-2 rounded">
                        This domain does not accept POST requests. Set "Site URL" in Settings → Payment Gateway to a domain that does.
                    </div>
                <?php else: ?>
                    <div class="text-sm text-brand-error">
                        <i class="fa-solid fa-circle-xmark mr-1"></i>Callback POST failed (HTTP <?php echo $wpt['http_code']; ?>)
                        <?php if ($wpt['error']): ?> — <?php echo htmlspecialchars($wpt['error']); ?><?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
            <div class="mt-2 text-xs text-brand-muted">
                The SGVIP gateway confirms payments via the status-check API, so a callback is optional.
                This URL is kept as a defensive/manual trigger and MUST accept POST requests.
            </div>
        </div>

        <!-- Test 6: Payment Orders Table -->
        <div class="mb-6 p-4 bg-brand-bg/60 border border-brand-border rounded-xl">
            <h3 class="text-sm font-bold text-brand-text mb-3">
                <i class="fa-solid fa-database text-brand-muted mr-2"></i>6. Payment Orders Table
            </h3>
            <?php if (!empty($results['payment_orders_table']['exists'])): ?>
                <div class="text-sm text-brand-success mb-2">
                    <i class="fa-solid fa-circle-check mr-1"></i>Table exists. Total orders: <?php echo $results['payment_orders_table']['total_orders']; ?>
                </div>
                <?php if (!empty($results['payment_orders_table']['by_status'])): ?>
                    <div class="text-xs text-brand-muted">
                        Status breakdown:
                        <?php foreach ($results['payment_orders_table']['by_status'] as $sc): ?>
                            <span class="inline-block ml-2 px-2 py-0.5 bg-brand-bg rounded"><?php echo htmlspecialchars($sc['status']); ?>: <?php echo $sc['cnt']; ?></span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="text-sm text-brand-error">
                    <i class="fa-solid fa-circle-xmark mr-1"></i>Table error: <?php echo htmlspecialchars($results['payment_orders_table']['error']); ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="mt-6 p-4 bg-brand-warning/10 border border-brand-warning/20 rounded-xl">
            <p class="text-xs text-brand-warning">
                <i class="fa-solid fa-triangle-exclamation mr-1"></i>
                <strong>IMPORTANT:</strong> Delete this file (test-payment.php) from your server after testing!
                It exposes diagnostic information.
            </p>
        </div>

        <div class="mt-6 text-center">
            <a href="test-payment.php" class="inline-flex items-center justify-center px-5 py-2.5 bg-brand-primary text-white text-sm font-bold rounded-xl hover:opacity-90 transition-all space-x-2 shadow-glow">
                <i class="fa-solid fa-rotate"></i><span>Re-run Tests</span>
            </a>
        </div>
    </div>
</div>

<?php
require_once 'includes/footer.php';
?>
