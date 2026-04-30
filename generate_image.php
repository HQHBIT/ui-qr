<?php
require 'vendor/autoload.php';
require 'config.php';

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use chillerlan\QRCode\Data\QRMatrix;

// --- SECURITY CHECK ---
if (session_status() === PHP_SESSION_NONE) session_start();
$isLoggedIn = isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;

$uuid   = $_GET['id'] ?? '';
$token  = $_GET['token'] ?? '';
$format = in_array($_GET['format'] ?? '', ['png', 'jpg', 'svg', 'json'], true) ? $_GET['format'] : 'png';

if (!$uuid) { http_response_code(400); die('No ID'); }

// --- FETCH ---
$stmt = $db->prepare("SELECT * FROM products WHERE uuid = :uuid");
$stmt->execute([':uuid' => $uuid]);
$item = $stmt->fetch();
if (!$item) { http_response_code(404); die('Invalid ID'); }

// --- AUTHZ ---
$authorized = false;
$me = current_user();
if ($isLoggedIn && $me) {
    if ($me['role'] === 'admin' || (int)$item['user_id'] === (int)$me['id']) {
        $authorized = true;
    }
} elseif (!empty($token)) {
    $stmt = $db->prepare("SELECT id FROM api_tokens WHERE token = ? AND product_uuid = ? AND expires_at > datetime('now') LIMIT 1");
    $stmt->execute([$token, $uuid]);
    if ($stmt->fetch()) $authorized = true;
}
if (!$authorized) {
    http_response_code(403);
    die('Forbidden: Invalid or expired access token. Login to dashboard or generate a new API token.');
}

// --- CACHE LOOKUP (skip for json matrix and live-design previews) ---
$designOverrideKeys = ['fg','bg','fg2','eye_color','gradient','gradient_dir','module_shape','eye_shape','eye_inner'];
$hasOverrides = false;
foreach ($designOverrideKeys as $k) { if (isset($_GET[$k])) { $hasOverrides = true; break; } }
$cacheable = ($format !== 'json') && !$hasOverrides;
$cacheFile = null;
if ($cacheable) {
    $logoFullForKey = ($item['logo_path'] && file_exists(LOGO_DIR . '/' . $item['logo_path']))
        ? LOGO_DIR . '/' . $item['logo_path']
        : null;
    $logoMtime = $logoFullForKey ? (int)filemtime($logoFullForKey) : 0;
    $cacheKey = hash('sha256', implode('|', [
        $uuid,
        (string)$item['type'],
        (string)$item['target_data'],
        (string)($item['design_json'] ?? ''),
        (string)($item['logo_path'] ?? ''),
        (string)$logoMtime,
        $format,
    ]));
    $cacheFile = qr_cache_dir() . '/' . $uuid . '-' . substr($cacheKey, 0, 16) . '.' . $format;
    if (is_file($cacheFile)) {
        $ctype = $format === 'svg' ? 'image/svg+xml'
               : ($format === 'jpg' ? 'image/jpeg' : 'image/png');
        header('Content-Type: ' . $ctype);
        header('Cache-Control: private, max-age=86400');
        header('ETag: "' . substr($cacheKey, 0, 16) . '"');
        readfile($cacheFile);
        exit;
    }
}

function qr_cache_write(?string $path, string $bytes): void {
    if ($path === null) return;
    $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, $bytes) !== false) {
        @rename($tmp, $path);
    } else {
        @unlink($tmp);
    }
}

// --- CONTENT ---
if ($item['type'] === 'wifi') {
    $j = json_decode($item['target_data'], true);
    $qrContent = "WIFI:S:{$j['ssid']};T:{$j['enc']};P:{$j['pass']};;";
} else {
    $qrContent = BASE_URL . "/p/" . $uuid;
}

