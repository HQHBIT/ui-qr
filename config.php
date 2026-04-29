<?php
declare(strict_types=1);

// --- AUTHENTICATION SETTINGS ---
define('ADMIN_USER', 'admin');
define('ADMIN_PASS', '18ddbf4f606ab5a2');

// --- API SETTINGS ---
// Generate a secure random key using the generator on the API Docs page, or run:
// php -r "echo bin2hex(random_bytes(32));"
define('API_KEY', 'bdbf960e71744f6d7de3477debf7be4541330f54597c9cc84b567ac72c37a067');

// --- SITE SETTINGS ---
// No trailing slash. Examples:
//   Root install:      'https://yourdomain.com'
//   Subdir install:    'https://yourdomain.com/qr-track'
define('BASE_URL',   'http://54.198.213.208:8091');

// Absolute paths — place OUTSIDE your web root for security
define('DB_PATH',    '/home/admin/qr-track-data/db/tuxxin_qr.sqlite');
define('LOGO_DIR',   '/home/admin/qr-track-data/tmp');

define('TIMEZONE',   'America/New_York');
define('THEME_PATH', __DIR__ . '/themes');

// --- NETWORK SETTINGS ---
// Set true if your server is behind a Cloudflare Tunnel or similar reverse proxy
define('USE_CLOUDFLARE_TUNNEL', false);

// --- DISABLED QR CODE PAGE ---
// Where to redirect when a QR code is inactive.
// Set to a full URL (e.g. 'https://yourdomain.com') or leave '' to show the built-in "Link Inactive" page.
define('DISABLED_REDIRECT_URL', '');

// --- API RATE THROTTLING ---
// Limits requests per IP to prevent abuse. Set API_THROTTLE_ENABLED to false to disable.
define('API_THROTTLE_ENABLED', true);
define('API_THROTTLE_LIMIT',  60);   // Max requests per window per IP
define('API_THROTTLE_WINDOW', 60);   // Window size in seconds

// --- SESSION SETTINGS ---
// Seconds of inactivity before the admin session expires (default: 2 hours)
define('SESSION_LIFETIME', 7200);

// =============================================================================
// END OF CONFIGURATION — do not edit below this line
// =============================================================================

function require_auth() {
    if (session_status() === PHP_SESSION_NONE) session_start();

    if (isset($_SESSION['last_active']) && (time() - $_SESSION['last_active']) > SESSION_LIFETIME) {
        session_unset();
        session_destroy();
        header("Location: " . BASE_URL . "/login.php");
        exit;
    }
    $_SESSION['last_active'] = time();

    if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
        header("Location: " . BASE_URL . "/login.php");
        exit;
    }

    // DB-backed session validation: confirm the user still exists and refresh role.
    // Uses a static flag so the DB is queried at most once per request even if
    // require_auth() / require_role() are called multiple times.
    static $validated = false;
    if (!$validated) {
        $validated = true;
        global $db;
        $uid = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
        if ($uid > 0) {
            $stmt = $db->prepare("SELECT username, role FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([$uid]);
            $row = $stmt->fetch();
            if (!$row) {
                // User no longer exists — destroy session and redirect to login
                session_unset();
                session_destroy();
                header("Location: " . BASE_URL . "/login.php");
                exit;
            }
            // Refresh role (and username) from DB so demotion/promotion takes effect immediately
            if ($row['role'] !== ($_SESSION['role'] ?? '')) {
                $_SESSION['role'] = $row['role'];
            }
            if ($row['username'] !== ($_SESSION['username'] ?? '')) {
                $_SESSION['username'] = $row['username'];
            }
        }
    }
}

function current_user(): ?array {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['logged_in']) || empty($_SESSION['user_id'])) return null;
    return [
        'id'       => (int)$_SESSION['user_id'],
        'username' => $_SESSION['username'] ?? '',
        'role'     => $_SESSION['role'] ?? 'user',
    ];
}

function is_admin(): bool {
    $u = current_user();
    return $u !== null && $u['role'] === 'admin';
}

function require_role(string $role): void {
    require_auth();
    $u = current_user();
    if (!$u || $u['role'] !== $role) {
        http_response_code(403);
        die('Forbidden: insufficient privileges.');
    }
}

