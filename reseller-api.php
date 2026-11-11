<?php
require_once 'includes/auth.php';
require_once 'includes/functions.php';
require_once 'includes/reseller.php';
require_once 'includes/reseller_provider.php';

requireLogin();

$success = '';
$error  = '';

$pdo = getDBConnection();

// Make sure reseller columns exist
try { ensureResellerProviderColumns(); } catch (Exception $e) {}

$user = getUserData();
$userId = (int)$user['id'];

// Only resellers can access this page
if (!isUserReseller($userId)) {
    // Not a reseller — redirect to dashboard with message
    header('Location: dashboard.php?msg=not_reseller');
    exit;
}

// Sync total spend (in case)
try { syncUserTotalSpent($userId); } catch (Exception $e) {}

// Refresh user data after sync
$user = getUserData();

// Handle POST actions
if ($_POST) {
    verify_csrf();

    if (isset($_POST['generate_key']) || isset($_POST['regenerate_key'])) {
        $newKey = generateResellerApiKey($userId);
        if ($newKey) {
            // Make sure API is enabled for this reseller
            setResellerApiEnabled($userId, 1);
            $success = isset($_POST['regenerate_key'])
                ? 'API Key regenerated successfully! The old key has been revoked.'
                : 'API Key generated successfully! You can now connect your website.';
            $user = getUserData();
        } else {
            $error = 'Failed to generate API Key. Please try again.';
        }
    } elseif (isset($_POST['toggle_api'])) {
        $newState = isset($_POST['enable_api']) ? 1 : 0;
        setResellerApiEnabled($userId, $newState);
        $success = $newState ? 'Your Reseller API is now ENABLED.' : 'Your Reseller API is now DISABLED.';
        $user = getUserData();
        $apiEnabled = !empty($user['reseller_api_enabled']);
    }
}

// Load config + catalog for display
$providerCfg     = getResellerProviderConfig();
$masterKey       = $providerCfg['master_key'] ?? '';
$apiKey          = $user['reseller_api_key'] ?? '';
$apiEnabled      = !empty($user['reseller_api_enabled']);
$endpointUrl     = getResellerEndpointUrl();
$productCatalog  = getResellerProductCatalog();
$totalSpent      = getUserTotalSpent($userId);
$balance         = (float)($user['balance'] ?? 0);

// Build the PHP integration code snippet (same-to-same as keysmaster example)
$phpExample = '<?php' . "\n"
    . '// ===== Connect to SG PANEL Reseller API =====' . "\n"
    . '$api_key    = "' . htmlspecialchars($apiKey ?: 'YOUR_API_KEY') . '";' . "\n"
    . '$master_key = "' . htmlspecialchars($masterKey ?: 'YOUR_MASTER_KEY') . '";' . "\n"
    . '$endpoint   = "' . htmlspecialchars($endpointUrl) . '";' . "\n\n"
    . '// --- Buy a key ---' . "\n"
    . '$ch = curl_init($endpoint);' . "\n"
    . 'curl_setopt_array($ch, [' . "\n"
    . '    CURLOPT_RETURNTRANSFER => true,' . "\n"
    . '    CURLOPT_POST           => true,' . "\n"
    . '    CURLOPT_HTTPHEADER     => ["x-master-key: " . $master_key],' . "\n"
    . '    CURLOPT_POSTFIELDS     => http_build_query([' . "\n"
    . '        "api_key"    => $api_key,' . "\n"
    . '        "action"     => "buy",' . "\n"
    . '        "product_id" => "11",       // PID from product reference table' . "\n"
    . '        "duration"   => "1 DaYs",   // exact duration string' . "\n"
    . '        "android_id" => "DEVICE_ID_OR_EMPTY",' . "\n"
    . '    ]),' . "\n"
    . ']);' . "\n"
    . '$response = curl_exec($ch);' . "\n"
    . 'curl_close($ch);' . "\n\n"
    . '$result = json_decode($response, true);' . "\n"
    . 'if ($result && $result["status"] === "success") {' . "\n"
    . '    $key = $result["msg"];   // the license key' . "\n"
    . '    echo "Key: " . $key;' . "\n"
    . '} else {' . "\n"
    . '    echo "Error: " . ($result["msg"] ?? "Unknown error");' . "\n"
    . '}' . "\n";

