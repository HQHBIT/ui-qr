<?php
require 'config.php';
require_auth();

$me      = current_user();
$isAdmin = is_admin();
$uuid    = $_GET['uuid'] ?? '';

if (!$uuid) {
    http_response_code(400);
    die('Missing uuid.');
}

$stmt = $db->prepare("
    SELECT p.*, u.username AS owner_username, f.name AS folder_name
    FROM products p
    LEFT JOIN users u ON u.id = p.user_id
    LEFT JOIN folders f ON f.id = p.folder_id
    WHERE p.uuid = ?
");
$stmt->execute([$uuid]);
$product = $stmt->fetch();
if (!$product) { http_response_code(404); die('QR not found.'); }

if (!$isAdmin && (int)$product['user_id'] !== (int)$me['id']) {
    http_response_code(403);
    die('Forbidden.');
}

// Pull all scans for this QR
$scans = $db->prepare("SELECT ip_address, user_agent, scanned_at, scan_status, geo_city, geo_region, geo_country, geo_isp FROM scans WHERE product_uuid = ? ORDER BY scanned_at DESC");
$scans->execute([$uuid]);
$scans = $scans->fetchAll();

// Convert UTC scan times to configured TZ
$tzLocal = new DateTimeZone(TIMEZONE);
$tzUtc   = new DateTimeZone('UTC');
foreach ($scans as &$s) {
    $dt = new DateTime($s['scanned_at'], $tzUtc);
    $dt->setTimezone($tzLocal);
    $s['scanned_local'] = $dt;
}
unset($s);

$total      = count($scans);
$totalOk    = 0;
$totalBlock = 0;
$uniqueIps  = [];

$byDay      = []; // YYYY-MM-DD => count
$byHour     = []; // 0..23 => count
$byDow      = []; // 0..6  => count (0 = Sunday)
$byCountry  = [];
$byCity     = [];
$byIsp      = [];
$byDevice   = [];
$byOs       = [];
$byBrowser  = [];

function parse_ua(string $ua): array {
    $ua = (string)$ua;
    $os = 'Other';
    $device = 'Desktop';
    $browser = 'Other';

    if (stripos($ua, 'iPhone') !== false || stripos($ua, 'iPod') !== false) { $os = 'iOS';     $device = 'Mobile'; }
    elseif (stripos($ua, 'iPad') !== false)                                  { $os = 'iPadOS';  $device = 'Tablet'; }
    elseif (stripos($ua, 'Android') !== false) {
        $os = 'Android';
        $device = stripos($ua, 'Mobile') !== false ? 'Mobile' : 'Tablet';
    }
    elseif (stripos($ua, 'Windows') !== false) { $os = 'Windows'; }
    elseif (stripos($ua, 'Mac OS X') !== false || stripos($ua, 'Macintosh') !== false) { $os = 'macOS'; }
    elseif (stripos($ua, 'CrOS') !== false)    { $os = 'ChromeOS'; }
    elseif (stripos($ua, 'Linux') !== false)   { $os = 'Linux'; }

    if     (stripos($ua, 'Edg/') !== false || stripos($ua, 'EdgA/') !== false) $browser = 'Edge';
    elseif (stripos($ua, 'OPR/') !== false || stripos($ua, 'Opera') !== false) $browser = 'Opera';
    elseif (stripos($ua, 'SamsungBrowser') !== false) $browser = 'Samsung Internet';
    elseif (stripos($ua, 'Firefox') !== false) $browser = 'Firefox';
    elseif (stripos($ua, 'Chrome') !== false)  $browser = 'Chrome';
    elseif (stripos($ua, 'Safari') !== false)  $browser = 'Safari';
    elseif (stripos($ua, 'curl')   !== false)  $browser = 'curl';
    elseif (stripos($ua, 'bot') !== false || stripos($ua, 'spider') !== false) $browser = 'Bot';

    return [$os, $device, $browser];
}

foreach ($scans as $s) {
    $dt = $s['scanned_local'];
    $day  = $dt->format('Y-m-d');
    $hour = (int)$dt->format('G');
    $dow  = (int)$dt->format('w');

    $byDay[$day]   = ($byDay[$day]   ?? 0) + 1;
    $byHour[$hour] = ($byHour[$hour] ?? 0) + 1;
    $byDow[$dow]   = ($byDow[$dow]   ?? 0) + 1;

    $uniqueIps[$s['ip_address']] = true;

    if ($s['scan_status'] === 'blocked') $totalBlock++;
    else                                  $totalOk++;

    $country = $s['geo_country'] ?: 'Unknown';
    $city    = ($s['geo_city'] ?: 'Unknown') . ($s['geo_country'] ? ', ' . $s['geo_country'] : '');
    $isp     = $s['geo_isp'] ?: 'Unknown';

    $byCountry[$country] = ($byCountry[$country] ?? 0) + 1;
    $byCity[$city]       = ($byCity[$city]       ?? 0) + 1;
    $byIsp[$isp]         = ($byIsp[$isp]         ?? 0) + 1;

    [$os, $device, $browser] = parse_ua($s['user_agent'] ?? '');
    $byOs[$os]           = ($byOs[$os]           ?? 0) + 1;
    $byDevice[$device]   = ($byDevice[$device]   ?? 0) + 1;
    $byBrowser[$browser] = ($byBrowser[$browser] ?? 0) + 1;
}

// Build a 30-day continuous time series
$days = [];
$dailySeries = [];
$now = new DateTime('now', $tzLocal);
for ($i = 29; $i >= 0; $i--) {
    $d = (clone $now)->modify("-$i days")->format('Y-m-d');
    $days[] = $d;
    $dailySeries[] = (int)($byDay[$d] ?? 0);
}

// Hour-of-day series (24)
$hourSeries = [];
for ($h = 0; $h < 24; $h++) $hourSeries[] = (int)($byHour[$h] ?? 0);

// Day-of-week series (Mon..Sun for clarity)
$dowLabels = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];
$dowSeries = [];
foreach ([1,2,3,4,5,6,0] as $d) $dowSeries[] = (int)($byDow[$d] ?? 0);

