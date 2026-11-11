<?php
require_once 'includes/auth.php';

// Simple helpers
function formatCurrency($amount){
    return '₹' . number_format((float)$amount, 2);
}
function formatDate($dt){
    if(!$dt){ return '-'; }
    return date('d M Y, h:i A', strtotime($dt));
}
// Return the complete (un-truncated) key for display / copy.
function displayKey($key){
    $lk = isset($key['license_key']) ? (string)$key['license_key'] : '';
    $pk = isset($key['provider_key']) ? (string)$key['provider_key'] : '';
    if ($pk !== '' && strlen($pk) > strlen($lk)) { return $pk; }
    return $lk;
}

// Require user login
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Use centralized PDO connection
try {
    $pdo = getDBConnection();
} catch (Throwable $e) {
    die('Database connection failed');
}

// Load current user
$stmt = $pdo->prepare('SELECT id, username, role, balance FROM users WHERE id = ? LIMIT 1');
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();
if(!$user){
    session_destroy();
    header('Location: login.php');
    exit;
}

$success = '';
$error = '';

// Handle key purchase with transaction
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['purchase_key'])) {

    $keyId = (int)($_POST['key_id'] ?? 0);
    if ($keyId <= 0) {
        $error = 'Invalid key.';
    } else {
        try {
            $pdo->beginTransaction();

            // Lock key row
            $stmt = $pdo->prepare('SELECT id, mod_id, price FROM license_keys WHERE id = ? AND sold_to IS NULL LIMIT 1 FOR UPDATE');
            $stmt->execute([$keyId]);
            $key = $stmt->fetch();
            if(!$key){
                throw new Exception('This key is no longer available.');
            }

            // Refresh user balance with lock
            $stmt = $pdo->prepare('SELECT balance FROM users WHERE id = ? FOR UPDATE');
            $stmt->execute([$user['id']]);
            $row = $stmt->fetch();
            $currentBalance = (float)$row['balance'];
            $price = (float)$key['price'];
            if ($currentBalance < $price) {
                throw new Exception('Insufficient balance.');
            }

            // Deduct and mark sold
            $stmt = $pdo->prepare('UPDATE users SET balance = balance - ? WHERE id = ?');
            $stmt->execute([$price, $user['id']]);

            $stmt = $pdo->prepare("UPDATE license_keys SET status = 'sold', sold_to = ?, sold_at = NOW() WHERE id = ?");
            $stmt->execute([$user['id'], $keyId]);

            // record transaction
            try {
                $stmt = $pdo->prepare('INSERT INTO transactions (user_id, type, amount, reference, status, created_at) VALUES (?, "purchase", ?, ?, "completed", NOW())');
                $stmt->execute([$user['id'], -$price, 'License Key Purchase (ID: #' . $keyId . ')']);
            } catch (Throwable $ignored) {}

            $pdo->commit();
            $success = 'License key purchased successfully!';

            // Refresh user data to update balance
            $stmt = $pdo->prepare('SELECT id, username, role, balance FROM users WHERE id = ? LIMIT 1');
            $stmt->execute([$user['id']]);
            $user = $stmt->fetch();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $error = $e->getMessage();
        }
    }
}

// Get filter parameters
$modId = $_GET['mod_id'] ?? '';

// Get all active mods
$mods = [];
try {
    $stmt = $pdo->query("SELECT id, name FROM mods WHERE status = 'active' ORDER BY name");
    $mods = $stmt->fetchAll();
} catch (Throwable $e) {}

