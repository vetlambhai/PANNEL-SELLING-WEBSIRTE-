<?php
require_once 'includes/auth.php';

requireAdmin();
$pdo = getDBConnection();

$success = '';
$error = '';

// Handle Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? null;
    $key_id = $_POST['key_id'] ?? null;
    
    if ($key_id && $action) {
        try {
            if ($action === 'edit_key') {
                $duration = (int)$_POST['duration'];
                $duration_type = $_POST['duration_type'];
                if (!in_array($duration_type, ['days', 'hours', 'minutes', 'months', 'lifetime'])) {
                    throw new Exception("Invalid duration type.");
                }
                $price = (float)$_POST['price'];
                $new_license_key = trim($_POST['license_key'] ?? '');
                $reset_device = isset($_POST['reset_device']) ? 1 : 0;
                
                if (empty($new_license_key)) {
                    throw new Exception("License key cannot be empty.");
                }
                
                $update_sql = "UPDATE license_keys SET license_key = ?, duration = ?, duration_type = ?, price = ?";
                $params = [$new_license_key, $duration, $duration_type, $price];
                
                if ($reset_device) {
                    $update_sql .= ", device_id = NULL";
                }
                $update_sql .= " WHERE id = ?";
                $params[] = $key_id;
                
                $stmt = $pdo->prepare($update_sql);
                $stmt->execute($params);
                $success = "License key updated successfully.";
                
            } elseif ($action === 'block_key') {
                $stmt = $pdo->prepare("UPDATE license_keys SET status = 'blocked' WHERE id = ?");
                $stmt->execute([$key_id]);
                $success = "License key blocked successfully.";
            } elseif ($action === 'unblock_key') {
                $stmt = $pdo->prepare("UPDATE license_keys SET status = 'available' WHERE id = ? AND sold_to IS NULL");
                $stmt->execute([$key_id]);
                $stmt = $pdo->prepare("UPDATE license_keys SET status = 'sold' WHERE id = ? AND sold_to IS NOT NULL");
                $stmt->execute([$key_id]);
                $success = "License key unblocked successfully.";
            } elseif ($action === 'expire_key') {
                $stmt = $pdo->prepare("UPDATE license_keys SET status = 'expired' WHERE id = ?");
                $stmt->execute([$key_id]);
                $success = "License key expired successfully.";
            } elseif ($action === 'delete_key') {
                $stmt = $pdo->prepare("DELETE FROM license_keys WHERE id = ?");
                $stmt->execute([$key_id]);
                $success = "License key deleted successfully.";
            }
        } catch (Exception $e) {
            $error = "Action failed: " . $e->getMessage();
        }
    }
}

// Get filter parameters
$filters = [
    'mod_id' => $_GET['mod_id'] ?? '',
    'status' => $_GET['status'] ?? '',
    'search' => $_GET['search'] ?? ''
];

// Get all mods for filter dropdown
$stmt = $pdo->query("SELECT id, name FROM mods ORDER BY name");
$mods = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Build query with filters
$where = ["1=1"];
$params = [];

if (!empty($filters['mod_id'])) {
    $where[] = "lk.mod_id = ?";
    $params[] = $filters['mod_id'];
}

if (!empty($filters['status'])) {
    $where[] = "lk.status = ?";
    $params[] = $filters['status'];
}

