<?php
/**
 * Google OAuth Login Entry Point
 * Redirects the user to Google's consent screen.
 * After consent, Google sends the user back to google-callback.php with a ?code=.
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

// Already logged in? Go to dashboard.
if (isLoggedIn()) {
    header('Location: ' . (isAdmin() ? 'admin.php' : 'dashboard.php'));
    exit();
}

// If admin hasn't configured Google credentials, show a friendly message.
if (empty(GOOGLE_CLIENT_ID) || empty(GOOGLE_CLIENT_SECRET)) {
    die('<!DOCTYPE html><html lang="en" class="dark"><head><meta charset="UTF-8">'
      . '<title>Google Login unavailable</title>'
      . '<link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">'
      . '</head><body class="bg-neutral-900 text-white min-h-screen flex items-center justify-center p-6">'
      . '<div class="max-w-md text-center space-y-4">'
      . '<div class="text-5xl">🔒</div>'
      . '<h1 class="text-xl font-bold">Google Login is not configured</h1>'
      . '<p class="text-sm text-neutral-400">The site admin needs to add Google Client ID &amp; Secret in the Settings panel first.</p>'
      . '<a href="login.php" class="inline-block mt-2 px-4 py-2 bg-indigo-600 rounded-lg text-sm font-semibold hover:bg-indigo-500">Back to Login</a>'
      . '</div></body></html>');
}

// Build the Google OAuth 2.0 authorization URL
$params = [
    'client_id'     => GOOGLE_CLIENT_ID,
    'redirect_uri'  => GOOGLE_REDIRECT_URI,
    'response_type' => 'code',
    'scope'         => 'openid email profile',
    'access_type'   => 'online',
    'prompt'        => 'consent',
    'state'         => bin2hex(random_bytes(8)), // simple CSRF/state guard
];

// Remember state for verification in callback
$_SESSION['google_oauth_state'] = $params['state'];

$authUrl = 'https://accounts.google.com/o/oauth2/auth?' . http_build_query($params);

header('Location: ' . $authUrl);
exit();