function csrf_token(): string {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(): void {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals(csrf_token(), $token)) {
        http_response_code(403);
        die('Invalid request (CSRF check failed).');
    }
}

function purge_old_tokens($db) {
    $db->exec("DELETE FROM api_tokens WHERE expires_at < datetime('now')");
}

// --- DATABASE CONNECTION ---
$dbDir  = dirname(DB_PATH);
$dbFile = DB_PATH;

if ((!is_dir($dbDir) || !is_writable($dbDir)) || (file_exists($dbFile) && !is_writable($dbFile))) {
    exit("Database Permission Error: PHP cannot write to $dbDir. Check that the directory exists and is writable by the web server.");
}

try {
    $db = new PDO('sqlite:' . DB_PATH);
    $db->setAttribute(PDO::ATTR_ERRMODE,            PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    $db->exec("CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT UNIQUE NOT NULL,
        password_hash TEXT NOT NULL,
        role TEXT NOT NULL DEFAULT 'user',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS folders (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        parent_id INTEGER DEFAULT NULL,
        name TEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_folders_user_parent ON folders(user_id, parent_id)");

    $db->exec("CREATE TABLE IF NOT EXISTS products (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        uuid TEXT UNIQUE,
        title TEXT,
        type TEXT,
        target_data TEXT,
        logo_path TEXT DEFAULT NULL,
        is_active INTEGER DEFAULT 1,
        is_deleted INTEGER DEFAULT 0,
        user_id INTEGER DEFAULT NULL,
        design_json TEXT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // Migration: add user_id + design_json if missing
    $pcols = $db->query("PRAGMA table_info(products)")->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('user_id', $pcols)) {
        $db->exec("ALTER TABLE products ADD COLUMN user_id INTEGER DEFAULT NULL");
    }
    if (!in_array('design_json', $pcols)) {
        $db->exec("ALTER TABLE products ADD COLUMN design_json TEXT DEFAULT NULL");
    }
    if (!in_array('folder_id', $pcols)) {
        $db->exec("ALTER TABLE products ADD COLUMN folder_id INTEGER DEFAULT NULL");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_products_folder ON products(folder_id)");
    }

    // Bootstrap: seed admin from config constants on first run; assign legacy QRs to admin
    $userCount = (int)$db->query("SELECT COUNT(*) FROM users")->fetchColumn();
    if ($userCount === 0) {
        $hash = password_hash(ADMIN_PASS, PASSWORD_DEFAULT);
        $db->prepare("INSERT INTO users (username, password_hash, role) VALUES (?, ?, 'admin')")
           ->execute([ADMIN_USER, $hash]);
        $adminId = (int)$db->lastInsertId();
        $db->prepare("UPDATE products SET user_id = ? WHERE user_id IS NULL")->execute([$adminId]);
    }

    $db->exec("CREATE TABLE IF NOT EXISTS scans (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        product_uuid TEXT,
        ip_address TEXT,
        user_agent TEXT,
        scan_status TEXT DEFAULT 'success',
        scanned_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(product_uuid) REFERENCES products(uuid)
    )");

    // Migration: add geo columns if missing
    $columns = $db->query("PRAGMA table_info(scans)")->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('geo_city', $columns)) {
        $db->exec("ALTER TABLE scans ADD COLUMN geo_city TEXT");
        $db->exec("ALTER TABLE scans ADD COLUMN geo_region TEXT");
        $db->exec("ALTER TABLE scans ADD COLUMN geo_country TEXT");
        $db->exec("ALTER TABLE scans ADD COLUMN geo_isp TEXT");
    }

    $db->exec("CREATE TABLE IF NOT EXISTS api_tokens (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        token TEXT UNIQUE,
        product_uuid TEXT,
        expires_at DATETIME
    )");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_api_token ON api_tokens(token)");

    $db->exec("CREATE TABLE IF NOT EXISTS rate_limit (
        ip TEXT PRIMARY KEY,
        window_start INTEGER NOT NULL,
        request_count INTEGER NOT NULL DEFAULT 1
    )");

} catch (PDOException $e) {
    die("Database Error: " . $e->getMessage());
}