// --- DESIGN (preview overrides DB so live editor works) ---
$saved = json_decode($item['design_json'] ?? '', true) ?: [];
$ovr = function ($k, $default) use ($saved) {
    if (!isset($_GET[$k])) return $saved[$k] ?? $default;
    $v = $_GET[$k];
    if (in_array($k, ['fg','bg','fg2','eye_color'], true)) {
        return preg_match('/^#[0-9a-fA-F]{6}$/', $v) ? strtolower($v) : ($saved[$k] ?? $default);
    }
    if ($k === 'gradient')     return $v === '1' || $v === 'true';
    if ($k === 'gradient_dir') return in_array($v, ['horizontal','vertical','diagonal','radial'], true) ? $v : $default;
    if ($k === 'module_shape') return in_array($v, ['square','dot','rounded','classy','diamond','star','cross'], true) ? $v : $default;
    if ($k === 'eye_shape')    return in_array($v, ['square','rounded','circle','leaf','frame','flower'], true) ? $v : $default;
    if ($k === 'eye_inner')    return in_array($v, ['square','rounded','circle','dot','diamond'], true) ? $v : $default;
    return $default;
};

$design = [
    'fg'           => $ovr('fg', '#000000'),
    'bg'           => $ovr('bg', '#ffffff'),
    'gradient'     => (bool)$ovr('gradient', false),
    'gradient_dir' => $ovr('gradient_dir', 'diagonal'),
    'fg2'          => $ovr('fg2', '#1e90ff'),
    'module_shape' => $ovr('module_shape', 'square'),
    'eye_shape'    => $ovr('eye_shape', 'square'),
    'eye_inner'    => $ovr('eye_inner', 'square'),
    'eye_color'    => $ovr('eye_color', $ovr('fg', '#000000')),
];

// --- BUILD MATRIX (no quietzone — added in SVG) ---
$options = new QROptions([
    'version'         => 7,
    'eccLevel'        => QRCode::ECC_H,
    'addQuietzone'    => false,
]);
$qrcode = new QRCode($options);
$qrcode->addByteSegment($qrContent);
$matrix = $qrcode->getQRMatrix();
$size   = $matrix->getSize();

// JSON matrix for client-side renderer (super fast preview)
if ($format === 'json') {
    $cells = [];
    for ($y = 0; $y < $size; $y++) {
        $row = [];
        for ($x = 0; $x < $size; $x++) $row[] = $matrix->check($x, $y) ? 1 : 0;
        $cells[] = $row;
    }
    $logoData = null;
    $logoFullPath = ($item['logo_path'] && file_exists(LOGO_DIR . '/' . $item['logo_path']))
        ? LOGO_DIR . '/' . $item['logo_path']
        : null;
    if ($logoFullPath) {
        $bytes = file_get_contents($logoFullPath);
        $info  = getimagesizefromstring($bytes);
        $mime  = $info['mime'] ?? 'image/png';
        $logoData = 'data:' . $mime . ';base64,' . base64_encode($bytes);
    }
    header('Content-Type: application/json');
    header('Cache-Control: private, max-age=300');
    echo json_encode([
        'size'    => $size,
        'cells'   => $cells,
        'design'  => $design,
        'hasLogo' => $logoData !== null,
        'logo'    => $logoData,
    ]);
    exit;
}

// --- LOGO ---
$logoFile = ($item['logo_path'] && file_exists(LOGO_DIR . '/' . $item['logo_path']))
    ? LOGO_DIR . '/' . $item['logo_path']
    : null;

// --- RENDER SVG ---
$scale     = 14;            // px per module
$quietCells = 4;
$pxSize    = ($size + 2 * $quietCells) * $scale;
$svg       = render_designer_svg($matrix, $design, $logoFile, $scale, $quietCells);

if ($format === 'svg') {
    qr_cache_write($cacheFile, $svg);
    header('Content-Type: image/svg+xml');
    header('Cache-Control: private, max-age=86400');
    echo $svg;
    exit;
}

// --- RASTERIZE ---
$rasterized = svg_to_raster($svg, $format, $pxSize);
if ($rasterized === null) {
    // Fallback: render plain GD square QR with logo, no shapes/colors
    $opts = new QROptions([
        'version'          => 7,
        'outputType'       => $format === 'png' ? QRCode::OUTPUT_IMAGE_PNG : QRCode::OUTPUT_IMAGE_JPG,
        'eccLevel'         => QRCode::ECC_H,
        'scale'            => 10,
        'imageBase64'      => false,
        'imageTransparent' => ($format === 'png'),
    ]);
    $img = (new QRCode($opts))->render($qrContent);
    if ($logoFile) {
        $src  = imagecreatefromstring($img);
        $logo = imagecreatefromstring(file_get_contents($logoFile));
        if ($src && $logo) {
            $W = imagesx($src); $H = imagesy($src);
            $LW = imagesx($logo); $LH = imagesy($logo);
            $tw = $W / 5; $sc = $LW / $tw; $th = $LH / $sc;
            imagecopyresampled($src, $logo,
                (int)(($W - $tw)/2), (int)(($H - $th)/2), 0, 0,
                (int)$tw, (int)$th, $LW, $LH);
            ob_start();
            if ($format === 'png') imagepng($src); else imagejpeg($src);
            $img = ob_get_clean();
            imagedestroy($src); imagedestroy($logo);
        }
    }
    qr_cache_write($cacheFile, $img);
    header('Content-Type: image/' . $format);
    header('Cache-Control: private, max-age=86400');
    echo $img;
    exit;
}

