<?php
/**
 * =====================================================================
 * MultiPanelX - RESELLER API PROVIDER ENDPOINT
 * =====================================================================
 * Yeh endpoint aapki website ko ek Reseller PROVIDER banata hai.
 *
 * Jaise aap keysmaster.shop/reseller_api.php se connect karte hain,
 * waise hi dusra koi user aapke is endpoint se connect kar payega.
 *
 * FORMAT (bilkul keysmaster jaisa):
 * -----------------------------------
 * POST  {your-site}/reseller_api.php
 *
 * POST Fields (application/x-www-form-urlencoded):
 *   api_key     = <reseller ka unique API key>
 *   action      = buy | balance | products
 *   product_id  = <PID>        (buy ke liye zaroori)
 *   duration    = "1 Day"      (buy ke liye zaroori - exact provider duration)
 *   android_id  = <device id>  (device-bound products ke liye MANDATORY)
 *
 * Header:
 *   x-master-key: <MASTER_KEY>   (sab resellers ka same - admin set karta hai)
 *
 * RESPONSE (JSON):
 *   Buy success:    {"status":"success","msg":"<REAL_KEY>"}
 *   Buy error:      {"status":"error","msg":"<error message>"}
 *   Balance:        {"status":"success","balance":"123.45"}
 *   Products:       {"status":"success","products":[...]}
 *
 * WORKING:
 *   1. Reseller apni website se api_key + master_key bhejta hai
 *   2. Hum api_key se reseller dhundhte hain, master_key validate karte hain
 *   3. product_id (PID) + duration se mod + plan match karte hain
 *   4. Reseller ka effective price (reseller_price) check karte hain
 *   5. Internally buyKeyFromReseller() call karke KeysMaster se REAL key lete hain
 *   6. Reseller ka balance deduct karte hain
 *   7. JSON response me key return karte hain
 * =====================================================================
 */

// Error display off for API (clean JSON only)
error_reporting(0);
ini_set('display_errors', 0);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/reseller.php';
require_once __DIR__ . '/includes/reseller_provider.php';
require_once __DIR__ . '/includes/refer_system.php';

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, x-master-key');

// Handle CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

ensureResellerColumns();
ensureResellerProviderColumns();

// Helper: JSON response bhejke exit
function api_response($data, $httpCode = 200) {
    http_response_code($httpCode);
    echo json_encode($data);
    exit;
}

// Helper: error response (keysmaster format: {"status":"error","msg":"..."})
function api_error($msg, $httpCode = 200) {
    api_response(['status' => 'error', 'msg' => $msg], $httpCode);
}

// Helper: success response
function api_success($extra = []) {
    api_response(array_merge(['status' => 'success'], $extra));
}

// ============================================================
// 1. CONFIG CHECK
// ============================================================
$cfg = getResellerProviderConfig();
if ($cfg['enabled'] !== '1') {
    api_error('Reseller API is currently disabled by the provider. Please contact admin.');
}

$masterKey = $cfg['master_key'] ?? '';
if (empty($masterKey)) {
    api_error('Provider master key is not configured.');
}

// Reseller API (provider) bhi internally keysmaster se keys leta hai -
// isliye keysmaster reseller client bhi enabled hona chahiye
if (!isResellerEnabled()) {
    api_error('Key provider backend is not configured. Please ask admin to configure the upstream reseller API.');
}

// ============================================================
// 2. AUTHENTICATION - MASTER KEY + API KEY
// ============================================================
// Master key header se aata hai
$receivedMaster = '';
if (function_exists('getallheaders')) {
    $headers = getallheaders();
    foreach ($headers as $k => $v) {
        if (strtolower($k) === 'x-master-key') { $receivedMaster = trim($v); break; }
    }
}
// Fallback: Apache/nginx me custom header
if ($receivedMaster === '' && isset($_SERVER['HTTP_X_MASTER_KEY'])) {
    $receivedMaster = trim($_SERVER['HTTP_X_MASTER_KEY']);
}

if (empty($receivedMaster) || !hash_equals($masterKey, $receivedMaster)) {
    api_error('Invalid or missing Master Key. Send header: x-master-key: <MASTER_KEY>');
}

// API key POST field se
$apiKey = trim($_POST['api_key'] ?? $_REQUEST['api_key'] ?? '');
if (empty($apiKey)) {
    api_error('API key is required. Send POST field: api_key=<YOUR_API_KEY>');
}

