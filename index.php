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

        $stmt = $db->prepare("INSERT INTO products (uuid, title, type, target_data, logo_path, user_id, design_json) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$uuid, $title, $type, $target, $logoPath, $me['id'], $designJson]);

        header("Location: " . BASE_URL);
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

        $stmt = $db->prepare("UPDATE products SET title = ?, target_data = ?, design_json = ? WHERE id = ? AND is_deleted = 0");
        $stmt->execute([$title, $target, $designJson, $id]);

        header("Location: " . BASE_URL);
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

    // Action: Update Design (live designer save)
    if ($_POST['action'] === 'design') {
        $id = (int)($_POST['id'] ?? 0);
        ensure_owner_or_admin($db, $id, $me);
        $design = sanitize_design($_POST['design'] ?? []);
        $db->prepare("UPDATE products SET design_json = ? WHERE id = ?")
           ->execute([json_encode($design), $id]);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'ok', 'design' => $design]);
        exit;
    }
}

// --- FETCH DATA ---
$showTrash = isset($_GET['trash']);
$deletedFlag = $showTrash ? 1 : 0;

if ($isAdmin) {
    $stmt = $db->prepare("
        SELECT p.*, u.username AS owner_username,
            (SELECT COUNT(*) FROM scans WHERE product_uuid = p.uuid) as scan_count
        FROM products p
        LEFT JOIN users u ON u.id = p.user_id
        WHERE p.is_deleted = ?
        ORDER BY p.created_at DESC
    ");
    $stmt->execute([$deletedFlag]);
} else {
    $stmt = $db->prepare("
        SELECT p.*, NULL AS owner_username,
            (SELECT COUNT(*) FROM scans WHERE product_uuid = p.uuid) as scan_count
        FROM products p
        WHERE p.is_deleted = ? AND p.user_id = ?
        ORDER BY p.created_at DESC
    ");
    $stmt->execute([$deletedFlag, $me['id']]);
}
$products = $stmt->fetchAll();

$csrfToken = csrf_token();

// --- RENDER VIEW ---
define('SHOW_ADD_BTN', true);
include THEME_PATH . '/header.php';
?>

<?php if ($showTrash): ?>
<div style="margin-bottom:15px; display:flex; align-items:center; gap:15px;">
    <a href="<?= htmlspecialchars(BASE_URL) ?>" class="btn btn-sm" style="background:#6c757d;">&larr; Back to Dashboard</a>
    <h2 style="margin:0; color:#dc3545;">Trash</h2>
</div>
<?php else: ?>
<div style="margin-bottom:15px; display:flex; justify-content:flex-end;">
    <a href="?trash" class="btn btn-sm" style="background:#6c757d;" title="View deleted QR codes">&#128465; Trash</a>
</div>
<?php endif; ?>

<div class="qr-list">
    <?php if (empty($products)): ?>
        <p style="text-align:center; color:#666; padding:40px 0;">
            <?= $showTrash ? 'Trash is empty.' : 'No QR codes yet. Click "+ New QR Code" to create one.' ?>
        </p>
    <?php endif; ?>

    <?php foreach($products as $p): ?>
    <div class="qr-item">
        <div class="qr-info">
            <h3><?= htmlspecialchars($p['title']) ?> <span style="font-size:0.7em; opacity:0.6">[<?= strtoupper(htmlspecialchars($p['type'])) ?>]</span><?php if ($isAdmin && !empty($p['owner_username'])): ?> <span style="font-size:0.7em; opacity:0.7; padding:2px 6px; border-radius:8px; background:#eef3ff; color:#1e90ff;"><?= htmlspecialchars($p['owner_username']) ?></span><?php endif; ?></h3>
            <div class="qr-meta">Created: <?= date('M d, Y', strtotime($p['created_at'])) ?></div>
        </div>

        <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap; justify-content: flex-end;">

            <?php if ($showTrash): ?>
                <form method="POST" style="margin:0;">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="action" value="restore">
                    <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                    <button type="submit" class="btn btn-sm" style="background:#28a745;">Restore</button>
                </form>
            <?php else: ?>
                <?php if ($p['type'] === 'wifi'): ?>
                    <div class="qr-stats" style="color: #666; text-decoration: none; cursor: default;">Not Trackable</div>
                <?php else: ?>
                    <div onclick="loadStats('<?= htmlspecialchars($p['uuid']) ?>')" class="qr-stats"><?= (int)$p['scan_count'] ?> Scans</div>
                <?php endif; ?>

                <label class="switch">
                    <input type="checkbox" onchange="toggleQR(<?= (int)$p['id'] ?>, '<?= htmlspecialchars($csrfToken) ?>')" <?= $p['is_active'] ? 'checked' : '' ?>>
                    <span class="slider"></span>
                </label>

                <button class="btn btn-sm" onclick='showQR(<?= htmlspecialchars(json_encode($p['uuid']), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($p['title']), ENT_QUOTES) ?>, <?= (int)$p['id'] ?>, <?= htmlspecialchars(json_encode(json_decode($p['design_json'] ?? '') ?: new stdClass()), ENT_QUOTES) ?>)'>Design / Get Code</button>

                <button class="btn btn-sm btn-info" onclick='openEditModal(<?= htmlspecialchars(json_encode([
                    'id'          => $p['id'],
                    'title'       => $p['title'],
                    'type'        => $p['type'],
                    'target_data' => $p['target_data'],
                ]), ENT_QUOTES) ?>)' title="Edit">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" viewBox="0 0 16 16">
                        <path d="M12.146.146a.5.5 0 0 1 .708 0l3 3a.5.5 0 0 1 0 .708l-10 10a.5.5 0 0 1-.168.11l-5 2a.5.5 0 0 1-.65-.65l2-5a.5.5 0 0 1 .11-.168l10-10zM11.207 2.5 13.5 4.793 14.793 3.5 12.5 1.207 11.207 2.5zm1.586 3L10.5 3.207 4 9.707V10h.5a.5.5 0 0 1 .5.5v.5h.5a.5.5 0 0 1 .5.5v.5h.293l6.5-6.5zm-9.761 5.175-.106.106-1.528 3.821 3.821-1.528.106-.106A.5.5 0 0 1 5 12.5V12h-.5a.5.5 0 0 1-.5-.5V11h-.5a.5.5 0 0 1-.468-.325z"/>
                    </svg>
                </button>

                <button class="btn btn-sm btn-danger" onclick="confirmDelete(<?= (int)$p['id'] ?>)" title="Delete" style="display:flex; align-items:center; padding: 8px;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" viewBox="0 0 16 16">
                        <path d="M5.5 5.5A.5.5 0 0 1 6 6v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5zm2.5 0a.5.5 0 0 1 .5.5v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5zm3 .5a.5.5 0 0 0-1 0v6a.5.5 0 0 0 1 0V6z"/>
                        <path fill-rule="evenodd" d="M14.5 3a1 1 0 0 1-1 1H13v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V4h-.5a1 1 0 0 1-1-1V2a1 1 0 0 1 1-1H6a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1h3.5a1 1 0 0 1 1 1v1zM4.118 4 4 4.059V13a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1V4.059L11.882 4H4.118zM2.5 3V2h11v1h-11z"/>
                    </svg>
                </button>
            <?php endif; ?>

        </div>
    </div>
    <?php endforeach; ?>
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

