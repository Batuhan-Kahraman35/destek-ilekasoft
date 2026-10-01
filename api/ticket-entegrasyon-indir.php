<?php
/**
 * Ticket Entegrasyon Paketi İndirme
 * Kullanım: ?kaynak_id=1
 */

require_once __DIR__ . '/../admin/auth.php';
requireAuth();

$user = Auth::user();
if ($user['departman_id'] != 1) {
    http_response_code(403);
    die('Yetki hatası.');
}

$db = Database::getInstance();
$kaynakId = (int)($_GET['kaynak_id'] ?? 0);

if ($kaynakId <= 0) {
    die('Geçersiz kaynak_id.');
}

$kaynak = $db->fetchOne("SELECT * FROM Ticket_Kaynak WHERE Ticket_Kaynak_id = ? AND Durum = 1", [$kaynakId]);
if (!$kaynak) {
    die('Kaynak bulunamadı.');
}

$apiKey = $kaynak['Ticket_Kaynak_api_key'] ?? '';
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$apiEndpoint = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'destek.ornekproje.com') . '/api/tickets.php';
$kaynakAd = $kaynak['Ticket_Kaynak_ad'] ?? 'Kaynak';
$sabitKullanici = !empty($kaynak['Ticket_Kaynak_kullanici_id']);

$templateDir = __DIR__ . '/entegrasyon-sablonlari';
$destekTpl    = file_get_contents($templateDir . '/destek.php.tpl');
$detayTpl     = file_get_contents($templateDir . '/destek-detay.php.tpl');
$bildirimTpl  = file_get_contents($templateDir . '/destek-bildirim.php.tpl');
$widgetTpl    = file_get_contents($templateDir . '/destek-widget.php.tpl');
$headerTpl    = file_get_contents($templateDir . '/destek-header.php.tpl');

if ($destekTpl === false || $detayTpl === false || $bildirimTpl === false || $widgetTpl === false || $headerTpl === false) {
    die('Şablon dosyaları bulunamadı.');
}

// Session/eposta bloğu
if ($sabitKullanici) {
    $sessionBlock = '// Bu kaynak için sabit kullanıcı tanımlı. Eposta gerekmez.
$eposta  = null;
$kulAd   = null;
$kulSoyad = null;
$kulTelefon = null;';
} else {
    $sessionBlock = '// ⚠️ Aşağıdaki satırları kendi oturum sisteminize göre düzenleyin.
// Kullanıcının bilgileri $_SESSION değişkenlerinden alınır.
// Farklı session key kullanıyorsanız bu satırları güncelleyin.
$eposta     = $_SESSION[\'kullanici_email\'] ?? null;
$kulAd      = $_SESSION[\'kullanici_ad\'] ?? null;
$kulSoyad   = $_SESSION[\'kullanici_soyad\'] ?? null;
$kulTelefon = $_SESSION[\'kullanici_telefon\'] ?? null; // 905xxxxxxxxx formatında
if (!$eposta) {
    header(\'Location: giris.php\');
    exit;
}';
}

$replacements = [
    '{{API_KEY}}'        => $apiKey,
    '{{API_ENDPOINT}}'   => $apiEndpoint,
    '{{KAYNAK_AD}}'      => $kaynakAd,
    '{{SESSION_BLOCK}}'  => $sessionBlock,
];

$destekPhp    = str_replace(array_keys($replacements), array_values($replacements), $destekTpl);
$detayPhp     = str_replace(array_keys($replacements), array_values($replacements), $detayTpl);
$bildirimPhp  = str_replace(array_keys($replacements), array_values($replacements), $bildirimTpl);
$widgetPhp    = str_replace(array_keys($replacements), array_values($replacements), $widgetTpl);
$headerPhp    = str_replace(array_keys($replacements), array_values($replacements), $headerTpl);

// ZIP oluştur
$zipFile = tempnam(sys_get_temp_dir(), 'entegrasyon_');
$zip = new ZipArchive();
if ($zip->open($zipFile, ZipArchive::OVERWRITE) !== true) {
    die('ZIP oluşturulamadı.');
}
$zip->addFromString('destek.php', $destekPhp);
$zip->addFromString('destek-detay.php', $detayPhp);
$zip->addFromString('destek-bildirim.php', $bildirimPhp);
$zip->addFromString('destek-widget.php', $widgetPhp);
$zip->addFromString('destek-header.php', $headerPhp);
$zip->close();

$dosyaAdi = 'ticket-entegrasyon-' . strtolower($kaynak['Ticket_Kaynak_kod'] ?? 'paket') . '.zip';
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $dosyaAdi . '"');
header('Content-Length: ' . filesize($zipFile));
readfile($zipFile);
unlink($zipFile);
exit;
