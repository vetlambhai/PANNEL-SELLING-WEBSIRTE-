<?php
/**
 * ============================================================================
 *  SG PANEL (MultiPanelX)  --  Automated Installation Wizard
 *  ---------------------------------------------------------------------------
 *  install.php
 *
 *  WHAT IT DOES
 *    1. Shows a simple setup form asking for:
 *         - Database Hostname  (default: localhost)
 *         - Database Name
 *         - Database Username
 *         - Database Password
 *         - Admin Username
 *         - Admin Password
 *    2. "Test Connection" verifies the credentials without changing anything.
 *    3. "Install Now" performs the full automated installation:
 *         - tests the DB connection (creates the database if it is missing)
 *         - imports master_install.sql  -> creates all tables + initial data
 *         - automatically writes the credentials into config/database.php
 *         - automatically creates / resets the default admin account
 *         - writes config/install.lock so the wizard locks itself
 *    4. Every possible failure (wrong password, missing DB, permission denied,
 *       SQL error, unwritable config, ...) is caught and shown in a friendly,
 *       non-technical UI with an actionable hint.
 *
 *  NOTE: Delete this file (and config/install.lock) once you are done.
 * ============================================================================
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');   // we render our own error UI
ini_set('log_errors', '1');

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

/* -------------------------------------------------------------------------- */
/*  Configuration                                                             */
/* -------------------------------------------------------------------------- */
define('APP_NAME', 'SG PANEL');
define('APP_SUBTITLE', 'MultiPanelX');
define('MASTER_SQL_FILE', __DIR__ . '/master_install.sql');
define('CONFIG_DB_FILE', __DIR__ . '/config/database.php');
define('LOCK_FILE', __DIR__ . '/config/install.lock');

/** Tables created by master_install.sql (used for the optional clean install). */
$KNOWN_TABLES = [
    'license_keys',
    'mods',
    'mod_apks',
    'mod_plans',
    'payment_orders',
    'referral_codes',
    'referral_commissions',
    'transactions',
    'users',
    'user_sessions',
];

/* ========================================================================== */
/*  Helper functions                                                          */
/* ========================================================================== */

/** HTML-escape a value. */
function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/**
 * Split a SQL dump into individual statements.
 *
 * This is a *real* parser (not explode(';')) so it is safe with semicolons
 * inside string literals, backtick identifiers, line comments (-- and #) and
 * block comments.
 */
function splitSqlStatements(string $sql): array
{
    $statements = [];
    $current    = '';
    $len        = strlen($sql);
    $i          = 0;

    $inSingle       = false;
    $inDouble       = false;
    $inBacktick     = false;
    $inLineComment  = false;
    $inBlockComment = false;

    while ($i < $len) {
        $ch   = $sql[$i];
        $next = ($i + 1 < $len) ? $sql[$i + 1] : '';

        /* ---- inside a line comment: skip until end of line ---- */
        if ($inLineComment) {
            if ($ch === "\n") {
                $inLineComment = false;
                $current .= $ch;
            }
            $i++;
            continue;
        }

        /* ---- inside a block comment: skip until the closing marker ---- */
        if ($inBlockComment) {
            if ($ch === '*' && $next === '/') {
                $inBlockComment = false;
                $i += 2;
                continue;
            }
            $i++;
            continue;
        }

        /* ---- inside a single-quoted string ---- */
        if ($inSingle) {
            $current .= $ch;
            if ($ch === '\\') {                       // backslash escape
                if ($next !== '') {
                    $current .= $next;
                    $i += 2;
                    continue;
                }
            } elseif ($ch === "'") {
                if ($next === "'") {                  // doubled quote
                    $current .= $next;
                    $i += 2;
                    continue;
                }
                $inSingle = false;
            }
            $i++;
            continue;
        }

        /* ---- inside a double-quoted string ---- */
        if ($inDouble) {
            $current .= $ch;
            if ($ch === '\\') {
                if ($next !== '') {
                    $current .= $next;
                    $i += 2;
                    continue;
                }
            } elseif ($ch === '"') {
                if ($next === '"') {
                    $current .= $next;
                    $i += 2;
                    continue;
                }
                $inDouble = false;
            }
            $i++;
            continue;
        }

        /* ---- inside a backtick identifier ---- */
        if ($inBacktick) {
            $current .= $ch;
            if ($ch === '`') {
                $inBacktick = false;
            }
            $i++;
            continue;
        }

        /* ---- not inside anything: look for comment / string / delimiter ---- */

        if ($ch === '-' && $next === '-') {           // "-- " line comment
            $after = ($i + 2 < $len) ? $sql[$i + 2] : "\n";
            if ($after === ' ' || $after === "\t" || $after === "\n" || $after === "\r" || $i + 2 >= $len) {
                $inLineComment = true;
                $i += 2;
                continue;
            }
        }

        if ($ch === '#') {                            // "#" line comment
            $inLineComment = true;
            $i++;
            continue;
        }

        if ($ch === '/' && $next === '*') {           // block comment
            $inBlockComment = true;
            $i += 2;
            continue;
        }

        if ($ch === "'") {
            $inSingle = true;
            $current .= $ch;
            $i++;
            continue;
        }

        if ($ch === '"') {
            $inDouble = true;
            $current .= $ch;
            $i++;
            continue;
        }

        if ($ch === '`') {
            $inBacktick = true;
            $current .= $ch;
            $i++;
            continue;
        }

        if ($ch === ';') {                            // end of statement
            $trimmed = trim($current);
            if ($trimmed !== '') {
                $statements[] = $trimmed;
            }
            $current = '';
            $i++;
            continue;
        }

        $current .= $ch;
        $i++;
    }

    $trimmed = trim($current);
    if ($trimmed !== '') {
        $statements[] = $trimmed;
    }

    return $statements;
}

