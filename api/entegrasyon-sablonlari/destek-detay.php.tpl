<?php
/**
 * Destek Talebi Detay / Yeni Talep
 * Kaynak: {{KAYNAK_AD}}
 * Bu dosya otomatik oluşturulmuştur.
 *
 * Kullanım:
 *   destek-detay.php?id=5    → Ticket detayı + mesajlar + yanıt formu
 *   destek-detay.php?yeni=1  → Yeni ticket oluşturma formu
 *
 * ═══════════════════════════════════════════════════════════════
 * API RESPONSE KEY REFERANSLARI
 * ═══════════════════════════════════════════════════════════════
 *
 * ticket_meta → data.kategoriler[] → { id, ad }
 *             → data.oncelikler[]  → { id, ad }
 *             → data.durumlar[]    → { id, ad, renk, ikon }
 *
 * ticket_detail → data:
 *   Tickets_id, Tickets_no, Tickets_konu
 *   durum_ad, durum_renk, durum_ikon
 *   oncelik_ad, oncelik_renk
 *   kategori_ad, kategori_renk
 *   acilis_tarihi, son_yanit_tarihi
 *   Ticket_Durumlar_kapatma_durumu  → 1 ise ticket kapalı
 *   atanan_ad           → Atanan kişi adı (null = henüz atanmadı)
 *   atanan_soyad        → Atanan kişi soyadı
 *   mesajlar[] → (aşağıya bakınız)
 *
 * mesajlar[] → her mesaj objesi:
 *   mesaj_id           → Mesaj ID
 *   mesaj               → Mesaj içeriği (text)
 *   mesaj_kaynak         → 'api' = kullanıcı (sağ/mavi), 'panel' = admin (sol/yeşil)
 *   yazan_ad, yazan_soyad → Yazan kişi adı
 *   tarih                → Mesaj tarihi (YYYY-MM-DD HH:MM)
 *   ekler[]              → Dosya ekleri (aşağıya bakınız)
 *
 * ekler[] → her ek objesi:
 *   dosya_adi   → Orijinal dosya adı
 *   dosya_url   → İndirme/görüntüleme linki (tam URL)
 *   dosya_boyutu → Byte cinsinden boyut
 *   mime_turu    → MIME tipi
 *
 * create_ticket → gönderilen payload:
 *   action, konu, mesaj, kategori_id, oncelik_id
 *   kullanici: { ad, soyad, eposta, telefon }
 *   ekler[]: { dosya_adi, mime_turu, icerik_base64 }
 *
 * reply_ticket → gönderilen payload:
 *   action, ticket_id, mesaj, eposta
 *   ekler[]: { dosya_adi, mime_turu, icerik_base64 }
 *
 * list_tickets → data[] her ticket:
 *   Tickets_id, Tickets_no, Tickets_konu
 *   durum_ad, durum_renk, oncelik_ad, oncelik_renk
 *   acilis_tarihi, son_yanit_tarihi, mesaj_sayisi
 *   atanan_ad, atanan_soyad
 * ═══════════════════════════════════════════════════════════════
 */
session_start();

$apiKey = '{{API_KEY}}';
$apiUrl = '{{API_ENDPOINT}}';

{{SESSION_BLOCK}}

