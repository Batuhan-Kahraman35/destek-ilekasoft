<?php
/**
 * Admin Panel - Ticket Kaynak Yönetimi
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
requireAuth();

$user = Auth::user();
$db = Database::getInstance();

// Sadece admin erişebilir
if ($user['departman_id'] != 1) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Bu sayfaya erişim yetkiniz yok.']);
        exit;
    }
    PageAuth::accessDenied('Bu sayfaya erişim yetkiniz bulunmamaktadır.');
}

$currentPageFile = basename($_SERVER['PHP_SELF']);

$pageInfo = $db->fetchOne(
    "SELECT s.sayfalar_sayfa_adi, m.menuler_menu_adi AS menu_adi
     FROM Menu_Sayfalar s
     LEFT JOIN Menuler m ON s.sayfalar_menu_id = m.menuler_id
     WHERE s.sayfalar_sayfa_url LIKE ? AND s.sayfalar_durum = 1",
    ['%' . $currentPageFile]
);

$pageTitle = $pageInfo['sayfalar_sayfa_adi'] ?? 'Ticket Kaynak Yönetimi';
$menuAdi = $pageInfo['menu_adi'] ?? null;

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';

function generateApiKey(): string
{
    return bin2hex(random_bytes(32));
}

/**
 * Kaynağa atanmış personelleri gelen listeye göre senkronlar.
 * Listeden çıkarılanlar pasife alınır, tekrar eklenenler yeniden aktif edilir.
 */
