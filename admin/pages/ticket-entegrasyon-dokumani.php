<?php
/**
 * Admin Panel - Ticket Entegrasyon Dokumani
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
requireAuth();

$user = Auth::user();
$db = Database::getInstance();

// Sadece admin erişebilir
if ($user['departman_id'] != 1) {
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

$pageTitle = $pageInfo['sayfalar_sayfa_adi'] ?? 'Ticket Entegrasyon Dokumani';
$menuAdi = $pageInfo['menu_adi'] ?? null;

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';

$kaynaklar = $db->fetchAll(
    "SELECT Ticket_Kaynak_id, Ticket_Kaynak_ad, Ticket_Kaynak_tip, Ticket_Kaynak_kod, Ticket_Kaynak_api_url, Ticket_Kaynak_api_key, Durum
     FROM Ticket_Kaynak
     WHERE Durum = 1
     ORDER BY Ticket_Kaynak_ad ASC"
);

$canliTicketler = $db->fetchAll(
        "SELECT TOP 500
                t.Tickets_id,
                t.Tickets_no,
                t.Tickets_konu,
                t.Tickets_kaynak_id,
                t.Tickets_olusturan_kullanici_id,
                ku.kullanici_ad,
                ku.kullanici_soyad,
                ku.kullanici_email,
                ku.kullanici_telefon,
                CONVERT(VARCHAR(16), t.OlusturmaTarihi, 120) AS OlusturmaTarihiFmt
         FROM Tickets t
         LEFT JOIN kullanicilar ku ON ku.kullanici_id = t.Tickets_olusturan_kullanici_id
         WHERE t.Durum = 1
             AND ISNULL(t.Tickets_kaynak_id, 0) > 0
         ORDER BY t.OlusturmaTarihi DESC"
);
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
        .doc-code {
            background: #111827;
            color: #f3f4f6;
            border-radius: 8px;
            padding: 12px;
            font-family: Consolas, "Courier New", monospace;
            font-size: 12px;
            white-space: pre-wrap;
            word-break: break-word;
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
                                <h3>4</h3>
                                <p>Aktif Endpoint</p>
                            </div>
                            <i class="small-box-icon bi bi-diagram-3"></i>
                        </div>
                    </div>
                    <div class="col-12 col-md-3">
                        <div class="small-box text-bg-success">
                            <div class="inner">
                                <h3>1</h3>
                                <p>Header Zorunlu</p>
                            </div>
                            <i class="small-box-icon bi bi-shield-lock"></i>
                        </div>
                    </div>
                    <div class="col-12 col-md-3">
                        <div class="small-box text-bg-warning">
                            <div class="inner">
                                <h3>2</h3>
                                <p>Zorunlu Kimlik</p>
                            </div>
                            <i class="small-box-icon bi bi-person-badge"></i>
                        </div>
                    </div>
                    <div class="col-12 col-md-3">
                        <div class="small-box text-bg-info">
                            <div class="inner">
                                <h3>JSON</h3>
                                <p>Veri Formatı</p>
                            </div>
                            <i class="small-box-icon bi bi-braces"></i>
                        </div>
                    </div>
                </div>

                <div class="card mb-3">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <button class="btn btn-link p-0 text-decoration-none" data-bs-toggle="collapse" data-bs-target="#filterPanel">
                                <i class="bi bi-funnel me-1"></i> Filtreler
                            </button>
                        </h5>
                    </div>
                    <div id="filterPanel" class="collapse show">
                        <div class="card-body bg-light">
                            <div class="row g-3">
                                <div class="col-12 col-md-4">
                                    <label class="form-label">Action</label>
                                    <select class="form-select select2-basic" id="filtreAction">
                                        <option value="">Tümü</option>
                                        <option value="create_ticket">create_ticket</option>
                                        <option value="reply_ticket">reply_ticket</option>
                                        <option value="ticket_detail">ticket_detail</option>
                                        <option value="ticket_messages">ticket_messages</option>
                                        <option value="ticket_meta">ticket_meta</option>
                                    </select>
                                </div>
                                <div class="col-12 col-md-4">
                                    <label class="form-label">Method</label>
                                    <select class="form-select select2-basic" id="filtreMethod">
                                        <option value="">Tümü</option>
                                        <option value="POST">POST</option>
                                    </select>
                                </div>
                                <div class="col-12 col-md-4">
                                    <label class="form-label">Arama</label>
                                    <input type="text" class="form-control" id="filtreArama" placeholder="Action veya açıklama">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card mb-3">
                    <div class="card-header">
                        <h5 class="card-title mb-0"><i class="bi bi-hdd-network me-1"></i> Canlı Kaynak Bilgisi</h5>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-12 col-md-6">
                                <label class="form-label">Kaynak Seçimi</label>
                                <select class="form-select select2-basic" id="kaynakSecim">
                                    <?php foreach ($kaynaklar as $kaynak): ?>
                                        <option value="<?= (int)$kaynak['Ticket_Kaynak_id'] ?>">
                                            <?= htmlspecialchars($kaynak['Ticket_Kaynak_ad']) ?> (<?= htmlspecialchars($kaynak['Ticket_Kaynak_kod']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label">Kaynak API URL</label>
                                <input type="text" class="form-control" id="kaynakApiUrl" readonly>
                            </div>
                            <div class="col-12 col-md-4">
                                <label class="form-label">Kaynak Kodu</label>
                                <input type="text" class="form-control" id="canliKaynakKod" readonly>
                            </div>
                            <div class="col-12 col-md-4">
                                <label class="form-label">Kaynak Tipi</label>
                                <input type="text" class="form-control" id="canliKaynakTip" readonly>
                            </div>
                            <div class="col-12 col-md-4">
                                <label class="form-label">API Key</label>
                                <div class="input-group">
                                    <input type="text" class="form-control" id="canliApiKey" readonly>
                                    <button type="button" class="btn btn-outline-secondary" id="btnCanliKeyKopyala">
                                        <i class="bi bi-clipboard"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="col-12">
                                <a href="#" class="btn btn-success" id="btnPaketIndir" download>
                                    <i class="bi bi-download me-1"></i> Kaynağa Özel PHP Entegrasyon Dosyasını İndir
                                </a>
                                <button type="button" class="btn btn-info ms-2 disabled" id="btnJsonIndir">
                                    <i class="bi bi-filetype-json me-1"></i> JSON Dokümantasyonu İndir
                                </button>
                                <div class="form-text">PHP butonu seçili kaynağa özel istemci dosyası; JSON butonu tüm API dokümantasyonunu indirir.</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card mb-3">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0"><i class="bi bi-ticket-detailed me-1"></i> Canlı Ticket Seçimi</h5>
                        <small class="text-muted">Seçilen ticket tüm örnek sorgulara otomatik uygulanır.</small>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover table-sm align-middle" id="canliTicketTable" style="width:100%">
                                <thead class="table-light">
                                    <tr>
                                        <th>Ticket ID</th>
                                        <th>Ticket No</th>
                                        <th>Konu</th>
                                        <th>Oluşturan Kullanıcı ID</th>
                                        <th>Açılış</th>
                                        <th>İşlem</th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                        <div class="alert alert-secondary mt-2 mb-0 py-2" id="seciliTicketBilgi">
                            Seçili ticket bulunamadı.
                        </div>
                    </div>
                </div>

                <div class="card mb-3">
                    <div class="card-header">
                        <h5 class="card-title mb-0"><i class="bi bi-list-check me-1"></i> Endpoint Referansı</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover table-sm align-middle" id="endpointTable" style="width:100%">
                                <thead class="table-light">
                                    <tr>
                                        <th>Action</th>
                                        <th>Method</th>
                                        <th>URL</th>
                                        <th>Zorunlu Alanlar</th>
                                        <th>Açıklama</th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="card mb-3">
                    <div class="card-header">
                        <h5 class="card-title mb-0"><i class="bi bi-journal-code me-1"></i> API Docs Kullanımı</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered table-sm align-middle mb-2">
                                <thead class="table-light">
                                    <tr>
                                        <th>Endpoint</th>
                                        <th>Açıklama</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td><code>/api/ticket-docs.php?action=full</code></td>
                                        <td>Tüm dokümantasyon, örnekler ve changelog bilgisi.</td>
                                    </tr>
                                    <tr>
                                        <td><code>/api/ticket-docs.php?action=meta</code></td>
                                        <td>Sadece sürüm özeti ve docs hash bilgisi.</td>
                                    </tr>
                                    <tr>
                                        <td><code>/api/ticket-docs.php?action=changelog</code></td>
                                        <td>Değişiklik geçmişi (varsayılan limit ile).</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div class="alert alert-info mb-0">
                            Bu endpointlerde kimlik doğrulama için <strong>X-API-KEY</strong> header zorunludur.
                        </div>
                    </div>
                </div>

                <div class="card mb-3">
                    <div class="card-header">
                        <h5 class="card-title mb-0"><i class="bi bi-database-check me-1"></i> Veritabanı Kayıt Akışı</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered table-sm align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th>Action</th>
                                        <th>Yazılan / Güncellenen Tablolar</th>
                                        <th>Önemli Alanlar</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td><span class="badge text-bg-primary">create_ticket</span></td>
                                        <td>
                                            Ticket_Kaynak_KullaniciEsleme (gerekirse), kullanicilar (gerekirse), Tickets, Ticket_Mesajlar
                                        </td>
                                        <td>
                                            Tickets_no, Tickets_kaynak_id, Tickets_olusturan_kullanici_id, Tickets_kategori_id, Tickets_oncelik_id, Tickets_durum_id, Ticket_Mesajlar_mesaj
                                        </td>
                                    </tr>
                                    <tr>
                                        <td><span class="badge text-bg-primary">reply_ticket</span></td>
                                        <td>
                                            Ticket_Mesajlar, Tickets (son yanit tarihi)
                                        </td>
                                        <td>
                                            Ticket_Mesajlar_ticket_id, Ticket_Mesajlar_yazan_kul_id, Ticket_Mesajlar_kaynak, Tickets_son_yanit_tarihi
                                        </td>
                                    </tr>
                                    <tr>
                                        <td><span class="badge text-bg-primary">ticket_detail</span></td>
                                        <td>
                                            Yazma yok, sadece okuma
                                        </td>
                                        <td>
                                            Tickets + Ticket_Kategoriler + Ticket_Oncelikler + Ticket_Durumlar + kullanicilar
                                        </td>
                                    </tr>
                                    <tr>
                                        <td><span class="badge text-bg-primary">ticket_messages</span></td>
                                        <td>
                                            Yazma yok, sadece okuma
                                        </td>
                                        <td>
                                            Ticket_Mesajlar + Tickets + kullanicilar
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div class="alert alert-info mt-2 mb-0">
                            Aynı kaynak kullanıcısı tekrar istek attığında önce Ticket_Kaynak_KullaniciEsleme kontrol edilir. Eşleşme yoksa kullanıcı harici tipinde kullanıcılar tablosuna eklenir ve eşleme kaydı açılır.
                        </div>
                    </div>
                </div>

                <div class="card mb-3">
                    <div class="card-header">
                        <h5 class="card-title mb-0"><i class="bi bi-exclamation-octagon me-1"></i> Hata Senaryoları ve Çözüm</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered table-sm align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th>Senaryo</th>
                                        <th>Örnek API Cevabı</th>
                                        <th>Kontrol / Çözüm</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td>API key eksik veya geçersiz</td>
                                        <td><code>{"success":false,"message":"Geçersiz API anahtarı."}</code></td>
                                        <td>X-API-KEY header zorunlu. Ticket_Kaynak kaydındaki güncel anahtarı kullanın.</td>
                                    </tr>
                                    <tr>
                                        <td>Kaynak pasif (Durum=0)</td>
                                        <td><code>{"success":false,"message":"Kaynak aktif degil."}</code></td>
                                        <td>Ticket_Kaynak yönetiminden kaynağı aktif edin veya yeni aktif kaynak tanımlayın.</td>
                                    </tr>
                                    <tr>
                                        <td>Action eksik veya hatalı</td>
                                        <td><code>{"success":false,"message":"Gönderilen verilerde eksik veya hatalı alanlar var.","errors":{"action":"action alanı zorunludur."}}</code></td>
                                        <td>Request gövdesinde action değerini dokümandaki 4 action'dan biri olarak gönderin.</td>
                                    </tr>
                                    <tr>
                                        <td>kullanici bilgileri eksik</td>
                                        <td><code>{"success":false,"message":"Gönderilen verilerde eksik veya hatalı alanlar var.","errors":{"kullanici.ad":"'kullanici' nesnesi içerisinde 'ad' alanı zorunludur."}}</code></td>
                                        <td>Kullanıcı nesnesi içinde benzersiz bir ID, ad ve e-posta gönderin.</td>
                                    </tr>
                                    <tr>
                                        <td>ticket_id bulunamadı veya erişim yok</td>
                                        <td><code>{"success":false,"message":"Gönderilen verilerde eksik veya hatalı alanlar var.","errors":{"ticket_id":"Root seviyesinde 'ticket_id' alanı zorunludur."}}</code><br><br>Veya (Ticket yoksa):<br><code>{"success":false,"message":"Ticket bulunamadı."}</code></td>
                                        <td>ticket_id, kaynak ve kullanıcı uyumunu kontrol edin; ticket aktif olmalıdır.</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div class="alert alert-warning mt-2 mb-0">
                            Üretim ortamında API key loglara açık metin yazdırılmamalı. Anahtar yenileme sonrası istemci tarafta anlık güncelleme yapın.
                        </div>
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-12 col-lg-6">
                        <div class="card h-100">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5 class="card-title mb-0"><i class="bi bi-send-plus me-1"></i> create_ticket Örneği</h5>
                                <button class="btn btn-outline-secondary btn-sm" onclick="copyCode('codeCreate')"><i class="bi bi-clipboard me-1"></i>Kopyala</button>
                            </div>
                            <div class="card-body">
                                                                <div class="doc-code" id="codeCreate"></div>
                            </div>
                        </div>
                    </div>

                    <div class="col-12 col-lg-6">
                        <div class="card h-100">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5 class="card-title mb-0"><i class="bi bi-reply me-1"></i> reply_ticket Örneği</h5>
                                <button class="btn btn-outline-secondary btn-sm" onclick="copyCode('codeReply')"><i class="bi bi-clipboard me-1"></i>Kopyala</button>
                            </div>
                            <div class="card-body">
                                                                <div class="doc-code" id="codeReply"></div>
                            </div>
                        </div>
                    </div>

                    <div class="col-12 col-lg-6">
                        <div class="card h-100">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5 class="card-title mb-0"><i class="bi bi-file-earmark-text me-1"></i> ticket_detail Örneği</h5>
                                <button class="btn btn-outline-secondary btn-sm" onclick="copyCode('codeDetail')"><i class="bi bi-clipboard me-1"></i>Kopyala</button>
                            </div>
                            <div class="card-body">
                                <div class="doc-code" id="codeDetail"></div>
                            </div>
                        </div>
                    </div>

                    <div class="col-12 col-lg-6">
                        <div class="card h-100">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5 class="card-title mb-0"><i class="bi bi-list-ul me-1"></i> list_tickets Örneği</h5>
                                <button class="btn btn-outline-secondary btn-sm" onclick="copyCode('codeList')"><i class="bi bi-clipboard me-1"></i>Kopyala</button>
                            </div>
                            <div class="card-body">
                                <div class="doc-code" id="codeList"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card mt-3">
                    <div class="card-header">
                        <h5 class="card-title mb-0"><i class="bi bi-window-sidebar me-1"></i> Hazır UI Seçenekleri (Entegrasyon Paketi)</h5>
                    </div>
                    <div class="card-body">
                        <p class="mb-2"><strong>PHP Entegrasyon Paketi</strong> indirdiğinizde içerisinde 2 farklı hazır arayüz şablonu ve arka planda güvenli haberleşmeyi sağlayan bir <strong>Proxy Katmanı</strong> bulunur.</p>
                        
                        <div class="row g-3">
                            <div class="col-md-6">
                                <div class="border rounded p-3 h-100 bg-light">
                                    <h6 class="fw-bold"><i class="bi bi-chat-square-dots text-primary me-1"></i> Seçenek 1: Yüzen Widget (Floating)</h6>
                                    <p class="small text-muted mb-2"><code>destek-widget.php.tpl</code></p>
                                    <p class="small mb-0">Sayfanın sağ alt köşesinde havada duran bir ikon. Tıklandığında küçük bir pencerede bilet listesi açılır. Genel amaçlı websiteleri veya müşteri portalları için uygundur.</p>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="border rounded p-3 h-100 bg-light">
                                    <h6 class="fw-bold"><i class="bi bi-layout-text-window-reverse text-success me-1"></i> Seçenek 2: Header Bildirim Dropdown (Native)</h6>
                                    <p class="small text-muted mb-2"><code>destek-header.php.tpl</code></p>
                                    <p class="small mb-0">Özel yönetim panelleri için tasarlanmış native bir görünüm. Sayfanın Header barında (bildirim çanının yanında) yer alır. AJAX ile arka planda yoklar ve yeni yanıt varsa kırmızı badge ile gösterir. Tıklandığında Bootstrap Dropdown menü açılır.</p>
                                </div>
                            </div>
                        </div>

                        <div class="alert alert-info mt-3 mb-0">
                            <h6 class="alert-heading fw-bold"><i class="bi bi-shield-lock me-1"></i> Proxy ve LocalStorage Mimarisi (Güvenlik & Performans)</h6>
                            <ul class="mb-0 small">
                                <li>Frontend hiçbir zaman doğrudan API'mize istek atmaz, API Key'iniz ifşa olmaz. <code>destek-bildirim.php</code> proxy dosyası sunucu tarafında CURL ile veriyi çeker.</li>
                                <li>Kullanıcıya sadece yeni mesajları (okumadıklarını) kırmızı uyarı olarak göstermek için, kullanıcının biletlere en son baktığı an (Sunucu Tarih formatında <code>Y-m-d H:i:s</code>) tarayıcının <strong>localStorage</strong> (<code>destek_son_kontrol</code>) alanında tutulur.</li>
                                <li>Bu sayede tarayıcı ile sunucu arasındaki Timezone (Saat Dilimi) farklılıkları, tarihlerin Integer'a çevrilmeden doğrudan alfebetik String olarak (<code>'2026-05-20 18:45' > '2026-05-20 17:00'</code>) karşılaştırılmasıyla tamamen ortadan kaldırılır.</li>
                            </ul>
                        </div>
                    </div>
                </div>

                <div class="card mt-3">
                    <div class="card-header">
                        <h5 class="card-title mb-0"><i class="bi bi-shield-check me-1"></i>Zorunlu Header</h5>
                    </div>
                    <div class="card-body">
                        <div class="doc-code" id="headerCode"></div>
                    </div>
                </div>

            </div>
        </div>
    </main>

    <?php include __DIR__ . '/../includes/footer.php'; ?>
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
const KAYNAKLAR = <?= json_encode($kaynaklar, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const CANLI_TICKETLER = <?= json_encode($canliTicketler, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const endpointData = [
    {
        action: 'create_ticket',
        method: 'POST',
        url: '/api/tickets.php',
        required: 'action, kullanici.ad, kullanici.eposta, konu, mesaj, kategori_id, oncelik_id',
        description: 'Yeni ticket oluşturur. kullanici{} nesnesi zorunludur (ad, eposta). Opsiyonel: soyad, telefon, dosyalar[], ekler[].'
    },
    {
        action: 'reply_ticket',
        method: 'POST',
        url: '/api/tickets.php',
        required: 'action, ticket_id, eposta, mesaj',
        description: 'Mevcut ticket\'a yanıt ekler. Sadece eposta yeterli. Opsiyonel: dosyalar[], ekler[].'
    },
    {
        action: 'list_tickets',
        method: 'POST',
        url: '/api/tickets.php',
        required: 'action, eposta',
        description: 'Kullanıcının tüm ticketlarını listeler. Durum, öncelik, mesaj sayısı bilgisi döner.'
    },
    {
        action: 'ticket_detail',
        method: 'POST',
        url: '/api/tickets.php',
        required: 'action, ticket_id, eposta',
        description: 'Ticket detayı + mesajları + ekleri tek seferde döner.'
    },
    {
        action: 'ticket_meta',
        method: 'POST',
        url: '/api/tickets.php',
        required: 'action',
        description: 'Aktif kategori, öncelik ve durum listelerini döndürür. create_ticket öncesi kullanılması önerilir.'
    }
];

let seciliTicketId = null;
let canliTicketTable = null;

function copyCode(id) {
    const text = document.getElementById(id).innerText;
    navigator.clipboard.writeText(text).then(function () {
        Swal.fire({
            toast: true,
            position: 'top-end',
            icon: 'success',
            title: 'Kod kopyalandı',
            showConfirmButton: false,
            timer: 1500
        });
    });
}

function currentKaynak() {
    const id = parseInt($('#kaynakSecim').val() || '0', 10);
    return KAYNAKLAR.find(function (k) { return parseInt(k.Ticket_Kaynak_id, 10) === id; }) || null;
}

function buildKullanici(ad, soyad, eposta, telefon) {
    return {
        ad: ad || 'Batuhan',
        soyad: soyad || 'Kahraman',
        eposta: eposta || 'ornek@site.com',
        telefon: telefon || '5xxxxxxxxx'
    };
}

function seciliTicket() {
    if (!seciliTicketId) return null;
    return CANLI_TICKETLER.find(function (t) {
        return parseInt(t.Tickets_id, 10) === parseInt(seciliTicketId, 10);
    }) || null;
}

function filtreliTicketler() {
    const k = currentKaynak();
    if (!k) return [];
    return CANLI_TICKETLER.filter(function (t) {
        return parseInt(t.Tickets_kaynak_id, 10) === parseInt(k.Ticket_Kaynak_id, 10);
    });
}

function renderOrnekKodlar() {
    const k = currentKaynak();
    if (!k) {
        $('#codeCreate').text('');
        $('#codeReply').text('');
        $('#codeDetail').text('');
        $('#codeList').text('');
        $('#headerCode').text('');
        $('#seciliTicketBilgi').text('Seçili kaynak bulunamadı.');
        return;
    }

    const endpoint = window.location.origin + '/api/tickets.php';
    const ticket = seciliTicket();
    const ticketId = ticket ? parseInt(ticket.Tickets_id, 10) : 2;
    const olusturanId = ticket && ticket.Tickets_olusturan_kullanici_id
        ? String(ticket.Tickets_olusturan_kullanici_id)
        : '1';
    const ad = ticket && ticket.kullanici_ad ? String(ticket.kullanici_ad) : 'Batuhan';
    const soyad = ticket && ticket.kullanici_soyad ? String(ticket.kullanici_soyad) : 'Kahraman';
    const eposta = ticket && ticket.kullanici_email ? String(ticket.kullanici_email) : 'ornek@site.com';
    const telefon = ticket && ticket.kullanici_telefon ? String(ticket.kullanici_telefon) : '5xxxxxxxxx';
    const kullaniciObj = buildKullanici(ad, soyad, eposta, telefon);

    if (ticket) {
        const detayLink = '/admin/ticket-detay?id=' + encodeURIComponent(String(ticketId));
        $('#seciliTicketBilgi').html(
            '<strong>Seçili Ticket:</strong> #' + ticketId
            + ' (' + (ticket.Tickets_no || '-') + ') - '
            + $('<div>').text(ticket.Tickets_konu || '-').html()
            + ' | <a href="' + detayLink + '" target="_blank">Detayı aç</a>'
        );
    } else {
        $('#seciliTicketBilgi').text('Seçilen kaynakta ticket bulunamadı. Örneklerde varsayılan ticket_id=2 kullanılıyor.');
    }

    // create_ticket: kullanici{} nesnesi zorunlu + opsiyonel ekler
    const createPayloadObj = {
        action: 'create_ticket',
        kullanici: kullaniciObj,
        konu: 'API test ticket',
        mesaj: 'Harici siteden test kaydı',
        kategori_id: 1,
        oncelik_id: 2,
        ekler: [
            {
                dosya_adi: 'ornek-log.txt',
                mime_turu: 'text/plain',
                icerik_base64: 'U2VsYW0sIGJ1IGJpciBkZW5lbWUgZG9zeWFzaWRpci4='
            }
        ]
    };

    // reply_ticket: eposta + mesaj + opsiyonel ekler
    const replyPayloadObj = {
        action: 'reply_ticket',
        ticket_id: ticketId,
        eposta: eposta,
        mesaj: 'Harici siteden yanıt testi',
        ekler: [
            {
                dosya_adi: 'cevap-ek.pdf',
                mime_turu: 'application/pdf',
                icerik_base64: 'JVBERi0xLjQgZGVuZW1l...'
            }
        ]
    };

    // list_tickets: sadece eposta
    const listPayload = {
        action: 'list_tickets',
        eposta: eposta
    };

    // ticket_detail: ticket_id + eposta (mesajlar dahil döner)
    const detailPayload = {
        action: 'ticket_detail',
        ticket_id: ticketId,
        eposta: eposta
    };

    $('#codeCreate').text(JSON.stringify(createPayloadObj, null, 2));
    $('#codeReply').text(JSON.stringify(replyPayloadObj, null, 2));
    $('#codeList').text(JSON.stringify(listPayload, null, 2));
    $('#codeDetail').text(JSON.stringify(detailPayload, null, 2));

    $('#headerCode').text(
        'POST ' + endpoint + '\n'
        + 'Content-Type: application/json (Postman Body: raw -> JSON)\n'
        + 'X-API-KEY: ' + (k.Ticket_Kaynak_api_key || '')
    );
}

function canliTicketSec(ticketId) {
    seciliTicketId = parseInt(ticketId || '0', 10) || null;
    if (canliTicketTable) {
        canliTicketTable.rows().every(function () {
            const rowData = this.data();
            const same = parseInt(rowData.Tickets_id, 10) === parseInt(seciliTicketId || '0', 10);
            $(this.node()).toggleClass('table-active', same);
        });
    }
    renderOrnekKodlar();
}

function canliTicketleriYenile() {
    if (!canliTicketTable) return;
    const data = filtreliTicketler();

    canliTicketTable.clear().rows.add(data).draw();

    const seciliBuKaynaktaVar = data.some(function (t) {
        return parseInt(t.Tickets_id, 10) === parseInt(seciliTicketId || '0', 10);
    });

    if (!seciliBuKaynaktaVar) {
        seciliTicketId = data.length ? parseInt(data[0].Tickets_id, 10) : null;
    }

    if (canliTicketTable.rows().count()) {
        canliTicketTable.rows().every(function () {
            const rowData = this.data();
            const same = parseInt(rowData.Tickets_id, 10) === parseInt(seciliTicketId || '0', 10);
            $(this.node()).toggleClass('table-active', same);
        });
    }

    renderOrnekKodlar();
}

function renderCanliBilgi() {
    const k = currentKaynak();
    if (!k) {
        $('#canliEndpoint').val(window.location.origin + '/api/tickets.php');
        $('#kaynakApiUrl').val('');
        $('#canliKaynakKod').val('');
        $('#canliKaynakTip').val('');
        $('#canliApiKey').val('');
        $('#btnPaketIndir').attr('href', '#').addClass('disabled');
        $('#btnJsonIndir').addClass('disabled');
        canliTicketleriYenile();
        return;
    }

    const endpoint = window.location.origin + '/api/tickets.php';
    $('#canliEndpoint').val(endpoint);
    $('#kaynakApiUrl').val(k.Ticket_Kaynak_api_url || '');
    $('#canliKaynakKod').val(k.Ticket_Kaynak_kod || '');
    $('#canliKaynakTip').val(k.Ticket_Kaynak_tip || '');
    $('#canliApiKey').val(k.Ticket_Kaynak_api_key || '');
    $('#btnPaketIndir')
        .attr('href', '/api/ticket-entegrasyon-indir.php?kaynak_id=' + encodeURIComponent(String(k.Ticket_Kaynak_id)))
        .removeClass('disabled');
    $('#btnJsonIndir').removeClass('disabled');
    canliTicketleriYenile();
}

$(document).ready(function () {
    $('.select2-basic').select2({ theme: 'bootstrap-5', width: '100%' });

    const table = $('#endpointTable').DataTable({
        data: endpointData,
        columns: [
            { data: 'action' },
            { data: 'method' },
            { data: 'url' },
            { data: 'required' },
            { data: 'description' }
        ],
        columnDefs: [
            {
                targets: 1,
                render: function (data) {
                    return '<span class="badge text-bg-primary">' + data + '</span>';
                }
            },
            {
                targets: 2,
                render: function (data) {
                    return '<code>' + data + '</code>';
                }
            }
        ],
        paging: false,
        info: false,
        language: { url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json' }
    });

    canliTicketTable = $('#canliTicketTable').DataTable({
        data: [],
        columns: [
            { data: 'Tickets_id' },
            { data: 'Tickets_no' },
            { data: 'Tickets_konu' },
            { data: 'Tickets_olusturan_kullanici_id' },
            { data: 'OlusturmaTarihiFmt' },
            {
                data: null,
                orderable: false,
                searchable: false,
                render: function (data, type, row) {
                    const id = parseInt(row.Tickets_id, 10);
                    return '<button type="button" class="btn btn-sm btn-outline-primary btn-canli-ticket-sec" data-ticket-id="' + id + '">Seç</button>'
                        + ' <a class="btn btn-sm btn-outline-secondary" target="_blank" href="/admin/ticket-detay?id=' + id + '">Detay</a>';
                }
            }
        ],
        pageLength: 10,
        lengthMenu: [[10, 25, 50], [10, 25, 50]],
        order: [[0, 'desc']],
        language: { url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json' }
    });

    $('#filtreAction').on('change', function () {
        table.column(0).search(this.value).draw();
    });

    $('#filtreMethod').on('change', function () {
        table.column(1).search(this.value).draw();
    });

    $('#filtreArama').on('keyup', function () {
        table.search(this.value).draw();
    });

    $('#canliTicketTable').on('click', '.btn-canli-ticket-sec', function () {
        canliTicketSec($(this).data('ticket-id'));
    });

    $('#kaynakSecim').on('change', renderCanliBilgi);

    $('#btnJsonIndir').on('click', async function () {
        const k = currentKaynak();
        if (!k) return;
        const apiKey = k.Ticket_Kaynak_api_key || '';
        const btn = $(this);
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>İndiriliyor...');
        try {
            const response = await fetch('/api/ticket-docs.php?action=full', {
                headers: { 'X-API-KEY': apiKey }
            });
            if (!response.ok) {
                Swal.fire('Hata', 'API yanıt vermedi: HTTP ' + response.status, 'error');
                return;
            }
            const data = await response.json();
            const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'ticket-api-docs-' + (k.Ticket_Kaynak_kod || 'dokuman') + '.json';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
            Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: 'JSON indirildi', showConfirmButton: false, timer: 2000 });
        } catch (err) {
            Swal.fire('Hata', 'İndirme başarısız: ' + err.message, 'error');
        } finally {
            btn.prop('disabled', false).html('<i class="bi bi-filetype-json me-1"></i> JSON Dokumantasyonu Indir');
        }
    });

    $('#btnCanliKeyKopyala').on('click', function () {
        const key = $('#canliApiKey').val() || '';
        if (!key) return;
        navigator.clipboard.writeText(key).then(function () {
            Swal.fire({
                toast: true,
                position: 'top-end',
                icon: 'success',
                title: 'API key kopyalandi',
                showConfirmButton: false,
                timer: 1500
            });
        });
    });

    renderCanliBilgi();
});
</script>
</body>
</html>
