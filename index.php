<?php
require 'config.php';
require_auth();

define('PAGE_TITLE', 'Dashboard | Umoor Iqtesadiyah QR Track');

const ALLOWED_TYPES = ['url', 'phone', 'map', 'vcard', 'wifi', 'sms', 'email', 'social'];

$me      = current_user();
$isAdmin = is_admin();

function sanitize_design(array $in): array {
    $hex = function ($v, $default) {
        return preg_match('/^#[0-9a-fA-F]{6}$/', (string)$v) ? strtolower($v) : $default;
    };
    $allowedModule   = ['square','dot','rounded','classy','diamond','star','cross'];
    $allowedEye      = ['square','rounded','circle','leaf','frame','flower'];
    $allowedEyeInner = ['square','rounded','circle','dot','diamond'];
    $allowedGradDir  = ['horizontal','vertical','diagonal','radial'];
    return [
        'fg'           => $hex($in['fg']           ?? '#000000', '#000000'),
        'bg'           => $hex($in['bg']           ?? '#ffffff', '#ffffff'),
        'gradient'     => !empty($in['gradient']),
        'gradient_dir' => in_array($in['gradient_dir'] ?? 'diagonal', $allowedGradDir, true)  ? $in['gradient_dir'] : 'diagonal',
        'fg2'          => $hex($in['fg2']          ?? '#1e90ff', '#1e90ff'),
        'module_shape' => in_array($in['module_shape'] ?? 'square', $allowedModule, true)    ? $in['module_shape'] : 'square',
        'eye_shape'    => in_array($in['eye_shape']    ?? 'square', $allowedEye, true)       ? $in['eye_shape']    : 'square',
        'eye_inner'    => in_array($in['eye_inner']    ?? 'square', $allowedEyeInner, true)  ? $in['eye_inner']    : 'square',
        'eye_color'    => $hex($in['eye_color']    ?? '#000000', '#000000'),
    ];
}

function ensure_owner_or_admin($db, int $id, ?array $me): array {
    $stmt = $db->prepare("SELECT * FROM products WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) { http_response_code(404); die('Not found.'); }
    if (($me['role'] ?? '') !== 'admin' && (int)$row['user_id'] !== (int)($me['id'] ?? 0)) {
        http_response_code(403); die('Forbidden.');
    }
    return $row;
}

function fetch_folder_or_die($db, int $id, int $userId, bool $allowAdmin): array {
    $stmt = $db->prepare("SELECT * FROM folders WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) { http_response_code(404); die('Folder not found.'); }
    if (!$allowAdmin && (int)$row['user_id'] !== $userId) {
        http_response_code(403); die('Forbidden.');
    }
    return $row;
}

function build_folder_path($db, ?int $folderId, int $userId): array {
    $path = [];
    $cur = $folderId;
    $guard = 0;
    while ($cur !== null && $guard++ < 32) {
        $st = $db->prepare("SELECT id, name, parent_id FROM folders WHERE id = ? AND user_id = ?");
        $st->execute([$cur, $userId]);
        $row = $st->fetch();
        if (!$row) break;
        array_unshift($path, $row);
        $cur = $row['parent_id'] !== null ? (int)$row['parent_id'] : null;
    }
    return $path;
}

function valid_folder_id_for_user($db, $folderId, int $userId): ?int {
    if ($folderId === null || $folderId === '' || $folderId === '0') return null;
    $folderId = (int)$folderId;
    $st = $db->prepare("SELECT id FROM folders WHERE id = ? AND user_id = ?");
    $st->execute([$folderId, $userId]);
    return $st->fetch() ? $folderId : null;
}

function descendant_folder_ids($db, int $rootId, int $userId): array {
    $ids = [$rootId];
    $queue = [$rootId];
    while ($queue) {
        $next = [];
        $placeholders = implode(',', array_fill(0, count($queue), '?'));
        $st = $db->prepare("SELECT id FROM folders WHERE user_id = ? AND parent_id IN ($placeholders)");
        $st->execute(array_merge([$userId], $queue));
        foreach ($st->fetchAll(PDO::FETCH_COLUMN, 0) as $cid) {
            $ids[] = (int)$cid;
            $next[] = (int)$cid;
        }
        $queue = $next;
    }
    return $ids;
}

// --- HANDLE FORM SUBMISSIONS & ACTIONS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    verify_csrf();

    // Action: Add New QR Code
    if ($_POST['action'] === 'add') {
        $uuid  = bin2hex(random_bytes(6));
        $title = trim($_POST['title'] ?? '');
        $type  = $_POST['type'] ?? '';

        if ($title === '' || !in_array($type, ALLOWED_TYPES, true)) {
            http_response_code(400);
            die('Invalid input.');
        }

        // Construct target data based on type
        $target = '';
        if ($type === 'vcard') {
            $target = json_encode([
                'fname'   => trim($_POST['v_fname']    ?? ''),
                'lname'   => trim($_POST['v_lname']    ?? ''),
                'phone'   => trim($_POST['v_phone']    ?? ''),
                'email'   => trim($_POST['v_email']    ?? ''),
                'company' => trim($_POST['v_company']  ?? ''),
            ]);
        } elseif ($type === 'wifi') {
            $target = json_encode([
                'ssid' => trim($_POST['wifi_ssid'] ?? ''),
                'pass' => trim($_POST['wifi_pass'] ?? ''),
                'enc'  => trim($_POST['wifi_enc']  ?? 'WPA'),
            ]);
        } elseif ($type === 'sms') {
            $target = json_encode([
                'phone' => trim($_POST['sms_phone'] ?? ''),
                'body'  => trim($_POST['sms_body']  ?? ''),
            ]);
        } elseif ($type === 'email') {
            $target = json_encode([
                'email'   => trim($_POST['email_addr'] ?? ''),
                'subject' => trim($_POST['email_sub']  ?? ''),
                'body'    => trim($_POST['email_body'] ?? ''),
            ]);
        } else {
            $target = trim($_POST['target'] ?? '');
        }

        // Handle logo upload with MIME validation
        $logoPath = null;
        if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
            $allowedMimes = ['image/png' => 'png', 'image/jpeg' => 'jpg'];
            $finfo        = new finfo(FILEINFO_MIME_TYPE);
            $mime         = $finfo->file($_FILES['logo']['tmp_name']);

            if (isset($allowedMimes[$mime])) {
                $ext      = $allowedMimes[$mime];
                $filename = 'logo_' . $uuid . '.' . $ext;
                $destPath = LOGO_DIR . '/' . $filename;
                if (!is_dir(LOGO_DIR)) {
                    mkdir(LOGO_DIR, 0755, true);
                }
                if (move_uploaded_file($_FILES['logo']['tmp_name'], $destPath)) {
                    $logoPath = $filename;
                } else {
                    error_log("File upload error: Unable to move file to " . $destPath);
                }
            }
        }

        $design = sanitize_design($_POST['design'] ?? []);
        $designJson = json_encode($design);
        $folderId = valid_folder_id_for_user($db, $_POST['folder_id'] ?? null, $me['id']);

        $stmt = $db->prepare("INSERT INTO products (uuid, title, type, target_data, logo_path, user_id, design_json, folder_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$uuid, $title, $type, $target, $logoPath, $me['id'], $designJson, $folderId]);

        $redir = BASE_URL;
        if ($folderId !== null) $redir .= '?folder=' . $folderId;
        header("Location: " . $redir);
        exit;
    }

    // Action: Edit QR Code (update title + target data only — type never changes)
    if ($_POST['action'] === 'edit') {
        $id    = (int)($_POST['id'] ?? 0);
        $title = trim($_POST['title'] ?? '');

        if ($id <= 0 || $title === '') {
            http_response_code(400);
            die('Invalid input.');
        }

        $current = ensure_owner_or_admin($db, $id, $me);
        if ($current['is_deleted']) { http_response_code(404); die('Not found.'); }
        $type = $current['type'];
        $target = '';
        if ($type === 'vcard') {
            $target = json_encode([
                'fname'   => trim($_POST['v_fname']   ?? ''),
                'lname'   => trim($_POST['v_lname']   ?? ''),
                'phone'   => trim($_POST['v_phone']   ?? ''),
                'email'   => trim($_POST['v_email']   ?? ''),
                'company' => trim($_POST['v_company'] ?? ''),
            ]);
        } elseif ($type === 'wifi') {
            $target = json_encode([
                'ssid' => trim($_POST['wifi_ssid'] ?? ''),
                'pass' => trim($_POST['wifi_pass'] ?? ''),
                'enc'  => trim($_POST['wifi_enc']  ?? 'WPA'),
            ]);
        } elseif ($type === 'sms') {
            $target = json_encode([
                'phone' => trim($_POST['sms_phone'] ?? ''),
                'body'  => trim($_POST['sms_body']  ?? ''),
            ]);
        } elseif ($type === 'email') {
            $target = json_encode([
                'email'   => trim($_POST['email_addr'] ?? ''),
                'subject' => trim($_POST['email_sub']  ?? ''),
                'body'    => trim($_POST['email_body'] ?? ''),
            ]);
        } else {
            $target = trim($_POST['target'] ?? '');
        }

        $design = sanitize_design($_POST['design'] ?? json_decode($current['design_json'] ?? '[]', true) ?: []);
        $designJson = json_encode($design);
        $ownerId = (int)$current['user_id'];
        $folderId = isset($_POST['folder_id'])
            ? valid_folder_id_for_user($db, $_POST['folder_id'], $ownerId)
            : ($current['folder_id'] !== null ? (int)$current['folder_id'] : null);

        $stmt = $db->prepare("UPDATE products SET title = ?, target_data = ?, design_json = ?, folder_id = ? WHERE id = ? AND is_deleted = 0");
        $stmt->execute([$title, $target, $designJson, $folderId, $id]);
        qr_cache_purge((string)$current['uuid']);

        $redir = BASE_URL;
        if ($folderId !== null) $redir .= '?folder=' . $folderId;
        header("Location: " . $redir);
        exit;
    }

    // Action: Toggle Active Status
    if ($_POST['action'] === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        ensure_owner_or_admin($db, $id, $me);
        $db->prepare("UPDATE products SET is_active = NOT is_active WHERE id = ?")->execute([$id]);
        exit;
    }

    // Action: Soft Delete
    if ($_POST['action'] === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        ensure_owner_or_admin($db, $id, $me);
        $db->prepare("UPDATE products SET is_active = 0, is_deleted = 1 WHERE id = ?")->execute([$id]);
        exit;
    }

    // Action: Restore Deleted
    if ($_POST['action'] === 'restore') {
        $id = (int)($_POST['id'] ?? 0);
        ensure_owner_or_admin($db, $id, $me);
        $db->prepare("UPDATE products SET is_deleted = 0, is_active = 1 WHERE id = ?")->execute([$id]);
        header("Location: " . BASE_URL);
        exit;
    }

    // Action: Add Folder
    if ($_POST['action'] === 'folder_add') {
        $name = trim($_POST['name'] ?? '');
        $parentId = $_POST['parent_id'] ?? null;
        $parentId = ($parentId === '' || $parentId === '0' || $parentId === null) ? null : (int)$parentId;
        if ($name === '' || mb_strlen($name) > 100) { http_response_code(400); die('Invalid folder name.'); }
        if ($parentId !== null) fetch_folder_or_die($db, $parentId, $me['id'], false);
        $db->prepare("INSERT INTO folders (user_id, parent_id, name) VALUES (?, ?, ?)")
           ->execute([$me['id'], $parentId, $name]);
        $newId = (int)$db->lastInsertId();
        header('Content-Type: application/json');
        echo json_encode(['status' => 'ok', 'id' => $newId, 'name' => $name, 'parent_id' => $parentId]);
        exit;
    }

    // Action: Rename Folder
    if ($_POST['action'] === 'folder_rename') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        if ($id <= 0 || $name === '' || mb_strlen($name) > 100) { http_response_code(400); die('Invalid input.'); }
        fetch_folder_or_die($db, $id, $me['id'], false);
        $db->prepare("UPDATE folders SET name = ? WHERE id = ? AND user_id = ?")->execute([$name, $id, $me['id']]);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'ok']);
        exit;
    }

    // Action: Delete Folder (move children + QRs to parent)
    if ($_POST['action'] === 'folder_delete') {
        $id = (int)($_POST['id'] ?? 0);
        $folder = fetch_folder_or_die($db, $id, $me['id'], false);
        $parent = $folder['parent_id'] !== null ? (int)$folder['parent_id'] : null;
        $db->prepare("UPDATE folders  SET parent_id = ? WHERE parent_id = ? AND user_id = ?")
           ->execute([$parent, $id, $me['id']]);
        $db->prepare("UPDATE products SET folder_id = ? WHERE folder_id = ? AND user_id = ?")
           ->execute([$parent, $id, $me['id']]);
        $db->prepare("DELETE FROM folders WHERE id = ? AND user_id = ?")->execute([$id, $me['id']]);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'ok', 'parent_id' => $parent]);
        exit;
    }

    // Action: Move QR to folder
    if ($_POST['action'] === 'move') {
        $id = (int)($_POST['id'] ?? 0);
        ensure_owner_or_admin($db, $id, $me);
        $row = $db->query("SELECT user_id FROM products WHERE id = " . (int)$id)->fetch();
        $ownerId = (int)$row['user_id'];
        $targetFolder = valid_folder_id_for_user($db, $_POST['folder_id'] ?? null, $ownerId);
        $db->prepare("UPDATE products SET folder_id = ? WHERE id = ?")->execute([$targetFolder, $id]);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'ok']);
        exit;
    }

    // Action: Update Design (live designer save)
    if ($_POST['action'] === 'design') {
        $id = (int)($_POST['id'] ?? 0);
        $row = ensure_owner_or_admin($db, $id, $me);
        $design = sanitize_design($_POST['design'] ?? []);
        $db->prepare("UPDATE products SET design_json = ? WHERE id = ?")
           ->execute([json_encode($design), $id]);
        qr_cache_purge((string)$row['uuid']);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'ok', 'design' => $design]);
        exit;
    }
}