function personelSenkronla($db, int $kaynakId, array $personelIdler, int $islemKullanici, string $now): void
{
    $mevcutlar = $db->fetchAll(
        "SELECT Ticket_Kaynak_Personel_id, Ticket_Kaynak_Personel_kullanici_id, Durum
         FROM Ticket_Kaynak_Personel
         WHERE Ticket_Kaynak_Personel_kaynak_id = ?",
        [$kaynakId]
    );

    $mevcutHarita = [];
    foreach ($mevcutlar as $satir) {
        $mevcutHarita[(int)$satir['Ticket_Kaynak_Personel_kullanici_id']] = $satir;
    }

    foreach ($personelIdler as $kullaniciId) {
        if (isset($mevcutHarita[$kullaniciId])) {
            if ((int)$mevcutHarita[$kullaniciId]['Durum'] !== 1) {
                $db->update('Ticket_Kaynak_Personel', [
                    'Durum' => 1,
                    'GuncelleyenKullanici' => $islemKullanici,
                    'GuncellemeTarihi' => $now,
                ], [
                    'Ticket_Kaynak_Personel_id' => (int)$mevcutHarita[$kullaniciId]['Ticket_Kaynak_Personel_id'],
                ]);
            }
            continue;
        }

        $db->insert('Ticket_Kaynak_Personel', [
            'Ticket_Kaynak_Personel_kaynak_id' => $kaynakId,
            'Ticket_Kaynak_Personel_kullanici_id' => $kullaniciId,
            'OlusturanKullanici' => $islemKullanici,
            'OlusturmaTarihi' => $now,
            'GuncelleyenKullanici' => $islemKullanici,
            'GuncellemeTarihi' => $now,
            'Durum' => 1,
        ]);
    }

    foreach ($mevcutHarita as $kullaniciId => $satir) {
        if (in_array($kullaniciId, $personelIdler, true) || (int)$satir['Durum'] !== 1) {
            continue;
        }

        $db->update('Ticket_Kaynak_Personel', [
            'Durum' => 0,
            'GuncelleyenKullanici' => $islemKullanici,
            'GuncellemeTarihi' => $now,
        ], [
            'Ticket_Kaynak_Personel_id' => (int)$satir['Ticket_Kaynak_Personel_id'],
        ]);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (ob_get_level()) {
        ob_end_clean();
    }

    header('Content-Type: application/json; charset=utf-8');

    try {
        $action = $_POST['action'] ?? '';
        $now = app_now();

        if ($action === 'istatistik') {
            $istatistik = [
                'toplam' => (int)($db->fetchOne("SELECT COUNT(*) AS sayi FROM Ticket_Kaynak")['sayi'] ?? 0),
                'aktif' => (int)($db->fetchOne("SELECT COUNT(*) AS sayi FROM Ticket_Kaynak WHERE Durum = 1")['sayi'] ?? 0),
                'pasif' => (int)($db->fetchOne("SELECT COUNT(*) AS sayi FROM Ticket_Kaynak WHERE Durum = 0")['sayi'] ?? 0),
                'son_guncelleme' => $db->fetchOne("SELECT TOP 1 CONVERT(VARCHAR(16), GuncellemeTarihi, 120) AS tarih FROM Ticket_Kaynak ORDER BY GuncellemeTarihi DESC")['tarih'] ?? '-',
            ];
            echo json_encode(['success' => true, 'data' => $istatistik]);
            exit;
        }

        if ($action === 'liste') {
            $satirlar = $db->fetchAll(
                "SELECT
                    tk.Ticket_Kaynak_id,
                    tk.Ticket_Kaynak_ad,
                    tk.Ticket_Kaynak_tip,
                    tk.Ticket_Kaynak_kod,
                    tk.Ticket_Kaynak_api_url,
                    tk.Ticket_Kaynak_api_key,
                    tk.Ticket_Kaynak_secret_key,
                    tk.Ticket_Kaynak_aciklama,
                    tk.Ticket_Kaynak_kullanici_id,
                    tk.Ticket_Kaynak_destek_sayfasi,
                    ku.kullanici_ad + ' ' + ISNULL(ku.kullanici_soyad, '') AS kullanici_adi,
                    tk.Durum,
                    CONVERT(VARCHAR(16), tk.OlusturmaTarihi, 120) AS olusturma_tarihi,
                    CONVERT(VARCHAR(16), tk.GuncellemeTarihi, 120) AS guncelleme_tarihi
                 FROM Ticket_Kaynak tk
                 LEFT JOIN kullanicilar ku ON tk.Ticket_Kaynak_kullanici_id = ku.kullanici_id
                 ORDER BY tk.Ticket_Kaynak_id DESC"
            );

            $personeller = $db->fetchAll(
                "SELECT
                    p.Ticket_Kaynak_Personel_kaynak_id AS kaynak_id,
                    p.Ticket_Kaynak_Personel_kullanici_id AS kullanici_id,
                    LTRIM(RTRIM(k.kullanici_ad + ' ' + ISNULL(k.kullanici_soyad, ''))) AS ad_soyad
                 FROM Ticket_Kaynak_Personel p
                 INNER JOIN kullanicilar k ON p.Ticket_Kaynak_Personel_kullanici_id = k.kullanici_id
                 WHERE p.Durum = 1
                 ORDER BY k.kullanici_ad ASC"
            );

            $personelHarita = [];
            foreach ($personeller as $per) {
                $kaynakId = (int)$per['kaynak_id'];
                if (!isset($personelHarita[$kaynakId])) {
                    $personelHarita[$kaynakId] = ['idler' => [], 'adlar' => []];
                }
                $personelHarita[$kaynakId]['idler'][] = (int)$per['kullanici_id'];
                $personelHarita[$kaynakId]['adlar'][] = $per['ad_soyad'];
            }

            foreach ($satirlar as &$satir) {
                $kaynakId = (int)$satir['Ticket_Kaynak_id'];
                $satir['personel_idler'] = $personelHarita[$kaynakId]['idler'] ?? [];
                $satir['personel_adlar'] = $personelHarita[$kaynakId]['adlar'] ?? [];
            }
            unset($satir);

            echo json_encode(['success' => true, 'data' => $satirlar]);
            exit;
        }

        if ($action === 'kaydet') {
            $id = (int)($_POST['id'] ?? 0);
            $ad = trim($_POST['ad'] ?? '');
            $tip = trim($_POST['tip'] ?? 'web');
            $kod = strtoupper(trim($_POST['kod'] ?? ''));
            $apiUrl = trim($_POST['api_url'] ?? '');
            $secret = trim($_POST['secret_key'] ?? '');
            $aciklama = trim($_POST['aciklama'] ?? '');
            $kullaniciId = !empty($_POST['kullanici_id']) ? (int)$_POST['kullanici_id'] : null;
            $destekSayfasi = trim($_POST['destek_sayfasi'] ?? '');

            $personelIdler = $_POST['personel_idler'] ?? [];
            if (!is_array($personelIdler)) {
                $personelIdler = [];
            }
            $personelIdler = array_values(array_unique(array_filter(array_map('intval', $personelIdler))));

            if ($ad === '') {
                throw new Exception('Kaynak adı zorunludur.');
            }
            if ($kod === '') {
                throw new Exception('Kaynak kodu zorunludur.');
            }
            if (!preg_match('/^[A-Z0-9_\-]+$/', $kod)) {
                throw new Exception('Kaynak kodu sadece büyük harf, rakam, alt çizgi ve tire içerebilir.');
            }

            $tipler = ['web', 'mobil', 'api', 'email', 'diger'];
            if (!in_array($tip, $tipler, true)) {
                $tip = 'diger';
            }

            if ($apiUrl !== '' && !preg_match('/^https?:\/\//i', $apiUrl)) {
                throw new Exception('API URL http:// veya https:// ile başlamalıdır.');
            }

            if ($id > 0) {
                $mevcut = $db->fetchOne("SELECT Ticket_Kaynak_id FROM Ticket_Kaynak WHERE Ticket_Kaynak_id = ?", [$id]);
                if (!$mevcut) {
                    throw new Exception('Kayıt bulunamadı.');
                }

                $cakisanKod = $db->fetchOne(
                    "SELECT Ticket_Kaynak_id FROM Ticket_Kaynak WHERE Ticket_Kaynak_kod = ? AND Ticket_Kaynak_id <> ?",
                    [$kod, $id]
                );
                if ($cakisanKod) {
                    throw new Exception('Bu kaynak kodu zaten kullanılıyor.');
                }

                $db->update('Ticket_Kaynak', [
                    'Ticket_Kaynak_ad' => $ad,
                    'Ticket_Kaynak_tip' => $tip,
                    'Ticket_Kaynak_kod' => $kod,
                    'Ticket_Kaynak_api_url' => $apiUrl !== '' ? $apiUrl : null,
                    'Ticket_Kaynak_secret_key' => $secret !== '' ? $secret : null,
                    'Ticket_Kaynak_aciklama' => $aciklama !== '' ? $aciklama : null,
                    'Ticket_Kaynak_kullanici_id' => $kullaniciId,
                    'Ticket_Kaynak_destek_sayfasi' => $destekSayfasi !== '' ? $destekSayfasi : null,
                    'GuncelleyenKullanici' => $user['kullanici_id'],
                    'GuncellemeTarihi' => $now,
                ], [
                    'Ticket_Kaynak_id' => $id,
                ]);

                personelSenkronla($db, $id, $personelIdler, (int)$user['kullanici_id'], $now);

                echo json_encode(['success' => true, 'message' => 'Kaynak güncellendi.']);
                exit;
            }

            $cakisanKod = $db->fetchOne("SELECT Ticket_Kaynak_id FROM Ticket_Kaynak WHERE Ticket_Kaynak_kod = ?", [$kod]);
            if ($cakisanKod) {
                throw new Exception('Bu kaynak kodu zaten kullanılıyor.');
            }

            $apiKey = generateApiKey();
            while ($db->fetchOne("SELECT Ticket_Kaynak_id FROM Ticket_Kaynak WHERE Ticket_Kaynak_api_key = ?", [$apiKey])) {
                $apiKey = generateApiKey();
            }

            $yeniId = $db->insert('Ticket_Kaynak', [
                'Ticket_Kaynak_ad' => $ad,
                'Ticket_Kaynak_tip' => $tip,
                'Ticket_Kaynak_kod' => $kod,
                'Ticket_Kaynak_api_url' => $apiUrl !== '' ? $apiUrl : null,
                'Ticket_Kaynak_api_key' => $apiKey,
                'Ticket_Kaynak_secret_key' => $secret !== '' ? $secret : null,
                'Ticket_Kaynak_aciklama' => $aciklama !== '' ? $aciklama : null,
                'Ticket_Kaynak_kullanici_id' => $kullaniciId,
                'Ticket_Kaynak_destek_sayfasi' => $destekSayfasi !== '' ? $destekSayfasi : null,
                'OlusturanKullanici' => $user['kullanici_id'],
                'OlusturmaTarihi' => $now,
                'GuncelleyenKullanici' => $user['kullanici_id'],
                'GuncellemeTarihi' => $now,
                'Durum' => 1,
            ]);

            personelSenkronla($db, (int)$yeniId, $personelIdler, (int)$user['kullanici_id'], $now);

            echo json_encode(['success' => true, 'message' => 'Kaynak eklendi.', 'id' => $yeniId]);
            exit;
        }

        if ($action === 'durum_degistir') {
            $id = (int)($_POST['id'] ?? 0);
            $durum = (int)($_POST['durum'] ?? 0);

            if ($id <= 0) {
                throw new Exception('Geçersiz kayıt.');
            }

            $db->update('Ticket_Kaynak', [
                'Durum' => $durum ? 1 : 0,
                'GuncelleyenKullanici' => $user['kullanici_id'],
                'GuncellemeTarihi' => $now,
            ], [
                'Ticket_Kaynak_id' => $id,
            ]);

            echo json_encode(['success' => true, 'message' => 'Durum güncellendi.']);
            exit;
        }

        if ($action === 'api_key_yenile') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                throw new Exception('Geçersiz kayıt.');
            }

            $mevcut = $db->fetchOne("SELECT Ticket_Kaynak_id FROM Ticket_Kaynak WHERE Ticket_Kaynak_id = ?", [$id]);
            if (!$mevcut) {
                throw new Exception('Kayıt bulunamadı.');
            }

            $yeniKey = generateApiKey();
            while ($db->fetchOne("SELECT Ticket_Kaynak_id FROM Ticket_Kaynak WHERE Ticket_Kaynak_api_key = ?", [$yeniKey])) {
                $yeniKey = generateApiKey();
            }

            $db->update('Ticket_Kaynak', [
                'Ticket_Kaynak_api_key' => $yeniKey,
                'GuncelleyenKullanici' => $user['kullanici_id'],
                'GuncellemeTarihi' => $now,
            ], [
                'Ticket_Kaynak_id' => $id,
            ]);

            echo json_encode(['success' => true, 'message' => 'API anahtarı yenilendi.', 'api_key' => $yeniKey]);
            exit;
        }

        throw new Exception('Geçersiz işlem.');
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}
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
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/admin/assets/css/custom.css">

    <style>
        .api-key-mask {
            font-family: monospace;
            font-size: 12px;
            letter-spacing: .5px;
        }
        .filter-box .card-body {
            background: #f8f9fa;
        }
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

                <div class="row mb-3">
                    <div class="col-12 col-md-3">
                        <div class="small-box text-bg-primary">
                            <div class="inner">
                                <h3 id="boxToplam">0</h3>
                                <p>Toplam Kaynak</p>
                            </div>
                            <i class="small-box-icon bi bi-diagram-3"></i>
                        </div>
                    </div>
                    <div class="col-12 col-md-3">
                        <div class="small-box text-bg-success">
                            <div class="inner">
                                <h3 id="boxAktif">0</h3>
                                <p>Aktif Kaynak</p>
                            </div>
                            <i class="small-box-icon bi bi-check-circle"></i>
                        </div>
                    </div>
                    <div class="col-12 col-md-3">
                        <div class="small-box text-bg-secondary">
                            <div class="inner">
                                <h3 id="boxPasif">0</h3>
                                <p>Pasif Kaynak</p>
                            </div>
                            <i class="small-box-icon bi bi-pause-circle"></i>
                        </div>
                    </div>
                    <div class="col-12 col-md-3">
                        <div class="small-box text-bg-info">
                            <div class="inner">
                                <h3 style="font-size: 20px" id="boxGuncel">-</h3>
                                <p>Son Güncelleme</p>
                            </div>
                            <i class="small-box-icon bi bi-clock-history"></i>
                        </div>
                    </div>
                </div>

                <div class="card filter-box mb-3">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <button class="btn btn-link p-0 text-decoration-none" data-bs-toggle="collapse" data-bs-target="#filterPanel">
                                <i class="bi bi-funnel me-1"></i> Filtreler
                            </button>
                        </h5>
                    </div>
                    <div id="filterPanel" class="collapse show">
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-12 col-md-4">
                                    <label class="form-label">Kaynak Tipi</label>
                                    <select class="form-select select2-basic" id="filtreTip">
                                        <option value="">Tümü</option>
                                        <option value="web">Web</option>
                                        <option value="mobil">Mobil</option>
                                        <option value="api">API</option>
                                        <option value="email">E-Posta</option>
                                        <option value="diger">Diğer</option>
                                    </select>
                                </div>
                                <div class="col-12 col-md-4">
                                    <label class="form-label">Durum</label>
                                    <select class="form-select select2-basic" id="filtreDurum">
                                        <option value="">Tümü</option>
                                        <option value="1">Aktif</option>
                                        <option value="0">Pasif</option>
                                    </select>
                                </div>
                                <div class="col-12 col-md-4">
                                    <label class="form-label">Personel</label>
                                    <select class="form-select select2-basic" id="filtrePersonel">
                                        <option value="">Tümü</option>
                                        <?php
                                        $filtrePersonelleri = $db->fetchAll(
                                            "SELECT DISTINCT
                                                k.kullanici_id,
                                                LTRIM(RTRIM(k.kullanici_ad + ' ' + ISNULL(k.kullanici_soyad, ''))) AS ad_soyad
                                             FROM Ticket_Kaynak_Personel p
                                             INNER JOIN kullanicilar k ON p.Ticket_Kaynak_Personel_kullanici_id = k.kullanici_id
                                             WHERE p.Durum = 1
                                             ORDER BY ad_soyad ASC"
                                        );
                                        foreach ($filtrePersonelleri as $fp):
                                        ?>
                                            <option value="<?= htmlspecialchars($fp['ad_soyad']) ?>"><?= htmlspecialchars($fp['ad_soyad']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-12 col-md-4">
                                    <label class="form-label">Arama</label>
                                    <input type="text" class="form-control" id="filtreArama" placeholder="Ad, kod veya URL">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0">
                            <i class="bi bi-hdd-network me-1"></i> Kaynak Listesi
                        </h5>
                        <button class="btn btn-success btn-sm" id="btnYeniKaynak">
                            <i class="bi bi-plus-circle me-1"></i> Yeni Kaynak
                        </button>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table id="kaynakTable" class="table table-hover table-bordered table-sm align-middle" style="width:100%">
                                <thead class="table-light">
                                    <tr>
                                        <th>Kaynak</th>
                                        <th>Tip</th>
                                        <th>Kod</th>
                                        <th>API URL</th>
                                        <th>API Key</th>
                                        <th>Personel</th>
                                        <th>Durum</th>
                                        <th>Güncelleme</th>
                                        <th>İşlemler</th>
                                    </tr>
                                </thead>
                                <tbody id="kaynakTableBody"></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <?php include __DIR__ . '/../includes/footer.php'; ?>
</div>

<div class="modal fade" id="kaynakModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <form id="kaynakForm">
                <div class="modal-header">
                    <h5 class="modal-title" id="kaynakModalTitle">Yeni Kaynak</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="kaynakId" name="id" value="0">

                    <div class="row g-3">
                        <div class="col-12 col-md-6">
                            <label class="form-label fw-semibold">Kaynak Adı <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="ad" name="ad" maxlength="150" required>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label fw-semibold">Kaynak Tipi <span class="text-danger">*</span></label>
                            <select class="form-select select2-modal" id="tip" name="tip" required>
                                <option value="web">Web</option>
                                <option value="mobil">Mobil</option>
                                <option value="api">API</option>
                                <option value="email">E-Posta</option>
                                <option value="diger">Diğer</option>
                            </select>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label fw-semibold">Kaynak Kodu <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="kod" name="kod" maxlength="100" placeholder="SITE_A" required>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label fw-semibold">API URL</label>
                            <input type="text" class="form-control" id="api_url" name="api_url" maxlength="500" placeholder="https://sitea.com">
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label fw-semibold">Destek Sayfası <small class="text-muted">(Ticket Sayfası)</small></label>
                            <input type="text" class="form-control" id="destek_sayfasi" name="destek_sayfasi" maxlength="500" placeholder="/destek.php">
                            <div class="form-text">API URL + bu değer bildirim linkinde kullanılır. Örn: https://sitea.com/destek.php</div>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label fw-semibold">Secret Key</label>
                            <input type="text" class="form-control" id="secret_key" name="secret_key" maxlength="200">
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label fw-semibold">Mevcut API Key</label>
                            <input type="text" class="form-control" id="api_key_info" readonly>
                            <div class="form-text">Yeni kayıtta otomatik üretilir. Düzenlemede yenile butonu kullanılır.</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Sabit Kullanıcı <small class="text-muted">(Opsiyonel)</small></label>
                            <select class="form-select select2-modal" id="kullanici_id" name="kullanici_id">
                                <option value="">-- Seçilmedi (API'den kullanıcı gönderilmeli) --</option>
                                <?php
                                $kullanicilar = $db->fetchAll("SELECT kullanici_id, kullanici_ad, kullanici_soyad, kullanici_email FROM kullanicilar WHERE kullanici_durum = 1 ORDER BY kullanici_ad ASC");
                                foreach ($kullanicilar as $kul):
                                ?>
                                    <option value="<?= (int)$kul['kullanici_id'] ?>">
                                        <?= htmlspecialchars($kul['kullanici_ad'] . ' ' . ($kul['kullanici_soyad'] ?? '')) ?> (<?= htmlspecialchars($kul['kullanici_email'] ?? '') ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Seçilirse create_ticket'ta kullanıcı gönderilmese bile bu kullanıcı ile işlem yapılır.</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Personeller <small class="text-muted">(Kaynağı Görebilecek Kişiler)</small></label>
                            <select class="form-select select2-modal" id="personel_idler" name="personel_idler[]" multiple>
                                <?php foreach ($kullanicilar as $kul): ?>
                                    <option value="<?= (int)$kul['kullanici_id'] ?>">
                                        <?= htmlspecialchars($kul['kullanici_ad'] . ' ' . ($kul['kullanici_soyad'] ?? '')) ?> (<?= htmlspecialchars($kul['kullanici_email'] ?? '') ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Menü yetkisinde <strong>Kaynağı Gör</strong> açık olan departmanlardaki personeller yalnızca burada seçildikleri kaynakların taleplerini görür.</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Açıklama</label>
                            <textarea class="form-control" id="aciklama" name="aciklama" rows="3" maxlength="500"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer d-flex justify-content-between">
                    <button type="button" class="btn btn-warning" id="btnModalApiKeyYenile" style="display:none;">
                        <i class="bi bi-arrow-repeat me-1"></i> API Key Yenile
                    </button>
                    <div class="ms-auto d-flex gap-2">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Vazgeç</button>
                        <button type="submit" class="btn btn-success" id="btnKaydet">
                            <i class="bi bi-check2-circle me-1"></i> Kaydet
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11" crossorigin="anonymous"></script>
<script src="/admin/assets/js/adminlte.min.js"></script>
<script src="/admin/assets/js/custom.js"></script>

<script>
const PAGE_PATH = window.location.pathname;
let table = null;
let kaynaklar = [];
const modal = new bootstrap.Modal(document.getElementById('kaynakModal'));

function escHtml(text) {
    return String(text || '').replace(/[&<>'"]/g, function (m) {
        return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '\'': '&#39;', '"': '&quot;'}[m];
    });
}

function postAjax(data, callback) {
    $.ajax({
        url: PAGE_PATH,
        method: 'POST',
        data: data,
        success: function (res) {
            if (typeof res === 'string') {
                try { res = JSON.parse(res); } catch (e) {}
            }
            callback(res);
        },
        error: function () {
            Swal.fire('Hata', 'Sunucu bağlantı hatası.', 'error');
        }
    });
}

function keyMask(key) {
    if (!key) return '-';
    if (key.length <= 12) return key;
    return key.substring(0, 6) + '...' + key.substring(key.length - 6);
}

function fillStats() {
    postAjax({action: 'istatistik'}, function (res) {
        if (!res.success) return;
        $('#boxToplam').text(res.data.toplam || 0);
        $('#boxAktif').text(res.data.aktif || 0);
        $('#boxPasif').text(res.data.pasif || 0);
        $('#boxGuncel').text(res.data.son_guncelleme || '-');
    });
}

function fillTable() {
    postAjax({action: 'liste'}, function (res) {
        if (!res.success) {
            Swal.fire('Hata', res.message || 'Liste yüklenemedi.', 'error');
            return;
        }

        kaynaklar = res.data || [];

        if (table) {
            table.clear().rows.add(kaynaklar).draw();
        } else {
            table = $('#kaynakTable').DataTable({
                data: kaynaklar,
                columns: [
                    { data: 'Ticket_Kaynak_ad' },
                    { data: 'Ticket_Kaynak_tip' },
                    { data: 'Ticket_Kaynak_kod' },
                    { data: 'Ticket_Kaynak_api_url' },
                    { data: 'Ticket_Kaynak_api_key' },
                    { data: 'personel_adlar' },
                    { data: 'Durum' },
                    { data: 'guncelleme_tarihi' },
                    { data: null }
                ],
                columnDefs: [
                    {
                        targets: 1,
                        render: function (data) {
                            const map = {
                                web: '<span class="badge text-bg-primary">Web</span>',
                                mobil: '<span class="badge text-bg-info">Mobil</span>',
                                api: '<span class="badge text-bg-dark">API</span>',
                                email: '<span class="badge text-bg-warning">E-Posta</span>',
                                diger: '<span class="badge text-bg-secondary">Diğer</span>'
                            };
                            return map[data] || '<span class="badge text-bg-secondary">Diğer</span>';
                        }
                    },
                    {
                        targets: 3,
                        render: function (data) {
                            if (!data) return '<span class="text-muted">-</span>';
                            return '<a href="' + escHtml(data) + '" target="_blank" rel="noopener">' + escHtml(data) + '</a>';
                        }
                    },
                    {
                        targets: 4,
                        render: function (data, type, row) {
                            const masked = keyMask(data);
                            return '<div class="d-flex align-items-center gap-1">'
                                + '<span class="api-key-mask">' + escHtml(masked) + '</span>'
                                + '<button class="btn btn-outline-secondary btn-xs btn-sm" onclick="copyKey(\'' + escHtml(data) + '\')" title="Kopyala">'
                                + '<i class="bi bi-clipboard"></i></button></div>';
                        }
                    },
                    {
                        targets: 5,
                        render: function (data, type) {
                            const liste = data || [];
                            if (type === 'filter' || type === 'sort') {
                                return liste.join(' | ');
                            }
                            if (!liste.length) return '<span class="text-muted">-</span>';

                            const gosterilen = liste.slice(0, 2).map(function (ad) {
                                return '<span class="badge text-bg-light border">' + escHtml(ad) + '</span>';
                            }).join(' ');

                            const kalan = liste.length - 2;
                            return '<div class="d-flex flex-wrap gap-1">' + gosterilen
                                + (kalan > 0 ? ' <span class="badge text-bg-secondary" title="' + escHtml(liste.join(', ')) + '">+' + kalan + '</span>' : '')
                                + '</div>';
                        }
                    },
                    {
                        targets: 6,
                        render: function (data) {
                            return data == 1
                                ? '<span class="badge text-bg-success">Aktif</span>'
                                : '<span class="badge text-bg-secondary">Pasif</span>';
                        }
                    },
                    {
                        targets: 8,
                        orderable: false,
                        render: function (data, type, row) {
                            const aktifBtn = row.Durum == 1
                                ? '<button class="btn btn-secondary btn-xs btn-sm" onclick="durumDegistir(' + row.Ticket_Kaynak_id + ',0)"><i class="bi bi-pause"></i></button>'
                                : '<button class="btn btn-success btn-xs btn-sm" onclick="durumDegistir(' + row.Ticket_Kaynak_id + ',1)"><i class="bi bi-play"></i></button>';

                            return '<div class="d-flex gap-1">'
                                + '<button class="btn btn-warning btn-xs btn-sm" onclick="duzenle(' + row.Ticket_Kaynak_id + ')" title="Düzenle"><i class="bi bi-pencil"></i></button>'
                                + '<button class="btn btn-info btn-xs btn-sm" onclick="apiKeyYenile(' + row.Ticket_Kaynak_id + ')" title="API Key Yenile"><i class="bi bi-key"></i></button>'
                                + '<a class="btn btn-primary btn-xs btn-sm" href="/api/ticket-entegrasyon-indir.php?kaynak_id=' + row.Ticket_Kaynak_id + '" title="Entegrasyon Dosyası İndir"><i class="bi bi-download"></i></a>'
                                + aktifBtn
                                + '</div>';
                        }
                    }
                ],
                order: [[7, 'desc']],
                language: { url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json' }
            });

            $('#filtreTip').on('change', function () {
                table.column(1).search($(this).val()).draw();
            });
            $('#filtrePersonel').on('change', function () {
                const v = $(this).val();
                table.column(5).search(v ? v : '', false, true).draw();
            });
            $('#filtreDurum').on('change', function () {
                const v = $(this).val();
                if (v === '') {
                    table.column(6).search('').draw();
                } else if (v === '1') {
                    table.column(6).search('Aktif').draw();
                } else {
                    table.column(6).search('Pasif').draw();
                }
            });
            $('#filtreArama').on('keyup', function () {
                table.search($(this).val()).draw();
            });
        }
    });
}

function copyKey(key) {
    navigator.clipboard.writeText(key || '').then(function () {
        Swal.fire({toast: true, position: 'top-end', icon: 'success', title: 'API key kopyalandı', showConfirmButton: false, timer: 1600});
    });
}

function resetForm() {
    $('#kaynakForm')[0].reset();
    $('#kaynakId').val(0);
    $('#api_key_info').val('Otomatik üretilecek');
    $('#btnModalApiKeyYenile').hide();
    $('#tip').val('web').trigger('change');
    $('#kullanici_id').val('').trigger('change');
    $('#personel_idler').val([]).trigger('change');
    $('#destek_sayfasi').val('');
}

function duzenle(id) {
    const row = kaynaklar.find(function (x) { return x.Ticket_Kaynak_id == id; });
    if (!row) return;

    $('#kaynakModalTitle').text('Kaynak Düzenle');
    $('#kaynakId').val(row.Ticket_Kaynak_id);
    $('#ad').val(row.Ticket_Kaynak_ad || '');
    $('#tip').val(row.Ticket_Kaynak_tip || 'web').trigger('change');
    $('#kod').val(row.Ticket_Kaynak_kod || '');
    $('#api_url').val(row.Ticket_Kaynak_api_url || '');
    $('#destek_sayfasi').val(row.Ticket_Kaynak_destek_sayfasi || '');
    $('#secret_key').val(row.Ticket_Kaynak_secret_key || '');
    $('#kullanici_id').val(row.Ticket_Kaynak_kullanici_id || '').trigger('change');
    $('#personel_idler').val((row.personel_idler || []).map(String)).trigger('change');
    $('#aciklama').val(row.Ticket_Kaynak_aciklama || '');
    $('#api_key_info').val(row.Ticket_Kaynak_api_key || '');
    $('#btnModalApiKeyYenile').show();
    modal.show();
}

function durumDegistir(id, durum) {
    postAjax({action: 'durum_degistir', id: id, durum: durum}, function (res) {
        if (!res.success) {
            Swal.fire('Hata', res.message, 'error');
            return;
        }
        fillStats();
        fillTable();
        Swal.fire({toast: true, position: 'top-end', icon: 'success', title: res.message, showConfirmButton: false, timer: 1800});
    });
}

function apiKeyYenile(id) {
    Swal.fire({
        title: 'API anahtarı yenilensin mi?',
        text: 'Eski anahtar geçersiz olacaktır.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Evet, yenile',
        cancelButtonText: 'Vazgeç'
    }).then(function (r) {
        if (!r.isConfirmed) return;
        postAjax({action: 'api_key_yenile', id: id}, function (res) {
            if (!res.success) {
                Swal.fire('Hata', res.message, 'error');
                return;
            }
            fillTable();
            Swal.fire('Başarılı', 'Yeni API anahtarı üretildi.', 'success');
        });
    });
}

$(document).ready(function () {
    $('.select2-basic').select2({ theme: 'bootstrap-5', width: '100%' });
    $('.select2-modal').select2({ theme: 'bootstrap-5', width: '100%', dropdownParent: $('#kaynakModal') });

    fillStats();
    fillTable();

    $('#btnYeniKaynak').on('click', function () {
        resetForm();
        $('#kaynakModalTitle').text('Yeni Kaynak');
        modal.show();
    });

    $('#btnModalApiKeyYenile').on('click', function () {
        const id = parseInt($('#kaynakId').val() || '0', 10);
        if (id > 0) {
            apiKeyYenile(id);
        }
    });

    $('#kaynakForm').on('submit', function (e) {
        e.preventDefault();

        const ad = ($('#ad').val() || '').trim();
        const kod = ($('#kod').val() || '').trim();
        if (!ad || !kod) {
            Swal.fire('Uyarı', 'Kaynak adı ve kodu zorunludur.', 'warning');
            return;
        }

        const data = $(this).serializeArray();
        data.push({name: 'action', value: 'kaydet'});

        $('#btnKaydet').prop('disabled', true);
        postAjax(data, function (res) {
            $('#btnKaydet').prop('disabled', false);
            if (!res.success) {
                Swal.fire('Hata', res.message, 'error');
                return;
            }
            modal.hide();
            fillStats();
            fillTable();
            Swal.fire({toast: true, position: 'top-end', icon: 'success', title: res.message, showConfirmButton: false, timer: 1800});
        });
    });
});
</script>
</body>
</html>