/** Extract the native MySQL error number from a PDOException (0 if unknown). */
function pdoErrorCode(PDOException $e): int
{
    if (isset($e->errorInfo[1]) && $e->errorInfo[1] !== null) {
        return (int) $e->errorInfo[1];
    }
    return 0;
}

/**
 * Build a PDO connection.
 *
 * @param bool $withDb  When false, connect to the server without selecting a
 *                      database (used to auto-create a missing database).
 */
function dbConnect(string $host, string $name, string $user, string $pass, bool $withDb = true): PDO
{
    $dsn = 'mysql:host=' . $host;
    if ($withDb && $name !== '') {
        $dsn .= ';dbname=' . $name;
    }
    $dsn .= ';charset=utf8mb4';

    return new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_TIMEOUT            => 10,
    ]);
}

/** Turn a raw MySQL error into a friendly, actionable hint. */
function friendlyDbError(PDOException $e): array
{
    $code = pdoErrorCode($e);
    $map = [
        1045 => ['title' => 'Access denied',
                 'hint'  => 'The username or password is incorrect, or this user is not allowed to connect from this host. Double-check the credentials (many hosts prefix the username, e.g. "user_dbname").'],
        1049 => ['title' => 'Unknown database',
                 'hint'  => 'The database does not exist and could not be created automatically. Create it first in your hosting panel (cPanel / phpMyAdmin), then try again.'],
        1044 => ['title' => 'Database access denied',
                 'hint'  => 'The user does not have permission to use this database. Grant the user access to the database in your hosting panel.'],
        2002 => ['title' => 'Cannot reach the database server',
                 'hint'  => 'The hostname is wrong or the MySQL server is not reachable. For most hosts use "localhost" (or "127.0.0.1").'],
        2005 => ['title' => 'Unknown host',
                 'hint'  => 'The database hostname could not be resolved. Check the spelling of the hostname.'],
        2006 => ['title' => 'Server has gone away',
                 'hint'  => 'The connection was dropped. Check that the MySQL server is running and reachable.'],
    ];

    if (isset($map[$code])) {
        return $map[$code];
    }

    return [
        'title' => 'Database error' . ($code ? " (#{$code})" : ''),
        'hint'  => 'Please verify your database credentials and that the MySQL server is running.',
    ];
}

/**
 * Drop all known tables (respecting foreign keys) for a clean install.
 *
 * @return string[]  Human readable log lines.
 */
function dropKnownTables(PDO $pdo, array $tables): array
{
    $log = [];
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ($tables as $table) {
        try {
            $pdo->exec('DROP TABLE IF EXISTS `' . str_replace('`', '', $table) . '`');
            $log[] = "Dropped table `{$table}` (if it existed)";
        } catch (PDOException $e) {
            $log[] = "Could not drop `{$table}`: " . $e->getMessage();
        }
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    return $log;
}

/**
 * Import every statement from a SQL file.
 *
 * @return array{ok:bool,done:int,total:int,error?:string,code?:int,sql?:string}
 */
function importSqlFile(PDO $pdo, string $file): array
{
    $sql = @file_get_contents($file);
    if ($sql === false) {
        return ['ok' => false, 'done' => 0, 'total' => 0,
                'error' => 'Could not read the master SQL file: ' . $file, 'code' => 0];
    }

    $statements = splitSqlStatements($sql);
    $total      = count($statements);
    $done       = 0;

    foreach ($statements as $index => $statement) {
        try {
            $pdo->exec($statement);
            $done++;
        } catch (PDOException $e) {
            return [
                'ok'    => false,
                'done'  => $done,
                'total' => $total,
                'error' => $e->getMessage(),
                'code'  => pdoErrorCode($e),
                'sql'   => (strlen($statement) > 400) ? substr($statement, 0, 400) . ' …' : $statement,
            ];
        }
    }

    return ['ok' => true, 'done' => $done, 'total' => $total];
}

/** Safely single-quote a value for the generated PHP config. */
function phpQuote(string $value): string
{
    return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $value) . "'";
}

