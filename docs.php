<?php
// docs.php - API Integration Documentation Page
require_once 'includes/auth.php';
requireLogin();

$page_title = 'API Integration Docs';
include 'includes/header.php';

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
?>

<div class="space-y-8 max-w-5xl mx-auto pb-12">
    <!-- Header banner -->
    <div class="relative rounded-3xl bg-gradient-to-r from-brand-primary/10 via-brand-secondary/5 to-transparent border border-brand-border/60 p-6 sm:p-8 overflow-hidden backdrop-blur-xl">
        <div class="relative z-10 flex flex-col md:flex-row md:items-center justify-between gap-6">
            <div class="space-y-2">
                <span class="px-3 py-1 rounded-full text-xs font-semibold bg-brand-primary/10 text-brand-primary border border-brand-primary/20">Developer SDK</span>
                <h2 class="text-2xl sm:text-3xl font-extrabold tracking-tight bg-gradient-to-r from-brand-primary to-brand-secondary bg-clip-text text-transparent">API Integration Guide</h2>
                <p class="text-xs sm:text-sm text-brand-muted max-w-xl">Learn how to easily validate license keys and implement hardware security (Device ID lock) directly into your game mods, Android applications, or scripts.</p>
            </div>
            <div class="flex flex-col sm:flex-row items-center gap-3">
                <a href="download_docs" class="px-4 py-3 rounded-2xl bg-gradient-to-r from-brand-primary to-brand-secondary hover:opacity-90 active:scale-[0.98] transition-all duration-300 text-xs font-bold text-white shadow-glow flex items-center space-x-2">
                    <i class="fa-brands fa-markdown text-lg"></i>
                    <span>Download docs.md for AI</span>
                </a>
                <div class="flex items-center space-x-3 bg-brand-surface/40 border border-brand-border px-4 py-3 rounded-2xl">
                    <i class="fa-solid fa-circle-check text-brand-primary text-lg"></i>
                    <div class="text-left">
                        <p class="text-xs font-semibold text-brand-muted">Endpoint Status</p>
                        <p class="text-xs font-bold text-brand-primary">Online & Active</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 1. HTTP Specs -->
    <div class="bg-brand-surface border border-brand-border rounded-xl p-6 space-y-6">
        <h3 class="text-lg font-bold text-brand-text flex items-center space-x-2">
            <i class="fa-solid fa-circle-nodes text-brand-primary"></i>
            <span>1. HTTP Request Specifications</span>
        </h3>

        <!-- Method and URL -->
        <div class="space-y-2">
            <p class="text-xs font-bold text-brand-muted">ENDPOINT URL</p>
            <div class="flex items-center justify-between bg-slate-950 border border-slate-800 rounded-xl p-3 select-all">
                <div class="flex items-center space-x-2 truncate">
                    <span class="px-2 py-0.5 rounded-lg text-[10px] font-bold bg-brand-primary text-white">GET</span>
                    <code class="text-xs text-cyan-400 font-bold tracking-wide truncate"><?php echo htmlspecialchars($api_url); ?></code>
                </div>
            </div>
        </div>

        <!-- Parameters Table -->
        <div class="space-y-3">
            <p class="text-xs font-bold text-brand-muted">QUERY PARAMETERS</p>
            <div class="overflow-x-auto border border-brand-border rounded-xl">
                <table class="w-full text-left border-collapse text-xs">
                    <thead>
                        <tr class="bg-brand-surface/60 border-b border-brand-border">
                            <th class="p-3 font-semibold text-brand-text">Parameter</th>
                            <th class="p-3 font-semibold text-brand-text">Type</th>
                            <th class="p-3 font-semibold text-brand-text">Required</th>
                            <th class="p-3 font-semibold text-brand-text">Description</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-brand-border/40">
                        <tr>
                            <td class="p-3 font-mono font-bold text-brand-primary">key</td>
                            <td class="p-3 text-brand-muted">String</td>
                            <td class="p-3 text-brand-error font-semibold">Yes</td>
                            <td class="p-3 text-brand-muted">The license key to validate.</td>
                        </tr>
                        <tr>
                            <td class="p-3 font-mono font-bold text-brand-primary">device_id</td>
                            <td class="p-3 text-brand-muted">String</td>
                            <td class="p-3 text-brand-muted font-semibold">No (Recommended)</td>
                            <td class="p-3 text-brand-muted">The unique hardware fingerprint to lock the key to a specific device.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- 2. Response Payloads -->
    <div class="bg-brand-surface border border-brand-border rounded-xl p-6 space-y-6">
        <div class="flex items-center justify-between flex-wrap gap-4 border-b border-brand-border pb-4">
            <h3 class="text-lg font-bold text-brand-text flex items-center space-x-2">
                <i class="fa-solid fa-envelope-open-text text-brand-primary"></i>
                <span>2. Response Payloads & Errors</span>
            </h3>
            
            <div class="flex bg-brand-bg/50 border border-brand-border p-1 rounded-xl flex-wrap" role="tablist">
                <button onclick="switchPayload('success')" id="payload-btn-success" class="px-2.5 py-1 rounded-lg text-[11px] font-semibold text-white bg-brand-primary transition-all duration-300">Success</button>
                <button onclick="switchPayload('invalid')" id="payload-btn-invalid" class="px-2.5 py-1 rounded-lg text-[11px] font-semibold text-brand-muted hover:text-brand-text transition-all duration-300">Invalid Key</button>
                <button onclick="switchPayload('disabled')" id="payload-btn-disabled" class="px-2.5 py-1 rounded-lg text-[11px] font-semibold text-brand-muted hover:text-brand-text transition-all duration-300">Mod Disabled</button>
                <button onclick="switchPayload('expired')" id="payload-btn-expired" class="px-2.5 py-1 rounded-lg text-[11px] font-semibold text-brand-muted hover:text-brand-text transition-all duration-300">Expired</button>
                <button onclick="switchPayload('hwid')" id="payload-btn-hwid" class="px-2.5 py-1 rounded-lg text-[11px] font-semibold text-brand-muted hover:text-brand-text transition-all duration-300">HWID Locked</button>
            </div>
        </div>

        <!-- Success payload -->
        <div id="payload-content-success" class="payload-pane space-y-2">
            <p class="text-xs font-bold text-emerald-400">License key is active and successfully validated</p>
            <pre class="bg-slate-950 border border-slate-800 rounded-xl p-4 overflow-x-auto text-xs font-mono text-emerald-400 select-all">{
  "status": "success",
  "message": "License key validated successfully",
  "data": {
    "mod_name": "Premium Aim Assist",
    "duration": "30 days",
    "sold_at": "2026-07-03 15:20:28",
    "device_id": "hwid_9a38f82f8a9..."
  }
}</pre>
        </div>

        <!-- Invalid Key payload -->
        <div id="payload-content-invalid" class="payload-pane hidden space-y-2">
            <p class="text-xs font-bold text-red-400">Key does not exist in the database</p>
            <pre class="bg-slate-950 border border-slate-800 rounded-xl p-4 overflow-x-auto text-xs font-mono text-red-400 select-all">{
  "status": "error",
  "message": "Invalid license key"
}</pre>
        </div>

        <!-- Mod Disabled payload -->
        <div id="payload-content-disabled" class="payload-pane hidden space-y-2">
            <p class="text-xs font-bold text-red-400">The parent mod's status is set to inactive</p>
            <pre class="bg-slate-950 border border-slate-800 rounded-xl p-4 overflow-x-auto text-xs font-mono text-red-400 select-all">{
  "status": "error",
  "message": "This mod is currently disabled"
}</pre>
        </div>

        <!-- Expired payload -->
        <div id="payload-content-expired" class="payload-pane hidden space-y-2">
            <p class="text-xs font-bold text-red-400">License key has passed its time duration limit</p>
            <pre class="bg-slate-950 border border-slate-800 rounded-xl p-4 overflow-x-auto text-xs font-mono text-red-400 select-all">{
  "status": "error",
  "message": "License key has expired"
}</pre>
        </div>

        <!-- HWID Locked payload -->
        <div id="payload-content-hwid" class="payload-pane hidden space-y-2">
            <p class="text-xs font-bold text-red-400">License key is bound to another device footprint</p>
            <pre class="bg-slate-950 border border-slate-800 rounded-xl p-4 overflow-x-auto text-xs font-mono text-red-400 select-all">{
  "status": "error",
  "message": "License key is locked to another device"
}</pre>
        </div>
    </div>

    <!-- 3. Code Snippets -->
    <div class="bg-brand-surface border border-brand-border rounded-xl p-6 space-y-6">
        <div class="flex items-center justify-between flex-wrap gap-4 border-b border-brand-border pb-4">
            <h3 class="text-lg font-bold text-brand-text flex items-center space-x-2">
                <i class="fa-solid fa-code text-brand-primary"></i>
                <span>3. Code Integration Examples</span>
            </h3>
            <!-- Tabs buttons -->
            <div class="flex bg-brand-bg/50 border border-brand-border p-1 rounded-xl flex-wrap" role="tablist">
                <button onclick="switchTab('cpp')" id="tab-btn-cpp" class="px-3 py-1.5 rounded-lg text-xs font-semibold text-white bg-brand-primary transition-all duration-300">C++ (IMGUI)</button>
                <button onclick="switchTab('android')" id="tab-btn-android" class="px-3 py-1.5 rounded-lg text-xs font-semibold text-brand-muted hover:text-brand-text transition-all duration-300">Android (Java)</button>
                <button onclick="switchTab('python')" id="tab-btn-python" class="px-3 py-1.5 rounded-lg text-xs font-semibold text-brand-muted hover:text-brand-text transition-all duration-300">Python</button>
                <button onclick="switchTab('csharp')" id="tab-btn-csharp" class="px-3 py-1.5 rounded-lg text-xs font-semibold text-brand-muted hover:text-brand-text transition-all duration-300">C#</button>
            </div>
        </div>

        <!-- C++ Snippet -->
        <div id="tab-content-cpp" class="tab-pane space-y-3">
            <p class="text-xs text-brand-muted"><i class="fa-solid fa-circle-info text-brand-primary mr-1"></i> Standard DLL key verification snippet for Windows/PC IMGUI projects using libcurl.</p>
            <pre class="bg-slate-950 border border-slate-800 rounded-xl p-4 overflow-x-auto text-[11px] font-mono text-emerald-400 select-all">#include &lt;iostream&gt;
