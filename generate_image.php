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
$format = in_array($_GET['format'] ?? '', ['png', 'jpg', 'svg'], true) ? $_GET['format'] : 'png';

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
    if ($k === 'module_shape') return in_array($v, ['square','dot','rounded','classy','diamond'], true) ? $v : $default;
    if ($k === 'eye_shape')    return in_array($v, ['square','rounded','circle','leaf'], true) ? $v : $default;
    return $default;
};

$design = [
    'fg'           => $ovr('fg', '#000000'),
    'bg'           => $ovr('bg', '#ffffff'),
    'gradient'     => (bool)$ovr('gradient', false),
    'fg2'          => $ovr('fg2', '#1e90ff'),
    'module_shape' => $ovr('module_shape', 'square'),
    'eye_shape'    => $ovr('eye_shape', 'square'),
    'eye_color'    => $ovr('eye_color', $ovr('fg', '#000000')),
];

// --- BUILD MATRIX ---
$options = new QROptions([
    'version'         => 7,
    'eccLevel'        => QRCode::ECC_H,
    'addQuietzone'    => true,
    'quietzoneSize'   => 4,
]);
$qrcode = new QRCode($options);
$qrcode->addByteSegment($qrContent);
$matrix = $qrcode->getQRMatrix();
$size   = $matrix->getSize();

// --- LOGO ---
$logoFile = ($item['logo_path'] && file_exists(LOGO_DIR . '/' . $item['logo_path']))
    ? LOGO_DIR . '/' . $item['logo_path']
    : null;

// --- RENDER SVG ---
$scale  = 14;            // px per module
$pxSize = $size * $scale;
$svg    = render_designer_svg($matrix, $design, $logoFile, $scale);

if ($format === 'svg') {
    header('Content-Type: image/svg+xml');
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
    header('Content-Type: image/' . $format);
    echo $img;
    exit;
}

header('Content-Type: image/' . ($format === 'jpg' ? 'jpeg' : 'png'));
echo $rasterized;
exit;

// =========================================================================
// RENDERERS
// =========================================================================

function render_designer_svg(QRMatrix $matrix, array $d, ?string $logoFile, int $scale): string {
    $size  = $matrix->getSize();
    $px    = $size * $scale;
    $bg    = htmlspecialchars($d['bg'], ENT_QUOTES);
    $fg    = htmlspecialchars($d['fg'], ENT_QUOTES);
    $fg2   = htmlspecialchars($d['fg2'], ENT_QUOTES);
    $eyeCo = htmlspecialchars($d['eye_color'], ENT_QUOTES);
    $useGr = !empty($d['gradient']);
    $fillData = $useGr ? "url(#qrg)" : $fg;

    $defs = '';
    if ($useGr) {
        $defs .= '<linearGradient id="qrg" x1="0%" y1="0%" x2="100%" y2="100%">'
              . '<stop offset="0%" stop-color="' . $fg  . '"/>'
              . '<stop offset="100%" stop-color="' . $fg2 . '"/>'
              . '</linearGradient>';
    }

    // Identify finder eye centers (top-left, top-right, bottom-left)
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

    $modules = '';
    for ($y = 0; $y < $size; $y++) {
        for ($x = 0; $x < $size; $x++) {
            if (!$matrix->check($x, $y)) continue;
            if ($isInEye($x, $y))         continue; // drawn separately
            $cx = $x * $scale + $scale / 2;
            $cy = $y * $scale + $scale / 2;
            $modules .= module_shape_svg($d['module_shape'], $x * $scale, $y * $scale, $scale, $cx, $cy);
        }
    }

    // Eyes
    $eyeSvg = '';
    foreach ($eyes as [$ex, $ey]) {
        $eyeSvg .= eye_shape_svg($d['eye_shape'], $ex * $scale, $ey * $scale, $scale, $eyeCo, $bg);
    }

    // Logo
    $logoSvg = '';
    if ($logoFile && is_readable($logoFile)) {
        $bytes = file_get_contents($logoFile);
        $info  = getimagesizefromstring($bytes);
        $mime  = $info['mime'] ?? 'image/png';
        $b64   = base64_encode($bytes);
        $lw    = (int)($px / 4);
        $lh    = $lw;
        $lx    = (int)(($px - $lw) / 2);
        $ly    = (int)(($px - $lh) / 2);
        $pad   = (int)($scale * 0.6);
        $logoSvg = '<rect x="' . ($lx - $pad) . '" y="' . ($ly - $pad) . '" width="' . ($lw + 2*$pad) . '" height="' . ($lh + 2*$pad) . '" rx="6" fill="' . $bg . '"/>'
                 . '<image x="' . $lx . '" y="' . $ly . '" width="' . $lw . '" height="' . $lh . '" href="data:' . $mime . ';base64,' . $b64 . '" preserveAspectRatio="xMidYMid meet"/>';
    }

    return '<?xml version="1.0" encoding="UTF-8"?>'
        . '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" '
        . 'viewBox="0 0 ' . $px . ' ' . $px . '" width="' . $px . '" height="' . $px . '" shape-rendering="geometricPrecision">'
        . '<defs>' . $defs . '</defs>'
        . '<rect width="100%" height="100%" fill="' . $bg . '"/>'
        . '<g fill="' . $fillData . '">' . $modules . '</g>'
        . $eyeSvg
        . $logoSvg
        . '</svg>';
}

