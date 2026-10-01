<?php
/**
 * Admin Panel - Cron Yönetimi
 *
 * Görevler (kart + iç zamanlayıcı tablosu), çalışma geçmişi ve sıklık şablonları.
 * İş mantığı admin/cron/tasks.php içindedir; bu sayfa arayüz + AJAX katmanıdır.
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
require_once __DIR__ . '/../cron/tasks.php';
requireAuth();

$user = Auth::user();
$db   = Database::getInstance();

$currentPageFile = basename($_SERVER['PHP_SELF']);
$yetki = PageAuth::checkPagePermissions($user['kullanici_id'], $user['departman_id'], $currentPageFile);

if (empty($yetki['has_access'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Bu sayfaya erişim yetkiniz yok.']);
        exit;
    }
    PageAuth::accessDenied($yetki['error'] ?? 'Bu sayfaya erişim yetkiniz bulunmamaktadır.');
}

$pageInfo = $db->fetchOne("
    SELECT s.sayfalar_sayfa_adi, m.menuler_menu_adi AS menu_adi
    FROM Menu_Sayfalar s
    LEFT JOIN Menuler m ON s.sayfalar_menu_id = m.menuler_id
    WHERE s.sayfalar_sayfa_url LIKE ? AND s.sayfalar_durum = 1
", ['%' . $currentPageFile]);

$pageTitle = $pageInfo['sayfalar_sayfa_adi'] ?? 'Cron Yönetimi';
$menuAdi   = $pageInfo['menu_adi']           ?? null;

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle    = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';

// =====================================================================
// AJAX İşlemleri
// =====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');

    $simdi     = app_now();
    $kullanici = (int)$user['kullanici_id'];

    try {
        $action = $_POST['action'] ?? '';

        /* ----------------------------------------------------------------
           InfoBox istatistikleri (bugün)
           ---------------------------------------------------------------- */
        if ($action === 'istatistik') {
            $g = $db->fetchOne("SELECT COUNT(*) AS sayi FROM dbo.CronGorevler WHERE Durum = 1");
            $z = $db->fetchOne("SELECT COUNT(*) AS sayi FROM dbo.CronZamanlamalar WHERE Durum = 1");
            $l = $db->fetchOne("
                SELECT
                    SUM(CASE WHEN CronCalismaLog_CalismaDurum = 1 THEN 1 ELSE 0 END) AS basarili,
                    SUM(CASE WHEN CronCalismaLog_CalismaDurum = 2 THEN 1 ELSE 0 END) AS hatali,
                    SUM(CASE WHEN CronCalismaLog_CalismaDurum = 0 THEN 1 ELSE 0 END) AS calisan
                FROM dbo.CronCalismaLog
                WHERE CAST(CronCalismaLog_BaslangicTarihi AS DATE) = CAST(GETDATE() AS DATE)
            ");

            echo json_encode(['success' => true, 'data' => [
                'gorev'     => (int)($g['sayi'] ?? 0),
                'zamanlama' => (int)($z['sayi'] ?? 0),
                'basarili'  => (int)($l['basarili'] ?? 0),
                'hatali'    => (int)($l['hatali'] ?? 0),
                'calisan'   => (int)($l['calisan'] ?? 0),
            ]]);
            exit;
        }

        /* ----------------------------------------------------------------
           GÖREVLER + ZAMANLAYICILAR (kart görünümü tek istekte beslenir)
           ---------------------------------------------------------------- */
        if ($action === 'gorev_liste') {
            $gorevler = $db->fetchAll("
                SELECT g.CronGorevler_Id, g.CronGorevler_Ad, g.CronGorevler_Aciklama,
                       g.CronGorevler_GorevKodu, g.CronGorevler_Parametreler,
                       g.CronGorevler_MaxSureSn, g.Durum
                FROM dbo.CronGorevler g
                WHERE g.Durum = 1
                ORDER BY g.CronGorevler_Ad
            ");

            $zamanlamalar = $db->fetchAll("
                SELECT z.CronZamanlamalar_Id, z.CronZamanlamalar_GorevId, z.CronZamanlamalar_Ad,
                       z.CronZamanlamalar_CronIfadesi, z.CronZamanlamalar_SabitParametreler,
                       z.CronZamanlamalar_TelafiEt, z.CronZamanlamalar_TelafiSaatSiniri, z.Durum,
                       CONVERT(VARCHAR(19), z.CronZamanlamalar_BaslangicTarihi, 120) AS BaslangicTarihi,
                       CONVERT(VARCHAR(19), z.CronZamanlamalar_BitisTarihi, 120)     AS BitisTarihi,
                       CONVERT(VARCHAR(19), z.CronZamanlamalar_SonCalisma, 120)      AS SonCalisma,
                       (SELECT TOP 1 l.CronCalismaLog_CalismaDurum FROM dbo.CronCalismaLog l
                         WHERE l.CronCalismaLog_ZamanlamaId = z.CronZamanlamalar_Id
                         ORDER BY l.CronCalismaLog_Id DESC) AS SonDurum,
                       (SELECT TOP 1 l.CronCalismaLog_SureSaniye FROM dbo.CronCalismaLog l
                         WHERE l.CronCalismaLog_ZamanlamaId = z.CronZamanlamalar_Id
                         ORDER BY l.CronCalismaLog_Id DESC) AS SonSure
                FROM dbo.CronZamanlamalar z
                WHERE z.Durum IN (0, 1)
                ORDER BY z.CronZamanlamalar_Id
            ");

            echo json_encode(['success' => true, 'gorevler' => $gorevler, 'zamanlamalar' => $zamanlamalar]);
            exit;
        }

        if ($action === 'gorev_kaydet') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0 && empty($yetki['can_edit']))  throw new Exception('Düzenleme yetkiniz yok.');
            if ($id === 0 && empty($yetki['can_add'])) throw new Exception('Ekleme yetkiniz yok.');

            $ad       = trim($_POST['ad'] ?? '');
            $kod      = strtolower(trim($_POST['kod'] ?? ''));
            $aciklama = trim($_POST['aciklama'] ?? '');
            $maxSure  = (int)($_POST['max_sure'] ?? 600);
            $paramlar = trim($_POST['parametreler'] ?? '');

            if ($ad === '')  throw new Exception('Görev adı zorunludur.');
            if (!preg_match('/^[a-z0-9_]+$/', $kod)) {
                throw new Exception('Görev kodu yalnızca küçük harf, rakam ve alt çizgi içerebilir.');
            }
            if ($maxSure < 10) throw new Exception('Maksimum süre en az 10 saniye olmalıdır.');
            if ($paramlar !== '' && json_decode($paramlar, true) === null) {
                throw new Exception('Parametre şeması geçerli bir JSON değil.');
            }

            $cakisma = $db->fetchOne(
                "SELECT CronGorevler_Id FROM dbo.CronGorevler WHERE CronGorevler_GorevKodu = ? AND CronGorevler_Id <> ?",
                [$kod, $id]
            );
            if ($cakisma) throw new Exception('Bu görev kodu zaten kullanılıyor.');

            $veri = [
                'CronGorevler_Ad'           => $ad,
                'CronGorevler_Aciklama'     => $aciklama !== '' ? $aciklama : null,
                'CronGorevler_GorevKodu'    => $kod,
                'CronGorevler_Parametreler' => $paramlar !== '' ? $paramlar : null,
                'CronGorevler_MaxSureSn'    => $maxSure,
                'GuncelleyenKullanici'      => $kullanici,
                'GuncellemeTarihi'          => $simdi,
            ];

            if ($id > 0) {
                $db->update('CronGorevler', $veri, ['CronGorevler_Id' => $id]);
            } else {
                $veri['Durum']              = 1;
                $veri['OlusturanKullanici'] = $kullanici;
                $veri['OlusturmaTarihi']    = $simdi;
                $db->insert('CronGorevler', $veri);
            }

            echo json_encode(['success' => true, 'message' => 'Görev kaydedildi.']);
            exit;
        }

        if ($action === 'gorev_sil') {
            if (empty($yetki['can_delete'])) throw new Exception('Silme yetkiniz yok.');
            $id = (int)($_POST['id'] ?? 0);

            $bagli = $db->fetchOne(
                "SELECT COUNT(*) AS sayi FROM dbo.CronZamanlamalar WHERE CronZamanlamalar_GorevId = ? AND Durum = 1",
                [$id]
            );
            if ((int)($bagli['sayi'] ?? 0) > 0) {
                throw new Exception('Bu göreve bağlı aktif zamanlayıcı var, önce onları kaldırın.');
            }

            $db->update('CronGorevler', [
                'Durum'                => 0,
                'GuncelleyenKullanici' => $kullanici,
                'GuncellemeTarihi'     => $simdi,
            ], ['CronGorevler_Id' => $id]);

            echo json_encode(['success' => true, 'message' => 'Görev kaldırıldı.']);
            exit;
        }

        /* ----------------------------------------------------------------
           ZAMANLAYICILAR
           ---------------------------------------------------------------- */
        if ($action === 'zamanlama_kaydet') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0 && empty($yetki['can_edit']))  throw new Exception('Düzenleme yetkiniz yok.');
            if ($id === 0 && empty($yetki['can_add'])) throw new Exception('Ekleme yetkiniz yok.');

            $gorevId    = (int)($_POST['gorev_id'] ?? 0);
            $ad         = trim($_POST['ad'] ?? '');
            $ifade      = trim($_POST['cron'] ?? '');
            $baslangic  = trim($_POST['baslangic'] ?? '');
            $bitis      = trim($_POST['bitis'] ?? '');
            $telafi     = isset($_POST['telafi']) ? 1 : 0;
            $telafiSaat = (int)($_POST['telafi_saat'] ?? 6);

            if ($gorevId <= 0) throw new Exception('Görev bulunamadı.');
            if ($ad === '')    throw new Exception('Zamanlayıcı adı zorunludur.');
            if (!cronIfadeGecerliMi($ifade)) {
                throw new Exception('Cron ifadesi geçersiz. 5 alan bekleniyor (dk sa gün ay hafta_günü).');
            }
            if ($telafiSaat < 1) $telafiSaat = 1;

            // Sabit parametreler görevin şemasına göre doğrulanır (JSON elle yazılmaz)
            $gorev = $db->fetchOne("SELECT CronGorevler_Parametreler FROM dbo.CronGorevler WHERE CronGorevler_Id = ?", [$gorevId]);
            $gelen = json_decode($_POST['parametreler'] ?? '{}', true) ?: [];
            $params = cronParamDogrula($gorev['CronGorevler_Parametreler'] ?? null, $gelen);

            $veri = [
                'CronZamanlamalar_GorevId'           => $gorevId,
                'CronZamanlamalar_Ad'                => $ad,
                'CronZamanlamalar_CronIfadesi'       => $ifade,
                'CronZamanlamalar_BaslangicTarihi'   => $baslangic !== '' ? str_replace('T', ' ', $baslangic) : $simdi,
                'CronZamanlamalar_BitisTarihi'       => $bitis !== '' ? str_replace('T', ' ', $bitis) : null,
                'CronZamanlamalar_SabitParametreler' => $params ? json_encode($params, JSON_UNESCAPED_UNICODE) : null,
                'CronZamanlamalar_TelafiEt'          => $telafi,
                'CronZamanlamalar_TelafiSaatSiniri'  => $telafiSaat,
                'GuncelleyenKullanici'               => $kullanici,
                'GuncellemeTarihi'                   => $simdi,
            ];

            if ($id > 0) {
                $db->update('CronZamanlamalar', $veri, ['CronZamanlamalar_Id' => $id]);
            } else {
                $veri['Durum']              = 1;
                $veri['OlusturanKullanici'] = $kullanici;
                $veri['OlusturmaTarihi']    = $simdi;
                $db->insert('CronZamanlamalar', $veri);
            }

            echo json_encode(['success' => true, 'message' => 'Zamanlayıcı kaydedildi.']);
            exit;
        }

        // Duraklat / devam ettir
        if ($action === 'zamanlama_durum') {
            if (empty($yetki['can_edit'])) throw new Exception('Düzenleme yetkiniz yok.');
            $id    = (int)($_POST['id'] ?? 0);
            $durum = (int)($_POST['durum'] ?? 0) === 1 ? 1 : 0;

            $db->update('CronZamanlamalar', [
                'Durum'                => $durum,
                'GuncelleyenKullanici' => $kullanici,
                'GuncellemeTarihi'     => $simdi,
            ], ['CronZamanlamalar_Id' => $id]);

            echo json_encode(['success' => true, 'message' => $durum ? 'Zamanlayıcı devam ediyor.' : 'Zamanlayıcı duraklatıldı.']);
            exit;
        }

        if ($action === 'zamanlama_sil') {
            if (empty($yetki['can_delete'])) throw new Exception('Silme yetkiniz yok.');
            $id = (int)($_POST['id'] ?? 0);

            $db->delete('CronZamanlamalar', ['CronZamanlamalar_Id' => $id]);
            echo json_encode(['success' => true, 'message' => 'Zamanlayıcı silindi.']);
            exit;
        }

        /* ----------------------------------------------------------------
           PARAMETRE SEÇENEK KAYNAKLARI
           Şemada tip=select|multiselect olan alanların dropdown içerikleri.
           ---------------------------------------------------------------- */
        if ($action === 'param_kaynaklar') {
            $sonuc = [];
            foreach (array_keys(cronParamKaynaklari()) as $anahtar) {
                $sonuc[$anahtar] = cronParamSecenek($db, $anahtar);
            }
            echo json_encode(['success' => true, 'data' => $sonuc]);
            exit;
        }

        /* ----------------------------------------------------------------
           SIKLIK ŞABLONLARI
           ---------------------------------------------------------------- */
        if ($action === 'sablon_liste') {
            $satirlar = $db->fetchAll("
                SELECT CronSiklikSablonlari_Id, CronSiklikSablonlari_Etiket,
                       CronSiklikSablonlari_Ifade, CronSiklikSablonlari_Sira, Durum,
                       (SELECT COUNT(*) FROM dbo.CronZamanlamalar z
                         WHERE z.CronZamanlamalar_CronIfadesi = CronSiklikSablonlari_Ifade AND z.Durum = 1) AS KullanimAdedi
                FROM dbo.CronSiklikSablonlari
                ORDER BY CronSiklikSablonlari_Sira, CronSiklikSablonlari_Id
            ");
            echo json_encode(['success' => true, 'data' => $satirlar]);
            exit;
        }

        if ($action === 'sablon_kaydet') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0 && empty($yetki['can_edit']))  throw new Exception('Düzenleme yetkiniz yok.');
            if ($id === 0 && empty($yetki['can_add'])) throw new Exception('Ekleme yetkiniz yok.');

            $etiket = trim($_POST['etiket'] ?? '');
            $ifade  = trim($_POST['ifade'] ?? '');
            $sira   = (int)($_POST['sira'] ?? 0);

            if ($etiket === '') throw new Exception('Etiket zorunludur.');
            if (!cronIfadeGecerliMi($ifade)) throw new Exception('Cron ifadesi geçersiz.');

            $veri = [
                'CronSiklikSablonlari_Etiket' => $etiket,
                'CronSiklikSablonlari_Ifade'  => $ifade,
                'CronSiklikSablonlari_Sira'   => $sira,
                'Durum'                       => isset($_POST['durum']) ? 1 : 0,
                'GuncelleyenKullanici'        => $kullanici,
                'GuncellemeTarihi'            => $simdi,
            ];

            if ($id > 0) {
                $db->update('CronSiklikSablonlari', $veri, ['CronSiklikSablonlari_Id' => $id]);
            } else {
                $veri['OlusturanKullanici'] = $kullanici;
                $veri['OlusturmaTarihi']    = $simdi;
                $db->insert('CronSiklikSablonlari', $veri);
            }

            echo json_encode(['success' => true, 'message' => 'Şablon kaydedildi.']);
            exit;
        }

        if ($action === 'sablon_sil') {
            if (empty($yetki['can_delete'])) throw new Exception('Silme yetkiniz yok.');
            $id = (int)($_POST['id'] ?? 0);

            $db->update('CronSiklikSablonlari', [
                'Durum'                => 0,
                'GuncelleyenKullanici' => $kullanici,
                'GuncellemeTarihi'     => $simdi,
            ], ['CronSiklikSablonlari_Id' => $id]);

            echo json_encode(['success' => true, 'message' => 'Şablon pasife alındı.']);
            exit;
        }

        /* ----------------------------------------------------------------
           ÇALIŞMA GEÇMİŞİ
           ---------------------------------------------------------------- */
        if ($action === 'log_liste') {
            $kosul  = ['1 = 1'];
            $params = [];

            if (!empty($_POST['gorev_id'])) {
                $kosul[]  = 'l.CronCalismaLog_GorevId = ?';
                $params[] = (int)$_POST['gorev_id'];
            }
            if (isset($_POST['calisma_durum']) && $_POST['calisma_durum'] !== '') {
                $kosul[]  = 'l.CronCalismaLog_CalismaDurum = ?';
                $params[] = (int)$_POST['calisma_durum'];
            }
            if (isset($_POST['tetik_tur']) && $_POST['tetik_tur'] !== '') {
                $kosul[]  = 'l.CronCalismaLog_TetikleyenTur = ?';
                $params[] = (int)$_POST['tetik_tur'];
            }
            if (!empty($_POST['tarih_bas'])) {
                $kosul[]  = 'l.CronCalismaLog_BaslangicTarihi >= ?';
                $params[] = $_POST['tarih_bas'] . ' 00:00:00';
            }
            if (!empty($_POST['tarih_bit'])) {
                $kosul[]  = 'l.CronCalismaLog_BaslangicTarihi <= ?';
                $params[] = $_POST['tarih_bit'] . ' 23:59:59';
            }

            // Log tablosu büyür: panelde en fazla 500 satır gösterilir
            $satirlar = $db->fetchAll("
                SELECT TOP 500
                       l.CronCalismaLog_Id, l.CronCalismaLog_SureSaniye, l.CronCalismaLog_CalismaDurum,
                       l.CronCalismaLog_TetikleyenTur, l.CronCalismaLog_Sonuc,
                       CONVERT(VARCHAR(19), l.CronCalismaLog_BaslangicTarihi, 120) AS Baslangic,
                       g.CronGorevler_Ad, g.CronGorevler_GorevKodu,
                       z.CronZamanlamalar_Ad,
                       k.kullanici_ad, k.kullanici_soyad
                FROM dbo.CronCalismaLog l
                INNER JOIN dbo.CronGorevler g ON g.CronGorevler_Id = l.CronCalismaLog_GorevId
                LEFT JOIN dbo.CronZamanlamalar z ON z.CronZamanlamalar_Id = l.CronCalismaLog_ZamanlamaId
                LEFT JOIN kullanicilar k ON k.kullanici_id = l.CronCalismaLog_TetikleyenKullanici
                WHERE " . implode(' AND ', $kosul) . "
                ORDER BY l.CronCalismaLog_Id DESC
            ", $params);

            echo json_encode(['success' => true, 'data' => $satirlar]);
            exit;
        }

        if ($action === 'log_detay') {
            $id = (int)($_POST['id'] ?? 0);
            $satir = $db->fetchOne("
                SELECT l.*, g.CronGorevler_Ad, g.CronGorevler_GorevKodu,
                       CONVERT(VARCHAR(19), l.CronCalismaLog_BaslangicTarihi, 120) AS Baslangic,
                       CONVERT(VARCHAR(19), l.CronCalismaLog_BitisTarihi, 120)     AS Bitis
                FROM dbo.CronCalismaLog l
                INNER JOIN dbo.CronGorevler g ON g.CronGorevler_Id = l.CronCalismaLog_GorevId
                WHERE l.CronCalismaLog_Id = ?
            ", [$id]);

            if (!$satir) throw new Exception('Log kaydı bulunamadı.');

            // sqlsrv DATETIME'ı nesne döndürür; JSON'a metin olarak yaz
            foreach ($satir as $k => $v) {
                if ($v instanceof DateTime) $satir[$k] = $v->format('Y-m-d H:i:s');
            }

            echo json_encode(['success' => true, 'data' => $satir]);
            exit;
        }

        /* ----------------------------------------------------------------
           MANUEL ÇALIŞTIRMA
           ---------------------------------------------------------------- */
        if ($action === 'tetikle') {
            if (empty($yetki['can_edit'])) throw new Exception('Manuel çalıştırma yetkiniz yok.');

            $gorevId     = (int)($_POST['gorev_id'] ?? 0);
            $zamanlamaId = (int)($_POST['zamanlama_id'] ?? 0);

            if ($zamanlamaId > 0) {
                $z = $db->fetchOne("
                    SELECT z.CronZamanlamalar_GorevId, z.CronZamanlamalar_SabitParametreler,
                           g.CronGorevler_GorevKodu, g.CronGorevler_MaxSureSn
                    FROM dbo.CronZamanlamalar z
                    INNER JOIN dbo.CronGorevler g ON g.CronGorevler_Id = z.CronZamanlamalar_GorevId
                    WHERE z.CronZamanlamalar_Id = ? AND g.Durum = 1
                ", [$zamanlamaId]);
                if (!$z) throw new Exception('Zamanlayıcı bulunamadı.');

                $gorevId   = (int)$z['CronZamanlamalar_GorevId'];
                $gorevKodu = (string)$z['CronGorevler_GorevKodu'];
                $maxSureSn = (int)$z['CronGorevler_MaxSureSn'];
                $params    = dinamikParamCoz(json_decode($z['CronZamanlamalar_SabitParametreler'] ?? '{}', true) ?: []);
            } else {
                $g = $db->fetchOne("SELECT * FROM dbo.CronGorevler WHERE CronGorevler_Id = ? AND Durum = 1", [$gorevId]);
                if (!$g) throw new Exception('Görev bulunamadı veya pasif.');

                $gorevKodu   = (string)$g['CronGorevler_GorevKodu'];
                $maxSureSn   = (int)$g['CronGorevler_MaxSureSn'];
                $zamanlamaId = 0;

                $gelen  = json_decode($_POST['parametreler'] ?? '{}', true) ?: [];
                $params = dinamikParamCoz(cronParamDogrula($g['CronGorevler_Parametreler'], $gelen));
            }

            // Overlap koruması: aynı görev çalışıyorken ikinci tetiğe izin verilmez
            $acik = cronCalisanKayit($db, $gorevId, $maxSureSn);
            if ($acik !== null) {
                throw new Exception('Bu görev şu anda çalışıyor (başlangıç: ' . $acik['Baslangic'] . ').');
            }

            ignore_user_abort(true);
            set_time_limit($maxSureSn);

            $logId = cronLogOlustur($db, $gorevId, $zamanlamaId ?: null, $params, 0, $kullanici);
            $bas   = microtime(true);

            try {
                $sonuc = gorevCalistir($gorevKodu, $params, $db);
                cronLogBitir($db, $logId, $sonuc['durum'], $sonuc['sonuc'], $bas, $sonuc['cikti']);

                echo json_encode([
                    'success' => true,
                    'durum'   => $sonuc['durum'],
                    'message' => $sonuc['sonuc'],
                    'cikti'   => $sonuc['cikti'],
                    'sure'    => round(microtime(true) - $bas, 2),
                    'log_id'  => $logId,
                ]);
                exit;
            } catch (Throwable $e) {
                cronLogBitir($db, $logId, 2, 'HATA: ' . get_class($e) . ': ' . $e->getMessage(), $bas);
                error_log('Cron panel tetik hatasi (' . $gorevKodu . '): ' . $e->getMessage());
                throw new Exception($e->getMessage());
            }
        }

        throw new Exception('Geçersiz işlem.');

    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

// =====================================================================
// Sayfa verileri
// =====================================================================
$gorevSecenekleri = $db->fetchAll("
    SELECT CronGorevler_Id AS id, CronGorevler_Ad AS ad, CronGorevler_GorevKodu AS kod, CronGorevler_MaxSureSn AS max_sure
    FROM dbo.CronGorevler
    WHERE Durum = 1
    ORDER BY CronGorevler_Ad
");

$runnerYol = str_replace('/', '\\', realpath(__DIR__ . '/../cron/runner.php') ?: '');
$phpYol    = 'C:\Program Files (x86)\Plesk\Additional\PleskPHP83\php.exe';
$pleskCli  = '"' . $phpYol . '" -f "' . $runnerYol . '"';
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
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/admin/assets/css/custom.css">

    <style>
        .nav-tabs .nav-link { cursor: pointer; }
        .cron-kod {
            font-family: var(--bs-font-monospace); font-size: .8rem;
            background: var(--bs-tertiary-bg); padding: .1rem .4rem; border-radius: .25rem;
        }
        .cron-ifade { font-family: var(--bs-font-monospace); font-size: .85rem; color: var(--bs-danger); letter-spacing: .05rem; }
        .gorev-karti .card-header { background: var(--bs-body-bg); }
        .gorev-karti .table { margin-bottom: 0; }
        .cikti-kutu {
            background: #1e1e1e; color: #d4d4d4; padding: 1rem; border-radius: .375rem;
            max-height: 420px; overflow: auto; font-size: .8rem; white-space: pre-wrap; word-break: break-word;
        }
        .plesk-satir { font-family: var(--bs-font-monospace); font-size: .78rem; word-break: break-all; }
        .stat-kart { border: 0; border-radius: .5rem; color: #fff; }
        .stat-kart .stat-ikon { font-size: 1.9rem; opacity: .9; }
        .stat-kart .stat-sayi { font-size: 1.75rem; font-weight: 600; line-height: 1.1; }
        .dinamik-yardim { background: var(--bs-tertiary-bg); border-radius: .375rem; padding: .6rem .75rem; font-size: .8rem; }
        .dinamik-yardim .cron-kod { color: var(--bs-danger); }
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
                        <h3 class="mb-0"><?= htmlspecialchars($pageTitle) ?></h3>
                    </div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-end">
                            <?php if ($menuAdi): ?>
                            <li class="breadcrumb-item"><?= htmlspecialchars($menuAdi) ?></li>
                            <?php endif; ?>
                            <li class="breadcrumb-item active"><?= htmlspecialchars($pageTitle) ?></li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>

        <div class="app-content">
            <div class="container-fluid">

                <!-- Plesk Cron tek satır -->
                <div class="card mb-3">
                    <div class="card-body py-2">
                        <div class="d-flex align-items-center flex-wrap gap-2">
                            <span class="fw-semibold text-nowrap">
                                <i class="bi bi-gear-fill text-primary me-1"></i> Plesk Cron (Tek Satır):
                            </span>
                            <span class="cron-ifade">* * * * *</span>
                            <div class="input-group input-group-sm flex-grow-1" style="min-width: 260px;">
                                <input type="text" class="form-control plesk-satir" id="plesk-satir" readonly value="<?= htmlspecialchars($pleskCli) ?>">
                                <button class="btn btn-outline-secondary" id="btnKopyala" title="Panoya kopyala">
                                    <i class="bi bi-clipboard"></i>
                                </button>
                            </div>
                        </div>
                        <div class="form-text mt-1">
                            Plesk'te <strong>Komut çalıştır</strong> tipiyle, her dakika çalışacak tek görev olarak tanımlanır.
                        </div>
                    </div>
                </div>

                <!-- InfoBox -->
                <div class="row g-3 mb-3">
                    <div class="col-12 col-sm-6 col-xl-3">
                        <div class="card stat-kart bg-primary h-100">
                            <div class="card-body d-flex align-items-center gap-3">
                                <i class="bi bi-list-task stat-ikon"></i>
                                <div>
                                    <div class="small">Tanımlı Görev</div>
                                    <div class="stat-sayi" id="stat-gorev">-</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-xl-3">
                        <div class="card stat-kart bg-info h-100">
                            <div class="card-body d-flex align-items-center gap-3">
                                <i class="bi bi-clock-history stat-ikon"></i>
                                <div>
                                    <div class="small">Aktif Zamanlayıcı</div>
                                    <div class="stat-sayi" id="stat-zamanlama">-</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-xl-3">
                        <div class="card stat-kart bg-success h-100">
                            <div class="card-body d-flex align-items-center gap-3">
                                <i class="bi bi-check-circle stat-ikon"></i>
                                <div>
                                    <div class="small">Bugün Başarılı</div>
                                    <div class="stat-sayi" id="stat-basarili">-</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-xl-3">
                        <div class="card stat-kart bg-danger h-100">
                            <div class="card-body d-flex align-items-center gap-3">
                                <i class="bi bi-x-circle stat-ikon"></i>
                                <div>
                                    <div class="small">Bugün Hatalı</div>
                                    <div class="stat-sayi" id="stat-hatali">-</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Sekmeler -->
                <ul class="nav nav-tabs mb-3" id="cron-tabs">
                    <li class="nav-item">
                        <a class="nav-link active" data-tip="gorev"><i class="bi bi-list-ul me-1"></i> Görevler</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" data-tip="log"><i class="bi bi-journal-text me-1"></i> Çalışma Geçmişi</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" data-tip="sablon"><i class="bi bi-folder me-1"></i> Sıklık Şablonları</a>
                    </li>
                </ul>

                <!-- ================= Görevler ================= -->
                <div id="bolum-gorev">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                        <div class="input-group input-group-sm" style="max-width: 320px;">
                            <span class="input-group-text"><i class="bi bi-search"></i></span>
                            <input type="text" class="form-control" id="gorev-arama" placeholder="Görev adı veya kodu ara">
                        </div>
                        <div class="d-flex gap-2">
                            <button class="btn btn-outline-secondary btn-sm" id="btnGorevYenile">
                                <i class="bi bi-arrow-clockwise"></i> Yenile
                            </button>
                            <?php if (!empty($yetki['can_add'])): ?>
                            <button class="btn btn-primary btn-sm" id="btnYeniGorev">
                                <i class="bi bi-plus-lg"></i> Yeni Görev
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div id="gorev-listesi"></div>
                </div>

                <!-- ================= Çalışma Geçmişi ================= -->
                <div id="bolum-log" class="d-none">
                    <div class="card mb-3">
                        <div class="card-header" style="cursor:pointer;" data-bs-toggle="collapse" data-bs-target="#filtrePanel">
                            <h5 class="card-title mb-0">
                                <i class="bi bi-funnel me-1"></i> Filtrele
                                <i class="bi bi-chevron-down float-end"></i>
                            </h5>
                        </div>
                        <div class="collapse" id="filtrePanel">
                            <div class="card-body">
                                <div class="row g-2">
                                    <div class="col-12 col-md-3">
                                        <label class="form-label">Arama (Görev / Sonuç)</label>
                                        <input type="text" id="filtre-arama" class="form-control">
                                    </div>
                                    <div class="col-12 col-md-3">
                                        <label class="form-label">Görev</label>
                                        <select id="filtre-gorev" class="form-select select2-basic">
                                            <option value="">Tümü</option>
                                            <?php foreach ($gorevSecenekleri as $g): ?>
                                                <option value="<?= (int)$g['id'] ?>"><?= htmlspecialchars($g['ad']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-6 col-md-2">
                                        <label class="form-label">Çalışma Durumu</label>
                                        <select id="filtre-calisma" class="form-select select2-basic">
                                            <option value="">Tümü</option>
                                            <option value="1">Başarılı</option>
                                            <option value="2">Hata</option>
                                            <option value="0">Çalışıyor</option>
                                        </select>
                                    </div>
                                    <div class="col-6 col-md-2">
                                        <label class="form-label">Tetikleyen</label>
                                        <select id="filtre-tetik" class="form-select select2-basic">
                                            <option value="">Tümü</option>
                                            <option value="1">Otomatik / CLI</option>
                                            <option value="0">Manuel / Panel</option>
                                        </select>
                                    </div>
                                    <div class="col-6 col-md-1">
                                        <label class="form-label">Başlangıç</label>
                                        <input type="date" id="filtre-tarih-bas" class="form-control">
                                    </div>
                                    <div class="col-6 col-md-1">
                                        <label class="form-label">Bitiş</label>
                                        <input type="date" id="filtre-tarih-bit" class="form-control">
                                    </div>
                                </div>
                                <div class="mt-3 d-flex gap-2">
                                    <button class="btn btn-primary btn-sm" id="btnFiltrele">
                                        <i class="bi bi-search me-1"></i> Filtrele
                                    </button>
                                    <button class="btn btn-outline-secondary btn-sm" id="btnFiltreTemizle">
                                        <i class="bi bi-x-circle me-1"></i> Temizle
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-body">
                            <table class="table table-hover table-sm w-100" id="logTable">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Görev</th>
                                        <th>Zamanlayıcı</th>
                                        <th>Başlangıç</th>
                                        <th>Süre</th>
                                        <th>Durum</th>
                                        <th>Tetikleyen</th>
                                        <th>Sonuç</th>
                                        <th>İşlem</th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- ================= Sıklık Şablonları ================= -->
                <div id="bolum-sablon" class="d-none">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="card-title mb-0">Sıklık Şablonları</h5>
                            <?php if (!empty($yetki['can_add'])): ?>
                            <button class="btn btn-primary btn-sm" id="btnYeniSablon">
                                <i class="bi bi-plus-lg"></i> Yeni Şablon
                            </button>
                            <?php endif; ?>
                        </div>
                        <div class="card-body">
                            <p class="text-muted small">
                                Zamanlayıcı penceresindeki hazır sıklık listesi buradan yönetilir; değerler koda gömülü değildir.
                            </p>
                            <table class="table table-hover table-sm w-100" id="sablonTable">
                                <thead>
                                    <tr>
                                        <th>Etiket</th>
                                        <th>Cron İfadesi</th>
                                        <th>Sıra</th>
                                        <th>Kullanım</th>
                                        <th>Durum</th>
                                        <th>İşlem</th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </main>

    <?php include __DIR__ . '/../includes/footer.php'; ?>
</div>

<!-- ================= Zamanlayıcı Modalı ================= -->
<div class="modal fade" id="modalZamanlama" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-clock me-2"></i><span id="zamanlama-modal-baslik">Zamanlayıcı Ekle</span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="z_id">
                <input type="hidden" id="z_gorev_id">

                <div class="mb-3">
                    <label class="form-label">Zamanlayıcı Adı <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="z_ad">
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label">Sıklık Şablonu</label>
                        <select class="form-select select2-modal" id="z_sablon"></select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Cron İfadesi <span class="text-danger">*</span></label>
                        <input type="text" class="form-control cron-ifade" id="z_cron" placeholder="*/15 * * * *">
                        <div class="form-text">Dakika Saat GünAyı AySayısı HaftaGünü (0=Paz)</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Başlangıç Tarihi <span class="text-danger">*</span></label>
                        <input type="datetime-local" class="form-control" id="z_baslangic">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Bitiş Tarihi <span class="text-muted">(boş = sürekli)</span></label>
                        <input type="datetime-local" class="form-control" id="z_bitis">
                    </div>
                </div>

                <hr>
                <h6 class="fw-semibold">Sabit Parametreler</h6>
                <div id="z_parametreler" class="row g-3"></div>

                <div class="dinamik-yardim mt-3">
                    <strong>Dinamik Tarih Değerleri:</strong>
                    <span class="cron-kod">{bugun}</span> Bugün
                    <span class="cron-kod">{dun}</span> Dün
                    <span class="cron-kod">{7gun_once}</span> 7 gün önce
                    <span class="cron-kod">{30gun_once}</span> 30 gün önce
                    <span class="cron-kod">{ay_basi}</span> Ayın 1'i
                    <span class="cron-kod">{ay_sonu}</span> Ay sonu
                    <span class="cron-kod">{gecen_ay_basi}</span> Geçen ay başı
                    <span class="cron-kod">{gecen_ay_sonu}</span> Geçen ay sonu
                    <span class="cron-kod">{3ay_once}</span> 3 ay önce
                    <span class="cron-kod">{6ay_once}</span> 6 ay önce
                </div>

                <hr>
                <div class="row g-3 align-items-end">
                    <div class="col-md-6">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" role="switch" id="z_telafi" value="1">
                            <label class="form-check-label" for="z_telafi">Kaçırılan tetiği telafi et</label>
                        </div>
                        <div class="form-text">Günlük/haftalık görevlerde açın, dakikalık görevlerde kapalı bırakın.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Telafi Saat Sınırı</label>
                        <input type="number" class="form-control" id="z_telafi_saat" value="6" min="1">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">İptal</button>
                <button type="button" class="btn btn-primary btn-sm" id="btnZamanlamaKaydet">
                    <i class="bi bi-save me-1"></i> Kaydet
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ================= Görev Modalı ================= -->
<div class="modal fade" id="modalGorev" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="gorev-modal-baslik">Yeni Görev</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="g_id">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Görev Adı <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="g_ad">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Görev Kodu <span class="text-danger">*</span></label>
                        <input type="text" class="form-control cron-ifade" id="g_kod" placeholder="ornek_gorev_kodu">
                        <div class="form-text">
                            <span class="cron-kod">admin/cron/gorevler/&lt;kod&gt;.php</span> içindeki
                            <span class="cron-kod">gorev_&lt;kod&gt;()</span> fonksiyonuna karşılık gelir.
                        </div>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Açıklama</label>
                        <input type="text" class="form-control" id="g_aciklama">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Maksimum Süre (saniye)</label>
                        <input type="number" class="form-control" id="g_max_sure" value="600" min="10">
                        <div class="form-text">Zombi eşiği bu değerin 1.5 katıdır.</div>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Parametre Şeması (JSON)</label>
                        <textarea class="form-control cron-ifade" id="g_parametreler" rows="4"
                                  placeholder='[{"ad":"gun","etiket":"Gün","zorunlu":false,"tip":"number","varsayilan":90}]'></textarea>
                        <div class="form-text">Zamanlayıcı penceresindeki parametre alanları bu şemadan üretilir.</div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">İptal</button>
                <button type="button" class="btn btn-primary btn-sm" id="btnGorevKaydet">Kaydet</button>
            </div>
        </div>
    </div>
</div>

<!-- ================= Şablon Modalı ================= -->
<div class="modal fade" id="modalSablon" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="sablon-modal-baslik">Yeni Şablon</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="s_id">
                <div class="mb-3">
                    <label class="form-label">Etiket <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="s_etiket" placeholder="Her 10 dakika">
                </div>
                <div class="mb-3">
                    <label class="form-label">Cron İfadesi <span class="text-danger">*</span></label>
                    <input type="text" class="form-control cron-ifade" id="s_ifade" placeholder="*/10 * * * *">
                </div>
                <div class="mb-3">
                    <label class="form-label">Sıra</label>
                    <input type="number" class="form-control" id="s_sira" value="0">
                </div>
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" role="switch" id="s_durum" value="1" checked>
                    <label class="form-check-label" for="s_durum">Aktif</label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">İptal</button>
                <button type="button" class="btn btn-primary btn-sm" id="btnSablonKaydet">Kaydet</button>
            </div>
        </div>
    </div>
</div>

<!-- ================= Manuel Çalıştır Modalı ================= -->
<div class="modal fade" id="modalTetik" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="tetik-modal-baslik">Manuel Çalıştır</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="t_gorev_id">
                <input type="hidden" id="t_zamanlama_id">
                <div id="t_uyari" class="alert alert-warning py-2 small d-none"></div>
                <div id="t_parametreler" class="row g-3"></div>
                <div id="t_sonuc" class="mt-3 d-none">
                    <div id="t_sonuc_mesaj" class="alert py-2 small mb-2"></div>
                    <pre class="cikti-kutu mb-0" id="t_cikti"></pre>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Kapat</button>
                <button type="button" class="btn btn-success btn-sm" id="btnTetikCalistir">
                    <i class="bi bi-play-fill"></i> Çalıştır
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ================= Log Detay Modalı ================= -->
<div class="modal fade" id="modalLog" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Çalışma Detayı</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <dl class="row small mb-3" id="log-ozet"></dl>
                <label class="form-label">Çıktı</label>
                <pre class="cikti-kutu mb-0" id="log-cikti"></pre>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Kapat</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js" crossorigin="anonymous"></script>
<script src="/admin/assets/js/adminlte.min.js"></script>
<script src="/admin/assets/js/custom.js"></script>

<script>
const PAGE_PATH = window.location.pathname;
const YETKI = {
    ekle:  <?= !empty($yetki['can_add'])    ? 'true' : 'false' ?>,
    duzen: <?= !empty($yetki['can_edit'])   ? 'true' : 'false' ?>,
    sil:   <?= !empty($yetki['can_delete']) ? 'true' : 'false' ?>
};

let aktifTip     = 'gorev';
let logDT        = null;
let sablonDT     = null;
let gorevCache   = {};
let zamanlamaCache = {};
let sablonCache  = {};
let sablonSecenek = [];
let PARAM_KAYNAK = {};   // kaynak anahtarı -> [{deger, etiket}] (DB'den, sayfa açılışında bir kez)

function escHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function postAjax(data, callback) {
    $.post(PAGE_PATH, data, function (res) {
        if (typeof res === 'string') { try { res = JSON.parse(res); } catch (e) {} }
        callback(res);
    }).fail(function () { showToast('Sunucu bağlantı hatası.', 'error'); });
}

function durumRozet(durum) {
    if (durum === 1) return '<span class="badge text-bg-success">Başarılı</span>';
    if (durum === 2) return '<span class="badge text-bg-danger">Hata</span>';
    if (durum === 0) return '<span class="badge text-bg-warning">Çalışıyor</span>';
    return '<span class="text-muted">-</span>';
}

/* ===================== Başlangıç ===================== */
$(document).ready(function () {
    const ortak = {
        data: [],
        language: { url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json' },
        pageLength: 25,
        scrollX: true
    };

    logDT = $('#logTable').DataTable(Object.assign({}, ortak, {
        order: [[0, 'desc']],
        columnDefs: [{ targets: [5, 8], className: 'text-center' }, { targets: 8, orderable: false }]
    }));

    sablonDT = $('#sablonTable').DataTable(Object.assign({}, ortak, {
        order: [[2, 'asc']],
        columnDefs: [{ targets: [3, 4, 5], className: 'text-center' }, { targets: 5, orderable: false }]
    }));

    $('.select2-basic').select2({ theme: 'bootstrap-5', width: '100%' });
    $('#z_sablon').select2({ theme: 'bootstrap-5', width: '100%', dropdownParent: $('#modalZamanlama') });

    gorevListeYukle();
    sablonSecenekYukle();
    paramKaynakYukle();
    istatistikYukle();

    $('#cron-tabs .nav-link').on('click', function (e) {
        e.preventDefault();
        tipDegistir($(this).data('tip'));
    });

    // Plesk satırı
    $('#btnKopyala').on('click', function () {
        const el = document.getElementById('plesk-satir');
        el.select();
        navigator.clipboard.writeText(el.value).then(function () {
            showToast('Panoya kopyalandı.', 'success');
        });
    });

    // Görevler
    $('#btnGorevYenile').on('click', function () { gorevListeYukle(); istatistikYukle(); });
    $('#gorev-arama').on('keyup', gorevKartlariCiz);
    $('#btnYeniGorev').on('click', function () { gorevModalAc(null); });

    $('#gorev-listesi').on('click', '.btn-gorev-tetikle',   function () { tetikModalAc($(this).data('id'), 0); });
    $('#gorev-listesi').on('click', '.btn-gorev-duzenle',   function () { gorevModalAc($(this).data('id')); });
    $('#gorev-listesi').on('click', '.btn-gorev-sil',       function () { gorevSil($(this).data('id')); });
    $('#gorev-listesi').on('click', '.btn-zamanlama-ekle',  function () { zamanlamaModalAc(null, $(this).data('gorev')); });
    $('#gorev-listesi').on('click', '.btn-zamanlama-duzenle', function () { zamanlamaModalAc($(this).data('id'), 0); });
    $('#gorev-listesi').on('click', '.btn-zamanlama-tetikle', function () { tetikModalAc(0, $(this).data('id')); });
    $('#gorev-listesi').on('click', '.btn-zamanlama-sil',     function () { zamanlamaSil($(this).data('id')); });
    $('#gorev-listesi').on('click', '.btn-zamanlama-duraklat', function () {
        zamanlamaDurum($(this).data('id'), $(this).data('durum'));
    });

    $('#z_sablon').on('change', function () {
        const v = $(this).val();
        if (v) $('#z_cron').val(v);
    });

    // Loglar
    $('#btnFiltrele').on('click', logListeYukle);
    $('#filtre-arama').on('keyup', function (e) { if (e.key === 'Enter') logListeYukle(); });
    $('#btnFiltreTemizle').on('click', function () {
        $('#filtre-arama, #filtre-tarih-bas, #filtre-tarih-bit').val('');
        $('#filtre-gorev, #filtre-calisma, #filtre-tetik').val('').trigger('change');
        logListeYukle();
    });
    $('#logTable tbody').on('click', '.btn-log-detay', function () { logDetayAc($(this).data('id')); });

    // Şablonlar
    $('#btnYeniSablon').on('click', function () { sablonModalAc(null); });
    $('#sablonTable tbody').on('click', '.btn-sablon-duzenle', function () { sablonModalAc($(this).data('id')); });
    $('#sablonTable tbody').on('click', '.btn-sablon-sil',     function () { sablonSil($(this).data('id')); });

    // Kaydetmeler
    $('#btnGorevKaydet').on('click', gorevKaydet);
    $('#btnZamanlamaKaydet').on('click', zamanlamaKaydet);
    $('#btnSablonKaydet').on('click', sablonKaydet);
    $('#btnTetikCalistir').on('click', tetikCalistir);
});

/* ===================== Sekme yönetimi ===================== */
function tipDegistir(tip) {
    aktifTip = tip;
    $('#cron-tabs .nav-link').removeClass('active').filter('[data-tip="' + tip + '"]').addClass('active');

    $('#bolum-gorev').toggleClass('d-none', tip !== 'gorev');
    $('#bolum-log').toggleClass('d-none', tip !== 'log');
    $('#bolum-sablon').toggleClass('d-none', tip !== 'sablon');

    if (tip === 'log') {
        logListeYukle();
        setTimeout(function () { logDT.columns.adjust(); }, 50);
    } else if (tip === 'sablon') {
        sablonListeYukle();
        setTimeout(function () { sablonDT.columns.adjust(); }, 50);
    }
}

function istatistikYukle() {
    postAjax({ action: 'istatistik' }, function (res) {
        if (!res.success) return;
        $('#stat-gorev').text(res.data.gorev);
        $('#stat-zamanlama').text(res.data.zamanlama);
        $('#stat-basarili').text(res.data.basarili);
        $('#stat-hatali').text(res.data.hatali);
    });
}

/* ===================== Görev kartları ===================== */
function gorevListeYukle() {
    postAjax({ action: 'gorev_liste' }, function (res) {
        if (!res.success) { showToast(res.message, 'error'); return; }

        gorevCache = {};
        zamanlamaCache = {};

        res.gorevler.forEach(function (g) {
            g.zamanlamalar = [];
            gorevCache[g.CronGorevler_Id] = g;
        });
        res.zamanlamalar.forEach(function (z) {
            zamanlamaCache[z.CronZamanlamalar_Id] = z;
            if (gorevCache[z.CronZamanlamalar_GorevId]) {
                gorevCache[z.CronZamanlamalar_GorevId].zamanlamalar.push(z);
            }
        });

        gorevKartlariCiz();
    });
}

function gorevKartlariCiz() {
    const arama = ($('#gorev-arama').val() || '').toLowerCase();
    let html = '';

    Object.keys(gorevCache).forEach(function (id) {
        const g = gorevCache[id];
        if (arama && !(g.CronGorevler_Ad + ' ' + g.CronGorevler_GorevKodu).toLowerCase().includes(arama)) return;

        let butonlar = '';
        if (YETKI.duzen) {
            butonlar +=
                '<button class="btn btn-success btn-sm btn-gorev-tetikle" data-id="' + g.CronGorevler_Id + '">' +
                '<i class="bi bi-play-fill"></i> Manuel Çalıştır</button>';
        }
        if (YETKI.ekle) {
            butonlar +=
                '<button class="btn btn-primary btn-sm btn-zamanlama-ekle" data-gorev="' + g.CronGorevler_Id + '">' +
                '<i class="bi bi-plus-circle"></i> Zamanlayıcı Ekle</button>';
        }
        if (YETKI.duzen) {
            butonlar +=
                '<button class="btn btn-outline-secondary btn-sm btn-gorev-duzenle" data-id="' + g.CronGorevler_Id + '" title="Görevi düzenle">' +
                '<i class="bi bi-pencil"></i></button>';
        }
        if (YETKI.sil) {
            butonlar +=
                '<button class="btn btn-outline-danger btn-sm btn-gorev-sil" data-id="' + g.CronGorevler_Id + '" title="Görevi kaldır">' +
                '<i class="bi bi-trash"></i></button>';
        }

        let satirlar = '';
        if (!g.zamanlamalar.length) {
            satirlar = '<tr><td colspan="6" class="text-center text-muted py-3">Bu göreve tanımlı zamanlayıcı yok.</td></tr>';
        } else {
            g.zamanlamalar.forEach(function (z) {
                const pasif = parseInt(z.Durum, 10) !== 1;

                let islem = '<div class="btn-group btn-group-sm">';
                if (YETKI.duzen) {
                    islem += '<button class="btn ' + (pasif ? 'btn-outline-success' : 'btn-warning') + ' btn-zamanlama-duraklat" ' +
                             'data-id="' + z.CronZamanlamalar_Id + '" data-durum="' + (pasif ? 1 : 0) + '" ' +
                             'title="' + (pasif ? 'Devam ettir' : 'Duraklat') + '">' +
                             '<i class="bi ' + (pasif ? 'bi-play' : 'bi-pause') + '"></i></button>';
                    islem += '<button class="btn btn-success btn-zamanlama-tetikle" data-id="' + z.CronZamanlamalar_Id + '" title="Şimdi çalıştır">' +
                             '<i class="bi bi-play-fill"></i></button>';
                    islem += '<button class="btn btn-warning btn-zamanlama-duzenle" data-id="' + z.CronZamanlamalar_Id + '" title="Düzenle">' +
                             '<i class="bi bi-pencil"></i></button>';
                }
                if (YETKI.sil) {
                    islem += '<button class="btn btn-danger btn-zamanlama-sil" data-id="' + z.CronZamanlamalar_Id + '" title="Sil">' +
                             '<i class="bi bi-trash"></i></button>';
                }
                islem += '</div>';

                const sonDurum = z.SonDurum === null || z.SonDurum === undefined
                    ? '<span class="text-muted">-</span>'
                    : durumRozet(parseInt(z.SonDurum, 10)) +
                      '<div class="small text-muted">' + (z.SonSure !== null ? z.SonSure + 's' : '') + '</div>';

                satirlar +=
                    '<tr' + (pasif ? ' class="opacity-50"' : '') + '>' +
                    '<td>' + escHtml(z.CronZamanlamalar_Ad) +
                        (parseInt(z.CronZamanlamalar_TelafiEt, 10) === 1
                            ? ' <span class="badge text-bg-info" title="Telafi açık">telafi ' + z.CronZamanlamalar_TelafiSaatSiniri + ' sa</span>'
                            : '') +
                        (pasif ? ' <span class="badge text-bg-secondary">duraklatıldı</span>' : '') + '</td>' +
                    '<td><span class="cron-ifade">' + escHtml(z.CronZamanlamalar_CronIfadesi) + '</span></td>' +
                    '<td>' + escHtml(z.BaslangicTarihi || '-') +
                        '<div class="small text-muted">' + (z.BitisTarihi ? escHtml(z.BitisTarihi) : 'Sürekli') + '</div></td>' +
                    '<td>' + escHtml(z.SonCalisma || '-') + '</td>' +
                    '<td>' + sonDurum + '</td>' +
                    '<td class="text-center">' + islem + '</td>' +
                    '</tr>';
            });
        }

        html +=
            '<div class="card mb-3 gorev-karti">' +
            '<div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">' +
                '<div>' +
                    '<span class="fw-semibold me-2">' + escHtml(g.CronGorevler_Ad) + '</span>' +
                    '<span class="cron-kod">' + escHtml(g.CronGorevler_GorevKodu) + '</span>' +
                    '<span class="text-success small ms-2">' + escHtml(g.CronGorevler_Aciklama || '') + '</span>' +
                '</div>' +
                '<div class="d-flex gap-2">' + butonlar + '</div>' +
            '</div>' +
            '<div class="card-body p-0">' +
                '<div class="table-responsive">' +
                '<table class="table table-sm table-hover align-middle">' +
                '<thead><tr>' +
                    '<th>Zamanlayıcı Adı</th><th>Cron İfadesi</th><th>Başlangıç / Bitiş</th>' +
                    '<th>Son Çalışma</th><th>Durum</th><th class="text-center">İşlem</th>' +
                '</tr></thead>' +
                '<tbody>' + satirlar + '</tbody>' +
                '</table></div>' +
            '</div></div>';
    });

    $('#gorev-listesi').html(html || '<div class="alert alert-info">Kayıtlı görev bulunamadı.</div>');
}

/* ===================== Görev CRUD ===================== */
function gorevModalAc(id) {
    $('#g_id').val(id || '');
    if (id && gorevCache[id]) {
        const r = gorevCache[id];
        $('#gorev-modal-baslik').text('Görevi Düzenle');
        $('#g_ad').val(r.CronGorevler_Ad);
        $('#g_kod').val(r.CronGorevler_GorevKodu);
        $('#g_aciklama').val(r.CronGorevler_Aciklama || '');
        $('#g_max_sure').val(r.CronGorevler_MaxSureSn);
        $('#g_parametreler').val(r.CronGorevler_Parametreler || '');
    } else {
        $('#gorev-modal-baslik').text('Yeni Görev');
        $('#g_ad, #g_kod, #g_aciklama, #g_parametreler').val('');
        $('#g_max_sure').val(600);
    }
    new bootstrap.Modal(document.getElementById('modalGorev')).show();
}

function gorevKaydet() {
    postAjax({
        action: 'gorev_kaydet',
        id: $('#g_id').val() || 0,
        ad: $('#g_ad').val(),
        kod: $('#g_kod').val(),
        aciklama: $('#g_aciklama').val(),
        max_sure: $('#g_max_sure').val(),
        parametreler: $('#g_parametreler').val()
    }, function (res) {
        if (!res.success) { showToast(res.message, 'error'); return; }
        bootstrap.Modal.getInstance(document.getElementById('modalGorev')).hide();
        showToast(res.message, 'success');
        gorevListeYukle();
        istatistikYukle();
    });
}

function gorevSil(id) {
    const r = gorevCache[id];
    Swal.fire({
        title: 'Görev kaldırılsın mı?',
        text: (r ? r.CronGorevler_Ad : '') + ' listeden kaldırılacak.',
        icon: 'warning', showCancelButton: true,
        confirmButtonText: 'Evet, kaldır', cancelButtonText: 'İptal'
    }).then(function (s) {
        if (!s.isConfirmed) return;
        postAjax({ action: 'gorev_sil', id: id }, function (res) {
            showToast(res.message, res.success ? 'success' : 'error');
            if (res.success) { gorevListeYukle(); istatistikYukle(); }
        });
    });
}

/* ===================== Zamanlayıcı ===================== */
function paramKaynakYukle() {
    postAjax({ action: 'param_kaynaklar' }, function (res) {
        if (res.success) PARAM_KAYNAK = res.data || {};
    });
}

function sablonSecenekYukle() {
    postAjax({ action: 'sablon_liste' }, function (res) {
        if (!res.success) return;
        sablonSecenek = res.data.filter(function (s) { return parseInt(s.Durum, 10) === 1; });

        let opt = '<option value="">Seçiniz...</option>';
        sablonSecenek.forEach(function (s) {
            opt += '<option value="' + escHtml(s.CronSiklikSablonlari_Ifade) + '">' + escHtml(s.CronSiklikSablonlari_Etiket) + '</option>';
        });
        $('#z_sablon').html(opt);
    });
}

/**
 * Şemadaki tek bir parametre alanının HTML'ini üretir.
 * tip: text (varsayılan) · number · date · select · multiselect
 * select/multiselect seçenekleri PARAM_KAYNAK cache'inden (DB) gelir.
 *
 * @param sinif  üretilen input'a verilecek sınıf (zaman-param | tetik-param)
 */
function parametreAlaniHtml(alan, mevcut, sinif) {
    const etiket = '<label class="form-label">' + escHtml(alan.etiket || alan.ad) +
                   (alan.zorunlu ? ' <span class="text-danger">*</span>' : '') + '</label>';
    const tip = alan.tip || 'text';

    if (tip === 'select' || tip === 'multiselect') {
        const secenekler = PARAM_KAYNAK[alan.kaynak] || [];
        const secili = tip === 'multiselect'
            ? (Array.isArray(mevcut) ? mevcut.map(String) : (mevcut ? String(mevcut).split(',') : []))
            : [String(mevcut === null || mevcut === undefined ? '' : mevcut)];

        let opt = tip === 'select' ? '<option value="">Seçiniz...</option>' : '';
        secenekler.forEach(function (s) {
            const deger = String(s.deger);
            opt += '<option value="' + escHtml(deger) + '"' +
                   (secili.indexOf(deger) !== -1 ? ' selected' : '') + '>' + escHtml(s.etiket) + '</option>';
        });

        if (!secenekler.length) {
            opt = '<option value="">Seçenek bulunamadı</option>';
        }

        return '<div class="col-12">' + etiket +
               '<select class="form-select param-secim ' + sinif + '" data-ad="' + escHtml(alan.ad) + '"' +
               (tip === 'multiselect' ? ' multiple' : '') + '>' + opt + '</select>' +
               (alan.aciklama ? '<div class="form-text">' + escHtml(alan.aciklama) + '</div>' : '') +
               '</div>';
    }

    const inputTip = tip === 'number' ? 'number' : (tip === 'date' ? 'date' : 'text');
    return '<div class="col-12">' + etiket +
           '<input type="' + inputTip + '" class="form-control ' + sinif + '" data-ad="' + escHtml(alan.ad) + '" value="' +
           escHtml(mevcut === null || mevcut === undefined ? '' : mevcut) + '">' +
           (alan.aciklama ? '<div class="form-text">' + escHtml(alan.aciklama) + '</div>' : '') +
           '</div>';
}

/**
 * Bir konteynerdeki .param-secim alanlarını Select2'ye çevirir (proje kuralı: aranabilir dropdown).
 */
function paramSelect2(konteynerId, modalId) {
    $('#' + konteynerId + ' .param-secim').select2({
        theme: 'bootstrap-5',
        width: '100%',
        dropdownParent: $('#' + modalId),
        placeholder: 'Seçiniz...',
        allowClear: true
    });
}

/**
 * Bir konteynerdeki parametre alanlarını okuyup nesneye çevirir.
 * multiselect alanlar dizi olarak döner.
 */
function parametreleriTopla(sinif) {
    const params = {};
    $('.' + sinif).each(function () {
        const ad = $(this).data('ad');
        const v  = $(this).val();

        if ($(this).is('select[multiple]')) {
            if (v && v.length) params[ad] = v;
        } else if (v !== '' && v !== null) {
            params[ad] = v;
        }
    });
    return params;
}

function parametreAlanlariCiz(gorev, degerler) {
    let sema = [];
    try { sema = JSON.parse(gorev.CronGorevler_Parametreler || '[]') || []; } catch (e) { sema = []; }

    if (!sema.length) {
        $('#z_parametreler').html('<div class="col-12"><p class="text-muted small mb-0">Bu görev parametre almıyor.</p></div>');
        return;
    }

    let html = '';
    sema.forEach(function (alan) {
        const mevcut = degerler && degerler[alan.ad] !== undefined
            ? degerler[alan.ad]
            : (alan.varsayilan !== undefined ? alan.varsayilan : '');

        html += parametreAlaniHtml(alan, mevcut, 'zaman-param');
    });

    $('#z_parametreler').html(html);
    paramSelect2('z_parametreler', 'modalZamanlama');
}

function zamanlamaModalAc(id, gorevId) {
    $('#z_id').val(id || '');

    let gorev = null;
    let degerler = {};

    if (id && zamanlamaCache[id]) {
        const z = zamanlamaCache[id];
        gorev = gorevCache[z.CronZamanlamalar_GorevId];
        try { degerler = JSON.parse(z.CronZamanlamalar_SabitParametreler || '{}') || {}; } catch (e) { degerler = {}; }

        $('#zamanlama-modal-baslik').text('Zamanlayıcı Düzenle');
        $('#z_gorev_id').val(z.CronZamanlamalar_GorevId);
        $('#z_ad').val(z.CronZamanlamalar_Ad);
        $('#z_cron').val(z.CronZamanlamalar_CronIfadesi);
        $('#z_sablon').val(z.CronZamanlamalar_CronIfadesi).trigger('change.select2');
        $('#z_baslangic').val((z.BaslangicTarihi || '').replace(' ', 'T'));
        $('#z_bitis').val((z.BitisTarihi || '').replace(' ', 'T'));
        $('#z_telafi').prop('checked', parseInt(z.CronZamanlamalar_TelafiEt, 10) === 1);
        $('#z_telafi_saat').val(z.CronZamanlamalar_TelafiSaatSiniri);
    } else {
        gorev = gorevCache[gorevId];
        const simdi = new Date();
        simdi.setMinutes(simdi.getMinutes() - simdi.getTimezoneOffset());

        $('#zamanlama-modal-baslik').text('Zamanlayıcı Ekle');
        $('#z_gorev_id').val(gorevId);
        $('#z_ad, #z_cron, #z_bitis').val('');
        $('#z_sablon').val('').trigger('change.select2');
        $('#z_baslangic').val(simdi.toISOString().slice(0, 16));
        $('#z_telafi').prop('checked', false);
        $('#z_telafi_saat').val(6);
    }

    parametreAlanlariCiz(gorev || {}, degerler);
    new bootstrap.Modal(document.getElementById('modalZamanlama')).show();
}

function zamanlamaKaydet() {
    const params = parametreleriTopla('zaman-param');

    const veri = {
        action: 'zamanlama_kaydet',
        id: $('#z_id').val() || 0,
        gorev_id: $('#z_gorev_id').val(),
        ad: $('#z_ad').val(),
        cron: $('#z_cron').val(),
        baslangic: $('#z_baslangic').val(),
        bitis: $('#z_bitis').val(),
        parametreler: JSON.stringify(params),
        telafi_saat: $('#z_telafi_saat').val()
    };
    if ($('#z_telafi').is(':checked')) veri.telafi = 1;

    postAjax(veri, function (res) {
        if (!res.success) { showToast(res.message, 'error'); return; }
        bootstrap.Modal.getInstance(document.getElementById('modalZamanlama')).hide();
        showToast(res.message, 'success');
        gorevListeYukle();
        istatistikYukle();
    });
}

function zamanlamaDurum(id, durum) {
    postAjax({ action: 'zamanlama_durum', id: id, durum: durum }, function (res) {
        showToast(res.message, res.success ? 'success' : 'error');
        gorevListeYukle();
        istatistikYukle();
    });
}

function zamanlamaSil(id) {
    const z = zamanlamaCache[id];
    Swal.fire({
        title: 'Zamanlayıcı silinsin mi?',
        text: (z ? z.CronZamanlamalar_Ad : '') + ' kalıcı olarak silinecek.',
        icon: 'warning', showCancelButton: true,
        confirmButtonText: 'Evet, sil', cancelButtonText: 'İptal'
    }).then(function (s) {
        if (!s.isConfirmed) return;
        postAjax({ action: 'zamanlama_sil', id: id }, function (res) {
            showToast(res.message, res.success ? 'success' : 'error');
            if (res.success) { gorevListeYukle(); istatistikYukle(); }
        });
    });
}

/* ===================== Çalışma geçmişi ===================== */
function logListeYukle() {
    postAjax({
        action: 'log_liste',
        gorev_id: $('#filtre-gorev').val(),
        calisma_durum: $('#filtre-calisma').val(),
        tetik_tur: $('#filtre-tetik').val(),
        tarih_bas: $('#filtre-tarih-bas').val(),
        tarih_bit: $('#filtre-tarih-bit').val()
    }, function (res) {
        if (!res.success) { showToast(res.message, 'error'); return; }

        const arama = ($('#filtre-arama').val() || '').toLowerCase();
        const satirlar = [];

        res.data.forEach(function (r) {
            if (arama && !((r.CronGorevler_Ad || '') + ' ' + (r.CronCalismaLog_Sonuc || '')).toLowerCase().includes(arama)) return;

            const tetikleyen = parseInt(r.CronCalismaLog_TetikleyenTur, 10) === 1
                ? '<span class="badge text-bg-secondary">Otomatik</span>'
                : '<span class="badge text-bg-primary">' +
                  escHtml(((r.kullanici_ad || '') + ' ' + (r.kullanici_soyad || '')).trim() || 'Manuel') + '</span>';

            satirlar.push([
                r.CronCalismaLog_Id,
                escHtml(r.CronGorevler_Ad),
                escHtml(r.CronZamanlamalar_Ad || '-'),
                escHtml(r.Baslangic),
                r.CronCalismaLog_SureSaniye !== null ? r.CronCalismaLog_SureSaniye + ' sn' : '-',
                durumRozet(parseInt(r.CronCalismaLog_CalismaDurum, 10)),
                tetikleyen,
                escHtml((r.CronCalismaLog_Sonuc || '').substring(0, 120)),
                '<button class="btn btn-sm btn-outline-secondary btn-log-detay" data-id="' + r.CronCalismaLog_Id + '"><i class="bi bi-eye"></i></button>'
            ]);
        });

        logDT.clear().rows.add(satirlar).draw();
    });
}

function logDetayAc(id) {
    postAjax({ action: 'log_detay', id: id }, function (res) {
        if (!res.success) { showToast(res.message, 'error'); return; }
        const r = res.data;

        const satir = function (etiket, deger) {
            return '<dt class="col-sm-3">' + etiket + '</dt><dd class="col-sm-9">' + deger + '</dd>';
        };

        $('#log-ozet').html(
            satir('Görev', escHtml(r.CronGorevler_Ad) + ' <span class="cron-kod">' + escHtml(r.CronGorevler_GorevKodu) + '</span>') +
            satir('Başlangıç', escHtml(r.Baslangic)) +
            satir('Bitiş', escHtml(r.Bitis || '-')) +
            satir('Süre', r.CronCalismaLog_SureSaniye !== null ? r.CronCalismaLog_SureSaniye + ' sn' : '-') +
            satir('Durum', durumRozet(parseInt(r.CronCalismaLog_CalismaDurum, 10))) +
            satir('Parametreler', '<span class="cron-kod">' + escHtml(r.CronCalismaLog_Parametreler || '-') + '</span>') +
            satir('Sonuç', escHtml(r.CronCalismaLog_Sonuc || '-'))
        );

        $('#log-cikti').text(r.CronCalismaLog_Cikti || '(çıktı yok)');
        new bootstrap.Modal(document.getElementById('modalLog')).show();
    });
}

/* ===================== Sıklık şablonları ===================== */
function sablonListeYukle() {
    postAjax({ action: 'sablon_liste' }, function (res) {
        if (!res.success) { showToast(res.message, 'error'); return; }

        sablonCache = {};
        const satirlar = [];

        res.data.forEach(function (s) {
            sablonCache[s.CronSiklikSablonlari_Id] = s;

            let islem = '<div class="btn-group btn-group-sm">';
            if (YETKI.duzen) {
                islem += '<button class="btn btn-warning btn-sablon-duzenle" data-id="' + s.CronSiklikSablonlari_Id + '"><i class="bi bi-pencil"></i></button>';
            }
            if (YETKI.sil) {
                islem += '<button class="btn btn-danger btn-sablon-sil" data-id="' + s.CronSiklikSablonlari_Id + '"><i class="bi bi-trash"></i></button>';
            }
            islem += '</div>';

            satirlar.push([
                escHtml(s.CronSiklikSablonlari_Etiket),
                '<span class="cron-ifade">' + escHtml(s.CronSiklikSablonlari_Ifade) + '</span>',
                s.CronSiklikSablonlari_Sira,
                s.KullanimAdedi,
                parseInt(s.Durum, 10) === 1
                    ? '<span class="badge text-bg-success">Aktif</span>'
                    : '<span class="badge text-bg-secondary">Pasif</span>',
                islem
            ]);
        });

        sablonDT.clear().rows.add(satirlar).draw();
    });
}

function sablonModalAc(id) {
    $('#s_id').val(id || '');
    if (id && sablonCache[id]) {
        const s = sablonCache[id];
        $('#sablon-modal-baslik').text('Şablonu Düzenle');
        $('#s_etiket').val(s.CronSiklikSablonlari_Etiket);
        $('#s_ifade').val(s.CronSiklikSablonlari_Ifade);
        $('#s_sira').val(s.CronSiklikSablonlari_Sira);
        $('#s_durum').prop('checked', parseInt(s.Durum, 10) === 1);
    } else {
        $('#sablon-modal-baslik').text('Yeni Şablon');
        $('#s_etiket, #s_ifade').val('');
        $('#s_sira').val(0);
        $('#s_durum').prop('checked', true);
    }
    new bootstrap.Modal(document.getElementById('modalSablon')).show();
}

function sablonKaydet() {
    const veri = {
        action: 'sablon_kaydet',
        id: $('#s_id').val() || 0,
        etiket: $('#s_etiket').val(),
        ifade: $('#s_ifade').val(),
        sira: $('#s_sira').val()
    };
    if ($('#s_durum').is(':checked')) veri.durum = 1;

    postAjax(veri, function (res) {
        if (!res.success) { showToast(res.message, 'error'); return; }
        bootstrap.Modal.getInstance(document.getElementById('modalSablon')).hide();
        showToast(res.message, 'success');
        sablonListeYukle();
        sablonSecenekYukle();
    });
}

function sablonSil(id) {
    const s = sablonCache[id];
    Swal.fire({
        title: 'Şablon pasife alınsın mı?',
        text: (s ? s.CronSiklikSablonlari_Etiket : '') + ' dropdown listesinden kaldırılacak.',
        icon: 'warning', showCancelButton: true,
        confirmButtonText: 'Evet, kaldır', cancelButtonText: 'İptal'
    }).then(function (r) {
        if (!r.isConfirmed) return;
        postAjax({ action: 'sablon_sil', id: id }, function (res) {
            showToast(res.message, res.success ? 'success' : 'error');
            if (res.success) { sablonListeYukle(); sablonSecenekYukle(); }
        });
    });
}

/* ===================== Manuel çalıştırma ===================== */
function tetikModalAc(gorevId, zamanlamaId) {
    $('#t_gorev_id').val(gorevId || 0);
    $('#t_zamanlama_id').val(zamanlamaId || 0);
    $('#t_sonuc').addClass('d-none');
    $('#t_cikti').text('');
    $('#t_parametreler').empty();
    $('#t_uyari').addClass('d-none').html('');
    $('#btnTetikCalistir').prop('disabled', false).html('<i class="bi bi-play-fill"></i> Çalıştır');

    let gorev = null;

    if (zamanlamaId) {
        const z = zamanlamaCache[zamanlamaId];
        gorev = gorevCache[z.CronZamanlamalar_GorevId];
        $('#tetik-modal-baslik').text('Manuel Çalıştır: ' + (z ? z.CronZamanlamalar_Ad : ''));
        $('#t_parametreler').html(
            '<div class="col-12"><div class="alert alert-secondary py-2 small mb-0">' +
            'Parametreler zamanlayıcının sabit parametrelerinden alınacak: <span class="cron-kod">' +
            escHtml(z && z.CronZamanlamalar_SabitParametreler ? z.CronZamanlamalar_SabitParametreler : '{}') +
            '</span></div></div>'
        );
    } else {
        gorev = gorevCache[gorevId];
        $('#tetik-modal-baslik').text('Manuel Çalıştır: ' + (gorev ? gorev.CronGorevler_Ad : ''));

        let sema = [];
        try { sema = JSON.parse(gorev.CronGorevler_Parametreler || '[]') || []; } catch (e) { sema = []; }

        if (!sema.length) {
            $('#t_parametreler').html('<div class="col-12"><p class="text-muted small mb-0">Bu görev parametre almıyor.</p></div>');
        } else {
            let html = '';
            sema.forEach(function (alan) {
                html += parametreAlaniHtml(alan, alan.varsayilan !== undefined ? alan.varsayilan : '', 'tetik-param');
            });
            $('#t_parametreler').html(html);
            paramSelect2('t_parametreler', 'modalTetik');
        }
    }

    // Web isteği FastCGI zaman aşımına takılabilir; uzun görevlerde CLI'a yönlendir
    if (gorev && parseInt(gorev.CronGorevler_MaxSureSn, 10) > 300) {
        $('#t_uyari').removeClass('d-none').html(
            '<i class="bi bi-exclamation-triangle me-1"></i> Bu görevin süre limiti <strong>' +
            gorev.CronGorevler_MaxSureSn + ' sn</strong>. Web isteği sunucu zaman aşımına (~300 sn) takılabilir; ' +
            'uzun sürerse CLI kullanın:<br><span class="cron-kod">php admin/cron/worker.php gorev=' +
            escHtml(gorev.CronGorevler_GorevKodu) + '</span>'
        );
    }

    new bootstrap.Modal(document.getElementById('modalTetik')).show();
}

function tetikCalistir() {
    const params = parametreleriTopla('tetik-param');

    $('#btnTetikCalistir').prop('disabled', true)
        .html('<span class="spinner-border spinner-border-sm me-1"></span> Çalışıyor...');

    postAjax({
        action: 'tetikle',
        gorev_id: $('#t_gorev_id').val(),
        zamanlama_id: $('#t_zamanlama_id').val(),
        parametreler: JSON.stringify(params)
    }, function (res) {
        $('#btnTetikCalistir').prop('disabled', false).html('<i class="bi bi-play-fill"></i> Çalıştır');
        $('#t_sonuc').removeClass('d-none');

        if (!res.success) {
            $('#t_sonuc_mesaj').removeClass('alert-success alert-danger').addClass('alert-danger').text(res.message);
            $('#t_cikti').text('');
            showToast(res.message, 'error');
            return;
        }

        const basarili = res.durum === 1;
        $('#t_sonuc_mesaj')
            .removeClass('alert-success alert-danger')
            .addClass(basarili ? 'alert-success' : 'alert-danger')
            .text(res.message + '  (' + res.sure + ' sn, log #' + res.log_id + ')');
        $('#t_cikti').text(res.cikti || '(çıktı yok)');

        showToast(res.message, basarili ? 'success' : 'error');
        istatistikYukle();
        gorevListeYukle();
    });
}
</script>
</body>
</html>