// --- FETCH DATA ---
$showTrash    = isset($_GET['trash']);
$deletedFlag  = $showTrash ? 1 : 0;
$currentFolderId = isset($_GET['folder']) && $_GET['folder'] !== ''
    ? valid_folder_id_for_user($db, $_GET['folder'], $me['id'])
    : null;

$folderPath = $currentFolderId !== null ? build_folder_path($db, $currentFolderId, $me['id']) : [];

// Build folder filter clause (user's QRs only — admin global view = ignore folder filter when no folder selected)
$folderClause = '';
$folderParams = [];
if (!$showTrash && $currentFolderId !== null) {
    $folderClause = " AND p.folder_id = ? AND p.user_id = ?";
    $folderParams = [$currentFolderId, $me['id']];
}

if ($isAdmin) {
    $sql = "
        SELECT p.*, u.username AS owner_username, f.name AS folder_name,
            (SELECT COUNT(*) FROM scans WHERE product_uuid = p.uuid) as scan_count
        FROM products p
        LEFT JOIN users u ON u.id = p.user_id
        LEFT JOIN folders f ON f.id = p.folder_id
        WHERE p.is_deleted = ?" . $folderClause . "
        ORDER BY p.created_at DESC
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute(array_merge([$deletedFlag], $folderParams));
} else {
    if ($currentFolderId !== null) {
        $stmt = $db->prepare("
            SELECT p.*, NULL AS owner_username, f.name AS folder_name,
                (SELECT COUNT(*) FROM scans WHERE product_uuid = p.uuid) as scan_count
            FROM products p
            LEFT JOIN folders f ON f.id = p.folder_id
            WHERE p.is_deleted = ? AND p.user_id = ? AND p.folder_id = ?
            ORDER BY p.created_at DESC
        ");
        $stmt->execute([$deletedFlag, $me['id'], $currentFolderId]);
    } else {
        // Root view (non-admin): show only QRs with no folder
        $stmt = $db->prepare("
            SELECT p.*, NULL AS owner_username, f.name AS folder_name,
                (SELECT COUNT(*) FROM scans WHERE product_uuid = p.uuid) as scan_count
            FROM products p
            LEFT JOIN folders f ON f.id = p.folder_id
            WHERE p.is_deleted = ? AND p.user_id = ? AND p.folder_id IS NULL
            ORDER BY p.created_at DESC
        ");
        $stmt->execute([$deletedFlag, $me['id']]);
    }
}
$products = $stmt->fetchAll();

// Subfolders of current location (current user only)
$subStmt = $db->prepare("
    SELECT f.*, (SELECT COUNT(*) FROM products WHERE folder_id = f.id AND is_deleted = 0) AS qr_count,
           (SELECT COUNT(*) FROM folders WHERE parent_id = f.id) AS sub_count
    FROM folders f
    WHERE f.user_id = ? AND " . ($currentFolderId === null ? 'f.parent_id IS NULL' : 'f.parent_id = ?') . "
    ORDER BY f.name COLLATE NOCASE ASC
");
$subStmt->execute($currentFolderId === null ? [$me['id']] : [$me['id'], $currentFolderId]);
$subfolders = $subStmt->fetchAll();

// All folders (flat) for the move/select dropdowns
$allFolders = $db->prepare("SELECT id, parent_id, name FROM folders WHERE user_id = ? ORDER BY name COLLATE NOCASE ASC");
$allFolders->execute([$me['id']]);
$allFolders = $allFolders->fetchAll();

function build_folder_options(array $all, ?int $selected = null, ?int $parent = null, int $depth = 0): string {
    $html = '';
    foreach ($all as $f) {
        $fParent = $f['parent_id'] !== null ? (int)$f['parent_id'] : null;
        if ($fParent !== $parent) continue;
        $sel = ($selected !== null && (int)$f['id'] === $selected) ? ' selected' : '';
        $html .= '<option value="' . (int)$f['id'] . '"' . $sel . '>'
              . str_repeat('— ', $depth) . htmlspecialchars($f['name']) . '</option>';
        $html .= build_folder_options($all, $selected, (int)$f['id'], $depth + 1);
    }
    return $html;
}

$csrfToken = csrf_token();

// Type label + badge tone for the QR list
function qr_type_label(string $type): string {
    return [
        'url'    => 'Website',
        'phone'  => 'Phone',
        'map'    => 'Map',
        'vcard'  => 'vCard',
        'wifi'   => 'Wi-Fi',
        'sms'    => 'SMS',
        'email'  => 'Email',
        'social' => 'Social',
    ][$type] ?? strtoupper($type);
}
function qr_short_target(array $p): string {
    if ($p['type'] === 'url' || $p['type'] === 'social') return (string)$p['target_data'];
    if ($p['type'] === 'phone') return 'tel:' . $p['target_data'];
    if ($p['type'] === 'map')   return 'map: ' . $p['target_data'];
    if ($p['type'] === 'wifi')  return 'Wi-Fi network';
    if ($p['type'] === 'vcard') return 'vCard contact';
    if ($p['type'] === 'sms')   return 'SMS message';
    if ($p['type'] === 'email') return 'Email message';
    return BASE_URL . '/p/' . $p['uuid'];
}

// --- RENDER VIEW ---
define('ACTIVE_NAV', 'qrcodes');
include THEME_PATH . '/header.php';
?>

<style>
/* Page-specific */
.breadcrumb { display: flex; gap: 6px; align-items: center; font-size: 0.9rem; color: var(--muted); margin-bottom: 14px; flex-wrap: wrap; }
.breadcrumb a { color: var(--accent-strong); text-decoration: none; }
.breadcrumb a:hover { text-decoration: underline; }
.breadcrumb .sep { color: var(--muted-2); }

.section-title { font-size: 0.92rem; color: var(--muted); font-weight: 600; margin: 6px 0 12px; }

.folder-strip { display: grid; grid-auto-flow: column; grid-auto-columns: minmax(190px, 1fr); gap: 14px; overflow-x: auto; padding-bottom: 4px; margin-bottom: 22px; }
.folder-card {
    background: #fff;
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 16px;
    text-decoration: none;
    color: inherit;
    display: flex;
    flex-direction: column;
    gap: 10px;
    transition: border-color .15s, box-shadow .15s, transform .15s;
}
.folder-card:hover { border-color: var(--accent); box-shadow: 0 4px 14px rgba(34,197,94,0.12); transform: translateY(-1px); }
.folder-card .icon-wrap {
    width: 38px; height: 38px; border-radius: 50%;
    background: #eef2f6;
    display: flex; align-items: center; justify-content: center;
    color: var(--muted);
}
.folder-card .icon-wrap svg { width: 20px; height: 20px; }
.folder-card .count { font-weight: 800; font-size: 1.05rem; }
.folder-card .name { font-weight: 600; }
.folder-card .meta { font-size: 0.78rem; color: var(--muted); display: flex; align-items: center; gap: 4px; }

.filter-bar {
    background: #fff;
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 14px 16px;
    display: grid;
    grid-template-columns: 1.6fr 1fr 1fr 1fr 0.8fr;
    gap: 12px;
    margin-bottom: 18px;
}
.filter-bar .field { display: flex; flex-direction: column; gap: 4px; min-width: 0; }
.filter-bar .field label { font-size: 0.72rem; color: var(--muted); font-weight: 600; text-transform: none; margin: 0; }
.filter-bar input, .filter-bar select { margin: 0; padding: 9px 12px; font-size: 0.85rem; background: #f5f7fa; border: 1px solid transparent; border-radius: 999px; }
.filter-bar input:focus, .filter-bar select:focus { background: #fff; border-color: var(--accent); }
@media (max-width: 1100px) { .filter-bar { grid-template-columns: 1fr 1fr; } }

.list-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; padding: 4px 4px; }
.list-head label { display: flex; align-items: center; gap: 8px; cursor: pointer; font-size: 0.9rem; color: var(--muted); margin: 0; }
.list-head input[type="checkbox"] { width: 18px; height: 18px; accent-color: var(--accent); }

.qr-list { display: flex; flex-direction: column; gap: 10px; }
.qr-row {
    background: #fff;
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 14px 18px;
    display: grid;
    grid-template-columns: 22px 64px 1.4fr 1.6fr 110px auto;
    align-items: center;
    gap: 18px;
    transition: border-color .15s, box-shadow .15s;
    position: relative;
}
.qr-row:hover { border-color: var(--accent); box-shadow: 0 2px 10px rgba(34,197,94,0.08); }
.qr-row.selected { border-color: var(--accent); box-shadow: 0 0 0 2px var(--accent-soft); }
.qr-row input[type="checkbox"] { width: 18px; height: 18px; accent-color: var(--accent); margin: 0; }

.qr-thumb {
    width: 64px; height: 64px;
    border-radius: 8px;
    border: 1px solid var(--border);
    background: #fff;
    object-fit: contain;
    cursor: pointer;
    padding: 4px;
}
.qr-row-main { display: flex; flex-direction: column; gap: 4px; min-width: 0; }
.qr-row-title { font-weight: 700; font-size: 0.98rem; }
.qr-row-date { font-size: 0.78rem; color: var(--muted); display: flex; align-items: center; gap: 5px; }

.qr-row-meta { display: flex; flex-direction: column; gap: 4px; min-width: 0; font-size: 0.82rem; }
.qr-row-meta .meta-line { display: flex; align-items: center; gap: 6px; color: var(--muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.qr-row-meta .meta-line.url { color: var(--accent-strong); }
.qr-row-meta .meta-line.url a { color: inherit; text-decoration: none; overflow: hidden; text-overflow: ellipsis; }

.qr-row-scans { display: flex; flex-direction: column; align-items: center; gap: 2px; }
.qr-row-scans .label { font-size: 0.78rem; color: var(--info); cursor: pointer; text-decoration: none; }
.qr-row-scans .num {
    font-size: 1.4rem; font-weight: 800; color: var(--info);
    width: 56px; height: 56px; border-radius: 50%;
    background: #eef5ff;
    display: flex; align-items: center; justify-content: center;
}

.qr-row-actions { display: flex; gap: 8px; align-items: center; position: relative; }
.kebab {
    width: 36px; height: 36px;
    background: var(--accent);
    border: 0;
    border-radius: 8px;
    color: #fff;
    cursor: pointer;
    font-size: 1.1rem;
    line-height: 1;
    display: flex; align-items: center; justify-content: center;
}
.kebab:hover { background: var(--accent-strong); }

.row-menu {
    position: absolute;
    top: 100%; right: 0;
    margin-top: 4px;
    background: #fff;
    border: 1px solid var(--border);
    border-radius: 10px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.12);
    min-width: 180px;
    padding: 6px;
    z-index: 50;
    display: none;
}
.row-menu.open { display: block; }
.row-menu button {
    display: flex; align-items: center; gap: 8px;
    width: 100%;
    background: transparent;
    border: 0;
    text-align: left;
    padding: 8px 10px;
    border-radius: 6px;
    cursor: pointer;
    font-size: 0.86rem;
    color: var(--text);
}
.row-menu button:hover { background: #f5f7fa; }
.row-menu button.danger { color: var(--danger); }
.row-menu hr { border: 0; border-top: 1px solid var(--border); margin: 4px 0; }

@media (max-width: 1100px) {
    .qr-row { grid-template-columns: 22px 60px 1fr auto; }
    .qr-row-meta, .qr-row-scans { display: none; }
}

.empty {
    text-align: center;
    padding: 50px 20px;
    color: var(--muted);
    background: #fff;
    border: 1px dashed var(--border-strong);
    border-radius: 12px;
}

/* Preview modal */
.preview-img-wrap {
    background: #fff;
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 18px;
    text-align: center;
}
.preview-img-wrap img { max-width: 100%; height: auto; border-radius: 8px; }
.preview-meta { font-size: 0.85rem; color: var(--muted); margin-top: 12px; word-break: break-all; }
</style>

<?php if ($showTrash): ?>
<div class="topbar">
    <div style="display:flex; align-items:center; gap:12px;">
        <a href="<?= htmlspecialchars(BASE_URL) ?>" class="btn btn-ghost btn-sm">&larr; Back</a>
        <h1 style="color: var(--danger);">Trash</h1>
    </div>
</div>
<?php else: ?>

<div class="topbar">
    <h1>My QR Codes</h1>
    <div class="topbar-actions">
        <button class="btn btn-outline" onclick="openFolderAdd(<?= $currentFolderId !== null ? (int)$currentFolderId : 'null' ?>)">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M12 11v6M9 14h6"/></svg>
            New Folder
        </button>
        <button class="btn" onclick="openModal('addModal')">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg>
            Create QR Code
        </button>
        <a href="?trash" class="btn btn-ghost btn-sm" title="Trash">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M6 6l1 14a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-14"/></svg>
        </a>
    </div>
</div>

<?php if (!empty($folderPath)): ?>
<div class="breadcrumb">
    <a href="<?= htmlspecialchars(BASE_URL) ?>">Home</a>
    <?php foreach ($folderPath as $i => $f): ?>
        <span class="sep">/</span>
        <?php if ($i === count($folderPath) - 1): ?>
            <strong><?= htmlspecialchars($f['name']) ?></strong>
            <button class="btn btn-ghost btn-sm" style="margin-left:8px;" onclick="openFolderRename(<?= (int)$f['id'] ?>, <?= htmlspecialchars(json_encode($f['name']), ENT_QUOTES) ?>)">Rename</button>
            <button class="btn btn-ghost btn-sm" style="color:var(--danger); border-color:var(--danger);" onclick="confirmFolderDelete(<?= (int)$f['id'] ?>)">Delete</button>
        <?php else: ?>
            <a href="?folder=<?= (int)$f['id'] ?>"><?= htmlspecialchars($f['name']) ?></a>
        <?php endif; ?>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if (!empty($subfolders)): ?>
<div class="section-title">My Folders</div>
<div class="folder-strip">
    <?php foreach ($subfolders as $f): ?>
        <a class="folder-card" href="?folder=<?= (int)$f['id'] ?>">
            <div class="icon-wrap">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>
            </div>
            <div class="count"><?= (int)$f['qr_count'] ?> Files<?= (int)$f['sub_count'] ? ' · ' . (int)$f['sub_count'] . ' sub' : '' ?></div>
            <div class="name"><?= htmlspecialchars($f['name']) ?></div>
            <div class="meta">
                <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
                <?= date('M d, Y', strtotime($f['created_at'])) ?>
            </div>
        </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="filter-bar">
    <div class="field">
        <label>My QR Codes</label>
        <input type="search" id="filterSearch" placeholder="Search here">
    </div>
    <div class="field">
        <label>QR Code Status</label>
        <select id="filterStatus">
            <option value="">All</option>
            <option value="active">Active</option>
            <option value="disabled">Disabled</option>
        </select>
    </div>
    <div class="field">
        <label>QR Code Type</label>
        <select id="filterType">
            <option value="">All</option>
            <?php foreach (ALLOWED_TYPES as $t): ?>
                <option value="<?= htmlspecialchars($t) ?>"><?= htmlspecialchars(qr_type_label($t)) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field">
        <label>Sort by</label>
        <select id="filterSort">
            <option value="recent">Most recent</option>
            <option value="scans">Most scans</option>
            <option value="title">Title (A–Z)</option>
        </select>
    </div>
    <div class="field">
        <label>Quantity</label>
        <select id="filterQty">
            <option value="10">10</option>
            <option value="25">25</option>
            <option value="50">50</option>
            <option value="0" selected>All</option>
        </select>
    </div>
</div>

<div class="list-head">
    <label><input type="checkbox" id="selectAll"> Select All</label>
    <button class="btn btn-outline btn-sm" id="bulkDownload">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 4v12M6 10l6 6 6-6M5 20h14"/></svg>
        Download
    </button>
</div>
<?php endif; ?>

<div class="qr-list" id="qrList">
    <?php if (empty($products)): ?>
        <div class="empty">
            <?= $showTrash ? 'Trash is empty.' : 'No QR codes yet. Click "Create QR Code" to make one.' ?>
        </div>
    <?php endif; ?>

    <?php foreach($products as $p):
        $title  = htmlspecialchars($p['title']);
        $type   = $p['type'];
        $folder = $p['folder_name'] !== null ? htmlspecialchars($p['folder_name']) : 'No folder';
        $url    = qr_short_target($p);
        $stats  = (int)$p['scan_count'];
        $imgVer = substr(md5(($p['design_json'] ?? '') . ($p['logo_path'] ?? '') . ($p['target_data'] ?? '')), 0, 8);
        $imgSrc = htmlspecialchars(BASE_URL) . '/generate_image.php?id=' . urlencode($p['uuid']) . '&format=png&v=' . $imgVer;
        $isWifi = $type === 'wifi';
        $editPayload = json_encode([
            'id'          => $p['id'],
            'title'       => $p['title'],
            'type'        => $p['type'],
            'target_data' => $p['target_data'],
            'folder_id'   => $p['folder_id'] !== null ? (int)$p['folder_id'] : '',
        ]);
        $designPayload = json_encode(json_decode($p['design_json'] ?? '') ?: new stdClass());
    ?>
    <div class="qr-row"
         data-id="<?= (int)$p['id'] ?>"
         data-uuid="<?= htmlspecialchars($p['uuid']) ?>"
         data-title="<?= $title ?>"
         data-type="<?= htmlspecialchars($type) ?>"
         data-active="<?= $p['is_active'] ? '1' : '0' ?>"
         data-scans="<?= $stats ?>"
         data-search="<?= htmlspecialchars(strtolower($p['title'] . ' ' . $url . ' ' . $type)) ?>"
         data-created="<?= htmlspecialchars($p['created_at']) ?>">
        <input type="checkbox" class="row-check">
        <img class="qr-thumb" src="<?= $imgSrc ?>" alt="QR" loading="lazy"
             onclick='openPreview(<?= htmlspecialchars(json_encode([
                 'uuid'  => $p['uuid'],
                 'title' => $p['title'],
                 'type'  => qr_type_label($p['type']),
                 'url'   => $url,
             ]), ENT_QUOTES) ?>)'>
        <div class="qr-row-main">
            <span class="badge badge-type"><?= htmlspecialchars(qr_type_label($type)) ?></span>
            <div class="qr-row-title"><?= $title ?><?php if ($isAdmin && !empty($p['owner_username'])): ?> <span style="font-size:0.7em; color:var(--muted); font-weight:500;">· <?= htmlspecialchars($p['owner_username']) ?></span><?php endif; ?></div>
            <div class="qr-row-date">
                <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
                <?= date('M d, Y', strtotime($p['created_at'])) ?>
            </div>
        </div>
        <div class="qr-row-meta">
            <div class="meta-line">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>
                <?= $folder ?>
            </div>
            <div class="meta-line url">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/></svg>
                <a href="<?= htmlspecialchars(BASE_URL) ?>/p/<?= htmlspecialchars($p['uuid']) ?>" target="_blank" rel="noopener">
                    <?= htmlspecialchars($url) ?>
                </a>
            </div>
            <div class="meta-line">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9M16.5 3.5a2.12 2.12 0 1 1 3 3L7 19l-4 1 1-4z"/></svg>
                Modified: <?= date('M d, Y', strtotime($p['created_at'])) ?>
            </div>
        </div>
        <?php if ($showTrash): ?>
            <form method="POST" style="margin:0; grid-column: span 2;">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="action" value="restore">
                <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                <button type="submit" class="btn">Restore</button>
            </form>
        <?php else: ?>
            <?php if ($isWifi): ?>
                <div class="qr-row-scans"><span class="label">Scans</span><span class="num" style="background:#f1f3f5; color:var(--muted-2);">—</span></div>
            <?php else: ?>
                <a class="qr-row-scans" href="stats.php?uuid=<?= htmlspecialchars($p['uuid']) ?>" style="text-decoration:none;">
                    <span class="label">Scans</span>
                    <span class="num"><?= $stats ?></span>
                </a>
            <?php endif; ?>
            <div class="qr-row-actions">
                <a class="btn btn-outline btn-sm" href="stats.php?uuid=<?= htmlspecialchars($p['uuid']) ?>">Detail</a>
                <button class="kebab" onclick="toggleRowMenu(this, event)" aria-label="More">
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><circle cx="5" cy="12" r="2"/><circle cx="12" cy="12" r="2"/><circle cx="19" cy="12" r="2"/></svg>
                </button>
                <div class="row-menu">
                    <button onclick='openPreview(<?= htmlspecialchars(json_encode([
                        'uuid'  => $p['uuid'],
                        'title' => $p['title'],
                        'type'  => qr_type_label($p['type']),
                        'url'   => $url,
                    ]), ENT_QUOTES) ?>)'>
                        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"/><circle cx="12" cy="12" r="3"/></svg>
                        Preview
                    </button>
                    <button onclick='openDesigner(<?= htmlspecialchars(json_encode($p['uuid']), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($p['title']), ENT_QUOTES) ?>, <?= (int)$p['id'] ?>, <?= htmlspecialchars($designPayload, ENT_QUOTES) ?>)'>
                        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 19l7-7 3 3-7 7-3-3z"/><path d="M18 13l-1.5-7.5L2 2l3.5 14.5L13 18z"/><path d="M2 2l7.586 7.586"/><circle cx="11" cy="11" r="2"/></svg>
                        Edit Design
                    </button>
                    <button onclick='openEditModal(<?= htmlspecialchars($editPayload, ENT_QUOTES) ?>)'>
                        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9M16.5 3.5a2.12 2.12 0 1 1 3 3L7 19l-4 1 1-4z"/></svg>
                        Edit Info
                    </button>
                    <button onclick="toggleQRRow(<?= (int)$p['id'] ?>, this)">
                        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><circle cx="8" cy="12" r="4"/><path d="M8 8h8a4 4 0 0 1 0 8H8"/></svg>
                        <?= $p['is_active'] ? 'Disable' : 'Enable' ?>
                    </button>
                    <hr>
                    <button class="danger" onclick="confirmDelete(<?= (int)$p['id'] ?>)">
                        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M6 6l1 14a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-14"/></svg>
                        Delete
                    </button>
                </div>
            </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>

