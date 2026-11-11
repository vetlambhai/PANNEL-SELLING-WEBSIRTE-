<?php
/**
 * Google OAuth 2.0 Callback
 * Google redirects here with ?code=...&state=...
 * We exchange the code for an access token, fetch the user profile, then log in.
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

// Already logged in? Go home.
if (isLoggedIn()) {
    header('Location: ' . (isAdmin() ? 'admin.php' : 'dashboard.php'));
    exit();
}

$code  = $_GET['code'] ?? '';
$state = $_GET['state'] ?? '';
$error = $_GET['error'] ?? '';

function google_fail(string $msg) {
    // Send the user back to login with an error flag (login page can show a message if desired)
    header('Location: login.php?google_error=' . urlencode($msg));
    exit();
}

// User declined / Google returned an error
if ($error) {
    google_fail('Google sign-in was cancelled or failed.');
}
if (empty($code)) {
    google_fail('Missing authorization code from Google.');
}

// State validation (CSRF guard)
$savedState = $_SESSION['google_oauth_state'] ?? '';
unset($_SESSION['google_oauth_state']);
if (empty($savedState) || $savedState !== $state) {
    google_fail('Invalid state parameter. Please try again.');
}
if (empty(GOOGLE_CLIENT_ID) || empty(GOOGLE_CLIENT_SECRET)) {
    google_fail('Google Login is not configured by the admin.');
}

// --- Exchange authorization code for an access token ---
$tokenPayload = http_build_query([
    'code'          => $code,
    'client_id'     => GOOGLE_CLIENT_ID,
    'client_secret' => GOOGLE_CLIENT_SECRET,
    'redirect_uri'  => GOOGLE_REDIRECT_URI,
    'grant_type'    => 'authorization_code',
]);

$ch = curl_init('https://oauth2.googleapis.com/token');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $tokenPayload,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    CURLOPT_TIMEOUT        => 20,
]);
$tokenResp = curl_exec($ch);
$httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200 || empty($tokenResp)) {
    google_fail('Failed to exchange code with Google. Please try again.');
}

$tokenData = json_decode($tokenResp, true);
$accessToken = $tokenData['access_token'] ?? '';
if (empty($accessToken)) {
    google_fail('No access token returned by Google.');
}

// --- Fetch the user profile from Google ---
$ch = curl_init('https://www.googleapis.com/oauth2/v3/userinfo');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $accessToken],
    CURLOPT_TIMEOUT        => 20,
]);
$profileResp = curl_exec($ch);
$profileCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($profileCode !== 200 || empty($profileResp)) {
    google_fail('Failed to fetch your Google profile.');
}

$profile = json_decode($profileResp, true);
if (empty($profile['sub']) || empty($profile['email'])) {
    google_fail('Incomplete Google profile data.');
}

// Normalize to the keys google_oauth_login() expects
$userProfile = [
    'id'         => $profile['sub'],
    'email'      => $profile['email'],
    'name'       => $profile['name'] ?? '',
    'given_name' => $profile['given_name'] ?? '',
];

// Ensure DB schema has oauth columns (auto-migration)
if (function_exists('ensureResellerColumns')) {
    ensureResellerColumns();
}

// Log in / create the user
$ok = google_oauth_login($userProfile);
if ($ok) {
    // Logged in -> go to dashboard (or admin if admin account linked)
    header('Location: ' . (isAdmin() ? 'admin.php' : 'dashboard.php'));
    exit();
}
google_fail('Google login failed. Please try again or use username/password.');