/**
 * Write the DB credentials into config/database.php by replacing only the four
 * define() lines (the rest of the file -- settings loader, helpers, ... -- is
 * left untouched).
 *
 * @return array{ok:bool,msg:string}
 */
function updateDatabaseConfig(string $file, string $host, string $name, string $user, string $pass): array
{
    if (!is_file($file)) {
        return ['ok' => false, 'msg' => 'config/database.php was not found.'];
    }
    if (!is_writable($file)) {
        return ['ok' => false, 'msg' => 'config/database.php is not writable. Update the credentials manually.'];
    }

    $content = @file_get_contents($file);
    if ($content === false) {
        return ['ok' => false, 'msg' => 'Could not read config/database.php.'];
    }

    $replacements = [
        'DB_HOST' => $host,
        'DB_NAME' => $name,
        'DB_USER' => $user,
        'DB_PASS' => $pass,
    ];

    $changed = 0;
    foreach ($replacements as $const => $value) {
        $pattern     = "/define\\(\\s*'" . $const . "'\\s*,\\s*'[^']*'\\s*\\);/";
        $replacement = "define('" . $const . "', " . phpQuote($value) . ");";
        $newContent  = preg_replace($pattern, $replacement, $content, 1, $count);
        if ($newContent !== null && $count > 0) {
            $content = $newContent;
            $changed++;
        }
    }

    if ($changed === 0) {
        return ['ok' => false, 'msg' => 'Could not locate the DB define() lines in config/database.php.'];
    }

    if (@file_put_contents($file, $content) === false) {
        return ['ok' => false, 'msg' => 'Failed to write config/database.php (permission denied).'];
    }

    return ['ok' => true, 'msg' => "Updated {$changed} credential line(s) in config/database.php."];
}

/**
 * Create a new admin account, or reset an existing one to admin + new password.
 *
 * @return array{ok:bool,msg:string}
 */
function ensureAdminAccount(PDO $pdo, string $username, string $password): array
{
    $username = trim($username);
    if ($username === '' || $password === '') {
        return ['ok' => false, 'msg' => 'Admin username and password are required.'];
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);

    try {
        // 1) If a user with this username already exists, reset it and promote to admin.
        $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
        $stmt->execute([$username]);
        $existing = $stmt->fetch();

        if ($existing) {
            $upd = $pdo->prepare("UPDATE users SET password = ?, role = 'admin' WHERE id = ?");
            $upd->execute([$hash, $existing['id']]);
            return ['ok' => true, 'msg' => "Existing account '{$username}' was promoted to admin with the new password."];
        }

        // 2) Find a free email address (the seed data may already use <name>@example.com).
        $email   = $username . '@example.com';
        $check   = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $suffix  = 1;
        while (true) {
            $check->execute([$email]);
            if (!$check->fetch()) {
                break;
            }
            $email = $username . $suffix . '@example.com';
            $suffix++;
            if ($suffix > 1000) {                       // safety valve
                $email = $username . '_' . time() . '@example.com';
                break;
            }
        }

        // 3) Insert the new admin account.
        $ins = $pdo->prepare(
            "INSERT INTO users (username, email, password, role, balance, is_reseller, total_spent, reseller_api_enabled)
             VALUES (?, ?, ?, 'admin', 0.00, 0, 0.00, 1)"
        );
        $ins->execute([$username, $email, $hash]);
        return ['ok' => true, 'msg' => "Created admin account '{$username}' (email: {$email})."];
    } catch (PDOException $e) {
        return ['ok' => false, 'msg' => 'Could not create admin account: ' . $e->getMessage()];
    }
}

/** Return a simple {table => row-count} map for the imported tables. */
function tableRowCounts(PDO $pdo, array $tables): array
{
    $counts = [];
    foreach ($tables as $table) {
        try {
            $counts[$table] = (int) $pdo->query('SELECT COUNT(*) FROM `' . str_replace('`', '', $table) . '`')->fetchColumn();
        } catch (PDOException $e) {
            $counts[$table] = null;
        }
    }
    return $counts;
}

/* ========================================================================== */
/*  Request handling                                                          */
/* ========================================================================== */

$errors     = [];
$result     = null;   // the outcome panel (test / install)
$locked     = is_file(LOCK_FILE);
$force      = isset($_GET['force']) || (isset($_POST['force']) && $_POST['force']);

