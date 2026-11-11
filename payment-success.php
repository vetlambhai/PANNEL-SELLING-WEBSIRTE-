<?php
/**
 * payment-success.php - Landing page after gateway payment redirect.
 * Verifies payment status from gateway and auto-fulfills (generates key / credits wallet).
 *
 * The gateway redirects back with our order id: ?oid=ORDER_ID
 * (we also accept order_id / payment_id / id for robustness).
 *
 * IMPORTANT: This page is robust against session loss. If the user's session
 * was lost during the gateway redirect (e.g. http->https protocol mismatch),
 * we can still fulfill the payment using the order id from the URL and
 * restore the session from the payment_orders table.
 */
require_once 'includes/auth.php';
require_once 'includes/functions.php';
require_once 'includes/payment.php';

ensurePaymentOrdersTable();

$paymentId = trim($_GET['oid'] ?? $_GET['order_id'] ?? $_GET['payment_id'] ?? $_GET['id'] ?? '');
$status    = trim($_GET['status'] ?? '');
$cfg = getPaymentConfig();

/* ------------------------------------------------------------------ *
 * Session-recovery logic:
 * If the user is NOT logged in but we have a valid payment_id, try to
 * recover the session from the payment_orders record. This handles the
 * case where the gateway redirected back over HTTP and the HTTPS session
 * cookie was not sent.
 * ------------------------------------------------------------------ */