<!-- ── Preview Modal (image only — no design controls) ───────────────────────── -->
<div id="previewModal" class="modal">
    <div class="modal-content" style="max-width:480px;">
        <svg class="close-icon" onclick="closeModal('previewModal')" viewBox="0 0 24 24"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>
        <h2 id="previewTitle" style="margin-top:0;">QR Preview</h2>
        <div class="preview-img-wrap">
            <img id="previewImg" src="" alt="">
            <div class="dl-row" style="margin-top:14px;">
                <a id="previewDlPng" href="#" download class="btn btn-outline btn-sm">PNG</a>
                <a id="previewDlJpg" href="#" download class="btn btn-outline btn-sm">JPG</a>
                <a id="previewDlSvg" href="#" download class="btn btn-outline btn-sm">SVG</a>
                <button onclick="printPreview()" class="btn btn-ghost btn-sm">Print</button>
            </div>
        </div>
        <div class="preview-meta" id="previewMeta"></div>
    </div>
</div>

<!-- ── Add Modal ──────────────────────────────────────────────────────────────── -->
<div id="addModal" class="modal">
    <div class="modal-content">
        <svg class="close-icon" onclick="closeModal('addModal')" viewBox="0 0 24 24">
            <path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/>
        </svg>
        <h2>Add QR Code</h2>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="action" value="add">
            <label>Title</label>
            <input type="text" name="title" required placeholder="Product Name" maxlength="255">
            <label>Type</label>
            <select name="type" id="typeSelect" onchange="toggleFields('add')">
                <option value="url">Website URL</option>
                <option value="phone">Phone Number</option>
                <option value="map">Map Location</option>
                <option value="vcard">vCard Contact</option>
                <option value="wifi">Wi-Fi Network</option>
                <option value="sms">SMS Message</option>
                <option value="email">Email Message</option>
                <option value="social">Social Media</option>
            </select>

            <div id="add-field-general" class="type-fields" style="display:block;"><input type="text" name="target" placeholder="https://example.com" maxlength="2048"></div>
            <div id="add-field-vcard" class="type-fields">
                <input type="text" name="v_fname" placeholder="First Name" maxlength="100">
                <input type="text" name="v_lname" placeholder="Last Name" maxlength="100">
                <input type="text" name="v_phone" placeholder="Phone" maxlength="30">
                <input type="email" name="v_email" placeholder="Email" maxlength="255">
                <input type="text" name="v_company" placeholder="Company" maxlength="255">
            </div>
            <div id="add-field-wifi" class="type-fields">
                <input type="text" name="wifi_ssid" placeholder="Network Name (SSID)" maxlength="32">
                <input type="text" name="wifi_pass" placeholder="Password" maxlength="63">
                <select name="wifi_enc"><option value="WPA">WPA/WPA2</option><option value="WEP">WEP</option><option value="nopass">No Encryption</option></select>
            </div>
            <div id="add-field-sms" class="type-fields">
                <input type="tel" name="sms_phone" placeholder="Phone Number" maxlength="30">
                <textarea name="sms_body" placeholder="Message Body" maxlength="1000"></textarea>
            </div>
            <div id="add-field-email" class="type-fields">
                <input type="email" name="email_addr" placeholder="Recipient" maxlength="255">
                <input type="text" name="email_sub" placeholder="Subject" maxlength="255">
                <textarea name="email_body" placeholder="Body" maxlength="2000"></textarea>
            </div>

            <label>Folder</label>
            <select name="folder_id">
                <option value="">— No folder (Home) —</option>
                <?= build_folder_options($allFolders, $currentFolderId) ?>
            </select>

            <label>Embedded Logo (Optional — PNG or JPG only)</label>
            <input type="file" name="logo" accept="image/png, image/jpeg">

            <fieldset style="border:1px solid var(--border); border-radius:6px; padding:12px; margin:10px 0 20px;">
                <legend style="font-weight:bold; padding:0 6px;">Design</legend>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px;">
                    <label style="margin:0;">Foreground<input type="color" name="design[fg]" value="#000000"></label>
                    <label style="margin:0;">Background<input type="color" name="design[bg]" value="#ffffff"></label>
                    <label style="margin:0;">Module shape
                        <select name="design[module_shape]">
                            <option value="square">Square</option>
                            <option value="dot">Dots</option>
                            <option value="rounded">Rounded</option>
                            <option value="classy">Classy</option>
                            <option value="diamond">Diamond</option>
                        </select>
                    </label>
                    <label style="margin:0;">Eye shape
                        <select name="design[eye_shape]">
                            <option value="square">Square</option>
                            <option value="rounded">Rounded</option>
                            <option value="circle">Circle</option>
                            <option value="leaf">Leaf</option>
                        </select>
                    </label>
                    <label style="margin:0;">Eye color<input type="color" name="design[eye_color]" value="#000000"></label>
                    <label style="margin:0; display:flex; align-items:center; gap:6px;">
                        <input type="checkbox" name="design[gradient]" value="1" style="width:auto; margin:0;"> Gradient
                    </label>
                    <label style="margin:0;">Gradient end<input type="color" name="design[fg2]" value="#1e90ff"></label>
                </div>
            </fieldset>

            <button type="submit" class="btn" style="width:100%; margin-top:10px;">Generate QR</button>
        </form>
    </div>