#include &lt;string&gt;
#include &lt;curl/curl.h&gt;

size_t WriteCallback(void* contents, size_t size, size_t nmemb, void* userp) {
    ((std::string*)userp)->append((char*)contents, size * nmemb);
    return size * nmemb;
}

bool validateLicense(const std::string&amp; key, const std::string&amp; device_id) {
    CURL* curl = curl_easy_init();
    if (!curl) return false;

    std::string url = "<?php echo htmlspecialchars($api_url); ?>?key=" + key + "&amp;device_id=" + device_id;
    std::string readBuffer;

    curl_easy_setopt(curl, CURLOPT_URL, url.c_str());
    curl_easy_setopt(curl, CURLOPT_WRITEFUNCTION, WriteCallback);
    curl_easy_setopt(curl, CURLOPT_WRITEDATA, &amp;readBuffer);
    
    CURLcode res = curl_easy_perform(curl);
    curl_easy_cleanup(curl);

    if (res == CURLE_OK) {
        // Returns true if validation response status is success
        return readBuffer.find("\"status\":\"success\"") != std::string::npos;
    }
    return false;
}</pre>
        </div>

        <!-- Android Java Snippet -->
        <div id="tab-content-android" class="tab-pane hidden space-y-3">
            <p class="text-xs text-brand-muted"><i class="fa-solid fa-circle-info text-brand-primary mr-1"></i> Native Android verification module using asynchronous threads and HttpURLConnection.</p>
            <pre class="bg-slate-950 border border-slate-800 rounded-xl p-4 overflow-x-auto text-[11px] font-mono text-emerald-400 select-all">import java.io.BufferedReader;