if (!isLoggedIn() && !empty($paymentId)) {
    try {
        $pdo = getDBConnection();
        $stmt = $pdo->prepare("SELECT po.*, u.username, u.email, u.role, u.balance
                               FROM payment_orders po
                               JOIN users u ON po.user_id = u.id
                               WHERE po.payment_id = ? LIMIT 1");
        $stmt->execute([$paymentId]);
        $orderRow = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($orderRow) {
            // Restore the session so the user stays logged in
            $_SESSION['user_id']    = (int)$orderRow['user_id'];
            $_SESSION['username']   = $orderRow['username'];
            $_SESSION['email']      = $orderRow['email'];
            $_SESSION['role']       = $orderRow['role'];
            $_SESSION['balance']    = $orderRow['balance'];
            // Regenerate session ID for security after recovery
            session_regenerate_id(true);
        }
    } catch (Exception $e) {
        error_log("[PaymentSuccess] Session recovery error for $paymentId: " . $e->getMessage());
    }
}

// Now require login — either the original session survived or we recovered it
requireLogin();

$userId = $_SESSION['user_id'];

$page_title = "Payment Status - " . SITE_NAME . " " . SITE_SUBTITLE;
require_once 'includes/header.php';

$fulfilled = false;
$verified  = false;
$message   = '';
$isWallet  = false;
$planName  = '';
$keyValue  = '';

if (empty($paymentId)) {
    $message = 'No payment reference was received. If you completed a payment, please wait a moment and check your Wallet / Keys.';
} else {
    try {
        $pdo = getDBConnection();

        // Find our local order record (owned by this user)
        $stmt = $pdo->prepare("SELECT * FROM payment_orders WHERE payment_id = ? AND user_id = ? LIMIT 1");
        $stmt->execute([$paymentId, $userId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            // Maybe the order exists but belongs to a different user (edge case after session regen)
            // Try without user_id constraint but verify ownership
            $stmt = $pdo->prepare("SELECT * FROM payment_orders WHERE payment_id = ? LIMIT 1");
            $stmt->execute([$paymentId]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$order || (int)$order['user_id'] !== (int)$userId) {
                $order = null;
            }
        }

        if (!$order) {
            $message = 'We could not find a matching payment record for your account. If you just paid, please contact support with this ID: ' . htmlspecialchars($paymentId);
        } else {
            $isWallet = ($order['purpose'] === 'wallet');

            // If already fulfilled (e.g. webhook arrived first), show success immediately
            if ($order['status'] === 'paid' || $order['status'] === 'completed') {
                $fulfilled = true;
                $verified = true;
                if ($isWallet) {
                    $message = 'Your wallet has been recharged successfully. The amount has been added to your balance.';
                } else {
                    // Fetch the generated key
                    $stmt = $pdo->prepare("SELECT lk.*, m.name as mod_name FROM license_keys lk
                                           LEFT JOIN mods m ON lk.mod_id = m.id
                                           WHERE lk.sold_to = ? ORDER BY lk.sold_at DESC LIMIT 1");
                    $stmt->execute([$userId]);
                    $newKey = $stmt->fetch(PDO::FETCH_ASSOC);
                    $planName = $newKey ? htmlspecialchars($newKey['mod_name']) : '';
                    $keyValue = $newKey ? htmlspecialchars(displayKey($newKey)) : '';
                    $message = $keyValue
                        ? 'Payment successful! Your license key has been generated.'
                        : 'Payment successful! Your order has been completed.';
                }
            } else {
                // Query the gateway for the authoritative status.
                // IMPORTANT: Use the RETRY version which polls up to 6 times
                // (every 5s = 30s total). The gateway can take a few seconds
                // to reflect a payment after the user pays. Without retry, a
                // premature "pending" status would cause the wallet to never
                // be credited.
                $gw = checkPaymentStatusWithRetry($paymentId);

                if (!empty($gw['success']) && (strcasecmp($gw['status'], 'paid') === 0)) {
                    $utr = $gw['utr'] ?? null;
                    $fulfilled = fulfillPaymentOrder($paymentId, $utr);
                    $verified = true;

                    if ($fulfilled) {
                        if ($isWallet) {
                            $message = 'Your wallet has been recharged successfully. The amount has been added to your balance.';
                        } else {
                            // Fetch the generated key
                            $stmt = $pdo->prepare("SELECT lk.*, m.name as mod_name FROM license_keys lk
                                                   LEFT JOIN mods m ON lk.mod_id = m.id
                                                   WHERE lk.sold_to = ? ORDER BY lk.sold_at DESC LIMIT 1");
                            $stmt->execute([$userId]);
                            $newKey = $stmt->fetch(PDO::FETCH_ASSOC);
                            $planName = $newKey ? htmlspecialchars($newKey['mod_name']) : '';
                            $keyValue = $newKey ? htmlspecialchars(displayKey($newKey)) : '';
                            $message = $keyValue
                                ? 'Payment successful! Your license key has been generated.'
                                : 'Payment successful! Your order has been completed.';
                        }
                    } else {
                        $message = 'Payment was confirmed but we could not complete fulfillment. Please contact support with payment ID: ' . htmlspecialchars($paymentId);
                    }
                } elseif (!empty($gw['success']) && strcasecmp($gw['status'], 'paid') !== 0) {
                    // Not paid yet (e.g. active/pending) even after 30s of polling.
                    // The JS auto-retry on this page will keep checking.
                    $message = 'Your payment is being processed. Once confirmed, your order will be completed automatically. Current status: ' . htmlspecialchars(ucfirst($gw['status'] ?? 'pending'));
                } else {
                    // Gateway check failed - rely on local status + JS auto-retry
                    $errMsg = $gw['error'] ?? 'Gateway did not respond';
                    $message = 'We are verifying your payment. Please wait a moment — your order will be completed automatically once the payment is confirmed. (Ref: ' . htmlspecialchars($paymentId) . ')';
                    // Log the specific error for admin debugging
                    error_log("[PaymentSuccess] Gateway check failed for $paymentId: $errMsg");
                }
            }
        }
    } catch (Exception $e) {
        $message = 'A system error occurred while verifying your payment: ' . htmlspecialchars($e->getMessage());
    }
}

// Refresh the session balance so the header shows the updated wallet amount
if ($fulfilled && $isWallet) {
    try {
        $userData = getUserData($userId);
        if ($userData) {
            $_SESSION['balance'] = $userData['balance'];
        }
        // Clean up the one-time recharge mobile number now that it's used
        unset($_SESSION['recharge_mobile']);
    } catch (Exception $e) { /* ignore */ }
}

// Auto-retry flag: if the payment is NOT yet fulfilled but we DO have a valid
// pending order + order id, the page will keep polling every 5s (up to 120s)
// so the gateway gets time to mark the order COMPLETED. This makes wallet/key
// credit reliable even if the transaction is confirmed a few seconds after
// the user pays.
$autoRetry = (!$fulfilled && !empty($paymentId) && !empty($order));
?>

<div class="max-w-xl mx-auto mt-8">
    <div class="bg-brand-surface border border-brand-border rounded-2xl p-8 shadow-sm text-center">
        <?php if ($fulfilled): ?>
            <div class="w-20 h-20 mx-auto rounded-3xl bg-brand-success/10 border border-brand-success/20 flex items-center justify-center text-brand-success mb-5 shadow-glow">
                <i class="fa-solid fa-circle-check text-4xl"></i>
            </div>
            <h2 class="text-2xl font-bold text-brand-text mb-2">Payment Successful</h2>
        <?php else: ?>
            <div class="w-20 h-20 mx-auto rounded-3xl bg-brand-warning/10 border border-brand-warning/20 flex items-center justify-center text-brand-warning mb-5">
                <i class="fa-solid fa-clock text-4xl"></i>
            </div>
            <h2 class="text-2xl font-bold text-brand-text mb-2">Payment Verification</h2>
        <?php endif; ?>

        <p class="text-sm text-brand-muted mb-6 leading-relaxed"><?php echo $message; ?></p>

        <?php if ($autoRetry): ?>
        <!-- Manual re-check button: lets the user trigger an immediate status
             check instead of waiting for the next auto-poll cycle. -->
        <div class="mb-6">
            <button id="manualCheckBtn" onclick="manualCheck()" class="inline-flex items-center justify-center px-5 py-2.5 bg-brand-surface border border-brand-border text-sm font-bold rounded-xl hover:text-brand-primary hover:border-brand-primary/40 transition-all space-x-2">
                <i class="fa-solid fa-rotate"></i><span>Check Status Now</span>
            </button>
            <p id="manualCheckResult" class="text-xs mt-2 text-brand-muted"></p>
        </div>
        <?php endif; ?>

        <?php if ($fulfilled && !$isWallet && !empty($keyValue)): ?>
            <div class="bg-brand-bg/60 border border-brand-border rounded-xl p-4 mb-6 text-left">
                <p class="text-[10px] font-bold text-brand-muted uppercase tracking-wider mb-2"><?php echo $planName ? $planName . ' — ' : ''; ?>Your License Key</p>
                <div class="flex items-center gap-2">
                    <span class="font-mono text-sm font-bold text-brand-text break-all flex-1"><?php echo $keyValue; ?></span>
                    <button onclick="copyKey('<?php echo $keyValue; ?>', this)" class="text-brand-muted hover:text-brand-primary transition-colors p-1.5 rounded-lg hover:bg-brand-bg">
                        <i class="fa-regular fa-copy"></i>
                    </button>
                </div>
            </div>
        <?php endif; ?>

        <div class="flex flex-col sm:flex-row gap-3 justify-center">
            <a href="dashboard" class="inline-flex items-center justify-center px-5 py-2.5 bg-brand-primary text-white text-sm font-bold rounded-xl hover:opacity-90 transition-all space-x-2 shadow-glow">
                <i class="fa-solid fa-gauge-high"></i><span>Go to Dashboard</span>
            </a>
            <?php if ($isWallet): ?>
                <a href="keys" class="inline-flex items-center justify-center px-5 py-2.5 bg-brand-primary text-white text-sm font-bold rounded-xl hover:opacity-90 transition-all space-x-2 shadow-glow">
                    <i class="fa-solid fa-cart-shopping"></i><span>Buy Keys</span>
                </a>
                <a href="wallet" class="inline-flex items-center justify-center px-5 py-2.5 bg-brand-surface border border-brand-border text-sm font-bold rounded-xl hover:text-brand-primary hover:border-brand-primary/40 transition-all space-x-2">
                    <i class="fa-solid fa-wallet"></i><span>My Wallet</span>
                </a>
            <?php else: ?>
                <a href="keys" class="inline-flex items-center justify-center px-5 py-2.5 bg-brand-surface border border-brand-border text-sm font-bold rounded-xl hover:text-brand-primary hover:border-brand-primary/40 transition-all space-x-2">
                    <i class="fa-solid fa-key"></i><span>My Keys</span>
                </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function copyKey(text, btn) {
    navigator.clipboard.writeText(text).then(() => {
        const icon = btn.querySelector('i');
        icon.className = 'fa-solid fa-check text-brand-success';
        setTimeout(() => { icon.className = 'fa-regular fa-copy'; }, 2000);
    });
}

function manualCheck() {
    var btn = document.getElementById('manualCheckBtn');
    var resultEl = document.getElementById('manualCheckResult');
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i><span>Checking...</span>';
    }
    if (resultEl) resultEl.textContent = '';

    fetch('api/check-payment.php?payment_id=' + encodeURIComponent(PAYMENT_ID_GLOBAL), {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data && data.success && data.status === 'paid') {
            if (resultEl) {
                resultEl.innerHTML = '<i class="fa-solid fa-circle-check text-brand-success"></i> Payment confirmed! Updating...';
                resultEl.className = 'text-xs mt-2 text-brand-success';
            }
            setTimeout(function() { window.location.reload(); }, 1000);
        } else if (data && data.success) {
            if (resultEl) {
                resultEl.innerHTML = '<i class="fa-solid fa-clock"></i> Still verifying... Status: ' + (data.status || 'pending');
                resultEl.className = 'text-xs mt-2 text-brand-warning';
            }
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-rotate"></i><span>Check Status Now</span>';
            }
        } else {
            if (resultEl) {
                resultEl.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i> ' + (data.error || 'Check failed. Will auto-retry.');
                resultEl.className = 'text-xs mt-2 text-brand-error';
            }
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-rotate"></i><span>Check Status Now</span>';
            }
        }
    })
    .catch(function(err) {
        if (resultEl) {
            resultEl.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i> Network error. Will auto-retry.';
            resultEl.className = 'text-xs mt-2 text-brand-error';
        }
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-rotate"></i><span>Check Status Now</span>';
        }
    });
}
</script>