</div>

<!-- ── Edit Modal ─────────────────────────────────────────────────────────────── -->
<div id="editModal" class="modal">
    <div class="modal-content">
        <svg class="close-icon" onclick="closeModal('editModal')" viewBox="0 0 24 24">
            <path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/>
        </svg>
        <h2>Edit QR Code <span id="editTypeLabel" style="font-size:0.65em; opacity:0.5; font-weight:normal;"></span></h2>
        <form method="POST" id="editForm">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id" id="editId">
            <label>Title</label>
            <input type="text" name="title" id="editTitle" required maxlength="255">

            <label>Folder</label>
            <select name="folder_id" id="editFolderId">
                <option value="">— No folder (Home) —</option>
                <?= build_folder_options($allFolders) ?>
            </select>

            <!-- General (url, phone, map, social) -->
            <div id="edit-field-general" class="type-fields" style="display:none;">
                <label id="editGeneralLabel">Target</label>
                <input type="text" name="target" id="editTarget" maxlength="2048">
            </div>
            <!-- vCard -->
            <div id="edit-field-vcard" class="type-fields" style="display:none;">
                <input type="text" name="v_fname" id="editFname" placeholder="First Name" maxlength="100">
                <input type="text" name="v_lname" id="editLname" placeholder="Last Name" maxlength="100">
                <input type="text" name="v_phone" id="editVphone" placeholder="Phone" maxlength="30">
                <input type="email" name="v_email" id="editVemail" placeholder="Email" maxlength="255">
                <input type="text" name="v_company" id="editVcompany" placeholder="Company" maxlength="255">
            </div>
            <!-- WiFi -->
            <div id="edit-field-wifi" class="type-fields" style="display:none;">
                <input type="text" name="wifi_ssid" id="editSsid" placeholder="Network Name (SSID)" maxlength="32">
                <input type="text" name="wifi_pass" id="editPass" placeholder="Password" maxlength="63">
                <select name="wifi_enc" id="editEnc">
                    <option value="WPA">WPA/WPA2</option>
                    <option value="WEP">WEP</option>
                    <option value="nopass">No Encryption</option>
                </select>
            </div>
            <!-- SMS -->
            <div id="edit-field-sms" class="type-fields" style="display:none;">
                <input type="tel" name="sms_phone" id="editSmsPhone" placeholder="Phone Number" maxlength="30">
                <textarea name="sms_body" id="editSmsBody" placeholder="Message Body" maxlength="1000"></textarea>
            </div>
            <!-- Email -->
            <div id="edit-field-email" class="type-fields" style="display:none;">
                <input type="email" name="email_addr" id="editEmailAddr" placeholder="Recipient" maxlength="255">
                <input type="text" name="email_sub" id="editEmailSub" placeholder="Subject" maxlength="255">
                <textarea name="email_body" id="editEmailBody" placeholder="Body" maxlength="2000"></textarea>
            </div>

            <button type="submit" class="btn" style="width:100%; margin-top:20px;">Save Changes</button>
        </form>
    </div>
