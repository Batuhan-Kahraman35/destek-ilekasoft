<?php
/**
 * Admin Panel - AI Asistan (ticket analizi + sohbet)
 *
 * Çalıştırıcı (admin/cron/ai-calistirici.ps1) ticket'ı Claude Code ile analiz eder; bu sayfa
 * Ticket_AI_Oturumlari / Ticket_AI_Mesajlar tablolarını okuyup sohbeti gösterir ve kullanıcının
 * yazdığı mesajı kuyruğa ekler. Claude'u bu sayfa çalıştırmaz.
 *
 * Yetki ticket-liste.php üzerinden okunur; ayrıca yalnız Administrator departmanı (ajan tüm
 * proje dosyalarını okuyabildiği için).
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
requireAuth();

$user = Auth::user();
$db   = Database::getInstance();

$currentPageFile = basename($_SERVER['PHP_SELF']);
$pagePermissions = PageAuth::checkPagePermissions(
    $user['kullanici_id'],
    $user['departman_id'],
    'ticket-liste.php'
);
$isAdmin = ($user['departman_id'] == 1);

if (!$pagePermissions['has_access'] || !$isAdmin) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Erişim yetkiniz yok.']);
        exit;
    }
    PageAuth::accessDenied('Bu sayfa yalnız yöneticilere açıktır.');
}

$pageInfo = $db->fetchOne("
    SELECT s.sayfalar_sayfa_adi, m.menuler_menu_adi AS menu_adi
    FROM Menu_Sayfalar s
    LEFT JOIN Menuler m ON s.sayfalar_menu_id = m.menuler_id
    WHERE s.sayfalar_sayfa_url LIKE ? AND s.sayfalar_durum = 1
", ['%' . $currentPageFile]);

$pageTitle = $pageInfo['sayfalar_sayfa_adi'] ?? 'AI Asistan';
$menuAdi   = $pageInfo['menu_adi']           ?? null;

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle    = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';

/**
 * Ticket'ın aktif AI oturumu (durum tanımıyla birlikte)
 */
function aiAktifOturum(Database $db, int $ticketId): ?array
{
    return $db->fetchOne(
        "SELECT o.Ticket_AI_Oturumlari_id AS id, o.Ticket_AI_Oturumlari_proje_dizin AS proje_dizin,
                o.Ticket_AI_Oturumlari_toplam_token AS token, o.Ticket_AI_Oturumlari_toplam_maliyet AS maliyet,
                o.Ticket_AI_Oturumlari_son_hata AS son_hata,
                CONVERT(VARCHAR(16), o.Ticket_AI_Oturumlari_son_islem, 120) AS son_islem,
                d.Tanim_AI_Durumlari_kod AS durum_kod, d.Tanim_AI_Durumlari_ad AS durum_ad,
                d.Tanim_AI_Durumlari_renk AS durum_renk, d.Tanim_AI_Durumlari_ikon AS durum_ikon
         FROM dbo.Ticket_AI_Oturumlari o
         INNER JOIN dbo.Tanim_AI_Durumlari d ON d.Tanim_AI_Durumlari_id = o.Ticket_AI_Oturumlari_durum_id
         WHERE o.Ticket_AI_Oturumlari_ticket_id = ? AND o.Durum = 1",
        [$ticketId]
    ) ?: null;
}

/**
 * ticket-ajan.php'yi CLI olarak çalıştırır (VS Code akışıyla aynı kod: kaynak AI, bildirim, otomatik atama).
 * Başarılıysa data dizisini döner, hata mesajını Exception olarak fırlatır.
 */
function aiTicketAjan(Database $db, array $argumanlar): array
{
    $ayar = $db->fetchOne("SELECT Ticket_AI_Ayarlari_deger AS d FROM dbo.Ticket_AI_Ayarlari WHERE Ticket_AI_Ayarlari_anahtar = 'php_cli_yolu' AND Durum = 1");
    $php  = ($ayar['d'] ?? '') ?: 'C:\\Program Files (x86)\\Plesk\\Additional\\PleskPHP83\\php.exe';

    $surec = proc_open(
        array_merge([$php, __DIR__ . '/../cron/ticket-ajan.php'], $argumanlar),
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        realpath(__DIR__ . '/../..')
    );
    if (!is_resource($surec)) {
        throw new Exception('ticket-ajan başlatılamadı.');
    }
    $cikti = stream_get_contents($pipes[1]);
    $hata  = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($surec);

    $json = json_decode((string)$cikti, true);
    if (is_array($json) && !empty($json['success'])) {
        return $json['data'];
    }
    $hataJson = json_decode((string)$hata, true);
    throw new Exception($hataJson['message'] ?? trim($hata ?: (string)$cikti) ?: 'ticket-ajan hata verdi.');
}

/**
 * Taslak formundan metin dosyalarını (temp/ai-taslak/) ve ticket-ajan argümanlarını hazırlar.
 * İmza: önizlenen içerik gönderimde birebir aynı olmalı (metin değişirse yeniden önizleme gerekir).
 */
function aiTaslakHazirla(int $ticketId, array $post): array
{
    $mesaj  = trim(str_replace("\r\n", "\n", (string)($post['mesaj'] ?? '')));
    $icNot  = trim(str_replace("\r\n", "\n", (string)($post['ic_not'] ?? '')));
    $durum  = trim((string)($post['durum'] ?? ''));
    $atanan = trim((string)($post['atanan'] ?? ''));

    if ($mesaj === '' && $icNot === '') {
        throw new Exception('Müşteri yanıtı veya iç not boş olamaz.');
    }
    if ($durum !== '' && !ctype_digit($durum)) throw new Exception('Geçersiz durum.');
    if ($atanan !== '' && !in_array($atanan, ['ben', 'bos'], true) && !ctype_digit($atanan)) throw new Exception('Geçersiz atama.');

    $dizin = realpath(__DIR__ . '/../..') . '/temp/ai-taslak';
    if (!is_dir($dizin) && !mkdir($dizin, 0755, true) && !is_dir($dizin)) {
        throw new Exception('Taslak klasörü oluşturulamadı.');
    }
    // Bir günden eski taslak dosyalarını temizle
    foreach (glob($dizin . '/*.txt') ?: [] as $eski) {
        if (filemtime($eski) < time() - 86400) @unlink($eski);
    }

    $on  = 't' . $ticketId . '-' . bin2hex(random_bytes(6));
    $arg = ['yanit', (string)$ticketId];
    $dosyalar = [];
    if ($mesaj !== '') {
        file_put_contents($dosyalar[] = "{$dizin}/{$on}-yanit.txt", $mesaj);
        $arg[] = '--mesaj-dosya=' . end($dosyalar);
    }
    if ($icNot !== '') {
        file_put_contents($dosyalar[] = "{$dizin}/{$on}-icnot.txt", $icNot);
        $arg[] = '--icnot-dosya=' . end($dosyalar);
    }
    if ($durum !== '')  $arg[] = '--durum=' . $durum;
    if ($atanan !== '') $arg[] = '--atanan=' . $atanan;

    return [
        'arg'      => $arg,
        'dosyalar' => $dosyalar,
        'mesaj'    => $mesaj,
        'imza'     => hash('sha256', json_encode([$ticketId, $mesaj, $icNot, $durum, $atanan], JSON_UNESCAPED_UNICODE)),
    ];
}

/**
 * Sohbete eklenen görselleri ticket ekleriyle aynı klasöre (assets/uploads/tickets/<ticket>/, ai- önekiyle)
 * kaydeder, mutlak yolları döner.
 * Sınırlar Ticket_AI_Ayarlari'ndan okunur (gorsel_azami_adet, gorsel_azami_mb).
 */
function aiGorselKaydet(Database $db, int $ticketId, array $dosyalar): array
{
    $ayar = function (string $anahtar, int $varsayilan) use ($db): int {
        $s = $db->fetchOne("SELECT Ticket_AI_Ayarlari_deger AS d FROM dbo.Ticket_AI_Ayarlari WHERE Ticket_AI_Ayarlari_anahtar = ? AND Durum = 1", [$anahtar]);
        return (int)($s['d'] ?? 0) ?: $varsayilan;
    };
    $azamiAdet = $ayar('gorsel_azami_adet', 5);
    $azamiBayt = $ayar('gorsel_azami_mb', 5) * 1048576;   // 5 adet × 5 MB < post_max_size (30M)
    $uzantilar = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif', 'image/webp' => 'webp'];

    $adlar = (array)($dosyalar['name'] ?? []);
    if (count($adlar) > $azamiAdet) throw new Exception("En fazla {$azamiAdet} görsel eklenebilir.");

    $kok   = str_replace('\\', '/', realpath(__DIR__ . '/../..'));
    $goreli = 'assets/uploads/tickets/' . $ticketId;   // ticket-detay.php ekleriyle aynı yer
    $dizin = $kok . '/' . $goreli;
    if (!is_dir($dizin) && !mkdir($dizin, 0755, true) && !is_dir($dizin)) {
        throw new Exception('Görsel klasörü oluşturulamadı.');
    }

    $yollar = [];
    try {
    foreach ($adlar as $i => $ad) {
        $hata = (int)($dosyalar['error'][$i] ?? UPLOAD_ERR_NO_FILE);
        if ($hata !== UPLOAD_ERR_OK) throw new Exception("{$ad}: yüklenemedi (hata {$hata}).");
        $tmp = $dosyalar['tmp_name'][$i];
        if ((int)$dosyalar['size'][$i] > $azamiBayt) throw new Exception("{$ad}: boyut sınırı aşıldı (en çok " . ($azamiBayt / 1048576) . ' MB).');
        $mime = (string)(new finfo(FILEINFO_MIME_TYPE))->file($tmp);
        if (!isset($uzantilar[$mime]) || @getimagesize($tmp) === false) throw new Exception("{$ad}: yalnız PNG, JPG, GIF veya WEBP görsel eklenebilir.");

        $hedef = $dizin . '/ai-' . date('Ymd-His') . '-' . bin2hex(random_bytes(8)) . '.' . $uzantilar[$mime];
        if (!move_uploaded_file($tmp, $hedef)) throw new Exception("{$ad}: kaydedilemedi.");
        $yollar[] = $hedef;
    }
    } catch (Throwable $e) {
        foreach ($yollar as $y) @unlink($y);   // yarım kalan yüklemeyi geri al
        throw $e;
    }
    return $yollar;
}