<!-- ── Stats Modal ────────────────────────────────────────────────────────────── -->
<div id="statsModal" class="modal">
    <div class="modal-content">
        <svg class="close-icon" onclick="closeModal('statsModal')" viewBox="0 0 24 24"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>
        <h2>Scan History</h2>
        <div id="statsContent" style="max-height: 400px; overflow-y: auto;">Loading...</div>
    </div>
</div>

<!-- ── QR Designer Modal ──────────────────────────────────────────────────────── -->
<div id="qrModal" class="modal">
    <div class="modal-content" style="max-width:880px;">
        <svg class="close-icon" onclick="closeModal('qrModal')" viewBox="0 0 24 24"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>
        <h2 id="qrTitle" style="margin-bottom:18px;">QR Designer</h2>

        <div class="designer-grid">
            <div class="preview-card">
                <img id="qrImage" src="" alt="QR Code">
                <div class="dl-row">
                    <a id="dlPng" href="#" download class="btn btn-sm">PNG</a>
                    <a id="dlJpg" href="#" download class="btn btn-sm">JPG</a>
                    <a id="dlSvg" href="#" download class="btn btn-sm">SVG</a>
                    <button onclick="printQR()" class="btn btn-sm" style="background:#6c757d;">Print</button>
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
                    <button id="dReset" class="btn btn-sm" style="background:#6c757d;">Reset</button>
                    <span id="dSaveMsg" style="color:var(--muted); font-size:0.85em;"></span>
                </div>
            </div>
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
            <button onclick="closeModal('deleteModal')" class="btn" style="background: #6c757d;">Cancel</button>
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

