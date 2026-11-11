<?php
require_once 'includes/auth.php';
require_once 'includes/functions.php';
require_once 'includes/reseller.php';
require_once 'includes/reseller_provider.php';

requireAdmin();

ensureVideoUrlColumn();
try { ensureResellerProviderColumns(); } catch (Exception $e) {}

$pdo = getDBConnection();

$success = '';
$error = '';

// Handle Plan Deletion
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $planId = $_GET['delete'];
    $stmt = $pdo->prepare("DELETE FROM mod_plans WHERE id = ?");
    if ($stmt->execute([$planId])) {
        $success = 'Plan deleted successfully!';
    } else {
        $error = 'Failed to delete plan.';
    }
}

// Handle status change (3-status: active / inactive / stock_out)
if (isset($_GET['set_status']) && is_numeric($_GET['set_status']) && isset($_GET['status'])) {
    $allowedStatuses = ['active', 'inactive', 'stock_out'];
    $newStatus = $_GET['status'];
    if (in_array($newStatus, $allowedStatuses, true)) {
        $stmt = $pdo->prepare("UPDATE mod_plans SET status = ? WHERE id = ?");
        if ($stmt->execute([$newStatus, $_GET['set_status']])) {
            $success = 'Plan status updated to "' . ucfirst(str_replace('_', ' ', $newStatus)) . '" successfully!';
        } else {
            $error = 'Failed to update plan status.';
        }
    } else {
        $error = 'Invalid status value.';
    }
}

// Handle Add/Edit Plan
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add_plan' || $action === 'edit_plan') {
        $modId = (int)$_POST['mod_id'];
        $planName = trim($_POST['plan_name'] ?? '');
        $duration = (int)$_POST['duration'];
        $durationType = trim($_POST['duration_type']);
        $price = (float)$_POST['price'];
        $providerDuration = trim($_POST['provider_duration'] ?? '');
        $resellerPrice = trim($_POST['reseller_price'] ?? '');
        $resellerPrice = ($resellerPrice === '' || $resellerPrice === null) ? null : (float)$resellerPrice;
        
        if (empty($durationType)) {
            $error = 'Duration type is required.';
        } elseif (empty($providerDuration)) {
            $error = 'Provider Duration string is required. This is the exact duration string the reseller API expects (e.g. "1 DaYs", "1 MoNtHs").';
        } else {
            if ($action === 'add_plan') {
                $stmt = $pdo->prepare("INSERT INTO mod_plans (mod_id, plan_name, duration, duration_type, price, provider_duration, reseller_price, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'active')");
                if ($stmt->execute([$modId, $planName, $duration, $durationType, $price, $providerDuration, $resellerPrice])) {
                    $success = 'Plan added successfully!';
                } else {
                    $error = 'Failed to add plan.';
                }
            } else {
                $planId = (int)$_POST['plan_id'];
                $stmt = $pdo->prepare("UPDATE mod_plans SET mod_id = ?, plan_name = ?, duration = ?, duration_type = ?, price = ?, provider_duration = ?, reseller_price = ? WHERE id = ?");
                if ($stmt->execute([$modId, $planName, $duration, $durationType, $price, $providerDuration, $resellerPrice, $planId])) {
                    $success = 'Plan updated successfully!';
                } else {
                    $error = 'Failed to update plan.';
                }
            }
        }
    }
}

