<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= defined('PAGE_TITLE') ? htmlspecialchars(PAGE_TITLE) : 'Umoor Iqtesadiyah QR Track' ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Recursive:wght@300..900&display=swap" rel="stylesheet">
    <style>
        :root { --bg: #f5f7fa; --card: #ffffff; --text: #1a1a1a; --accent: #1e90ff; --border: #dde2e8; --danger: #dc3545; --info: #17a2b8; --muted: #6c757d; }
        body { font-family: 'Recursive', sans-serif; background: var(--bg); color: var(--text); margin: 0; padding: 20px; }

        .container { max-width: 1000px; margin: 0 auto; }

        /* Header */
        header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; border-bottom: 1px solid var(--border); padding-bottom: 20px; }
        .header-brand { display: flex; align-items: center; gap: 15px; }
        .logo-img { height: 50px; width: auto; }
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
        .preview-card { background: #fff; border: 1px solid var(--border); border-radius: 12px; padding: 16px; text-align: center; }
        .preview-card img { width: 240px; height: 240px; display: block; margin: 0 auto; }
        .dl-row { display: flex; gap: 6px; justify-content: center; margin-top: 14px; flex-wrap: wrap; }
        .swatch-pair { display: flex; align-items: center; gap: 8px; }
        .swatch-hex { font-family: monospace; font-size: 0.78rem; color: var(--muted); }
        @media (max-width: 720px) { .designer-grid { grid-template-columns: 1fr; } }

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
            <div>
                <h1>Umoor Iqtesadiyah QR Track</h1>
                <small style="color: var(--muted);">Generate &amp; Track QR Codes Easily!</small>
            </div>
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
