<?php
/**
 * =====================================================================
 * SG PANEL - Reseller API TEST SCRIPT
 * =====================================================================
 * Yeh script reseller_api.php endpoint ko test karta hai.
 *
 * USAGE (CLI se chalaye):
 *   php test_reseller_api.php https://your-site.com/reseller_api.php MASTER_KEY API_KEY
 *
 * Ya direct values edit karke:
 *   php test_reseller_api.php
 *
 * Yeh script 3 actions test karta hai:
 *   1. balance  - reseller ka balance check
 *   2. products - product list + stock
 *   3. buy      - ek key kharidna (agar product + balance available hai)
 * =====================================================================
 */

// ---- CONFIG (edit these or pass as CLI args) ----
$endpoint   = $argv[1] ?? 'https://localhost/SG_PANEL/reseller_api.php';
$masterKey  = $argv[2] ?? 'a7f3e8b2c9d1f4a6b8c2d5e9f1a3b6c8';
$apiKey     = $argv[3] ?? 'YOUR_RESELLER_API_KEY';

// For buy test (change to a real PID + duration from your catalog)
$testProductId = $argv[4] ?? '11';
$testDuration  = $argv[5] ?? '1 DaYs';
$testDeviceId  = $argv[6] ?? '';

echo "========================================\n";
echo " SG PANEL Reseller API - Test Script\n";
echo "========================================\n";
echo "Endpoint  : $endpoint\n";
echo "Master Key: $masterKey\n";
echo "API Key   : $apiKey\n\n";

if ($apiKey === 'YOUR_RESELLER_API_KEY') {
    echo "⚠️  WARNING: API Key not set. Edit this file or pass as 3rd CLI arg.\n";
    echo "   Usage: php test_reseller_api.php <endpoint> <master_key> <api_key> [product_id] [duration] [device_id]\n\n";
}

/**
 * cURL request helper
 */
function apiCall($endpoint, $masterKey, $fields) {
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['x-master-key: ' . $masterKey],
        CURLOPT_POSTFIELDS     => http_build_query($fields),
        CURLOPT_TIMEOUT        => 30,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    curl_close($ch);
    return ['response' => $response, 'httpCode' => $httpCode, 'error' => $error];
}

function printResult($label, $result) {
    echo "--- $label ---\n";
    if ($result['error']) {
        echo "❌ cURL Error: " . $result['error'] . "\n";
        return;
    }
    echo "HTTP Code: " . $result['httpCode'] . "\n";
    $data = json_decode($result['response'], true);
    if ($data) {
        echo "Response : " . json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
        if (isset($data['status'])) {
            echo $data['status'] === 'success' ? "✅ SUCCESS\n" : "❌ ERROR: " . ($data['msg'] ?? 'unknown') . "\n";
        }
    } else {
        echo "Raw: " . $result['response'] . "\n";
    }
    echo "\n";
}

// ===== TEST 1: BALANCE =====
echo ">>> Testing action=balance...\n";
$r = apiCall($endpoint, $masterKey, [
    'api_key' => $apiKey,
    'action'  => 'balance',
]);
printResult('BALANCE', $r);

// ===== TEST 2: PRODUCTS =====
echo ">>> Testing action=products...\n";
$r = apiCall($endpoint, $masterKey, [
    'api_key' => $apiKey,
    'action'  => 'products',
]);
printResult('PRODUCTS', $r);

// ===== TEST 3: BUY (only if API key is set) =====
if ($apiKey !== 'YOUR_RESELLER_API_KEY') {
    echo ">>> Testing action=buy (PID=$testProductId, duration=$testDuration)...\n";
    $fields = [
        'api_key'    => $apiKey,
        'action'     => 'buy',
        'product_id' => $testProductId,
        'duration'   => $testDuration,
    ];
    if (!empty($testDeviceId)) {
        $fields['android_id'] = $testDeviceId;
    }
    $r = apiCall($endpoint, $masterKey, $fields);
    printResult('BUY', $r);

    // Show the key if success
    $data = json_decode($r['response'], true);
    if ($data && ($data['status'] ?? '') === 'success') {
        echo "🔑 KEY GENERATED: " . ($data['msg'] ?? $data['key'] ?? 'N/A') . "\n";
        echo "💰 Remaining Balance: " . ($data['balance'] ?? 'N/A') . "\n\n";
    }
} else {
    echo ">>> Skipping buy test (set API key to run it)\n\n";
}

// ===== TEST 4: ERROR CASES =====
echo ">>> Testing error: wrong master key...\n";
$r = apiCall($endpoint, 'WRONG_MASTER_KEY', [
    'api_key' => $apiKey,
    'action'  => 'balance',
]);
printResult('WRONG MASTER KEY', $r);

echo ">>> Testing error: wrong API key...\n";
$r = apiCall($endpoint, $masterKey, [
    'api_key' => 'WRONG_API_KEY',
    'action'  => 'balance',
]);
printResult('WRONG API KEY', $r);

echo ">>> Testing error: missing action...\n";
$r = apiCall($endpoint, $masterKey, [
    'api_key' => $apiKey,
]);
printResult('MISSING ACTION', $r);

echo "========================================\n";
echo " Test Complete\n";
echo "========================================\n";