import java.io.InputStreamReader;
import java.net.HttpURLConnection;
import java.net.URL;

public class LicenseValidator {
    public interface ValidationCallback {
        void onResponse(boolean isValid, String rawJson);
    }

    public static void validateKey(final String key, final String deviceId, final ValidationCallback callback) {
        new Thread(new Runnable() {
            @Override
            public void run() {
                try {
                    String urlString = "<?php echo htmlspecialchars($api_url); ?>?key=" + key + "&amp;device_id=" + deviceId;
                    URL url = new URL(urlString);
                    HttpURLConnection conn = (HttpURLConnection) url.openConnection();
                    conn.setRequestMethod("GET");
                    conn.setConnectTimeout(5000);
                    conn.setReadTimeout(5000);
                    
                    int responseCode = conn.getResponseCode();
                    if (responseCode == HttpURLConnection.HTTP_OK) {
                        BufferedReader in = new BufferedReader(new InputStreamReader(conn.getInputStream()));
                        StringBuilder response = new StringBuilder();
                        String line;
                        while ((line = in.readLine()) != null) {
                            response.append(line);
                        }
                        in.close();
                        
                        boolean isValid = response.toString().contains("\"status\":\"success\"");
                        callback.onResponse(isValid, response.toString());
                    } else {
                        callback.onResponse(false, "HTTP error: " + responseCode);
                    }
                } catch (Exception e) {
                    callback.onResponse(false, "Connection error: " + e.getMessage());
                }
            }
        }).start();
    }
}</pre>
        </div>

        <!-- Python Snippet -->
        <div id="tab-content-python" class="tab-pane hidden space-y-3">
            <pre class="bg-slate-950 border border-slate-800 rounded-xl p-4 overflow-x-auto text-[11px] font-mono text-emerald-400 select-all">import requests

