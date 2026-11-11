<?php
require_once 'includes/auth.php';
requireLogin();

// Detect the dynamic URL path (proxy-aware: Cloudflare / LiteSpeed / nginx)
// Detect HTTPS even behind reverse proxies so the API URL is always https://.
$protocol = 'http://';
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    $protocol = 'https://';
} elseif (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strpos($_SERVER['HTTP_X_FORWARDED_PROTO'], 'https') === 0) {
    $protocol = 'https://';
} elseif (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on') {
    $protocol = 'https://';
} elseif (!empty($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) {
    $protocol = 'https://';
} elseif (!empty($_SERVER['REQUEST_SCHEME']) && $_SERVER['REQUEST_SCHEME'] === 'https') {
    $protocol = 'https://';
}
$domain = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
$api_url = $protocol . $domain . $basePath . '/api';
$site_name = defined('SITE_NAME') ? SITE_NAME : 'Our API';

$markdown = <<<MD
# {$site_name} - Licensing API Documentation

This documentation provides all the information needed to integrate the {$site_name} licensing and hardware-locking API into your mods, Android apps, or scripts. 
You can provide this document directly to AI assistants (like ChatGPT or Claude) to quickly generate integration code for your specific language/framework.

## Endpoint Details

**Base URL:** `{$api_url}`
**HTTP Method:** `GET`

## Query Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `key` | String | Yes | The license key to validate. |
| `device_id` | String | Recommended | The unique hardware fingerprint to lock the key to a specific device. |

## Response Formats (JSON)

### 1. Success (Valid Key)
Returns HTTP 200 OK
```json
{
    "status": "success",
    "message": "Valid license key.",
    "mod_id": 5,
    "expires_at": "2026-12-31 23:59:59"
}
```

### 2. Invalid Key
Returns HTTP 404 Not Found
```json
{
    "status": "error",
    "message": "Invalid license key."
}
```

### 3. Mod Disabled
Returns HTTP 200 OK (with error status)
```json
{
    "status": "error",
    "message": "This mod is currently disabled"
}
```

### 4. Expired Key
Returns HTTP 200 OK (with error status)
```json
{
    "status": "error",
    "message": "License key has expired"
}
```

### 5. HWID Locked (Mismatch)
Returns HTTP 200 OK (with error status)
```json
{
    "status": "error",
    "message": "License key is locked to another device"
}
```

## Admin Remote API

You can also automate keys management remotely using the same endpoint by providing your `api_key`.

**Base URL:** `{$api_url}`
**HTTP Method:** `POST`

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `api_key` | String | Yes | Your secret Admin API Key (from Settings). |
| `action`  | String | Yes | `block`, `unblock`, `expire`, `delete`, `edit` |
| `key_id`  | Int    | Yes | The internal database ID of the key. |
| `duration`| Int    | Optional | Used with edit action (e.g., 30). |
| `duration_type` | String | Optional | Used with edit action (`hours`/`days`/`months`). |

**Example:**
`POST {$api_url}` with body: `api_key=YOUR_SECRET&action=block&key_id=15`

## Integration Notes
Always use POST requests with your Admin API Key. Never expose your API key in client-side code.
MD;

header('Content-Type: text/markdown');
header('Content-Disposition: attachment; filename="multipanel_api_docs.md"');
echo $markdown;
exit;