qr_cache_write($cacheFile, $rasterized);
header('Content-Type: image/' . ($format === 'jpg' ? 'jpeg' : 'png'));
header('Cache-Control: private, max-age=86400');
echo $rasterized;
exit;

// =========================================================================
// RENDERERS
// =========================================================================

function render_designer_svg(QRMatrix $matrix, array $d, ?string $logoFile, int $scale, int $qz): string {
    $size      = $matrix->getSize();
    $totalCells = $size + 2 * $qz;
    $px        = $totalCells * $scale;
    $offset    = $qz * $scale;
    $bg        = htmlspecialchars($d['bg'], ENT_QUOTES);
    $fg        = htmlspecialchars($d['fg'], ENT_QUOTES);
    $fg2       = htmlspecialchars($d['fg2'], ENT_QUOTES);
    $eyeCo     = htmlspecialchars($d['eye_color'], ENT_QUOTES);
    $useGr     = !empty($d['gradient']);
    $fillData  = $useGr ? "url(#qrg)" : $fg;

    $defs = '';
    $qrPx = $size * $scale; // data area only (translate handles offset)
    if ($useGr) {
        $dir = $d['gradient_dir'] ?? 'diagonal';
        if ($dir === 'radial') {
            $defs .= '<radialGradient id="qrg" gradientUnits="userSpaceOnUse" '
                  . 'cx="' . ($qrPx/2) . '" cy="' . ($qrPx/2) . '" r="' . ($qrPx*0.7) . '">'
                  . '<stop offset="0%" stop-color="' . $fg  . '"/>'
                  . '<stop offset="100%" stop-color="' . $fg2 . '"/></radialGradient>';
        } else {
            $coordMap = [
                'horizontal' => [0,         0,        $qrPx, 0],
                'vertical'   => [0,         0,        0,     $qrPx],
                'diagonal'   => [0,         0,        $qrPx, $qrPx],
            ];
            [$x1,$y1,$x2,$y2] = $coordMap[$dir] ?? $coordMap['diagonal'];
            $defs .= '<linearGradient id="qrg" gradientUnits="userSpaceOnUse" '
                  . 'x1="' . $x1 . '" y1="' . $y1 . '" x2="' . $x2 . '" y2="' . $y2 . '">'
                  . '<stop offset="0%" stop-color="' . $fg  . '"/>'
                  . '<stop offset="100%" stop-color="' . $fg2 . '"/></linearGradient>';
        }
    }

    // Eye finder regions in matrix coords
    $eyes = [
        [0, 0],
        [$size - 7, 0],
        [0, $size - 7],
    ];
    $isInEye = function (int $x, int $y) use ($eyes): bool {
        foreach ($eyes as [$ex, $ey]) {
            if ($x >= $ex && $x < $ex + 7 && $y >= $ey && $y < $ey + 7) return true;
        }
        return false;
    };

    // Reserve circular logo space (skip data modules under it) so logo doesn't overlap dots
    $logoCells = 0;
    if ($logoFile) {
        $logoCells = (int)round($size * 0.22);
        if ($logoCells % 2 === 0) $logoCells++; // odd, centered
    }
    $logoCx = $size / 2;
    $logoCy = $size / 2;
    $logoR  = $logoCells / 2 + 0.5;

    $modules = '';
    for ($y = 0; $y < $size; $y++) {
        for ($x = 0; $x < $size; $x++) {
            if (!$matrix->check($x, $y)) continue;
            if ($isInEye($x, $y))         continue;
            if ($logoCells > 0) {
                $dx = $x + 0.5 - $logoCx;
                $dy = $y + 0.5 - $logoCy;
                if (($dx*$dx + $dy*$dy) <= ($logoR * $logoR)) continue;
            }
            $px_x = $x * $scale;
            $px_y = $y * $scale;
            $cx   = $px_x + $scale / 2;
            $cy   = $px_y + $scale / 2;
            $modules .= module_shape_svg($d['module_shape'], $px_x, $px_y, $scale, $cx, $cy);
        }
    }

    // Eyes
    $eyeSvg = '';
    foreach ($eyes as [$ex, $ey]) {
        $eyeSvg .= eye_shape_svg($d['eye_shape'], $d['eye_inner'] ?? 'square',
            $ex * $scale, $ey * $scale, $scale, $eyeCo, $bg);
    }

    // Logo
    $logoSvg = '';
    if ($logoFile && is_readable($logoFile)) {
        $bytes = file_get_contents($logoFile);
        $info  = getimagesizefromstring($bytes);
        $mime  = $info['mime'] ?? 'image/png';
        $b64   = base64_encode($bytes);
        // logo box sized to match the cleared circle
        $lw    = (int)($logoCells * $scale * 0.85);
        $lh    = $lw;
        $cxAbs = ($logoCx) * $scale;
        $cyAbs = ($logoCy) * $scale;
        $lx    = (int)($cxAbs - $lw / 2);
        $ly    = (int)($cyAbs - $lh / 2);
        $pad   = (int)($scale * 0.4);
        $logoSvg = '<rect x="' . ($lx - $pad) . '" y="' . ($ly - $pad) . '" width="' . ($lw + 2*$pad) . '" height="' . ($lh + 2*$pad) . '" rx="' . ($scale*0.6) . '" fill="' . $bg . '"/>'
                 . '<image x="' . $lx . '" y="' . $ly . '" width="' . $lw . '" height="' . $lh . '" href="data:' . $mime . ';base64,' . $b64 . '" preserveAspectRatio="xMidYMid meet"/>';
    }

    return '<?xml version="1.0" encoding="UTF-8"?>'
        . '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" '
        . 'viewBox="0 0 ' . $px . ' ' . $px . '" width="' . $px . '" height="' . $px . '" shape-rendering="geometricPrecision">'
        . '<defs>' . $defs . '</defs>'
        . '<rect width="100%" height="100%" fill="' . $bg . '"/>'
        . '<g transform="translate(' . $offset . ',' . $offset . ')">'
        .   '<g fill="' . $fillData . '">' . $modules . '</g>'
        .   $eyeSvg
        .   $logoSvg
        . '</g>'
        . '</svg>';
}

