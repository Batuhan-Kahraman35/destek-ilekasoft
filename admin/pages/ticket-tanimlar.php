<?php
/**
 * Admin Panel - Ticket Kategori / Öncelik / Durum Yönetimi
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
requireAuth();

$user = Auth::user();
$db   = Database::getInstance();

// Sadece admin erişebilir
if ($user['departman_id'] != 1) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Bu sayfaya erişim yetkiniz yok.']);
        exit;
    }
    PageAuth::accessDenied('Bu sayfaya erişim yetkiniz bulunmamaktadır.');
}

$currentPageFile = basename($_SERVER['PHP_SELF']);

$pageInfo = $db->fetchOne("
    SELECT s.sayfalar_sayfa_adi, m.menuler_menu_adi AS menu_adi
    FROM Menu_Sayfalar s
    LEFT JOIN Menuler m ON s.sayfalar_menu_id = m.menuler_id
    WHERE s.sayfalar_sayfa_url LIKE ? AND s.sayfalar_durum = 1
", ['%' . $currentPageFile]);

$pageTitle = $pageInfo['sayfalar_sayfa_adi'] ?? 'Ticket Tanımları';
$menuAdi   = $pageInfo['menu_adi']           ?? null;

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle    = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';

// =====================================================================
// AJAX İşlemleri
// =====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');

    try {
        $action = $_POST['action'] ?? '';
        $tip    = $_POST['tip']    ?? '';   // kategori | oncelik | durum

        $tablolar = [
            'kategori'      => ['tablo' => 'Ticket_Kategoriler',     'prefix' => 'Ticket_Kategoriler'],
            'oncelik'       => ['tablo' => 'Ticket_Oncelikler',      'prefix' => 'Ticket_Oncelikler'],
            'durum'         => ['tablo' => 'Ticket_Durumlar',        'prefix' => 'Ticket_Durumlar'],
            'bildirim_yolu' => ['tablo' => 'Ticket_BildirimYollari', 'prefix' => 'Ticket_BildirimYollari'],
        ];

        // Her tanım tipinin Tickets tablosundaki karşılığı (kullanım sayısı için)
        $kullanimKolonlari = [
            'kategori'      => 'Tickets_kategori_id',
            'oncelik'       => 'Tickets_oncelik_id',
            'durum'         => 'Tickets_durum_id',
            'bildirim_yolu' => 'Tickets_bildirim_yolu_id',
        ];

        // -----------------------------------------------------------------
        // istatistik (InfoBox) - tip bağımsız
        // -----------------------------------------------------------------
        if ($action === 'istatistik') {
            $sayilar = [];
            foreach ($tablolar as $anahtar => $bilgi) {
                $sonuc = $db->fetchOne("SELECT COUNT(*) AS sayi FROM {$bilgi['tablo']} WHERE Durum = 1");
                $sayilar[$anahtar] = (int)($sonuc['sayi'] ?? 0);
            }
            echo json_encode(['success' => true, 'data' => $sayilar]);
            exit;
        }

        if (!isset($tablolar[$tip])) throw new Exception('Geçersiz tip.');
        $tbl    = $tablolar[$tip]['tablo'];
        $prefix = $tablolar[$tip]['prefix'];

        // -----------------------------------------------------------------
        // liste
        // -----------------------------------------------------------------
        if ($action === 'liste') {
            $kullanimKolonu = $kullanimKolonlari[$tip];
            $satirlar = $db->fetchAll("
                SELECT t.*,
                       (SELECT COUNT(*) FROM Tickets tk
                         WHERE tk.$kullanimKolonu = t.{$prefix}_id AND tk.Durum = 1) AS kullanim_sayisi
                FROM $tbl t
                WHERE t.Durum = 1
                ORDER BY t.{$prefix}_sira ASC
            ");
            echo json_encode(['success' => true, 'data' => $satirlar]);
            exit;
        }

        // -----------------------------------------------------------------
        // ekle
        // -----------------------------------------------------------------
        if ($action === 'ekle') {
            $ad   = trim($_POST['ad']   ?? '');
            $renk = trim($_POST['renk'] ?? '#6c757d');
            $ikon = trim($_POST['ikon'] ?? '');
            $sira = intval($_POST['sira'] ?? 0);

            if (empty($ad)) throw new Exception('Ad boş olamaz.');
            if (!preg_match('/^#[0-9a-fA-F]{3,8}$/', $renk)) $renk = '#6c757d';

            $data = [
                "{$prefix}_ad"   => $ad,
                "{$prefix}_renk" => $renk,
                "{$prefix}_sira" => $sira,
                'OlusturanKullanici'  => $user['kullanici_id'],
                'OlusturmaTarihi'     => app_now(),
                'GuncelleyenKullanici' => $user['kullanici_id'],
                'GuncellemeTarihi'    => app_now(),
                'Durum'               => 1,
            ];
            if ($tip === 'kategori') $data["{$prefix}_aciklama"] = trim($_POST['aciklama'] ?? '');
            if ($tip === 'oncelik')  $data["{$prefix}_ikon"]     = $ikon ?: 'bi bi-dash-circle';
            if ($tip === 'durum') {
                $data["{$prefix}_ikon"]           = $ikon ?: 'bi bi-circle';
                $data["{$prefix}_kapatma_durumu"] = intval($_POST['kapatma_durumu'] ?? 0);
            }
            if ($tip === 'bildirim_yolu') $data["{$prefix}_ikon"] = $ikon ?: 'bi bi-circle';

            $id = $db->insert($tbl, $data);
            echo json_encode(['success' => true, 'message' => 'Kaydedildi.', 'id' => $id]);
            exit;
        }

        // -----------------------------------------------------------------
        // guncelle
        // -----------------------------------------------------------------
        if ($action === 'guncelle') {
            $id   = intval($_POST['id'] ?? 0);
            $ad   = trim($_POST['ad']   ?? '');
            $renk = trim($_POST['renk'] ?? '#6c757d');
            $ikon = trim($_POST['ikon'] ?? '');
            $sira = intval($_POST['sira'] ?? 0);

            if ($id <= 0)    throw new Exception('Geçersiz kayıt.');
            if (empty($ad))  throw new Exception('Ad boş olamaz.');
            if (!preg_match('/^#[0-9a-fA-F]{3,8}$/', $renk)) $renk = '#6c757d';

            $data = [
                "{$prefix}_ad"   => $ad,
                "{$prefix}_renk" => $renk,
                "{$prefix}_sira" => $sira,
                'GuncelleyenKullanici' => $user['kullanici_id'],
                'GuncellemeTarihi'     => app_now(),
            ];
            if ($tip === 'kategori') $data["{$prefix}_aciklama"] = trim($_POST['aciklama'] ?? '');
            if ($tip === 'oncelik')  $data["{$prefix}_ikon"]     = $ikon ?: 'bi bi-dash-circle';
            if ($tip === 'durum') {
                $data["{$prefix}_ikon"]           = $ikon ?: 'bi bi-circle';
                $data["{$prefix}_kapatma_durumu"] = intval($_POST['kapatma_durumu'] ?? 0);
            }
            if ($tip === 'bildirim_yolu') $data["{$prefix}_ikon"] = $ikon ?: 'bi bi-circle';

            $db->update($tbl, $data, ["{$prefix}_id" => $id]);
            echo json_encode(['success' => true, 'message' => 'Güncellendi.']);
            exit;
        }

        // -----------------------------------------------------------------
        // sil (soft delete)
        // -----------------------------------------------------------------
        if ($action === 'sil') {
            $id = intval($_POST['id'] ?? 0);
            if ($id <= 0) throw new Exception('Geçersiz kayıt.');

            // Kullanımda mı kontrol et
            $kontKolon = $kullanimKolonlari[$tip];
            $kullanim  = $db->fetchOne("SELECT COUNT(*) AS sayi FROM Tickets WHERE $kontKolon = ? AND Durum = 1", [$id]);
            if (($kullanim['sayi'] ?? 0) > 0) throw new Exception('Bu kayıt aktif ticketlarda kullanılıyor. Silinemez.');

            $db->update($tbl, [
                'Durum'               => 0,
                'GuncelleyenKullanici' => $user['kullanici_id'],
                'GuncellemeTarihi'    => app_now(),
            ], ["{$prefix}_id" => $id]);

            echo json_encode(['success' => true, 'message' => 'Silindi.']);
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
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/admin/assets/css/custom.css">

    <style>
        .renk-onizleme { width: 28px; height: 28px; border-radius: 4px; display: inline-block; border: 1px solid #dee2e6; vertical-align: middle; }
        .nav-tabs .nav-link { cursor: pointer; }
        .info-box { cursor: pointer; }
        .info-box.aktif { outline: 2px solid var(--bs-primary); outline-offset: -2px; }
        .onizleme-badge { font-size: .8rem; }
        .renk-kod { font-family: var(--bs-font-monospace); font-size: .75rem; }
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

                <!-- InfoBox: Tanım Sayıları -->
                <div class="row mb-3">
                    <div class="col-12 col-sm-6 col-md-3">
                        <div class="info-box" data-tip="kategori">
                            <span class="info-box-icon text-bg-primary shadow-sm"><i class="bi bi-tags"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Kategoriler</span>
                                <span class="info-box-number" id="stat-kategori">-</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-md-3">
                        <div class="info-box" data-tip="oncelik">
                            <span class="info-box-icon text-bg-warning shadow-sm"><i class="bi bi-exclamation-triangle"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Öncelikler</span>
                                <span class="info-box-number" id="stat-oncelik">-</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-md-3">
                        <div class="info-box" data-tip="durum">
                            <span class="info-box-icon text-bg-success shadow-sm"><i class="bi bi-flag"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Durumlar</span>
                                <span class="info-box-number" id="stat-durum">-</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-md-3">
                        <div class="info-box" data-tip="bildirim_yolu">
                            <span class="info-box-icon text-bg-info shadow-sm"><i class="bi bi-megaphone"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Bildirim Yolları</span>
                                <span class="info-box-number" id="stat-bildirim_yolu">-</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filtre Paneli -->
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
                                <div class="col-12 col-md-4">
                                    <label class="form-label">Arama (Ad / Açıklama)</label>
                                    <input type="text" id="filtre-ad" class="form-control" placeholder="Tanım adı ara">
                                </div>
                                <div class="col-12 col-md-4">
                                    <label class="form-label">Kullanım</label>
                                    <select id="filtre-kullanim" class="form-select select2-basic">
                                        <option value="">Tümü</option>
                                        <option value="var">Ticketlarda kullanılıyor</option>
                                        <option value="yok">Hiç kullanılmamış</option>
                                    </select>
                                </div>
                                <div class="col-12 col-md-4 filtre-kapatma" style="display:none">
                                    <label class="form-label">Kapatma Durumu</label>
                                    <select id="filtre-kapatma" class="form-select select2-basic">
                                        <option value="">Tümü</option>
                                        <option value="1">Ticket'ı kapatır</option>
                                        <option value="0">Kapatmaz</option>
                                    </select>
                                </div>
                            </div>
                            <div class="mt-3 d-flex gap-2">
                                <button class="btn btn-primary btn-sm" id="btnFiltrele">
                                    <i class="bi bi-search me-1"></i> Filtrele
                                </button>
                                <button class="btn btn-secondary btn-sm" id="btnFiltreTemizle">
                                    <i class="bi bi-x-circle me-1"></i> Temizle
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tablo Kartı -->
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <ul class="nav nav-tabs card-header-tabs mb-0" id="tanim-tabs">
                            <li class="nav-item">
                                <a class="nav-link active" data-tip="kategori" href="#">
                                    <i class="bi bi-tags me-1"></i> Kategoriler
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" data-tip="oncelik" href="#">
                                    <i class="bi bi-exclamation-triangle me-1"></i> Öncelikler
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" data-tip="durum" href="#">
                                    <i class="bi bi-flag me-1"></i> Durumlar
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" data-tip="bildirim_yolu" href="#">
                                    <i class="bi bi-megaphone me-1"></i> Bildirim Yolları
                                </a>
                            </li>
                        </ul>
                        <button class="btn btn-success btn-sm" id="btnYeniEkle">
                            <i class="bi bi-plus-circle me-1"></i> Yeni Ekle
                        </button>
                    </div>
                    <div class="card-body">
                        <table id="tanimTable" class="table table-hover table-bordered table-sm align-middle" style="width:100%">
                            <thead class="table-light">
                                <tr>
                                    <th>Önizleme</th>
                                    <th>Ad</th>
                                    <th>Açıklama</th>
                                    <th>İkon</th>
                                    <th>Renk</th>
                                    <th>Sıra</th>
                                    <th>Kapatma</th>
                                    <th>Kullanım</th>
                                    <th>İşlemler</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>

            </div>
        </div>
    </main>

    <?php include __DIR__ . '/../includes/footer.php'; ?>
</div>

<!-- Modal: Ekle / Düzenle -->
<div class="modal fade" id="modalForm" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modal-baslik">Yeni Ekle</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="form-id">
                <input type="hidden" id="form-tip">

                <!-- Canlı önizleme: kaydın ticket sayfalarındaki görünümü -->
                <div class="mb-3 p-3 bg-body-tertiary rounded text-center">
                    <div class="form-text mb-1">Önizleme</div>
                    <div id="form-onizleme"></div>
                </div>

                <div class="row g-2 mb-3">
                    <div class="col-8">
                        <label class="form-label">Ad <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="form-ad" maxlength="100">
                    </div>
                    <div class="col-4">
                        <label class="form-label">Sıra No</label>
                        <input type="number" class="form-control" id="form-sira" value="0" min="0">
                    </div>
                </div>

                <!-- Kategori'ye özel -->
                <div class="mb-3 alan-aciklama" style="display:none">
                    <label class="form-label">Açıklama</label>
                    <input type="text" class="form-control" id="form-aciklama" maxlength="500">
                </div>

                <!-- Öncelik / Durum / Bildirim Yolu'na özel: İkon -->
                <div class="mb-3 alan-ikon" style="display:none">
                    <label class="form-label">Bootstrap Icon Sınıfı</label>
                    <input type="text" class="form-control" id="form-ikon" placeholder="Örn: bi bi-circle">
                    <div class="form-text">
                        <a href="https://icons.getbootstrap.com/" target="_blank">Bootstrap Icons listesi</a>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Renk</label>
                    <div class="d-flex align-items-center gap-2">
                        <input type="color" class="form-control form-control-color" id="form-renk" value="#6c757d" style="width:60px">
                        <input type="text" class="form-control" id="form-renk-hex" value="#6c757d" maxlength="9" placeholder="#6c757d">
                        <span class="renk-onizleme" id="renk-onizleme" style="background:#6c757d"></span>
                    </div>
                </div>

                <!-- Durum'a özel: Kapatma -->
                <div class="mb-3 alan-kapatma" style="display:none">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="form-kapatma" value="1">
                        <label class="form-check-label" for="form-kapatma">
                            Bu durum kapalı sayılır (ticket'ı sonlandırır)
                        </label>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">İptal</button>
                <button type="button" class="btn btn-primary btn-sm" id="btnModalKaydet">Kaydet</button>
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
let aktifTip = 'kategori';
let tanimDT  = null;
const PAGE_PATH = window.location.pathname;

// Tanım tiplerinin arayüz ve veri karşılıkları — tek noktadan yönetilir
const TIPLER = {
    kategori:      { baslik: 'Kategoriler',      tekil: 'Kategori',      prefix: 'Ticket_Kategoriler',     ikonVar: false, aciklamaVar: true,  kapatmaVar: false },
    oncelik:       { baslik: 'Öncelikler',       tekil: 'Öncelik',       prefix: 'Ticket_Oncelikler',      ikonVar: true,  aciklamaVar: false, kapatmaVar: false },
    durum:         { baslik: 'Durumlar',         tekil: 'Durum',         prefix: 'Ticket_Durumlar',        ikonVar: true,  aciklamaVar: false, kapatmaVar: true  },
    bildirim_yolu: { baslik: 'Bildirim Yolları', tekil: 'Bildirim Yolu', prefix: 'Ticket_BildirimYollari', ikonVar: true,  aciklamaVar: false, kapatmaVar: false }
};

// Kolon sırası (thead ile birebir aynı olmalı)
const KOLON = { onizleme: 0, ad: 1, aciklama: 2, ikon: 3, renk: 4, sira: 5, kapatma: 6, kullanim: 7, islem: 8 };

let satirCache = {};   // id -> satır verisi (inline onclick yerine)

function escHtml(str) {
    if (!str) return '';
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function postAjax(data, callback) {
    $.post(PAGE_PATH, data, function (res) {
        if (typeof res === 'string') { try { res = JSON.parse(res); } catch(e) {} }
        callback(res);
    }).fail(function () { showToast('Sunucu bağlantı hatası.', 'error'); });
}

$(document).ready(function () {
    tanimDT = $('#tanimTable').DataTable({
        data: [],
        language: { url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json' },
        order: [[KOLON.sira, 'asc']],
        pageLength: 25,
        scrollX: true,
        columnDefs: [
            { targets: [KOLON.onizleme, KOLON.renk, KOLON.islem], orderable: false },
            { targets: KOLON.islem, className: 'text-center' },
            { targets: [KOLON.kapatma, KOLON.kullanim], className: 'text-center' }
        ]
    });

    $('.select2-basic').select2({ theme: 'bootstrap-5', width: '100%' });

    tipDegistir('kategori');
    loadIstatistik();

    // Tab tıklamaları
    $('#tanim-tabs .nav-link').on('click', function (e) {
        e.preventDefault();
        tipDegistir($(this).data('tip'));
    });

    // InfoBox tıklaması da ilgili sekmeyi açar
    $('.info-box').on('click', function () {
        tipDegistir($(this).data('tip'));
    });

    // Filtre
    $('#btnFiltrele').on('click', function () { tanimDT.draw(); });
    $('#filtre-ad').on('keyup', function (e) { if (e.key === 'Enter') tanimDT.draw(); });
    $('#btnFiltreTemizle').on('click', function () {
        $('#filtre-ad').val('');
        $('#filtre-kullanim, #filtre-kapatma').val('').trigger('change');
        tanimDT.draw();
    });

    // Yeni Ekle
    $('#btnYeniEkle').on('click', function () {
        formTemizle();
        $('#modal-baslik').text('Yeni ' + TIPLER[aktifTip].tekil + ' Ekle');
        modalAlanlarGoster(aktifTip);
        new bootstrap.Modal(document.getElementById('modalForm')).show();
    });

    // Satır işlemleri (inline onclick yerine event delegation)
    $('#tanimTable tbody').on('click', '.btn-duzenle', function () {
        duzenle($(this).data('id'));
    });
    $('#tanimTable tbody').on('click', '.btn-sil', function () {
        sil($(this).data('id'), $(this).data('ad'));
    });

    // Renk senkronizasyonu
    $('#form-renk').on('input', function () {
        const v = $(this).val();
        $('#form-renk-hex').val(v);
        onizlemeTazele();
    });
    $('#form-renk-hex').on('input', function () {
        const v = $(this).val();
        if (/^#[0-9a-fA-F]{3,8}$/.test(v)) { $('#form-renk').val(v); onizlemeTazele(); }
    });
    $('#form-ad, #form-ikon').on('input', onizlemeTazele);

    // Modal kaydet
    $('#btnModalKaydet').on('click', kaydet);
});

// -------------------------------------------------------
// Tip değişimi
// -------------------------------------------------------
function tipDegistir(tip) {
    aktifTip = tip;
    const bilgi = TIPLER[tip];

    $('#tanim-tabs .nav-link').removeClass('active').filter('[data-tip="' + tip + '"]').addClass('active');
    $('.info-box').removeClass('aktif').filter('[data-tip="' + tip + '"]').addClass('aktif');

    // Tipe uymayan kolonları gizle
    tanimDT.column(KOLON.aciklama).visible(bilgi.aciklamaVar, false);
    tanimDT.column(KOLON.ikon).visible(bilgi.ikonVar, false);
    tanimDT.column(KOLON.kapatma).visible(bilgi.kapatmaVar, false);

    // Kapatma filtresi yalnızca durum sekmesinde anlamlı
    $('.filtre-kapatma').toggle(bilgi.kapatmaVar);
    if (!bilgi.kapatmaVar) $('#filtre-kapatma').val('').trigger('change');

    loadListe();
}

// -------------------------------------------------------
// InfoBox sayıları
// -------------------------------------------------------
function loadIstatistik() {
    postAjax({ action: 'istatistik', tip: aktifTip }, function (res) {
        if (!res.success) return;
        Object.keys(res.data).forEach(function (k) {
            $('#stat-' + k).text(res.data[k]);
        });
    });
}

// -------------------------------------------------------
// Liste
// -------------------------------------------------------
function loadListe() {
    postAjax({ action: 'liste', tip: aktifTip }, function (res) {
        if (!res.success) { showToast(res.message, 'error'); return; }

        const bilgi  = TIPLER[aktifTip];
        const prefix = bilgi.prefix;
        satirCache = {};
        tanimDT.clear();

        res.data.forEach(function (row) {
            const id      = row[prefix + '_id'];
            const ad      = row[prefix + '_ad'] || '';
            const renk    = row[prefix + '_renk'] || '#6c757d';
            const ikon    = row[prefix + '_ikon'] || '';
            const sira    = parseInt(row[prefix + '_sira'] || 0);
            const acikl   = row[prefix + '_aciklama'] || '';
            const kapatir = row[prefix + '_kapatma_durumu'] == 1;
            const kullanim = parseInt(row.kullanim_sayisi || 0);

            satirCache[id] = row;

            const onizleme = `<span class="badge onizleme-badge" style="background-color:${escHtml(renk)}">`
                + (bilgi.ikonVar && ikon ? `<i class="${escHtml(ikon)} me-1"></i>` : '')
                + escHtml(ad) + '</span>';

            const silButonu = kullanim > 0
                ? `<button class="btn btn-danger btn-sm" disabled title="Aktif ticketlarda kullanılıyor, silinemez"><i class="bi bi-trash"></i></button>`
                : `<button class="btn btn-danger btn-sm btn-sil" data-id="${id}" data-ad="${escHtml(ad)}" title="Sil"><i class="bi bi-trash"></i></button>`;

            tanimDT.row.add([
                onizleme,
                escHtml(ad),
                acikl ? escHtml(acikl) : '<span class="text-muted">-</span>',
                ikon ? `<i class="${escHtml(ikon)} me-1"></i><span class="text-muted small">${escHtml(ikon)}</span>` : '<span class="text-muted">-</span>',
                `<span class="renk-onizleme" style="background:${escHtml(renk)}"></span> <span class="renk-kod text-muted">${escHtml(renk)}</span>`,
                sira,
                kapatir ? '<span class="badge text-bg-warning">Kapatır</span>' : '<span class="text-muted">-</span>',
                kullanim > 0
                    ? `<span class="badge text-bg-secondary">${kullanim}</span>`
                    : '<span class="badge text-bg-light text-muted">0</span>',
                `<div class="d-flex gap-1 justify-content-center">
                    <button class="btn btn-warning btn-sm btn-duzenle" data-id="${id}" title="Düzenle"><i class="bi bi-pencil"></i></button>
                    ${silButonu}
                </div>`
            ]);
        });

        tanimDT.draw();
    });
}

// -------------------------------------------------------
// Filtre (DataTables istemci tarafı)
// -------------------------------------------------------
$.fn.dataTable.ext.search.push(function (settings, veri, satirIndex, satirVerisi) {
    if (settings.nTable.id !== 'tanimTable') return true;

    const arama = ($('#filtre-ad').val() || '').trim().toLocaleLowerCase('tr');
    if (arama) {
        const metin = (veri[KOLON.ad] + ' ' + veri[KOLON.aciklama]).toLocaleLowerCase('tr');
        if (metin.indexOf(arama) === -1) return false;
    }

    const kullanimFiltre = $('#filtre-kullanim').val();
    if (kullanimFiltre) {
        const sayi = parseInt($('<div>').html(veri[KOLON.kullanim]).text()) || 0;
        if (kullanimFiltre === 'var' && sayi === 0) return false;
        if (kullanimFiltre === 'yok' && sayi > 0)  return false;
    }

    const kapatmaFiltre = $('#filtre-kapatma').val();
    if (kapatmaFiltre !== '' && kapatmaFiltre !== undefined && TIPLER[aktifTip].kapatmaVar) {
        const kapatir = veri[KOLON.kapatma].indexOf('Kapatır') !== -1 ? '1' : '0';
        if (kapatir !== kapatmaFiltre) return false;
    }

    return true;
});

// -------------------------------------------------------
// Modal
// -------------------------------------------------------
function modalAlanlarGoster(tip) {
    const bilgi = TIPLER[tip];
    $('.alan-aciklama').toggle(bilgi.aciklamaVar);
    $('.alan-ikon').toggle(bilgi.ikonVar);
    $('.alan-kapatma').toggle(bilgi.kapatmaVar);
    onizlemeTazele();
}

function onizlemeTazele() {
    const renk = $('#form-renk').val() || '#6c757d';
    const ad   = $('#form-ad').val().trim() || 'Örnek';
    const ikon = $('#form-ikon').val().trim();
    $('#renk-onizleme').css('background', renk);
    $('#form-onizleme').html(
        `<span class="badge onizleme-badge" style="background-color:${escHtml(renk)}">`
        + (TIPLER[aktifTip].ikonVar && ikon ? `<i class="${escHtml(ikon)} me-1"></i>` : '')
        + escHtml(ad) + '</span>'
    );
}

function formTemizle() {
    $('#form-id').val('');
    $('#form-tip').val(aktifTip);
    $('#form-ad').val('');
    $('#form-renk').val('#6c757d');
    $('#form-renk-hex').val('#6c757d');
    $('#renk-onizleme').css('background', '#6c757d');
    $('#form-sira').val(0);
    $('#form-aciklama').val('');
    $('#form-ikon').val('');
    $('#form-kapatma').prop('checked', false);
}

function duzenle(id) {
    const row = satirCache[id];
    if (!row) return;

    formTemizle();
    const prefix = TIPLER[aktifTip].prefix;

    $('#form-id').val(row[prefix + '_id']);
    $('#form-tip').val(aktifTip);
    $('#form-ad').val(row[prefix + '_ad'] || '');
    const renk = row[prefix + '_renk'] || '#6c757d';
    $('#form-renk').val(renk);
    $('#form-renk-hex').val(renk);
    $('#form-sira').val(row[prefix + '_sira'] || 0);
    $('#form-aciklama').val(row[prefix + '_aciklama'] || '');
    $('#form-ikon').val(row[prefix + '_ikon'] || '');
    $('#form-kapatma').prop('checked', row[prefix + '_kapatma_durumu'] == 1);

    $('#modal-baslik').text(TIPLER[aktifTip].tekil + ' Düzenle');
    modalAlanlarGoster(aktifTip);
    new bootstrap.Modal(document.getElementById('modalForm')).show();
}

function kaydet() {
    const id   = $('#form-id').val();
    const ad   = $('#form-ad').val().trim();
    const renk = $('#form-renk-hex').val().trim() || $('#form-renk').val();
    const sira = $('#form-sira').val();

    if (!ad) { showToast('Ad boş olamaz.', 'warning'); return; }

    postAjax({
        action: id ? 'guncelle' : 'ekle',
        tip:    aktifTip,
        id:     id,
        ad:     ad,
        renk:   renk,
        sira:   sira,
        aciklama:       $('#form-aciklama').val(),
        ikon:           $('#form-ikon').val().trim(),
        kapatma_durumu: $('#form-kapatma').is(':checked') ? 1 : 0
    }, function (res) {
        if (res.success) {
            bootstrap.Modal.getInstance(document.getElementById('modalForm')).hide();
            showToast(res.message, 'success');
            loadListe();
            loadIstatistik();
        } else {
            showToast(res.message, 'error');
        }
    });
}

function sil(id, ad) {
    Swal.fire({
        title: 'Silinsin mi?',
        text: (ad ? '"' + ad + '" ' : '') + 'kaydı listeden kaldırılacak.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Evet, Sil',
        cancelButtonText: 'İptal',
        confirmButtonColor: '#dc3545'
    }).then(function (r) {
        if (!r.isConfirmed) return;
        postAjax({ action: 'sil', tip: aktifTip, id: id }, function (res) {
            if (res.success) {
                showToast('Kayıt silindi.', 'success');
                loadListe();
                loadIstatistik();
            } else {
                showToast(res.message, 'error');
            }
        });
    });
}
</script>
</body>
</html>