</div>


<!-- ── QR Designer Modal ──────────────────────────────────────────────────────── -->
<div id="qrModal" class="modal">
    <div class="modal-content" style="max-width:880px;">
        <svg class="close-icon" onclick="closeModal('qrModal')" viewBox="0 0 24 24"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>
        <h2 id="qrTitle" style="margin-bottom:18px;">QR Designer</h2>

        <div class="designer-grid">
            <div class="preview-card">
                <div class="qr-canvas" id="qrCanvas">
                    <div class="qr-spinner" id="qrSpinner"></div>
                </div>
                <div class="dl-row">
                    <a id="dlPng" href="#" download class="btn btn-sm">PNG</a>
                    <a id="dlJpg" href="#" download class="btn btn-sm">JPG</a>
                    <a id="dlSvg" href="#" download class="btn btn-sm">SVG</a>
                    <button onclick="printQR()" class="btn btn-ghost btn-sm">Print</button>
                </div>
                <div style="margin-top:14px;">
                    <div style="font-size:0.78rem; color:var(--muted); font-weight:600; text-align:left; margin-bottom:6px;">PRESETS</div>
                    <div class="preset-row" id="dPresets"></div>
                </div>
            </div>

            <div class="designer-controls">
                <label>Foreground
                    <div class="swatch-pair">
                        <input type="color" id="dFg" value="#000000">
                    </div>
                </label>
                <label>Background
                    <div class="swatch-pair">
                        <input type="color" id="dBg" value="#ffffff">
                    </div>
                </label>

                <label>Module shape
                    <select id="dModule">
                        <option value="square">Square</option>
                        <option value="dot">Dots</option>
                        <option value="rounded">Rounded</option>
                        <option value="classy">Classy</option>
                        <option value="diamond">Diamond</option>
                        <option value="star">Star</option>
                        <option value="cross">Cross</option>
                    </select>
                </label>
                <label>Eye frame
                    <select id="dEye">
                        <option value="square">Square</option>
                        <option value="rounded">Rounded</option>
                        <option value="circle">Circle</option>
                        <option value="leaf">Leaf</option>
                        <option value="flower">Flower</option>
                        <option value="frame">Frame</option>
                    </select>
                </label>

                <label>Eye pupil
                    <select id="dEyeInner">
                        <option value="square">Square</option>
                        <option value="rounded">Rounded</option>
                        <option value="circle">Circle</option>
                        <option value="dot">Small dot</option>
                        <option value="diamond">Diamond</option>
                    </select>
                </label>
                <label>Eye color
                    <input type="color" id="dEyeColor" value="#000000">
                </label>

                <label class="full" style="display:flex; align-items:center; gap:10px; margin:0;">
                    <input type="checkbox" id="dGradient"> <span>Use gradient fill</span>
                </label>

                <label>Gradient end
                    <input type="color" id="dFg2" value="#1e90ff" disabled>
                </label>
                <label>Gradient direction
                    <select id="dGradDir" disabled>
                        <option value="diagonal">Diagonal</option>
                        <option value="horizontal">Horizontal</option>
                        <option value="vertical">Vertical</option>
                        <option value="radial">Radial</option>
                    </select>
                </label>

                <div class="full" style="display:flex; align-items:center; gap:12px; margin-top:6px;">
                    <button id="dSave" class="btn btn-sm">Save Design</button>
                    <button id="dReset" class="btn btn-ghost btn-sm">Reset</button>
                    <span id="dSaveMsg" style="color:var(--muted); font-size:0.85em;"></span>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ── Folder Add Modal ───────────────────────────────────────────────────────── -->
<div id="folderAddModal" class="modal">
    <div class="modal-content" style="max-width:420px;">
        <svg class="close-icon" onclick="closeModal('folderAddModal')" viewBox="0 0 24 24"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>
        <h2>New Folder</h2>
        <p id="folderAddParentLabel" style="color:var(--muted); margin:0 0 10px;"></p>
        <input type="text" id="folderAddName" placeholder="Folder name" maxlength="100">
        <button class="btn" style="width:100%;" onclick="submitFolderAdd()">Create</button>
        <div id="folderAddMsg" style="color:#dc3545; margin-top:8px; min-height:1em; font-size:0.85em;"></div>
    </div>
</div>

<!-- ── Folder Rename Modal ────────────────────────────────────────────────────── -->
<div id="folderRenameModal" class="modal">
    <div class="modal-content" style="max-width:420px;">
        <svg class="close-icon" onclick="closeModal('folderRenameModal')" viewBox="0 0 24 24"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>
        <h2>Rename Folder</h2>
        <input type="hidden" id="folderRenameId">
        <input type="text" id="folderRenameName" maxlength="100">
        <button class="btn" style="width:100%;" onclick="submitFolderRename()">Save</button>
    </div>
</div>

<!-- ── Folder Delete Confirm Modal ────────────────────────────────────────────── -->
<div id="folderDeleteModal" class="modal">
    <div class="modal-content" style="text-align:center; max-width:420px;">
        <svg class="close-icon" onclick="closeModal('folderDeleteModal')" viewBox="0 0 24 24"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>
        <h2>Delete this folder?</h2>
        <p>QR codes and subfolders inside will be moved up to the parent.</p>
        <div style="display:flex; gap:10px; justify-content:center; margin-top:20px;">
            <button class="btn btn-danger" onclick="submitFolderDelete()">Yes, delete</button>
            <button class="btn btn-ghost" onclick="closeModal('folderDeleteModal')">Cancel</button>
        </div>
    </div>
</div>

<!-- ── Delete Confirm Modal ───────────────────────────────────────────────────── -->
<div id="deleteModal" class="modal">
    <div class="modal-content" style="text-align: center;">
        <svg class="close-icon" onclick="closeModal('deleteModal')" viewBox="0 0 24 24"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>
        <h2>Are you sure?</h2>
        <p>This will move the QR code to Trash. You can restore it later.</p>
        <div style="margin-top: 20px; display:flex; gap:10px; justify-content:center;">
            <button id="confirmDeleteBtn" class="btn btn-danger">Yes, Delete</button>
            <button onclick="closeModal('deleteModal')" class="btn btn-ghost">Cancel</button>
        </div>
    </div>
</div>

<script>
const CSRF_TOKEN = <?= json_encode($csrfToken) ?>;

// ── Modal utilities ──────────────────────────────────────────────────────────
function openModal(id)  { document.getElementById(id).style.display = 'flex'; }
function closeModal(id) { document.getElementById(id).style.display = 'none'; }
window.addEventListener('click', function(e) {
    if (e.target.classList.contains('modal')) e.target.style.display = 'none';
});

// ── Print designer canvas (inline SVG) ───────────────────────────────────────
function printQR() {
    const canvas = document.getElementById('qrCanvas');
    const svg = canvas ? canvas.querySelector('svg') : null;
    if (!svg) return;
    const win = window.open('');
    win.document.write('<html><body style="text-align:center;"><h2 style="font-family:sans-serif">' +
        document.getElementById('qrTitle').innerText + '</h2>' + svg.outerHTML +
        '<script>window.onload=function(){window.print();window.close();}<\/script>' +
        '</body></html>');
    win.document.close();
}

// ── Add form type switcher ───────────────────────────────────────────────────
function toggleFields(prefix) {
    document.querySelectorAll('#addModal .type-fields').forEach(e => e.style.display = 'none');
    const type = document.getElementById('typeSelect').value;
    const generalInput = document.querySelector('#add-field-general input');
    if (['vcard','wifi','sms','email'].includes(type)) {
        document.getElementById('add-field-' + type).style.display = 'block';
    } else {
        document.getElementById('add-field-general').style.display = 'block';
        generalInput.type = (type === 'url' || type === 'social') ? 'url' : 'text';
        if (type === 'phone')      generalInput.placeholder = '+15550000000';
        else if (type === 'map')   generalInput.placeholder = '123 Main St, City, ST';
        else                       generalInput.placeholder = 'https://...';
    }
}