// Sort + clamp top buckets
arsort($byCountry); arsort($byCity); arsort($byIsp); arsort($byDevice); arsort($byOs); arsort($byBrowser);
$topCountries = array_slice($byCountry, 0, 10, true);
$topCities    = array_slice($byCity,    0, 10, true);
$topIsps      = array_slice($byIsp,     0, 10, true);

$peakDay  = $byDay  ? array_keys($byDay,  max($byDay))[0]  : null;
$peakHour = $byHour ? array_keys($byHour, max($byHour))[0] : null;

$lastScan = $scans ? $scans[0]['scanned_local']->format('Y-m-d H:i') : null;

define('PAGE_TITLE', 'Stats: ' . $product['title']);
include THEME_PATH . '/header.php';
?>

<div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; margin-bottom:18px;">
    <div style="display:flex; align-items:center; gap:14px; flex-wrap:wrap;">
        <a href="<?= htmlspecialchars(BASE_URL) ?><?= $product['folder_id'] !== null ? '?folder=' . (int)$product['folder_id'] : '' ?>" class="btn btn-sm" style="background:#6c757d;">&larr; Back</a>
        <h2 style="margin:0;"><?= htmlspecialchars($product['title']) ?>
            <span style="font-size:0.6em; opacity:0.6; font-weight:normal;">[<?= strtoupper(htmlspecialchars($product['type'])) ?>]</span>
        </h2>
        <?php if ($product['folder_name']): ?>
            <span style="font-size:0.85em; color:var(--muted);">📁 <?= htmlspecialchars($product['folder_name']) ?></span>
        <?php endif; ?>
        <?php if ($isAdmin && $product['owner_username']): ?>
            <span style="font-size:0.75em; padding:2px 8px; border-radius:8px; background:#eef3ff; color:var(--accent);"><?= htmlspecialchars($product['owner_username']) ?></span>
        <?php endif; ?>
    </div>
    <div style="display:flex; gap:8px;">
        <a href="<?= htmlspecialchars(BASE_URL) ?>/p/<?= htmlspecialchars($uuid) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-info">Open Link</a>
    </div>