if (!empty($filters['search'])) {
    $where[] = "(lk.license_key LIKE ? OR m.name LIKE ?)";
    $searchTerm = '%' . $filters['search'] . '%';
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

$sql = "SELECT lk.*, m.name as mod_name 
        FROM license_keys lk 
        LEFT JOIN mods m ON lk.mod_id = m.id 
        WHERE " . implode(' AND ', $where) . " 
        ORDER BY lk.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$licenseKeys = $stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = "License Key List - " . SITE_NAME . " " . SITE_SUBTITLE;
require_once 'includes/header.php';
?>

<!-- Header -->
<div class="mb-8 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
    <div>
        <h2 class="text-2xl font-bold tracking-tight text-glow-primary">Manage Keys</h2>
        <p class="text-sm text-brand-muted mt-1">View, edit, and manage all license keys.</p>
    </div>
    <div class="flex items-center space-x-3">
        <a href="license-add" class="inline-flex items-center justify-center px-4 py-2.5 bg-brand-primary text-white text-xs font-bold rounded-xl hover:opacity-90 active:scale-[0.98] transition-all space-x-1.5 shadow-glow">
            <i class="fa-solid fa-plus text-sm"></i>
            <span>Add Keys</span>
        </a>
    </div>
</div>

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

<!-- Filters -->
<div class="glass-card rounded-2xl p-4 md:p-6 mb-6 border border-brand-border/60">
    <form method="GET" class="flex flex-col md:flex-row md:items-end gap-4">
        <div class="flex-1">
            <label class="block text-[10px] font-bold uppercase text-brand-muted mb-1.5">Search</label>
            <div class="relative">
                <i class="fa-solid fa-magnifying-glass absolute left-4 top-1/2 -translate-y-1/2 text-brand-muted"></i>
                <input type="text" name="search" value="<?php echo htmlspecialchars($filters['search']); ?>" placeholder="Search by key..." class="w-full pl-10 pr-4 py-2 bg-brand-bg/50 border border-brand-border rounded-xl text-sm focus:outline-none focus:border-brand-primary text-brand-text">
            </div>
        </div>
        <div class="w-full md:w-48">
            <label class="block text-[10px] font-bold uppercase text-brand-muted mb-1.5">App/Mod</label>
            <select name="mod_id" class="w-full px-4 py-2 bg-brand-bg/50 border border-brand-border rounded-xl text-sm focus:outline-none focus:border-brand-primary text-brand-text appearance-none">
                <option value="">All Apps</option>
                <?php foreach ($mods as $mod): ?>
                    <option value="<?php echo $mod['id']; ?>" <?php echo $filters['mod_id'] == $mod['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($mod['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="w-full md:w-48">
            <label class="block text-[10px] font-bold uppercase text-brand-muted mb-1.5">Status</label>
            <div class="flex items-center space-x-2">
                <select name="status" class="w-full px-4 py-2 bg-brand-bg/50 border border-brand-border rounded-xl text-sm focus:outline-none focus:border-brand-primary text-brand-text appearance-none">
                    <option value="">All Status</option>
                    <option value="available" <?php echo $filters['status'] === 'available' ? 'selected' : ''; ?>>Available</option>
                    <option value="sold" <?php echo $filters['status'] === 'sold' ? 'selected' : ''; ?>>Sold</option>
                    <option value="blocked" <?php echo $filters['status'] === 'blocked' ? 'selected' : ''; ?>>Blocked</option>
                    <option value="expired" <?php echo $filters['status'] === 'expired' ? 'selected' : ''; ?>>Expired</option>
                </select>
                <button type="submit" class="px-4 py-2 bg-brand-primary text-white text-xs font-bold rounded-xl hover:opacity-90 active:scale-[0.98] transition-all">
                    Apply
                </button>
            </div>
        </div>
    </form>
</div>

<!-- Table Card -->
<div class="glass-card rounded-2xl p-6 border border-brand-border/60">
    <div class="overflow-x-auto">
        <table class="w-full text-left border-collapse">
            <thead>
                <tr class="border-b border-brand-border/60 text-brand-muted text-xs uppercase font-semibold">
                    <th class="pb-3">Mod Name</th>
                    <th class="pb-3">License Key</th>
                    <th class="pb-3">Duration</th>
                    <th class="pb-3">Price</th>
                    <th class="pb-3">Status</th>
                    <th class="pb-3 text-right">Created</th>
                    <th class="pb-3 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-brand-border/40 text-sm">
                <?php if (empty($licenseKeys)): ?>
                <tr>
                    <td colspan="7" class="text-center text-brand-muted py-12">
                        <i class="fa-solid fa-key text-3xl mb-2 opacity-50 block"></i>
                        No license keys matching filters.
                    </td>
                </tr>
                <?php else: ?>
                    <?php foreach ($licenseKeys as $key): ?>
                    <tr>
                        <td class="py-3.5 font-semibold text-brand-text whitespace-nowrap"><?php echo htmlspecialchars($key['mod_name']); ?></td>
                        <td class="py-3.5 font-mono text-xs text-brand-text whitespace-nowrap">
                            <div class="flex items-center space-x-2">
                                <span><?php echo htmlspecialchars($key['license_key']); ?></span>
                                <button onclick="copyToClipboard('<?php echo htmlspecialchars($key['license_key']); ?>', this)" class="text-brand-muted hover:text-brand-primary p-0.5 transition-colors">
                                    <i class="fa-regular fa-copy"></i>
                                </button>
                            </div>
                        </td>
                        <td class="py-3.5 whitespace-nowrap">
                            <span class="px-2 py-0.5 rounded text-[9px] font-bold bg-brand-primary/10 text-brand-primary uppercase">
                                <?php echo $key['duration'] . ' ' . $key['duration_type']; ?>
                            </span>
                        </td>
                        <td class="py-3.5 font-bold text-xs text-brand-success whitespace-nowrap">₹<?php echo number_format($key['price'], 2); ?></td>
                        <td class="py-3.5 whitespace-nowrap">
                            <span class="px-2 py-0.5 rounded text-[9px] font-bold uppercase 
                            <?php 
                            if ($key['status'] === 'available') echo 'bg-brand-success/10 text-brand-success';
                            elseif ($key['status'] === 'sold') echo 'bg-brand-primary/10 text-brand-primary';
                            elseif ($key['status'] === 'blocked') echo 'bg-brand-error/10 text-brand-error';
                            elseif ($key['status'] === 'expired') echo 'bg-brand-warning/10 text-brand-warning';
                            else echo 'bg-brand-muted/20 text-brand-muted';
                            ?>">
                                <?php echo ucfirst($key['status']); ?>
                            </span>
                        </td>
                        <td class="py-3.5 text-right text-brand-muted text-xs whitespace-nowrap"><?php echo date('d M Y H:i', strtotime($key['created_at'])); ?></td>
                        <td class="py-3.5 text-right whitespace-nowrap">
                            <div class="inline-flex space-x-2">
                                <button type="button" onclick="openEditModal(this)" 
                                   data-id="<?php echo $key['id']; ?>"
                                   data-key="<?php echo htmlspecialchars($key['license_key'], ENT_QUOTES); ?>"
                                   data-duration="<?php echo $key['duration']; ?>"
                                   data-type="<?php echo htmlspecialchars($key['duration_type'], ENT_QUOTES); ?>"
                                   data-price="<?php echo $key['price']; ?>"
                                   title="Edit Key"
                                   class="p-2 rounded-lg bg-brand-surface border border-brand-border text-xs font-bold hover:text-brand-primary hover:border-brand-primary/40 transition-colors">
                                    <i class="fa-solid fa-pen text-brand-primary"></i>
                                </button>
                                <?php if ($key['status'] === 'blocked'): ?>
                                <form method="POST" class="inline">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="unblock_key">
                                    <input type="hidden" name="key_id" value="<?php echo $key['id']; ?>">
                                    <button type="submit" title="Unblock Key" onclick="return confirm('Unblock this key?');" class="p-2 rounded-lg bg-brand-surface border border-brand-border text-xs font-bold hover:text-brand-success hover:border-brand-success/40 transition-colors">
                                        <i class="fa-solid fa-unlock text-brand-success"></i>
                                    </button>
                                </form>
                                <?php else: ?>
                                <form method="POST" class="inline">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="block_key">
                                    <input type="hidden" name="key_id" value="<?php echo $key['id']; ?>">
                                    <button type="submit" title="Block Key" onclick="return confirm('Block this key?');" class="p-2 rounded-lg bg-brand-surface border border-brand-border text-xs font-bold hover:text-brand-error hover:border-brand-error/40 transition-colors">
                                        <i class="fa-solid fa-ban text-brand-error"></i>
                                    </button>
                                </form>
                                <?php endif; ?>
                                <?php if ($key['status'] !== 'expired'): ?>
                                <form method="POST" class="inline">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="expire_key">
                                    <input type="hidden" name="key_id" value="<?php echo $key['id']; ?>">
                                    <button type="submit" title="Expire Key" onclick="return confirm('Expire this key immediately?');" class="p-2 rounded-lg bg-brand-surface border border-brand-border text-xs font-bold hover:text-brand-warning hover:border-brand-warning/40 transition-colors">
                                        <i class="fa-regular fa-clock text-brand-warning"></i>
                                    </button>
                                </form>
                                <?php endif; ?>
                                <form method="POST" class="inline">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="delete_key">
                                    <input type="hidden" name="key_id" value="<?php echo $key['id']; ?>">
                                    <button type="submit" title="Delete Key" onclick="return confirm('Are you sure you want to permanently delete this key?');" class="p-2 rounded-lg bg-brand-surface border border-brand-border text-xs font-bold hover:text-brand-error hover:border-brand-error/40 transition-colors">
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

<!-- Edit Key Modal -->
<div id="licEditModal" class="fixed inset-0 z-50 items-center justify-center bg-black/60 backdrop-blur-sm px-4" style="display:none">
    <div class="bg-brand-surface border border-brand-border rounded-2xl w-full max-w-md p-6 shadow-2xl relative animate-fade-in mx-auto">
        <button type="button" onclick="closeLicEditModal()" class="absolute top-4 right-4 text-brand-muted hover:text-brand-text transition-colors">
            <i class="fa-solid fa-xmark text-lg"></i>
        </button>
        
        <h3 class="text-xl font-bold mb-4 text-glow-primary">Edit License Key</h3>
        
        <form method="POST" id="editKeyForm">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="edit_key">
            <input type="hidden" name="key_id" id="edit_key_id">
            
            <div class="mb-4">
                <label class="block text-[10px] font-bold uppercase text-brand-muted mb-1.5">License Key</label>
                <input type="text" id="edit_key_string" name="license_key" required class="w-full px-4 py-2 bg-brand-bg/50 border border-brand-border rounded-xl text-sm focus:outline-none focus:border-brand-primary text-brand-text font-mono">
            </div>
            
            <div class="grid grid-cols-2 gap-4 mb-4">
                <div>
                    <label class="block text-[10px] font-bold uppercase text-brand-muted mb-1.5">Duration</label>
                    <input type="number" id="edit_duration" name="duration" required min="1" class="w-full px-4 py-2 bg-brand-bg/50 border border-brand-border rounded-xl text-sm focus:outline-none focus:border-brand-primary text-brand-text">
                </div>
                <div>
                    <label class="block text-[10px] font-bold uppercase text-brand-muted mb-1.5">Type</label>
                    <select id="edit_duration_type" name="duration_type" class="w-full px-4 py-2 bg-brand-bg/50 border border-brand-border rounded-xl text-sm focus:outline-none focus:border-brand-primary text-brand-text appearance-none">
                        <option value="minutes">Minutes</option>
                        <option value="hours">Hours</option>
                        <option value="days">Days</option>
                        <option value="months">Months</option>
                        <option value="lifetime">Lifetime</option>
                    </select>
                </div>
            </div>
            
            <div class="mb-4">
                <label class="block text-[10px] font-bold uppercase text-brand-muted mb-1.5">Price (₹)</label>
                <input type="number" step="0.01" id="edit_price" name="price" required class="w-full px-4 py-2 bg-brand-bg/50 border border-brand-border rounded-xl text-sm focus:outline-none focus:border-brand-primary text-brand-text">
            </div>
            
            <div class="mb-6">
                <label class="flex items-center space-x-2 cursor-pointer group">
                    <input type="checkbox" name="reset_device" value="1" class="w-4 h-4 rounded border-brand-border text-brand-primary focus:ring-brand-primary/20 bg-brand-bg/50 accent-brand-primary">
                    <span class="text-sm font-medium text-brand-muted group-hover:text-brand-text transition-colors">Reset Device ID (Unbind key from current device)</span>
                </label>
            </div>
            
            <div class="flex justify-end space-x-3 mt-6">
                <button type="button" onclick="closeLicEditModal()" class="px-5 py-2 text-sm font-bold text-brand-muted hover:text-brand-text transition-colors">Cancel</button>
                <button type="submit" class="px-5 py-2 bg-brand-primary text-white text-sm font-bold rounded-xl hover:opacity-90 active:scale-[0.98] transition-all shadow-glow">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
function openEditModal(btn) {
    var modal = document.getElementById('licEditModal');
    document.getElementById('edit_key_id').value = btn.dataset.id;
    document.getElementById('edit_key_string').value = btn.dataset.key;
    document.getElementById('edit_duration').value = btn.dataset.duration;
    document.getElementById('edit_duration_type').value = btn.dataset.type;
    document.getElementById('edit_price').value = btn.dataset.price;
    modal.style.display = 'flex';
}

function closeLicEditModal() {
    document.getElementById('licEditModal').style.display = 'none';
}
</script>

<?php
require_once 'includes/footer.php';
?>