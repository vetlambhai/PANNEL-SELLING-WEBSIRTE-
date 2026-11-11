<?php
require_once 'includes/auth.php';
require_once 'includes/functions.php';

requireAdmin();

$success = '';
$error = '';

// Check session flash messages
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (isset($_SESSION['flash_success'])) {
    $success = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}
if (isset($_SESSION['flash_error'])) {
    $error = $_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
}

try {
    $pdo = getDBConnection();
} catch (Exception $e) {
    $error = 'Database connection failed: ' . $e->getMessage();
    $pdo = null;
}

// Handle generate referral code
if ($_POST && isset($_POST['generate_code']) && $pdo) {
    try {
        $expiryDays = (int)$_POST['expiry_days'];
        
        if ($expiryDays <= 0) {
            $error = 'Please enter a valid expiry period';
        } else {
            $code = generateReferralCode();
            $expiresAt = date('Y-m-d H:i:s', strtotime("+$expiryDays days"));
            
            $stmt = $pdo->prepare("INSERT INTO referral_codes (code, created_by, expires_at) VALUES (?, ?, ?)");
            if ($stmt->execute([$code, $_SESSION['user_id'], $expiresAt])) {
                $_SESSION['flash_success'] = "Referral code generated successfully: $code";
                header('Location: referrals.php');
                exit;
            } else {
                $error = 'Failed to generate referral code';
            }
        }
    } catch (Exception $e) {
        $error = 'Error generating referral code: ' . $e->getMessage();
    }
}

// Handle deactivate code
if (isset($_POST['deactivate']) && is_numeric($_POST['deactivate']) && $pdo) {
    verify_csrf();
    try {
        $stmt = $pdo->prepare("UPDATE referral_codes SET status = 'inactive' WHERE id = ?");
        $stmt->execute([$_POST['deactivate']]);
        if ($stmt->rowCount() > 0) {
            $_SESSION['flash_success'] = 'Referral code deactivated successfully!';
        } else {
            $_SESSION['flash_error'] = 'Referral code was not found or already inactive.';
        }
        header('Location: referrals.php');
        exit;
    } catch (Exception $e) {
        $_SESSION['flash_error'] = 'Error deactivating referral code: ' . $e->getMessage();
        header('Location: referrals.php');
        exit;
    }
}

// Handle delete code
if (isset($_POST['delete']) && is_numeric($_POST['delete']) && $pdo) {
    verify_csrf();
    try {
        $stmt = $pdo->prepare("DELETE FROM referral_codes WHERE id = ?");
        $stmt->execute([$_POST['delete']]);
        if ($stmt->rowCount() > 0) {
            $_SESSION['flash_success'] = 'Referral code deleted successfully!';
        } else {
            $_SESSION['flash_error'] = 'Referral code not found.';
        }
        header('Location: referrals.php');
        exit;
    } catch (Exception $e) {
        $_SESSION['flash_error'] = 'Error deleting referral code: ' . $e->getMessage();
        header('Location: referrals.php');
        exit;
    }
}

