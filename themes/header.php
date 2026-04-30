<?php
$hdrUser = function_exists('current_user') ? current_user() : null;
$hdrScript = basename($_SERVER['SCRIPT_NAME'] ?? '');
$activeNav = defined('ACTIVE_NAV') ? ACTIVE_NAV : (
    $hdrScript === 'users.php' ? 'users' :
    ($hdrScript === 'stats.php' ? 'stats' :
    ($hdrScript === 'api_instructions.php' ? 'api' : 'qrcodes'))
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= defined('PAGE_TITLE') ? htmlspecialchars(PAGE_TITLE) : 'Umoor Iqtesadiyah QR Track' ?></title>
    <link rel="icon" type="image/png" href="<?= htmlspecialchars(BASE_URL) ?>/logo-v2.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Recursive:wght@300..900&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #f5f7fa;
            --card: #ffffff;
            --text: #1a1a1a;
            --muted: #6c757d;
            --muted-2: #8a93a0;
            --border: #e5e9ef;
            --border-strong: #d3dae4;
            --accent: #22c55e;
            --accent-soft: #e7f7ed;
            --accent-strong: #16a34a;
            --info: #1e90ff;
            --warn: #f59e0b;
            --warn-soft: #fff4e0;
            --danger: #dc3545;
            --danger-soft: #fdecee;
        }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; }
        body { font-family: 'Recursive', sans-serif; background: var(--bg); color: var(--text); }
        a { color: var(--accent-strong); }

        /* App grid */
        .app { display: grid; grid-template-columns: 240px 1fr; min-height: 100vh; }
        .sidebar {
            background: #fff;
            border-right: 1px solid var(--border);
            padding: 22px 14px;
            display: flex;
            flex-direction: column;
            gap: 22px;
            position: sticky;
            top: 0;
            height: 100vh;
        }
        .brand { display: flex; align-items: center; gap: 10px; padding: 0 6px; text-decoration: none; color: inherit; }
        .brand-logo { width: 36px; height: 36px; object-fit: contain; }
        .brand-text { font-weight: 800; font-size: 0.95rem; line-height: 1.1; }
        .brand-sub { font-size: 0.72rem; color: var(--muted); font-weight: 600; letter-spacing: 0.02em; }

        .nav { display: flex; flex-direction: column; gap: 2px; }
        .nav a {
            display: flex; align-items: center; gap: 10px;
            padding: 10px 12px;
            border-radius: 8px;
            color: var(--text);
            text-decoration: none;
            font-size: 0.92rem;
            font-weight: 500;
            border-left: 3px solid transparent;
        }
        .nav a:hover { background: #f5f7fa; }
        .nav a.active {
            background: var(--accent-soft);
            color: var(--accent-strong);
            font-weight: 700;
            border-left-color: var(--accent);
        }
        .nav-icon { width: 18px; height: 18px; flex: 0 0 18px; }

        .nav-divider { border-top: 1px solid var(--border); margin: 8px 0; }
        .nav-bottom { margin-top: auto; display: flex; flex-direction: column; gap: 8px; }
        .user-chip {
            padding: 10px 12px;
            border-radius: 8px;
            background: #f5f7fa;
            font-size: 0.82rem;
            display: flex; flex-direction: column; gap: 2px;
        }
        .user-chip .role { font-size: 0.68rem; padding: 1px 6px; border-radius: 6px; background: var(--accent); color: #fff; align-self: flex-start; font-weight: 700; }
        .user-chip .role.user { background: var(--muted); }

        /* Main */
        .main { padding: 28px 36px 60px; min-width: 0; }
        .topbar { display: flex; align-items: center; justify-content: space-between; gap: 16px; margin-bottom: 22px; flex-wrap: wrap; }
        .topbar h1 { margin: 0; font-size: 1.7rem; font-weight: 800; }
        .topbar-actions { display: flex; gap: 10px; flex-wrap: wrap; }

        /* Buttons */
        .btn {
            background: var(--accent);
            color: #fff;
            padding: 10px 18px;
            border: 1px solid var(--accent);
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            font-size: 0.9rem;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-family: inherit;
        }
        .btn:hover { background: var(--accent-strong); border-color: var(--accent-strong); }
        .btn-outline { background: #fff; color: var(--accent-strong); border-color: var(--accent); }
        .btn-outline:hover { background: var(--accent-soft); }
        .btn-sm { padding: 6px 12px; font-size: 0.8rem; }
        .btn-ghost { background: transparent; border-color: var(--border-strong); color: var(--text); }
        .btn-ghost:hover { background: #f5f7fa; border-color: var(--muted); }
        .btn-danger { background: var(--danger); border-color: var(--danger); color: #fff; }
        .btn-danger:hover { background: #b22a37; border-color: #b22a37; }
        .btn svg { width: 16px; height: 16px; }

        /* Forms */
        input, select, textarea {
            width: 100%;
            padding: 10px 12px;
            margin: 6px 0 16px;
            background: #fff;
            border: 1px solid var(--border-strong);
            color: var(--text);
            border-radius: 8px;
            font-family: inherit;
            font-size: 0.9rem;
        }
        input:focus, select:focus, textarea:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px var(--accent-soft); }
        input[type="color"] { height: 40px; padding: 2px; cursor: pointer; }
        input[type="checkbox"], input[type="radio"] { width: auto; margin: 0; }
        input[type="file"] { padding: 8px; }
        label { display: block; font-size: 0.82rem; color: var(--muted); font-weight: 600; margin-bottom: 2px; }

        /* Modal */
        .modal { display: none; position: fixed; inset: 0; background: rgba(15,23,42,0.45); z-index: 100; align-items: center; justify-content: center; padding: 20px; }
        .modal-content { background: #fff; padding: 28px; border-radius: 14px; width: 100%; max-width: 600px; position: relative; max-height: 92vh; overflow-y: auto; border: 1px solid var(--border); box-shadow: 0 24px 60px rgba(0,0,0,0.18); }
        .close-icon { position: absolute; top: 16px; right: 18px; cursor: pointer; fill: var(--muted); width: 22px; height: 22px; }
        .close-icon:hover { fill: var(--text); }

        /* Badges */
        .badge { display: inline-block; padding: 3px 10px; border-radius: 999px; font-size: 0.7rem; font-weight: 700; letter-spacing: 0.02em; text-transform: uppercase; }
        .badge-type { background: var(--warn-soft); color: var(--warn); }
        .badge-ok { background: var(--accent-soft); color: var(--accent-strong); }
        .badge-danger { background: var(--danger-soft); color: var(--danger); }

        /* Card */
        .card { background: var(--card); border: 1px solid var(--border); border-radius: 12px; padding: 18px 20px; }

        /* Spinner (designer) */
        .qr-spinner { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; background: rgba(255,255,255,0.85); border-radius: 8px; pointer-events: none; opacity: 0; transition: opacity 0.15s; }
        .qr-spinner.on { opacity: 1; }
        .qr-spinner::after { content: ''; width: 28px; height: 28px; border: 3px solid var(--border); border-top-color: var(--accent); border-radius: 50%; animation: qrspin 0.7s linear infinite; }
        @keyframes qrspin { to { transform: rotate(360deg); } }

        /* Designer */
        .designer-grid { display: grid; grid-template-columns: 280px 1fr; gap: 24px; align-items: start; }
        .designer-controls { display: grid; grid-template-columns: 1fr 1fr; gap: 12px 14px; }
        .designer-controls label { margin: 0; }
        .designer-controls label > input, .designer-controls label > select { margin: 4px 0 0; padding: 8px 10px; }
        .designer-controls .full { grid-column: 1 / -1; }
        .preset-row { display: flex; gap: 6px; flex-wrap: wrap; }
        .preset-chip { width: 32px; height: 32px; border-radius: 50%; border: 2px solid var(--border-strong); cursor: pointer; padding: 0; }
        .preset-chip:hover { border-color: var(--accent); transform: scale(1.08); }
        .preview-card { background: #fff; border: 1px solid var(--border); border-radius: 12px; padding: 14px; text-align: center; }
        .preview-card .qr-canvas { width: 200px; height: 200px; display: block; margin: 0 auto; position: relative; }
        .preview-card .qr-canvas svg, .preview-card .qr-canvas img { width: 100%; height: 100%; display: block; }
        .dl-row { display: flex; gap: 6px; justify-content: center; margin-top: 14px; flex-wrap: wrap; }
        @media (max-width: 720px) { .designer-grid { grid-template-columns: 1fr; } }

        /* Toggle Switch */
        .switch { position: relative; display: inline-block; width: 38px; height: 20px; }
        .switch input { opacity: 0; width: 0; height: 0; }
        .slider { position: absolute; cursor: pointer; inset: 0; background-color: #cfd6dd; transition: .25s; border-radius: 20px; }
        .slider:before { position: absolute; content: ""; height: 16px; width: 16px; left: 2px; bottom: 2px; background: #fff; transition: .25s; border-radius: 50%; }
        input:checked + .slider { background-color: var(--accent); }
        input:checked + .slider:before { transform: translateX(18px); }

        /* Stats dashboard (kept) */
        .kpi-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 12px; margin-bottom: 18px; }
        .kpi { background: var(--card); border: 1px solid var(--border); border-radius: 10px; padding: 14px 16px; }
        .kpi-label { font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.04em; color: var(--muted); font-weight: 600; }
        .kpi-value { font-size: 1.7rem; font-weight: 800; margin-top: 4px; line-height: 1.1; color: var(--accent-strong); }
        .kpi-value-sm { font-size: 0.95rem; font-weight: 700; margin-top: 6px; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 14px; }
        .card-block { background: var(--card); border: 1px solid var(--border); border-radius: 10px; padding: 16px; }
        .card-block h3 { margin: 0 0 12px; font-size: 1rem; }
        .bar-list { display: flex; flex-direction: column; gap: 8px; }
        .bar-row { display: grid; grid-template-columns: 100px 1fr 90px; align-items: center; gap: 10px; font-size: 0.85rem; }
        .bar-label { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .bar-track { background: #eef2f6; height: 10px; border-radius: 5px; overflow: hidden; }
        .bar-fill { height: 100%; background: linear-gradient(90deg, var(--accent), #4ade80); border-radius: 5px; }
        .bar-count { text-align: right; font-variant-numeric: tabular-nums; }
        .recent-scans { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
        .recent-scans th { text-align: left; padding: 8px 10px; border-bottom: 2px solid var(--border); color: var(--muted); font-weight: 600; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.03em; position: sticky; top: 0; background: var(--card); }
        .recent-scans td { padding: 10px; border-bottom: 1px solid var(--border); vertical-align: top; }
        .recent-scans tr:last-child td { border-bottom: none; }
        .scan-row { border-bottom: 1px solid var(--border); padding: 12px 0; font-size: 0.9rem; display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
        .scan-meta { color: var(--muted); font-size: 0.8rem; grid-column: 1 / -1; }
        .scan-badge { display: inline-block; background: var(--danger); color: #fff; font-size: 0.7em; font-weight: bold; padding: 2px 6px; border-radius: 3px; margin-top: 4px; }
        @media (min-width: 600px) { .scan-row { grid-template-columns: 1.5fr 1.5fr 1fr; align-items: start; } .scan-meta { grid-column: auto; } }

        .type-fields { display: none; }

        /* Legacy list (users.php) */
        .qr-list { display: grid; gap: 8px; }
        .qr-item { background: var(--card); border: 1px solid var(--border); border-radius: 10px; padding: 14px 16px; display: flex; align-items: center; justify-content: space-between; gap: 12px; }
        .qr-info h3 { margin: 0; font-size: 1rem; }
        .qr-meta { font-size: 0.8rem; color: var(--muted); margin-top: 2px; }
        .qr-stats { font-weight: 700; color: var(--info); cursor: pointer; text-decoration: underline; }

        /* Mobile */
        @media (max-width: 900px) {
            .app { grid-template-columns: 1fr; }
            .sidebar { position: static; height: auto; flex-direction: row; flex-wrap: wrap; align-items: center; padding: 12px 14px; gap: 12px; }
            .nav { flex-direction: row; flex-wrap: wrap; }
            .nav-divider, .nav-bottom { display: none; }
            .main { padding: 18px 16px 50px; }
        }
    </style>
</head>
<body>
<div class="app">
    <aside class="sidebar">
        <a class="brand" href="<?= htmlspecialchars(BASE_URL) ?>">
            <img src="<?= htmlspecialchars(BASE_URL) ?>/logo-v2.png" alt="" class="brand-logo">
            <div>
                <div class="brand-text">Umoor Iqtesadiyah</div>
                <div class="brand-sub">QR Track</div>
            </div>
        </a>

        <nav class="nav">
            <a href="<?= htmlspecialchars(BASE_URL) ?>" class="<?= $activeNav === 'qrcodes' ? 'active' : '' ?>">
                <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="16" y="16" width="3" height="3"/></svg>
                My QR Codes
            </a>
            <?php if ($hdrUser && $hdrUser['role'] === 'admin'): ?>
            <a href="<?= htmlspecialchars(BASE_URL) ?>/users.php" class="<?= $activeNav === 'users' ? 'active' : '' ?>">
                <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c0-3.3 2.9-6 6.5-6s6.5 2.7 6.5 6"/><circle cx="17" cy="9" r="2.5"/><path d="M21.5 18.5c0-2.4-1.9-4.5-4.5-4.5"/></svg>
                Users
            </a>
            <?php endif; ?>
            <a href="<?= htmlspecialchars(BASE_URL) ?>/api_instructions.php" class="<?= $activeNav === 'api' ? 'active' : '' ?>">
                <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 18l6-6-6-6"/><path d="M8 6l-6 6 6 6"/></svg>
                API Docs
            </a>
        </nav>

        <div class="nav-bottom">
            <?php if ($hdrUser): ?>
            <div class="user-chip">
                <span style="font-weight:600;"><?= htmlspecialchars($hdrUser['username']) ?></span>
                <span class="role <?= $hdrUser['role'] === 'admin' ? '' : 'user' ?>"><?= strtoupper(htmlspecialchars($hdrUser['role'])) ?></span>
            </div>
            <a href="<?= htmlspecialchars(BASE_URL) ?>/logout.php" class="btn btn-ghost btn-sm" style="justify-content:center;">Logout</a>
            <?php endif; ?>
        </div>
    </aside>

    <main class="main">
