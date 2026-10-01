<?php
/**
 * Ticket Mesaj Şablonları Yönetimi
 */
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
requireAuth();

$user = Auth::user();
$db   = Database::getInstance();

$currentPageFile = basename($_SERVER['PHP_SELF']);
$pagePermissions = PageAuth::checkPagePermissions($user['kullanici_id'], $user['departman_id'], $currentPageFile);
if (!$pagePermissions['has_access']) {
    PageAuth::accessDenied($pagePermissions['error'] ?? 'Erişim yetkiniz yok.');
}

$siteTitle = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC")['site_ayarlari_site_title'] ?? 'Örnek Yazılım';
$pageTitle = 'Mesaj Şablonları';

// Olay, alıcı tipi ve kanal tanımları DB'den okunur
$olaylar = $db->fetchAll("
    SELECT Ticket_Sablon_Olaylari_kod AS kod, Ticket_Sablon_Olaylari_ad AS ad,
           Ticket_Sablon_Olaylari_aciklama AS aciklama, Ticket_Sablon_Olaylari_renk AS renk
    FROM Ticket_Sablon_Olaylari
    WHERE Durum = 1
    ORDER BY Ticket_Sablon_Olaylari_sira, Ticket_Sablon_Olaylari_ad
");
$aliciTipleri = $db->fetchAll("
    SELECT Ticket_Sablon_AliciTipleri_kod AS kod, Ticket_Sablon_AliciTipleri_ad AS ad,
           Ticket_Sablon_AliciTipleri_aciklama AS aciklama, Ticket_Sablon_AliciTipleri_renk AS renk
    FROM Ticket_Sablon_AliciTipleri
    WHERE Durum = 1
    ORDER BY Ticket_Sablon_AliciTipleri_sira, Ticket_Sablon_AliciTipleri_ad
");
$kanallar = $db->fetchAll("
    SELECT Ticket_Sablon_Kanallari_kod AS kod, Ticket_Sablon_Kanallari_ad AS ad,
           Ticket_Sablon_Kanallari_aciklama AS aciklama, Ticket_Sablon_Kanallari_renk AS renk,
           Ticket_Sablon_Kanallari_konu_kullanir AS konu_kullanir
    FROM Ticket_Sablon_Kanallari
    WHERE Durum = 1
    ORDER BY Ticket_Sablon_Kanallari_sira, Ticket_Sablon_Kanallari_ad
");
$gecerliOlaylar = array_column($olaylar, 'kod');
$gecerliAlicilar = array_column($aliciTipleri, 'kod');
$gecerliKanallar = array_column($kanallar, 'kod');

// AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'] ?? '';
    try {
        switch ($action) {
            case 'stats':
                $tip    = in_array($_POST['tip'] ?? '', ['bildirim','cevap']) ? $_POST['tip'] : 'bildirim';
                $toplam = $db->fetchOne("SELECT COUNT(*) AS s FROM Ticket_Mesaj_Sablonlari WHERE Sablon_Tip=?", [$tip])['s'] ?? 0;
                $aktif  = $db->fetchOne("SELECT COUNT(*) AS s FROM Ticket_Mesaj_Sablonlari WHERE Sablon_Tip=? AND Durum=1", [$tip])['s'] ?? 0;
                echo json_encode(['success' => true, 'data' => compact('toplam','aktif')]);
                break;

            case 'list':
                $tip  = in_array($_POST['tip'] ?? '', ['bildirim','cevap']) ? $_POST['tip'] : 'bildirim';
                $rows = $db->fetchAll("SELECT Sablon_Id,Sablon_Ad,Sablon_Olay,Sablon_Alici_Tipi,Sablon_Kanal,Sablon_Konu,Sablon_Icerik,Sablon_Tip,Durum FROM Ticket_Mesaj_Sablonlari WHERE Sablon_Tip=? ORDER BY Sablon_Id", [$tip]);
                echo json_encode(['success' => true, 'data' => $rows]);
                break;

            case 'kaydet':
                if (!$pagePermissions['can_add'] && !$pagePermissions['can_edit']) throw new Exception('Yetki yok.');
                $id     = (int)($_POST['sablon_id'] ?? 0);
                $tip    = in_array($_POST['sablon_tip'] ?? '', ['bildirim','cevap']) ? $_POST['sablon_tip'] : 'bildirim';
                $ad     = trim($_POST['sablon_ad'] ?? '');
                $icerik = trim($_POST['sablon_icerik'] ?? '');
                $durum  = isset($_POST['durum']) ? 1 : 0;

                if ($tip === 'cevap') {
                    if (!$ad || !$icerik) throw new Exception('Şablon adı ve içerik zorunlu.');
                    $data = [
                        'Sablon_Ad'          => $ad,
                        'Sablon_Tip'         => 'cevap',
                        'Sablon_Olay'        => null,
                        'Sablon_Alici_Tipi'  => null,
                        'Sablon_Kanal'       => null,
                        'Sablon_Konu'        => null,
                        'Sablon_Icerik'      => $icerik,
                        'Durum'              => $durum,
                        'GuncelleyenKullanici' => $user['kullanici_id'],
                        'GuncellemeTarihi'   => app_now(),
                    ];
                } else {
                    $olay   = trim($_POST['sablon_olay'] ?? '');
                    $alici  = trim($_POST['sablon_alici_tipi'] ?? '');
                    $kanal  = trim($_POST['sablon_kanal'] ?? '');
                    $konu   = trim($_POST['sablon_konu'] ?? '');

                    if (!$ad || !$olay || !$alici || !$kanal || !$icerik) throw new Exception('Zorunlu alanlar eksik.');
                    if (!in_array($olay,  $gecerliOlaylar,  true)) throw new Exception('Geçersiz olay.');
                    if (!in_array($alici, $gecerliAlicilar, true)) throw new Exception('Geçersiz alıcı tipi.');
                    if (!in_array($kanal, $gecerliKanallar, true)) throw new Exception('Geçersiz kanal.');

                    $data = [
                        'Sablon_Ad'          => $ad,
                        'Sablon_Tip'         => 'bildirim',
                        'Sablon_Olay'        => $olay,
                        'Sablon_Alici_Tipi'  => $alici,
                        'Sablon_Kanal'       => $kanal,
                        'Sablon_Konu'        => $konu !== '' ? $konu : null,
                        'Sablon_Icerik'      => $icerik,
                        'Durum'              => $durum,
                        'GuncelleyenKullanici' => $user['kullanici_id'],
                        'GuncellemeTarihi'   => app_now(),
                    ];
                }

                if ($id > 0) {
                    $db->update('Ticket_Mesaj_Sablonlari', $data, ['Sablon_Id' => $id]);
                    echo json_encode(['success' => true, 'message' => 'Şablon güncellendi.']);
                } else {
                    if (!$pagePermissions['can_add']) throw new Exception('Ekleme yetkiniz yok.');
                    $data['OlusturanKullanici'] = $user['kullanici_id'];
                    $data['OlusturmaTarihi']    = app_now();
                    $db->insert('Ticket_Mesaj_Sablonlari', $data);
                    echo json_encode(['success' => true, 'message' => 'Şablon eklendi.']);
                }
                break;

            case 'sil':
                if (!$pagePermissions['can_delete']) throw new Exception('Silme yetkiniz yok.');
                $id = (int)($_POST['sablon_id'] ?? 0);
                if (!$id) throw new Exception('ID gerekli.');
                $db->update('Ticket_Mesaj_Sablonlari', [
                    'Durum' => 0,
                    'GuncelleyenKullanici' => $user['kullanici_id'],
                    'GuncellemeTarihi' => app_now(),
                ], ['Sablon_Id' => $id]);
                echo json_encode(['success' => true, 'message' => 'Şablon pasife alındı.']);
                break;

            default:
                throw new Exception('Geçersiz işlem.');
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
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
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/source-sans-3@5.0.12/index.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="/admin/assets/css/adminlte.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/datatables.net-bs5@1.13.8/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/admin/assets/css/custom.css">
    <style>
        .sablon-icerik { font-family: monospace; font-size: .82rem; white-space: pre-wrap; }
        .degisken-badge { cursor:pointer; font-size:.75rem; }
        .select2-container { z-index: 1056; }
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
                    <div class="col-sm-6"><h3 class="mb-0"><i class="bi bi-chat-square-text me-2"></i><?= htmlspecialchars($pageTitle) ?></h3></div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-end">
                            <li class="breadcrumb-item">Sistem</li>
                            <li class="breadcrumb-item active"><?= htmlspecialchars($pageTitle) ?></li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>

        <div class="app-content">
            <div class="container-fluid">

                <!-- Sekmeler -->
                <ul class="nav nav-tabs mb-3" id="sablonTab" role="tablist">
                    <li class="nav-item">
                        <button class="nav-link active" data-tip="bildirim" type="button">
                            <i class="bi bi-bell me-1"></i>Bildirim Şablonları
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" data-tip="cevap" type="button">
                            <i class="bi bi-chat-left-text me-1"></i>Cevap Şablonları
                        </button>
                    </li>
                </ul>

                <!-- Info Boxes -->
                <div class="row mb-3">
                    <div class="col-12 col-sm-6 col-md-3">
                        <div class="info-box">
                            <span class="info-box-icon text-bg-primary shadow-sm"><i class="bi bi-file-text"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Toplam Şablon</span>
                                <span class="info-box-number" id="stat-toplam">–</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-md-3">
                        <div class="info-box">
                            <span class="info-box-icon text-bg-success shadow-sm"><i class="bi bi-check2-circle"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Aktif Şablon</span>
                                <span class="info-box-number" id="stat-aktif">–</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Araçlar -->
                <div class="row mb-2">
                    <div class="col-12 text-end">
                        <?php if ($pagePermissions['can_add']): ?>
                        <button class="btn btn-primary btn-sm" onclick="sablonAc()">
                            <i class="bi bi-plus-lg me-1"></i><span id="btnYeniLabel">Yeni Bildirim Şablonu</span>
                        </button>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Bildirim Tablosu -->
                <div class="card" id="pane-bildirim">
                    <div class="card-body p-0">
                        <table id="tblBildirim" class="table table-sm table-hover table-striped mb-0">
                            <thead class="table-dark">
                                <tr>
                                    <th>#</th>
                                    <th>Şablon Adı</th>
                                    <th>Olay</th>
                                    <th>Alıcı</th>
                                    <th>Kanal</th>
                                    <th>Durum</th>
                                    <th class="text-end">İşlem</th>
                                </tr>
                            </thead>
                            <tbody id="tblBildirimBody">
                                <tr><td colspan="7" class="text-center text-muted py-4">Yükleniyor…</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Cevap Tablosu -->
                <div class="card d-none" id="pane-cevap">
                    <div class="card-body p-0">
                        <table id="tblCevap" class="table table-sm table-hover table-striped mb-0">
                            <thead class="table-dark">
                                <tr>
                                    <th>#</th>
                                    <th>Şablon Adı</th>
                                    <th>İçerik Önizleme</th>
                                    <th>Durum</th>
                                    <th class="text-end">İşlem</th>
                                </tr>
                            </thead>
                            <tbody id="tblCevapBody">
                                <tr><td colspan="5" class="text-center text-muted py-4">Yükleniyor…</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Değişken Rehberi -->
                <div class="card mt-3">
                    <div class="card-header py-2">
                        <strong><i class="bi bi-braces me-1"></i>Kullanılabilir Değişkenler</strong>
                        <small class="text-muted ms-2">Şablon içeriğine tıklayarak ekleyin</small>
                    </div>
                    <div class="card-body py-2">
                        <?php
                        $degiskenler = [
                            '{{ticket_no}}'    => 'Ticket numarası',
                            '{{ticket_id}}'    => 'Ticket ID',
                            '{{konu}}'         => 'Ticket konusu',
                            '{{kategori}}'     => 'Kategori adı',
                            '{{oncelik}}'      => 'Öncelik adı',
                            '{{durum}}'        => 'Ticket durumu',
                            '{{yazan_ad}}'     => 'Yazan adı',
                            '{{yazan_soyad}}'  => 'Yazan soyadı',
                            '{{mesaj}}'        => 'Yanıt / mesaj metni',
                            '{{tarih}}'        => 'Tarih',
                            '{{link}}'         => 'Otomatik link (admin/kullanıcı)',
                        ];
                        foreach ($degiskenler as $kod => $aciklama): ?>
                        <span class="badge bg-secondary degisken-badge me-1 mb-1"
                              onclick="degiskenEkle('<?= $kod ?>')"
                              title="<?= htmlspecialchars($aciklama) ?>"><?= $kod ?></span>
                        <?php endforeach; ?>
                        <div class="mt-2 small text-muted">
                            <i class="bi bi-info-circle me-1"></i>
                            <strong>{{link}}:</strong>
                            Admin şablonlarında → <code>https://destek.ornekproje.com/admin/ticket-detay?id={ticket_id}</code> &nbsp;|&nbsp;
                            Kullanıcı şablonlarında → <code>Ticket_Kaynak_api_url + Ticket_Kaynak_destek_sayfasi</code>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </main>
    <?php include __DIR__ . '/../includes/footer.php'; ?>
</div>

<!-- MODAL -->
<div class="modal fade" id="sablonModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalBaslik">Şablon</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="sablonForm">
                <div class="modal-body">
                    <input type="hidden" name="action" value="kaydet">
                    <input type="hidden" name="sablon_id" id="f_id">
                    <input type="hidden" name="sablon_tip" id="f_tip" value="bildirim">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label fw-semibold">Şablon Adı <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="sablon_ad" id="f_ad" maxlength="200" required>
                        </div>
                        <div class="col-md-4 bildirim-alan">
                            <label class="form-label fw-semibold">Olay <span class="text-danger">*</span></label>
                            <select class="form-select select2-modal" name="sablon_olay" id="f_olay">
                                <option value="">Seçiniz…</option>
                                <?php foreach ($olaylar as $o): ?>
                                <option value="<?= htmlspecialchars($o['kod']) ?>" title="<?= htmlspecialchars($o['aciklama'] ?? '') ?>"><?= htmlspecialchars($o['ad']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 bildirim-alan">
                            <label class="form-label fw-semibold">Alıcı Tipi <span class="text-danger">*</span></label>
                            <select class="form-select select2-modal" name="sablon_alici_tipi" id="f_alici">
                                <option value="">Seçiniz…</option>
                                <?php foreach ($aliciTipleri as $a): ?>
                                <option value="<?= htmlspecialchars($a['kod']) ?>" title="<?= htmlspecialchars($a['aciklama'] ?? '') ?>"><?= htmlspecialchars($a['ad']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 bildirim-alan">
                            <label class="form-label fw-semibold">Kanal <span class="text-danger">*</span></label>
                            <select class="form-select select2-modal" name="sablon_kanal" id="f_kanal">
                                <option value="">Seçiniz…</option>
                                <?php foreach ($kanallar as $k): ?>
                                <option value="<?= htmlspecialchars($k['kod']) ?>" title="<?= htmlspecialchars($k['aciklama'] ?? '') ?>"><?= htmlspecialchars($k['ad']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 bildirim-alan" id="konuRow">
                            <label class="form-label fw-semibold" id="konuLabel">Konu</label>
                            <input type="text" class="form-control" name="sablon_konu" id="f_konu" maxlength="300">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">İçerik <span class="text-danger">*</span></label>
                            <small class="text-muted ms-1">— Değişken eklemek için yukarıdaki badge'lere tıklayın</small>
                            <textarea class="form-control sablon-icerik" name="sablon_icerik" id="f_icerik" rows="10" required></textarea>
                        </div>
                        <div class="col-12">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="durum" id="f_durum" checked>
                                <label class="form-check-label" for="f_durum">Aktif</label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                    <button type="submit" class="btn btn-primary" id="btnKaydet"><i class="bi bi-save me-1"></i>Kaydet</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/datatables.net@1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/datatables.net-bs5@1.13.8/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="/admin/assets/js/adminlte.min.js"></script>
<script src="/admin/assets/js/custom.js"></script>
<script>
const pagePermissions = {
    can_add:    <?= $pagePermissions['can_add']    ? 'true' : 'false' ?>,
    can_edit:   <?= $pagePermissions['can_edit']   ? 'true' : 'false' ?>,
    can_delete: <?= $pagePermissions['can_delete'] ? 'true' : 'false' ?>,
};
const modal = new bootstrap.Modal(document.getElementById('sablonModal'));
let aktifTip = 'bildirim';
let dtBildirim = null, dtCevap = null;

// Olay ve alıcı tipi tanımları DB'den gelir
const OLAYLAR      = <?= json_encode($olaylar, JSON_UNESCAPED_UNICODE) ?>;
const ALICI_TIPLER = <?= json_encode($aliciTipleri, JSON_UNESCAPED_UNICODE) ?>;

const KANALLAR     = <?= json_encode($kanallar, JSON_UNESCAPED_UNICODE) ?>;

const oLayLabel  = {}, oLayColor  = {};
const aliciLabel = {}, aliciColor = {};
const kanalLabel = {}, kanalColor = {}, kanalKonuKullanir = {};
OLAYLAR.forEach(function(o){ oLayLabel[o.kod] = o.ad; oLayColor[o.kod] = o.renk || 'secondary'; });
ALICI_TIPLER.forEach(function(a){ aliciLabel[a.kod] = a.ad; aliciColor[a.kod] = a.renk || 'secondary'; });
KANALLAR.forEach(function(k){
    kanalLabel[k.kod] = k.ad;
    kanalColor[k.kod] = k.renk || 'secondary';
    kanalKonuKullanir[k.kod] = Number(k.konu_kullanir) === 1;
});
const tipLabel   = { bildirim:'Bildirim', cevap:'Cevap' };

function esc(s){ return $('<div>').text(String(s||'')).html(); }
function kisalt(s, n){ s = String(s||''); return s.length > n ? s.slice(0,n)+'…' : s; }

function statsYukle(){
    $.post('', {action:'stats', tip:aktifTip}, function(r){
        if(!r.success) return;
        $('#stat-toplam').text(r.data.toplam);
        $('#stat-aktif').text(r.data.aktif);
    });
}

function listYukle(){
    $.post('', {action:'list', tip:aktifTip}, function(r){
        if(!r.success) return;
        aktifTip === 'cevap' ? cevapListeCiz(r.data) : bildirimListeCiz(r.data);
    });
}

function bildirimListeCiz(data){
    const $b = $('#tblBildirimBody').empty();
    if(!data.length){
        $b.html('<tr><td colspan="7" class="text-center text-muted py-4">Kayıt yok.</td></tr>');
        if(dtBildirim){ dtBildirim.destroy(); dtBildirim = null; }
        return;
    }
    data.forEach(function(s){
        const durumBadge = s.Durum == 1
            ? '<span class="badge text-bg-success">Aktif</span>'
            : '<span class="badge text-bg-secondary">Pasif</span>';
        const editBtn = pagePermissions.can_edit
            ? `<button class="btn btn-xs btn-outline-primary me-1" onclick='sablonDuzenle(${JSON.stringify(s)})'><i class="bi bi-pencil"></i></button>` : '';
        const silBtn = pagePermissions.can_delete && s.Durum == 1
            ? `<button class="btn btn-xs btn-outline-danger" onclick="sablonSil(${s.Sablon_Id},'${esc(s.Sablon_Ad)}')"><i class="bi bi-trash"></i></button>` : '';
        $b.append(`<tr>
            <td>${s.Sablon_Id}</td>
            <td><strong>${esc(s.Sablon_Ad)}</strong>${s.Sablon_Konu ? `<br><small class="text-muted">${esc(s.Sablon_Konu)}</small>` : ''}</td>
            <td><span class="badge bg-${oLayColor[s.Sablon_Olay]||'secondary'}">${oLayLabel[s.Sablon_Olay]||s.Sablon_Olay}</span></td>
            <td><span class="badge bg-${aliciColor[s.Sablon_Alici_Tipi]||'secondary'}">${aliciLabel[s.Sablon_Alici_Tipi]||s.Sablon_Alici_Tipi}</span></td>
            <td><span class="badge bg-${kanalColor[s.Sablon_Kanal]||'secondary'}">${esc(kanalLabel[s.Sablon_Kanal]||s.Sablon_Kanal)}</span></td>
            <td>${durumBadge}</td>
            <td class="text-end">${editBtn}${silBtn}</td>
        </tr>`);
    });
    if(dtBildirim){ dtBildirim.destroy(); }
    dtBildirim = $('#tblBildirim').DataTable({ language:{ url:'//cdn.datatables.net/plug-ins/1.13.8/i18n/tr.json' }, pageLength:25, order:[] });
}

function cevapListeCiz(data){
    const $b = $('#tblCevapBody').empty();
    if(!data.length){
        $b.html('<tr><td colspan="5" class="text-center text-muted py-4">Kayıt yok.</td></tr>');
        if(dtCevap){ dtCevap.destroy(); dtCevap = null; }
        return;
    }
    data.forEach(function(s){
        const durumBadge = s.Durum == 1
            ? '<span class="badge text-bg-success">Aktif</span>'
            : '<span class="badge text-bg-secondary">Pasif</span>';
        const editBtn = pagePermissions.can_edit
            ? `<button class="btn btn-xs btn-outline-primary me-1" onclick='sablonDuzenle(${JSON.stringify(s)})'><i class="bi bi-pencil"></i></button>` : '';
        const silBtn = pagePermissions.can_delete && s.Durum == 1
            ? `<button class="btn btn-xs btn-outline-danger" onclick="sablonSil(${s.Sablon_Id},'${esc(s.Sablon_Ad)}')"><i class="bi bi-trash"></i></button>` : '';
        $b.append(`<tr>
            <td>${s.Sablon_Id}</td>
            <td><strong>${esc(s.Sablon_Ad)}</strong></td>
            <td><small class="text-muted">${esc(kisalt(s.Sablon_Icerik, 90))}</small></td>
            <td>${durumBadge}</td>
            <td class="text-end">${editBtn}${silBtn}</td>
        </tr>`);
    });
    if(dtCevap){ dtCevap.destroy(); }
    dtCevap = $('#tblCevap').DataTable({ language:{ url:'//cdn.datatables.net/plug-ins/1.13.8/i18n/tr.json' }, pageLength:25, order:[] });
}

function tipModuUygula(){
    const cevapMi = aktifTip === 'cevap';
    $('.bildirim-alan').toggle(!cevapMi);
    // gizli alanlar required olmamalı
    $('#f_olay, #f_alici, #f_kanal').prop('required', !cevapMi);
    $('#f_tip').val(aktifTip);
    if(!cevapMi) konuRowGoster();
}

function sablonAc(){
    $('#modalBaslik').text('Yeni ' + (tipLabel[aktifTip]||'') + ' Şablonu');
    $('#sablonForm')[0].reset();
    $('#f_id').val('');
    $('#f_durum').prop('checked', true);
    $('.select2-modal').val('').trigger('change');
    tipModuUygula();
    modal.show();
}

function sablonDuzenle(s){
    aktifTip = s.Sablon_Tip || 'bildirim';
    $('#modalBaslik').text((tipLabel[aktifTip]||'') + ' Şablonu Düzenle');
    $('#f_id').val(s.Sablon_Id);
    $('#f_ad').val(s.Sablon_Ad);
    $('#f_olay').val(s.Sablon_Olay || '').trigger('change');
    $('#f_alici').val(s.Sablon_Alici_Tipi || '').trigger('change');
    $('#f_kanal').val(s.Sablon_Kanal || '').trigger('change');
    $('#f_konu').val(s.Sablon_Konu || '');
    $('#f_icerik').val(s.Sablon_Icerik);
    $('#f_durum').prop('checked', s.Durum == 1);
    tipModuUygula();
    modal.show();
}

function sablonSil(id, ad){
    Swal.fire({
        title:'Pasife Al',
        html:`<strong>${ad}</strong> şablonu pasife alınacak.`,
        icon:'warning', showCancelButton:true,
        confirmButtonColor:'#dc3545', cancelButtonColor:'#6c757d',
        confirmButtonText:'Evet', cancelButtonText:'İptal'
    }).then(function(r){
        if(!r.isConfirmed) return;
        $.post('', {action:'sil', sablon_id:id}, function(r){
            showToast(r.message, r.success?'success':'danger');
            if(r.success){ statsYukle(); listYukle(); }
        });
    });
}

function konuRowGoster(){
    // Konu alanının görünürlüğü ve etiketi kanal tanımından (konu_kullanir) gelir
    const kanal = $('#f_kanal').val();
    $('#konuRow').toggle(!!kanalKonuKullanir[kanal]);
    $('#konuLabel').text(kanal ? (kanalLabel[kanal] || kanal) + ' Başlığı' : 'Başlık');
    $('#f_konu').attr('placeholder', kanal ? (kanalLabel[kanal] || kanal) + ' kanalı için gönderilecek başlık' : '');
}

function degiskenEkle(v){
    const ta = document.getElementById('f_icerik');
    if(!ta) return;
    const s = ta.selectionStart, e = ta.selectionEnd;
    ta.value = ta.value.slice(0,s) + v + ta.value.slice(e);
    ta.selectionStart = ta.selectionEnd = s + v.length;
    ta.focus();
}

$('#f_kanal').on('change', konuRowGoster);

// Sekme değişimi
$('#sablonTab .nav-link').on('click', function(){
    const tip = $(this).data('tip');
    if(tip === aktifTip) return;
    aktifTip = tip;
    $('#sablonTab .nav-link').removeClass('active');
    $(this).addClass('active');
    $('#pane-bildirim').toggleClass('d-none', tip !== 'bildirim');
    $('#pane-cevap').toggleClass('d-none', tip !== 'cevap');
    $('#btnYeniLabel').text('Yeni ' + (tipLabel[tip]||'') + ' Şablonu');
    statsYukle();
    listYukle();
});

$('#sablonForm').on('submit', function(ev){
    ev.preventDefault();
    const $btn = $('#btnKaydet').prop('disabled', true).html('<i class="bi bi-arrow-repeat spin me-1"></i>Kaydediliyor…');
    $.post('', $(this).serialize(), function(r){
        if(r.success){
            modal.hide();
            showToast(r.message, 'success');
            statsYukle(); listYukle();
        } else {
            showToast(r.message, 'danger');
        }
    }).fail(function(){ showToast('Sunucu hatası.', 'danger'); })
      .always(function(){ $btn.prop('disabled',false).html('<i class="bi bi-save me-1"></i>Kaydet'); });
});

$(function(){
    // Aranabilir dropdown'lar (modal içinde olduğu için dropdownParent zorunlu)
    $('.select2-modal').select2({
        theme: 'bootstrap-5',
        width: '100%',
        dropdownParent: $('#sablonModal')
    });
    statsYukle();
    listYukle();
});
</script>
</body>
</html>
