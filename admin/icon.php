<?php
/**
 * PWA Ikon Uretici
 * Logoyu kare tuval uzerine ortalayip istenen boyutta PNG dondurur.
 * Kullanim: /admin/icon.php?size=192  |  /admin/icon.php?size=512&maskable=1
 *
 * Logo kaynagi sirasiyla denenir:
 *   1) tanim_site_ayarlari.site_ayarlari_logo_url (uzak URL veya yerel dosya)
 *   2) admin/assets/images/logo.png  (yedek)
 *   3) Renkli kare + site adinin bas harfi (son care)
 */

require_once __DIR__ . '/db.php';

$size = (int)($_GET['size'] ?? 192);
if ($size < 48)   $size = 48;
if ($size > 1024) $size = 1024;
$maskable = !empty($_GET['maskable']);

$db = Database::getInstance();
$ayar = $db->fetchOne("
    SELECT TOP 1 site_ayarlari_logo_url, site_ayarlari_site_title
    FROM dbo.tanim_site_ayarlari
    ORDER BY site_ayarlari_id DESC
");
$logoUrl   = trim((string)($ayar['site_ayarlari_logo_url'] ?? ''));
$siteTitle = trim((string)($ayar['site_ayarlari_site_title'] ?? '')) !== ''
    ? $ayar['site_ayarlari_site_title'] : 'Destek';

$kok = rtrim(str_replace(DIRECTORY_SEPARATOR, '/', dirname(__DIR__)), '/');

/* --- Kaynak cozumleme -------------------------------------------------- */
$logoPath   = null;
$logoRemote = null;

if ($logoUrl !== '') {
    if (stripos($logoUrl, 'http') === 0) {
        $logoRemote = $logoUrl;
    } else {
        // URL'yi olasi yerel yollara cevir; ilk mevcut olan kullanilir
        $rel = '/' . ltrim($logoUrl, '/');
        $adaylar = [
            $kok . $rel,                                    // /admin/assets/... -> kok/admin/assets/...
            $kok . '/admin/' . ltrim($logoUrl, '/'),         // assets/... -> kok/admin/assets/...
            $kok . '/admin/assets/' . ltrim($logoUrl, '/'),
        ];
        foreach ($adaylar as $aday) {
            if (is_file($aday)) { $logoPath = $aday; break; }
        }
    }
}

// Yedek: paketle gelen logo
if ($logoPath === null && $logoRemote === null) {
    $yedek = __DIR__ . '/assets/images/logo.png';
    if (is_file($yedek)) $logoPath = $yedek;
}

/* --- Disk onbellegi ---------------------------------------------------- */
$cacheDir = __DIR__ . '/assets/images/pwa-cache';
if (!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);

$srcStamp  = ($logoPath && is_file($logoPath)) ? (string)filemtime($logoPath) : md5((string)$logoUrl);
$cacheFile = $cacheDir . '/icon_' . $size . ($maskable ? '_m' : '') . '_'
           . substr(md5(($logoPath ?? $logoRemote ?? 'fallback') . $srcStamp), 0, 8) . '.png';

header('Content-Type: image/png');
header('Cache-Control: public, max-age=86400');

if (is_file($cacheFile)) {
    readfile($cacheFile);
    exit;
}

/* --- Kaynak goruntuyu yukle -------------------------------------------- */
$imgData = null;
if ($logoRemote) {
    $ch = curl_init($logoRemote);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_SSL_VERIFYPEER => false]);
    $imgData = curl_exec($ch);
    curl_close($ch);
} elseif ($logoPath && is_file($logoPath)) {
    $imgData = file_get_contents($logoPath);
}
$src = $imgData ? @imagecreatefromstring($imgData) : null;

/* --- Kare tuval -------------------------------------------------------- */
$canvas = imagecreatetruecolor($size, $size);
imagesavealpha($canvas, true);
imagealphablending($canvas, false);
imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 255, 255, 255, 127));
imagealphablending($canvas, true);

// Maskable ikonda kenarlardan %10 guvenli bosluk birakilir
$pad  = $maskable ? (int)round($size * 0.10) : 0;
$area = $size - ($pad * 2);

if ($src) {
    $sw = imagesx($src);
    $sh = imagesy($src);
    $ratio = min($area / $sw, $area / $sh);
    $dw = (int)round($sw * $ratio);
    $dh = (int)round($sh * $ratio);
    $dx = (int)round(($size - $dw) / 2);
    $dy = (int)round(($size - $dh) / 2);
    imagealphablending($canvas, false);
    imagecopyresampled($canvas, $src, $dx, $dy, 0, 0, $dw, $dh, $sw, $sh);
    imagedestroy($src);
} else {
    // Son care: markali kare + bas harf
    imagealphablending($canvas, true);
    imagefilledrectangle($canvas, 0, 0, $size, $size, imagecolorallocate($canvas, 13, 110, 253));
    $harf  = mb_strtoupper(mb_substr($siteTitle, 0, 1), 'UTF-8');
    $white = imagecolorallocate($canvas, 255, 255, 255);
    $font  = __DIR__ . '/assets/fonts/NotoSans-Regular.ttf';
    if (is_file($font)) {
        $fontSize = $size * 0.45;
        $box = imagettfbbox($fontSize, 0, $font, $harf);
        $tx = ($size - ($box[2] - $box[0])) / 2 - $box[0];
        $ty = ($size - ($box[7] - $box[1])) / 2 - $box[1];
        imagettftext($canvas, $fontSize, 0, (int)$tx, (int)$ty, $white, $font, $harf);
    } else {
        imagestring($canvas, 5, (int)($size / 2 - 5), (int)($size / 2 - 8), $harf, $white);
    }
}

imagesavealpha($canvas, true);
@imagepng($canvas, $cacheFile);
imagepng($canvas);
imagedestroy($canvas);
