<?php
require 'config.php';

// Harden session cookie before starting
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

// Already logged in? Go to dashboard
if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    header("Location: " . BASE_URL);
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = trim($_POST['username'] ?? '');
    $pass = $_POST['password'] ?? '';

    $authedRow = null;

    $stmt = $db->prepare("SELECT id, username, password_hash, role FROM users WHERE username = ? LIMIT 1");
    $stmt->execute([$user]);
    $row = $stmt->fetch();

    if ($row && password_verify($pass, $row['password_hash'])) {
        $authedRow = $row;
    } elseif ($user === ADMIN_USER && $pass === ADMIN_PASS) {
        // Bootstrap fallback: ensure admin row exists with current config password
        $hash = password_hash(ADMIN_PASS, PASSWORD_DEFAULT);
        if ($row) {
            $db->prepare("UPDATE users SET password_hash = ?, role = 'admin' WHERE id = ?")
               ->execute([$hash, $row['id']]);
            $authedRow = ['id' => $row['id'], 'username' => $row['username'], 'role' => 'admin'];
        } else {
            $db->prepare("INSERT INTO users (username, password_hash, role) VALUES (?, ?, 'admin')")
               ->execute([ADMIN_USER, $hash]);
            $authedRow = ['id' => (int)$db->lastInsertId(), 'username' => ADMIN_USER, 'role' => 'admin'];
        }
    }

    if ($authedRow) {
        session_regenerate_id(true);
        $_SESSION['logged_in']   = true;
        $_SESSION['user_id']     = (int)$authedRow['id'];
        $_SESSION['username']    = $authedRow['username'];
        $_SESSION['role']        = $authedRow['role'];
        $_SESSION['last_active'] = time();

        purge_old_tokens($db);

        header("Location: " . BASE_URL);
        exit;
    } else {
        $error = 'Invalid credentials.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Login | Umoor Iqtesadiyah QR Track</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Recursive:wght@300..900&display=swap" rel="stylesheet">
    <style>
        :root { --bg: #f5f7fa; --card: #ffffff; --text: #1a1a1a; --accent: #1e90ff; --border: #dde2e8; }
        body { font-family: 'Recursive', sans-serif; background: var(--bg); color: var(--text); height: 100vh; display: flex; align-items: center; justify-content: center; margin: 0; }
        .login-card { background: var(--card); padding: 40px; border-radius: 10px; width: 100%; max-width: 350px; border: 1px solid var(--border); box-shadow: 0 6px 24px rgba(0,0,0,0.08); }
        h2 { margin: 0 0 20px 0; text-align: center; color: var(--accent); }
        input { width: 100%; padding: 12px; margin: 10px 0; background: #ffffff; border: 1px solid var(--border); color: var(--text); border-radius: 4px; box-sizing: border-box; }
        input:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px rgba(30,144,255,0.15); }
        button { width: 100%; padding: 12px; background: var(--accent); border: none; color: white; border-radius: 4px; font-weight: bold; cursor: pointer; margin-top: 10px; }
        button:hover { opacity: 0.9; }
        .error { color: #dc3545; text-align: center; margin-bottom: 15px; font-size: 0.9em; }
        .logo { display: block; margin: 0 auto 20px auto; width: 60px; }
    </style>
</head>
<body>
    <div class="login-card">
        <img src="<?= htmlspecialchars(BASE_URL) ?>/logo-v2.png" alt="Logo" class="logo">
        <h2>Sign In</h2>
        <?php if ($error): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <form method="POST">
            <input type="text" name="username" placeholder="Username" required autofocus autocomplete="username">
            <input type="password" name="password" placeholder="Password" required autocomplete="current-password">
            <button type="submit">Sign In</button>
        </form>
    </div>
</body>
</html>