// ── Edit modal ───────────────────────────────────────────────────────────────
function openEditModal(data) {
    // Hide all edit fields
    document.querySelectorAll('#editModal .type-fields').forEach(e => e.style.display = 'none');

    document.getElementById('editId').value    = data.id;
    document.getElementById('editTitle').value = data.title;
    document.getElementById('editFolderId').value = data.folder_id === null || data.folder_id === undefined ? '' : data.folder_id;
    document.getElementById('editTypeLabel').textContent = '[' + data.type.toUpperCase() + ']';

    const type = data.type;
    let target = data.target_data;
    let parsed = null;
    try { parsed = JSON.parse(target); } catch(e) {}

    if (type === 'vcard' && parsed) {
        document.getElementById('edit-field-vcard').style.display = 'block';
        document.getElementById('editFname').value    = parsed.fname    || '';
        document.getElementById('editLname').value    = parsed.lname    || '';
        document.getElementById('editVphone').value   = parsed.phone    || '';
        document.getElementById('editVemail').value   = parsed.email    || '';
        document.getElementById('editVcompany').value = parsed.company  || '';
    } else if (type === 'wifi' && parsed) {
        document.getElementById('edit-field-wifi').style.display = 'block';
        document.getElementById('editSsid').value = parsed.ssid || '';
        document.getElementById('editPass').value = parsed.pass || '';
        const encSel = document.getElementById('editEnc');
        for (let opt of encSel.options) { if (opt.value === parsed.enc) { opt.selected = true; break; } }
    } else if (type === 'sms' && parsed) {
        document.getElementById('edit-field-sms').style.display = 'block';
        document.getElementById('editSmsPhone').value = parsed.phone || '';
        document.getElementById('editSmsBody').value  = parsed.body  || '';
    } else if (type === 'email' && parsed) {
        document.getElementById('edit-field-email').style.display = 'block';
        document.getElementById('editEmailAddr').value = parsed.email   || '';
        document.getElementById('editEmailSub').value  = parsed.subject || '';
        document.getElementById('editEmailBody').value = parsed.body    || '';
    } else {
        // url, phone, map, social
        document.getElementById('edit-field-general').style.display = 'block';
        const lbl = { url:'Target URL', phone:'Phone Number', map:'Map Address', social:'Profile URL' };
        document.getElementById('editGeneralLabel').textContent = lbl[type] || 'Target';
        document.getElementById('editTarget').value = target;
    }

    openModal('editModal');
}

// ── Toggle QR active ─────────────────────────────────────────────────────────
function toggleQR(id, csrf) {
    const fd = new FormData();
    fd.append('action', 'toggle');
    fd.append('id', id);
    fd.append('csrf_token', csrf);
    fetch('index.php', { method: 'POST', body: fd });
}

// ── Designer modal ───────────────────────────────────────────────────────────
let DESIGNER_STATE = { uuid: null, id: null, title: '' };
const DESIGN_DEFAULT = {
    fg:'#000000', bg:'#ffffff',
    gradient:false, gradient_dir:'diagonal', fg2:'#1e90ff',
    module_shape:'square',
    eye_shape:'square', eye_inner:'square',
    eye_color:'#000000'
};

const PRESETS = [
    { name:'Classic',    fg:'#000000', bg:'#ffffff', module_shape:'square',  eye_shape:'square',  eye_inner:'square',  eye_color:'#000000', gradient:false },
    { name:'Ocean',      fg:'#0a4a6e', bg:'#e8f4fa', module_shape:'rounded', eye_shape:'rounded', eye_inner:'rounded', eye_color:'#0a4a6e', gradient:true,  fg2:'#1e90ff', gradient_dir:'diagonal' },
    { name:'Sunset',     fg:'#d63384', bg:'#fff7e6', module_shape:'dot',     eye_shape:'circle',  eye_inner:'circle',  eye_color:'#d63384', gradient:true,  fg2:'#fd7e14', gradient_dir:'horizontal' },
    { name:'Forest',     fg:'#1b5e20', bg:'#f1f8e9', module_shape:'rounded', eye_shape:'leaf',    eye_inner:'rounded', eye_color:'#2e7d32', gradient:true,  fg2:'#558b2f', gradient_dir:'vertical' },
    { name:'Royal',      fg:'#4527a0', bg:'#f3e5f5', module_shape:'classy',  eye_shape:'rounded', eye_inner:'rounded', eye_color:'#4527a0', gradient:true,  fg2:'#7b1fa2', gradient_dir:'radial' },
    { name:'Mono Dots',  fg:'#1a1a1a', bg:'#ffffff', module_shape:'dot',     eye_shape:'circle',  eye_inner:'circle',  eye_color:'#1a1a1a', gradient:false },
    { name:'Neon',       fg:'#00e5ff', bg:'#0a1929', module_shape:'dot',     eye_shape:'circle',  eye_inner:'dot',     eye_color:'#00e5ff', gradient:true,  fg2:'#7c4dff', gradient_dir:'diagonal' },
    { name:'Coral',      fg:'#c2185b', bg:'#ffe9ec', module_shape:'star',    eye_shape:'flower',  eye_inner:'circle',  eye_color:'#ad1457', gradient:false },
    { name:'Diamond',    fg:'#000000', bg:'#ffffff', module_shape:'diamond', eye_shape:'leaf',    eye_inner:'diamond', eye_color:'#000000', gradient:false },
];

function previewSwatch(p) {
    if (p.gradient) {
        return 'linear-gradient(135deg,' + p.fg + ',' + p.fg2 + ')';
    }
    return 'linear-gradient(135deg,' + p.fg + ' 50%,' + p.bg + ' 50%)';
}

function buildPresetChips() {
    const row = document.getElementById('dPresets');
    if (!row || row.dataset.built) return;
    row.dataset.built = '1';
    PRESETS.forEach((p, i) => {
        const b = document.createElement('button');
        b.type = 'button';
        b.className = 'preset-chip';
        b.title = p.name;
        b.style.background = previewSwatch(p);
        b.addEventListener('click', () => { applyDesignerInputs(p); refreshPreview(); });
        row.appendChild(b);
    });
}

function readDesigner() {
    return {
        fg:           document.getElementById('dFg').value,
        bg:           document.getElementById('dBg').value,
        gradient:     document.getElementById('dGradient').checked,
        gradient_dir: document.getElementById('dGradDir').value,
        fg2:          document.getElementById('dFg2').value,
        module_shape: document.getElementById('dModule').value,
        eye_shape:    document.getElementById('dEye').value,
        eye_inner:    document.getElementById('dEyeInner').value,
        eye_color:    document.getElementById('dEyeColor').value,
    };
}

function applyDesignerInputs(d) {
    d = Object.assign({}, DESIGN_DEFAULT, d || {});
    document.getElementById('dFg').value         = d.fg;
    document.getElementById('dBg').value         = d.bg;
    document.getElementById('dGradient').checked = !!d.gradient;
    document.getElementById('dGradDir').value    = d.gradient_dir;
    document.getElementById('dFg2').value        = d.fg2;
    document.getElementById('dModule').value     = d.module_shape;
    document.getElementById('dEye').value        = d.eye_shape;
    document.getElementById('dEyeInner').value   = d.eye_inner;
    document.getElementById('dEyeColor').value   = d.eye_color;
    syncGradientEnable();
}

function syncGradientEnable() {
    const on = document.getElementById('dGradient').checked;
    document.getElementById('dFg2').disabled    = !on;
    document.getElementById('dGradDir').disabled = !on;
}

function buildDesignQS(d) {
    const p = new URLSearchParams();
    p.set('fg', d.fg); p.set('bg', d.bg);
    p.set('fg2', d.fg2); p.set('eye_color', d.eye_color);
    p.set('module_shape', d.module_shape);
    p.set('eye_shape', d.eye_shape);
    p.set('eye_inner', d.eye_inner);
    p.set('gradient', d.gradient ? '1' : '0');
    p.set('gradient_dir', d.gradient_dir);
    return p.toString();
}

// Cached matrix (size, cells, hasLogo) keyed by uuid
const MATRIX_CACHE = {};

async function loadMatrix(uuid) {
    if (MATRIX_CACHE[uuid]) return MATRIX_CACHE[uuid];
    setSpinner(true);
    try {
        const res = await fetch('generate_image.php?id=' + encodeURIComponent(uuid) + '&format=json');
        if (!res.ok) throw new Error('fetch matrix failed');
        const j = await res.json();
        MATRIX_CACHE[uuid] = j;
        return j;
    } finally {
        setSpinner(false);
    }
}

function setSpinner(on) {
    const sp = document.getElementById('qrSpinner');
    if (sp) sp.classList.toggle('on', !!on);
}

// ── Client-side SVG renderer (mirrors PHP) ───────────────────────────────────
const SCALE = 14;
const QZ    = 4;

function moduleShapeSvg(shape, x, y, s, cx, cy) {
    switch (shape) {
        case 'dot':     return '<circle cx="'+cx+'" cy="'+cy+'" r="'+(s*0.46)+'"/>';
        case 'rounded': return '<rect x="'+x+'" y="'+y+'" width="'+s+'" height="'+s+'" rx="'+(s*0.4)+'"/>';
        case 'classy': {
            const r = s * 0.45;
            return '<path d="M'+(x+r)+' '+y+' L'+(x+s)+' '+y+' L'+(x+s)+' '+(y+s-r)+' A'+r+' '+r+' 0 0 1 '+(x+s-r)+' '+(y+s)+' L'+x+' '+(y+s)+' L'+x+' '+(y+r)+' A'+r+' '+r+' 0 0 1 '+(x+r)+' '+y+' Z"/>';
        }
        case 'diamond': return '<path d="M'+cx+' '+(y+0.5)+' L'+(x+s-0.5)+' '+cy+' L'+cx+' '+(y+s-0.5)+' L'+(x+0.5)+' '+cy+' z"/>';
        case 'star': {
            const r1 = s*0.5, r2 = s*0.22;
            let pts = '';
            for (let i = 0; i < 10; i++) {
                const r = i % 2 === 0 ? r1 : r2;
                const a = -Math.PI/2 + i * Math.PI/5;
                pts += (i ? ' ' : '') + (cx + r*Math.cos(a)).toFixed(2) + ',' + (cy + r*Math.sin(a)).toFixed(2);
            }
            return '<polygon points="'+pts+'"/>';
        }
        case 'cross': {
            const t = s*0.36, e = (s-t)/2;
            return '<path d="M'+(cx-t/2)+' '+(y+0.5)+' h'+t+' v'+e+' h'+e+' v'+t+' h-'+e+' v'+e+' h-'+t+' v-'+e+' h-'+e+' v-'+t+' h'+e+' z"/>';
        }
        default: return '<rect x="'+x+'" y="'+y+'" width="'+s+'" height="'+s+'"/>';
    }
}

