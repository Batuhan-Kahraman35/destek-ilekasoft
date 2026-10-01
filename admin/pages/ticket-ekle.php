<?php
/**
 * Admin Panel - Yeni Ticket Ekle
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
require_once __DIR__ . '/../includes/TicketService.php';
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
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => $pagePermissions['error'] ?? 'Erişim yetkiniz yok.']);
        exit;
    }
    PageAuth::accessDenied($pagePermissions['error'] ?? 'Bu sayfaya erişim yetkiniz bulunmamaktadır.');
}

$isAdmin = ($user['departman_id'] == 1);

// Sayfa bilgileri
$pageInfo = $db->fetchOne("
    SELECT s.sayfalar_sayfa_adi, s.sayfalar_aciklama, m.menuler_menu_adi AS menu_adi
    FROM Menu_Sayfalar s
    LEFT JOIN Menuler m ON s.sayfalar_menu_id = m.menuler_id
    WHERE s.sayfalar_sayfa_url LIKE ? AND s.sayfalar_durum = 1
", ['%' . $currentPageFile]);

$pageTitle = $pageInfo['sayfalar_sayfa_adi'] ?? 'Yeni Ticket Oluştur';
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

        // -----------------------------------------------------------------
        // form_options
        // -----------------------------------------------------------------
        if ($action === 'form_options') {
            $kategoriler     = $db->fetchAll("SELECT Ticket_Kategoriler_id AS id, Ticket_Kategoriler_ad AS ad, Ticket_Kategoriler_renk AS renk FROM Ticket_Kategoriler WHERE Durum = 1 ORDER BY Ticket_Kategoriler_sira");
            $oncelikler      = $db->fetchAll("SELECT Ticket_Oncelikler_id AS id, Ticket_Oncelikler_ad AS ad, Ticket_Oncelikler_renk AS renk FROM Ticket_Oncelikler WHERE Durum = 1 ORDER BY Ticket_Oncelikler_sira");
            $bildirimYollari = $db->fetchAll("SELECT Ticket_BildirimYollari_id AS id, Ticket_BildirimYollari_ad AS ad, Ticket_BildirimYollari_ikon AS ikon FROM Ticket_BildirimYollari WHERE Durum = 1 ORDER BY Ticket_BildirimYollari_sira");
            $personeller     = $isAdmin ? $db->fetchAll("SELECT kullanici_id AS id, kullanici_ad + ' ' + kullanici_soyad AS ad FROM kullanicilar WHERE kullanici_durum = 1 ORDER BY kullanici_ad") : [];
            $kaynaklar       = $isAdmin ? $db->fetchAll("SELECT Ticket_Kaynak_id AS id, Ticket_Kaynak_ad AS ad, Ticket_Kaynak_kod AS kod FROM Ticket_Kaynak WHERE Durum = 1 ORDER BY Ticket_Kaynak_ad") : [];

            echo json_encode(['success' => true, 'data' => compact('kategoriler', 'oncelikler', 'bildirimYollari', 'personeller', 'kaynaklar')]);
            exit;
        }

        // -----------------------------------------------------------------
        // ticket_ekle
        // -----------------------------------------------------------------
        if ($action === 'ticket_ekle') {
            if (!$pagePermissions['can_add']) throw new Exception('Ekleme yetkiniz bulunmamaktadır.');

            // Mükerrer gönderim koruması: form anahtarı yalnız bir kez kullanılabilir.
            // PHP session kilidi aynı oturumdaki eşzamanlı istekleri sıraya sokar;
            // ikinci istek, ilki bittikten sonra anahtarı dolu bulur.
            $formAnahtar = (string)($_POST['form_anahtar'] ?? '');
            if (!preg_match('/^[a-f0-9]{32}$/', $formAnahtar)) {
                throw new Exception('Geçersiz form anahtarı. Sayfayı yenileyip tekrar deneyin.');
            }
            $oncekiKayit = $_SESSION['ticket_ekle_anahtarlar'][$formAnahtar] ?? null;
            if ($oncekiKayit) {
                echo json_encode([
                    'success'   => true,
                    'mukerrer'  => true,
                    'message'   => 'Bu ticket zaten oluşturuldu.',
                    'ticket_id' => $oncekiKayit['ticket_id'],
                    'ticket_no' => $oncekiKayit['ticket_no'],
                ]);
                exit;
            }

            $konu            = trim($_POST['konu']       ?? '');
            $aciklama        = trim($_POST['aciklama']   ?? '');
            $kategoriId      = intval($_POST['kategori_id'] ?? 0);
            $oncelikId       = intval($_POST['oncelik_id']  ?? 0);
            $bildirimYoluId  = !empty($_POST['bildirim_yolu_id']) ? intval($_POST['bildirim_yolu_id']) : null;
            $atananId        = $isAdmin && !empty($_POST['atanan_id']) ? intval($_POST['atanan_id']) : null;
            $kaynakId        = $isAdmin && !empty($_POST['kaynak_id']) ? intval($_POST['kaynak_id']) : null;
            $olusturanId = $isAdmin && !empty($_POST['olusturan_id']) ? intval($_POST['olusturan_id']) : (int)$user['kullanici_id'];
            $olusturmaTarihi = !empty($_POST['olusturma_tarihi']) ? str_replace('T', ' ', trim($_POST['olusturma_tarihi'])) . ':00' : null;

            if (empty($konu))     throw new Exception('Konu boş olamaz.');
            if (empty($aciklama)) throw new Exception('Açıklama boş olamaz.');
            if ($kategoriId <= 0) throw new Exception('Kategori seçiniz.');
            if ($oncelikId  <= 0) throw new Exception('Öncelik seçiniz.');

            $dosyalar = [];
            if (!empty($_FILES['dosyalar']) && is_array($_FILES['dosyalar']['name'] ?? null)) {
                $count = count($_FILES['dosyalar']['name']);
                for ($i = 0; $i < $count; $i++) {
                    $dosyalar[] = [
                        'name' => $_FILES['dosyalar']['name'][$i] ?? '',
                        'type' => $_FILES['dosyalar']['type'][$i] ?? '',
                        'tmp_name' => $_FILES['dosyalar']['tmp_name'][$i] ?? '',
                        'error' => $_FILES['dosyalar']['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                        'size' => $_FILES['dosyalar']['size'][$i] ?? 0,
                    ];
                }
            }

            $service = new TicketService($db);
            $sonuc = $service->ticketOlustur([
                'konu' => $konu,
                'aciklama' => $aciklama,
                'mesaj' => $aciklama,
                'kategori_id' => $kategoriId,
                'oncelik_id' => $oncelikId,
                'bildirim_yolu_id' => $bildirimYoluId,
                'atanan_id' => $atananId,
                'kaynak_id' => $kaynakId,
                'dosyalar' => $dosyalar,
                'olusturan_kullanici_id' => $olusturanId,
                'islem_kullanici_id' => (int)$user['kullanici_id'],
                'mesaj_kaynak' => 'panel',
                'olusturma_tarihi' => $olusturmaTarihi,
            ]);

            $anahtarlar = $_SESSION['ticket_ekle_anahtarlar'] ?? [];
            $anahtarlar[$formAnahtar] = [
                'ticket_id' => (int)($sonuc['ticket_id'] ?? 0),
                'ticket_no' => (string)($sonuc['ticket_no'] ?? ''),
            ];
            $_SESSION['ticket_ekle_anahtarlar'] = array_slice($anahtarlar, -20, null, true);

            echo json_encode([
                'success' => true,
                'message' => 'Ticket oluşturuldu.',
                'ticket_id' => $sonuc['ticket_id'] ?? 0,
                'ticket_no' => $sonuc['ticket_no'] ?? '',
            ]);
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
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/admin/assets/css/custom.css">
</head>
<body class="layout-fixed sidebar-expand-lg sidebar-open bg-body-tertiary">
<div class="app-wrapper">

    <?php include __DIR__ . '/../includes/header.php'; ?>
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>

    <main class="app-main">
        <!-- Başlık -->
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
                            <li class="breadcrumb-item"><a href="/admin/ticket-liste">Ticket Listesi</a></li>
                            <li class="breadcrumb-item active"><?= htmlspecialchars($pageTitle) ?></li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>

        <!-- İçerik -->
        <div class="app-content">
            <div class="container-fluid">
                <div class="row justify-content-center">
                    <div class="col-12 col-lg-9">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="bi bi-plus-circle me-1"></i> Yeni Ticket
                                </h5>
                            </div>
                            <div class="card-body">
                                <form id="formTicket" enctype="multipart/form-data">
                                    <input type="hidden" name="form_anahtar" id="form_anahtar" value="<?= bin2hex(random_bytes(16)) ?>">

                                    <!-- Konu -->
                                    <div class="mb-3">
                                        <label class="form-label fw-semibold">Konu <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" id="konu" name="konu" placeholder="Ticket konusunu kısaca yazın" maxlength="300" required>
                                    </div>

                                    <div class="row g-3 mb-3">
                                        <!-- Oluşturma Tarihi -->
                                        <div class="col-12 col-md-6 col-lg-3">
                                            <label class="form-label fw-semibold">Tarih <span class="text-danger">*</span></label>
                                            <input type="datetime-local" id="olusturma_tarihi" name="olusturma_tarihi" class="form-control" value="<?= date('Y-m-d\TH:i') ?>" required>
                                        </div>
                                        <!-- Kategori -->
                                        <div class="col-12 col-md-6 col-lg-3">
                                            <label class="form-label fw-semibold">Kategori <span class="text-danger">*</span></label>
                                            <select id="kategori_id" name="kategori_id" class="form-select select2-basic" required>
                                                <option value="">Seçiniz...</option>
                                            </select>
                                        </div>
                                        <!-- Öncelik -->
                                        <div class="col-12 col-md-6 col-lg-3">
                                            <label class="form-label fw-semibold">Öncelik <span class="text-danger">*</span></label>
                                            <select id="oncelik_id" name="oncelik_id" class="form-select select2-basic" required>
                                                <option value="">Seçiniz...</option>
                                            </select>
                                        </div>
                                        <!-- Bildirim Yolu -->
                                        <div class="col-12 col-md-6 col-lg-3">
                                            <label class="form-label fw-semibold">Bildirim Yolu</label>
                                            <select id="bildirim_yolu_id" name="bildirim_yolu_id" class="form-select select2-basic">
                                                <option value="">Seçiniz...</option>
                                            </select>
                                        </div>
                                        <!-- Talebi Açan (sadece admin) -->
                                        <?php if ($isAdmin): ?>
                                        <div class="col-12 col-md-6 col-lg-3">
                                            <label class="form-label fw-semibold">Talebi Açan Personel</label>
                                            <select id="olusturan_id" name="olusturan_id" class="form-select select2-basic">
                                                <option value="">Kendim</option>
                                            </select>
                                        </div>
                                        <?php endif; ?>
                                        <!-- Atanan (sadece admin) -->
                                        <?php if ($isAdmin): ?>
                                        <div class="col-12 col-md-6 col-lg-3">
                                            <label class="form-label fw-semibold">Atanan Personel</label>
                                            <select id="atanan_id" name="atanan_id" class="form-select select2-basic">
                                                <option value="">Atama Yok</option>
                                            </select>
                                        </div>
                                        <?php endif; ?>
                                        <!-- Kaynak (sadece admin) -->
                                        <?php if ($isAdmin): ?>
                                        <div class="col-12 col-md-6 col-lg-3">
                                            <label class="form-label fw-semibold">Kaynak</label>
                                            <select id="kaynak_id" name="kaynak_id" class="form-select select2-basic">
                                                <option value="">Panel (Varsayılan)</option>
                                            </select>
                                        </div>
                                        <?php endif; ?>
                                    </div>



                                    <!-- Açıklama -->
                                    <div class="mb-3">
                                        <label class="form-label fw-semibold">Açıklama <span class="text-danger">*</span></label>
                                        <textarea class="form-control" id="aciklama" name="aciklama" rows="6"
                                                  placeholder="Sorunu veya talebi ayrıntılı açıklayın..." required></textarea>
                                    </div>

                                    <!-- Dosya Ekleri -->
                                    <div class="mb-4">
                                        <label class="form-label fw-semibold">Dosya Ekle</label>
                                        <input type="file" class="form-control" id="dosyalar" name="dosyalar[]" multiple
                                               accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx,.xls,.xlsx,.txt">
                                        <div class="form-text">Maks. 10MB per dosya. İzin verilen: jpg, png, gif, pdf, doc, docx, xls, xlsx, txt</div>
                                    </div>

                                    <div class="d-flex gap-2">
                                        <button type="submit" class="btn btn-success" id="btnKaydet">
                                            <i class="bi bi-send me-1"></i> Ticket Oluştur
                                        </button>
                                        <a href="/admin/ticket-liste" class="btn btn-secondary">
                                            <i class="bi bi-x me-1"></i> Vazgeç
                                        </a>
                                    </div>

                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <?php include __DIR__ . '/../includes/footer.php'; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11" crossorigin="anonymous"></script>
<script src="/admin/assets/js/adminlte.min.js"></script>
<script src="/admin/assets/js/custom.js"></script>

<script>
const IS_ADMIN  = <?= $isAdmin ? 'true' : 'false' ?>;
const PAGE_PATH = window.location.pathname;

function postAjax(data, callback, formData) {
    const fd = formData || new FormData();
    if (!formData) {
        Object.entries(data).forEach(([k, v]) => fd.append(k, v));
    }
    $.ajax({
        url: PAGE_PATH,
        type: 'POST',
        data: fd,
        processData: false,
        contentType: false,
        success: function (res) {
            if (typeof res === 'string') { try { res = JSON.parse(res); } catch(e) {} }
            callback(res);
        },
        error: function () { Swal.fire('Hata', 'Sunucu bağlantı hatası.', 'error'); }
    });
}

$(document).ready(function () {
    // Form seçeneklerini yükle
    postAjax({ action: 'form_options' }, function (res) {
        if (!res.success) return;

        res.data.kategoriler.forEach(function (k) {
            $('#kategori_id').append(`<option value="${k.id}">${k.ad}</option>`);
        });
        res.data.oncelikler.forEach(function (o) {
            const selected = (o.id == 2) ? 'selected' : '';
            $('#oncelik_id').append(`<option value="${o.id}" ${selected}>${o.ad}</option>`);
        });
        res.data.bildirimYollari.forEach(function (b) {
            $('#bildirim_yolu_id').append(`<option value="${b.id}"><i class="${b.ikon}"></i> ${b.ad}</option>`);
        });
        if (IS_ADMIN) {
            res.data.personeller.forEach(function (p) {
                $('#atanan_id').append(`<option value="${p.id}">${p.ad}</option>`);
                $('#olusturan_id').append(`<option value="${p.id}">${p.ad}</option>`);
            });
            (res.data.kaynaklar || []).forEach(function (k) {
                $('#kaynak_id').append(`<option value="${k.id}">${k.ad} (${k.kod})</option>`);
            });
        }

        $('.select2-basic').select2({
            theme: 'bootstrap-5',
            width: '100%'
        });
    });

    // Form gönder
    let gonderiliyor = false;
    $('#formTicket').on('submit', function (e) {
        e.preventDefault();
        if (gonderiliyor) return;

        const konu     = $('#konu').val().trim();
        const aciklama = $('#aciklama').val().trim();
        const katId    = $('#kategori_id').val();
        const oncId    = $('#oncelik_id').val();

        if (!konu)     { Swal.fire('Uyarı', 'Konu boş olamaz.', 'warning'); return; }
        if (!aciklama) { Swal.fire('Uyarı', 'Açıklama boş olamaz.', 'warning'); return; }
        if (!katId)    { Swal.fire('Uyarı', 'Kategori seçiniz.', 'warning'); return; }
        if (!oncId)    { Swal.fire('Uyarı', 'Öncelik seçiniz.', 'warning'); return; }

        const fd = new FormData(this);
        fd.append('action', 'ticket_ekle');

        gonderiliyor = true;
        $('#btnKaydet').prop('disabled', true).html('<i class="bi bi-hourglass-split me-1"></i> Oluşturuluyor...');

        postAjax({}, function (res) {
            if (res.success) {
                // Başarılı: buton kilitli kalır, sayfadan çıkılana kadar tekrar gönderilemez
                Swal.fire({
                    icon: 'success',
                    title: 'Ticket Oluşturuldu',
                    html: `Ticket No: <strong>${res.ticket_no}</strong>`,
                    showCancelButton: true,
                    confirmButtonText: 'Detaya Git',
                    cancelButtonText: 'Listeye Dön'
                }).then(function (result) {
                    if (result.isConfirmed) {
                        window.location.href = '/admin/ticket-detay?id=' + res.ticket_id;
                    } else {
                        window.location.href = '/admin/ticket-liste';
                    }
                });
            } else {
                gonderiliyor = false;
                $('#btnKaydet').prop('disabled', false).html('<i class="bi bi-send me-1"></i> Ticket Oluştur');
                Swal.fire('Hata', res.message, 'error');
            }
        }, fd);
    });
});
</script>
</body>
</html>