$balanceExample = '<?php' . "\n"
    . '// --- Check your balance ---' . "\n"
    . '$ch = curl_init($endpoint);' . "\n"
    . 'curl_setopt_array($ch, [' . "\n"
    . '    CURLOPT_RETURNTRANSFER => true,' . "\n"
    . '    CURLOPT_POST           => true,' . "\n"
    . '    CURLOPT_HTTPHEADER     => ["x-master-key: " . $master_key],' . "\n"
    . '    CURLOPT_POSTFIELDS     => http_build_query([' . "\n"
    . '        "api_key" => $api_key,' . "\n"
    . '        "action"  => "balance",' . "\n"
    . '    ]),' . "\n"
    . ']);' . "\n"
    . '$response = curl_exec($ch);' . "\n"
    . 'curl_close($ch);' . "\n"
    . '$result = json_decode($response, true);' . "\n"
    . '// $result = {"status":"success","balance":"450.00","total_spent":"1200.00"}' . "\n";

$productsExample = '<?php' . "\n"
    . '// --- List all products ---' . "\n"
    . '$ch = curl_init($endpoint);' . "\n"
    . 'curl_setopt_array($ch, [' . "\n"
    . '    CURLOPT_RETURNTRANSFER => true,' . "\n"
    . '    CURLOPT_POST           => true,' . "\n"
    . '    CURLOPT_HTTPHEADER     => ["x-master-key: " . $master_key],' . "\n"
    . '    CURLOPT_POSTFIELDS     => http_build_query([' . "\n"
    . '        "api_key" => $api_key,' . "\n"
    . '        "action"  => "products",' . "\n"
    . '    ]),' . "\n"
    . ']);' . "\n"
    . '$response = curl_exec($ch);' . "\n"
    . 'curl_close($ch);' . "\n"
    . '$result = json_decode($response, true);' . "\n"
    . '// $result["products"] = array of {pid, product_name, requires_device_id, durations[]}' . "\n";

$page_title = "Reseller API - " . SITE_NAME . " " . SITE_SUBTITLE;
require_once 'includes/header.php';
?>