<?php if ($autoRetry): ?>
<!-- AJAX AUTO-POLL: Check payment status every 5s via the SG PANEL's own
     api/check-payment.php endpoint (which calls the gateway's
     /api/check-order-status). No page reload needed. When the payment is
     confirmed, we auto-reload to show the success state. -->
<script>
(function () {
    var PAYMENT_ID = <?php echo json_encode($paymentId); ?>;
    // Expose globally for the manualCheck() function
    window.PAYMENT_ID_GLOBAL = PAYMENT_ID;
    var tries = 0, MAX = 24, DELAY = 5000; // 24 × 5s = 120s max polling

    var statusEl = document.querySelector('.max-w-xl p.text-brand-muted');

    function poll() {
        if (tries >= MAX) return;
        if (document.hidden) return; // pause when tab not visible
        tries++;

        fetch('api/check-payment.php?payment_id=' + encodeURIComponent(PAYMENT_ID), {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data && data.success && data.status === 'paid' && data.fulfilled) {
                // Payment confirmed & wallet credited! Reload to show success.
                if (statusEl) {
                    statusEl.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin mr-2"></i>Payment confirmed! Updating your balance...';
                }
                window.location.reload();
            } else if (data && data.success && data.status === 'paid' && !data.fulfilled) {
                // Payment confirmed but fulfillment failed — reload to show error
                window.location.reload();
            } else {
                // Still pending — update the message and continue polling
                if (statusEl && tries > 1) {
                    statusEl.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin mr-2"></i>Verifying your payment... (attempt ' + tries + '/' + MAX + ')';
                }
                setTimeout(poll, DELAY);
            }
        })
        .catch(function () {
            // Network error — retry after delay
            setTimeout(poll, DELAY);
        });
    }

    // Start polling
    setTimeout(poll, DELAY);

    // Resume polling when tab becomes visible again
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden && tries < MAX) {
            setTimeout(poll, DELAY);
        }
    });
})();
</script>
<?php endif; ?>

<?php
require_once 'includes/footer.php';
?>