</div>

<div class="kpi-row">
    <div class="kpi"><div class="kpi-label">Total Scans</div><div class="kpi-value"><?= number_format($total) ?></div></div>
    <div class="kpi"><div class="kpi-label">Unique IPs</div><div class="kpi-value"><?= number_format(count($uniqueIps)) ?></div></div>
    <div class="kpi"><div class="kpi-label">Successful</div><div class="kpi-value" style="color:#28a745;"><?= number_format($totalOk) ?></div></div>
    <div class="kpi"><div class="kpi-label">Blocked</div><div class="kpi-value" style="color:#dc3545;"><?= number_format($totalBlock) ?></div></div>
    <div class="kpi"><div class="kpi-label">Last Scan</div><div class="kpi-value-sm"><?= $lastScan ? htmlspecialchars($lastScan) : '—' ?></div></div>
    <div class="kpi"><div class="kpi-label">Peak Day</div><div class="kpi-value-sm"><?= $peakDay ? htmlspecialchars($peakDay) : '—' ?></div></div>
</div>

<?php if ($total === 0): ?>
    <div style="background:var(--card); border:1px solid var(--border); border-radius:10px; padding:50px 20px; text-align:center; margin-top:20px;">
        <div style="font-size:3rem; margin-bottom:10px;">📊</div>
        <h3 style="margin:0 0 6px;">No scans yet</h3>
        <p style="color:var(--muted); margin:0;">Stats will appear once people scan this code.</p>
    </div>
<?php else: ?>

<div class="stats-grid">
    <div class="card-block" style="grid-column: 1 / -1;">
        <h3>Scans — Last 30 days</h3>
        <canvas id="chartDaily" height="80"></canvas>
    </div>

    <div class="card-block">
        <h3>Hour of Day</h3>
        <canvas id="chartHour" height="120"></canvas>
    </div>

    <div class="card-block">
        <h3>Day of Week</h3>
        <canvas id="chartDow" height="120"></canvas>
    </div>

    <div class="card-block">
        <h3>Devices</h3>
        <canvas id="chartDevice" height="160"></canvas>
    </div>

    <div class="card-block">
        <h3>Operating System</h3>
        <canvas id="chartOs" height="160"></canvas>
    </div>

    <div class="card-block">
        <h3>Browsers</h3>
        <canvas id="chartBrowser" height="160"></canvas>
    </div>

    <div class="card-block">
        <h3>Top Countries</h3>
        <?= render_bar_list($topCountries, $total) ?>
    </div>

    <div class="card-block">
        <h3>Top Cities</h3>
        <?= render_bar_list($topCities, $total) ?>
    </div>

    <div class="card-block">
        <h3>Top ISPs</h3>
        <?= render_bar_list($topIsps, $total) ?>
    </div>

    <div class="card-block" style="grid-column: 1 / -1;">
        <h3>Recent Scans</h3>
        <div style="max-height:380px; overflow-y:auto;">
            <table class="recent-scans">
                <thead>
                    <tr>
                        <th>When</th>
                        <th>Location</th>
                        <th>IP / ISP</th>
                        <th>Device</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (array_slice($scans, 0, 100) as $s):
                        [$os, $device, $browser] = parse_ua($s['user_agent'] ?? '');
                    ?>
                        <tr>
                            <td><?= htmlspecialchars($s['scanned_local']->format('M d, H:i')) ?></td>
                            <td><?= htmlspecialchars(($s['geo_city'] ?: '—') . ($s['geo_country'] ? ', ' . $s['geo_country'] : '')) ?></td>
                            <td>
                                <div><?= htmlspecialchars($s['ip_address']) ?></div>
                                <div style="color:var(--muted); font-size:0.78em;"><?= htmlspecialchars($s['geo_isp'] ?: '—') ?></div>
                            </td>
                            <td>
                                <div><?= htmlspecialchars($device) ?> · <?= htmlspecialchars($os) ?></div>
                                <div style="color:var(--muted); font-size:0.78em;"><?= htmlspecialchars($browser) ?></div>
                            </td>
                            <td>
                                <?php if ($s['scan_status'] === 'blocked'): ?>
                                    <span class="badge badge-danger">BLOCKED</span>
                                <?php else: ?>
                                    <span class="badge badge-ok">OK</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