function roundedRectPath(x, y, w, h, r) {
    r = Math.min(r, w/2, h/2);
    return 'M'+(x+r)+' '+y
        +' h'+(w-2*r)
        +' a'+r+' '+r+' 0 0 1 '+r+' '+r
        +' v'+(h-2*r)
        +' a'+r+' '+r+' 0 0 1 -'+r+' '+r
        +' h-'+(w-2*r)
        +' a'+r+' '+r+' 0 0 1 -'+r+' -'+r
        +' v-'+(h-2*r)
        +' a'+r+' '+r+' 0 0 1 '+r+' -'+r+' Z ';
}

function leafRectPath(x, y, w, h, r) {
    r = Math.min(r, w/2, h/2);
    return 'M'+(x+r)+' '+y
        +' L'+(x+w)+' '+y
        +' L'+(x+w)+' '+(y+h-r)
        +' A'+r+' '+r+' 0 0 1 '+(x+w-r)+' '+(y+h)
        +' L'+x+' '+(y+h)
        +' L'+x+' '+(y+r)
        +' A'+r+' '+r+' 0 0 1 '+(x+r)+' '+y+' Z ';
}

function eyeOuterPath(shape, x, y, s, color) {
    const outer = 7*s, ix = x+s, iy = y+s, iw = 5*s;
    const cx = x+outer/2, cy = y+outer/2;
    const or = outer/2, ir = (5*s)/2;

    if (shape === 'circle') {
        return '<path fill-rule="evenodd" fill="'+color+'" d="'
            +'M'+(cx-or)+' '+cy+' a'+or+' '+or+' 0 1 0 '+(2*or)+' 0 a'+or+' '+or+' 0 1 0 -'+(2*or)+' 0 Z'
            +' M'+(cx-ir)+' '+cy+' a'+ir+' '+ir+' 0 1 1 '+(2*ir)+' 0 a'+ir+' '+ir+' 0 1 1 -'+(2*ir)+' 0 Z"/>';
    }
    if (shape === 'rounded') {
        return '<path fill-rule="evenodd" fill="'+color+'" d="'
            + roundedRectPath(x, y, outer, outer, s*1.6)
            + roundedRectPath(ix, iy, iw, iw, s*1.0)
            +'"/>';
    }
    if (shape === 'leaf') {
        return '<path fill-rule="evenodd" fill="'+color+'" d="'
            + leafRectPath(x, y, outer, outer, s*1.8)
            + leafRectPath(ix, iy, iw, iw, s*1.0)
            +'"/>';
    }
    if (shape === 'flower') {
        return '<path fill-rule="evenodd" fill="'+color+'" d="'
            + roundedRectPath(x, y, outer, outer, s*2.4)
            + roundedRectPath(ix, iy, iw, iw, s*1.6)
            +'"/>';
    }
    // square / frame
    return '<path fill-rule="evenodd" fill="'+color+'" d="'
        +'M'+x+' '+y+' h'+outer+' v'+outer+' h-'+outer+' Z '
        +'M'+ix+' '+iy+' v'+iw+' h'+iw+' v-'+iw+' Z"/>';
}

function eyeInnerShape(shape, x, y, s, color) {
    const iw = 3*s, ix = x+2*s, iy = y+2*s;
    const cx = ix + iw/2, cy = iy + iw/2;
    if (shape === 'circle')  return '<circle cx="'+cx+'" cy="'+cy+'" r="'+(iw/2)+'" fill="'+color+'"/>';
    if (shape === 'dot')     return '<circle cx="'+cx+'" cy="'+cy+'" r="'+(iw*0.42)+'" fill="'+color+'"/>';
    if (shape === 'rounded') return '<rect x="'+ix+'" y="'+iy+'" width="'+iw+'" height="'+iw+'" rx="'+(s*0.7)+'" fill="'+color+'"/>';
    if (shape === 'diamond') return '<path fill="'+color+'" d="M'+cx+' '+iy+' L'+(ix+iw)+' '+cy+' L'+cx+' '+(iy+iw)+' L'+ix+' '+cy+' Z"/>';
    return '<rect x="'+ix+'" y="'+iy+'" width="'+iw+'" height="'+iw+'" fill="'+color+'"/>';
}

function renderClientSvg(matrix, d) {
    const size = matrix.size;
    const cells = matrix.cells;
    const hasLogo = !!matrix.hasLogo;
    const logoData = matrix.logo || null;
    const total = size + 2 * QZ;
    const px = total * SCALE;
    const offset = QZ * SCALE;
    const qrPx = size * SCALE;

    const fillData = d.gradient ? 'url(#qrg)' : d.fg;
    let defs = '';
    if (d.gradient) {
        if (d.gradient_dir === 'radial') {
            defs = '<radialGradient id="qrg" gradientUnits="userSpaceOnUse" cx="'+(qrPx/2)+'" cy="'+(qrPx/2)+'" r="'+(qrPx*0.7)+'">'
                 + '<stop offset="0%" stop-color="'+d.fg+'"/>'
                 + '<stop offset="100%" stop-color="'+d.fg2+'"/></radialGradient>';
        } else {
            const m = { horizontal:[0,0,qrPx,0], vertical:[0,0,0,qrPx], diagonal:[0,0,qrPx,qrPx] };
            const c = m[d.gradient_dir] || m.diagonal;
            defs = '<linearGradient id="qrg" gradientUnits="userSpaceOnUse" x1="'+c[0]+'" y1="'+c[1]+'" x2="'+c[2]+'" y2="'+c[3]+'">'
                 + '<stop offset="0%" stop-color="'+d.fg+'"/>'
                 + '<stop offset="100%" stop-color="'+d.fg2+'"/></linearGradient>';
        }
    }

    const eyes = [[0,0],[size-7,0],[0,size-7]];
    const isInEye = (x, y) => eyes.some(([ex,ey]) => x>=ex && x<ex+7 && y>=ey && y<ey+7);

    let logoCells = 0;
    if (hasLogo) {
        logoCells = Math.round(size * 0.22);
        if (logoCells % 2 === 0) logoCells++;
    }
    const logoCx = size/2, logoCy = size/2;
    const logoR  = logoCells/2 + 0.5;

    let modules = '';
    for (let y = 0; y < size; y++) {
        for (let x = 0; x < size; x++) {
            if (!cells[y][x]) continue;
            if (isInEye(x, y)) continue;
            if (logoCells > 0) {
                const dx = x + 0.5 - logoCx;
                const dy = y + 0.5 - logoCy;
                if (dx*dx + dy*dy <= logoR*logoR) continue;
            }
            const px_x = x * SCALE, px_y = y * SCALE;
            const cx = px_x + SCALE/2, cy = px_y + SCALE/2;
            modules += moduleShapeSvg(d.module_shape, px_x, px_y, SCALE, cx, cy);
        }
    }

    let eyeSvg = '';
    for (const [ex, ey] of eyes) {
        eyeSvg += eyeOuterPath(d.eye_shape, ex*SCALE, ey*SCALE, SCALE, d.eye_color)
               +  eyeInnerShape(d.eye_inner, ex*SCALE, ey*SCALE, SCALE, d.eye_color);
    }

    let logoSvg = '';
    if (hasLogo && logoData) {
        const lw = Math.round(logoCells * SCALE * 0.85);
        const cxAbs = logoCx * SCALE;
        const cyAbs = logoCy * SCALE;
        const lx = Math.round(cxAbs - lw/2);
        const ly = Math.round(cyAbs - lw/2);
        const pad = Math.round(SCALE * 0.4);
        logoSvg = '<rect x="'+(lx-pad)+'" y="'+(ly-pad)+'" width="'+(lw+2*pad)+'" height="'+(lw+2*pad)+'" rx="'+(SCALE*0.6)+'" fill="'+d.bg+'"/>'
                + '<image x="'+lx+'" y="'+ly+'" width="'+lw+'" height="'+lw+'" href="'+logoData+'" preserveAspectRatio="xMidYMid meet"/>';
    }

    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '+px+' '+px+'" shape-rendering="geometricPrecision">'
        + '<defs>'+defs+'</defs>'
        + '<rect width="100%" height="100%" fill="'+d.bg+'"/>'
        + '<g transform="translate('+offset+','+offset+')">'
        +   '<g fill="'+fillData+'">'+modules+'</g>'
        +   eyeSvg
        +   logoSvg
        + '</g>'
        + '</svg>';
}

let previewTimer = null;
function refreshPreview() {
    if (!DESIGNER_STATE.uuid) return;
    syncGradientEnable();
    const d = readDesigner();

    // Update download links immediately
    const qs = buildDesignQS(d);
    const base = 'generate_image.php?id=' + encodeURIComponent(DESIGNER_STATE.uuid) + '&' + qs + '&_t=' + Date.now();
    document.getElementById('dlPng').href = base + '&format=png';
    document.getElementById('dlJpg').href = base + '&format=jpg';
    document.getElementById('dlSvg').href = base + '&format=svg';
    const fname = DESIGNER_STATE.title || 'QR';
    document.getElementById('dlPng').setAttribute('download', fname + '-QR.png');
    document.getElementById('dlJpg').setAttribute('download', fname + '-QR.jpg');
    document.getElementById('dlSvg').setAttribute('download', fname + '-QR.svg');

    // Render SVG client-side (instant)
    const m = MATRIX_CACHE[DESIGNER_STATE.uuid];
    if (!m) return; // matrix still loading
    const canvas = document.getElementById('qrCanvas');
    const spinner = canvas.querySelector('.qr-spinner');
    canvas.innerHTML = renderClientSvg(m, d);
    if (spinner) canvas.appendChild(spinner);
}

