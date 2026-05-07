<?php
require 'config.php';
require_auth();

define('PAGE_TITLE', 'My Account | Umoor Iqtesadiyah QR Track');
define('ACTIVE_NAV', 'account');

$me = current_user();
$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'logo_upload') {
        if (!isset($_FILES['logo']) || $_FILES['logo']['error'] !== UPLOAD_ERR_OK) {
            $err = 'Upload failed. Pick a PNG or JPG file.';
        } else {
            $allowedMimes = ['image/png' => 'png', 'image/jpeg' => 'jpg'];
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime  = $finfo->file($_FILES['logo']['tmp_name']);
            if (!isset($allowedMimes[$mime])) {
                $err = 'Only PNG or JPG allowed.';
            } elseif ($_FILES['logo']['size'] > 2 * 1024 * 1024) {
                $err = 'Max size 2 MB.';
            } else {
                if (!is_dir(LOGO_DIR)) mkdir(LOGO_DIR, 0755, true);
                // Remove any prior user logo (different ext possible)
                foreach (['png','jpg'] as $oldExt) {
                    $stale = LOGO_DIR . '/user_' . (int)$me['id'] . '.' . $oldExt;
                    if (is_file($stale)) @unlink($stale);
                }
                $ext  = $allowedMimes[$mime];
                $name = 'user_' . (int)$me['id'] . '.' . $ext;
                $dest = LOGO_DIR . '/' . $name;
                if (move_uploaded_file($_FILES['logo']['tmp_name'], $dest)) {
                    $db->prepare("UPDATE users SET logo_path = ? WHERE id = ?")
                       ->execute([$name, (int)$me['id']]);
                    $_SESSION['logo_path'] = $name;
                    $msg = 'Logo updated.';
                    $me = current_user();
                } else {
                    $err = 'Could not save file.';
                }
            }
        }
    } elseif ($action === 'logo_remove') {
        if (!empty($me['logo_path'])) {
            $f = LOGO_DIR . '/' . $me['logo_path'];
            if (is_file($f)) @unlink($f);
            $db->prepare("UPDATE users SET logo_path = NULL WHERE id = ?")
               ->execute([(int)$me['id']]);
            $_SESSION['logo_path'] = null;
            $msg = 'Logo removed.';
            $me = current_user();
        }
    }
}

$csrfToken = csrf_token();
$logoSrc = '';
if (!empty($me['logo_path'])) {
    $f = LOGO_DIR . '/' . $me['logo_path'];
    if (is_file($f)) {
        $b = @file_get_contents($f);
        if ($b !== false) {
            $info = getimagesizefromstring($b);
            $mime = $info['mime'] ?? 'image/png';
            $logoSrc = 'data:' . $mime . ';base64,' . base64_encode($b);
        }
    }
}

include THEME_PATH . '/header.php';
?>

<style>
.account-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; max-width: 900px; }
@media (max-width: 800px) { .account-grid { grid-template-columns: 1fr; } }
.logo-preview {
    width: 140px; height: 140px;
    border-radius: 16px;
    border: 1px dashed var(--border-strong);
    background: #f5f7fa;
    display: flex; align-items: center; justify-content: center;
    margin-bottom: 14px;
    overflow: hidden;
}
.logo-preview img { max-width: 100%; max-height: 100%; object-fit: contain; }
.logo-preview .empty { color: var(--muted); font-size: 0.85rem; }
.flash { padding: 10px 14px; border-radius: 8px; margin-bottom: 14px; font-size: 0.9rem; }
.flash.ok  { background: var(--accent-soft); color: var(--accent-strong); }
.flash.err { background: var(--danger-soft); color: var(--danger); }
.account-card { background: #fff; border: 1px solid var(--border); border-radius: 12px; padding: 22px; }
.account-card h3 { margin: 0 0 14px; font-size: 1.05rem; }
.kv { display: grid; grid-template-columns: 110px 1fr; gap: 8px 14px; font-size: 0.92rem; }
.kv .k { color: var(--muted); }
.kv .v { font-weight: 600; }
</style>

<div class="topbar">
    <h1>My Account</h1>
</div>

<?php if ($msg): ?><div class="flash ok"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="flash err"><?= htmlspecialchars($err) ?></div><?php endif; ?>

<div class="account-grid">
    <div class="account-card">
        <h3>Profile</h3>
        <div class="kv">
            <div class="k">Username</div>
            <div class="v"><?= htmlspecialchars($me['username']) ?></div>
            <div class="k">Role</div>
            <div class="v"><?= htmlspecialchars(strtoupper($me['role'])) ?></div>
        </div>
    </div>

    <div class="account-card">
        <h3>Brand Logo</h3>
        <p style="color:var(--muted); font-size:0.85rem; margin:0 0 12px;">
            Replaces the default mark in your sidebar. PNG or JPG, up to 2&nbsp;MB.
        </p>
        <div class="logo-preview">
            <?php if ($logoSrc): ?>
                <img src="<?= $logoSrc ?>" alt="Your logo">
            <?php else: ?>
                <span class="empty">No logo</span>
            <?php endif; ?>
        </div>
        <form method="POST" enctype="multipart/form-data" style="margin:0;">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="action" value="logo_upload">
            <input type="file" name="logo" accept="image/png, image/jpeg" required>
            <button type="submit" class="btn btn-sm" style="margin-top:6px;">Upload</button>
        </form>
        <?php if ($logoSrc): ?>
        <form method="POST" style="margin:10px 0 0;" onsubmit="return confirm('Remove your logo?');">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="action" value="logo_remove">
            <button type="submit" class="btn btn-ghost btn-sm">Remove logo</button>
        </form>
        <?php endif; ?>
    </div>
</div>

<?php include THEME_PATH . '/footer.php'; ?>
