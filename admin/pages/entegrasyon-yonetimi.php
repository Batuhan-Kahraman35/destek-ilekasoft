<?php
/**
 * Entegrasyon Yönetimi
 * Mail, WhatsApp ve SMS kanal ayarları ile bağlantı testleri
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
requireAuth();

$user = Auth::user();
$db   = Database::getInstance();

// Sayfa yetki kontrolü
$currentPageFile = basename($_SERVER['PHP_SELF']);
$pagePermissions = PageAuth::checkPagePermissions(
    $user['kullanici_id'],
    $user['departman_id'],
    $currentPageFile
);

if (!$pagePermissions['has_access']) {
    PageAuth::accessDenied($pagePermissions['error'] ?? 'Bu sayfaya erişim yetkiniz bulunmamaktadır.');
}

// Sayfa başlığı
$pageInfo = $db->fetchOne("
    SELECT s.sayfalar_sayfa_adi, s.sayfalar_aciklama, m.menuler_menu_adi as menu_adi
    FROM Menu_Sayfalar s
    LEFT JOIN Menuler m ON s.sayfalar_menu_id = m.menuler_id
    WHERE s.sayfalar_sayfa_url LIKE ? AND s.sayfalar_durum = 1
", ['%' . $currentPageFile]);

$pageTitle   = $pageInfo['sayfalar_sayfa_adi'] ?? 'Entegrasyon Yönetimi';
$menuAdi     = $pageInfo['menu_adi'] ?? null;
$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle   = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';

// ─────────────────────────────────────────────
// AJAX İşlemleri
// ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {

            // İstatistik
            case 'stats':
                $toplam   = $db->fetchOne("SELECT COUNT(*) AS sayi FROM Entegrasyon WHERE Entegrasyon_Durum = 1")['sayi'] ?? 0;
                $aktifKanal = $db->fetchOne("SELECT COUNT(*) AS sayi FROM EntegrasyonKanallari WHERE Kanal_Durum = 1")['sayi'] ?? 0;
                $logBugün = $db->fetchOne("SELECT COUNT(*) AS sayi FROM EntegrasyonLoglari WHERE CAST(Log_Tarih AS DATE) = CAST(GETDATE() AS DATE)")['sayi'] ?? 0;
                $hataBugün = $db->fetchOne("SELECT COUNT(*) AS sayi FROM EntegrasyonLoglari WHERE Log_Durum = 'ERROR' AND CAST(Log_Tarih AS DATE) = CAST(GETDATE() AS DATE)")['sayi'] ?? 0;
                echo json_encode(['success' => true, 'data' => compact('toplam', 'aktifKanal', 'logBugün', 'hataBugün')]);
                break;

            // Entegrasyon listesi (kanallarla birlikte)
            case 'list':
                $rows = $db->fetchAll("
                    SELECT
                        e.Entegrasyon_Id,
                        e.Entegrasyon_Kod,
                        e.Entegrasyon_Adi,
                        e.Entegrasyon_Aciklama,
                        e.Entegrasyon_Ikon,
                        e.Entegrasyon_Renk,
                        e.Entegrasyon_Sira,
                        e.Entegrasyon_Durum,
                        -- Varsayılan kanal
                        k.Kanal_Id,
                        k.Kanal_Adi,
                        k.Kanal_Ortam,
                        k.Kanal_Endpoint,
                        k.Kanal_Kullanici,
                        k.Kanal_Parametreler,
                        k.Kanal_Varsayilan,
                        k.Kanal_Durum,
                        CONVERT(VARCHAR(16), k.Kanal_Son_Kullanim, 120) AS Son_Kullanim,
                        -- Log özeti
                        (SELECT COUNT(*) FROM EntegrasyonLoglari l WHERE l.Log_Kanal_Id = k.Kanal_Id
                         AND CAST(l.Log_Tarih AS DATE) = CAST(GETDATE() AS DATE)) AS LogBugun,
                        (SELECT COUNT(*) FROM EntegrasyonLoglari l WHERE l.Log_Kanal_Id = k.Kanal_Id
                         AND l.Log_Durum = 'ERROR'
                         AND CAST(l.Log_Tarih AS DATE) = CAST(GETDATE() AS DATE)) AS HataBugun
                    FROM Entegrasyon e
                    LEFT JOIN EntegrasyonKanallari k
                           ON k.Kanal_Entegrasyon_Id = e.Entegrasyon_Id AND k.Kanal_Varsayilan = 1
                    ORDER BY e.Entegrasyon_Sira
                ");
                echo json_encode(['success' => true, 'data' => $rows]);
                break;

            // Kanal kaydet (ekle/güncelle)
            case 'kanal_kaydet':
                $kanalId         = (int)($_POST['kanal_id'] ?? 0);
                $entegrasyonId   = (int)($_POST['entegrasyon_id'] ?? 0);
                $kanalAdi        = trim($_POST['kanal_adi'] ?? '');
                $endpoint        = trim($_POST['endpoint'] ?? '');
                $kullanici       = trim($_POST['kullanici'] ?? '');
                $sifre           = trim($_POST['sifre'] ?? '');
                $parametreler    = trim($_POST['parametreler'] ?? '{}');
                $durum           = isset($_POST['durum']) ? 1 : 0;

                if (!$entegrasyonId || !$kanalAdi) {
                    echo json_encode(['success' => false, 'message' => 'Zorunlu alanlar eksik.']);
                    break;
                }

                // JSON geçerliliği
                if (!empty($parametreler) && json_decode($parametreler) === null) {
                    echo json_encode(['success' => false, 'message' => 'Parametreler geçerli JSON değil.']);
                    break;
                }

                if ($kanalId > 0) {
                    // Güncelle
                    $data = [
                        'Kanal_Adi'          => $kanalAdi,
                        'Kanal_Endpoint'     => $endpoint,
                        'Kanal_Kullanici'    => $kullanici,
                        'Kanal_Parametreler' => $parametreler,
                        'Kanal_Durum'        => $durum,
                        'GuncelleyenKullanici' => $user['kullanici_id'],
                        'GuncellemeTarihi'   => app_now(),
                    ];
                    // Şifre doluysa güncelle
                    if (!empty($sifre)) {
                        $data['Kanal_Sifre'] = $sifre;
                    }
                    $db->update('EntegrasyonKanallari', $data, ['Kanal_Id' => $kanalId]);
                    echo json_encode(['success' => true, 'message' => 'Kanal güncellendi.']);
                } else {
                    // Ekle
                    $db->insert('EntegrasyonKanallari', [
                        'Kanal_Entegrasyon_Id'  => $entegrasyonId,
                        'Kanal_Adi'             => $kanalAdi,
                        'Kanal_Ortam'           => 'PROD',
                        'Kanal_Endpoint'        => $endpoint,
                        'Kanal_Kullanici'       => $kullanici,
                        'Kanal_Sifre'           => $sifre,
                        'Kanal_Parametreler'    => $parametreler,
                        'Kanal_Varsayilan'      => 1,
                        'Kanal_Durum'           => $durum,
                        'OlusturanKullanici'    => $user['kullanici_id'],
                        'OlusturmaTarihi'       => app_now(),
                    ]);
                    echo json_encode(['success' => true, 'message' => 'Kanal eklendi.']);
                }
                break;

            // Kanal sil
            case 'kanal_sil':
                if (!$pagePermissions['can_delete']) {
                    echo json_encode(['success' => false, 'message' => 'Silme yetkiniz yok.']);
                    break;
                }
                $kanalId = (int)($_POST['kanal_id'] ?? 0);
                if (!$kanalId) {
                    echo json_encode(['success' => false, 'message' => 'Kanal ID gerekli.']);
                    break;
                }
                // Log kaydı varsa fiziksel silme yapma, pasife al
                $logSayisi = $db->fetchOne("SELECT COUNT(*) AS sayi FROM EntegrasyonLoglari WHERE Log_Kanal_Id = ?", [$kanalId])['sayi'] ?? 0;
                if ($logSayisi > 0) {
                    $db->update('EntegrasyonKanallari', [
                        'Kanal_Durum'          => 0,
                        'GuncelleyenKullanici' => $user['kullanici_id'],
                        'GuncellemeTarihi'     => app_now(),
                    ], ['Kanal_Id' => $kanalId]);
                    echo json_encode(['success' => true, 'message' => 'Kanal pasife alındı. (Log kaydı mevcut olduğundan fiziksel silinmedi.)']);
                } else {
                    $db->execute("DELETE FROM EntegrasyonKanallari WHERE Kanal_Id = ?", [$kanalId]);
                    echo json_encode(['success' => true, 'message' => 'Kanal silindi.']);
                }
                break;

            // Bağlantı testi
            case 'baglanti_test':
                $kod = strtoupper(trim($_POST['kod'] ?? ''));
                if (!$kod) {
                    echo json_encode(['success' => false, 'message' => 'Entegrasyon kodu gerekli.']);
                    break;
                }

                require_once __DIR__ . '/../includes/EntegrasyonHelper.php';
                $helper = new EntegrasyonHelper($db);
                $sonuc  = $helper->baglantiTest($kod);
                echo json_encode($sonuc);
                break;

            // Test mesajı gönder
            case 'test_gonder':
                $kod    = strtoupper(trim($_POST['kod'] ?? ''));
                $hedef  = trim($_POST['hedef'] ?? '');
                $mesaj  = trim($_POST['mesaj'] ?? 'Test mesajı - Örnek Yazılım Entegrasyon');

                if (!$kod || !$hedef) {
                    echo json_encode(['success' => false, 'message' => 'Kod ve hedef zorunludur.']);
                    break;
                }

                require_once __DIR__ . '/../includes/EntegrasyonHelper.php';
                $helper = new EntegrasyonHelper($db);

                switch ($kod) {
                    case 'MAIL':
                        $sonuc = $helper->mailGonder($hedef, 'Test Maili - Örnek Yazılım', '<h3>' . htmlspecialchars($mesaj) . '</h3>');
                        break;
                    case 'WHATSAPP':
                        $sonuc = $helper->whatsappGonder($hedef, $mesaj);
                        break;
                    case 'SMS':
                        $sonuc = $helper->smsGonder($hedef, $mesaj);
                        break;
                    default:
                        $sonuc = ['success' => false, 'message' => 'Bilinmeyen entegrasyon.'];
                }
                echo json_encode($sonuc);
                break;

            default:
                echo json_encode(['success' => false, 'message' => 'Geçersiz işlem.']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Hata: ' . $e->getMessage()]);
    }
    exit;
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
    <link rel="stylesheet" href="/Admin/assets/css/Adminlte.min.css">
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/Admin/assets/css/custom.css">
    <!-- DataTables & Select2 -->
    <link href="https://cdn.datatables.net/v/bs5/dt-2.0.7/datatables.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />

    <style>
        .entegrasyon-kart {
            border-left: 4px solid #dee2e6;
            transition: box-shadow .2s;
        }
        .entegrasyon-kart:hover { box-shadow: 0 4px 12px rgba(0,0,0,.1); }
        .test-sonuc { font-size: .85rem; margin-top: 6px; min-height: 22px; }
        .params-textarea { font-family: monospace; font-size: .8rem; }
        .badge-durum-ok  { background-color: #198754; }
        .badge-durum-err { background-color: #dc3545; }
        .sifre-goster { cursor:pointer; }
    </style>
</head>
<body class="layout-fixed sidebar-expand-lg sidebar-open bg-body-tertiary">
<div class="app-wrapper">
    <?php include __DIR__ . '/../includes/header.php'; ?>
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>

    <main class="app-main">
        <!-- Sayfa Başlığı -->
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

        <!-- İçerik -->
        <div class="app-content">
            <div class="container-fluid">

                <!-- Info Boxes -->
                <div class="row mb-3">
                    <div class="col-12 col-sm-6 col-md-3">
                        <div class="info-box">
                            <span class="info-box-icon text-bg-primary shadow-sm"><i class="bi bi-plug-fill"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Entegrasyon</span>
                                <span class="info-box-number" id="stat-toplam">–</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-md-3">
                        <div class="info-box">
                            <span class="info-box-icon text-bg-success shadow-sm"><i class="bi bi-check2-circle"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Aktif Kanal</span>
                                <span class="info-box-number" id="stat-kanal">–</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-md-3">
                        <div class="info-box">
                            <span class="info-box-icon text-bg-info shadow-sm"><i class="bi bi-journal-text"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Log (Bugün)</span>
                                <span class="info-box-number" id="stat-log">–</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-md-3">
                        <div class="info-box">
                            <span class="info-box-icon text-bg-danger shadow-sm"><i class="bi bi-exclamation-octagon"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Hata (Bugün)</span>
                                <span class="info-box-number" id="stat-hata">–</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filtre Alanı -->
                <div class="card mb-3">
                    <div class="card-header" data-bs-toggle="collapse" data-bs-target="#filtreAlan" style="cursor: pointer;">
                        <h3 class="card-title"><i class="bi bi-funnel me-1"></i>Filtreler</h3>
                        <div class="card-tools">
                            <button type="button" class="btn btn-tool bg-transparent border-0"><i class="bi bi-chevron-down"></i></button>
                        </div>
                    </div>
                    <div id="filtreAlan" class="collapse show">
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-md-3">
                                    <label class="form-label fw-semibold">Durum</label>
                                    <select class="form-select select2-init" id="filtreDurum">
                                        <option value="">Tümü</option>
                                        <option value="1">Aktif</option>
                                        <option value="0">Pasif</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label fw-semibold">Entegrasyon Tipi</label>
                                    <select class="form-select select2-init" id="filtreTip">
                                        <option value="">Tümü</option>
                                        <option value="MAIL">Mail</option>
                                        <option value="WHATSAPP">WhatsApp</option>
                                        <option value="SMS">SMS</option>
                                    </select>
                                </div>
                                <div class="col-md-6 d-flex align-items-end justify-content-end">
                                    <button class="btn btn-secondary" type="button" id="btnFiltreTemizle"><i class="bi bi-eraser me-1"></i>Temizle</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tablo Alanı -->
                <div class="card">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table id="entegrasyonTable" class="table table-bordered table-striped w-100 align-middle">
                                <thead>
                                    <tr>
                                        <th style="width: 40px" class="text-center">İkon</th>
                                        <th>Entegrasyon</th>
                                        <th>Kanal</th>
                                        <th>Sunucu / Endpoint</th>
                                        <th>Kullanıcı</th>
                                        <th>Son Kullanım</th>
                                        <th>Log / Hata (Bugün)</th>
                                        <th>Durum</th>
                                        <th style="width: 140px" class="text-end">İşlemler</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr><td colspan="9" class="text-center text-muted py-4"><i class="bi bi-arrow-repeat spin fs-4"></i><br>Yükleniyor…</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Log Bağlantısı -->
                <div class="row mt-3">
                    <div class="col-12 text-end">
                        <a href="/admin/entegrasyon-loglari" class="btn btn-outline-secondary btn-sm">
                            <i class="bi bi-journal-text me-1"></i>Tüm Logları Görüntüle
                        </a>
                    </div>
                </div>

            </div>
        </div>
    </main>

    <?php include __DIR__ . '/../includes/footer.php'; ?>
</div>

<!-- MODAL: Kanal Düzenle -->
<div class="modal fade" id="kanalModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalBaslik">Kanal Ayarları</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="kanalForm">
                <div class="modal-body">
                    <input type="hidden" id="kanal_id"        name="kanal_id">
                    <input type="hidden" id="entegrasyon_id"  name="entegrasyon_id">
                    <input type="hidden" name="action"        value="kanal_kaydet">

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Kanal Adı <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="kanal_adi" name="kanal_adi" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Sunucu / Endpoint</label>
                            <input type="text" class="form-control" id="endpoint" name="endpoint" placeholder="smtp.gmail.com veya http://…">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Kullanıcı Adı / E-posta</label>
                            <input type="text" class="form-control" id="kullanici" name="kullanici"
                                   autocomplete="new-password">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Şifre / API Key</label>
                            <div class="input-group">
                                <input type="password" class="form-control" id="sifre" name="sifre"
                                       autocomplete="new-password" placeholder="Değiştirmek için doldurun">
                                <button type="button" class="btn btn-outline-secondary sifre-goster"
                                        onclick="toggleSifre()">
                                    <i class="bi bi-eye" id="sifreIkon"></i>
                                </button>
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Parametreler (JSON)</label>
                            <div id="params-hint" class="text-muted small mb-1"></div>
                            <textarea class="form-control params-textarea" id="parametreler" name="parametreler"
                                      rows="4" placeholder='{"port":465,"encryption":"SSL"}'>{}</textarea>
                            <div class="form-text">Şifreleme, port, instance adı gibi ek ayarlar.</div>
                        </div>
                        <div class="col-12">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="durum" name="durum" checked>
                                <label class="form-check-label" for="durum">Aktif</label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle me-1"></i>İptal
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save me-1"></i>Kaydet
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODAL: Test Gönder -->
<div class="modal fade" id="testModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="testModalBaslik">Test Mesajı Gönder</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="test_kod">
                <div class="mb-3">
                    <label class="form-label fw-semibold" id="testHedefLabel">Alıcı</label>
                    <input type="text" class="form-control" id="test_hedef"
                           placeholder="E-posta, telefon numarası…">
                    <div class="form-text" id="testHedefYardim"></div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Test Mesajı</label>
                    <textarea class="form-control" id="test_mesaj" rows="3">Merhaba! Bu bir test mesajıdır. — Örnek Yazılım</textarea>
                </div>
                <div id="testSonuc" class="alert d-none"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-x-circle me-1"></i>Kapat
                </button>
                <button type="button" class="btn btn-success" id="testGonderBtn">
                    <i class="bi bi-send me-1"></i>Gönder
                </button>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
<script src="https://cdn.datatables.net/v/bs5/dt-2.0.7/datatables.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="/Admin/assets/js/Adminlte.min.js"></script>
<script src="/Admin/assets/js/custom.js"></script>

<script>
const pagePermissions = {
    can_add:    <?= $pagePermissions['can_add']    ? 'true' : 'false' ?>,
    can_edit:   <?= $pagePermissions['can_edit']   ? 'true' : 'false' ?>,
    can_delete: <?= $pagePermissions['can_delete'] ? 'true' : 'false' ?>
};

const kanalModal = new bootstrap.Modal(document.getElementById('kanalModal'));
const testModal  = new bootstrap.Modal(document.getElementById('testModal'));

// ── İstatistikler ──────────────────────────────
function istatistikYukle() {
    $.post('', { action: 'stats' }, function(r) {
        if (!r.success) return;
        $('#stat-toplam').text(r.data.toplam);
        $('#stat-kanal').text(r.data.aktifKanal);
        $('#stat-log').text(r.data['logBugün']);
        $('#stat-hata').text(r.data['hataBugün']);
    });
}

// ── Entegrasyon Kartları ───────────────────────
const ikonMap = {
    'MAIL':      'bi bi-envelope-fill',
    'WHATSAPP':  'bi bi-whatsapp',
    'SMS':       'bi bi-chat-dots-fill',
};

const renkMap = {
    'MAIL':      '#EA4335',
    'WHATSAPP':  '#25D366',
    'SMS':       '#FF6B00',
};

const paramsHintMap = {
    'MAIL':     '{"port": 465, "encryption": "SSL", "from_name": "Firma Adı"}',
    'WHATSAPP': '{"instance": "InstanceAdi"}',
    'SMS':      '{"method": "POST", "originator": "GONDEREN", "action": "12"}',
};

let table;

function entegrasyonlariYukle() {
    $.post('', { action: 'list' }, function(r) {
        if (!r.success) return;
        
        if ($.fn.DataTable.isDataTable('#entegrasyonTable')) {
            $('#entegrasyonTable').DataTable().destroy();
        }

        const $tbody = $('#entegrasyonTable tbody');
        $tbody.empty();

        r.data.forEach(function(e) {
            const renk  = e.Entegrasyon_Renk || renkMap[e.Entegrasyon_Kod] || '#6c757d';
            const ikon  = e.Entegrasyon_Ikon  || ikonMap[e.Entegrasyon_Kod] || 'bi bi-plug';
            const durumMetin = e.Kanal_Durum == 1 ? 'Aktif' : 'Pasif';
            const durum = e.Kanal_Durum == 1 ? '<span class="badge text-bg-success">Aktif</span>' : '<span class="badge text-bg-secondary">Pasif</span>';
            const sK    = e.Son_Kullanim ? e.Son_Kullanim : '—';
            const logBadge = `<span class="badge text-bg-info">${e.LogBugun ?? 0} log</span>`;
            const hataBadge = (e.HataBugun > 0)
                ? `<span class="badge text-bg-danger ms-1">${e.HataBugun} hata</span>` : '';

            // İşlemler
            let islemler = `<div class="d-flex gap-1 justify-content-end">
                <button class="btn btn-sm btn-outline-secondary" onclick="baglantiTest('${e.Entegrasyon_Kod}', ${e.Kanal_Id ?? 0})" title="Bağlantıyı Test Et"><i class="bi bi-wifi"></i></button>
                <button class="btn btn-sm btn-outline-success" onclick="testMesajAc('${e.Entegrasyon_Kod}', '${escHtml(e.Entegrasyon_Adi)}')" title="Test Mesajı Gönder"><i class="bi bi-send"></i></button>
            `;
            if (pagePermissions.can_edit) {
                const eStr = escHtml(JSON.stringify(e));
                islemler += `<button class="btn btn-sm btn-outline-primary" onclick="kanalDuzenle(${eStr})" title="Düzenle"><i class="bi bi-pencil"></i></button>`;
            }
            if (pagePermissions.can_delete && e.Kanal_Id) {
                islemler += `<button class="btn btn-sm btn-outline-danger" onclick="kanalSil(${e.Kanal_Id}, '${escHtml(e.Entegrasyon_Adi)}')" title="Sil"><i class="bi bi-trash"></i></button>`;
            }
            islemler += `</div><div class="test-sonuc text-end mt-1" style="font-size:0.75rem;" id="test-sonuc-${e.Entegrasyon_Kod}"></div>`;

            const iconHtml = `<span class="rounded-circle d-inline-flex align-items-center justify-content-center" style="width:32px;height:32px;background:${renk}22"><i class="${ikon}" style="color:${renk}"></i></span>`;
            const entegrasyonHtml = `<strong>${escHtml(e.Entegrasyon_Adi)}</strong><br><small class="text-muted">${escHtml(e.Entegrasyon_Aciklama ?? '')}</small><span class="d-none">${e.Entegrasyon_Kod}</span>`;
            const sunucuHtml = e.Kanal_Endpoint ? `<code class="small text-truncate d-inline-block" style="max-width:180px" title="${escHtml(e.Kanal_Endpoint)}">${escHtml(e.Kanal_Endpoint)}</code>` : '—';

            const html = `
            <tr>
                <td class="text-center">${iconHtml}</td>
                <td>${entegrasyonHtml}</td>
                <td><strong>${escHtml(e.Kanal_Adi ?? '—')}</strong></td>
                <td>${sunucuHtml}</td>
                <td>${escHtml(e.Kanal_Kullanici ?? '—')}</td>
                <td>${escHtml(sK)}</td>
                <td>${logBadge}${hataBadge}</td>
                <td data-order="${e.Kanal_Durum}">${durum}<span class="d-none">${durumMetin}</span></td>
                <td>${islemler}</td>
            </tr>`;
            $tbody.append(html);
        });
        
        initTable();
    });
}

function initTable() {
    table = $('#entegrasyonTable').DataTable({
        language: { url: 'https://cdn.datatables.net/plug-ins/1.13.4/i18n/tr.json' },
        responsive: true,
        autoWidth: false,
        destroy: true,
        dom: '<"row"<"col-sm-12 col-md-6"l><"col-sm-12 col-md-6"f>>rt<"row"<"col-sm-12 col-md-5"i><"col-sm-12 col-md-7"p>>',
        order: [[1, 'asc']],
        columnDefs: [
            { orderable: false, targets: [0, 8] }
        ]
    });
}

// ── Bağlantı Testi ─────────────────────────────
function baglantiTest(kod, kanalId) {
    const $el = $(`#test-sonuc-${kod}`);
    $el.html('<span class="text-muted"><i class="bi bi-arrow-repeat spin me-1"></i>Test ediliyor…</span>');

    $.post('', { action: 'baglanti_test', kod: kod }, function(r) {
        if (r.success) {
            $el.html(`<span class="text-success"><i class="bi bi-check-circle-fill me-1"></i>${escHtml(r.message ?? r.state ?? 'Bağlantı başarılı')}</span>`);
        } else {
            $el.html(`<span class="text-danger"><i class="bi bi-x-circle-fill me-1"></i>${escHtml(r.message ?? 'Bağlantı başarısız')}</span>`);
        }
    }).fail(function() {
        $el.html('<span class="text-danger"><i class="bi bi-x-circle-fill me-1"></i>İstek başarısız.</span>');
    });
}

// ── Test Mesajı ────────────────────────────────
function testMesajAc(kod, adi) {
    $('#test_kod').val(kod);
    $('#testModalBaslik').text(`Test Mesajı — ${adi}`);
    $('#testSonuc').addClass('d-none').text('');

    const hints = {
        'MAIL':     ['E-posta Adresi', 'Örn: deneme@ornek.com'],
        'WHATSAPP': ['Telefon Numarası', 'Örn: 905551234567 (başında + olmadan)'],
        'SMS':      ['Telefon Numarası', 'Örn: 905551234567'],
    };
    const [lbl, hint] = hints[kod] || ['Alıcı', ''];
    $('#testHedefLabel').text(lbl);
    $('#testHedefYardim').text(hint);
    $('#test_hedef').val('');
    testModal.show();
}

$('#testGonderBtn').on('click', function() {
    const kod    = $('#test_kod').val();
    const hedef  = $('#test_hedef').val().trim();
    const mesaj  = $('#test_mesaj').val().trim();

    if (!hedef) { showToast('Alıcı alanı boş olamaz.', 'warning'); return; }

    const $btn = $(this);
    $btn.prop('disabled', true).html('<i class="bi bi-arrow-repeat spin me-1"></i>Gönderiliyor…');
    $('#testSonuc').addClass('d-none');

    $.post('', { action: 'test_gonder', kod, hedef, mesaj }, function(r) {
        const $sonuc = $('#testSonuc');
        $sonuc.removeClass('d-none alert-success alert-danger')
              .addClass(r.success ? 'alert-success' : 'alert-danger')
              .html((r.success ? '<i class="bi bi-check-circle-fill me-2"></i>' : '<i class="bi bi-x-circle-fill me-2"></i>') + escHtml(r.message));
    }).fail(function() {
        $('#testSonuc').removeClass('d-none alert-success').addClass('alert-danger').text('İstek başarısız.');
    }).always(function() {
        $btn.prop('disabled', false).html('<i class="bi bi-send me-1"></i>Gönder');
    });
});

// ── Kanal Düzenle ──────────────────────────────
function kanalDuzenle(e) {
    $('#modalBaslik').text(e.Entegrasyon_Adi + ' — Kanal Ayarları');
    $('#kanal_id').val(e.Kanal_Id ?? '');
    $('#entegrasyon_id').val(e.Entegrasyon_Id);
    $('#kanal_adi').val(e.Kanal_Adi ?? '');
    $('#endpoint').val(e.Kanal_Endpoint ?? '');
    $('#kullanici').val(e.Kanal_Kullanici ?? '');
    $('#sifre').val('');
    $('#durum').prop('checked', e.Kanal_Durum == 1);

    // Parametreler
    let params = '{}';
    if (e.Kanal_Parametreler) {
        try { params = JSON.stringify(JSON.parse(e.Kanal_Parametreler), null, 2); } catch(x) { params = e.Kanal_Parametreler; }
    }
    $('#parametreler').val(params);
    $('#params-hint').text('Örn: ' + (paramsHintMap[e.Entegrasyon_Kod] ?? ''));
    kanalModal.show();
}

// ── Kanal Sil ─────────────────────────────────
function kanalSil(kanalId, adi) {
    Swal.fire({
        title: 'Kanalı Sil',
        html: `<strong>${escHtml(adi)}</strong> kanalını silmek istediğinize emin misiniz?<br><small class="text-muted">Log kaydı varsa pasife alınır.</small>`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        cancelButtonColor: '#6c757d',
        confirmButtonText: '<i class="bi bi-trash me-1"></i>Evet, Sil',
        cancelButtonText: 'İptal',
    }).then(function(result) {
        if (!result.isConfirmed) return;
        $.post('', { action: 'kanal_sil', kanal_id: kanalId }, function(r) {
            showToast(r.message, r.success ? 'success' : 'danger');
            if (r.success) {
                entegrasyonlariYukle();
                istatistikYukle();
            }
        }).fail(function() {
            showToast('İstek başarısız.', 'danger');
        });
    });
}

// ── Form Kaydet ────────────────────────────────
$('#kanalForm').on('submit', function(ev) {
    ev.preventDefault();
    const $btn = $(this).find('[type=submit]');
    $btn.prop('disabled', true).html('<i class="bi bi-arrow-repeat spin me-1"></i>Kaydediliyor…');

    $.post('', $(this).serialize(), function(r) {
        if (r.success) {
            kanalModal.hide();
            showToast(r.message, 'success');
            entegrasyonlariYukle();
            istatistikYukle();
        } else {
            showToast(r.message, 'danger');
        }
    }).fail(function() {
        showToast('İstek başarısız.', 'danger');
    }).always(function() {
        $btn.prop('disabled', false).html('<i class="bi bi-save me-1"></i>Kaydet');
    });
});

// ── Yardımcılar ────────────────────────────────
function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function toggleSifre() {
    const inp = document.getElementById('sifre');
    const ikon = document.getElementById('sifreIkon');
    if (inp.type === 'password') {
        inp.type = 'text';
        ikon.className = 'bi bi-eye-slash';
    } else {
        inp.type = 'password';
        ikon.className = 'bi bi-eye';
    }
}

// ── Init ───────────────────────────────────────
$(function() {
    $('.select2-init').select2({
        theme: 'bootstrap-5',
        width: '100%'
    });

    $('#filtreDurum').on('change', function() {
        if (table) {
            const val = $(this).val() == '1' ? 'Aktif' : ($(this).val() == '0' ? 'Pasif' : '');
            table.column(7).search(val ? '^' + val + '$' : '', true, false).draw();
        }
    });

    $('#filtreTip').on('change', function() {
        if (table) {
            table.column(1).search($(this).val()).draw();
        }
    });

    $('#btnFiltreTemizle').on('click', function() {
        $('#filtreDurum').val('').trigger('change.select2');
        $('#filtreTip').val('').trigger('change.select2');
        if (table) {
            table.search('').columns().search('').draw();
        }
    });

    istatistikYukle();
    entegrasyonlariYukle();
});
</script>
</body>
</html>