// Get available keys (unsold)
try {
    if ($modId !== '' && ctype_digit((string)$modId)) {
        $stmt = $pdo->prepare('SELECT lk.id, lk.mod_id, lk.duration, lk.duration_type, lk.price, m.name AS mod_name
                               FROM license_keys lk
                               LEFT JOIN mods m ON m.id = lk.mod_id
                               WHERE lk.sold_to IS NULL AND lk.mod_id = ?
                               ORDER BY lk.id DESC');
        $stmt->execute([$modId]);
    } else {
        $stmt = $pdo->query('SELECT lk.id, lk.mod_id, lk.duration, lk.duration_type, lk.price, m.name AS mod_name
                              FROM license_keys lk
                              LEFT JOIN mods m ON m.id = lk.mod_id
                              WHERE lk.sold_to IS NULL
                              ORDER BY lk.id DESC');
    }
    $availableKeys = $stmt->fetchAll();
} catch (Throwable $e) {
    $availableKeys = [];
}

// Get user's purchased keys
try {
    $stmt = $pdo->prepare('SELECT lk.*, m.name AS mod_name
                           FROM license_keys lk
                           LEFT JOIN mods m ON m.id = lk.mod_id
                           WHERE lk.sold_to = ?
                           ORDER BY lk.sold_at DESC');
    $stmt->execute([$user['id']]);
    $purchasedKeys = $stmt->fetchAll();
} catch (Throwable $e) {
    $purchasedKeys = [];
}

$page_title = "Generate Keys - " . SITE_NAME . " " . SITE_SUBTITLE;
require_once 'includes/header.php';
?>

<!-- Header -->
<div class="mb-8 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
    <div>
        <h2 class="text-2xl font-bold tracking-tight text-glow-primary">Generate Keys</h2>
        <p class="text-sm text-brand-muted mt-1 font-medium">Purchase active license keys directly using your wallet balance.</p>
    </div>
</div>

<!-- Alerts -->
<?php if ($success): ?>
    <div class="bg-brand-success/10 border border-brand-success/20 text-brand-success text-xs rounded-xl p-3.5 mb-6 flex items-center space-x-2.5">
        <i class="fa-solid fa-circle-check text-base"></i>
        <span><?php echo htmlspecialchars($success); ?></span>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="bg-brand-error/10 border border-brand-error/20 text-brand-error text-xs rounded-xl p-3.5 mb-6 flex items-center space-x-2.5">
        <i class="fa-solid fa-circle-exclamation text-base"></i>
        <span><?php echo htmlspecialchars($error); ?></span>
    </div>
<?php endif; ?>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
    <!-- Store catalog card -->
    <div class="glass-card rounded-2xl p-6 border border-brand-border/60 h-fit">
        <h3 class="text-base font-bold mb-4 flex items-center space-x-2">
            <i class="fa-solid fa-cart-shopping text-brand-primary"></i>
            <span>Available Keys</span>
        </h3>
        
        <!-- Filter dropdown -->
        <form method="GET" class="mb-6">
    <?php echo csrf_field(); ?>
            <label for="mod_id" class="block text-[10px] font-bold uppercase text-brand-muted mb-1.5">Filter by Mod</label>
            <div class="flex items-center space-x-2">
                <select id="mod_id" name="mod_id" class="w-full px-4 py-2 bg-brand-bg/50 border border-brand-border rounded-xl text-xs focus:outline-none focus:border-brand-primary text-brand-text">
                    <option value="">All Mods</option>
                    <?php foreach ($mods as $mod): ?>
                        <option value="<?php echo $mod['id']; ?>" <?php echo $modId == $mod['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($mod['name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="px-4 py-2 bg-brand-primary text-white text-xs font-bold rounded-xl hover:opacity-90 active:scale-[0.98] transition-all">
                    Filter
                </button>
            </div>
        </form>

        <div class="space-y-4 max-h-[350px] overflow-y-auto pr-1">
            <?php if (empty($availableKeys)): ?>
                <div class="text-center text-brand-muted py-6">
                    No keys available matching selection.
                </div>
            <?php else: ?>
                <?php foreach ($availableKeys as $key): ?>
                    <div class="p-4 rounded-xl bg-brand-surface/40 border border-brand-border/50 flex flex-col justify-between space-y-3">
                        <div class="flex justify-between items-start">
                            <div>
                                <h4 class="text-xs font-bold text-brand-text"><?php echo htmlspecialchars($key['mod_name']); ?></h4>
                                <span class="text-[9px] font-bold bg-brand-primary/10 text-brand-primary px-1.5 py-0.5 rounded uppercase mt-1 inline-block">
                                    <?php echo $key['duration'] . ' ' . $key['duration_type']; ?>
                                </span>
                            </div>
                            <span class="text-xs font-bold text-brand-success">₹<?php echo number_format($key['price'], 2); ?></span>
                        </div>
                        <form method="POST">
    <?php echo csrf_field(); ?>
                            <input type="hidden" name="key_id" value="<?php echo $key['id']; ?>">
                            <button type="submit" name="purchase_key" class="w-full py-2 bg-brand-primary/10 border border-brand-primary/25 hover:bg-brand-primary hover:text-white transition-all text-xs font-bold rounded-lg text-brand-primary flex items-center justify-center space-x-1">
                                <i class="fa-solid fa-credit-card"></i>
                                <span>Buy Key</span>
                            </button>
                        </form>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Purchased Keys history list -->
    <div class="lg:col-span-2 glass-card rounded-2xl p-6 border border-brand-border/60">
        <h3 class="text-base font-bold mb-6 flex items-center space-x-2">
            <i class="fa-solid fa-clock-rotate-left text-brand-secondary"></i>
            <span>My Purchased Keys</span>
        </h3>
        
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse">
                <thead>
                    <tr class="border-b border-brand-border/60 text-brand-muted text-xs uppercase font-semibold">
                        <th class="pb-3">Mod Name</th>
                        <th class="pb-3">License Key</th>
                        <th class="pb-3">Duration</th>
                        <th class="pb-3">Price</th>
                        <th class="pb-3">Purchased Date</th>
                        <th class="pb-3 text-right">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-brand-border/40 text-sm">
                    <?php if (empty($purchasedKeys)): ?>
                    <tr>
                        <td colspan="6" class="text-center text-brand-muted py-12">
                            <i class="fa-solid fa-key text-3xl mb-2 opacity-50 block"></i>
                            No purchased keys yet.
                        </td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($purchasedKeys as $key): ?>
                        <tr>
                            <td class="py-3.5 font-semibold text-brand-text"><?php echo htmlspecialchars($key['mod_name']); ?></td>
                            <td class="py-3.5 font-mono text-xs text-brand-text">
                                <span class="bg-brand-bg/50 px-2 py-1 rounded border border-brand-border/50"><?php echo htmlspecialchars(displayKey($key)); ?></span>
                            </td>
                            <td class="py-3.5">
                                <span class="px-2 py-0.5 rounded text-[9px] font-bold bg-brand-primary/10 text-brand-primary uppercase">
                                    <?php echo $key['duration'] . ' ' . $key['duration_type']; ?>
                                </span>
                            </td>
                            <td class="py-3.5 font-bold text-xs text-brand-success">₹<?php echo number_format($key['price'], 2); ?></td>
                            <td class="py-3.5 text-brand-muted text-xs"><?php echo formatDate($key['sold_at']); ?></td>
                            <td class="py-3.5 text-right">
                                <button onclick="copyToClipboard('<?php echo htmlspecialchars(displayKey($key), ENT_QUOTES); ?>', this)" class="p-1.5 rounded bg-brand-surface border border-brand-border text-brand-muted hover:text-brand-primary transition-colors">
                                    <i class="fa-regular fa-copy"></i>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
require_once 'includes/footer.php';
?>