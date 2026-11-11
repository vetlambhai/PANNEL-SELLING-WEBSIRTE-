<?php
require_once 'includes/auth.php';
require_once 'includes/functions.php';
require_once 'includes/reseller.php';

requireAdmin();
ensureVideoUrlColumn();
$pdo = getDBConnection();

$success = '';
$error = '';

// Handle Send Key submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_key'])) {
    verify_csrf();
    $targetUserId = (int)($_POST['user_id'] ?? 0);
    $sendMode = $_POST['send_mode'] ?? 'existing'; // 'existing' or 'generate'
    $keyId = (int)($_POST['key_id'] ?? 0);
    $planId = (int)($_POST['plan_id'] ?? 0);
    $deviceId = trim($_POST['device_id'] ?? '');

    if ($targetUserId <= 0) {
        $error = 'Please select a valid user.';
    } else {
        try {
            // Verify the target user exists and is not an admin
            $stmt = $pdo->prepare("SELECT id, username, balance FROM users WHERE id = ? AND role = 'user'");
            $stmt->execute([$targetUserId]);
            $targetUser = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$targetUser) {
                throw new Exception('Selected user not found or is an admin.');
            }

            if ($sendMode === 'existing') {
                // Assign an existing available key (already purchased from reseller)
                $pdo->beginTransaction();
                $stmt = $pdo->prepare("SELECT lk.*, m.name as mod_name FROM license_keys lk
                                       LEFT JOIN mods m ON lk.mod_id = m.id
                                       WHERE lk.id = ? AND lk.status = 'available' AND lk.sold_to IS NULL
                                       FOR UPDATE");
                $stmt->execute([$keyId]);
                $key = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$key) {
                    throw new Exception('Selected key is not available or already sold.');
                }

                $price = (float)$key['price'];
                $modId = $key['mod_id'];
                $keyString = $key['license_key'];
                $duration = $key['duration'];
                $durationType = $key['duration_type'];
                $reference = 'Admin sent key: ' . $key['mod_name'] . ' (' . $keyString . ')';

                // Mark key as sold (store device_id if provided)
                $stmt = $pdo->prepare("UPDATE license_keys SET status = 'sold', sold_to = ?, sold_at = NOW(), device_id = ? WHERE id = ?");
                $stmt->execute([$targetUserId, $deviceId ?: null, $keyId]);

                $pdo->commit();
            } else {
                // Generate a new key from a plan via the RESELLER API (NO random key generation)
                $stmt = $pdo->prepare("SELECT p.*, m.name as mod_name, m.provider_pid, m.requires_device_id FROM mod_plans p
                                       LEFT JOIN mods m ON p.mod_id = m.id
                                       WHERE p.id = ? AND p.status = 'active'");
                $stmt->execute([$planId]);
                $plan = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$plan) {
                    throw new Exception('Selected plan is not available.');
                }

                $providerPid = trim($plan['provider_pid'] ?? '');
                $providerDuration = trim($plan['provider_duration'] ?? '');
                $requiresDeviceId = (int)($plan['requires_device_id'] ?? 0);

                if (empty($providerPid)) {
                    throw new Exception('This product has no Provider PID configured. Set the Provider PID in Edit Mod first.');
                }
                if (empty($providerDuration)) {
                    throw new Exception('This plan has no Provider Duration string configured. Set it in Manage Plans first.');
                }
                if (!isResellerEnabled()) {
                    throw new Exception('Reseller API is not enabled. Configure it in Settings first.');
                }
                if ($requiresDeviceId === 1 && empty($deviceId)) {
                    throw new Exception('Device ID is required for this product (' . htmlspecialchars($plan['mod_name']) . '). Please enter the Android Device ID.');
                }

                // Call the reseller API to buy a REAL key (outside any DB transaction lock)
                $apiResult = buyKeyFromReseller($providerPid, $providerDuration, $deviceId);
                if (!$apiResult['success']) {
                    throw new Exception('Reseller API error: ' . $apiResult['message']);
                }
                $keyString = $apiResult['key'];
                $providerKey = $apiResult['key']; // same as license_key

                $price = 0; // Admin-sent keys from plans are free (manual gift)
                $modId = $plan['mod_id'];
                $duration = $plan['duration'];
                $durationType = $plan['duration_type'];
                $reference = 'Admin sent key: ' . $plan['mod_name'] . ' - ' . $plan['plan_name'] . ' (' . $keyString . ')';

                // Insert the REAL key from the API as sold
                $stmt = $pdo->prepare("INSERT INTO license_keys (mod_id, license_key, provider_key, duration, duration_type, price, status, sold_to, sold_at, device_id)
                                       VALUES (?, ?, ?, ?, ?, ?, 'sold', ?, NOW(), ?)");
                $stmt->execute([$modId, $keyString, $providerKey, $duration, $durationType, $price, $targetUserId, $deviceId ?: null]);
            }

            // Record the transaction (amount 0 since it's a manual admin send)
            $stmt = $pdo->prepare("INSERT INTO transactions (user_id, amount, type, reference, status, plan_id) VALUES (?, 0, 'purchase', ?, 'completed', ?)");
            $stmt->execute([$targetUserId, $reference, $planId ?: null]);

            $success = 'Key sent successfully to "' . htmlspecialchars($targetUser['username']) . '"! License Key: ' . $keyString;
        } catch (Exception $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = $e->getMessage();
        }
    }
}