function module_shape_svg(string $shape, float $x, float $y, float $s, float $cx, float $cy): string {
    switch ($shape) {
        case 'dot':
            return '<circle cx="' . $cx . '" cy="' . $cy . '" r="' . ($s * 0.46) . '"/>';
        case 'rounded':
            return '<rect x="' . $x . '" y="' . $y . '" width="' . $s . '" height="' . $s . '" rx="' . ($s * 0.4) . '"/>';
        case 'classy':
            // Top-left + bottom-right rounded; other corners square
            $r = $s * 0.45;
            return '<path d="M' . ($x + $r) . ' ' . $y
                 . ' L' . ($x + $s) . ' ' . $y
                 . ' L' . ($x + $s) . ' ' . ($y + $s - $r)
                 . ' A' . $r . ' ' . $r . ' 0 0 1 ' . ($x + $s - $r) . ' ' . ($y + $s)
                 . ' L' . $x . ' ' . ($y + $s)
                 . ' L' . $x . ' ' . ($y + $r)
                 . ' A' . $r . ' ' . $r . ' 0 0 1 ' . ($x + $r) . ' ' . $y . ' Z"/>';
        case 'diamond':
            return '<path d="M' . $cx . ' ' . ($y + 0.5)
                 . ' L' . ($x + $s - 0.5) . ' ' . $cy
                 . ' L' . $cx . ' ' . ($y + $s - 0.5)
                 . ' L' . ($x + 0.5) . ' ' . $cy . ' z"/>';
        case 'star':
            $r1 = $s * 0.5; $r2 = $s * 0.22;
            $pts = '';
            for ($i = 0; $i < 10; $i++) {
                $r = $i % 2 === 0 ? $r1 : $r2;
                $a = -M_PI / 2 + $i * M_PI / 5;
                $px = $cx + $r * cos($a);
                $py = $cy + $r * sin($a);
                $pts .= ($i ? ' ' : '') . round($px, 2) . ',' . round($py, 2);
            }
            return '<polygon points="' . $pts . '"/>';
        case 'cross':
            $t = $s * 0.36;
            return '<path d="M' . ($cx - $t/2) . ' ' . ($y + 0.5)
                 . ' h' . $t . ' v' . (($s - $t)/2)
                 . ' h' . (($s - $t)/2) . ' v' . $t
                 . ' h-' . (($s - $t)/2) . ' v' . (($s - $t)/2)
                 . ' h-' . $t . ' v-' . (($s - $t)/2)
                 . ' h-' . (($s - $t)/2) . ' v-' . $t
                 . ' h' . (($s - $t)/2) . ' z"/>';
        case 'square':
        default:
            return '<rect x="' . $x . '" y="' . $y . '" width="' . $s . '" height="' . $s . '"/>';
    }
}