// Get all referral codes
try {
    if ($pdo) {
        $stmt = $pdo->query("SELECT rc.*, u.username as created_by_name 
                            FROM referral_codes rc 
                            LEFT JOIN users u ON rc.created_by = u.id 
                            ORDER BY rc.created_at DESC");
        $referralCodes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $referralCodes = [];
    }
} catch (Exception $e) {
    $referralCodes = [];
    $error = 'Failed to fetch referral codes: ' . $e->getMessage();
}

// Get referral code statistics
try {
    if ($pdo) {
        $stmt = $pdo->query("SELECT 
            COUNT(*) as total_codes,
            COUNT(CASE WHEN status = 'active' AND expires_at > NOW() THEN 1 END) as active_codes,
            COUNT(CASE WHEN status = 'inactive' OR expires_at <= NOW() THEN 1 END) as inactive_codes
            FROM referral_codes");
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    } else {
        $stats = ['total_codes' => 0, 'active_codes' => 0, 'inactive_codes' => 0];
    }
} catch (Exception $e) {
    $stats = ['total_codes' => 0, 'active_codes' => 0, 'inactive_codes' => 0];
}

$page_title = "Referral Codes - " . SITE_NAME . " " . SITE_SUBTITLE;
require_once 'includes/header.php';
?>

<!-- Header -->
<div class="mb-8 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
    <div>
        <h2 class="text-2xl font-bold tracking-tight text-glow-primary">Referral Codes</h2>
        <p class="text-sm text-brand-muted mt-1 font-medium">Create and manage access tokens required for new registration.</p>
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

<!-- Layout -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
    <!-- Action/Form and Stats Card -->
    <div class="space-y-6">
        <!-- Generate Form -->
        <div class="glass-card rounded-2xl p-6 border border-brand-border/60">
            <h3 class="text-base font-bold mb-4 flex items-center space-x-2">
                <i class="fa-solid fa-square-plus text-brand-primary"></i>
                <span>Generate Token</span>
            </h3>
            <form method="POST" class="space-y-4">
                <div>
                    <label for="expiry_days" class="block text-xs font-semibold text-brand-muted mb-1.5">Expiry Days *</label>
                    <input type="number" id="expiry_days" name="expiry_days" min="1" required value="7"
                           class="w-full px-4 py-2.5 bg-brand-bg/50 border border-brand-border rounded-xl text-sm focus:outline-none focus:border-brand-primary text-brand-text">
                </div>
                <button type="submit" name="generate_code" class="w-full py-3 bg-gradient-to-r from-brand-primary to-brand-secondary hover:opacity-90 active:scale-[0.98] transition-all duration-300 rounded-xl text-sm font-bold text-white shadow-glow flex items-center justify-center space-x-2">
                    <i class="fa-solid fa-wand-magic-sparkles"></i>
                    <span>Generate Code</span>
                </button>
            </form>
        </div>

        <!-- Stats -->
        <div class="bg-brand-surface/40 border border-brand-border rounded-2xl p-6 space-y-4">
            <h4 class="text-sm font-bold text-brand-text mb-2 flex items-center space-x-2">
                <i class="fa-solid fa-chart-pie text-brand-secondary"></i>
                <span>Stats Summary</span>
            </h4>
            <div class="grid grid-cols-3 gap-2 text-center">
                <div class="bg-brand-bg/50 p-3 rounded-xl border border-brand-border/50">
                    <p class="text-[10px] text-brand-muted font-semibold uppercase">Total</p>
                    <p class="text-lg font-bold text-brand-text mt-1"><?php echo $stats['total_codes']; ?></p>
                </div>
                <div class="bg-brand-bg/50 p-3 rounded-xl border border-brand-border/50">
                    <p class="text-[10px] text-brand-muted font-semibold uppercase">Active</p>
                    <p class="text-lg font-bold text-brand-success mt-1"><?php echo $stats['active_codes']; ?></p>
                </div>
                <div class="bg-brand-bg/50 p-3 rounded-xl border border-brand-border/50">
                    <p class="text-[10px] text-brand-muted font-semibold uppercase">Used</p>
                    <p class="text-lg font-bold text-brand-muted mt-1"><?php echo $stats['inactive_codes']; ?></p>
                </div>
            </div>
        </div>
    </div>

    <!-- Table List Card -->
    <div class="lg:col-span-2 glass-card rounded-2xl p-6 border border-brand-border/60">
        <h3 class="text-base font-bold mb-6 flex items-center space-x-2">
            <i class="fa-solid fa-ticket text-brand-secondary"></i>
            <span>All Referral Tokens</span>
        </h3>
        
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse">
                <thead>
                    <tr class="border-b border-brand-border/60 text-brand-muted text-xs uppercase font-semibold">
                        <th class="pb-3">Code</th>
                        <th class="pb-3">Creator</th>
                        <th class="pb-3">Expires At</th>
                        <th class="pb-3">Status</th>
                        <th class="pb-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-brand-border/40 text-sm">
                    <?php if (empty($referralCodes)): ?>
                    <tr>
                        <td colspan="5" class="text-center text-brand-muted py-12">
                            <i class="fa-solid fa-ticket-slash text-3xl mb-2 opacity-50 block"></i>
                            No tokens generated yet.
                        </td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($referralCodes as $code): 
                            $isExpired = strtotime($code['expires_at']) < time();
                            $statusLabel = $code['status'] === 'active' && !$isExpired ? 'Active' : ($isExpired ? 'Expired' : 'Used');
                            $statusClass = $statusLabel === 'Active' ? 'bg-brand-success/10 text-brand-success' : 'bg-brand-muted/20 text-brand-muted';
                        ?>
                        <tr>
                            <td class="py-3.5 font-mono text-xs text-brand-text font-bold">
                                <div class="flex items-center space-x-2">
                                    <span><?php echo htmlspecialchars($code['code']); ?></span>
                                    <button onclick="copyToClipboard('<?php echo htmlspecialchars($code['code']); ?>', this)" class="text-brand-muted hover:text-brand-primary p-0.5 transition-colors">
                                        <i class="fa-regular fa-copy"></i>
                                    </button>
                                </div>
                            </td>
                            <td class="py-3.5 text-xs text-brand-text font-medium"><?php echo htmlspecialchars($code['created_by_name']); ?></td>
                            <td class="py-3.5 text-xs text-brand-muted"><?php echo date('d M Y H:i', strtotime($code['expires_at'])); ?></td>
                            <td class="py-3.5">
                                <span class="px-2 py-0.5 rounded text-[9px] font-bold uppercase <?php echo $statusClass; ?>">
                                    <?php echo $statusLabel; ?>
                                </span>
                            </td>
                            <td class="py-3.5 text-right">
                                <div class="inline-flex space-x-2">
                                    <?php if ($code['status'] === 'active' && !$isExpired): ?>
                                        <form method="POST" style="display:inline;">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="deactivate" value="<?php echo $code['id']; ?>">
                                            <button type="submit" 
                                               class="p-2 rounded-lg bg-brand-surface border border-brand-border text-xs font-bold text-brand-warning hover:border-brand-warning/40 transition-colors inline-block"
                                               onclick="return confirm('Deactivate this code? It can no longer be used for signups.')"
                                               title="Deactivate">
                                                <i class="fa-solid fa-lock"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                    <form method="POST" style="display:inline;">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="delete" value="<?php echo $code['id']; ?>">
                                        <button type="submit" 
                                           class="p-2 rounded-lg bg-brand-surface border border-brand-border text-xs font-bold text-brand-error hover:border-brand-error/40 transition-colors inline-block"
                                           onclick="return confirm('Delete this code permanently?')"
                                           title="Delete">
                                            <i class="fa-regular fa-trash-can text-brand-error"></i>
                                        </button>
                                    </form>
                                </div>
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