<!-- ===== Reseller API Page ===== -->
<div class="max-w-6xl mx-auto space-y-5 animate-fade-in">

    <!-- Header -->
    <div class="relative rounded-2xl p-5 bg-neutral-900 border-2 border-emerald-400/70 shadow-[0_0_20px_rgba(16,185,129,0.25)] dark:bg-neutral-900">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <h2 class="text-2xl font-bold tracking-tight text-white" style="text-shadow:0 0 15px rgba(16,185,129,0.5);">
                    <i class="fa-solid fa-code mr-2 text-emerald-400"></i>Reseller API
                </h2>
                <p class="text-sm text-emerald-300/70 mt-1">Connect your website to <?php echo htmlspecialchars(SITE_NAME); ?> and sell keys through our API.</p>
            </div>
            <span class="text-xs font-bold text-emerald-300 bg-emerald-500/10 px-4 py-2 rounded-full border border-emerald-400/30 shadow-[0_0_15px_rgba(16,185,129,0.15)]">
                <i class="fa-solid fa-handshake mr-1"></i>
                Reseller: <?php echo htmlspecialchars($user['username']); ?>
            </span>
        </div>
    </div>

    <!-- Alerts -->
    <?php if ($success): ?>
        <div class="relative rounded-xl bg-green-900/20 border-2 border-green-400/50 shadow-[0_0_20px_rgba(34,197,94,0.2)] text-green-400 text-sm p-4 flex items-center space-x-2.5">
            <i class="fa-solid fa-circle-check text-base"></i>
            <span><?php echo htmlspecialchars($success); ?></span>
        </div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="relative rounded-xl bg-red-900/20 border-2 border-red-500/50 shadow-[0_0_20px_rgba(239,68,68,0.2)] text-red-400 text-sm p-4 flex items-center space-x-2.5">
            <i class="fa-solid fa-circle-exclamation text-base"></i>
            <span><?php echo htmlspecialchars($error); ?></span>
        </div>
    <?php endif; ?>

    <!-- ===== API Credentials Card ===== -->
    <div class="rounded-2xl bg-neutral-900/80 border border-neutral-700/60 p-6">
        <h3 class="text-base font-bold text-white mb-1 flex items-center space-x-2">
            <i class="fa-solid fa-key text-emerald-400"></i>
            <span>API &amp; Password (Credentials)</span>
        </h3>
        <p class="text-[11px] text-neutral-500 mb-5">Your unique API Key identifies your account. The Master Key is shared by all resellers and must be sent as the <code class="text-emerald-400">x-master-key</code> header.</p>

        <div class="space-y-5">
            <!-- API Key -->
            <div>
                <label class="block text-[10px] font-bold uppercase tracking-wider text-neutral-500 mb-1.5">Your API Key</label>
                <?php if (!empty($apiKey)): ?>
                    <div class="flex flex-col sm:flex-row gap-2">
                        <input type="text" readonly value="<?php echo htmlspecialchars($apiKey); ?>" id="apiKeyField"
                               class="flex-1 px-4 py-3 bg-neutral-950 border border-neutral-700 rounded-xl text-sm font-mono text-emerald-400 focus:outline-none">
                        <button type="button" onclick="copyText('apiKeyField')" class="px-4 py-3 bg-neutral-800 border border-neutral-700 text-neutral-300 rounded-xl text-xs font-bold hover:bg-neutral-700 transition-colors">
                            <i class="fa-solid fa-copy mr-1"></i>Copy
                        </button>
                    </div>
                    <div class="flex flex-wrap gap-2 mt-3">
                        <form method="POST" class="inline" onsubmit="return confirm('Regenerate API Key? The old key will stop working immediately and any connected site must update it.')">
                            <?php echo csrf_field(); ?>
                            <button type="submit" name="regenerate_key" class="px-4 py-2 bg-amber-500/20 border border-amber-500/40 text-amber-400 rounded-xl text-xs font-bold hover:bg-amber-500/30 transition-colors">
                                <i class="fa-solid fa-rotate mr-1"></i>Regenerate API Key
                            </button>
                        </form>
                        <?php if (!$apiEnabled): ?>
                        <form method="POST" class="inline">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="toggle_api" value="1">
                            <input type="hidden" name="enable_api" value="1">
                            <button type="submit" class="px-4 py-2 bg-emerald-500/20 border border-emerald-500/40 text-emerald-400 rounded-xl text-xs font-bold hover:bg-emerald-500/30 transition-colors">
                                <i class="fa-solid fa-toggle-on mr-1"></i>Enable API
                            </button>
                        </form>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="bg-neutral-950 border border-dashed border-neutral-700 rounded-xl p-6 text-center">
                        <i class="fa-solid fa-key text-3xl text-neutral-600 mb-2 block"></i>
                        <p class="text-sm text-neutral-400 mb-4">You don't have an API Key yet. Generate one to start connecting websites.</p>
                        <form method="POST" class="inline">
                            <?php echo csrf_field(); ?>
                            <button type="submit" name="generate_key" class="px-6 py-3 bg-gradient-to-r from-emerald-500 to-sky-500 text-white rounded-xl text-sm font-bold hover:opacity-90 transition-opacity shadow-lg">
                                <i class="fa-solid fa-wand-magic-sparkles mr-2"></i>Generate API Key
                            </button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Master Key -->
            <div>
                <label class="block text-[10px] font-bold uppercase tracking-wider text-neutral-500 mb-1.5">Master Key (x-master-key header)</label>
                <div class="flex flex-col sm:flex-row gap-2">
                    <input type="text" readonly value="<?php echo htmlspecialchars($masterKey); ?>" id="masterKeyField"
                           class="flex-1 px-4 py-3 bg-neutral-950 border border-neutral-700 rounded-xl text-sm font-mono text-sky-400 focus:outline-none">
                    <button type="button" onclick="copyText('masterKeyField')" class="px-4 py-3 bg-neutral-800 border border-neutral-700 text-neutral-300 rounded-xl text-xs font-bold hover:bg-neutral-700 transition-colors">
                        <i class="fa-solid fa-copy mr-1"></i>Copy
                    </button>
                </div>
                <p class="text-[10px] text-neutral-500 mt-1.5">This is the <b>same</b> for all resellers. Send it in the <code class="text-sky-400">x-master-key</code> HTTP header on every request.</p>
            </div>

            <!-- Endpoint URL -->
            <div>
                <label class="block text-[10px] font-bold uppercase tracking-wider text-neutral-500 mb-1.5">API Endpoint URL</label>
                <div class="flex flex-col sm:flex-row gap-2">
                    <input type="text" readonly value="<?php echo htmlspecialchars($endpointUrl); ?>" id="endpointField"
                           class="flex-1 px-4 py-3 bg-neutral-950 border border-neutral-700 rounded-xl text-sm font-mono text-cyan-400 focus:outline-none">
                    <button type="button" onclick="copyText('endpointField')" class="px-4 py-3 bg-neutral-800 border border-neutral-700 text-neutral-300 rounded-xl text-xs font-bold hover:bg-neutral-700 transition-colors">
                        <i class="fa-solid fa-copy mr-1"></i>Copy
                    </button>
                </div>
                <p class="text-[10px] text-neutral-500 mt-1.5">Send all POST requests to this URL.</p>
            </div>
        </div>
    </div>

    <!-- ===== Integration Code Card ===== -->
    <div class="rounded-2xl bg-neutral-900/80 border border-neutral-700/60 p-6">
        <h3 class="text-base font-bold text-white mb-1 flex items-center space-x-2">
            <i class="fa-solid fa-plug text-cyan-400"></i>
            <span>Integration Code (PHP + cURL)</span>
        </h3>
        <p class="text-[11px] text-neutral-500 mb-4">Copy this ready-to-use PHP code into your website. It connects to our API exactly like the keysmaster example.</p>

        <!-- Tabs -->
        <div class="flex flex-wrap gap-2 mb-3" id="codeTabs">
            <button type="button" onclick="showCodeTab('buy')" id="tab-buy" class="px-3 py-1.5 rounded-lg text-xs font-bold bg-cyan-500/20 border border-cyan-500/40 text-cyan-300">Buy Key</button>
            <button type="button" onclick="showCodeTab('balance')" id="tab-balance" class="px-3 py-1.5 rounded-lg text-xs font-bold bg-neutral-800 border border-neutral-700 text-neutral-400">Check Balance</button>
            <button type="button" onclick="showCodeTab('products')" id="tab-products" class="px-3 py-1.5 rounded-lg text-xs font-bold bg-neutral-800 border border-neutral-700 text-neutral-400">List Products</button>
        </div>

        <div id="code-buy" class="code-block">
            <pre class="bg-neutral-950 border border-neutral-800 rounded-xl p-4 overflow-x-auto text-[11px] leading-relaxed text-neutral-300 font-mono"><code><?php echo htmlspecialchars($phpExample); ?></code></pre>
        </div>
        <div id="code-balance" class="code-block hidden">
            <pre class="bg-neutral-950 border border-neutral-800 rounded-xl p-4 overflow-x-auto text-[11px] leading-relaxed text-neutral-300 font-mono"><code><?php echo htmlspecialchars($balanceExample); ?></code></pre>
        </div>
        <div id="code-products" class="code-block hidden">
            <pre class="bg-neutral-950 border border-neutral-800 rounded-xl p-4 overflow-x-auto text-[11px] leading-relaxed text-neutral-300 font-mono"><code><?php echo htmlspecialchars($productsExample); ?></code></pre>
        </div>
    </div>

    <!-- ===== API Reference Card ===== -->
    <div class="rounded-2xl bg-neutral-900/80 border border-neutral-700/60 p-6">
        <h3 class="text-base font-bold text-white mb-1 flex items-center space-x-2">
            <i class="fa-solid fa-book text-violet-400"></i>
            <span>API Reference</span>
        </h3>
        <p class="text-[11px] text-neutral-500 mb-4">All requests are <b>POST</b> to the endpoint URL. Always include the <code class="text-sky-400">x-master-key</code> header.</p>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-[10px] uppercase tracking-wider text-neutral-500 border-b border-neutral-700">
                    <tr>
                        <th class="text-left py-2 pr-4">Field</th>
                        <th class="text-left py-2 pr-4">Required</th>
                        <th class="text-left py-2 pr-4">Description</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-800 text-neutral-300 text-xs">
                    <tr><td class="py-2 pr-4 font-mono text-cyan-400">api_key</td><td class="py-2 pr-4">Yes</td><td class="py-2 pr-4">Your unique API Key (shown above)</td></tr>
                    <tr><td class="py-2 pr-4 font-mono text-cyan-400">action</td><td class="py-2 pr-4">Yes</td><td class="py-2 pr-4"><code>buy</code>, <code>balance</code>, or <code>products</code></td></tr>
                    <tr><td class="py-2 pr-4 font-mono text-cyan-400">product_id</td><td class="py-2 pr-4">buy only</td><td class="py-2 pr-4">Product PID from the reference table below</td></tr>
                    <tr><td class="py-2 pr-4 font-mono text-cyan-400">duration</td><td class="py-2 pr-4">buy only</td><td class="py-2 pr-4">Exact duration string (e.g. <code>1 DaYs</code>, <code>1 MoNtHs</code>)</td></tr>
                    <tr><td class="py-2 pr-4 font-mono text-cyan-400">android_id</td><td class="py-2 pr-4">if needed</td><td class="py-2 pr-4">Device ID — required only for products that need it (see table)</td></tr>
                </tbody>
            </table>
        </div>

        <div class="mt-4 bg-neutral-950 border border-neutral-800 rounded-xl p-3 text-[11px] text-neutral-400">
            <p class="font-bold text-neutral-300 mb-1">Response format:</p>
            <p class="font-mono text-emerald-400">{ "status": "success", "msg": "THE_LICENSE_KEY" }</p>
            <p class="font-mono text-red-400 mt-1">{ "status": "error", "msg": "Error description" }</p>
        </div>
    </div>

    <!-- ===== Product Reference Table Card ===== -->
    <div class="rounded-2xl bg-neutral-900/80 border border-neutral-700/60 p-6">
        <h3 class="text-base font-bold text-white mb-1 flex items-center space-x-2">
            <i class="fa-solid fa-table-list text-amber-400"></i>
            <span>Product Reference Table</span>
        </h3>
        <p class="text-[11px] text-neutral-500 mb-4">Use these PIDs and duration strings when calling the <code class="text-cyan-400">buy</code> action. Prices shown are your reseller prices.</p>

        <?php if (empty($productCatalog)): ?>
            <div class="bg-neutral-950 border border-dashed border-neutral-700 rounded-xl p-8 text-center">
                <i class="fa-solid fa-box-open text-3xl text-neutral-600 mb-2 block"></i>
                <p class="text-sm text-neutral-400">No products are available yet. The admin needs to add products and plans first.</p>
            </div>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-[10px] uppercase tracking-wider text-neutral-500 border-b border-neutral-700">
                        <tr>
                            <th class="text-left py-2 pr-4">Product Name</th>
                            <th class="text-left py-2 pr-4">PID</th>
                            <th class="text-left py-2 pr-4">Device ID</th>
                            <th class="text-left py-2 pr-4">Duration String</th>
                            <th class="text-right py-2 pr-4">Reseller Price</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-800 text-neutral-300 text-xs">
                        <?php foreach ($productCatalog as $prod): ?>
                            <?php foreach ($prod['durations'] as $dur): ?>
                                <tr class="hover:bg-neutral-800/40">
                                    <td class="py-2 pr-4 font-semibold text-neutral-200"><?php echo htmlspecialchars($prod['product_name']); ?></td>
                                    <td class="py-2 pr-4 font-mono text-cyan-400"><?php echo htmlspecialchars($prod['pid']); ?></td>
                                    <td class="py-2 pr-4">
                                        <?php if ($prod['requires_device_id']): ?>
                                            <span class="text-amber-400 font-semibold">Required</span>
                                        <?php else: ?>
                                            <span class="text-neutral-600">No</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-2 pr-4 font-mono text-violet-400"><?php echo htmlspecialchars($dur['duration']); ?></td>
                                    <td class="py-2 pr-4 text-right font-mono font-bold text-emerald-400">&#8377;<?php echo number_format((float)$dur['price'], 2); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- ===== Important Notes Card ===== -->
    <div class="rounded-2xl bg-amber-900/10 border border-amber-500/30 p-5">
        <h3 class="text-sm font-bold text-amber-400 mb-2 flex items-center space-x-2">
            <i class="fa-solid fa-circle-info"></i>
            <span>Important Notes</span>
        </h3>
        <ul class="text-[11px] text-amber-200/80 space-y-1.5 list-disc list-inside">
            <li>Keys are purchased from <b>your wallet balance</b>. Make sure you have enough balance before calling the buy action.</li>
            <li>The <code>duration</code> string must match <b>exactly</b> (case-sensitive) as shown in the Product Reference Table.</li>
            <li>For products marked "Device ID Required", you must pass a valid <code>android_id</code> — otherwise the request will fail.</li>
            <li>If you regenerate your API Key, update it on all connected websites immediately. The old key stops working instantly.</li>
            <li>The Master Key is shared by all resellers. If the admin changes it, you will be notified — update the <code>x-master-key</code> header on your sites.</li>
            <li>Every successful buy returns the license key in the <code>msg</code> field and deducts the price from your balance.</li>
        </ul>
    </div>