$form = [
    'db_host'       => 'localhost',
    'db_name'       => '',
    'db_user'       => '',
    'db_pass'       => '',
    'admin_user'    => 'admin',
    'admin_pass'    => 'admin123',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'install';

    $host        = trim((string) ($_POST['db_host'] ?? ''));
    $name        = trim((string) ($_POST['db_name'] ?? ''));
    $user        = trim((string) ($_POST['db_user'] ?? ''));
    $pass        = (string) ($_POST['db_pass'] ?? '');
    $adminUser    = trim((string) ($_POST['admin_user'] ?? 'admin'));
    $adminPass    = (string) ($_POST['admin_pass'] ?? 'admin123');

    // These steps are now fully automatic (no UI toggles):
    //   - config/database.php is always updated with the entered credentials
    //   - the default admin account is always created / reset
    //   - existing tables are never dropped (safe re-runs)
    $updateConfig = true;
    $createAdmin  = true;
    $dropTables   = false;

    // keep the form values for re-rendering
    $form['db_host']       = $host;
    $form['db_name']       = $name;
    $form['db_user']       = $user;
    $form['db_pass']       = $pass;
    $form['admin_user']    = $adminUser;
    $form['admin_pass']    = $adminPass;

    if ($host === '') {
        $errors[] = 'Database Hostname is required.';
    }
    if ($name === '') {
        $errors[] = 'Database Name is required.';
    }
    if ($user === '') {
        $errors[] = 'Database Username is required.';
    }
    if ($action === 'install' && !is_file(MASTER_SQL_FILE)) {
        $errors[] = 'master_install.sql was not found next to install.php.';
    }

    if (!$errors && $action === 'test') {
        /* ---------------------- TEST CONNECTION ONLY ---------------------- */
        try {
            $pdo = dbConnect($host, $name, $user, $pass, true);
            $version = $pdo->query('SELECT VERSION()')->fetchColumn();
            $tables  = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);

            $result = [
                'ok'    => true,
                'type'  => 'test',
                'title' => 'Connection Successful',
                'lines' => [
                    "Connected to database <b>" . h($name) . "</b> on <b>" . h($host) . "</b>.",
                    "MySQL server version: <b>" . h((string) $version) . "</b>.",
                    count($tables) > 0
                        ? 'The database already contains <b>' . count($tables) . '</b> table(s).'
                        : 'The database is empty — ready for a fresh installation.',
                ],
            ];
        } catch (PDOException $e) {
            $code = pdoErrorCode($e);
            if ($code === 1049) {
                // database missing -> check if we can reach the server to create it
                try {
                    $pdo2    = dbConnect($host, '', $user, $pass, false);
                    $version = $pdo2->query('SELECT VERSION()')->fetchColumn();
                    $result  = [
                        'ok'    => true,
                        'type'  => 'test',
                        'title' => 'Connection Successful (database will be created)',
                        'lines' => [
                            "Connected to the MySQL server on <b>" . h($host) . "</b> (version <b>" . h((string) $version) . "</b>).",
                            "The database <b>" . h($name) . "</b> does not exist yet — it will be created automatically during installation.",
                        ],
                    ];
                } catch (PDOException $e2) {
                    $friendly = friendlyDbError($e2);
                    $result   = [
                        'ok'    => false,
                        'type'  => 'test',
                        'title' => $friendly['title'],
                        'error' => $e2->getMessage(),
                        'hint'  => $friendly['hint'],
                    ];
                }
            } else {
                $friendly = friendlyDbError($e);
                $result   = [
                    'ok'    => false,
                    'type'  => 'test',
                    'title' => $friendly['title'],
                    'error' => $e->getMessage(),
                    'hint'  => $friendly['hint'],
                ];
            }
        }
    } elseif (!$errors && $action === 'install') {
        /* --------------------------- FULL INSTALL -------------------------- */
        $log      = [];
        $dbCreated = false;

        // 1) connect (create the database first if it is missing)
        try {
            $pdo = dbConnect($host, $name, $user, $pass, true);
            $log[] = "Connected to database `{$name}`.";
        } catch (PDOException $e) {
            if (pdoErrorCode($e) === 1049) {
                try {
                    $server = dbConnect($host, '', $user, $pass, false);
                    $server->exec(
                        'CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '', $name) .
                        '` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci'
                    );
                    $pdo       = dbConnect($host, $name, $user, $pass, true);
                    $dbCreated = true;
                    $log[]     = "Database `{$name}` did not exist and was created.";
                } catch (PDOException $e2) {
                    $friendly = friendlyDbError($e2);
                    $result   = [
                        'ok'    => false,
                        'type'  => 'install',
                        'title' => $friendly['title'],
                        'error' => $e2->getMessage(),
                        'hint'  => $friendly['hint'],
                        'log'   => $log,
                    ];
                }
            } else {
                $friendly = friendlyDbError($e);
                $result   = [
                    'ok'    => false,
                    'type'  => 'install',
                    'title' => $friendly['title'],
                    'error' => $e->getMessage(),
                    'hint'  => $friendly['hint'],
                    'log'   => $log,
                ];
            }
        }

        // 2) optional clean install
        if ($result === null && $dropTables) {
            $log = array_merge($log, dropKnownTables($pdo, $KNOWN_TABLES));
        }

        // 3) import master_install.sql
        if ($result === null) {
            $import = importSqlFile($pdo, MASTER_SQL_FILE);
            if (!$import['ok']) {
                $friendly = friendlyDbError(new PDOException($import['error'], $import['code'] ?? 0));
                // special-case: tables already exist
                if (($import['code'] ?? 0) === 1050) {
                    $friendly = [
                        'title' => 'Tables already exist',
                        'hint'  => 'This database already contains the application tables. Please use an empty database, or remove the existing tables manually before running the installer again.',
                    ];
                }
                $result = [
                    'ok'        => false,
                    'type'      => 'install',
                    'title'     => $friendly['title'],
                    'error'     => $import['error'],
                    'hint'      => $friendly['hint'],
                    'sql'       => $import['sql'] ?? '',
                    'progress'  => $import['done'] . ' / ' . $import['total'] . ' statements executed',
                    'log'       => $log,
                ];
            } else {
                $log[] = "Imported master_install.sql ({$import['done']} statements executed).";
            }
        }

        // 4) update config/database.php
        $configMsg = null;
        if ($result === null && $updateConfig) {
            $cfg = updateDatabaseConfig(CONFIG_DB_FILE, $host, $name, $user, $pass);
            $configMsg = $cfg['msg'];
            $log[] = $cfg['ok'] ? $cfg['msg'] : 'WARNING: ' . $cfg['msg'];
        }

        // 5) default admin account
        $adminMsg = null;
        if ($result === null && $createAdmin) {
            $adm = ensureAdminAccount($pdo, $adminUser, $adminPass);
            $adminMsg = $adm['msg'];
            $log[] = $adm['ok'] ? $adm['msg'] : 'WARNING: ' . $adm['msg'];
        }

        // 6) success -> write lock file + summary
        if ($result === null) {
            $counts = tableRowCounts($pdo, $KNOWN_TABLES);

            @file_put_contents(
                LOCK_FILE,
                "Installed on " . date('Y-m-d H:i:s') . " (db: {$name} @ {$host})\n"
            );

            $result = [
                'ok'         => true,
                'type'       => 'install',
                'title'      => 'Installation Completed Successfully',
                'dbCreated'  => $dbCreated,
                'configMsg'  => $configMsg,
                'adminMsg'   => $adminMsg,
                'adminUser'  => $createAdmin ? $adminUser : null,
                'adminPass'  => $createAdmin ? $adminPass : null,
                'counts'     => $counts,
                'log'        => $log,
            ];
        }
    }
}