function module_shape_svg(string $shape, float $x, float $y, float $s, float $cx, float $cy): string {
    switch ($shape) {
        case 'dot':
            return '<circle cx="' . $cx . '" cy="' . $cy . '" r="' . ($s * 0.45) . '"/>';
        case 'rounded':
            return '<rect x="' . $x . '" y="' . $y . '" width="' . $s . '" height="' . $s . '" rx="' . ($s * 0.35) . '"/>';
        case 'classy':
            $r = $s * 0.4;
            return '<path d="M' . ($x + $r) . ' ' . $y
                 . ' h' . ($s - $r) . ' v' . ($s - $r)
                 . ' a' . $r . ' ' . $r . ' 0 0 1 -' . $r . ' ' . $r
                 . ' h-' . ($s - $r) . ' v-' . ($s - $r)
                 . ' a' . $r . ' ' . $r . ' 0 0 1 ' . $r . ' -' . $r . ' z"/>';
        case 'diamond':
            return '<path d="M' . $cx . ' ' . $y
                 . ' L' . ($x + $s) . ' ' . $cy
                 . ' L' . $cx . ' ' . ($y + $s)
                 . ' L' . $x . ' ' . $cy . ' z"/>';
        case 'square':
        default:
            return '<rect x="' . $x . '" y="' . $y . '" width="' . $s . '" height="' . $s . '"/>';
    }
}

function eye_shape_svg(string $shape, float $x, float $y, float $s, string $color, string $bg): string {
    $outer = 7 * $s;
    $mid   = 5 * $s;
    $inner = 3 * $s;
    $cx    = $x + $outer / 2;
    $cy    = $y + $outer / 2;

    if ($shape === 'circle') {
        return '<circle cx="' . $cx . '" cy="' . $cy . '" r="' . ($outer/2) . '" fill="' . $color . '"/>'
             . '<circle cx="' . $cx . '" cy="' . $cy . '" r="' . ($mid/2)   . '" fill="' . $bg    . '"/>'
             . '<circle cx="' . $cx . '" cy="' . $cy . '" r="' . ($inner/2) . '" fill="' . $color . '"/>';
    }
    if ($shape === 'rounded') {
        return '<rect x="' . $x . '" y="' . $y . '" width="' . $outer . '" height="' . $outer . '" rx="' . ($s*1.5) . '" fill="' . $color . '"/>'
             . '<rect x="' . ($x+$s) . '" y="' . ($y+$s) . '" width="' . $mid . '" height="' . $mid . '" rx="' . ($s*1.0) . '" fill="' . $bg . '"/>'
             . '<rect x="' . ($x+2*$s) . '" y="' . ($y+2*$s) . '" width="' . $inner . '" height="' . $inner . '" rx="' . ($s*0.5) . '" fill="' . $color . '"/>';
    }
    if ($shape === 'leaf') {
        // Asymmetric rounding: top-left + bottom-right corners
        $rOuter = $s * 2.2;
        $rInner = $s * 1.2;
        return '<rect x="' . $x . '" y="' . $y . '" width="' . $outer . '" height="' . $outer . '" rx="' . $rOuter . '" ry="' . ($s*0.6) . '" fill="' . $color . '"/>'
             . '<rect x="' . ($x+$s) . '" y="' . ($y+$s) . '" width="' . $mid . '" height="' . $mid . '" rx="' . $rInner . '" ry="' . ($s*0.4) . '" fill="' . $bg . '"/>'
             . '<rect x="' . ($x+2*$s) . '" y="' . ($y+2*$s) . '" width="' . $inner . '" height="' . $inner . '" rx="' . ($s*0.7) . '" fill="' . $color . '"/>';
    }
    // square
    return '<rect x="' . $x . '" y="' . $y . '" width="' . $outer . '" height="' . $outer . '" fill="' . $color . '"/>'
         . '<rect x="' . ($x+$s) . '" y="' . ($y+$s) . '" width="' . $mid . '" height="' . $mid . '" fill="' . $bg . '"/>'
         . '<rect x="' . ($x+2*$s) . '" y="' . ($y+2*$s) . '" width="' . $inner . '" height="' . $inner . '" fill="' . $color . '"/>';
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
