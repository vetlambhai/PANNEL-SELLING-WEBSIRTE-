<?php
/**
 * wallet-recharge.php - Initiate an auto payment for wallet recharge.
 * Creates a payment link via the SG Payment gateway and redirects the user to it.
 */
require_once 'includes/auth.php';
require_once 'includes/functions.php';
require_once 'includes/payment.php';

requireLogin();

$userId = $_SESSION['user_id'];
$cfg = getPaymentConfig();
$error = '';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['amount'])) {
    header('Location: wallet');
    exit();
}

verify_csrf();

$amount = (float)$_POST['amount'];
$mobile = trim($_POST['mobile_number'] ?? '');

// Basic mobile number validation (10-digit Indian style, digits only)
$mobileClean = preg_replace('/[^0-9]/', '', $mobile);
if ($mobile !== '' && (strlen($mobileClean) < 10 || strlen($mobileClean) > 15)) {
    $error = 'Please enter a valid mobile number.';
} elseif ($amount <= 0) {
    $error = 'Please enter a valid recharge amount.';
} elseif ($cfg['enabled'] !== '1' || empty($cfg['user_token'])) {
    $error = 'Auto payment gateway is not configured. Please contact admin.';
} else {
    try {
        // Store the mobile number in session so it's available after the gateway redirect.
        if ($mobileClean !== '') {
            $_SESSION['recharge_mobile'] = $mobileClean;
        }

        // Create the order on the gateway (POST /api/create-order) and get the
        // payment URL. The order id is generated + stored locally by the helper.
        $result = startGatewayPayment($userId, $amount, $mobileClean, 'wallet', null, null);

        if (!empty($result['success']) && !empty($result['payment_url'])) {
            header('Location: ' . $result['payment_url']);
            exit();
        } else {
            $error = 'Could not create payment order: ' . htmlspecialchars($result['error'] ?? 'Unknown error');
        }
    } catch (Exception $e) {
        $error = 'Error: ' . $e->getMessage();
    }
}

$page_title = "Recharge Error - " . SITE_NAME . " " . SITE_SUBTITLE;
require_once 'includes/header.php';
?>
<div class="max-w-lg mx-auto mt-12">
    <div class="bg-brand-surface border border-brand-error/30 rounded-2xl p-8 text-center shadow-sm">
        <div class="w-16 h-16 mx-auto rounded-2xl bg-brand-error/10 border border-brand-error/20 flex items-center justify-center text-brand-error mb-5">
            <i class="fa-solid fa-triangle-exclamation text-2xl"></i>
        </div>
        <h2 class="text-xl font-bold text-brand-text mb-2">Recharge Could Not Be Started</h2>
        <p class="text-sm text-brand-muted mb-6"><?php echo $error ?: 'An unexpected error occurred. Please try again.'; ?></p>
        <a href="wallet" class="inline-flex items-center justify-center px-5 py-2.5 bg-brand-primary text-white text-sm font-bold rounded-xl hover:opacity-90 transition-all space-x-2 shadow-glow">
            <i class="fa-solid fa-arrow-left-long"></i>
            <span>Back to Wallet</span>
        </a>
    </div>
</div>
<?php
require_once 'includes/footer.php';
?>