def validate_license(key: str, device_id: str) -> bool:
    api_url = "<?php echo htmlspecialchars($api_url); ?>"
    params = {
        "key": key,
        "device_id": device_id
    }
    try:
        response = requests.get(api_url, params=params)
        data = response.json()
        if data.get("status") == "success":
            print(f"Welcome to {data.get('data', {}).get('mod_name')}!")
            return True
        else:
            print(f"Error: {data.get('message')}")
            return False
    except Exception as e:
        return False</pre>
        </div>

        <!-- C# Snippet -->
        <div id="tab-content-csharp" class="tab-pane hidden space-y-3">
            <pre class="bg-slate-950 border border-slate-800 rounded-xl p-4 overflow-x-auto text-[11px] font-mono text-emerald-400 select-all">using System;
using System.Net.Http;
using System.Threading.Tasks;

public class LicenseValidator
{
    private static readonly HttpClient client = new HttpClient();

    public static async Task&lt;bool&gt; ValidateKey(string key, string deviceId)
    {
        string url = $"<?php echo htmlspecialchars($api_url); ?>?key={key}&amp;device_id={deviceId}";
        try
        {
            string response = await client.GetStringAsync(url);
            return response.Contains("\"status\":\"success\"");
        }
        catch
        {
            return false;
        }
    }
}</pre>
        </div>
    </div>
    <!-- 4. Admin API -->
    <div class="bg-brand-surface border border-brand-border rounded-xl p-6 space-y-6">
        <h3 class="text-lg font-bold text-brand-text flex items-center space-x-2">
            <i class="fa-solid fa-server text-brand-secondary"></i>
            <span>4. Admin Remote API Specifications</span>
        </h3>
        
        <p class="text-xs text-brand-muted">Automate your keys management through remote requests. Requires the <strong>Admin API Key</strong> configured in Settings.</p>
        
        <div class="space-y-2">
            <p class="text-xs font-bold text-brand-muted">ENDPOINT URL</p>
            <div class="flex items-center justify-between bg-slate-950 border border-slate-800 rounded-xl p-3 select-all">
                <div class="flex items-center space-x-2 truncate">
                    <span class="px-2 py-0.5 rounded-lg text-[10px] font-bold bg-brand-primary text-white">POST</span>
                    <code class="text-xs text-brand-secondary font-bold tracking-wide truncate"><?php echo htmlspecialchars($protocol . $domain . $basePath . '/api'); ?></code>
                </div>
            </div>
        </div>

        <div class="space-y-3 mt-4">
            <p class="text-xs font-bold text-brand-muted">FORM PARAMETERS (POST DATA)</p>
            <div class="overflow-x-auto border border-brand-border rounded-xl">
                <table class="w-full text-left border-collapse text-xs">
                    <thead>
                        <tr class="bg-brand-surface/60 border-b border-brand-border">
                            <th class="p-3 font-semibold text-brand-text">Parameter</th>
                            <th class="p-3 font-semibold text-brand-text">Required</th>
                            <th class="p-3 font-semibold text-brand-text">Description</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-brand-border/40">
                        <tr>
                            <td class="p-3 font-mono font-bold text-brand-primary">api_key</td>
                            <td class="p-3 text-brand-error font-semibold">Yes</td>
                            <td class="p-3 text-brand-muted">Your secret Admin API Key.</td>
                        </tr>
                        <tr>
                            <td class="p-3 font-mono font-bold text-brand-primary">action</td>
                            <td class="p-3 text-brand-error font-semibold">Yes</td>
                            <td class="p-3 text-brand-muted">Action to perform: <code class="bg-brand-bg px-1 rounded">block</code>, <code class="bg-brand-bg px-1 rounded">unblock</code>, <code class="bg-brand-bg px-1 rounded">expire</code>, <code class="bg-brand-bg px-1 rounded">delete</code>, or <code class="bg-brand-bg px-1 rounded">edit</code>.</td>
                        </tr>
                        <tr>
                            <td class="p-3 font-mono font-bold text-brand-primary">key_id</td>
                            <td class="p-3 text-brand-error font-semibold">Yes</td>
                            <td class="p-3 text-brand-muted">The internal Database ID of the key (NOT the key string itself).</td>
                        </tr>
                        <tr>
                            <td class="p-3 font-mono font-bold text-brand-primary">duration</td>
                            <td class="p-3 text-brand-muted font-semibold">If editing</td>
                            <td class="p-3 text-brand-muted">Used with <code class="bg-brand-bg px-1 rounded">action=edit</code>. E.g. 30</td>
                        </tr>
                        <tr>
                            <td class="p-3 font-mono font-bold text-brand-primary">duration_type</td>
                            <td class="p-3 text-brand-muted font-semibold">If editing</td>
                            <td class="p-3 text-brand-muted">Used with <code class="bg-brand-bg px-1 rounded">action=edit</code>. (hours/days/months)</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
