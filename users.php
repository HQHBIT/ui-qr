<?php
require 'config.php';
require_role('admin');

define('PAGE_TITLE', 'Users | Umoor Iqtesadiyah QR Track');

$me      = current_user();
$message = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verify_csrf();

    if ($_POST['action'] === 'add') {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $role     = ($_POST['role'] ?? 'user') === 'admin' ? 'admin' : 'user';

        if ($username === '' || strlen($password) < 6) {
            $error = 'Username required and password must be at least 6 characters.';
        } else {
            $exists = $db->prepare("SELECT id FROM users WHERE username = ?");
            $exists->execute([$username]);
            if ($exists->fetch()) {
                $error = 'Username already taken.';
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $db->prepare("INSERT INTO users (username, password_hash, role) VALUES (?, ?, ?)")
                   ->execute([$username, $hash, $role]);
                $message = 'User created.';
            }
        }
    } elseif ($_POST['action'] === 'reset') {
        $id       = (int)($_POST['id'] ?? 0);
        $password = $_POST['password'] ?? '';
        if ($id <= 0 || strlen($password) < 6) {
            $error = 'Password must be at least 6 characters.';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $db->prepare("UPDATE users SET password_hash = ? WHERE id = ?")->execute([$hash, $id]);
            $message = 'Password reset.';
        }
    } elseif ($_POST['action'] === 'role') {
        $id   = (int)($_POST['id'] ?? 0);
        $role = ($_POST['role'] ?? 'user') === 'admin' ? 'admin' : 'user';
        if ($id === $me['id'] && $role !== 'admin') {
            $error = 'Cannot demote yourself.';
        } else {
            $db->prepare("UPDATE users SET role = ? WHERE id = ?")->execute([$role, $id]);
            $message = 'Role updated.';
        }
    } elseif ($_POST['action'] === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id === $me['id']) {
            $error = 'Cannot delete yourself.';
        } else {
            $db->prepare("DELETE FROM users WHERE id = ?")->execute([$id]);
            $db->prepare("UPDATE products SET is_deleted = 1, is_active = 0 WHERE user_id = ?")->execute([$id]);
            $message = 'User deleted; their QR codes moved to trash.';
        }
    }
}

$users = $db->query("
    SELECT u.id, u.username, u.role, u.created_at,
        (SELECT COUNT(*) FROM products WHERE user_id = u.id AND is_deleted = 0) AS qr_count
    FROM users u
    ORDER BY u.role DESC, u.username ASC
")->fetchAll();

$csrfToken = csrf_token();
include THEME_PATH . '/header.php';
?>
<div style="margin-bottom:15px; display:flex; align-items:center; gap:15px; justify-content:space-between;">
    <div style="display:flex; align-items:center; gap:15px;">
        <a href="<?= htmlspecialchars(BASE_URL) ?>" class="btn btn-sm" style="background:#6c757d;">&larr; Back</a>
        <h2 style="margin:0;">User Management</h2>
    </div>
    <button class="btn btn-sm" onclick="openModal('addUserModal')">+ New User</button>
</div>

<?php if ($message): ?><div style="background:#d4edda; color:#155724; padding:10px 15px; border-radius:6px; margin-bottom:15px;"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($error): ?><div style="background:#f8d7da; color:#721c24; padding:10px 15px; border-radius:6px; margin-bottom:15px;"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="qr-list">
    <?php foreach ($users as $u): ?>
    <div class="qr-item">
        <div class="qr-info">
            <h3><?= htmlspecialchars($u['username']) ?>
                <span style="font-size:0.7em; opacity:0.7; padding:2px 8px; border-radius:10px; background:<?= $u['role']==='admin' ? '#1e90ff' : '#6c757d' ?>; color:#fff;"><?= strtoupper($u['role']) ?></span>
            </h3>
            <div class="qr-meta"><?= (int)$u['qr_count'] ?> QR codes &middot; Joined <?= date('M d, Y', strtotime($u['created_at'])) ?></div>
        </div>
        <div style="display:flex; gap:8px; flex-wrap:wrap; justify-content:flex-end;">
            <button class="btn btn-sm btn-info" onclick='openResetModal(<?= htmlspecialchars(json_encode(['id'=>$u['id'],'username'=>$u['username']]), ENT_QUOTES) ?>)'>Reset Password</button>
            <?php if ($u['id'] !== $me['id']): ?>
                <form method="POST" style="margin:0;">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="action" value="role">
                    <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                    <input type="hidden" name="role" value="<?= $u['role']==='admin' ? 'user' : 'admin' ?>">
                    <button type="submit" class="btn btn-sm" style="background:#6c757d;"><?= $u['role']==='admin' ? 'Demote' : 'Promote' ?></button>
                </form>
                <form method="POST" style="margin:0;" onsubmit="return confirm('Delete user and trash all their QR codes?');">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                </form>
            <?php else: ?>
                <span style="color:var(--muted); font-size:0.8em; align-self:center;">(you)</span>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<div id="addUserModal" class="modal">
    <div class="modal-content">
        <svg class="close-icon" onclick="closeModal('addUserModal')" viewBox="0 0 24 24"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>
        <h2>Add User</h2>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="action" value="add">
            <label>Username</label>
            <input type="text" name="username" required maxlength="60" autocomplete="off">
            <label>Password (min 6 chars)</label>
            <input type="password" name="password" required minlength="6" autocomplete="new-password">
            <label>Role</label>
            <select name="role">
                <option value="user">User</option>
                <option value="admin">Admin</option>
            </select>
            <button type="submit" class="btn" style="width:100%; margin-top:20px;">Create User</button>
        </form>
    </div>
</div>

<div id="resetModal" class="modal">
    <div class="modal-content">
        <svg class="close-icon" onclick="closeModal('resetModal')" viewBox="0 0 24 24"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>
        <h2>Reset Password <span id="resetUserLabel" style="font-size:0.65em; opacity:0.6;"></span></h2>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="action" value="reset">
            <input type="hidden" name="id" id="resetId">
            <label>New Password (min 6 chars)</label>
            <input type="password" name="password" required minlength="6" autocomplete="new-password">
            <button type="submit" class="btn" style="width:100%; margin-top:20px;">Set Password</button>
        </form>
    </div>
</div>

<script>
function openModal(id)  { document.getElementById(id).style.display = 'flex'; }
function closeModal(id) { document.getElementById(id).style.display = 'none'; }
window.addEventListener('click', e => { if (e.target.classList.contains('modal')) e.target.style.display = 'none'; });
function openResetModal(data) {
    document.getElementById('resetId').value = data.id;
    document.getElementById('resetUserLabel').textContent = '— ' + data.username;
    openModal('resetModal');
}
</script>

<?php include THEME_PATH . '/footer.php'; ?>