// Build outer eye ring path (7×7 outer minus 5×5 inner cutout)
function eye_outer_path(string $shape, float $x, float $y, float $s, string $color): string {
    $outer = 7 * $s;
    $cx = $x + $outer / 2;
    $cy = $y + $outer / 2;
    $or = $outer / 2;
    $ir = (5 * $s) / 2; // inner radius for cutout
    $ix = $x + $s;
    $iy = $y + $s;
    $iw = 5 * $s;

    if ($shape === 'circle') {
        // Outer circle with circular hole (even-odd)
        return '<path fill-rule="evenodd" fill="' . $color . '" d="'
             . 'M' . ($cx - $or) . ' ' . $cy
             . ' a' . $or . ' ' . $or . ' 0 1 0 ' . (2*$or) . ' 0'
             . ' a' . $or . ' ' . $or . ' 0 1 0 -' . (2*$or) . ' 0 Z'
             . ' M' . ($cx - $ir) . ' ' . $cy
             . ' a' . $ir . ' ' . $ir . ' 0 1 1 ' . (2*$ir) . ' 0'
             . ' a' . $ir . ' ' . $ir . ' 0 1 1 -' . (2*$ir) . ' 0 Z"/>';
    }
    if ($shape === 'rounded') {
        $r1 = $s * 1.6;
        $r2 = $s * 1.0;
        return '<path fill-rule="evenodd" fill="' . $color . '" d="'
             . rounded_rect_path($x, $y, $outer, $outer, $r1)
             . rounded_rect_path($ix, $iy, $iw, $iw, $r2)
             . '"/>';
    }
    if ($shape === 'leaf') {
        // top-left + bottom-right rounded, other two square
        return '<path fill-rule="evenodd" fill="' . $color . '" d="'
             . leaf_rect_path($x, $y, $outer, $outer, $s * 1.8)
             . leaf_rect_path($ix, $iy, $iw, $iw, $s * 1.0)
             . '"/>';
    }
    if ($shape === 'frame') {
        // square outer, square inner — same as square ring (no inner cutout overlap)
        return '<path fill-rule="evenodd" fill="' . $color . '" d="'
             . 'M' . $x . ' ' . $y . ' h' . $outer . ' v' . $outer . ' h-' . $outer . ' Z '
             . 'M' . $ix . ' ' . $iy . ' v' . $iw . ' h' . $iw . ' v-' . $iw . ' Z"/>';
    }
    if ($shape === 'flower') {
        // Outer rounded with notches — soft cloud-like via rounded-rect frame
        $r1 = $s * 2.4;
        $r2 = $s * 1.6;
        return '<path fill-rule="evenodd" fill="' . $color . '" d="'
             . rounded_rect_path($x, $y, $outer, $outer, $r1)
             . rounded_rect_path($ix, $iy, $iw, $iw, $r2)
             . '"/>';
    }
    // square
    return '<path fill-rule="evenodd" fill="' . $color . '" d="'
         . 'M' . $x . ' ' . $y . ' h' . $outer . ' v' . $outer . ' h-' . $outer . ' Z '
         . 'M' . $ix . ' ' . $iy . ' v' . $iw . ' h' . $iw . ' v-' . $iw . ' Z"/>';
}