function switchTab(lang) {
    // Hide all tab content
    document.querySelectorAll('.tab-pane').forEach(el => el.classList.add('hidden'));
    
    // Deactivate all tab buttons
    document.querySelectorAll('[id^="tab-btn-"]').forEach(btn => {
        btn.classList.remove('bg-brand-primary', 'text-white');
        btn.classList.add('text-brand-muted', 'hover:text-brand-text');
    });

    // Show selected content and activate button
    document.getElementById('tab-content-' + lang).classList.remove('hidden');
    const activeBtn = document.getElementById('tab-btn-' + lang);
    activeBtn.classList.remove('text-brand-muted', 'hover:text-brand-text');
    activeBtn.classList.add('bg-brand-primary', 'text-white');
}

function switchPayload(payload) {
    // Hide all payload responses
    document.querySelectorAll('.payload-pane').forEach(el => el.classList.add('hidden'));
    
    // Deactivate all payload buttons
    document.querySelectorAll('[id^="payload-btn-"]').forEach(btn => {
        btn.classList.remove('bg-brand-primary', 'text-white');
        btn.classList.add('text-brand-muted', 'hover:text-brand-text');
    });

    // Show selected content and activate button
    document.getElementById('payload-content-' + payload).classList.remove('hidden');
    const activeBtn = document.getElementById('payload-btn-' + payload);
    activeBtn.classList.remove('text-brand-muted', 'hover:text-brand-text');
    activeBtn.classList.add('bg-brand-primary', 'text-white');
}
</script>

<?php include 'includes/footer.php'; ?>
