<?php
/**
 * Admin Panel - Default Dashboard
 * Ticket/Destek odaklı ana sayfa
 * NOT: Bu dosya anasayfa.php tarafından include edilir, doğrudan çağrılmaz!
 */

// $user ve $db değişkenleri anasayfa.php'den geliyor

$isAdmin = ((int)($user['departman_id'] ?? 0) === 1);
$kullaniciId = (int)($user['kullanici_id'] ?? 0);

// Site ayarlarını çek
$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Portal';

// AJAX İşlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {
            case 'dashboard_stats':
                $baseWhere = "t.Durum = 1";
                $params = [];

                if (!$isAdmin) {
                    $baseWhere .= " AND (t.Tickets_olusturan_kullanici_id = ? OR t.Tickets_atanan_kullanici_id = ?)";
                    $params = [$kullaniciId, $kullaniciId];
                }

                // Toplam açık ticket
                $acikTicket = $db->fetchOne("
                    SELECT COUNT(*) as sayi FROM Tickets t
                    INNER JOIN Ticket_Durumlar d ON t.Tickets_durum_id = d.Ticket_Durumlar_id
                    WHERE $baseWhere AND d.Ticket_Durumlar_kapatma_durumu = 0
                ", $params)['sayi'] ?? 0;

                // Bana atanan açık ticketlar
                $banaAtanan = $db->fetchOne("
                    SELECT COUNT(*) as sayi FROM Tickets t
                    INNER JOIN Ticket_Durumlar d ON t.Tickets_durum_id = d.Ticket_Durumlar_id
                    WHERE t.Durum = 1 AND t.Tickets_atanan_kullanici_id = ?
                      AND d.Ticket_Durumlar_kapatma_durumu = 0
                ", [$kullaniciId])['sayi'] ?? 0;

                // Bugün açılan ticketlar
                $bugunParams = $params;
                $bugunAcilan = $db->fetchOne("
                    SELECT COUNT(*) as sayi FROM Tickets t
                    WHERE $baseWhere
                      AND CAST(t.OlusturmaTarihi AS DATE) = CAST(GETDATE() AS DATE)
                ", $bugunParams)['sayi'] ?? 0;

                // Acil ticketlar
                $acilTicket = $db->fetchOne("
                    SELECT COUNT(*) as sayi FROM Tickets t
                    INNER JOIN Ticket_Durumlar d ON t.Tickets_durum_id = d.Ticket_Durumlar_id
                    INNER JOIN Ticket_Oncelikler o ON t.Tickets_oncelik_id = o.Ticket_Oncelikler_id
                    WHERE $baseWhere AND d.Ticket_Durumlar_kapatma_durumu = 0
                      AND o.Ticket_Oncelikler_ad = N'Acil'
                ", $params)['sayi'] ?? 0;

                // Bu hafta kapatılan
                $kapatilan = $db->fetchOne("
                    SELECT COUNT(*) as sayi FROM Tickets t
                    INNER JOIN Ticket_Durumlar d ON t.Tickets_durum_id = d.Ticket_Durumlar_id
                    WHERE $baseWhere AND d.Ticket_Durumlar_kapatma_durumu = 1
                      AND t.Tickets_kapanis_tarihi >= DATEADD(DAY, -7, GETDATE())
                ", $params)['sayi'] ?? 0;

                // Toplam ticket
                $toplamTicket = $db->fetchOne("
                    SELECT COUNT(*) as sayi FROM Tickets t WHERE $baseWhere
                ", $params)['sayi'] ?? 0;

                echo json_encode(['success' => true, 'data' => [
                    'acik_ticket'   => intval($acikTicket),
                    'bana_atanan'   => intval($banaAtanan),
                    'bugun_acilan'  => intval($bugunAcilan),
                    'acil_ticket'   => intval($acilTicket),
                    'kapatilan_hafta' => intval($kapatilan),
                    'toplam_ticket' => intval($toplamTicket),
                ]]);
                break;

            case 'son_ticketlar':
                $where = "t.Durum = 1 AND t.Tickets_durum_id = 1";
                $p = [];
                if (!$isAdmin) {
                    $where .= " AND (t.Tickets_olusturan_kullanici_id = ? OR t.Tickets_atanan_kullanici_id = ?)";
                    $p = [$kullaniciId, $kullaniciId];
                }

                $data = $db->fetchAll("
                    SELECT TOP 10
                        t.Tickets_id,
                        t.Tickets_no,
                        t.Tickets_konu,
                        d.Ticket_Durumlar_ad AS durum_ad,
                        d.Ticket_Durumlar_renk AS durum_renk,
                        o.Ticket_Oncelikler_ad AS oncelik_ad,
                        o.Ticket_Oncelikler_renk AS oncelik_renk,
                        ku.kullanici_ad + ' ' + ku.kullanici_soyad AS olusturan,
                        ka.kullanici_ad + ' ' + ka.kullanici_soyad AS atanan,
                        CONVERT(VARCHAR(16), t.OlusturmaTarihi, 120) AS tarih
                    FROM Tickets t
                    INNER JOIN Ticket_Durumlar d ON t.Tickets_durum_id = d.Ticket_Durumlar_id
                    INNER JOIN Ticket_Oncelikler o ON t.Tickets_oncelik_id = o.Ticket_Oncelikler_id
                    LEFT JOIN kullanicilar ku ON t.Tickets_olusturan_kullanici_id = ku.kullanici_id
                    LEFT JOIN kullanicilar ka ON t.Tickets_atanan_kullanici_id = ka.kullanici_id
                    WHERE $where
                    ORDER BY t.OlusturmaTarihi DESC
                ", $p);
                echo json_encode(['success' => true, 'data' => $data]);
                break;

            case 'bekleyen_ticketlar':
                // Son mesajı müşteri (ticket açan) tarafından yazılmış = admin yanıt bekleniyor
                $where = "t.Durum = 1 AND d.Ticket_Durumlar_kapatma_durumu = 0";
                $p = [];
                if (!$isAdmin) {
                    $where .= " AND t.Tickets_atanan_kullanici_id = ?";
                    $p = [$kullaniciId];
                }

                $data = $db->fetchAll("
                    SELECT TOP 10
                        t.Tickets_id,
                        t.Tickets_no,
                        t.Tickets_konu,
                        d.Ticket_Durumlar_ad AS durum_ad,
                        d.Ticket_Durumlar_renk AS durum_renk,
                        o.Ticket_Oncelikler_ad AS oncelik_ad,
                        o.Ticket_Oncelikler_renk AS oncelik_renk,
                        ku.kullanici_ad + ' ' + ku.kullanici_soyad AS olusturan,
                        CONVERT(VARCHAR(16), t.Tickets_son_yanit_tarihi, 120) AS son_yanit,
                        (SELECT COUNT(*) FROM Ticket_Mesajlar m WHERE m.Ticket_Mesajlar_ticket_id = t.Tickets_id AND m.Durum = 1) AS mesaj_sayisi
                    FROM Tickets t
                    INNER JOIN Ticket_Durumlar d ON t.Tickets_durum_id = d.Ticket_Durumlar_id
                    INNER JOIN Ticket_Oncelikler o ON t.Tickets_oncelik_id = o.Ticket_Oncelikler_id
                    LEFT JOIN kullanicilar ku ON t.Tickets_olusturan_kullanici_id = ku.kullanici_id
                    WHERE $where
                      AND (
                          -- Son mesajı müşteri yazmış (ic_not değil)
                          (SELECT TOP 1 Ticket_Mesajlar_yazan_kul_id
                           FROM Ticket_Mesajlar
                           WHERE Ticket_Mesajlar_ticket_id = t.Tickets_id
                             AND Durum = 1
                             AND Ticket_Mesajlar_ic_not = 0
                           ORDER BY OlusturmaTarihi DESC
                          ) = t.Tickets_olusturan_kullanici_id
                      )
                    ORDER BY o.Ticket_Oncelikler_sira ASC, t.Tickets_son_yanit_tarihi DESC
                ", $p);
                echo json_encode(['success' => true, 'data' => $data]);
                break;

            default:
                echo json_encode(['success' => false, 'message' => 'Geçersiz işlem']);
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
    <title>Ana Sayfa - <?= htmlspecialchars($siteTitle) ?></title>

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/source-sans-3@5.0.12/index.css" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" crossorigin="anonymous">
    <link rel="stylesheet" href="/admin/assets/css/adminlte.min.css">
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/admin/assets/css/custom.css">
    <style>
        .dashboard-card { transition: transform 0.2s, box-shadow 0.2s; border: none; }
        .dashboard-card:hover { transform: translateY(-3px); box-shadow: 0 8px 25px rgba(0,0,0,0.1); }
        .small-box .inner h3 { font-size: 2rem; font-weight: 700; }
        .small-box .inner p { font-size: 0.9rem; }
        .table-dashboard th { font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.5px; color: #6c757d; border-top: none; }
        .table-dashboard td { font-size: 0.85rem; vertical-align: middle; }
        .welcome-banner {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #fff;
            border-radius: 10px;
            padding: 1.5rem 2rem;
            margin-bottom: 1.5rem;
        }
        .welcome-banner h2 { font-weight: 700; margin-bottom: 0.25rem; }
        .welcome-banner p { opacity: 0.85; margin-bottom: 0; }
        .stat-mini { text-align: center; padding: 0.75rem; }
        .stat-mini .stat-val { font-size: 1.5rem; font-weight: 700; }
        .stat-mini .stat-lbl { font-size: 0.75rem; color: #6c757d; text-transform: uppercase; }
    </style>
</head>
<body class="layout-fixed sidebar-expand-lg sidebar-open bg-body-tertiary">
    <div class="app-wrapper">
        <?php include __DIR__ . '/../includes/header.php'; ?>
        <?php include __DIR__ . '/../includes/sidebar.php'; ?>

        <main class="app-main">
            <div class="app-content">
                <div class="container-fluid">

                    <!-- Hoşgeldin Bandı -->
                    <div class="welcome-banner">
                        <div class="d-flex justify-content-between align-items-center flex-wrap">
                            <div>
                                <h2><i class="bi bi-hand-wave me-2"></i>Hoş geldin, <?= htmlspecialchars($user['kullanici_ad'] ?? '') ?>!</h2>
                                <p><i class="bi bi-calendar3 me-1"></i> <?= strftime('%d %B %Y, %A') ?: date('d.m.Y') ?></p>
                            </div>
                            <div class="d-none d-md-flex gap-3 mt-2 mt-md-0 align-items-center">
                                <div class="stat-mini">
                                    <div class="stat-val text-white" id="mini_toplam">-</div>
                                    <div class="stat-lbl text-white-50">Toplam Ticket</div>
                                </div>
                                <div class="stat-mini">
                                    <div class="stat-val text-white" id="mini_kapatilan">-</div>
                                    <div class="stat-lbl text-white-50">Bu Hafta Kapatılan</div>
                                </div>
                                <div class="d-flex flex-column align-items-center gap-1 ms-2">
                                    <button id="btn-manual-refresh" class="btn btn-sm btn-light btn-outline-light" title="Şimdi Yenile">
                                        <i class="bi bi-arrow-clockwise"></i>
                                    </button>
                                    <span class="badge text-bg-secondary" id="refresh-badge" style="font-size:0.7rem;min-width:32px;">60s</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- InfoBox'lar -->
                    <div class="row mb-4">
                        <div class="col-6 col-lg-3">
                            <div class="small-box text-bg-warning dashboard-card">
                                <div class="inner">
                                    <h3 id="ib_acik">-</h3>
                                    <p>Açık Ticket</p>
                                </div>
                                <div class="small-box-icon"><i class="bi bi-circle"></i></div>
                                <a href="/admin/ticket-liste" class="small-box-footer link-light link-underline-opacity-0 link-underline-opacity-50-hover">
                                    Listeye Git <i class="bi bi-arrow-right-circle ms-1"></i>
                                </a>
                            </div>
                        </div>
                        <div class="col-6 col-lg-3">
                            <div class="small-box text-bg-primary dashboard-card">
                                <div class="inner">
                                    <h3 id="ib_bana_atanan">-</h3>
                                    <p>Bana Atanan</p>
                                </div>
                                <div class="small-box-icon"><i class="bi bi-person-check"></i></div>
                                <a href="/admin/ticket-liste" class="small-box-footer link-light link-underline-opacity-0 link-underline-opacity-50-hover">
                                    Detaylar <i class="bi bi-arrow-right-circle ms-1"></i>
                                </a>
                            </div>
                        </div>
                        <div class="col-6 col-lg-3">
                            <div class="small-box text-bg-success dashboard-card">
                                <div class="inner">
                                    <h3 id="ib_bugun">-</h3>
                                    <p>Bugün Açılan</p>
                                </div>
                                <div class="small-box-icon"><i class="bi bi-calendar-plus"></i></div>
                                <a href="/admin/ticket-liste" class="small-box-footer link-light link-underline-opacity-0 link-underline-opacity-50-hover">
                                    Detaylar <i class="bi bi-arrow-right-circle ms-1"></i>
                                </a>
                            </div>
                        </div>
                        <div class="col-6 col-lg-3">
                            <div class="small-box text-bg-danger dashboard-card">
                                <div class="inner">
                                    <h3 id="ib_acil">-</h3>
                                    <p>Acil Ticket</p>
                                </div>
                                <div class="small-box-icon"><i class="bi bi-exclamation-triangle"></i></div>
                                <a href="/admin/ticket-liste" class="small-box-footer link-light link-underline-opacity-0 link-underline-opacity-50-hover">
                                    Detaylar <i class="bi bi-arrow-right-circle ms-1"></i>
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- Tablolar -->
                    <div class="row mb-4">
                        <!-- Yeni Açılan Ticketlar -->
                        <div class="col-lg-6">
                            <div class="card dashboard-card h-100">
                                <div class="card-header d-flex justify-content-between align-items-center">
                                    <h3 class="card-title mb-0"><i class="bi bi-ticket-perforated me-2"></i>Yeni Açılan Ticketlar</h3>
                                    <a href="/admin/ticket-ekle" class="btn btn-success btn-sm"><i class="bi bi-plus me-1"></i>Yeni</a>
                                </div>
                                <div class="card-body p-0">
                                    <div class="table-responsive">
                                        <table class="table table-hover table-dashboard mb-0">
                                            <thead>
                                                <tr>
                                                    <th>No</th>
                                                    <th>Konu</th>
                                                    <th>Öncelik</th>
                                                    <th>Durum</th>
                                                    <th>Tarih</th>
                                                </tr>
                                            </thead>
                                            <tbody id="tbl_son_ticketlar">
                                                <tr><td colspan="5" class="text-center text-muted py-3">Yükleniyor...</td></tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <!-- Bekleyen / Atanan Ticketlar -->
                        <div class="col-lg-6">
                            <div class="card dashboard-card h-100">
                                <div class="card-header">
                                    <h3 class="card-title mb-0"><i class="bi bi-hourglass-split me-2"></i>Yanıt Bekleyen Ticketlar</h3>
                                </div>
                                <div class="card-body p-0">
                                    <div class="table-responsive">
                                        <table class="table table-hover table-dashboard mb-0">
                                            <thead>
                                                <tr>
                                                    <th>No</th>
                                                    <th>Konu</th>
                                                    <th>Öncelik</th>
                                                    <th>Mesaj</th>
                                                    <th>Son Yanıt</th>
                                                </tr>
                                            </thead>
                                            <tbody id="tbl_bekleyen">
                                                <tr><td colspan="5" class="text-center text-muted py-3">Yükleniyor...</td></tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </main>

        <?php include __DIR__ . '/../includes/footer.php'; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
    <script src="/admin/assets/js/adminlte.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <script src="/admin/assets/js/custom.js"></script>
    <script>
    function escHtml(str) {
        if (!str) return '';
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    const REFRESH_SURE = 60; // saniye
    let kalanSure = REFRESH_SURE;
    let refreshTimer;

    function tumunuYukle() {
        $('#refresh-badge').addClass('text-bg-info').removeClass('text-bg-secondary text-bg-warning');
        var tamamlandi = 0;
        function kontrol() {
            tamamlandi++;
            if (tamamlandi >= 3) {
                $('#refresh-badge').removeClass('text-bg-info');
                guncelGeriSayim();
            }
        }
        loadDashboardStats(kontrol);
        loadSonTicketlar(kontrol);
        loadBekleyenTicketlar(kontrol);
    }

    function geriSayimBaslat() {
        clearInterval(refreshTimer);
        kalanSure = REFRESH_SURE;
        guncelGeriSayim();
        refreshTimer = setInterval(function() {
            kalanSure--;
            guncelGeriSayim();
            if (kalanSure <= 0) {
                kalanSure = REFRESH_SURE;
                tumunuYukle();
            }
        }, 1000);
    }

    function guncelGeriSayim() {
        $('#refresh-badge').text(kalanSure + 's');
        if (kalanSure <= 10) {
            $('#refresh-badge').removeClass('text-bg-secondary text-bg-info').addClass('text-bg-warning');
        } else {
            $('#refresh-badge').removeClass('text-bg-warning text-bg-info').addClass('text-bg-secondary');
        }
    }

    $(document).ready(function() {
        tumunuYukle();
        geriSayimBaslat();

        // Manuel yenile butonu
        $('#btn-manual-refresh').on('click', function() {
            tumunuYukle();
            geriSayimBaslat();
        });
    });

    function loadDashboardStats(callback) {
        $.post('', { action: 'dashboard_stats' }, function(res) {
            if (res && res.success) {
                const d = res.data;
                $('#ib_acik').text(d.acik_ticket ?? '-');
                $('#ib_bana_atanan').text(d.bana_atanan ?? '-');
                $('#ib_bugun').text(d.bugun_acilan ?? '-');
                $('#ib_acil').text(d.acil_ticket ?? '-');
                $('#mini_toplam').text(d.toplam_ticket ?? '-');
                $('#mini_kapatilan').text(d.kapatilan_hafta ?? '-');
            }
            if (typeof callback === 'function') callback();
        }, 'json').fail(function(xhr) {
            // Oturum süresi dolmuşsa sayfayı yenile
            if (xhr.status === 401 || (xhr.responseText && xhr.responseText.indexOf('login') !== -1)) {
                location.reload();
            }
            if (typeof callback === 'function') callback();
        });
    }

    // --- Ses Bildirimi ---
    let _audioCtx = null;
    function getAudioCtx() {
        if (!_audioCtx) _audioCtx = new (window.AudioContext || window.webkitAudioContext)();
        return _audioCtx;
    }

    function sesCaldir() {
        try {
            const ctx = getAudioCtx();
            // Çift ding: 880Hz → 1100Hz
            [880, 1100].forEach(function(frekans, i) {
                const osc  = ctx.createOscillator();
                const gain = ctx.createGain();
                osc.connect(gain);
                gain.connect(ctx.destination);
                osc.type = 'sine';
                osc.frequency.setValueAtTime(frekans, ctx.currentTime + i * 0.18);
                gain.gain.setValueAtTime(0.4, ctx.currentTime + i * 0.18);
                gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + i * 0.18 + 0.5);
                osc.start(ctx.currentTime + i * 0.18);
                osc.stop(ctx.currentTime + i * 0.18 + 0.5);
            });
        } catch(e) { console.warn('Ses çalınamadı:', e); }
    }

    function yeniTicketBildirim(sayi, ilkKonu) {
        sesCaldir();

        // SweetAlert2 Toast
        Swal.fire({
            toast: true,
            position: 'top-end',
            icon: 'info',
            title: sayi + ' yeni ticket açıldı',
            text: ilkKonu ? '"' + ilkKonu + '"' : '',
            showConfirmButton: false,
            timer: 6000,
            timerProgressBar: true,
        });

        // Tarayıcı bildirim API (izin varsa)
        if (Notification && Notification.permission === 'granted') {
            new Notification('🎫 Yeni Ticket', {
                body: sayi + ' yeni ticket açıldı' + (ilkKonu ? ': ' + ilkKonu : ''),
                icon: '/admin/assets/img/favicon.png'
            });
        }
    }

    // Bilinen ticket ID seti (ilk yüklemede set edilir, sonraki yüklemelerde karşılaştırılır)
    let _bilinen_ticket_ids = null;
    let _ilk_yukleme = true;

    function loadSonTicketlar(callback) {
        $.post('', { action: 'son_ticketlar' }, function(res) {
            if (!res || !res.success || !res.data.length) {
                $('#tbl_son_ticketlar').html('<tr><td colspan="5" class="text-center text-muted py-3">Kayıt bulunamadı</td></tr>');
                _bilinen_ticket_ids = new Set();
                _ilk_yukleme = false;
                if (typeof callback === 'function') callback();
                return;
            }

            // Yeni ticket kontrolü
            const gelenIds = new Set(res.data.map(r => r.Tickets_id));
            if (!_ilk_yukleme && _bilinen_ticket_ids !== null) {
                const yeniIdler = [...gelenIds].filter(id => !_bilinen_ticket_ids.has(id));
                if (yeniIdler.length > 0) {
                    const ilkYeniTicket = res.data.find(r => yeniIdler.includes(r.Tickets_id));
                    yeniTicketBildirim(yeniIdler.length, ilkYeniTicket ? ilkYeniTicket.Tickets_konu : '');
                }
            }
            _bilinen_ticket_ids = gelenIds;
            _ilk_yukleme = false;

            let html = '';
            res.data.forEach(function(r) {
                html += '<tr>' +
                    '<td><a href="/admin/ticket-detay?id=' + r.Tickets_id + '" class="text-decoration-none"><span class="ticket-no">' + escHtml(r.Tickets_no) + '</span></a></td>' +
                    '<td><a href="/admin/ticket-detay?id=' + r.Tickets_id + '" class="text-decoration-none fw-semibold">' + escHtml(r.Tickets_konu) + '</a></td>' +
                    '<td><span class="badge" style="background-color:' + (r.oncelik_renk || '#6c757d') + '">' + escHtml(r.oncelik_ad) + '</span></td>' +
                    '<td><span class="badge" style="background-color:' + (r.durum_renk || '#6c757d') + '">' + escHtml(r.durum_ad) + '</span></td>' +
                    '<td><small class="text-muted">' + (r.tarih || '-') + '</small></td>' +
                    '</tr>';
            });
            $('#tbl_son_ticketlar').html(html);
            if (typeof callback === 'function') callback();
        }, 'json').fail(function(xhr) {
            if (xhr.status === 401 || (xhr.responseText && xhr.responseText.indexOf('login') !== -1)) {
                location.reload();
            }
            if (typeof callback === 'function') callback();
        });
    }

    function loadBekleyenTicketlar(callback) {
        $.post('', { action: 'bekleyen_ticketlar' }, function(res) {
            if (!res || !res.success || !res.data.length) {
                $('#tbl_bekleyen').html('<tr><td colspan="5" class="text-center text-muted py-3">Bekleyen ticket yok 🎉</td></tr>');
                if (typeof callback === 'function') callback();
                return;
            }
            let html = '';
            res.data.forEach(function(r) {
                html += '<tr>' +
                    '<td><a href="/admin/ticket-detay?id=' + r.Tickets_id + '" class="text-decoration-none"><span class="ticket-no">' + escHtml(r.Tickets_no) + '</span></a></td>' +
                    '<td><a href="/admin/ticket-detay?id=' + r.Tickets_id + '" class="text-decoration-none fw-semibold">' + escHtml(r.Tickets_konu) + '</a></td>' +
                    '<td><span class="badge" style="background-color:' + (r.oncelik_renk || '#6c757d') + '">' + escHtml(r.oncelik_ad) + '</span></td>' +
                    '<td><span class="badge text-bg-secondary">' + (r.mesaj_sayisi || 0) + '</span></td>' +
                    '<td><small class="text-muted">' + (r.son_yanit || '-') + '</small></td>' +
                    '</tr>';
            });
            $('#tbl_bekleyen').html(html);
            if (typeof callback === 'function') callback();
        }, 'json').fail(function(xhr) {
            if (xhr.status === 401 || (xhr.responseText && xhr.responseText.indexOf('login') !== -1)) {
                location.reload();
            }
            if (typeof callback === 'function') callback();
        });
    }
    </script>
</body>
</html>
