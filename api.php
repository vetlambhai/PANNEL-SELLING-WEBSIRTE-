<?php
// api.php - API Validation Endpoint for Mod Menus
header("Content-Type: application/json; charset=UTF-8");
require_once 'config/database.php';

// Initialize response array
$response = [
    "status" => "error",
    "message" => "Invalid Request"
];

// --- ADMIN API LOGIC ---
if (isset($_POST['api_key'])) {
    // Load Admin API Key from settings
    $settings_file = __DIR__ . '/config/settings.json';
    $admin_api_key = '';
    if (file_exists($settings_file)) {
        $settings = json_decode(file_get_contents($settings_file), true);
        if (isset($settings['admin_api_key'])) {
            $admin_api_key = $settings['admin_api_key'];
        }
    }

    if (empty($admin_api_key) || trim($_POST['api_key']) !== $admin_api_key) {
        $response["message"] = "Unauthorized. Invalid Admin API Key";
        echo json_encode($response);
        exit;
    }

    if (!isset($_POST['action'])) {
        $response["message"] = "Action parameter is required";
        echo json_encode($response);
        exit;
    }

    $action = trim($_POST['action']);
    $key_id = isset($_POST['key_id']) ? (int)$_POST['key_id'] : null;

    if (!$key_id) {
        $response["message"] = "key_id parameter is required";
        echo json_encode($response);
        exit;
    }

    try {
        $pdo = getDBConnection();
        $stmt = $pdo->prepare("SELECT * FROM license_keys WHERE id = ?");
        $stmt->execute([$key_id]);
        $keyData = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$keyData) {
            $response["message"] = "License key not found";
            echo json_encode($response);
            exit;
        }

        switch ($action) {
            case 'block':
                $stmt = $pdo->prepare("UPDATE license_keys SET status = 'blocked' WHERE id = ?");
                $stmt->execute([$key_id]);
                $response = ["status" => "success", "message" => "License key successfully blocked"];
                break;
            case 'unblock':
                $stmt = $pdo->prepare("UPDATE license_keys SET status = IF(sold_to IS NULL, 'available', 'sold') WHERE id = ?");
                $stmt->execute([$key_id]);
                $response = ["status" => "success", "message" => "License key successfully unblocked"];
                break;
            case 'expire':
                $stmt = $pdo->prepare("UPDATE license_keys SET status = 'expired' WHERE id = ?");
                $stmt->execute([$key_id]);
                $response = ["status" => "success", "message" => "License key successfully expired"];
                break;
            case 'delete':
                $stmt = $pdo->prepare("DELETE FROM license_keys WHERE id = ?");
                $stmt->execute([$key_id]);
                $response = ["status" => "success", "message" => "License key successfully deleted"];
                break;
            case 'edit':
                $duration = isset($_POST['duration']) ? (int)$_POST['duration'] : $keyData['duration'];
                $duration_type = isset($_POST['duration_type']) ? $_POST['duration_type'] : $keyData['duration_type'];
                $price = isset($_POST['price']) ? (float)$_POST['price'] : $keyData['price'];
                $reset_device = isset($_POST['reset_device']) && $_POST['reset_device'] == '1';
                
                $update_sql = "UPDATE license_keys SET duration = ?, duration_type = ?, price = ?";
                $params = [$duration, $duration_type, $price];
                if ($reset_device) {
                    $update_sql .= ", device_id = NULL";
                }
                $update_sql .= " WHERE id = ?";
                $params[] = $key_id;
                
                $stmt = $pdo->prepare($update_sql);
                $stmt->execute($params);
                $response = ["status" => "success", "message" => "License key successfully edited"];
                break;
            default:
                $response["message"] = "Invalid action. Supported actions: block, unblock, expire, delete, edit";
                break;
        }
    } catch (Exception $e) {
        $response["message"] = "Database error: " . $e->getMessage();
    }
    echo json_encode($response);
    exit;
}

// --- APP API LOGIC ---
// Verify if the license key is provided
if (!isset($_GET['key']) || empty(trim($_GET['key']))) {
    $response["message"] = "License key is required";
    echo json_encode($response);
    exit;
}

$key = trim($_GET['key']);
$deviceId = isset($_GET['device_id']) ? trim($_GET['device_id']) : null;

try {
    $pdo = getDBConnection();

    // Check if the key exists and retrieve mod details
    $stmt = $pdo->prepare("
        SELECT lk.*, m.name as mod_name, m.status as mod_status 
        FROM license_keys lk
        JOIN mods m ON lk.mod_id = m.id
        WHERE (lk.license_key = ? OR (lk.provider_key IS NOT NULL AND lk.provider_key = ?)) LIMIT 1
    ");
    $stmt->execute([$key, $key]);
    $license = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$license) {
        $response["message"] = "Invalid license key";
        echo json_encode($response);
        exit;
    }

    // Verify if the mod is active
    if ($license['mod_status'] !== 'active') {
        $response["message"] = "This mod is currently disabled";
        echo json_encode($response);
        exit;
    }

    // Verify if the key is blocked or expired
    if ($license['status'] === 'blocked') {
        $response["message"] = "This license key has been blocked by the administrator";
        echo json_encode($response);
        exit;
    }
    if ($license['status'] === 'expired') {
        $response["message"] = "This license key has been manually expired by the administrator";
        echo json_encode($response);
        exit;
    }

    // Verify if the key has been purchased/sold
    if ($license['status'] !== 'sold') {
        $response["message"] = "License key has not been activated or sold yet";
        echo json_encode($response);
        exit;
    }

    // Verify key expiration
    if (!empty($license['sold_at'])) {
        $durationType = strtolower($license['duration_type']);
        
        // Lifetime keys never expire
        if ($durationType === 'lifetime') {
            // Skip expiration check for lifetime keys
        } else {
            $soldTimestamp = strtotime($license['sold_at']);
            $duration = (int)$license['duration'];
            
            $expirationTime = strtotime("+$duration $durationType", $soldTimestamp);
            $currentTime = time();
            
            if ($currentTime > $expirationTime) {
                $response["message"] = "License key has expired";
                echo json_encode($response);
                exit;
            }
        }
    }

    // Device ID Lock Verification
    if ($deviceId !== null) {
        if (empty($license['device_id'])) {
            // First time use, bind the device ID to the license key
            $updateStmt = $pdo->prepare("UPDATE license_keys SET device_id = ? WHERE id = ?");
            $updateStmt->execute([$deviceId, $license['id']]);
            $license['device_id'] = $deviceId;
        } elseif ($license['device_id'] !== $deviceId) {
            // Locked to another device
            $response["message"] = "License key is locked to another device";
            echo json_encode($response);
            exit;
        }
    } else {
        // If device ID is not supplied but the key is already locked to a device
        if (!empty($license['device_id'])) {
            $response["message"] = "Device ID is required for this locked license key";
            echo json_encode($response);
            exit;
        }
    }
    
    $response = [
        "status" => "success",
        "message" => "License key validated successfully",
        "data" => [
            "mod_name" => $license['mod_name'],
            "duration" => $license['duration'] . ' ' . $license['duration_type'],
            "sold_at" => $license['sold_at'],
            "device_id" => $license['device_id']
        ]
    ];

} catch (Exception $e) {
    $response["message"] = "Server database connection error";
}

echo json_encode($response);
exit;
?>