// ── Print ────────────────────────────────────────────────────────────────────
function printQR() {
    const win = window.open('');
    win.document.write('<html><body style="text-align:center;"><h2 style="font-family:sans-serif">' +
        document.getElementById('qrTitle').innerText +
        '</h2><img src="' + document.getElementById('qrImage').src +
        '" onload="window.print();window.close()" /></body></html>');
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

let previewTimer = null;
function refreshPreview() {
    if (!DESIGNER_STATE.uuid) return;
    syncGradientEnable();
    clearTimeout(previewTimer);
    previewTimer = setTimeout(() => {
        const d = readDesigner();
        const qs = buildDesignQS(d);
        const base = 'generate_image.php?id=' + encodeURIComponent(DESIGNER_STATE.uuid) + '&' + qs + '&_t=' + Date.now();
        document.getElementById('qrImage').src    = base + '&format=svg';
        document.getElementById('dlPng').href     = base + '&format=png';
        document.getElementById('dlJpg').href     = base + '&format=jpg';
        document.getElementById('dlSvg').href     = base + '&format=svg';
        const fname = DESIGNER_STATE.title || 'QR';
        document.getElementById('dlPng').setAttribute('download', fname + '-QR.png');
        document.getElementById('dlJpg').setAttribute('download', fname + '-QR.jpg');
        document.getElementById('dlSvg').setAttribute('download', fname + '-QR.svg');
    }, 120);
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

function showQR(uuid, title, id, design) {
    DESIGNER_STATE = { uuid, id, title };
    document.getElementById('qrTitle').innerText = title + ' — Designer';
    buildPresetChips();
    applyDesignerInputs(design);
    refreshPreview();
    openModal('qrModal');
}

// ── Scan stats ───────────────────────────────────────────────────────────────
function loadStats(uuid) {
    openModal('statsModal');
    document.getElementById('statsContent').innerHTML = '<p style="text-align:center; padding:20px;">Loading geolocation data...</p>';
    fetch('api_stats.php?uuid=' + encodeURIComponent(uuid))
        .then(res => res.json())
        .then(data => {
            let html = '';
            if (data.length === 0) {
                html = '<p>No scans yet.</p>';
            } else {
                data.forEach(row => {
                    const badge = row.scan_status === 'blocked'
                        ? '<div class="scan-badge">DISABLED SCAN</div>' : '';
                    html += `
                    <div class="scan-row">
                        <div style="padding-right:10px;">
                            <div class="scan-ip">${row.ip_address}</div>
                            <div style="color:#6c757d; font-size:0.85em;">${row.geo.isp || 'Unknown ISP'}</div>
                            ${badge}
                        </div>
                        <div>
                            <div style="color: var(--accent); font-weight:bold;">${row.geo.city}, ${row.geo.region}</div>
                            <div style="color:#6c757d; font-size:0.85em;">${row.geo.country}</div>
                        </div>
                        <div class="scan-meta">
                            <div>${row.scanned_at}</div>
                            <div style="font-size:0.75em; opacity:0.7; margin-top:4px; word-break:break-word;">${row.user_agent}</div>
                        </div>
                    </div>`;
                });
            }
            document.getElementById('statsContent').innerHTML = html;
        })
        .catch(() => {
            document.getElementById('statsContent').innerHTML = '<p style="color:red">Error loading stats.</p>';
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
</script>

<?php include THEME_PATH . '/footer.php'; ?>