</div>

<script>
function copyText(fieldId) {
    var field = document.getElementById(fieldId);
    field.select();
    field.setSelectionRange(0, 99999);
    navigator.clipboard.writeText(field.value).then(function() {
        var btn = field.parentElement.querySelector('button');
        var orig = btn.innerHTML;
        btn.innerHTML = '<i class="fa-solid fa-check mr-1"></i>Copied!';
        setTimeout(function() { btn.innerHTML = orig; }, 1500);
    });
}

function showCodeTab(tab) {
    // hide all
    var blocks = document.querySelectorAll('.code-block');
    blocks.forEach(function(b) { b.classList.add('hidden'); });
    document.getElementById('code-' + tab).classList.remove('hidden');
    // reset all tabs
    ['buy','balance','products'].forEach(function(t) {
        var tb = document.getElementById('tab-' + t);
        tb.className = 'px-3 py-1.5 rounded-lg text-xs font-bold bg-neutral-800 border border-neutral-700 text-neutral-400';
    });
    // active tab
    var active = document.getElementById('tab-' + tab);
    active.className = 'px-3 py-1.5 rounded-lg text-xs font-bold bg-cyan-500/20 border border-cyan-500/40 text-cyan-300';
}
</script>

<?php
require_once 'includes/footer.php';
?>
