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
        :root { --bg: #f5f7fa; --card: #ffffff; --text: #1a1a1a; --accent: #1e90ff; --border: #dde2e8; --danger: #dc3545; --info: #17a2b8; --muted: #6c757d; }
        body { font-family: 'Recursive', sans-serif; background: var(--bg); color: var(--text); margin: 0; padding: 20px; }

        .container { max-width: 1000px; margin: 0 auto; }

        /* Header */
        header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; border-bottom: 1px solid var(--border); padding-bottom: 20px; }
        .header-brand { display: flex; align-items: center; gap: 15px; }
        .logo-img { height: 60px; width: auto; }
        h1 { margin: 0; font-weight: 800; background: linear-gradient(45deg, #1e90ff, #66b2ff); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }

        /* UI Elements */
        .btn { background: var(--accent); color: #fff; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer; font-weight: 600; text-decoration: none; display: inline-block; }
        .btn:hover { opacity: 0.9; }
        .btn-sm { padding: 5px 10px; font-size: 0.8rem; }
        .btn-danger { background: var(--danger); }
        .btn-info   { background: var(--info); }
        .btn-logout { background: var(--muted); }

        /* Compact Grid */
        .qr-list { display: grid; gap: 8px; }
        .qr-item {
            background: var(--card);
            padding: 12px 15px;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border: 1px solid var(--border);
            transition: transform 0.2s;
        }
        .qr-item { box-shadow: 0 1px 2px rgba(0,0,0,0.04); }
        .qr-item:hover { transform: translateY(-1px); border-color: var(--accent); box-shadow: 0 2px 8px rgba(30,144,255,0.12); }
        .qr-info h3 { margin: 0; font-size: 1.05rem; }
        .qr-meta { font-size: 0.8rem; color: var(--muted); margin-top: 2px; }
        .qr-stats { font-weight: bold; color: var(--accent); cursor: pointer; text-decoration: underline; margin-right: 15px; }

        /* Modals */
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.45); z-index: 100; align-items: center; justify-content: center; }
        .modal-content { background: var(--card); padding: 30px; border-radius: 10px; width: 90%; max-width: 600px; position: relative; max-height: 90vh; overflow-y: auto; border: 1px solid var(--border); box-shadow: 0 10px 30px rgba(0,0,0,0.15); }

        /* SVG Close Icon */
        .close-icon { position: absolute; top: 15px; right: 20px; cursor: pointer; fill: #333; width: 24px; height: 24px; opacity: 0.6; transition: opacity 0.2s; }
        .close-icon:hover { opacity: 1; }

        /* Inputs */
        input, select, textarea { width: 100%; padding: 12px; margin: 8px 0 20px; background: #ffffff; border: 1px solid var(--border); color: var(--text); border-radius: 4px; box-sizing: border-box; font-family: inherit; }
        input:focus, select:focus, textarea:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px rgba(30,144,255,0.15); }
        input[type="color"] { width: 100%; height: 40px; padding: 2px; cursor: pointer; }
        input[type="color"]::-webkit-color-swatch-wrapper { padding: 0; }
        input[type="color"]::-webkit-color-swatch { border: 1px solid var(--border); border-radius: 3px; }
        input[type="checkbox"], input[type="radio"] { width: auto; margin: 0; padding: 0; }
        input[type="file"] { padding: 8px; }

        /* Designer */
        .designer-grid { display: grid; grid-template-columns: 280px 1fr; gap: 24px; align-items: start; }
        .designer-controls { display: grid; grid-template-columns: 1fr 1fr; gap: 12px 14px; }
        .designer-controls label { display: block; margin: 0; font-size: 0.82rem; color: var(--muted); font-weight: 600; }
        .designer-controls label > input, .designer-controls label > select { margin: 4px 0 0; padding: 8px 10px; }
        .designer-controls .full { grid-column: 1 / -1; }
        .preset-row { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 4px; }
        .preset-chip { width: 32px; height: 32px; border-radius: 50%; border: 2px solid var(--border); cursor: pointer; padding: 0; position: relative; overflow: hidden; }
        .preset-chip:hover { border-color: var(--accent); transform: scale(1.08); }
        .preview-card { background: #fff; border: 1px solid var(--border); border-radius: 12px; padding: 14px; text-align: center; }
        .preview-card .qr-canvas { width: 200px; height: 200px; display: block; margin: 0 auto; position: relative; }
        .preview-card .qr-canvas svg { width: 100%; height: 100%; display: block; }
        .qr-spinner { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; background: rgba(255,255,255,0.85); border-radius: 8px; pointer-events: none; opacity: 0; transition: opacity 0.15s; }
        .qr-spinner.on { opacity: 1; }
        .qr-spinner::after { content: ''; width: 28px; height: 28px; border: 3px solid #dde2e8; border-top-color: var(--accent); border-radius: 50%; animation: qrspin 0.7s linear infinite; }
        @keyframes qrspin { to { transform: rotate(360deg); } }
        .dl-row { display: flex; gap: 6px; justify-content: center; margin-top: 14px; flex-wrap: wrap; }
        .swatch-pair { display: flex; align-items: center; gap: 8px; }
        .swatch-hex { font-family: monospace; font-size: 0.78rem; color: var(--muted); }
        @media (max-width: 720px) { .designer-grid { grid-template-columns: 1fr; } }

        /* Folders */
        .folder-bar { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 14px; flex-wrap: wrap; }
        .folder-breadcrumb { display: flex; align-items: center; gap: 6px; font-size: 0.95rem; flex-wrap: wrap; }
        .folder-breadcrumb a { color: var(--accent); text-decoration: none; }
        .folder-breadcrumb a:hover { text-decoration: underline; }
        .crumb-sep { color: var(--muted); }
        .folder-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 10px; margin-bottom: 18px; }
        .folder-card { background: var(--card); border: 1px solid var(--border); border-radius: 8px; padding: 14px 12px; text-decoration: none; color: var(--text); display: flex; flex-direction: column; align-items: center; gap: 4px; transition: border-color 0.15s, transform 0.15s, box-shadow 0.15s; }
        .folder-card:hover { border-color: var(--accent); transform: translateY(-1px); box-shadow: 0 2px 8px rgba(30,144,255,0.12); }
        .folder-icon { font-size: 1.8rem; line-height: 1; }
        .folder-name { font-weight: 600; font-size: 0.95rem; text-align: center; word-break: break-word; }
        .folder-meta { font-size: 0.75rem; color: var(--muted); }

        /* Stats dashboard */
        .kpi-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 12px; margin-bottom: 18px; }
        .kpi { background: var(--card); border: 1px solid var(--border); border-radius: 10px; padding: 14px 16px; }
        .kpi-label { font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.04em; color: var(--muted); font-weight: 600; }
        .kpi-value { font-size: 1.7rem; font-weight: 800; margin-top: 4px; line-height: 1.1; background: linear-gradient(45deg, #1e90ff, #66b2ff); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
        .kpi-value-sm { font-size: 0.95rem; font-weight: 700; margin-top: 6px; }

        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 14px; }
        .card-block { background: var(--card); border: 1px solid var(--border); border-radius: 10px; padding: 16px; }
        .card-block h3 { margin: 0 0 12px; font-size: 1rem; color: var(--text); }

        .bar-list { display: flex; flex-direction: column; gap: 8px; }
        .bar-row { display: grid; grid-template-columns: 100px 1fr 90px; align-items: center; gap: 10px; font-size: 0.85rem; }
        .bar-label { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .bar-track { background: #eef2f6; height: 10px; border-radius: 5px; overflow: hidden; }
        .bar-fill { height: 100%; background: linear-gradient(90deg, #1e90ff, #66b2ff); border-radius: 5px; }
        .bar-count { text-align: right; font-variant-numeric: tabular-nums; }

        .recent-scans { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
        .recent-scans th { text-align: left; padding: 8px 10px; border-bottom: 2px solid var(--border); color: var(--muted); font-weight: 600; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.03em; position: sticky; top: 0; background: var(--card); }
        .recent-scans td { padding: 10px; border-bottom: 1px solid var(--border); vertical-align: top; }
        .recent-scans tr:last-child td { border-bottom: none; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 0.7rem; font-weight: 700; letter-spacing: 0.03em; }
        .badge-ok { background: #d4edda; color: #155724; }
        .badge-danger { background: #f8d7da; color: #721c24; }

        /* Toggle Switch */
        .switch { position: relative; display: inline-block; width: 40px; height: 20px; margin: 0 15px; }
        .switch input { opacity: 0; width: 0; height: 0; }
        .slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #cfd6dd; transition: .4s; border-radius: 20px; }
        .slider:before { position: absolute; content: ""; height: 16px; width: 16px; left: 2px; bottom: 2px; background-color: white; transition: .4s; border-radius: 50%; }
        input:checked + .slider { background-color: var(--accent); }
        input:checked + .slider:before { transform: translateX(20px); }

        /* Scan Stats */
        .scan-row { border-bottom: 1px solid var(--border); padding: 12px 0; font-size: 0.9rem; display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
        .scan-meta { color: var(--muted); font-size: 0.8rem; grid-column: 1 / -1; }
        .scan-badge { display: inline-block; background: var(--danger); color: #fff; font-size: 0.7em; font-weight: bold; padding: 2px 6px; border-radius: 3px; margin-top: 4px; }

        @media (min-width: 600px) {
            .scan-row { grid-template-columns: 1.5fr 1.5fr 1fr; align-items: start; }
            .scan-meta { grid-column: auto; }
        }

        .type-fields { display: none; }
    </style>
</head>
<body>
<div class="container">
    <header>
        <div class="header-brand">
            <a href="<?= htmlspecialchars(BASE_URL) ?>" style="text-decoration:none; display:flex; align-items:center; gap:15px; color:inherit;">
                <img src="<?= htmlspecialchars(BASE_URL) ?>/logo-v2.png" alt="Logo" class="logo-img">
                <div>
                    <h1>Umoor Iqtesadiyah QR Track</h1>
                    <small style="color: var(--muted);">Generate &amp; Track QR Codes Easily!</small>
                </div>
            </a>
        </div>

        <div style="display:flex; gap:10px; flex-wrap:wrap; justify-content:flex-end; align-items:center;">
            <?php $hdrUser = function_exists('current_user') ? current_user() : null; ?>
            <?php if ($hdrUser): ?>
                <span style="color:var(--muted); font-size:0.85em;">
                    <?= htmlspecialchars($hdrUser['username']) ?>
                    <span style="padding:1px 6px; border-radius:8px; background:<?= $hdrUser['role']==='admin' ? '#1e90ff' : '#6c757d' ?>; color:#fff; font-size:0.75em; margin-left:4px;"><?= strtoupper($hdrUser['role']) ?></span>
                </span>
                <?php if ($hdrUser['role'] === 'admin'): ?>
                    <a href="<?= htmlspecialchars(BASE_URL) ?>/users.php" class="btn btn-sm" style="background:#6c757d;">Users</a>
                <?php endif; ?>
            <?php endif; ?>
            <?php if(defined('SHOW_ADD_BTN')): ?>
                <a href="<?= htmlspecialchars(BASE_URL) ?>/api_instructions.php" class="btn btn-info btn-sm">API</a>
                <button class="btn btn-sm" onclick="openModal('addModal')">+ New QR Code</button>
            <?php endif; ?>
            <?php if ($hdrUser): ?>
                <a href="<?= htmlspecialchars(BASE_URL) ?>/logout.php" class="btn btn-logout btn-sm">Logout</a>
            <?php endif; ?>
        </div>
    </header>
