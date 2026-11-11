<?php
require_once 'config/database.php';
require_once 'includes/auth.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['results' => [], 'error' => 'not_logged_in']);
    exit;
}

$pdo = getDBConnection();

if (!isset($_GET['q']) || empty(trim($_GET['q']))) {
    echo json_encode(['results' => []]);
    exit;
}

$query = trim($_GET['q']);
$search_term = "%{$query}%";
$results = [];

// 1. Search Pages (Navigation)
$pages = [
    ['title' => 'Dashboard', 'url' => 'index', 'icon' => 'fa-solid fa-chart-pie', 'type' => 'Page'],
    ['title' => 'Manage Mods', 'url' => 'mods', 'icon' => 'fa-solid fa-gamepad', 'type' => 'Page'],
    ['title' => 'License Keys', 'url' => 'keys', 'icon' => 'fa-solid fa-key', 'type' => 'Page'],
    ['title' => 'Orders', 'url' => 'approvals', 'icon' => 'fa-solid fa-shopping-cart', 'type' => 'Page'],
    ['title' => 'Manage Clients', 'url' => 'users', 'icon' => 'fa-solid fa-users', 'type' => 'Page'],
    ['title' => 'Site Settings', 'url' => 'settings', 'icon' => 'fa-solid fa-cog', 'type' => 'Page'],
    ['title' => 'API Docs', 'url' => 'docs', 'icon' => 'fa-solid fa-code', 'type' => 'Page'],
];

foreach ($pages as $page) {
    if (stripos($page['title'], $query) !== false) {
        $results[] = $page;
    }
}

$isAdmin = isAdmin();
$userId = $_SESSION['user_id'];

// 2. Search Mods (Everyone can search mods)
$stmt = $pdo->prepare("SELECT id, name FROM mods WHERE name LIKE ? LIMIT 3");
$stmt->execute([$search_term]);
while ($row = $stmt->fetch()) {
    $results[] = [
        'title' => $row['name'],
        'url' => 'mods',
        'icon' => 'fa-solid fa-box',
        'type' => 'Mod'
    ];
}

// 3. Search Users (Admin ONLY)
if ($isAdmin) {
    $stmt = $pdo->prepare("SELECT id, username FROM users WHERE username LIKE ? LIMIT 3");
    $stmt->execute([$search_term]);
    while ($row = $stmt->fetch()) {
        $results[] = [
            'title' => $row['username'],
            'url' => 'users',
            'icon' => 'fa-solid fa-user',
            'type' => 'User'
        ];
    }
}

// 4. Search License Keys
if ($isAdmin) {
    // Admin can search ALL keys
    $stmt = $pdo->prepare("SELECT id, license_key FROM license_keys WHERE license_key LIKE ? LIMIT 3");
    $stmt->execute([$search_term]);
} else {
    // User can only search THEIR OWN keys
    $stmt = $pdo->prepare("SELECT id, license_key FROM license_keys WHERE license_key LIKE ? AND sold_to = ? LIMIT 3");
    $stmt->execute([$search_term, $userId]);
}

while ($row = $stmt->fetch()) {
    $results[] = [
        'title' => $row['license_key'],
        'url' => 'keys',
        'icon' => 'fa-solid fa-key',
        'type' => 'License Key'
    ];
}

echo json_encode(['results' => $results]);
exit;