function aiDurumIdGetir(Database $db, string $kod): int
{
    $satir = $db->fetchOne("SELECT Tanim_AI_Durumlari_id AS id FROM dbo.Tanim_AI_Durumlari WHERE Tanim_AI_Durumlari_kod = ?", [$kod]);
    if (!$satir) {
        throw new Exception("AI durum tanımı bulunamadı: {$kod}");
    }
    return (int)$satir['id'];
}

// =====================================================================
// AJAX İşlemleri
// =====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');

    try {
        $action   = $_POST['action'] ?? '';
        $ticketId = (int)($_POST['ticket_id'] ?? 0);

        switch ($action) {
            // -----------------------------------------------------------------
            // InfoBox sayımları (tablo/sohbet yüklendikten sonra ayrı çekilir)
            // -----------------------------------------------------------------
            case 'istatistik':
                $sayimlar = $db->fetchAll(
                    "SELECT d.Tanim_AI_Durumlari_kod AS kod, COUNT(*) AS adet
                     FROM dbo.Ticket_AI_Oturumlari o
                     INNER JOIN dbo.Tanim_AI_Durumlari d ON d.Tanim_AI_Durumlari_id = o.Ticket_AI_Oturumlari_durum_id
                     WHERE o.Durum = 1
                     GROUP BY d.Tanim_AI_Durumlari_kod"
                );
                $bugun = $db->fetchOne(
                    "SELECT COUNT(*) AS adet, ISNULL(SUM(Ticket_AI_Oturumlari_toplam_maliyet), 0) AS maliyet
                     FROM dbo.Ticket_AI_Oturumlari
                     WHERE Ticket_AI_Oturumlari_claude_oturum IS NOT NULL
                       AND OlusturmaTarihi >= CAST(CAST(GETDATE() AS DATE) AS DATETIME)"
                );
                $limit = $db->fetchOne("SELECT Ticket_AI_Ayarlari_deger AS d FROM dbo.Ticket_AI_Ayarlari WHERE Ticket_AI_Ayarlari_anahtar = 'gunluk_limit' AND Durum = 1");

                echo json_encode(['success' => true, 'data' => [
                    'durumlar'      => array_column($sayimlar, 'adet', 'kod'),
                    'bugun_adet'    => (int)($bugun['adet'] ?? 0),
                    'bugun_maliyet' => (float)($bugun['maliyet'] ?? 0),
                    'gunluk_limit'  => (int)($limit['d'] ?? 0),
                ]], JSON_UNESCAPED_UNICODE);
                exit;

            // -----------------------------------------------------------------
            // Sohbet: oturum durumu + son_id'den sonraki mesajlar (polling)
            // -----------------------------------------------------------------
            case 'sohbet':
                if ($ticketId <= 0) throw new Exception('Geçersiz ticket.');
                $sonId  = (int)($_POST['son_id'] ?? 0);
                $oturum = aiAktifOturum($db, $ticketId);

                $mesajlar = [];
                if ($oturum) {
                    $mesajlar = $db->fetchAll(
                        "SELECT m.Ticket_AI_Mesajlar_id AS id, m.Ticket_AI_Mesajlar_rol AS rol,
                                m.Ticket_AI_Mesajlar_icerik AS icerik, m.Ticket_AI_Mesajlar_arac_adi AS arac,
                                d.Tanim_AI_Durumlari_kod AS durum_kod,
                                CONVERT(VARCHAR(19), m.OlusturmaTarihi, 120) AS tarih,
                                k.kullanici_ad + ' ' + k.kullanici_soyad AS yazan
                         FROM dbo.Ticket_AI_Mesajlar m
                         LEFT JOIN dbo.Tanim_AI_Durumlari d ON d.Tanim_AI_Durumlari_id = m.Ticket_AI_Mesajlar_durum_id
                         LEFT JOIN dbo.kullanicilar k ON k.kullanici_id = m.OlusturanKullanici
                         WHERE m.Ticket_AI_Mesajlar_oturum_id = ? AND m.Ticket_AI_Mesajlar_id > ? AND m.Durum = 1
                         ORDER BY m.Ticket_AI_Mesajlar_id",
                        [$oturum['id'], $sonId]
                    );
                }

                // Daha önce gösterilmiş kullanıcı isteklerinin durumu değişmiş olabilir (kuyrukta → tamamlandı)
                $bekleyen = $oturum ? (int)($db->fetchOne(
                    "SELECT COUNT(*) AS adet FROM dbo.Ticket_AI_Mesajlar m
                     INNER JOIN dbo.Tanim_AI_Durumlari d ON d.Tanim_AI_Durumlari_id = m.Ticket_AI_Mesajlar_durum_id
                     WHERE m.Ticket_AI_Mesajlar_oturum_id = ? AND m.Ticket_AI_Mesajlar_rol IN ('kullanici', 'geri_al')
                       AND d.Tanim_AI_Durumlari_kod IN ('kuyrukta', 'calisiyor') AND m.Durum = 1",
                    [$oturum['id']]
                )['adet'] ?? 0) : 0;

                // Geri alınmış değişiklik kartları (önceden çizilmiş kartlar güncellensin)
                $geriAlinan = $oturum ? array_map('intval', array_column($db->fetchAll(
                    "SELECT m.Ticket_AI_Mesajlar_id AS id FROM dbo.Ticket_AI_Mesajlar m
                     INNER JOIN dbo.Tanim_AI_Durumlari d ON d.Tanim_AI_Durumlari_id = m.Ticket_AI_Mesajlar_durum_id
                     WHERE m.Ticket_AI_Mesajlar_oturum_id = ? AND m.Ticket_AI_Mesajlar_rol = 'degisiklik'
                       AND d.Tanim_AI_Durumlari_kod = 'iptal' AND m.Durum = 1",
                    [$oturum['id']]
                ), 'id')) : [];

                echo json_encode(['success' => true, 'data' => [
                    'oturum'      => $oturum,
                    'mesajlar'    => $mesajlar,
                    'bekleyen'    => $bekleyen,
                    'geri_alinan' => $geriAlinan,
                ]], JSON_UNESCAPED_UNICODE);
                exit;

            // -----------------------------------------------------------------
            // Geri al: dosyalar çalıştırıcı tarafından yedekten geri yüklenir
            // (sayfa open_basedir nedeniyle proje dosyalarına erişemez)
            // -----------------------------------------------------------------
            case 'geri_al':
                if ($ticketId <= 0) throw new Exception('Geçersiz ticket.');
                $degisiklikId = (int)($_POST['degisiklik_id'] ?? 0);

                $oturum = aiAktifOturum($db, $ticketId);
                if (!$oturum) throw new Exception('Aktif AI oturumu yok.');
                if (in_array($oturum['durum_kod'], ['kuyrukta', 'calisiyor'], true)) {
                    throw new Exception('Asistan çalışırken geri alınamaz; işlemin bitmesini bekleyin.');
                }

                $degisiklik = $db->fetchOne(
                    "SELECT m.Ticket_AI_Mesajlar_id, d.Tanim_AI_Durumlari_kod AS durum_kod
                     FROM dbo.Ticket_AI_Mesajlar m
                     LEFT JOIN dbo.Tanim_AI_Durumlari d ON d.Tanim_AI_Durumlari_id = m.Ticket_AI_Mesajlar_durum_id
                     WHERE m.Ticket_AI_Mesajlar_id = ? AND m.Ticket_AI_Mesajlar_oturum_id = ?
                       AND m.Ticket_AI_Mesajlar_rol = 'degisiklik' AND m.Durum = 1",
                    [$degisiklikId, $oturum['id']]
                );
                if (!$degisiklik) throw new Exception('Değişiklik kaydı bulunamadı.');
                if ($degisiklik['durum_kod'] === 'iptal') throw new Exception('Bu değişiklik zaten geri alınmış.');

                $bekleyenIstek = $db->fetchOne(
                    "SELECT COUNT(*) AS adet FROM dbo.Ticket_AI_Mesajlar m
                     INNER JOIN dbo.Tanim_AI_Durumlari d ON d.Tanim_AI_Durumlari_id = m.Ticket_AI_Mesajlar_durum_id
                     WHERE m.Ticket_AI_Mesajlar_oturum_id = ? AND m.Ticket_AI_Mesajlar_rol = 'geri_al'
                       AND m.Ticket_AI_Mesajlar_icerik = ? AND d.Tanim_AI_Durumlari_kod IN ('kuyrukta', 'calisiyor')",
                    [$oturum['id'], (string)$degisiklikId]
                );
                if ((int)($bekleyenIstek['adet'] ?? 0) > 0) throw new Exception('Geri alma isteği zaten kuyrukta.');

                $db->insert('dbo.Ticket_AI_Mesajlar', [
                    'Ticket_AI_Mesajlar_oturum_id' => $oturum['id'],
                    'Ticket_AI_Mesajlar_rol'       => 'geri_al',
                    'Ticket_AI_Mesajlar_icerik'    => (string)$degisiklikId,
                    'Ticket_AI_Mesajlar_durum_id'  => aiDurumIdGetir($db, 'kuyrukta'),
                    'OlusturanKullanici'           => $user['kullanici_id'],
                    'OlusturmaTarihi'              => date('Y-m-d H:i:s'),
                    'Durum'                        => 1,
                ]);

                echo json_encode(['success' => true, 'message' => 'Geri alma kuyruğa alındı.'], JSON_UNESCAPED_UNICODE);
                exit;

            // -----------------------------------------------------------------
            // Taslak önizleme: ticket-ajan yanit ... --onizleme (hiçbir kayıt yazılmaz)
            // -----------------------------------------------------------------
            case 'taslak_onizle':
                if ($ticketId <= 0) throw new Exception('Geçersiz ticket.');
                $taslak = aiTaslakHazirla($ticketId, $_POST);
                try {
                    $rapor = aiTicketAjan($db, array_merge($taslak['arg'], ['--onizleme']));
                } finally {
                    foreach ($taslak['dosyalar'] as $f) @unlink($f);
                }
                $_SESSION['ai_taslak_imza'][$ticketId] = $taslak['imza'];

                echo json_encode(['success' => true, 'data' => $rapor], JSON_UNESCAPED_UNICODE);
                exit;

            // -----------------------------------------------------------------
            // Taslak gönder: önizlenen içerikle birebir aynıysa bayraksız çalıştırılır
            // -----------------------------------------------------------------
            case 'taslak_gonder':
                if ($ticketId <= 0) throw new Exception('Geçersiz ticket.');
                $taslak = aiTaslakHazirla($ticketId, $_POST);
                $onizlenen = $_SESSION['ai_taslak_imza'][$ticketId] ?? null;
                if (!$onizlenen || !hash_equals($onizlenen, $taslak['imza'])) {
                    foreach ($taslak['dosyalar'] as $f) @unlink($f);
                    throw new Exception('Metin veya seçenekler önizlemeden sonra değişti. Lütfen yeniden önizleyin.');
                }

                // Aynı müşteri yanıtının ikinci kez gönderilmesini engelle (sayfa yenilense de kart durur)
                if ($taslak['mesaj'] !== '') {
                    $ayni = $db->fetchOne(
                        "SELECT TOP 1 Ticket_Mesajlar_id FROM dbo.Ticket_Mesajlar
                         WHERE Ticket_Mesajlar_ticket_id = ? AND Ticket_Mesajlar_ic_not = 0 AND Durum = 1
                           AND Ticket_Mesajlar_mesaj = ? AND OlusturmaTarihi >= DATEADD(DAY, -1, GETDATE())",
                        [$ticketId, $taslak['mesaj']]
                    );
                    if ($ayni) {
                        foreach ($taslak['dosyalar'] as $f) @unlink($f);
                        throw new Exception('Bu müşteri yanıtı zaten gönderilmiş (mesaj #' . $ayni['Ticket_Mesajlar_id'] . ').');
                    }
                }

                try {
                    $sonuc = aiTicketAjan($db, $taslak['arg']);
                } finally {
                    foreach ($taslak['dosyalar'] as $f) @unlink($f);
                }
                unset($_SESSION['ai_taslak_imza'][$ticketId]);

                $oturum = aiAktifOturum($db, $ticketId);
                if ($oturum) {
                    $ozet = 'Gönderildi: ' . implode(', ', array_filter([
                        $sonuc['mesaj_id'] ? 'müşteri yanıtı #' . $sonuc['mesaj_id'] : null,
                        $sonuc['ic_not_id'] ? 'iç not #' . $sonuc['ic_not_id'] : null,
                    ])) . ' · bildirim: ' . ($sonuc['bildirim'] ?? '-') . ' · gönderen: ' . ($user['name'] ?? '-');
                    $db->insert('dbo.Ticket_AI_Mesajlar', [
                        'Ticket_AI_Mesajlar_oturum_id' => $oturum['id'],
                        'Ticket_AI_Mesajlar_rol'       => 'sistem',
                        'Ticket_AI_Mesajlar_icerik'    => $ozet,
                        'OlusturanKullanici'           => $user['kullanici_id'],
                        'OlusturmaTarihi'              => date('Y-m-d H:i:s'),
                        'Durum'                        => 1,
                    ]);
                }

                echo json_encode(['success' => true, 'data' => $sonuc, 'message' => 'Yanıt gönderildi.'], JSON_UNESCAPED_UNICODE);
                exit;

            // -----------------------------------------------------------------
            // Ticket durum / öncelik değiştirme (ticket-detay.php ile aynı kurallar)
            // -----------------------------------------------------------------
            case 'durum_guncelle':
            case 'oncelik_guncelle':
                if (!$pagePermissions['can_edit']) throw new Exception('Düzenleme yetkiniz yok.');
                if ($ticketId <= 0) throw new Exception('Geçersiz ticket.');
                $durumMu = $action === 'durum_guncelle';
                $deger   = (int)($_POST['deger_id'] ?? 0);

                $tanim = $durumMu
                    ? $db->fetchOne("SELECT Ticket_Durumlar_id AS id, Ticket_Durumlar_ad AS ad, Ticket_Durumlar_renk AS renk, Ticket_Durumlar_ikon AS ikon,
                                            Ticket_Durumlar_kapatma_durumu AS kapatma
                                     FROM dbo.Ticket_Durumlar WHERE Ticket_Durumlar_id = ? AND Durum = 1", [$deger])
                    : $db->fetchOne("SELECT Ticket_Oncelikler_id AS id, Ticket_Oncelikler_ad AS ad, Ticket_Oncelikler_renk AS renk, Ticket_Oncelikler_ikon AS ikon
                                     FROM dbo.Ticket_Oncelikler WHERE Ticket_Oncelikler_id = ? AND Durum = 1", [$deger]);
                if (!$tanim) throw new Exception($durumMu ? 'Durum bulunamadı.' : 'Öncelik bulunamadı.');

                $simdi  = date('Y-m-d H:i:s');
                $guncel = [
                    ($durumMu ? 'Tickets_durum_id' : 'Tickets_oncelik_id') => $deger,
                    'GuncelleyenKullanici' => $user['kullanici_id'],
                    'GuncellemeTarihi'     => $simdi,
                ];
                if ($durumMu && !empty($tanim['kapatma'])) {
                    $guncel['Tickets_kapanis_tarihi']    = $simdi;
                    $guncel['Tickets_musteri_cevap_var'] = 0;
                }
                $db->update('dbo.Tickets', $guncel, ['Tickets_id' => $ticketId]);

                echo json_encode(['success' => true, 'data' => $tanim,
                    'message' => ($durumMu ? 'Durum' : 'Öncelik') . ' güncellendi.'], JSON_UNESCAPED_UNICODE);
                exit;

            // -----------------------------------------------------------------
            // Mesaj gönder: kuyruğa eklenir, çalıştırıcı --resume ile işler
            // -----------------------------------------------------------------
            case 'mesaj_gonder':
                if ($ticketId <= 0) throw new Exception('Geçersiz ticket.');
                $mesaj = trim((string)($_POST['mesaj'] ?? ''));
                $gorselVar = !empty($_FILES['gorseller']['name'][0]);
                if ($mesaj === '' && !$gorselVar) throw new Exception('Mesaj boş olamaz.');
                if (mb_strlen($mesaj) > 20000) throw new Exception('Mesaj çok uzun (en fazla 20.000 karakter).');

                $oturum = aiAktifOturum($db, $ticketId);
                if (!$oturum) throw new Exception('Bu ticket için AI oturumu yok. Önce analizi başlatın.');
                if ($oturum['durum_kod'] === 'kuyrukta') throw new Exception('İlk analiz henüz başlamadı, lütfen bekleyin.');

                // Görseller: proje içine kaydedilir, mutlak yolu mesaja eklenir; ajan Read aracıyla açar
                if ($gorselVar) {
                    $yollar = aiGorselKaydet($db, $ticketId, $_FILES['gorseller']);
                    $mesaj = ($mesaj !== '' ? $mesaj : 'Ekteki görsel(ler)i incele.')
                           . "\n\n[Ekli görseller]\n" . implode("\n", array_map(fn($y) => '- ' . $y, $yollar));
                }

                $db->insert('dbo.Ticket_AI_Mesajlar', [
                    'Ticket_AI_Mesajlar_oturum_id' => $oturum['id'],
                    'Ticket_AI_Mesajlar_rol'       => 'kullanici',
                    'Ticket_AI_Mesajlar_icerik'    => $mesaj,
                    'Ticket_AI_Mesajlar_durum_id'  => aiDurumIdGetir($db, 'kuyrukta'),
                    'OlusturanKullanici'           => $user['kullanici_id'],
                    'OlusturmaTarihi'              => date('Y-m-d H:i:s'),
                    'Durum'                        => 1,
                ]);

                echo json_encode(['success' => true, 'message' => 'Mesaj kuyruğa alındı.'], JSON_UNESCAPED_UNICODE);
                exit;

            // -----------------------------------------------------------------
            // Analizi başlat / yeniden başlat (eski oturum pasife alınır, geçmişi silinmez)
            // -----------------------------------------------------------------
            case 'analiz_baslat':
                if ($ticketId <= 0) throw new Exception('Geçersiz ticket.');
                $ticket = $db->fetchOne("SELECT Tickets_id FROM dbo.Tickets WHERE Tickets_id = ? AND Durum = 1", [$ticketId]);
                if (!$ticket) throw new Exception('Ticket bulunamadı.');

                $oturum = aiAktifOturum($db, $ticketId);
                if ($oturum && in_array($oturum['durum_kod'], ['kuyrukta', 'calisiyor'], true)) {
                    throw new Exception('Analiz zaten sürüyor.');
                }
                if ($oturum) {
                    $db->update('dbo.Ticket_AI_Oturumlari', [
                        'Durum'                => 0,
                        'GuncelleyenKullanici' => $user['kullanici_id'],
                        'GuncellemeTarihi'     => date('Y-m-d H:i:s'),
                    ], ['Ticket_AI_Oturumlari_id' => $oturum['id']]);
                }

                $db->insert('dbo.Ticket_AI_Oturumlari', [
                    'Ticket_AI_Oturumlari_ticket_id' => $ticketId,
                    'Ticket_AI_Oturumlari_durum_id'  => aiDurumIdGetir($db, 'kuyrukta'),
                    'OlusturanKullanici'             => $user['kullanici_id'],
                    'OlusturmaTarihi'                => date('Y-m-d H:i:s'),
                    'Durum'                          => 1,
                ]);

                echo json_encode(['success' => true, 'message' => 'Analiz kuyruğa alındı.'], JSON_UNESCAPED_UNICODE);
                exit;

            default:
                throw new Exception('Geçersiz işlem.');
        }
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// =====================================================================
// Sayfa verisi
// =====================================================================
$ticketId = (int)($_GET['id'] ?? 0);
$ticket = $ticketId > 0 ? $db->fetchOne(
    "SELECT t.Tickets_id, t.Tickets_no, t.Tickets_konu,
            CONVERT(VARCHAR(16), t.OlusturmaTarihi, 120) AS acilis,
            dur.Ticket_Durumlar_ad AS durum_ad, dur.Ticket_Durumlar_renk AS durum_renk, dur.Ticket_Durumlar_ikon AS durum_ikon,
            t.Tickets_durum_id AS durum_id, t.Tickets_oncelik_id AS oncelik_id,
            onc.Ticket_Oncelikler_ad AS oncelik_ad, onc.Ticket_Oncelikler_renk AS oncelik_renk, onc.Ticket_Oncelikler_ikon AS oncelik_ikon,
            kay.Ticket_Kaynak_ad AS kaynak_ad, kay.Ticket_Kaynak_api_url AS kaynak_url,
            ac.kullanici_ad + ' ' + ac.kullanici_soyad AS acan,
            (SELECT COUNT(*) FROM dbo.Ticket_Mesajlar m WHERE m.Ticket_Mesajlar_ticket_id = t.Tickets_id AND m.Durum = 1 AND m.Ticket_Mesajlar_ic_not = 0) AS mesaj_sayisi,
            (SELECT COUNT(*) FROM dbo.Ticket_Ekler e WHERE e.Ticket_Ekler_ticket_id = t.Tickets_id AND e.Durum = 1) AS ek_sayisi
     FROM dbo.Tickets t
     LEFT JOIN dbo.Ticket_Durumlar   dur ON dur.Ticket_Durumlar_id   = t.Tickets_durum_id
     LEFT JOIN dbo.Ticket_Oncelikler onc ON onc.Ticket_Oncelikler_id = t.Tickets_oncelik_id
     LEFT JOIN dbo.Ticket_Kaynak     kay ON kay.Ticket_Kaynak_id     = t.Tickets_kaynak_id
     LEFT JOIN dbo.kullanicilar      ac  ON ac.kullanici_id          = t.Tickets_olusturan_kullanici_id
     WHERE t.Tickets_id = ? AND t.Durum = 1",
    [$ticketId]
) : null;

$ilkMesaj = $ticket ? $db->fetchOne(
    "SELECT TOP 1 Ticket_Mesajlar_mesaj AS mesaj FROM dbo.Ticket_Mesajlar
     WHERE Ticket_Mesajlar_ticket_id = ? AND Durum = 1 AND Ticket_Mesajlar_ic_not = 0
     ORDER BY OlusturmaTarihi, Ticket_Mesajlar_id",
    [$ticketId]
) : null;

$hizliKomutlar = ['onay', 'işlem yok', 'sadece analiz'];

// Taslak kartı seçenekleri
$taslakDurumlar = $ticket ? $db->fetchAll(
    "SELECT Ticket_Durumlar_id AS id, Ticket_Durumlar_ad AS ad FROM dbo.Ticket_Durumlar WHERE Durum = 1 ORDER BY Ticket_Durumlar_sira"
) : [];
$durumTanimlari = $ticket ? $db->fetchAll(
    "SELECT Ticket_Durumlar_id AS id, Ticket_Durumlar_ad AS ad, Ticket_Durumlar_renk AS renk, Ticket_Durumlar_ikon AS ikon
     FROM dbo.Ticket_Durumlar WHERE Durum = 1 ORDER BY Ticket_Durumlar_sira"
) : [];
$oncelikTanimlari = $ticket ? $db->fetchAll(
    "SELECT Ticket_Oncelikler_id AS id, Ticket_Oncelikler_ad AS ad, Ticket_Oncelikler_renk AS renk, Ticket_Oncelikler_ikon AS ikon
     FROM dbo.Ticket_Oncelikler WHERE Durum = 1 ORDER BY Ticket_Oncelikler_sira"
) : [];
$taslakPersoneller = $ticket ? $db->fetchAll(
    "SELECT kullanici_id AS id, kullanici_ad + ' ' + kullanici_soyad AS ad FROM dbo.kullanicilar WHERE kullanici_durum = 1 ORDER BY kullanici_ad"
) : [];
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> - <?= htmlspecialchars($siteTitle) ?></title>

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/source-sans-3@5.0.12/index.css" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" crossorigin="anonymous">
    <link rel="stylesheet" href="/admin/assets/css/adminlte.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/admin/assets/css/custom.css">

    <style>
        .ai-taslak { border: 1px solid #0d6efd33; border-left: 3px solid #0d6efd; border-radius: 8px; padding: .75rem; background: #f8fbff; margin: .5rem 0; }
        .ai-taslak.gonderildi { border-left-color: #198754; background: #f6fff9; }
        .ai-taslak textarea { font-size: .85rem; }
        .swal-rapor { text-align: left; font-size: .85rem; }
        .swal-rapor pre { white-space: pre-wrap; background: #f6f8fa; padding: .5rem; border-radius: 6px; margin-bottom: .5rem; }
        /* İçerik uzun olsa da Gönder / Vazgeç butonları ekranda kalsın: yalnız gövde kayar */
        .swal-rapor-govde { max-height: 58vh; overflow-y: auto !important; margin: .5rem 1rem 0 !important; }
        /* position: relative → Select2'nin mutlak konumlu gizli <select>'leri kaydırma kutusunun içinde kalır;
           yoksa sohbetin kaydırılmış içeriği kadar sayfayı uzatıp altta boş alan açar */
        #sohbetAlani { position: relative; height: calc(100vh - 430px); min-height: 380px; overflow-y: auto; background: #fafbfc; }
        .ai-balon { max-width: 92%; border-radius: 10px; padding: .75rem 1rem; margin-bottom: .75rem; overflow-wrap: anywhere; }
        .ai-asistan  { background: #fff; border-left: 3px solid #6f42c1; box-shadow: 0 1px 2px rgba(0,0,0,.05); }
        .ai-kullanici { background: #e8f4fd; border-left: 3px solid #0d6efd; margin-left: auto; white-space: pre-wrap; }
        .ai-sistem   { background: #fff8e1; border-left: 3px solid #ffc107; font-size: .85rem; }
        .ai-meta     { font-size: .72rem; color: #6c757d; margin-bottom: .25rem; }
        .ai-asistan table { font-size: .85rem; margin: .5rem 0; }
        .ai-asistan table td, .ai-asistan table th { border: 1px solid #dee2e6; padding: .25rem .5rem; }
        .ai-asistan pre { background: #f6f8fa; padding: .6rem; border-radius: 6px; font-size: .8rem; white-space: pre-wrap; overflow-wrap: anywhere; }
        .ai-asistan h3 { font-size: 1rem; margin-top: .75rem; font-weight: 600; }
        .ai-araclar { font-size: .78rem; margin-bottom: .75rem; }
        .ai-araclar summary { cursor: pointer; color: #6c757d; }
        .ai-araclar .arac-satir { font-family: monospace; color: #495057; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .panel-bilgi label { font-size: .78rem; color: #6c757d; margin-bottom: 0; }
        .panel-bilgi .deger { font-size: .9rem; font-weight: 500; }
        .ilk-mesaj { font-size: .85rem; max-height: 220px; overflow-y: auto; white-space: pre-wrap; }
        .ai-degisiklik { background: #fff; border-left: 3px solid #198754; box-shadow: 0 1px 2px rgba(0,0,0,.05); }
        .ai-degisiklik.geri-alindi { border-left-color: #6c757d; opacity: .75; }
        .ai-diff { font-family: monospace; font-size: .78rem; background: #f6f8fa; border-radius: 6px; padding: .5rem; max-height: 420px; overflow: auto; margin: 0; }
        .ai-diff div { white-space: pre-wrap; overflow-wrap: anywhere; }
        .ai-diff .d-ekle { background: #e6ffed; color: #116329; }
        .ai-diff .d-sil  { background: #ffebe9; color: #82071e; }
        .ai-diff .d-hunk { color: #0969da; }
        .ai-diff .d-dosya { font-weight: 600; color: #24292f; margin-top: .4rem; }
        .rozet-degistir { cursor: pointer; }
        .rozet-degistir:hover { filter: brightness(.9); }
        .gorsel-kutu { position: relative; width: 72px; height: 72px; border: 1px solid #dee2e6; border-radius: 6px; overflow: hidden; background: #fff; }
        .gorsel-kutu img { width: 100%; height: 100%; object-fit: cover; }
        .gorsel-kutu .btn-gorsel-sil { position: absolute; top: 2px; right: 2px; padding: 0 .3rem; line-height: 1.2; font-size: .7rem; }
        .ai-kullanici .ai-gorseller { display: flex; flex-wrap: wrap; gap: .4rem; margin-top: .5rem; white-space: normal; }
        .ai-kullanici .ai-gorseller img { max-width: 160px; max-height: 120px; border-radius: 6px; border: 1px solid #cfe2ff; cursor: zoom-in; }
        #formMesaj.surukle textarea { border: 2px dashed #0d6efd; background: #f0f7ff; }
        .yaziyor::after { content: '…'; animation: yanip 1s infinite; }
        @keyframes yanip { 50% { opacity: 0; } }
    </style>
</head>
<body class="layout-fixed sidebar-expand-lg sidebar-open bg-body-tertiary">
<div class="app-wrapper">

    <?php include __DIR__ . '/../includes/header.php'; ?>
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>

    <main class="app-main">
        <div class="app-content-header">
            <div class="container-fluid">
                <div class="row">
                    <div class="col-sm-6">
                        <h3 class="mb-0"><i class="bi bi-robot me-2"></i><?= htmlspecialchars($pageTitle) ?></h3>
                        <?php if ($ticket): ?>
                        <small class="text-muted">#<?= (int)$ticket['Tickets_id'] ?> · <?= htmlspecialchars($ticket['Tickets_konu']) ?></small>
                        <?php endif; ?>
                    </div>
                    <div class="col-sm-6 d-flex flex-column align-items-end gap-1">
                        <?php if ($ticket): ?>
                        <a href="/admin/ticket-detay?id=<?= (int)$ticket['Tickets_id'] ?>" class="btn btn-secondary btn-sm">
                            <i class="bi bi-arrow-left me-1"></i> Ticket'a Dön
                        </a>
                        <?php endif; ?>
                        <ol class="breadcrumb mb-0">
                            <?php if ($menuAdi): ?><li class="breadcrumb-item"><?= htmlspecialchars($menuAdi) ?></li><?php endif; ?>
                            <li class="breadcrumb-item"><a href="/admin/ticket-liste">Ticket Listesi</a></li>
                            <li class="breadcrumb-item active">AI Asistan</li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>

        <div class="app-content">
            <div class="container-fluid">

                <!-- InfoBox -->
                <div class="row">
                    <div class="col-6 col-lg-3">
                        <div class="info-box">
                            <span class="info-box-icon text-bg-secondary shadow-sm"><i class="bi bi-hourglass-split"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Kuyrukta / Çalışıyor</span>
                                <span class="info-box-number" id="ibKuyruk">-</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-lg-3">
                        <div class="info-box">
                            <span class="info-box-icon text-bg-warning shadow-sm"><i class="bi bi-chat-dots"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Yanıtınızı Bekleyen</span>
                                <span class="info-box-number" id="ibBekleyen">-</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-lg-3">
                        <div class="info-box">
                            <span class="info-box-icon text-bg-primary shadow-sm"><i class="bi bi-calendar-check"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Bugünkü Analiz / Limit</span>
                                <span class="info-box-number" id="ibBugun">-</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-lg-3">
                        <div class="info-box">
                            <span class="info-box-icon text-bg-success shadow-sm"><i class="bi bi-cash-coin"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Bugünkü Tahmini Maliyet</span>
                                <span class="info-box-number" id="ibMaliyet">-</span>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if (!$ticket): ?>
                <div class="alert alert-info">
                    <i class="bi bi-info-circle me-1"></i>
                    Bir ticket seçin: <code>/admin/ai-asistan?id=NN</code> veya ticket detay sayfasındaki <b>AI Asistan</b> butonu.
                </div>
                <?php else: ?>
                <div class="row">
                    <!-- Sol: Ticket bilgisi -->
                    <div class="col-12 col-lg-4">
                        <div class="card card-outline card-secondary mb-3">
                            <div class="card-header"><h3 class="card-title"><i class="bi bi-ticket-detailed me-1"></i> Ticket</h3></div>
                            <div class="card-body panel-bilgi">
                                <div class="row g-2 mb-2">
                                    <div class="col-6"><label>Ticket No</label><div class="deger"><?= htmlspecialchars($ticket['Tickets_no'] ?? '-') ?></div></div>
                                    <div class="col-6"><label>Durum</label><div class="deger"><span class="badge rozet-degistir" id="rozetDurum" data-tur="durum" data-id="<?= (int)$ticket['durum_id'] ?>" title="Durumu değiştir"></span></div></div>
                                    <div class="col-6"><label>Kaynak</label><div class="deger"><?= htmlspecialchars($ticket['kaynak_ad'] ?? '-') ?></div></div>
                                    <div class="col-6"><label>Öncelik</label><div class="deger"><span class="badge rozet-degistir" id="rozetOncelik" data-tur="oncelik" data-id="<?= (int)$ticket['oncelik_id'] ?>" title="Önceliği değiştir"></span></div></div>
                                    <div class="col-6"><label>Açan</label><div class="deger"><?= htmlspecialchars($ticket['acan'] ?? '-') ?></div></div>
                                    <div class="col-6"><label>Açılış</label><div class="deger"><?= htmlspecialchars($ticket['acilis'] ?? '-') ?></div></div>
                                    <div class="col-6"><label>Mesaj / Ek</label><div class="deger"><?= (int)$ticket['mesaj_sayisi'] ?> / <?= (int)$ticket['ek_sayisi'] ?></div></div>
                                </div>
                                <?php if ($ilkMesaj): ?>
                                <label>İlk mesaj</label>
                                <div class="ilk-mesaj border rounded p-2 bg-light"><?= htmlspecialchars($ilkMesaj['mesaj']) ?></div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="card card-outline card-primary mb-3">
                            <div class="card-header"><h3 class="card-title"><i class="bi bi-cpu me-1"></i> AI Oturumu</h3></div>
                            <div class="card-body panel-bilgi">
                                <div class="row g-2">
                                    <div class="col-12"><label>Durum</label><div class="deger" id="otDurum">-</div></div>
                                    <div class="col-12"><label>Proje dizini</label><div class="deger text-break small" id="otDizin">-</div></div>
                                    <div class="col-6"><label>Token</label><div class="deger" id="otToken">-</div></div>
                                    <div class="col-6"><label>Tahmini maliyet</label><div class="deger" id="otMaliyet">-</div></div>
                                    <div class="col-12"><label>Son işlem</label><div class="deger" id="otSonIslem">-</div></div>
                                </div>
                                <div class="alert alert-danger small mt-2 mb-0 d-none" id="otHata"></div>
                                <button type="button" class="btn btn-outline-primary btn-sm w-100 mt-3" id="btnAnaliz">
                                    <i class="bi bi-play-circle me-1"></i> <span>Analizi Başlat</span>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Sağ: Sohbet -->
                    <div class="col-12 col-lg-8">
                        <div class="card card-outline card-purple mb-3" style="--bs-card-border-color:#6f42c1">
                            <div class="card-header d-flex align-items-center">
                                <h3 class="card-title mb-0"><i class="bi bi-chat-square-text me-1"></i> Sohbet</h3>
                                <span class="ms-auto small text-muted d-none" id="durumYazi"><span class="spinner-border spinner-border-sm me-1"></span><span class="yaziyor">Çalışıyor</span></span>
                            </div>
                            <div class="card-body p-3" id="sohbetAlani">
                                <div class="text-center text-muted py-5" id="sohbetBos">
                                    <i class="bi bi-robot fs-1 d-block mb-2"></i>
                                    Bu ticket için henüz analiz yok.
                                </div>
                            </div>
                            <div class="card-footer">
                                <div class="d-flex flex-wrap gap-1 mb-2">
                                    <?php foreach ($hizliKomutlar as $komut): ?>
                                    <button type="button" class="btn btn-outline-secondary btn-sm btn-hizli" data-komut="<?= htmlspecialchars($komut) ?>"><?= htmlspecialchars($komut) ?></button>
                                    <?php endforeach; ?>
                                </div>
                                <div class="d-flex flex-wrap gap-2 mb-2 d-none" id="gorselOnizleme"></div>
                                <form id="formMesaj" class="d-flex gap-2">
                                    <input type="file" id="fileGorsel" accept="image/png,image/jpeg,image/gif,image/webp" multiple hidden>
                                    <button type="button" class="btn btn-outline-secondary align-self-end" id="btnGorsel" title="Görsel ekle (yapıştırma ve sürükle-bırak da desteklenir)"><i class="bi bi-paperclip"></i></button>
                                    <textarea class="form-control" id="txtMesaj" rows="2" placeholder="Mesajınızı yazın… (Ctrl+Enter ile gönder, Ctrl+V ile görsel yapıştır)"></textarea>
                                    <button type="submit" class="btn btn-primary align-self-end" id="btnGonder"><i class="bi bi-send"></i></button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <?php include __DIR__ . '/../includes/footer.php'; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/marked@12.0.2/marked.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/dompurify@3.1.6/dist/purify.min.js" crossorigin="anonymous"></script>
<script src="/admin/assets/js/adminlte.min.js"></script>
<script src="/admin/assets/js/custom.js"></script>

<script>
const TICKET_ID = <?= $ticket ? (int)$ticket['Tickets_id'] : 0 ?>;
const PAGE_PATH = window.location.pathname;
const TASLAK_DURUMLAR    = <?= json_encode($taslakDurumlar, JSON_UNESCAPED_UNICODE) ?>;
const TASLAK_PERSONELLER = <?= json_encode($taslakPersoneller, JSON_UNESCAPED_UNICODE) ?>;
const ROZET_TANIMLARI = {
    durum:   { baslik: 'Durum',   liste: <?= json_encode($durumTanimlari, JSON_UNESCAPED_UNICODE) ?> },
    oncelik: { baslik: 'Öncelik', liste: <?= json_encode($oncelikTanimlari, JSON_UNESCAPED_UNICODE) ?> }
};

// Durum / öncelik rozeti: tanımdaki renk ve ikonla çizilir
function rozetCiz($r) {
    const t = ROZET_TANIMLARI[$r.data('tur')].liste.find(x => x.id == $r.attr('data-id'));
    const renk = t && /^#[0-9a-f]{3,8}$/i.test(t.renk || '') ? t.renk : '#6c757d';
    $r.css('background-color', renk).html(t
        ? `<i class="${escHtml(t.ikon || 'bi bi-circle')} me-1"></i>${escHtml(t.ad)} <i class="bi bi-pencil-square ms-1 opacity-75"></i>`
        : '- <i class="bi bi-pencil-square ms-1 opacity-75"></i>');
}

// Rozete tıklanınca aranabilir listeden yeni değer seçilir
function rozetDegistir($r) {
    const tur = $r.data('tur'), tanim = ROZET_TANIMLARI[tur], mevcut = String($r.attr('data-id'));
    const ops = tanim.liste.map(x => `<option value="${x.id}"${String(x.id) === mevcut ? ' selected' : ''}>${escHtml(x.ad)}</option>`).join('');
    Swal.fire({
        title: tanim.baslik + ' değiştir',
        html: `<select id="swalRozetSec" class="form-select">${ops}</select>`,
        showCancelButton: true, confirmButtonText: 'Kaydet', cancelButtonText: 'Vazgeç',
        didOpen: function (p) { $('#swalRozetSec').select2({ theme: 'bootstrap-5', width: '100%', dropdownParent: $(p) }); },
        preConfirm: function () { return $('#swalRozetSec').val(); }
    }).then(function (s) {
        if (!s.isConfirmed) return;
        if (String(s.value) === mevcut) { toast('info', tanim.baslik + ' zaten aynı'); return; }
        postAjax({ action: tur + '_guncelle', ticket_id: TICKET_ID, deger_id: s.value }, function (res) {
            if (!res.success) { Swal.fire('Hata', res.message, 'error'); return; }
            $r.attr('data-id', s.value);
            rozetCiz($r);
            toast('success', res.message);
        });
    });
}

let sonId = 0;
let taslakSayac = 0;
let yoklamaZamanlayici = null;
let aracGrubu = null;   // ardışık araç adımları tek <details> içinde toplanır

function escHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function toast(icon, title) {
    Swal.fire({ toast: true, position: 'top-end', icon: icon, title: title, showConfirmButton: false, timer: 2000 });
}

function postAjax(data, callback) {
    $.post(PAGE_PATH, data, function (res) {
        if (typeof res === 'string') { try { res = JSON.parse(res); } catch (e) {} }
        callback(res);
    }).fail(function () { toast('error', 'Sunucu bağlantı hatası.'); });
}

function markdownHtml(metin) {
    return DOMPurify.sanitize(marked.parse(String(metin || ''), { gfm: true, breaks: true }));
}

// ```taslak {json}``` bloklarını ayıklar; yerine yer tutucu koyar. Dönüş: { metin, taslaklar[] }
function taslakAyikla(metin) {
    const taslaklar = [];
    const temiz = String(metin || '').replace(/```taslak[^\n]*\n([\s\S]*?)\n```/g, function (tam, govde) {
        try {
            taslaklar.push(JSON.parse(govde));
            return `\n\n<div data-taslak-yer="${taslaklar.length - 1}"></div>\n\n`;
        } catch (e) {
            return tam;   // bozuk JSON: kod bloğu olarak kalsın
        }
    });
    return { metin: temiz, taslaklar: taslaklar };
}

function trSade(s) {
    return String(s || '').toLocaleLowerCase('tr-TR')
        .replace(/ç/g,'c').replace(/ğ/g,'g').replace(/ı/g,'i').replace(/ö/g,'o').replace(/ş/g,'s').replace(/ü/g,'u').trim();
}

// Taslak kartı: düzenlenebilir müşteri yanıtı + iç not, durum/atama (Select2), Önizle → Gönder
function taslakKarti(t) {
    const no = ++taslakSayac;
    const durumSecili = TASLAK_DURUMLAR.find(d => trSade(d.ad) === trSade(t.durum));
    const durumOps = '<option value="">Değiştirme</option>' + TASLAK_DURUMLAR.map(d =>
        `<option value="${d.id}"${durumSecili && durumSecili.id == d.id ? ' selected' : ''}>${escHtml(d.ad)}</option>`).join('');
    const atanan = String(t.atanan || '');
    const atamaOps = [['', 'Otomatik (atanmamışsa bana)'], ['ben', 'Bana ata'], ['bos', 'Atamayı kaldır']]
        .map(([v, a]) => `<option value="${v}"${atanan === v ? ' selected' : ''}>${a}</option>`).join('')
        + TASLAK_PERSONELLER.map(p => `<option value="${p.id}"${atanan === String(p.id) ? ' selected' : ''}>${escHtml(p.ad)}</option>`).join('');

    const $k = $(`<div class="ai-taslak">
        <div class="fw-semibold small mb-2"><i class="bi bi-envelope-paper me-1"></i>Yanıt taslağı <span class="text-muted fw-normal">(gönderen: OrnekYazilim AI)</span></div>
        <label class="form-label small mb-1" for="tsMesaj${no}">Müşteri yanıtı</label>
        <textarea class="form-control mb-2 ts-mesaj" id="tsMesaj${no}" rows="8"></textarea>
        <label class="form-label small mb-1" for="tsIcnot${no}">İç not <span class="text-muted">(yalnız ekip görür, bildirim gitmez)</span></label>
        <textarea class="form-control mb-2 ts-icnot" id="tsIcnot${no}" rows="5"></textarea>
        <div class="row g-2 align-items-end">
            <div class="col-md-4"><label class="form-label small mb-1" for="tsDurum${no}">Durum</label>
                <select class="form-select form-select-sm ts-durum" id="tsDurum${no}">${durumOps}</select></div>
            <div class="col-md-5"><label class="form-label small mb-1" for="tsAtanan${no}">Atama</label>
                <select class="form-select form-select-sm ts-atanan" id="tsAtanan${no}">${atamaOps}</select></div>
            <div class="col-md-3 text-end"><button type="button" class="btn btn-primary btn-sm w-100 btn-taslak-onizle"><i class="bi bi-eye me-1"></i>Önizle ve Gönder</button></div>
        </div>
    </div>`);
    $k.find('.ts-mesaj').val(t.musteri_yaniti || '');
    $k.find('.ts-icnot').val(t.ic_not || '');
    return $k;
}

function taslakVeri($k) {
    return {
        ticket_id: TICKET_ID,
        mesaj:  $k.find('.ts-mesaj').val(),
        ic_not: $k.find('.ts-icnot').val(),
        durum:  $k.find('.ts-durum').val() || '',
        atanan: $k.find('.ts-atanan').val() || ''
    };
}

// Önizleme raporu + tam metin gösterilir; onaylanırsa aynı içerik bayraksız gönderilir
function taslakOnizle($k) {
    const veri = taslakVeri($k);
    const $btn = $k.find('.btn-taslak-onizle').prop('disabled', true);
    postAjax(Object.assign({ action: 'taslak_onizle' }, veri), function (res) {
        $btn.prop('disabled', false);
        if (!res.success) { Swal.fire('Önizleme hatası', res.message, 'error'); return; }
        const r = res.data || {};
        // degisen: { durum: 'Çözüldü', atanan: 'Ad Soyad' | null, ... }
        const etiketler = { durum: 'Durum', oncelik: 'Öncelik', kategori: 'Kategori', atanan: 'Atanan' };
        const degisen = Object.entries(r.degisen || {}).map(([k, v]) =>
            `<li>${escHtml(etiketler[k] || k)}: <b>${escHtml(v === null ? '(atama kaldırılacak)' : v)}</b></li>`).join('');
        const html = `<div class="swal-rapor">
            <div class="alert alert-warning py-1 small mb-2">Henüz hiçbir şey gönderilmedi.</div>
            <div><b>Gönderen:</b> ${escHtml(r.yazan || '-')}</div>
            <div><b>Müşteri bildirimi:</b> ${escHtml(r.bildirim || '-')}</div>
            <div><b>Atama bildirimi:</b> ${escHtml(r.atama_bildirimi || '-')}</div>
            ${r.otomatik_atama ? `<div><b>Otomatik atama:</b> ${escHtml(r.otomatik_atama)}</div>` : ''}
            ${degisen ? `<div class="mt-1"><b>Ticket'ta değişecek:</b><ul class="mb-1">${degisen}</ul></div>` : ''}
            ${veri.mesaj.trim() ? `<div class="mt-2"><b>Müşteri yanıtı (tam metin):</b><pre>${escHtml(veri.mesaj.trim())}</pre></div>` : ''}
            ${veri.ic_not.trim() ? `<div><b>İç not (tam metin):</b><pre>${escHtml(veri.ic_not.trim())}</pre></div>` : ''}
        </div>`;
        Swal.fire({
            title: 'Önizleme', html: html, width: 760,
            customClass: { htmlContainer: 'swal-rapor-govde' },
            showCancelButton: true, confirmButtonText: '<i class="bi bi-send me-1"></i>Gönder', cancelButtonText: 'Vazgeç',
            confirmButtonColor: '#198754', reverseButtons: true, focusCancel: true
        }).then(function (s) {
            if (!s.isConfirmed) return;
            $btn.prop('disabled', true);
            postAjax(Object.assign({ action: 'taslak_gonder' }, veri), function (g) {
                if (!g.success) { $btn.prop('disabled', false); Swal.fire('Gönderilemedi', g.message, 'error'); return; }
                $k.addClass('gonderildi').find('textarea, select').prop('disabled', true);
                $btn.html('<i class="bi bi-check2 me-1"></i>Gönderildi');
                toast('success', g.message);
                sohbetYukle(false);
            });
        });
    });
}

// Araç girdisinden tek satırlık özet (dosya yolu, desen veya komut)
function aracOzet(arac, icerik) {
    let g = {};
    try { g = JSON.parse(icerik || '{}'); } catch (e) { return icerik; }
    const ozet = g.file_path || g.pattern || g.command || g.path || icerik;
    return ozet + (g.offset ? ` (satır ${g.offset}+)` : '');
}

function enAltaKaydir(zorla) {
    const el = document.getElementById('sohbetAlani');
    const altta = el.scrollHeight - el.scrollTop - el.clientHeight < 120;
    if (zorla || altta) el.scrollTop = el.scrollHeight;
}

function mesajEkle(m) {
    const $alan = $('#sohbetAlani');
    $('#sohbetBos').remove();

    if (m.rol === 'arac') {
        if (!aracGrubu) {
            aracGrubu = $('<details class="ai-araclar"><summary><i class="bi bi-tools me-1"></i><span class="arac-sayi">0</span> adım</summary><div class="ps-3 pt-1"></div></details>');
            $alan.append(aracGrubu);
        }
        aracGrubu.find('div').append(
            `<div class="arac-satir" title="${escHtml(m.icerik)}"><span class="badge text-bg-light border me-1">${escHtml(m.arac || '?')}</span>${escHtml(aracOzet(m.arac, m.icerik))}</div>`
        );
        const $sayi = aracGrubu.find('.arac-sayi');
        $sayi.text(parseInt($sayi.text(), 10) + 1);
        return;
    }
    aracGrubu = null;

    const tarih = escHtml((m.tarih || '').substring(11, 16));
    if (m.rol === 'kullanici') {
        const bekliyor = ['kuyrukta', 'calisiyor'].includes(m.durum_kod)
            ? ' <i class="bi bi-clock-history" title="İşleniyor"></i>' : '';
        // "[Ekli görseller]" bloğu küçük resim olarak gösterilir (assets/uploads/tickets/<ticket>/<dosya>)
        let metin = String(m.icerik || ''), resimler = '';
        const blok = metin.match(/\n*\[Ekli görseller\]\n((?:- .+(?:\n|$))+)$/);
        if (blok) {
            metin = metin.slice(0, blok.index);
            resimler = '<div class="ai-gorseller">' + blok[1].split('\n').map(function (s) {
                const p = s.match(/assets\/uploads\/tickets\/\d+\/[\w-]+\.(?:png|jpg|gif|webp)$/);
                if (!p) return '';
                const url = '/' + p[0];
                return `<a href="${escHtml(url)}" target="_blank"><img src="${escHtml(url)}" alt="görsel"></a>`;
            }).join('') + '</div>';
        }
        $alan.append(`<div class="ai-balon ai-kullanici" data-id="${m.id}"><div class="ai-meta"><i class="bi bi-person me-1"></i>${escHtml(m.yazan || 'Siz')} · ${tarih}${bekliyor}</div>${escHtml(metin)}${resimler}</div>`);
    } else if (m.rol === 'degisiklik') {
        $alan.append(degisiklikHtml(m, tarih));
    } else if (m.rol === 'geri_al') {
        $alan.append(`<div class="ai-balon ai-kullanici" data-id="${m.id}"><div class="ai-meta"><i class="bi bi-arrow-counterclockwise me-1"></i>${escHtml(m.yazan || 'Siz')} · ${tarih}</div>Değişikliği geri al</div>`);
    } else if (m.rol === 'asistan') {
        const a = taslakAyikla(m.icerik);
        const $b = $(`<div class="ai-balon ai-asistan"><div class="ai-meta"><i class="bi bi-robot me-1"></i>AI Asistan · ${tarih}</div>${markdownHtml(a.metin)}</div>`);
        $b.find('[data-taslak-yer]').each(function () {
            const t = a.taslaklar[parseInt($(this).attr('data-taslak-yer'), 10)];
            const $k = taslakKarti(t);
            $(this).replaceWith($k);
            $k.find('select').select2({ theme: 'bootstrap-5', width: '100%', dropdownParent: $k });
        });
        $alan.append($b);
    } else {
        $alan.append(`<div class="ai-balon ai-sistem"><div class="ai-meta"><i class="bi bi-info-circle me-1"></i>Sistem · ${tarih}</div>${escHtml(m.icerik)}</div>`);
    }
}

// Değişiklik kartı: dosya listesi + renkli birleşik diff + Geri Al
function degisiklikHtml(m, tarih) {
    let v = {};
    try { v = JSON.parse(m.icerik || '{}'); } catch (e) { return `<div class="ai-balon ai-sistem">${escHtml(m.icerik)}</div>`; }
    const dosyalar = (v.dosyalar || []).map(function (d) {
        const etiket = d.yeni ? '<span class="badge text-bg-success ms-1">yeni</span>' : (d.silindi ? '<span class="badge text-bg-danger ms-1">silindi</span>' : '');
        return `<li><code>${escHtml(d.yol)}</code>${etiket}</li>`;
    }).join('');
    const diff = String(v.diff || '').split('\n').map(function (s) {
        const sinif = /^(---|\+\+\+) /.test(s) ? 'd-dosya' : s.startsWith('@@') ? 'd-hunk' : s.startsWith('+') ? 'd-ekle' : s.startsWith('-') ? 'd-sil' : '';
        return `<div class="${sinif}">${escHtml(s) || '&nbsp;'}</div>`;
    }).join('');
    const geriAlindi = m.durum_kod === 'iptal';
    return `<div class="ai-balon ai-degisiklik${geriAlindi ? ' geri-alindi' : ''}" data-degisiklik="${m.id}">
        <div class="ai-meta d-flex align-items-center"><span><i class="bi bi-file-diff me-1"></i>Dosya değişikliği · ${tarih}</span>
            <span class="ms-auto durum-alani">${geriAlindi
                ? '<span class="badge text-bg-secondary">Geri alındı</span>'
                : `<button type="button" class="btn btn-outline-danger btn-sm py-0 btn-geri-al" data-id="${m.id}"><i class="bi bi-arrow-counterclockwise me-1"></i>Geri Al</button>`}</span></div>
        <ul class="mb-2 small ps-3">${dosyalar}</ul>
        <details><summary class="small text-muted">Farkı göster</summary><div class="ai-diff mt-1">${diff}</div></details>
    </div>`;
}

function oturumGoster(o, bekleyen) {
    const calisiyor = o && (['kuyrukta', 'calisiyor'].includes(o.durum_kod) || bekleyen > 0);
    $('#durumYazi').toggleClass('d-none', !calisiyor);
    $('#btnAnaliz').prop('disabled', !!(o && ['kuyrukta', 'calisiyor'].includes(o.durum_kod)));

    if (!o) {
        $('#otDurum').html('<span class="badge text-bg-light border">Oturum yok</span>');
        $('#btnAnaliz span').text('Analizi Başlat');
        $('#txtMesaj, #btnGonder, #btnGorsel, .btn-hizli').prop('disabled', true);
        return calisiyor;
    }
    $('#otDurum').html(`<span class="badge text-bg-${escHtml(o.durum_renk || 'secondary')}"><i class="bi ${escHtml(o.durum_ikon || '')} me-1"></i>${escHtml(o.durum_ad)}</span>`);
    $('#otDizin').text(o.proje_dizin || '-');
    $('#otToken').text(Number(o.token || 0).toLocaleString('tr-TR'));
    $('#otMaliyet').text('$' + Number(o.maliyet || 0).toFixed(2));
    $('#otSonIslem').text(o.son_islem || '-');
    $('#otHata').toggleClass('d-none', !o.son_hata).text(o.son_hata || '');
    $('#btnAnaliz span').text('Yeniden Analiz Et');
    $('#txtMesaj, #btnGonder, #btnGorsel, .btn-hizli').prop('disabled', o.durum_kod === 'kuyrukta');
    return calisiyor;
}

function sohbetYukle(ilk) {
    clearTimeout(yoklamaZamanlayici);
    postAjax({ action: 'sohbet', ticket_id: TICKET_ID, son_id: sonId }, function (res) {
        if (!res.success) { toast('error', res.message); return; }
        const d = res.data;
        (d.mesajlar || []).forEach(function (m) {
            mesajEkle(m);
            sonId = Math.max(sonId, parseInt(m.id, 10));
        });
        // Kullanıcı mesajlarının "işleniyor" simgesi: iş bitince kaldırılır
        if (!d.bekleyen) $('.ai-kullanici .bi-clock-history').remove();
        (d.geri_alinan || []).forEach(function (id) {
            const $k = $(`.ai-degisiklik[data-degisiklik="${id}"]`);
            if ($k.length && !$k.hasClass('geri-alindi')) {
                $k.addClass('geri-alindi').find('.durum-alani').html('<span class="badge text-bg-secondary">Geri alındı</span>');
            }
        });
        if ((d.mesajlar || []).length) enAltaKaydir(ilk);

        const calisiyor = oturumGoster(d.oturum, d.bekleyen);
        yoklamaZamanlayici = setTimeout(sohbetYukle, calisiyor ? 2000 : 10000);
    });
}

function istatistikYukle() {
    postAjax({ action: 'istatistik' }, function (res) {
        if (!res.success) return;
        const d = res.data, s = d.durumlar || {};
        $('#ibKuyruk').text((parseInt(s.kuyrukta || 0, 10) + parseInt(s.calisiyor || 0, 10)));
        $('#ibBekleyen').text(parseInt(s.yanit_bekliyor || 0, 10));
        $('#ibBugun').text(d.bugun_adet + ' / ' + (d.gunluk_limit || '∞'));
        $('#ibMaliyet').text('$' + Number(d.bugun_maliyet || 0).toFixed(2));
    });
}

// Gönderilmeyi bekleyen görseller (yapıştır / sürükle-bırak / ataç)
let bekleyenGorseller = [];

function gorselEkle(dosyalar) {
    Array.from(dosyalar || []).forEach(function (f) {
        if (!/^image\/(png|jpeg|gif|webp)$/.test(f.type)) { toast('warning', (f.name || 'Dosya') + ': yalnız görsel eklenebilir.'); return; }
        if (!f.name || f.name === 'image.png') {
            f = new File([f], 'yapistirilan-' + Date.now() + '.' + f.type.split('/')[1], { type: f.type });
        }
        bekleyenGorseller.push(f);
    });
    gorselOnizlemeCiz();
}

function gorselOnizlemeCiz() {
    const $o = $('#gorselOnizleme').empty().toggleClass('d-none', bekleyenGorseller.length === 0);
    bekleyenGorseller.forEach(function (f, i) {
        const url = URL.createObjectURL(f);
        $o.append(`<div class="gorsel-kutu" title="${escHtml(f.name)}"><img src="${url}" alt=""><button type="button" class="btn btn-danger btn-gorsel-sil" data-i="${i}">&times;</button></div>`);
    });
}

function mesajGonder(metin) {
    metin = String(metin || '').trim();
    if (!metin && !bekleyenGorseller.length) return;
    const fd = new FormData();
    fd.append('action', 'mesaj_gonder');
    fd.append('ticket_id', TICKET_ID);
    fd.append('mesaj', metin);
    bekleyenGorseller.forEach(f => fd.append('gorseller[]', f, f.name));

    $('#btnGonder, #btnGorsel').prop('disabled', true);
    $.ajax({ url: PAGE_PATH, type: 'POST', data: fd, processData: false, contentType: false, dataType: 'json' })
        .done(function (res) {
            if (!res.success) { Swal.fire('Uyarı', res.message, 'warning'); return; }
            $('#txtMesaj').val('');
            bekleyenGorseller = [];
            gorselOnizlemeCiz();
            sohbetYukle(true);
        })
        .fail(function () { toast('error', 'Sunucu bağlantı hatası.'); })
        .always(function () { $('#btnGonder, #btnGorsel').prop('disabled', false); });
}

$(function () {
    istatistikYukle();
    setInterval(istatistikYukle, 30000);
    if (!TICKET_ID) return;

    $('.rozet-degistir').each(function () { rozetCiz($(this)); })
        .on('click', function () { rozetDegistir($(this)); });

    sohbetYukle(true);

    $('#formMesaj').on('submit', function (e) { e.preventDefault(); mesajGonder($('#txtMesaj').val()); });
    $('#txtMesaj').on('keydown', function (e) {
        if (e.key === 'Enter' && e.ctrlKey) { e.preventDefault(); mesajGonder($(this).val()); }
    });
    $('.btn-hizli').on('click', function () { mesajGonder($(this).data('komut')); });

    // Görsel: ataç, Ctrl+V yapıştırma, sürükle-bırak
    $('#btnGorsel').on('click', () => $('#fileGorsel').trigger('click'));
    $('#fileGorsel').on('change', function () { gorselEkle(this.files); this.value = ''; });
    $('#txtMesaj').on('paste', function (e) {
        const ogeler = (e.originalEvent.clipboardData || {}).items || [];
        const resimler = Array.from(ogeler).filter(o => o.kind === 'file' && o.type.startsWith('image/')).map(o => o.getAsFile());
        if (resimler.length) { e.preventDefault(); gorselEkle(resimler); }
    });
    $('#formMesaj')
        .on('dragover', function (e) { e.preventDefault(); $(this).addClass('surukle'); })
        .on('dragleave drop', function () { $(this).removeClass('surukle'); })
        .on('drop', function (e) { e.preventDefault(); gorselEkle(e.originalEvent.dataTransfer.files); });
    $(document).on('click', '.btn-gorsel-sil', function () {
        bekleyenGorseller.splice(parseInt($(this).data('i'), 10), 1);
        gorselOnizlemeCiz();
    });

    $(document).on('click', '.btn-taslak-onizle', function () { taslakOnizle($(this).closest('.ai-taslak')); });

    $(document).on('click', '.btn-geri-al', function () {
        const id = $(this).data('id');
        Swal.fire({
            title: 'Değişiklik geri alınsın mı?',
            text: 'Bu adımda değişen dosyalar ilk haline döner, yeni oluşturulan dosyalar silinir.',
            icon: 'warning', showCancelButton: true, confirmButtonText: 'Evet, geri al', cancelButtonText: 'Vazgeç'
        }).then(function (r) {
            if (!r.isConfirmed) return;
            postAjax({ action: 'geri_al', ticket_id: TICKET_ID, degisiklik_id: id }, function (res) {
                if (!res.success) { Swal.fire('Uyarı', res.message, 'warning'); return; }
                toast('success', res.message);
                sohbetYukle(false);
            });
        });
    });

    $('#btnAnaliz').on('click', function () {
        const yeniden = $('#btnAnaliz span').text().startsWith('Yeniden');
        const baslat = function () {
            postAjax({ action: 'analiz_baslat', ticket_id: TICKET_ID }, function (res) {
                if (!res.success) { Swal.fire('Uyarı', res.message, 'warning'); return; }
                toast('success', res.message);
                sonId = 0; aracGrubu = null;
                $('#sohbetAlani').empty();
                sohbetYukle(true);
                istatistikYukle();
            });
        };
        if (!yeniden) { baslat(); return; }
        Swal.fire({
            title: 'Yeniden analiz edilsin mi?',
            text: 'Mevcut sohbet arşivlenir, ticket sıfırdan analiz edilir.',
            icon: 'question', showCancelButton: true, confirmButtonText: 'Evet, başlat', cancelButtonText: 'Vazgeç'
        }).then(function (r) { if (r.isConfirmed) baslat(); });
    });
});
</script>
</body>
</html>