// Get all mods for dropdown
$stmt = $pdo->query("SELECT id, name FROM mods ORDER BY name");
$mods = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get all plans with mod names
$stmt = $pdo->query("
    SELECT p.*, m.name as mod_name 
    FROM mod_plans p 
    LEFT JOIN mods m ON p.mod_id = m.id 
    ORDER BY m.name, p.price ASC
");
$plans = $stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = "Manage Plans - " . SITE_NAME . " " . SITE_SUBTITLE;
require_once 'includes/header.php';
?>

<!-- Header -->
<div class="mb-8 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
    <div>
        <h2 class="text-2xl font-bold tracking-tight text-glow-primary">Manage Plans</h2>
        <p class="text-sm text-brand-muted mt-1 font-medium">Create and manage pricing plans for your mods.</p>
    </div>
    
    <button type="button" onclick="openPlanModal()" class="inline-flex items-center justify-center px-4 py-2.5 bg-brand-primary text-white text-sm font-bold rounded-xl hover:opacity-90 active:scale-[0.98] transition-all space-x-2 shadow-glow w-full sm:w-auto">
        <i class="fa-solid fa-plus"></i>
        <span>Add New Plan</span>
    </button>
</div>

<?php if ($success): ?>
    <div class="mb-6 p-4 rounded-xl bg-brand-success/10 border border-brand-success/20 text-brand-success text-sm font-bold flex items-center animate-fade-in">
        <i class="fa-solid fa-circle-check mr-2"></i>
        <?php echo htmlspecialchars($success); ?>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="mb-6 p-4 rounded-xl bg-brand-error/10 border border-brand-error/20 text-brand-error text-sm font-bold flex items-center animate-fade-in">
        <i class="fa-solid fa-circle-exclamation mr-2"></i>
        <?php echo htmlspecialchars($error); ?>
    </div>
<?php endif; ?>

<!-- Plans List -->
<div class="glass-card rounded-2xl overflow-hidden animate-fade-in">
    <div class="p-4 border-b border-brand-border/60 flex items-center justify-between bg-brand-surface/50">
        <h3 class="font-bold text-brand-text flex items-center text-sm">
            <i class="fa-solid fa-tags text-brand-primary mr-2"></i>
            Active Plans
        </h3>
        <span class="text-xs font-bold text-brand-muted">Total: <?php echo count($plans); ?></span>
    </div>

    <div class="overflow-x-auto">
        <table class="w-full text-sm text-left">
            <thead class="text-xs text-brand-muted uppercase bg-brand-bg/50 font-bold border-b border-brand-border/60">
                <tr>
                    <th scope="col" class="px-6 py-4">Mod Name</th>
                    <th scope="col" class="px-6 py-4">Plan Name</th>
                    <th scope="col" class="px-6 py-4">Duration</th>
                    <th scope="col" class="px-6 py-4">Provider Duration</th>
                    <th scope="col" class="px-6 py-4">Price (₹)</th>
                    <th scope="col" class="px-6 py-4">Reseller (₹)</th>
                    <th scope="col" class="px-6 py-4 text-center">Status</th>
                    <th scope="col" class="px-6 py-4 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-brand-border/60">
                <?php if (empty($plans)): ?>
                    <tr>
                        <td colspan="8" class="px-6 py-12 text-center text-brand-muted">
                            <div class="flex flex-col items-center justify-center">
                                <i class="fa-solid fa-tags text-4xl mb-3 opacity-20"></i>
                                <p class="text-sm">No plans found. Create one to get started!</p>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($plans as $plan): ?>
                    <tr class="hover:bg-brand-bg/30 transition-colors group">
                        <td class="px-6 py-4">
                            <span class="font-bold text-brand-text"><?php echo htmlspecialchars($plan['mod_name'] ?? 'Unknown Mod'); ?></span>
                        </td>
                        <td class="px-6 py-4">
                            <span class="text-brand-text font-medium"><?php echo htmlspecialchars($plan['plan_name']); ?></span>
                        </td>
                        <td class="px-6 py-4">
                            <span class="text-brand-text"><?php echo $plan['duration'] . ' ' . ucfirst($plan['duration_type']); ?></span>
                        </td>
                        <td class="px-6 py-4">
                            <span class="font-mono text-[11px] text-brand-secondary bg-brand-bg/60 px-2 py-1 rounded-md"><?php echo htmlspecialchars($plan['provider_duration'] ?? '-'); ?></span>
                        </td>
                        <td class="px-6 py-4">
                            <span class="font-mono font-bold text-brand-success">₹<?php echo number_format($plan['price'], 2); ?></span>
                        </td>
                        <td class="px-6 py-4">
                            <?php if ($plan['reseller_price'] !== null && $plan['reseller_price'] !== '' && $plan['reseller_price'] >= 0): ?>
                                <span class="font-mono font-bold text-sky-400">₹<?php echo number_format($plan['reseller_price'], 2); ?></span>
                            <?php else: ?>
                                <span class="font-mono text-[11px] text-brand-muted italic">Auto</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-4 text-center">
                            <?php
                            $planBadgeClass = 'bg-brand-muted/10 text-brand-muted border border-brand-muted/20';
                            $planBadgeLabel = 'Inactive';
                            if ($plan['status'] === 'active') {
                                $planBadgeClass = 'bg-brand-success/10 text-brand-success border border-brand-success/20';
                                $planBadgeLabel = 'Active';
                            } elseif ($plan['status'] === 'stock_out') {
                                $planBadgeClass = 'bg-brand-warning/10 text-brand-warning border border-brand-warning/20';
                                $planBadgeLabel = 'Stock Out';
                            }
                            ?>
                            <span class="px-2.5 py-1 rounded-md text-[10px] font-bold uppercase tracking-wider <?php echo $planBadgeClass; ?>">
                                <?php echo $planBadgeLabel; ?>
                            </span>
                        </td>
                        <td class="px-6 py-4 text-right">
                            <div class="flex justify-end gap-2 items-center opacity-100">
                                <button onclick="openPlanModal(<?php echo htmlspecialchars(json_encode($plan)); ?>)" title="Edit Plan" class="p-2 rounded-lg bg-brand-surface border border-brand-border text-brand-text hover:text-brand-primary hover:border-brand-primary/40 transition-colors">
                                    <i class="fa-solid fa-pen text-xs"></i>
                                </button>
                                <!-- 3-status dropdown: Active / Inactive / Stock Out -->
                                <select onchange="if(confirm('Change status of this plan?')){window.location.href='?set_status=<?php echo $plan['id']; ?>&status='+this.value;} else {this.value='<?php echo htmlspecialchars($plan['status']); ?>';}"
                                        title="Change plan status"
                                        class="px-2 py-1.5 rounded-lg bg-brand-surface border border-brand-border text-[11px] font-bold text-brand-text focus:outline-none focus:border-brand-primary cursor-pointer">
                                    <option value="active" <?php echo $plan['status'] === 'active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="inactive" <?php echo $plan['status'] === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                    <option value="stock_out" <?php echo $plan['status'] === 'stock_out' ? 'selected' : ''; ?>>Stock Out</option>
                                </select>
                                <a href="?delete=<?php echo $plan['id']; ?>" onclick="return confirm('Are you sure you want to delete this plan?');" title="Delete Plan" class="p-2 rounded-lg bg-brand-surface border border-brand-border text-brand-text hover:text-brand-error hover:border-brand-error/40 transition-colors">
                                    <i class="fa-solid fa-trash-can text-xs"></i>
                                </a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Add/Edit Plan Modal -->
<div id="planModal" class="fixed inset-0 z-50 items-center justify-center bg-black/60 backdrop-blur-sm px-4" style="display:none">
    <div class="bg-brand-surface border border-brand-border rounded-2xl w-full max-w-md p-6 shadow-2xl relative animate-fade-in mx-auto">
        <button type="button" onclick="closePlanModal()" class="absolute top-4 right-4 text-brand-muted hover:text-brand-text transition-colors w-8 h-8 flex items-center justify-center rounded-lg hover:bg-brand-bg">
            <i class="fa-solid fa-xmark text-lg"></i>
        </button>
        
        <div class="flex items-center space-x-3 mb-6">
            <div class="w-10 h-10 rounded-xl bg-brand-primary/10 border border-brand-primary/20 flex items-center justify-center text-brand-primary">
                <i class="fa-solid fa-tags text-lg"></i>
            </div>
            <div>
                <h3 class="text-xl font-bold text-glow-primary" id="modalTitle">Add New Plan</h3>
                <p class="text-[10px] uppercase font-bold tracking-wider text-brand-muted mt-0.5">Plan Configuration</p>
            </div>
        </div>

        <form method="POST" id="planForm" class="space-y-4">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" id="planAction" value="add_plan">
            <input type="hidden" name="plan_id" id="planId" value="">
            <!-- Plan Name is no longer entered by admin; auto-generated from duration for DB compatibility -->
            <input type="hidden" name="plan_name" id="planName" value="">
            
            <div>
                <label class="block text-[10px] font-bold uppercase tracking-wider text-brand-muted mb-1.5">Select Mod</label>
                <select name="mod_id" id="modId" required class="w-full px-4 py-2.5 bg-brand-bg/50 border border-brand-border rounded-xl text-sm focus:outline-none focus:border-brand-primary text-brand-text appearance-none transition-colors">
                    <?php foreach ($mods as $mod): ?>
                        <option value="<?php echo $mod['id']; ?>"><?php echo htmlspecialchars($mod['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-[10px] font-bold uppercase tracking-wider text-brand-muted mb-1.5">Duration</label>
                    <input type="number" name="duration" id="planDuration" required min="1" placeholder="e.g. 30" class="w-full px-4 py-2.5 bg-brand-bg/50 border border-brand-border rounded-xl text-sm focus:outline-none focus:border-brand-primary text-brand-text placeholder-brand-muted/50 transition-colors">
                </div>
                <div>
                    <label class="block text-[10px] font-bold uppercase tracking-wider text-brand-muted mb-1.5">Type</label>
                    <input type="text" name="duration_type" id="planDurationType" list="durationTypeList" required placeholder="Select or type custom" class="w-full px-4 py-2.5 bg-brand-bg/50 border border-brand-border rounded-xl text-sm focus:outline-none focus:border-brand-primary text-brand-text placeholder-brand-muted/50 transition-colors" autocomplete="off">
                    <datalist id="durationTypeList">
                        <option value="minutes">Minutes</option>
                        <option value="hours">Hours</option>
                        <option value="days">Days</option>
                        <option value="months">Months</option>
                        <option value="lifetime">Lifetime</option>
                    </datalist>
                </div>
            </div>

            <div>
                <label class="block text-[10px] font-bold uppercase tracking-wider text-brand-muted mb-1.5">Provider Duration String *</label>
                <input type="text" name="provider_duration" id="planProviderDuration" required placeholder="e.g. 1 DaYs / 1 MoNtHs / 1 DaYs BRUTAL" class="w-full px-4 py-2.5 bg-brand-bg/50 border border-brand-border rounded-xl text-sm focus:outline-none focus:border-brand-primary text-brand-text placeholder-brand-muted/50 transition-colors font-mono">
                <p class="text-[10px] text-brand-muted mt-1.5">The EXACT duration string the KeysMaster reseller API expects. Must match the provider's format (e.g. "1 DaYs", "1 MoNtHs", "1 DaYs BRUTAL"). This is sent to the API when purchasing a key.</p>
            </div>

            <div>
                <label class="block text-[10px] font-bold uppercase tracking-wider text-brand-muted mb-1.5">Price (₹)</label>
                <div class="relative">
                    <span class="absolute left-4 top-1/2 -translate-y-1/2 text-brand-muted font-bold text-sm">₹</span>
                    <input type="number" name="price" id="planPrice" required min="0" step="0.01" placeholder="0.00" class="w-full pl-8 pr-4 py-2.5 bg-brand-bg/50 border border-brand-border rounded-xl text-sm focus:outline-none focus:border-brand-primary text-brand-text placeholder-brand-muted/50 transition-colors">
                </div>
            </div>

            <div>
                <label class="block text-[10px] font-bold uppercase tracking-wider text-sky-400 mb-1.5">Reseller Price (₹) <span class="text-brand-muted normal-case font-normal">— optional</span></label>
                <div class="relative">
                    <span class="absolute left-4 top-1/2 -translate-y-1/2 text-sky-400 font-bold text-sm">₹</span>
                    <input type="number" name="reseller_price" id="planResellerPrice" min="0" step="0.01" placeholder="Leave blank = auto discount" class="w-full pl-8 pr-4 py-2.5 bg-brand-bg/50 border border-sky-500/30 rounded-xl text-sm focus:outline-none focus:border-sky-500 text-brand-text placeholder-brand-muted/50 transition-colors">
                </div>
                <p class="text-[10px] text-brand-muted mt-1.5">Fixed price charged to resellers for this plan. If left blank, the default Reseller Discount % (set in Settings) is applied automatically.</p>
            </div>

            <div class="pt-2 flex justify-end gap-3 border-t border-brand-border/60 mt-6 pt-4">
                <button type="button" onclick="closePlanModal()" class="px-4 py-2 bg-brand-bg border border-brand-border text-brand-text text-xs font-bold rounded-xl hover:bg-brand-surface transition-colors">
                    Cancel
                </button>
                <button type="submit" class="px-6 py-2 bg-brand-primary text-white text-xs font-bold rounded-xl hover:opacity-90 transition-opacity shadow-glow">
                    Save Plan
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openPlanModal(plan = null) {
    const modal = document.getElementById('planModal');
    const form = document.getElementById('planForm');
    
    if (plan) {
        document.getElementById('modalTitle').textContent = 'Edit Plan';
        document.getElementById('planAction').value = 'edit_plan';
        document.getElementById('planId').value = plan.id;
        document.getElementById('modId').value = plan.mod_id;
        document.getElementById('planName').value = plan.plan_name || '';
        document.getElementById('planDuration').value = plan.duration;
        document.getElementById('planDurationType').value = plan.duration_type;
        document.getElementById('planProviderDuration').value = plan.provider_duration || '';
        document.getElementById('planPrice').value = plan.price;
        document.getElementById('planResellerPrice').value = (plan.reseller_price !== null && plan.reseller_price !== undefined && plan.reseller_price !== '') ? plan.reseller_price : '';
    } else {
        document.getElementById('modalTitle').textContent = 'Add New Plan';
        document.getElementById('planAction').value = 'add_plan';
        document.getElementById('planId').value = '';
        document.getElementById('planName').value = '';
        form.reset();
    }
    
    modal.style.display = 'flex';
    // Auto-generate plan name from duration + type (Plan Name field removed from UI)
    autoPlanName();
}

// Auto-fill hidden plan_name = "{duration} {Type}" so the DB NOT NULL column always has a value.
function autoPlanName() {
    const dur = document.getElementById('planDuration').value || '';
    const typeEl = document.getElementById('planDurationType');
    const type = typeEl ? typeEl.value.trim() : '';
    let label = dur && type ? (dur + ' ' + type.charAt(0).toUpperCase() + type.slice(1)) : '';
    document.getElementById('planName').value = label;
}
document.getElementById('planDuration').addEventListener('input', autoPlanName);
document.getElementById('planDurationType').addEventListener('input', autoPlanName);
document.getElementById('planDurationType').addEventListener('change', autoPlanName);

function closePlanModal() {
    const modal = document.getElementById('planModal');
    modal.style.display = 'none';
}

// Close modal on outside click
document.getElementById('planModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closePlanModal();
    }
});
</script>

<?php require_once 'includes/footer.php'; ?>