// Reseller user dhundo
$reseller = findResellerByApiKey($apiKey);
if (!$reseller) {
    api_error('Invalid API key or reseller account is disabled/inactive.');
}

$resellerId = (int)$reseller['id'];

// ============================================================
// 3. ACTION DISPATCH
// ============================================================
$action = trim($_POST['action'] ?? $_REQUEST['action'] ?? '');

if (empty($action)) {
    api_error('Action parameter is required. Supported: buy, balance, products');
}

switch (strtolower($action)) {

    // --------------------------------------------------------
    // ACTION: balance  - reseller ka current wallet balance
    // --------------------------------------------------------
    case 'balance':
        // Fresh balance DB se
        try {
            $pdo = getDBConnection();
            $stmt = $pdo->prepare("SELECT balance, total_spent FROM users WHERE id = ?");
            $stmt->execute([$resellerId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            api_success([
                'balance'     => number_format((float)$row['balance'], 2, '.', ''),
                'total_spent' => number_format((float)$row['total_spent'], 2, '.', ''),
                'currency'    => 'INR',
            ]);
        } catch (Exception $e) {
            api_error('Could not fetch balance.');
        }
        break;

    // --------------------------------------------------------
    // ACTION: products  - product reference table (PID + durations + price)
    // --------------------------------------------------------
    case 'products':
        $catalog = getResellerProductCatalog();
        api_success(['products' => $catalog, 'count' => count($catalog)]);
        break;

    // --------------------------------------------------------
    // ACTION: buy  - REAL key kharido (keysmaster se internally)
    // --------------------------------------------------------
    case 'buy':
        $productId   = trim($_POST['product_id'] ?? $_REQUEST['product_id'] ?? '');
        $duration    = trim($_POST['duration'] ?? $_REQUEST['duration'] ?? '');
        $deviceId    = trim($_POST['android_id'] ?? $_REQUEST['android_id'] ?? '');

        if (empty($productId)) {
            api_error('product_id is required (the Product PID).');
        }
        if (empty($duration)) {
            api_error('duration is required (e.g. "1 Day", "7 Days", "3 Hours").');
        }

        try {
            $pdo = getDBConnection();

            // 1. Product (mod) dhundo PID se (active + under_maintenance dono visible, inactive hidden)
            $stmt = $pdo->prepare("SELECT * FROM mods WHERE provider_pid = ? AND status IN ('active', 'under_maintenance') LIMIT 1");
            $stmt->execute([$productId]);
            $mod = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$mod) {
                api_error('Product not found for PID: ' . $productId . '. Please check your Product ID and try again.');
            }

            // Under maintenance product ko API se purchase block karo
            if ($mod['status'] === 'under_maintenance') {
                api_error('Product "' . $mod['name'] . '" is currently UNDER MAINTENANCE. Please try again later.');
            }

            // 2. Plan dhundo provider_duration match karke (active + stock_out dono visible, inactive hidden)
            $stmt = $pdo->prepare("SELECT * FROM mod_plans WHERE mod_id = ? AND provider_duration = ? AND status IN ('active', 'stock_out') LIMIT 1");
            $stmt->execute([$mod['id'], $duration]);
            $plan = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$plan) {
                // Try case-insensitive match as fallback
                $stmt = $pdo->prepare("SELECT * FROM mod_plans WHERE mod_id = ? AND status IN ('active', 'stock_out') AND LOWER(TRIM(provider_duration)) = LOWER(TRIM(?)) LIMIT 1");
                $stmt->execute([$mod['id'], $duration]);
                $plan = $stmt->fetch(PDO::FETCH_ASSOC);
            }

            if (!$plan) {
                api_error('Duration not found for this product: "' . $duration . '". Please check available durations for PID ' . $productId . '.');
            }

            // Stock out plan ko API se purchase block karo
            if ($plan['status'] === 'stock_out') {
                api_error('Plan "' . $plan['provider_duration'] . '" for product "' . $mod['name'] . '" is currently STOCK OUT. Please try again later.');
            }

            // 3. Device ID validation (device-bound products ke liye MANDATORY)
            $requiresDeviceId = (int)$mod['requires_device_id'];
            if ($requiresDeviceId && empty($deviceId)) {
                api_error('Android ID (device_id) is MANDATORY for this product. Please send android_id field.');
            }

            // 4. Reseller price calculate karo
            $price = getEffectivePrice($plan, true); // reseller = true

            if ($price <= 0) {
                api_error('Invalid product price. Please contact admin.');
            }

            // 5. Pre-check balance (without lock) — fast fail if clearly insufficient
            $stmt = $pdo->prepare("SELECT balance FROM users WHERE id = ?");
            $stmt->execute([$resellerId]);
            $currentBalance = (float)$stmt->fetchColumn();

            if ($currentBalance < $price) {
                api_error('Insufficient balance. Required: Rs.' . number_format($price, 2) . ', Available: Rs.' . number_format($currentBalance, 2) . '. Please recharge your wallet.');
            }

            // 6. EXTERNALLY keysmaster se REAL key lo (transaction ke bahar - lock timeout se bachne ke liye)
            // provider_pid aur provider_duration use karo (exact API format)
            $apiResult = buyKeyFromReseller($mod['provider_pid'], $plan['provider_duration'], $deviceId);

            if (!$apiResult['success'] || empty($apiResult['key'])) {
                $err = $apiResult['error'] ?? 'Unknown provider error';
                api_error('Failed to generate key from provider: ' . $err);
            }

            $realKey = $apiResult['key'];

            // 7. Balance deduct karo aur key store karo (transaction me - atomic)
            $pdo->beginTransaction();

            // Lock + re-check balance inside transaction (race-condition safe)
            $stmt = $pdo->prepare("SELECT balance FROM users WHERE id = ? FOR UPDATE");
            $stmt->execute([$resellerId]);
            $lockedBalance = (float)$stmt->fetchColumn();

            if ($lockedBalance < $price) {
                $pdo->rollBack();
                api_error('Insufficient balance. Required: Rs.' . number_format($price, 2) . ', Available: Rs.' . number_format($lockedBalance, 2) . '. Please recharge your wallet.');
            }

            $stmt = $pdo->prepare("UPDATE users SET balance = balance - ? WHERE id = ?");
            $stmt->execute([$price, $resellerId]);

            // Key store karo - reseller ke naam pe (sold_to = reseller id)
            $stmt = $pdo->prepare("INSERT INTO license_keys (mod_id, license_key, provider_key, duration, duration_type, price, status, sold_to, sold_at, device_id)
                                   VALUES (?, ?, ?, ?, ?, ?, 'sold', ?, NOW(), ?)");
            $stmt->execute([
                $mod['id'],
                $realKey,
                $realKey,
                $plan['duration'],
                $plan['duration_type'],
                $price,
                $resellerId,
                $deviceId ?: null,
            ]);

            // Transaction record (reseller ke liye purchase)
            $reference = "Reseller API Buy: " . $mod['name'] . " (" . $plan['provider_duration'] . ") [PID:" . $productId . "]";
            $stmt = $pdo->prepare("INSERT INTO transactions (user_id, amount, type, reference, status, plan_id) VALUES (?, ?, 'purchase', ?, 'completed', ?)");
            $stmt->execute([$resellerId, -$price, $reference, $plan['id']]);

            $pdo->commit();

            // 8. total_spent sync karo (reseller ka spend track)
            syncUserTotalSpent($resellerId);

            // 8b. Award 5% lifetime referral commission to the reseller's referrer (auto-credited to referrer wallet)
            try {
                addReferralCommission($resellerId, $price, 'reseller_api', $plan['id'], 'Reseller API purchase: ' . $mod['name'] . ' (' . $plan['provider_duration'] . ')');
            } catch (Throwable $re) {
                // Commission failure must never break the successful purchase
                error_log('Referral commission error (reseller_api.php): ' . $re->getMessage());
            }

            // 9. SUCCESS - keysmaster format: {"status":"success","msg":"KEY"}
            api_success([
                'msg'        => $realKey,    // key yahan hai (keysmaster format)
                'key'        => $realKey,    // convenience duplicate
                'product'    => $mod['name'],
                'duration'   => $plan['provider_duration'],
                'price'      => number_format($price, 2, '.', ''),
                'balance'    => number_format($lockedBalance - $price, 2, '.', ''),
            ]);

        } catch (Exception $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            api_error('Server error: ' . $e->getMessage());
        }
        break;

    // --------------------------------------------------------
    // DEFAULT - unknown action
    // --------------------------------------------------------
    default:
        api_error('Unknown action: "' . htmlspecialchars($action) . '". Supported: buy, balance, products');
        break;
}

api_error('Invalid request.');