function apiCall($url, $apiKey, $payload, $files = []) {
    $ch = curl_init($url);
    
    $headers = ['X-API-KEY: ' . $apiKey];
    $postData = [];
    
    if (!empty($files) && isset($files['dosyalar']) && is_array($files['dosyalar']['name'])) {
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $postData[$key] = json_encode($value, JSON_UNESCAPED_UNICODE);
            } else {
                $postData[$key] = $value;
            }
        }
        
        $count = count($files['dosyalar']['name']);
        for ($i = 0; $i < $count; $i++) {
            if (($files['dosyalar']['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
            $tmpName = $files['dosyalar']['tmp_name'][$i];
            $name = $files['dosyalar']['name'][$i];
            $type = $files['dosyalar']['type'][$i];
            
            $postData["dosyalar[$i]"] = new CURLFile($tmpName, $type, $name);
        }
    } else {
        $postData = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $headers[] = 'Content-Type: application/json';
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_POSTFIELDS     => $postData,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $res  = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // cURL bağlantı hatası (DNS, SSL, timeout vb.)
    if ($res === false) {
        return ['success' => false, 'message' => 'API bağlantı hatası: ' . ($err ?: 'Bilinmeyen hata') . ' — Endpoint: ' . $url];
    }

    // JSON parse her durumda dene (400 hata kodu bile JSON döndürebilir)
    $data = json_decode($res, true);
    if (is_array($data)) {
        return $data;
    }

    // JSON parse edilemiyorsa HTTP koduna göre ham hata döndür
    if ($code < 200 || $code >= 300) {
        return ['success' => false, 'message' => 'API HTTP hatası: ' . $code . ' — Yanıt: ' . mb_substr($res, 0, 200)];
    }

    return ['success' => false, 'message' => 'Geçersiz API yanıtı (JSON parse hatası). Ham yanıt: ' . mb_substr($res, 0, 200)];
}



// Meta bilgilerini her zaman çek
$metaResult = apiCall($apiUrl, $apiKey, ['action' => 'ticket_meta']);
$meta = ($metaResult['success'] ?? false) ? ($metaResult['data'] ?? []) : [];
$kategoriler = $meta['kategoriler'] ?? [];
$oncelikler  = $meta['oncelikler'] ?? [];

$ticketId = (int)($_GET['id'] ?? 0);
$yeniMi   = isset($_GET['yeni']);
$ticket   = null;
$mesajlar = [];
$hata     = '';
$hataObj  = [];
$basarili = '';

// ─── POST İşlemleri ───
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = $_POST['post_action'] ?? '';

    if ($postAction === 'create_ticket') {
        $kullanici = [];
        if ($eposta) {
            $kullanici = [
                'ad'     => $kulAd ?? '',
                'soyad'  => $kulSoyad ?? '',
                'eposta' => $eposta,
            ];
            if ($kulTelefon) {
                $kullanici['telefon'] = $kulTelefon;
            }
        }

        $payload = [
            'action'      => 'create_ticket',
            'konu'        => trim($_POST['konu'] ?? ''),
            'mesaj'       => trim($_POST['mesaj'] ?? ''),
            'kategori_id' => (int)($_POST['kategori_id'] ?? 0),
            'oncelik_id'  => (int)($_POST['oncelik_id'] ?? 0),
        ];
        if (!empty($kullanici)) {
            $payload['kullanici'] = $kullanici;
        }
        $files = !empty($_FILES['dosyalar']) ? ['dosyalar' => $_FILES['dosyalar']] : [];

        $result = apiCall($apiUrl, $apiKey, $payload, $files);
        if ($result['success'] ?? false) {
            $yeniTicketId = $result['data']['ticket_id'] ?? 0;
            header('Location: destek-detay.php?id=' . $yeniTicketId . '&ok=1');
            exit;
        } else {
            $hata = $result['message'] ?? 'Bir hata oluştu.';
            $hataObj = $result['errors'] ?? [];
            $yeniMi = true;
        }
    }

    if ($postAction === 'reply_ticket' && $ticketId > 0) {
        $payload = [
            'action'    => 'reply_ticket',
            'ticket_id' => $ticketId,
            'mesaj'     => trim($_POST['mesaj'] ?? ''),
        ];
        if ($eposta) $payload['eposta'] = $eposta;
        $files = !empty($_FILES['dosyalar']) ? ['dosyalar' => $_FILES['dosyalar']] : [];

        $result = apiCall($apiUrl, $apiKey, $payload, $files);
        if ($result['success'] ?? false) {
            header('Location: destek-detay.php?id=' . $ticketId . '&ok=2');
            exit;
        } else {
            $hata = $result['message'] ?? 'Yanıt gönderilemedi.';
            $hataObj = $result['errors'] ?? [];
        }
    }
}

if (isset($_GET['ok'])) {
    $basarili = ($_GET['ok'] == '1') ? 'Talep başarıyla oluşturuldu.' : 'Yanıtınız gönderildi.';
}

// ─── Ticket Detay ───
if ($ticketId > 0 && !$yeniMi) {
    $payload = ['action' => 'ticket_detail', 'ticket_id' => $ticketId];
    if ($eposta) $payload['eposta'] = $eposta;
    $detayResult = apiCall($apiUrl, $apiKey, $payload);

    if ($detayResult['success'] ?? false) {
        $ticket   = $detayResult['data'] ?? [];
        $mesajlar = $ticket['mesajlar'] ?? [];
    } else {
        $hata = $detayResult['message'] ?? 'Ticket bulunamadı.';
        $hataObj = $detayResult['errors'] ?? [];
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $yeniMi ? 'Yeni Talep' : ('Talep #' . $ticketId) ?> - {{KAYNAK_AD}}</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body { background: #f0f2f5; font-family: 'Segoe UI', sans-serif; }
        .destek-header { background: linear-gradient(135deg, #1e3a5f 0%, #2d5a8e 100%); color: #fff; padding: 1.5rem 0; }
        .ticket-info-table th { width: 140px; background: #f8f9fa; }
        /* ─── Chat Balonları ─── */
        /* mesaj_kaynak='api'  → Kullanıcının gönderdiği (sağ, mavi) */
        /* mesaj_kaynak='panel'→ Admin/panel yanıtı (sol, yeşil)     */
        .mesaj-satir { display: flex; margin-bottom: .75rem; }
        .mesaj-satir.giden { justify-content: flex-end; }   /* Sağ hizalı: kullanıcı mesajı */
        .mesaj-satir.gelen { justify-content: flex-start; } /* Sol hizalı: panel/admin mesajı */
        .mesaj-balon { max-width: 75%; padding: .75rem 1rem; border-radius: 12px; position: relative; }
        .mesaj-satir.giden .mesaj-balon {
            background: #d1e7ff; border: 1px solid #b6d4fe; border-bottom-right-radius: 2px;
        }
        .mesaj-satir.gelen .mesaj-balon {
            background: #d1f5e0; border: 1px solid #a3e4bc; border-bottom-left-radius: 2px;
        }
        .mesaj-meta { font-size: .75rem; color: #6c757d; margin-top: .35rem; }
    </style>
</head>
<body>

<div class="destek-header mb-4">
    <div class="container">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h4 class="mb-0">
                    <i class="bi bi-<?= $yeniMi ? 'plus-circle' : 'ticket-detailed' ?> me-2"></i>
                    <?= $yeniMi ? 'Yeni Destek Talebi' : 'Talep Detayı' ?>
                </h4>
            </div>
            <a href="destek.php" class="btn btn-outline-light btn-sm">
                <i class="bi bi-arrow-left me-1"></i> Taleplere Dön
            </a>
        </div>
    </div>
</div>

<div class="container mb-5">

    <?php if ($hata): ?>
        <div class="alert alert-danger">
            <i class="bi bi-exclamation-triangle me-1"></i><?= htmlspecialchars($hata) ?>
            <?php if (!empty($hataObj) && is_array($hataObj)): ?>
                <ul class="mb-0 mt-2">
                    <?php foreach ($hataObj as $err): ?>
                        <li><?= htmlspecialchars($err) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <?php if ($basarili): ?>
        <div class="alert alert-success"><i class="bi bi-check-circle me-1"></i><?= htmlspecialchars($basarili) ?></div>
    <?php endif; ?>

    <?php if ($yeniMi): ?>
    <!-- ═══ YENİ TALEP FORMU ═══ -->
    <div class="card">
        <div class="card-header"><h5 class="mb-0"><i class="bi bi-pencil-square me-1"></i> Yeni Talep Oluştur</h5></div>
        <div class="card-body">
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="post_action" value="create_ticket">

                <div class="mb-3">
                    <label class="form-label">Konu <span class="text-danger">*</span></label>
                    <input type="text" name="konu" class="form-control" required maxlength="300" value="<?= htmlspecialchars($_POST['konu'] ?? '') ?>">
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label">Kategori <span class="text-danger">*</span></label>
                        <select name="kategori_id" class="form-select" required>
                            <option value="">Seçiniz...</option>
                            <?php foreach ($kategoriler as $k): ?>
                                <option value="<?= (int)$k['id'] ?>" <?= (($_POST['kategori_id'] ?? '') == $k['id']) ? 'selected' : '' ?>><?= htmlspecialchars($k['ad']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Öncelik <span class="text-danger">*</span></label>
                        <select name="oncelik_id" class="form-select" required>
                            <option value="">Seçiniz...</option>
                            <?php foreach ($oncelikler as $o): ?>
                                <option value="<?= (int)$o['id'] ?>" <?= (($_POST['oncelik_id'] ?? '') == $o['id']) ? 'selected' : '' ?>><?= htmlspecialchars($o['ad']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Açıklama <span class="text-danger">*</span></label>
                    <textarea name="mesaj" class="form-control" rows="5" required><?= htmlspecialchars($_POST['mesaj'] ?? '') ?></textarea>
                </div>

                <div class="mb-3">
                    <label class="form-label">Dosya Ekle</label>
                    <input type="file" class="form-control" name="dosyalar[]" multiple accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx,.xls,.xlsx,.txt">
                    <div class="form-text">Maks. 10MB per dosya. Birden fazla dosya seçebilirsiniz.</div>
                </div>

                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-success"><i class="bi bi-send me-1"></i> Gönder</button>
                    <a href="destek.php" class="btn btn-secondary">Vazgeç</a>
                </div>
            </form>
        </div>
    </div>

    <?php elseif ($ticket): ?>
    <!-- ═══ TİCKET DETAY ═══ -->
    <div class="card mb-3">
        <div class="card-header">
            <h5 class="mb-0">
                <span class="text-muted">#<?= htmlspecialchars($ticket['Tickets_no'] ?? '') ?></span>
                <?= htmlspecialchars($ticket['Tickets_konu'] ?? '') ?>
            </h5>
        </div>
        <div class="card-body p-0">
            <table class="table table-bordered mb-0 ticket-info-table">
                <!-- durum_ad + durum_renk: Ticket durumu -->
                <tr><th>Durum</th><td><span class="badge" style="background:<?= htmlspecialchars($ticket['durum_renk'] ?? '#6c757d') ?>"><?= htmlspecialchars($ticket['durum_ad'] ?? '-') ?></span></td></tr>
                <!-- oncelik_ad: Ticket önceliği -->
                <tr><th>Öncelik</th><td><?= htmlspecialchars($ticket['oncelik_ad'] ?? '-') ?></td></tr>
                <!-- kategori_ad: Ticket kategorisi -->
                <tr><th>Kategori</th><td><?= htmlspecialchars($ticket['kategori_ad'] ?? '-') ?></td></tr>
                <!-- atanan_ad + atanan_soyad: Talebe atanan destek personeli -->
                <?php $atanan = trim(($ticket['atanan_ad'] ?? '') . ' ' . ($ticket['atanan_soyad'] ?? '')); ?>
                <tr><th>Atanan</th><td><?= $atanan ? htmlspecialchars($atanan) : '<span class="text-muted">Henüz atanmadı</span>' ?></td></tr>
                <!-- acilis_tarihi: Ticket açılış tarihi -->
                <tr><th>Açılış Tarihi</th><td><?= htmlspecialchars($ticket['acilis_tarihi'] ?? '-') ?></td></tr>
                <!-- son_yanit_tarihi: Son yanıt tarihi -->
                <tr><th>Son Yanıt</th><td><?= htmlspecialchars($ticket['son_yanit_tarihi'] ?? '-') ?></td></tr>
            </table>
        </div>
    </div>

    <!--
    ═══════════════════════════════════════════════════════════════
    MESAJ GÖRÜNÜM KURALLARI:
    ─────────────────────────────────────────────────────────────
    mesaj_kaynak = 'api'   → Kullanıcının (bu siteden) gönderdiği mesaj
                             → SAĞ tarafta, MAVİ balon
    mesaj_kaynak = 'panel' → Destek ekibinin/admin panelden gönderdiği yanıt
                             → SOL tarafta, YEŞİL balon
    ─────────────────────────────────────────────────────────────
    İç notlar (ic_not=1) API'den dönmez, sadece admin panelde görünür.
    ═══════════════════════════════════════════════════════════════
    -->
    <h5 class="mb-3"><i class="bi bi-chat-left-text me-1"></i> Mesajlar (<?= count($mesajlar) ?>)</h5>

    <?php if (empty($mesajlar)): ?>
        <div class="alert alert-secondary">Henüz mesaj yok.</div>
    <?php else: ?>
        <?php foreach ($mesajlar as $m):
            // mesaj_kaynak='api' → kullanıcı (giden, sağ), diğer → admin (gelen, sol)
            $giden = (($m['mesaj_kaynak'] ?? '') === 'api');
        ?>
        <!-- <?= $giden ? 'Giden (Kullanıcı → Destek)' : 'Gelen (Destek → Kullanıcı)' ?> -->
        <div class="mesaj-satir <?= $giden ? 'giden' : 'gelen' ?>">
            <div class="mesaj-balon">
                <div class="mb-1">
                    <strong class="small">
                        <?= htmlspecialchars(($m['yazan_ad'] ?? '') . ' ' . ($m['yazan_soyad'] ?? '')) ?>
                    </strong>
                </div>
                <div><?= nl2br(htmlspecialchars($m['mesaj'] ?? '')) ?></div>
                <?php if (!empty($m['ekler'])): ?>
                    <div class="mt-2">
                        <?php foreach ($m['ekler'] as $ek): ?>
                            <a href="<?= htmlspecialchars($ek['dosya_url'] ?? '#') ?>" target="_blank" class="badge bg-light text-dark border me-1">
                                <i class="bi bi-paperclip"></i> <?= htmlspecialchars($ek['dosya_adi'] ?? 'Dosya') ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <div class="mesaj-meta text-<?= $giden ? 'end' : 'start' ?>">
                    <i class="bi bi-clock me-1"></i><?= htmlspecialchars($m['tarih'] ?? '') ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <!-- Yanıt Formu -->
    <?php if (empty($ticket['Ticket_Durumlar_kapatma_durumu'])): ?>
    <div class="card mt-3">
        <div class="card-header"><h6 class="mb-0"><i class="bi bi-reply me-1"></i> Yanıt Yaz</h6></div>
        <div class="card-body">
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="post_action" value="reply_ticket">
                <div class="mb-3">
                    <textarea name="mesaj" class="form-control" rows="4" required placeholder="Yanıtınızı yazın..."></textarea>
                </div>
                <div class="mb-3">
                    <label class="form-label">Dosya Ekle</label>
                    <input type="file" class="form-control" name="dosyalar[]" multiple accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx,.xls,.xlsx,.txt">
                </div>
                <button type="submit" class="btn btn-success"><i class="bi bi-send me-1"></i> Yanıt Gönder</button>
            </form>
        </div>
    </div>
    <?php else: ?>
        <div class="alert alert-warning mt-3"><i class="bi bi-lock me-1"></i> Bu talep kapatılmıştır. Yanıt gönderemezsiniz.</div>
    <?php endif; ?>

    <?php else: ?>
        <div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-1"></i> Gösterilecek içerik bulunamadı.</div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