function eye_inner_shape(string $shape, float $x, float $y, float $s, string $color): string {
    $iw = 3 * $s;
    $ix = $x + 2 * $s;
    $iy = $y + 2 * $s;
    $cx = $ix + $iw / 2;
    $cy = $iy + $iw / 2;

    if ($shape === 'circle') {
        return '<circle cx="' . $cx . '" cy="' . $cy . '" r="' . ($iw/2) . '" fill="' . $color . '"/>';
    }
    if ($shape === 'dot') {
        return '<circle cx="' . $cx . '" cy="' . $cy . '" r="' . ($iw*0.42) . '" fill="' . $color . '"/>';
    }
    if ($shape === 'rounded') {
        return '<rect x="' . $ix . '" y="' . $iy . '" width="' . $iw . '" height="' . $iw . '" rx="' . ($s*0.7) . '" fill="' . $color . '"/>';
    }
    if ($shape === 'diamond') {
        return '<path fill="' . $color . '" d="M' . $cx . ' ' . $iy
             . ' L' . ($ix + $iw) . ' ' . $cy
             . ' L' . $cx . ' ' . ($iy + $iw)
             . ' L' . $ix . ' ' . $cy . ' Z"/>';
    }
    return '<rect x="' . $ix . '" y="' . $iy . '" width="' . $iw . '" height="' . $iw . '" fill="' . $color . '"/>';
}

function eye_shape_svg(string $outerShape, string $innerShape, float $x, float $y, float $s, string $color, string $bg): string {
    // Outer ring is one path with even-odd fill creating the cutout. Background shows through cutout naturally — no need to draw bg fill.
    return eye_outer_path($outerShape, $x, $y, $s, $color)
         . eye_inner_shape($innerShape, $x, $y, $s, $color);
}

function rounded_rect_path(float $x, float $y, float $w, float $h, float $r): string {
    $r = min($r, $w / 2, $h / 2);
    return 'M' . ($x + $r) . ' ' . $y
         . ' h' . ($w - 2*$r)
         . ' a' . $r . ' ' . $r . ' 0 0 1 ' . $r . ' ' . $r
         . ' v' . ($h - 2*$r)
         . ' a' . $r . ' ' . $r . ' 0 0 1 -' . $r . ' ' . $r
         . ' h-' . ($w - 2*$r)
         . ' a' . $r . ' ' . $r . ' 0 0 1 -' . $r . ' -' . $r
         . ' v-' . ($h - 2*$r)
         . ' a' . $r . ' ' . $r . ' 0 0 1 ' . $r . ' -' . $r
         . ' Z ';
}

function leaf_rect_path(float $x, float $y, float $w, float $h, float $r): string {
    // top-left + bottom-right rounded; top-right + bottom-left square
    $r = min($r, $w / 2, $h / 2);
    return 'M' . ($x + $r) . ' ' . $y
         . ' L' . ($x + $w) . ' ' . $y
         . ' L' . ($x + $w) . ' ' . ($y + $h - $r)
         . ' A' . $r . ' ' . $r . ' 0 0 1 ' . ($x + $w - $r) . ' ' . ($y + $h)
         . ' L' . $x . ' ' . ($y + $h)
         . ' L' . $x . ' ' . ($y + $r)
         . ' A' . $r . ' ' . $r . ' 0 0 1 ' . ($x + $r) . ' ' . $y
         . ' Z ';
}

function svg_to_raster(string $svg, string $format, int $px): ?string {
    if (!class_exists('Imagick')) return null;
    try {
        $im = new Imagick();
        $im->setBackgroundColor(new ImagickPixel('transparent'));
        $im->setResolution(150, 150);
        $im->readImageBlob($svg);
        $im->setImageFormat($format === 'jpg' ? 'jpeg' : 'png');
        if ($format === 'jpg') {
            $im->setImageBackgroundColor(new ImagickPixel('white'));
            $im = $im->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
        }
        $im->resizeImage($px, $px, Imagick::FILTER_LANCZOS, 1);
        $blob = $im->getImageBlob();
        $im->clear();
        return $blob;
    } catch (Throwable $e) {
        error_log('SVG raster error: ' . $e->getMessage());
        return null;
    }
}