['dFg','dBg','dFg2','dEyeColor','dModule','dEye','dEyeInner','dGradient','dGradDir'].forEach(id => {
    const el = document.getElementById(id);
    if (el) {
        el.addEventListener('input',  refreshPreview);
        el.addEventListener('change', refreshPreview);
    }
});

document.getElementById('dSave').addEventListener('click', function() {
    if (!DESIGNER_STATE.id) return;
    const d = readDesigner();
    const fd = new FormData();
    fd.append('action', 'design');
    fd.append('id', DESIGNER_STATE.id);
    fd.append('csrf_token', CSRF_TOKEN);
    Object.entries(d).forEach(([k,v]) => fd.append('design[' + k + ']', typeof v === 'boolean' ? (v ? '1' : '0') : v));
    const msg = document.getElementById('dSaveMsg');
    msg.textContent = 'Saving...';
    fetch('index.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(() => { msg.textContent = 'Saved ✓'; setTimeout(() => msg.textContent = '', 1800); })
        .catch(() => { msg.textContent = 'Error'; });
});

document.getElementById('dReset').addEventListener('click', function() {
    applyDesignerInputs(DESIGN_DEFAULT);
    refreshPreview();
});

function openDesigner(uuid, title, id, design) {
    DESIGNER_STATE = { uuid, id, title };
    document.getElementById('qrTitle').innerText = title + ' — Designer';
    buildPresetChips();
    applyDesignerInputs(design);
    openModal('qrModal');
    delete MATRIX_CACHE[uuid];
    loadMatrix(uuid)
        .then(matrix => {
            if (matrix && matrix.design) applyDesignerInputs(matrix.design);
            refreshPreview();
        })
        .catch(err => {
            console.error(err);
            document.getElementById('qrCanvas').innerHTML = '<div style="color:#dc3545; padding:20px; font-size:0.85em;">Failed to load QR matrix</div>';
        });
}
const showQR = openDesigner; // backwards-compat

// ── Preview modal (image-only, hits cached PNG) ──────────────────────────────
let PREVIEW_STATE = { uuid: null, title: '' };
function openPreview(p) {
    PREVIEW_STATE = { uuid: p.uuid, title: p.title };
    const base = 'generate_image.php?id=' + encodeURIComponent(p.uuid);
    const img = document.getElementById('previewImg');
    img.src = base + '&format=png&v=' + Date.now();
    img.alt = p.title;
    document.getElementById('previewTitle').innerText = p.title;
    document.getElementById('previewMeta').innerHTML =
        '<strong>' + (p.type || '') + '</strong>' + (p.url ? ' &middot; ' + p.url.replace(/</g,'&lt;') : '');
    const dlPng = document.getElementById('previewDlPng');
    const dlJpg = document.getElementById('previewDlJpg');
    const dlSvg = document.getElementById('previewDlSvg');
    dlPng.href = base + '&format=png'; dlPng.download = (p.title || 'QR') + '-QR.png';
    dlJpg.href = base + '&format=jpg'; dlJpg.download = (p.title || 'QR') + '-QR.jpg';
    dlSvg.href = base + '&format=svg'; dlSvg.download = (p.title || 'QR') + '-QR.svg';
    openModal('previewModal');
}
function printPreview() {
    const win = window.open('');
    win.document.write('<html><body style="text-align:center;"><h2 style="font-family:sans-serif">' +
        document.getElementById('previewTitle').innerText +
        '</h2><img src="' + document.getElementById('previewImg').src +
        '" onload="window.print();window.close()" /></body></html>');
    win.document.close();
}

// ── Row kebab menu ───────────────────────────────────────────────────────────
function toggleRowMenu(btn, ev) {
    ev.stopPropagation();
    const menu = btn.parentElement.querySelector('.row-menu');
    document.querySelectorAll('.row-menu.open').forEach(m => { if (m !== menu) m.classList.remove('open'); });
    menu.classList.toggle('open');
}
document.addEventListener('click', function(e) {
    if (!e.target.closest('.row-menu') && !e.target.closest('.kebab')) {
        document.querySelectorAll('.row-menu.open').forEach(m => m.classList.remove('open'));
    }
});

// Toggle active from row menu (also flip menu label)
function toggleQRRow(id, btnEl) {
    const row = btnEl.closest('.qr-row');
    const fd = new FormData();
    fd.append('action', 'toggle');
    fd.append('id', id);
    fd.append('csrf_token', CSRF_TOKEN);
    fetch('index.php', { method: 'POST', body: fd }).then(() => {
        const wasActive = row.dataset.active === '1';
        row.dataset.active = wasActive ? '0' : '1';
        btnEl.lastChild.textContent = ' ' + (wasActive ? 'Enable' : 'Disable');
        applyFilters();
    });
}

// ── Filters ──────────────────────────────────────────────────────────────────
function applyFilters() {
    const q = (document.getElementById('filterSearch')?.value || '').trim().toLowerCase();
    const status = document.getElementById('filterStatus')?.value || '';
    const type = document.getElementById('filterType')?.value || '';
    const sort = document.getElementById('filterSort')?.value || 'recent';
    const qty = parseInt(document.getElementById('filterQty')?.value || '0', 10);

    const list = document.getElementById('qrList');
    const rows = Array.from(list.querySelectorAll('.qr-row'));
    rows.forEach(r => {
        let show = true;
        if (q && !(r.dataset.search || '').includes(q)) show = false;
        if (type && r.dataset.type !== type) show = false;
        if (status === 'active'   && r.dataset.active !== '1') show = false;
        if (status === 'disabled' && r.dataset.active !== '0') show = false;
        r.dataset.matched = show ? '1' : '0';
    });

    const visible = rows.filter(r => r.dataset.matched === '1');
    visible.sort((a,b) => {
        if (sort === 'scans') return (+b.dataset.scans) - (+a.dataset.scans);
        if (sort === 'title') return a.dataset.title.localeCompare(b.dataset.title);
        return new Date(b.dataset.created) - new Date(a.dataset.created);
    });

    rows.forEach(r => r.style.display = 'none');
    const limit = qty > 0 ? Math.min(qty, visible.length) : visible.length;
    visible.slice(0, limit).forEach((r, i) => {
        r.style.display = '';
        r.style.order = i;
    });
    list.style.display = 'flex';
    list.style.flexDirection = 'column';
}
['filterSearch','filterStatus','filterType','filterSort','filterQty'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.addEventListener('input', applyFilters);
});

// ── Select-All + bulk download (one tab per selected) ────────────────────────
const selAll = document.getElementById('selectAll');
if (selAll) {
    selAll.addEventListener('change', () => {
        document.querySelectorAll('.row-check').forEach(c => {
            const row = c.closest('.qr-row');
            if (row.style.display === 'none') return;
            c.checked = selAll.checked;
            row.classList.toggle('selected', selAll.checked);
        });
    });
}
document.querySelectorAll('.row-check').forEach(c => {
    c.addEventListener('change', e => {
        c.closest('.qr-row').classList.toggle('selected', c.checked);
    });
});
const bulkDl = document.getElementById('bulkDownload');
if (bulkDl) {
    bulkDl.addEventListener('click', () => {
        const checked = document.querySelectorAll('.row-check:checked');
        if (checked.length === 0) { alert('Select QR codes to download.'); return; }
        checked.forEach(c => {
            const row = c.closest('.qr-row');
            const a = document.createElement('a');
            a.href = 'generate_image.php?id=' + encodeURIComponent(row.dataset.uuid) + '&format=png';
            a.download = (row.dataset.title || 'QR') + '-QR.png';
            document.body.appendChild(a); a.click(); a.remove();
        });
    });
}


// ── Delete ───────────────────────────────────────────────────────────────────
let deleteId = null;
function confirmDelete(id) { deleteId = id; openModal('deleteModal'); }
document.getElementById('confirmDeleteBtn').addEventListener('click', function() {
    const fd = new FormData();
    fd.append('action', 'delete');
    fd.append('id', deleteId);
    fd.append('csrf_token', CSRF_TOKEN);
    fetch('index.php', { method: 'POST', body: fd }).then(() => location.reload());
});

// ── Folders ──────────────────────────────────────────────────────────────────
let folderAddParent = null;
function openFolderAdd(parentId) {
    folderAddParent = parentId;
    document.getElementById('folderAddName').value = '';
    document.getElementById('folderAddMsg').textContent = '';
    document.getElementById('folderAddParentLabel').textContent =
        parentId === null ? 'Will be created at Home.' : 'Will be created under the current folder.';
    openModal('folderAddModal');
    setTimeout(() => document.getElementById('folderAddName').focus(), 50);
}
function submitFolderAdd() {
    const name = document.getElementById('folderAddName').value.trim();
    if (!name) { document.getElementById('folderAddMsg').textContent = 'Name required.'; return; }
    const fd = new FormData();
    fd.append('action', 'folder_add');
    fd.append('csrf_token', CSRF_TOKEN);
    fd.append('name', name);
    if (folderAddParent !== null) fd.append('parent_id', folderAddParent);
    fetch('index.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(j => {
            if (j.status === 'ok') {
                const target = j.parent_id !== null ? '?folder=' + j.parent_id : '';
                window.location = (location.search.includes('folder=') ? location.pathname + location.search : location.pathname + target);
                location.reload();
            }
        });
}

let folderRenameId = null;
function openFolderRename(id, name) {
    folderRenameId = id;
    document.getElementById('folderRenameId').value = id;
    document.getElementById('folderRenameName').value = name;
    openModal('folderRenameModal');
    setTimeout(() => document.getElementById('folderRenameName').select(), 50);
}
function submitFolderRename() {
    const name = document.getElementById('folderRenameName').value.trim();
    if (!name) return;
    const fd = new FormData();
    fd.append('action', 'folder_rename');
    fd.append('csrf_token', CSRF_TOKEN);
    fd.append('id', folderRenameId);
    fd.append('name', name);
    fetch('index.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(() => location.reload());
}

let folderDeleteId = null;
function confirmFolderDelete(id) { folderDeleteId = id; openModal('folderDeleteModal'); }
function submitFolderDelete() {
    const fd = new FormData();
    fd.append('action', 'folder_delete');
    fd.append('csrf_token', CSRF_TOKEN);
    fd.append('id', folderDeleteId);
    fetch('index.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(j => {
            const dest = j.parent_id !== null && j.parent_id !== undefined ? '?folder=' + j.parent_id : '';
            window.location = location.pathname + dest;
        });
}
</script>

<?php include THEME_PATH . '/footer.php'; ?>
