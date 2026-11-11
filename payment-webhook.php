<?php
/** ZapUPI webhook receiver. Payment fulfillment is performed only after
 * confirming the order against ZapUPI's server-side Order Status API.
 */
require_once __DIR__ . '/includes/payment.php';
ignore_user_abort(true);
set_time_limit(60);
header('Content-Type: application/json');

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) $data = $_POST;
$orderId = trim((string)($data['order_id'] ?? ''));
$hookStatus = strtolower(trim((string)($data['status'] ?? '')));
if ($orderId === '') { http_response_code(200); echo json_encode(['status'=>'ok']); exit; }

try {
    ensurePaymentOrdersTable();
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("SELECT * FROM payment_orders WHERE payment_id = ? LIMIT 1");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order || in_array($order['status'], ['paid','completed'], true)) {
        http_response_code(200); echo json_encode(['status'=>'ok']); exit;
    }

    // Do not trust webhook status alone. Verify directly with ZapUPI.
    $verified = checkPaymentStatus($orderId);
    if (empty($verified['success']) || $verified['status'] !== 'paid') {
        error_log('[ZapUPI] Webhook received but order status is not confirmed: ' . $orderId . ' ' . json_encode($verified));
        http_response_code(200); echo json_encode(['status'=>'ok']); exit;
    }
    // Compare amount before any wallet credit or license fulfillment.
    if ($verified['amount'] !== null && abs((float)$verified['amount'] - (float)$order['amount']) > 0.009) {
        error_log('[ZapUPI] Amount mismatch for order ' . $orderId);
        http_response_code(200); echo json_encode(['status'=>'ok']); exit;
    }
    $ok = fulfillPaymentOrder($orderId, $verified['utr'] ?? ($data['utr'] ?? null));
    if (!$ok) error_log('[ZapUPI] Fulfillment failed for order ' . $orderId);
} catch (Throwable $e) {
    error_log('[ZapUPI] Webhook error: ' . $e->getMessage());
}
http_response_code(200);
echo json_encode(['status'=>'ok']);