/* ========================================================================== */
/*  Render                                                                    */
/* ========================================================================== */
$showForm = !$locked || $force || $_SERVER['REQUEST_METHOD'] === 'POST';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Installation Wizard — <?= h(APP_NAME) ?> <?= h(APP_SUBTITLE) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<style>
    :root{
        --bg:#0b0f19; --surface:#111827; --border:#1f2937; --text:#f9fafb;
        --muted:#9ca3af; --primary:#6366f1; --secondary:#06b6d4;
        --success:#10b981; --warning:#f59e0b; --error:#ef4444;
    }
    *{margin:0;padding:0;box-sizing:border-box}
    body{
        font-family:'Inter',system-ui,-apple-system,sans-serif;
        background-color:var(--bg); color:var(--text); min-height:100vh;
        background-image:radial-gradient(circle at 10% 15%,rgba(99,102,241,.10) 0,transparent 45%),
                         radial-gradient(circle at 90% 85%,rgba(6,182,212,.10) 0,transparent 45%);
        display:flex; align-items:flex-start; justify-content:center; padding:40px 16px 64px;
    }
    .wrap{width:100%; max-width:640px}
    .brand{display:flex; align-items:center; gap:14px; margin-bottom:26px; justify-content:center}
    .brand .logo{
        width:52px;height:52px;border-radius:14px;display:flex;align-items:center;justify-content:center;
        background:linear-gradient(135deg,var(--primary),var(--secondary));
        box-shadow:0 0 22px rgba(99,102,241,.45); flex:0 0 auto;
    }
    .brand .logo svg{width:28px;height:28px;stroke:#fff}
    .brand h1{font-size:20px;font-weight:800;letter-spacing:-.02em;line-height:1.15}
    .brand p{font-size:12.5px;color:var(--muted);font-weight:500;margin-top:2px}
    .card{
        background:rgba(17,24,39,.72); border:1px solid rgba(31,41,55,.85); border-radius:18px;
        backdrop-filter:blur(10px); box-shadow:0 0 30px rgba(99,102,241,.10); overflow:hidden;
    }
    .card-head{padding:22px 26px 0}
    .card-head h2{font-size:16px;font-weight:700}
    .card-head p{font-size:13px;color:var(--muted);margin-top:6px;line-height:1.55}
    .card-body{padding:22px 26px 26px}
    .field{margin-bottom:16px}
    .field label{display:block;font-size:12.5px;font-weight:600;margin-bottom:7px;color:#d1d5db}
    .field label .req{color:var(--error)}
    .input{
        width:100%; background:rgba(11,15,25,.7); border:1px solid var(--border); color:var(--text);
        border-radius:10px; padding:11px 13px; font-size:14px; font-family:inherit; transition:.18s;
    }
    .input:focus{outline:none;border-color:var(--primary);box-shadow:0 0 0 3px rgba(99,102,241,.18)}
    .input::placeholder{color:rgba(156,163,175,.5)}
    .hint{font-size:11.5px;color:var(--muted);margin-top:6px;line-height:1.5}
    .section-title{
        font-size:11px;font-weight:700;letter-spacing:.09em;text-transform:uppercase;color:var(--muted);
        margin:22px 0 12px; display:flex; align-items:center; gap:10px;
    }
    .section-title::after{content:"";flex:1;height:1px;background:var(--border)}
    .check{
        display:flex; gap:11px; align-items:flex-start; padding:12px 13px; border:1px solid var(--border);
        border-radius:11px; margin-bottom:10px; background:rgba(11,15,25,.45); cursor:pointer; transition:.15s;
    }
    .check:hover{border-color:rgba(99,102,241,.45)}
    .check input{margin-top:2px;width:16px;height:16px;accent-color:var(--primary);flex:0 0 auto;cursor:pointer}
    .check .ct{font-size:13px;font-weight:600}
    .check .cd{font-size:11.5px;color:var(--muted);margin-top:3px;line-height:1.5}
    .check.warn{border-color:rgba(245,158,11,.35);background:rgba(245,158,11,.06)}
    .grid2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
    @media(max-width:520px){.grid2{grid-template-columns:1fr}}
    .actions{display:flex;gap:12px;margin-top:24px;flex-wrap:wrap}
    .btn{
        flex:1; min-width:150px; border:none; border-radius:11px; padding:13px 18px; font-size:14px; font-weight:700;
        cursor:pointer; font-family:inherit; transition:.18s; display:inline-flex; align-items:center; justify-content:center; gap:9px;
    }
    .btn-primary{background:linear-gradient(135deg,var(--primary),var(--secondary));color:#fff;box-shadow:0 6px 20px rgba(99,102,241,.35)}
    .btn-primary:hover{filter:brightness(1.08);transform:translateY(-1px)}
    .btn-ghost{background:rgba(99,102,241,.10);color:#c7d2fe;border:1px solid rgba(99,102,241,.35)}
    .btn-ghost:hover{background:rgba(99,102,241,.18)}
    .alert{border-radius:14px;padding:20px 22px;margin-bottom:18px;border:1px solid}
    .alert.ok{background:rgba(16,185,129,.08);border-color:rgba(16,185,129,.35)}
    .alert.bad{background:rgba(239,68,68,.08);border-color:rgba(239,68,68,.35)}
    .alert.warnbox{background:rgba(245,158,11,.08);border-color:rgba(245,158,11,.35)}
    .alert h3{font-size:15px;font-weight:700;display:flex;align-items:center;gap:9px;margin-bottom:10px}
    .alert.ok h3{color:var(--success)} .alert.bad h3{color:#f87171} .alert.warnbox h3{color:var(--warning)}
    .alert p{font-size:13px;color:#d1d5db;line-height:1.65}
    .alert ul{list-style:none;margin-top:8px}
    .alert li{font-size:13px;color:#d1d5db;line-height:1.7;padding-left:18px;position:relative}
    .alert li::before{content:"›";position:absolute;left:2px;color:var(--muted);font-weight:700}
    .errbox{
        background:rgba(11,15,25,.85);border:1px solid rgba(239,68,68,.3);border-radius:10px;padding:13px 15px;
        font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px;color:#fca5a5;margin-top:12px;word-break:break-word;
        white-space:pre-wrap;max-height:220px;overflow:auto;
    }
    .hintbox{font-size:12.5px;color:#fcd34d;margin-top:12px;line-height:1.6;background:rgba(245,158,11,.08);
        border-left:3px solid var(--warning);padding:10px 13px;border-radius:0 8px 8px 0}
    .summary{display:grid;grid-template-columns:repeat(2,1fr);gap:9px;margin-top:14px}
    .summary .item{background:rgba(11,15,25,.6);border:1px solid var(--border);border-radius:9px;padding:10px 12px;display:flex;justify-content:space-between;align-items:center;gap:8px}
    .summary .item span:first-child{font-size:12px;color:var(--muted);font-family:ui-monospace,monospace}
    .summary .item span:last-child{font-size:12.5px;font-weight:700;color:var(--secondary)}
    .creds{background:rgba(16,185,129,.08);border:1px dashed rgba(16,185,129,.4);border-radius:10px;padding:14px 16px;margin-top:14px}
    .creds .row{display:flex;justify-content:space-between;font-size:13px;padding:4px 0}
    .creds .row b{font-family:ui-monospace,monospace;color:#6ee7b7}
    .logbox{background:rgba(11,15,25,.85);border:1px solid var(--border);border-radius:10px;padding:14px 16px;margin-top:14px;max-height:230px;overflow:auto}
    .logbox div{font-size:12px;color:#9ca3af;font-family:ui-monospace,monospace;line-height:1.75;padding-left:16px;position:relative}
    .logbox div::before{content:"✓";position:absolute;left:0;color:var(--success)}
    .foot{text-align:center;font-size:11.5px;color:var(--muted);margin-top:22px;line-height:1.7}
    .foot a{color:var(--secondary);text-decoration:none}
    .foot a:hover{text-decoration:underline}
    .overlay{
        position:fixed;inset:0;background:rgba(11,15,25,.88);backdrop-filter:blur(4px);z-index:50;
        display:none;align-items:center;justify-content:center;flex-direction:column;gap:20px;
    }
    .overlay.show{display:flex}
    .spinner{width:54px;height:54px;border:4px solid rgba(99,102,241,.25);border-top-color:var(--primary);border-radius:50%;animation:spin .8s linear infinite}
    @keyframes spin{to{transform:rotate(360deg)}}
    .overlay p{font-size:14px;color:#c7d2fe;font-weight:600}
    .overlay small{font-size:12px;color:var(--muted)}
    .pill{display:inline-block;font-size:10.5px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;padding:3px 9px;border-radius:999px;margin-left:8px;vertical-align:middle}
    .pill.opt{background:rgba(6,182,212,.15);color:#67e8f9;border:1px solid rgba(6,182,212,.3)}
</style>
</head>
<body>
<div class="wrap">

    <div class="brand">
        <div class="logo">
            <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M12 2 3 7v10l9 5 9-5V7z"/><path d="M12 22V12"/><path d="M3 7l9 5 9-5"/>
            </svg>
        </div>
        <div>
            <h1><?= h(APP_NAME) ?></h1>
            <p><?= h(APP_SUBTITLE) ?> &middot; Installation Wizard</p>
        </div>
    </div>

    <?php /* ------------------------------ RESULT PANEL ------------------------------ */ ?>
    <?php if ($result !== null): ?>
        <?php if ($result['ok'] && $result['type'] === 'test'): ?>
            <div class="alert ok">
                <h3>&#10004; <?= h($result['title']) ?></h3>
                <ul>
                    <?php foreach ($result['lines'] as $line): ?>
                        <li><?= $line ?></li>
                    <?php endforeach; ?>
                </ul>
                <p style="margin-top:12px;color:var(--muted);font-size:12.5px">
                    Nothing was changed. Click <b>Install Now</b> below to run the full installation.
                </p>
            </div>
        <?php elseif ($result['ok'] && $result['type'] === 'install'): ?>
            <div class="alert ok">
                <h3>&#127881; <?= h($result['title']) ?></h3>
                <p>Your database has been set up and the application is ready to use.</p>

                <?php if (!empty($result['counts'])): ?>
                    <div class="summary">
                        <?php foreach ($result['counts'] as $tbl => $cnt): ?>
                            <div class="item">
                                <span><?= h($tbl) ?></span>
                                <span><?= $cnt === null ? '—' : (int) $cnt . ' rows' ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($result['adminUser'])): ?>
                    <div class="creds">
                        <div class="row"><span>Admin username</span><b><?= h($result['adminUser']) ?></b></div>
                        <div class="row"><span>Admin password</span><b><?= h($result['adminPass']) ?></b></div>
                    </div>
                    <div class="hintbox">&#9888; Change this password immediately after your first login.</div>
                <?php endif; ?>

                <?php if (!empty($result['log'])): ?>
                    <div class="logbox">
                        <?php foreach ($result['log'] as $line): ?>
                            <div><?= h($line) ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <div class="hintbox" style="margin-top:16px">
                    &#128274; For security, please <b>delete <code>install.php</code></b> and
                    <b><code>config/install.lock</code></b> from your server now.
                </div>
            </div>

            <div class="actions">
                <a class="btn btn-primary" href="index.php" style="text-decoration:none">Go to Website &#8594;</a>
                <a class="btn btn-ghost" href="login.php" style="text-decoration:none">Go to Login</a>
            </div>
        <?php else: ?>
            <div class="alert bad">
                <h3>&#10007; <?= h($result['title']) ?></h3>
                <?php if (!empty($result['error'])): ?>
                    <p><?= h($result['error']) ?></p>
                <?php endif; ?>
                <?php if (!empty($result['progress'])): ?>
                    <p style="margin-top:8px;color:var(--muted)">Progress: <?= h($result['progress']) ?></p>
                <?php endif; ?>
                <?php if (!empty($result['sql'])): ?>
                    <div class="errbox"><?= h($result['sql']) ?></div>
                <?php endif; ?>
                <?php if (!empty($result['hint'])): ?>
                    <div class="hintbox">&#128161; <?= h($result['hint']) ?></div>
                <?php endif; ?>
                <?php if (!empty($result['log'])): ?>
                    <div class="logbox">
                        <?php foreach ($result['log'] as $line): ?>
                            <div><?= h($line) ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($errors): ?>
        <div class="alert bad">
            <h3>&#10007; Please fix the following</h3>
            <ul>
                <?php foreach ($errors as $err): ?>
                    <li><?= h($err) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php /* -------------------------------- LOCKED -------------------------------- */ ?>
    <?php if ($locked && !$force && $_SERVER['REQUEST_METHOD'] !== 'POST'): ?>
        <div class="card">
            <div class="card-head"><h2>Installation already completed</h2></div>
            <div class="card-body">
                <div class="alert warnbox" style="margin:0">
                    <h3>&#128274; Locked</h3>
                    <p>This application has already been installed on this server
                       (a lock file <code>config/install.lock</code> exists).</p>
                    <div class="hintbox" style="margin-top:12px">
                        To protect your data, the installer is disabled. If you really want to
                        run it again, delete <code>config/install.lock</code> on the server, or
                        <a href="?force=1" style="color:#fcd34d">click here to re-run anyway</a>.
                    </div>
                </div>
                <div class="actions">
                    <a class="btn btn-primary" href="index.php" style="text-decoration:none">Go to Website &#8594;</a>
                </div>
            </div>
        </div>

    <?php /* --------------------------------- FORM --------------------------------- */ ?>
    <?php elseif ($showForm): ?>
        <form class="card" id="installForm" method="post" action="install.php" autocomplete="off">
            <?php if ($force): ?><input type="hidden" name="force" value="1"><?php endif; ?>
            <div class="card-head">
                <h2>Database Configuration</h2>
                <p>Enter your MySQL / MariaDB details below. If you are unsure, these are usually
                   available in your hosting control panel (cPanel &rarr; MySQL Databases).</p>
            </div>
            <div class="card-body">

                <div class="field">
                    <label>Database Hostname <span class="req">*</span></label>
                    <input class="input" type="text" name="db_host" value="<?= h($form['db_host']) ?>"
                           placeholder="localhost" required>
                    <div class="hint">Default is <b>localhost</b>. Some hosts use <b>127.0.0.1</b> or a custom host.</div>
                </div>

                <div class="field">
                    <label>Database Name <span class="req">*</span></label>
                    <input class="input" type="text" name="db_name" value="<?= h($form['db_name']) ?>"
                           placeholder="e.g. mywebsite_db" required>
                </div>

                <div class="field">
                    <label>Database Username <span class="req">*</span></label>
                    <input class="input" type="text" name="db_user" value="<?= h($form['db_user']) ?>"
                           placeholder="e.g. mywebsite_user" required>
                </div>

                <div class="field">
                    <label>Database Password</label>
                    <input class="input" type="text" name="db_pass" id="db_pass" value="<?= h($form['db_pass']) ?>"
                           placeholder="Leave blank if there is no password">
                </div>

                <div class="section-title">Admin Login Details</div>

                <div class="grid2">
                    <div class="field" style="margin-bottom:0">
                        <label>Admin Username</label>
                        <input class="input" type="text" name="admin_user" value="<?= h($form['admin_user']) ?>">
                    </div>
                    <div class="field" style="margin-bottom:0">
                        <label>Admin Password</label>
                        <input class="input" type="text" name="admin_pass" id="admin_pass" value="<?= h($form['admin_pass']) ?>">
                    </div>
                </div>
                <div class="hint" style="margin-top:8px">Change this password right after your first login.</div>

                <div class="actions">
                    <button class="btn btn-ghost" type="submit" name="action" value="test">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M5 12l5 5L20 7"/></svg>
                        Test Connection
                    </button>
                    <button class="btn btn-primary" type="submit" name="action" value="install">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12"/><path d="m7 10 5 5 5-5"/><path d="M5 21h14"/></svg>
                        Install Now
                    </button>
                </div>
            </div>
        </form>
    <?php endif; ?>

    <div class="foot">
        <?= h(APP_NAME) ?> &middot; <?= h(APP_SUBTITLE) ?> — Automated Installer<br>
        PHP <?= h(PHP_VERSION) ?> &middot; master SQL: <code>master_install.sql</code>
    </div>
</div>

<div class="overlay" id="overlay">
    <div class="spinner"></div>
    <p>Installing, please wait…</p>
    <small>Creating tables and importing initial data</small>
</div>

<script>
document.addEventListener('DOMContentLoaded', function(){
    var form = document.getElementById('installForm');
    if(!form) return;
    form.addEventListener('submit', function(e){
        var action = (e.submitter && e.submitter.value) || 'install';
        if(action === 'install'){
            document.getElementById('overlay').classList.add('show');
        }
    });
});
</script>
</body>
</html>