const ACCENT = getComputedStyle(document.documentElement).getPropertyValue('--accent').trim() || '#1e90ff';
const PIE_COLORS = ['#1e90ff','#ff7e5f','#28a745','#ffc107','#6f42c1','#17a2b8','#fd7e14','#e83e8c','#20c997','#6c757d'];

Chart.defaults.font.family = "'Recursive', sans-serif";
Chart.defaults.color = '#1a1a1a';

new Chart(document.getElementById('chartDaily'), {
    type: 'line',
    data: {
        labels: <?= json_encode($days) ?>,
        datasets: [{
            label: 'Scans',
            data: <?= json_encode($dailySeries) ?>,
            borderColor: ACCENT,
            backgroundColor: 'rgba(30,144,255,0.15)',
            fill: true, tension: 0.3, pointRadius: 3, pointHoverRadius: 5,
        }]
    },
    options: {
        plugins: { legend: { display: false } },
        scales: { x: { ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: 10 } }, y: { beginAtZero: true, ticks: { precision: 0 } } }
    }
});

new Chart(document.getElementById('chartHour'), {
    type: 'bar',
    data: {
        labels: Array.from({length:24}, (_,i) => String(i).padStart(2,'0')),
        datasets: [{ label: 'Scans', data: <?= json_encode($hourSeries) ?>, backgroundColor: ACCENT, borderRadius: 4 }]
    },
    options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
});

new Chart(document.getElementById('chartDow'), {
    type: 'bar',
    data: {
        labels: <?= json_encode($dowLabels) ?>,
        datasets: [{ label: 'Scans', data: <?= json_encode($dowSeries) ?>, backgroundColor: '#28a745', borderRadius: 4 }]
    },
    options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
});

function makeDoughnut(canvasId, data) {
    const labels = Object.keys(data);
    const values = Object.values(data);
    new Chart(document.getElementById(canvasId), {
        type: 'doughnut',
        data: {
            labels,
            datasets: [{ data: values, backgroundColor: PIE_COLORS, borderWidth: 2, borderColor: '#fff' }]
        },
        options: { plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, padding: 10, font: { size: 11 } } } }, cutout: '55%' }
    });
}
makeDoughnut('chartDevice',  <?= json_encode($byDevice) ?>);
makeDoughnut('chartOs',      <?= json_encode($byOs) ?>);
makeDoughnut('chartBrowser', <?= json_encode($byBrowser) ?>);
</script>

<?php endif; ?>

<?php
function render_bar_list(array $items, int $total): string {
    if (!$items) return '<p style="color:var(--muted);">No data.</p>';
    $max = max($items);
    $html = '<div class="bar-list">';
    foreach ($items as $label => $count) {
        $pct = $max > 0 ? ($count / $max) * 100 : 0;
        $share = $total > 0 ? ($count / $total) * 100 : 0;
        $html .= '<div class="bar-row">'
              . '<div class="bar-label" title="' . htmlspecialchars($label) . '">' . htmlspecialchars($label) . '</div>'
              . '<div class="bar-track"><div class="bar-fill" style="width:' . round($pct, 1) . '%"></div></div>'
              . '<div class="bar-count">' . number_format($count) . ' <span style="color:var(--muted); font-size:0.78em;">(' . round($share, 1) . '%)</span></div>'
              . '</div>';
    }
    return $html . '</div>';
}

include THEME_PATH . '/footer.php';