// Get all users (non-admin) for the dropdown
$stmt = $pdo->query("SELECT id, username, email, balance FROM users WHERE role = 'user' ORDER BY username");
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get all available keys for the dropdown
$stmt = $pdo->query("SELECT lk.id, lk.license_key, lk.provider_key, lk.price, lk.duration, lk.duration_type, m.name as mod_name
                     FROM license_keys lk
                     LEFT JOIN mods m ON lk.mod_id = m.id
                     WHERE lk.status = 'available' AND lk.sold_to IS NULL
                     ORDER BY m.name ASC, lk.id DESC");
$availableKeys = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get all active plans for the generate mode dropdown (include provider info)
$stmt = $pdo->query("SELECT p.id, p.plan_name, p.duration, p.duration_type, p.price, p.provider_duration, m.name as mod_name, m.provider_pid, m.requires_device_id
                     FROM mod_plans p
                     LEFT JOIN mods m ON p.mod_id = m.id
                     WHERE p.status = 'active'
                     ORDER BY m.name ASC, p.price ASC");
$plans = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Build a JSON map of plan -> requires_device_id for JS validation
$plansDeviceMap = [];
foreach ($plans as $p) {
    $plansDeviceMap[$p['id']] = [
        'requires_device_id' => (int)$p['requires_device_id'],
        'mod_name' => $p['mod_name'],
    ];
}

// Get recently sent keys (admin manual sends)
$stmt = $pdo->query("SELECT lk.license_key, lk.provider_key, lk.sold_at, lk.duration, lk.duration_type, lk.price,
                     m.name as mod_name, u.username as sent_to
                     FROM license_keys lk
                     LEFT JOIN mods m ON lk.mod_id = m.id
                     LEFT JOIN users u ON lk.sold_to = u.id
                     WHERE lk.status = 'sold' AND lk.price = 0
                     ORDER BY lk.sold_at DESC
                     LIMIT 20");
$recentSent = $stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = "Send Key to User - " . SITE_NAME . " " . SITE_SUBTITLE;
require_once 'includes/header.php';
?>

<!-- Header -->
<div class="mb-8">
    <h2 class="text-2xl font-bold tracking-tight text-glow-primary">Send Key to User</h2>
    <p class="text-sm text-brand-muted mt-1">Manually assign an existing key or purchase a real key from the reseller API and send it to any user.</p>
</div>

<!-- Reseller status banner -->
<?php if (!isResellerEnabled()): ?>
    <div class="bg-brand-warning/10 border border-brand-warning/20 text-brand-warning text-xs rounded-xl p-3.5 mb-6 flex items-center space-x-2.5">
        <i class="fa-solid fa-triangle-exclamation text-base"></i>
        <span>Reseller API is NOT enabled. "Generate from Plan" mode will not work until you configure the Reseller API in Settings.</span>
    </div>
<?php endif; ?>

<!-- Alerts -->
<?php if ($error): ?>
    <div class="bg-brand-error/10 border border-brand-error/20 text-brand-error text-xs rounded-xl p-3.5 mb-6 flex items-center space-x-2.5">
        <i class="fa-solid fa-circle-exclamation text-base"></i>
        <span><?php echo htmlspecialchars($error); ?></span>
    </div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="bg-brand-success/10 border border-brand-success/20 text-brand-success text-xs rounded-xl p-3.5 mb-6 flex items-center space-x-2.5">
        <i class="fa-solid fa-circle-check text-base"></i>
        <span><?php echo htmlspecialchars($success); ?></span>
    </div>
<?php endif; ?>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
    <!-- Send Key Form -->
    <div class="glass-card rounded-2xl p-6 border border-brand-border/60">
        <h3 class="text-base font-bold mb-6 flex items-center space-x-2">
            <i class="fa-solid fa-paper-plane text-brand-primary"></i>
            <span>Send a License Key</span>
        </h3>

        <form method="POST" action="send-key" id="sendKeyForm">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="send_key" value="1">
            <input type="hidden" name="send_mode" id="sendMode" value="existing">

            <!-- Select User -->
            <div class="mb-5">
                <label for="user_id" class="block text-xs font-bold text-brand-muted uppercase tracking-wider mb-2">
                    <i class="fa-solid fa-user mr-1"></i> Select User
                </label>
                <select name="user_id" id="user_id" required
                        class="w-full px-4 py-3 bg-brand-bg/50 border border-brand-border rounded-xl text-sm focus:outline-none focus:border-brand-primary text-brand-text">
                    <option value="">-- Choose a user --</option>
                    <?php foreach ($users as $u): ?>
                        <option value="<?php echo $u['id']; ?>">
                            <?php echo htmlspecialchars($u['username']); ?>
                            (<?php echo htmlspecialchars($u['email'] ?: 'no email'); ?>)
                            - Balance: &#8377;<?php echo number_format($u['balance'], 2); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if (empty($users)): ?>
                    <p class="text-[10px] text-brand-error mt-1.5">No users found. Users need to register first.</p>
                <?php endif; ?>
            </div>

            <!-- Mode Toggle -->
            <div class="mb-5">
                <label class="block text-xs font-bold text-brand-muted uppercase tracking-wider mb-2">
                    <i class="fa-solid fa-list mr-1"></i> Key Source
                </label>
                <div class="grid grid-cols-2 gap-3">
                    <button type="button" onclick="switchMode('existing')" id="modeBtnExisting"
                            class="py-2.5 px-3 text-xs font-bold rounded-xl border transition-all bg-brand-primary/10 border-brand-primary/30 text-brand-primary">
                        <i class="fa-solid fa-key mb-1 block"></i>
                        Existing Key
                    </button>
                    <button type="button" onclick="switchMode('generate')" id="modeBtnGenerate"
                            class="py-2.5 px-3 text-xs font-bold rounded-xl border transition-all bg-brand-bg/50 border-brand-border text-brand-muted">
                        <i class="fa-solid fa-cloud-arrow-down mb-1 block"></i>
                        Buy from Reseller
                    </button>
                </div>
            </div>

            <!-- Existing Key Selector -->
            <div class="mb-5" id="existingKeySection">
                <label for="key_id" class="block text-xs font-bold text-brand-muted uppercase tracking-wider mb-2">
                    <i class="fa-solid fa-key mr-1"></i> Select Available Key
                </label>
                <select name="key_id" id="key_id"
                        class="w-full px-4 py-3 bg-brand-bg/50 border border-brand-border rounded-xl text-sm focus:outline-none focus:border-brand-primary text-brand-text">
                    <option value="">-- Choose an available key --</option>
                    <?php foreach ($availableKeys as $k): ?>
                        <option value="<?php echo $k['id']; ?>">
                            <?php echo htmlspecialchars($k['mod_name']); ?>
                            - <?php echo htmlspecialchars($k['license_key']); ?>
                            (<?php echo $k['duration'] . ' ' . $k['duration_type']; ?>)
                            &#8377;<?php echo number_format($k['price'], 2); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if (empty($availableKeys)): ?>
                    <p class="text-[10px] text-brand-warning mt-1.5">No available keys. Use "Buy from Reseller" mode to purchase a real key from the provider.</p>
                <?php endif; ?>
            </div>

            <!-- Generate from Plan Selector (Buy from Reseller) -->
            <div class="mb-5 hidden" id="generatePlanSection">
                <label for="plan_id" class="block text-xs font-bold text-brand-muted uppercase tracking-wider mb-2">
                    <i class="fa-solid fa-tags mr-1"></i> Select Plan to Buy Key From Reseller
                </label>
                <select name="plan_id" id="plan_id"
                        class="w-full px-4 py-3 bg-brand-bg/50 border border-brand-border rounded-xl text-sm focus:outline-none focus:border-brand-primary text-brand-text">
                    <option value="">-- Choose a plan --</option>
                    <?php foreach ($plans as $p): ?>
                        <option value="<?php echo $p['id']; ?>" data-requires-device="<?php echo (int)$p['requires_device_id']; ?>">
                            <?php echo htmlspecialchars($p['mod_name']); ?>
                            - <?php echo htmlspecialchars($p['plan_name']); ?>
                            (<?php echo $p['duration'] . ' ' . $p['duration_type']; ?>)
                            <?php if ((int)$p['requires_device_id'] === 1): ?> [Device ID Required]<?php endif; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="text-[10px] text-brand-muted mt-1.5">A real key will be purchased from the KeysMaster reseller API and sent to the user for free (admin gift). No random keys are generated.</p>
            </div>

            <!-- Device ID Input -->
            <div class="mb-5" id="deviceIdSection">
                <label for="device_id" class="block text-xs font-bold text-brand-muted uppercase tracking-wider mb-2">
                    <i class="fa-solid fa-mobile-screen-button mr-1"></i> Device ID (Android ID)
                    <span id="deviceIdStar" class="text-brand-error">*</span>
                </label>
                <input type="text" name="device_id" id="device_id"
                       class="w-full px-4 py-3 bg-brand-bg/50 border border-brand-border rounded-xl text-sm focus:outline-none focus:border-brand-primary text-brand-text"
                       placeholder="e.g. a1b2c3d4e5f67890" autocomplete="off">
                <p class="text-[10px] text-brand-muted mt-1.5" id="deviceIdHint">Required for device-bound products (BALA_MOD). The key will be bound to this device. Optional for other products.</p>
            </div>

            <!-- Submit -->
            <button type="submit" class="w-full h-12 bg-gradient-to-r from-brand-primary to-brand-secondary text-white text-sm font-bold rounded-xl hover:opacity-90 active:scale-[0.98] transition-all flex items-center justify-center gap-2 shadow-glow">
                <i class="fa-solid fa-paper-plane"></i>
                <span>Send Key to User</span>
            </button>
        </form>
    </div>

    <!-- Recently Sent Keys -->
    <div class="glass-card rounded-2xl p-6 border border-brand-border/60">
        <h3 class="text-base font-bold mb-4 flex items-center space-x-2">
            <i class="fa-solid fa-clock-rotate-left text-brand-secondary"></i>
            <span>Recently Sent Keys</span>
        </h3>

        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse">
                <thead>
                    <tr class="border-b border-brand-border/60 text-brand-muted text-xs uppercase font-semibold">
                        <th class="pb-3">Sent To</th>
                        <th class="pb-3">Mod</th>
                        <th class="pb-3">Key</th>
                        <th class="pb-3">Date</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-brand-border/40 text-sm">
                    <?php if (empty($recentSent)): ?>
                    <tr>
                        <td colspan="4" class="text-center text-brand-muted py-12">
                            <i class="fa-solid fa-paper-plane text-3xl mb-2 opacity-50 block"></i>
                            No keys sent yet.
                        </td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($recentSent as $sent): ?>
                        <tr>
                            <td class="py-3 font-bold text-xs text-brand-text"><?php echo htmlspecialchars($sent['sent_to'] ?: 'Unknown'); ?></td>
                            <td class="py-3 text-xs text-brand-muted"><?php echo htmlspecialchars($sent['mod_name']); ?></td>
                            <td class="py-3 font-mono text-[10px] text-brand-secondary"><?php echo htmlspecialchars(substr(displayKey($sent), 0, 16)) . '...'; ?></td>
                            <td class="py-3 text-xs text-brand-muted"><?php echo formatDate($sent['sold_at']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
var plansDeviceMap = <?php echo json_encode($plansDeviceMap); ?>;

function switchMode(mode) {
    var existingBtn = document.getElementById('modeBtnExisting');
    var generateBtn = document.getElementById('modeBtnGenerate');
    var existingSection = document.getElementById('existingKeySection');
    var generateSection = document.getElementById('generatePlanSection');
    var sendMode = document.getElementById('sendMode');
    var deviceIdSection = document.getElementById('deviceIdSection');

    if (mode === 'existing') {
        sendMode.value = 'existing';
        existingBtn.classList.remove('bg-brand-bg/50', 'border-brand-border', 'text-brand-muted');
        existingBtn.classList.add('bg-brand-primary/10', 'border-brand-primary/30', 'text-brand-primary');
        generateBtn.classList.remove('bg-brand-primary/10', 'border-brand-primary/30', 'text-brand-primary');
        generateBtn.classList.add('bg-brand-bg/50', 'border-brand-border', 'text-brand-muted');
        existingSection.classList.remove('hidden');
        generateSection.classList.add('hidden');
        // Device ID optional for existing keys
        updateDeviceIdRequirement(false, '');
    } else {
        sendMode.value = 'generate';
        generateBtn.classList.remove('bg-brand-bg/50', 'border-brand-border', 'text-brand-muted');
        generateBtn.classList.add('bg-brand-primary/10', 'border-brand-primary/30', 'text-brand-primary');
        existingBtn.classList.remove('bg-brand-primary/10', 'border-brand-primary/30', 'text-brand-primary');
        existingBtn.classList.add('bg-brand-bg/50', 'border-brand-border', 'text-brand-muted');
        generateSection.classList.remove('hidden');
        existingSection.classList.add('hidden');
        // Check selected plan's device requirement
        var planSelect = document.getElementById('plan_id');
        var selectedPlanId = planSelect.value;
        var planInfo = selectedPlanId ? plansDeviceMap[selectedPlanId] : null;
        var requiresDevice = planInfo ? (planInfo.requires_device_id === 1) : false;
        var modName = planInfo ? planInfo.mod_name : '';
        updateDeviceIdRequirement(requiresDevice, modName);
    }
}

function updateDeviceIdRequirement(required, modName) {
    var star = document.getElementById('deviceIdStar');
    var hint = document.getElementById('deviceIdHint');
    var input = document.getElementById('device_id');
    if (required) {
        star.style.display = 'inline';
        input.required = true;
        hint.textContent = 'REQUIRED for ' + modName + ' (device-bound product). The key will be bound to this device.';
        hint.classList.remove('text-brand-muted');
        hint.classList.add('text-brand-warning');
    } else {
        star.style.display = 'none';
        input.required = false;
        hint.textContent = 'Required for device-bound products (BALA_MOD). The key will be bound to this device. Optional for other products.';
        hint.classList.remove('text-brand-warning');
        hint.classList.add('text-brand-muted');
    }
}

// When plan selection changes, update device id requirement
document.getElementById('plan_id').addEventListener('change', function() {
    var planId = this.value;
    var planInfo = planId ? plansDeviceMap[planId] : null;
    var requiresDevice = planInfo ? (planInfo.requires_device_id === 1) : false;
    var modName = planInfo ? planInfo.mod_name : '';
    updateDeviceIdRequirement(requiresDevice, modName);
});

// Form validation before submit
document.getElementById('sendKeyForm').addEventListener('submit', function(e) {
    var mode = document.getElementById('sendMode').value;
    var userId = document.getElementById('user_id').value;
    if (!userId) {
        e.preventDefault();
        alert('Please select a user.');
        return;
    }
    if (mode === 'existing') {
        var keyId = document.getElementById('key_id').value;
        if (!keyId) {
            e.preventDefault();
            alert('Please select an available key.');
            return;
        }
    } else {
        var planId = document.getElementById('plan_id').value;
        if (!planId) {
            e.preventDefault();
            alert('Please select a plan to buy a key from the reseller.');
            return;
        }
        var planInfo = plansDeviceMap[planId];
        var deviceId = document.getElementById('device_id').value.trim();
        if (planInfo && planInfo.requires_device_id === 1 && deviceId === '') {
            e.preventDefault();
            alert('Device ID is required for ' + planInfo.mod_name + '. Please enter the Android Device ID.');
            document.getElementById('device_id').focus();
            return;
        }
    }
});
</script>

<?php
require_once 'includes/footer.php';
?>
