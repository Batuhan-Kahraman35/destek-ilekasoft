<?php
/**
 * VAPID anahtar çifti üretici (YALNIZCA CLI)
 *
 * Web push için gereken EC P-256 anahtar çiftini üretir ve anahtarları
 * Entegrasyon / EntegrasyonKanallari tablolarına yazacak SQL'i ekrana basar.
 * Özel anahtar hiçbir zaman HTTP üzerinden servis edilmez.
 *
 * Kullanım:
 *   php admin/cron/pwa-vapid-uret.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Bu betik yalnizca komut satirindan calistirilabilir.');
}

require_once __DIR__ . '/../db.php';

/* --- Anahtar çifti ----------------------------------------------------- */
$config = ['config' => __DIR__ . '/../includes/openssl.cnf'];

$anahtar = openssl_pkey_new(array_merge([
    'private_key_type' => OPENSSL_KEYTYPE_EC,
    'curve_name'       => 'prime256v1',
], $config));

if (!$anahtar) {
    fwrite(STDERR, "HATA: EC anahtari uretilemedi.\n");
    while ($m = openssl_error_string()) {
        fwrite(STDERR, "  openssl: $m\n");
    }
    fwrite(STDERR, "  admin/includes/openssl.cnf dosyasini kontrol edin.\n");
    exit(1);
}

$detay = openssl_pkey_get_details($anahtar);

// VAPID public key: ham 65 baytlik nokta (0x04 || X || Y), base64url
$hamNokta = "\x04"
          . str_pad($detay['ec']['x'], 32, "\x00", STR_PAD_LEFT)
          . str_pad($detay['ec']['y'], 32, "\x00", STR_PAD_LEFT);
$publicKey = rtrim(strtr(base64_encode($hamNokta), '+/', '-_'), '=');

$privatePem = '';
openssl_pkey_export($anahtar, $privatePem, null, $config);

if ($privatePem === '') {
    fwrite(STDERR, "HATA: Ozel anahtar PEM olarak disa aktarilamadi.\n");
    exit(1);
}

/* --- Doğrulama: üretilen çiftle imza atılabiliyor mu? ------------------ */
$test = '';
$ok = openssl_sign('vapid-dogrulama', $test, openssl_pkey_get_private($privatePem), OPENSSL_ALGO_SHA256);
if (!$ok) {
    fwrite(STDERR, "HATA: Uretilen ozel anahtarla imza atilamadi.\n");
    exit(1);
}

/* --- E-posta (subject) ------------------------------------------------- */
$db = Database::getInstance();
$ayar = $db->fetchOne("
    SELECT TOP 1 site_ayarlari_Admin_email
    FROM dbo.tanim_site_ayarlari
    ORDER BY site_ayarlari_id DESC
");
$eposta  = trim((string)($ayar['site_ayarlari_Admin_email'] ?? ''));
$subject = 'mailto:' . ($eposta !== '' ? $eposta : 'destek@ornekproje.com');

/* --- Kanal parametreleri (JSON) ---------------------------------------- */
$parametreler = json_encode([
    'public'  => $publicKey,
    'private' => $privatePem,
    'subject' => $subject,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

// SQL string literali icin tek tirnaklar ikilenir
$parametrelerSql = str_replace("'", "''", $parametreler);

$dbAdi = (require __DIR__ . '/../../config/database.php')['connections']['sqlsrv']['database'];

/* --- Çıktı --------------------------------------------------------------
   STDOUT yalnizca calistirilabilir SQL icerir (dosyaya yonlendirilebilsin).
   Bilgi mesajlari STDERR'e yazilir.
   ---------------------------------------------------------------------- */
fwrite(STDERR, "\n");
fwrite(STDERR, "=========================================================\n");
fwrite(STDERR, " VAPID anahtar cifti uretildi\n");
fwrite(STDERR, "=========================================================\n");
fwrite(STDERR, " Public key : $publicKey\n");
fwrite(STDERR, " Subject    : $subject\n");
fwrite(STDERR, " Ozel anahtar STDOUT'taki SQL icinde yer aliyor.\n");
fwrite(STDERR, "=========================================================\n\n");

echo "-- Veritabani: $dbAdi\n";
echo "USE [$dbAdi];\n";
echo "GO\n\n";
echo <<<SQL
-- webpush entegrasyonu (yoksa olustur)
IF NOT EXISTS (SELECT 1 FROM dbo.Entegrasyon WHERE Entegrasyon_Kod = 'webpush')
BEGIN
    INSERT INTO dbo.Entegrasyon (Entegrasyon_Kod, Entegrasyon_Adi, Entegrasyon_Aciklama,
                                 Entegrasyon_Ikon, Entegrasyon_Renk, Entegrasyon_Sira, Entegrasyon_Durum)
    VALUES ('webpush', N'Web Push Bildirim', N'Tarayici ve masaustu push bildirimleri (VAPID)',
            'bi-bell', 'primary', 90, 1);
END
GO

-- VAPID kanali (varsa guncelle, yoksa ekle)
DECLARE @EntId INT = (SELECT TOP 1 Entegrasyon_Id FROM dbo.Entegrasyon WHERE Entegrasyon_Kod = 'webpush');
DECLARE @Param NVARCHAR(MAX) = N'$parametrelerSql';

IF EXISTS (SELECT 1 FROM dbo.EntegrasyonKanallari WHERE Kanal_Entegrasyon_Id = @EntId AND Kanal_Adi = N'VAPID')
BEGIN
    UPDATE dbo.EntegrasyonKanallari
    SET Kanal_Parametreler = @Param, Kanal_Durum = 1, Kanal_Varsayilan = 1
    WHERE Kanal_Entegrasyon_Id = @EntId AND Kanal_Adi = N'VAPID';
END
ELSE
BEGIN
    INSERT INTO dbo.EntegrasyonKanallari (Kanal_Entegrasyon_Id, Kanal_Adi, Kanal_Ortam,
                                          Kanal_Parametreler, Kanal_Varsayilan, Kanal_Durum)
    VALUES (@EntId, N'VAPID', 'production', @Param, 1, 1);
END
GO

SQL;
echo "\n";
echo "-- Dogrulama:\n";
echo "-- SELECT e.Entegrasyon_Kod, k.Kanal_Adi, k.Kanal_Durum, LEN(k.Kanal_Parametreler) AS param_uzunluk\n";
echo "-- FROM dbo.EntegrasyonKanallari k\n";
echo "-- INNER JOIN dbo.Entegrasyon e ON k.Kanal_Entegrasyon_Id = e.Entegrasyon_Id\n";
echo "-- WHERE e.Entegrasyon_Kod = 'webpush';\n\n";
