<?php
/**
 * Destek Talepleri Listesi
 * Kaynak: {{KAYNAK_AD}}
 * Bu dosya otomatik oluşturulmuştur.
 *
 * ═══════════════════════════════════════════════════════════════
 * API RESPONSE KEY REFERANSLARI
 * ═══════════════════════════════════════════════════════════════
 *
 * list_tickets → data[] her ticket objesi:
 *   Tickets_id          → Ticket ID (detay sayfasına yönlendirmede kullanılır)
 *   Tickets_no          → Ticket numarası (ör: TKT-0001)
 *   Tickets_konu        → Ticket konusu
 *   durum_ad            → Durum adı (ör: Açık, Kapalı)
 *   durum_renk          → Durum badge renk kodu (ör: #28a745)
 *   oncelik_ad          → Öncelik adı (ör: Acil, Normal)
 *   oncelik_renk        → Öncelik badge renk kodu
 *   acilis_tarihi       → Açılış tarihi (YYYY-MM-DD HH:MM)
 *   son_yanit_tarihi    → Son yanıt tarihi (null olabilir)
 *   mesaj_sayisi        → Toplam mesaj sayısı (int)
 *   atanan_ad           → Atanan kişi adı (null olabilir = henüz atanmadı)
 *   atanan_soyad        → Atanan kişi soyadı
 * ═══════════════════════════════════════════════════════════════
 */
session_start();

// ═══════════════════════════════════════════════════════════
// YAPILANDIRMA
// ═══════════════════════════════════════════════════════════
$apiKey = '{{API_KEY}}';
$apiUrl = '{{API_ENDPOINT}}';

{{SESSION_BLOCK}}

// ═══════════════════════════════════════════════════════════
// API
// ═══════════════════════════════════════════════════════════
// apiCall: API'ye POST isteği gönderir ve JSON yanıt döndürür.
// Hata durumlarında detaylı bilgi döndürür:
//   - cURL hatası  → curl_error() ile detay (DNS, SSL, timeout vb.)
//   - HTTP hatası  → HTTP durum kodu (403, 404, 500 vb.)
//   - JSON hatası  → Ham yanıtın ilk 200 karakteri (debug için)
// ═══════════════════════════════════════════════════════════
function apiCall($url, $apiKey, $payload) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'X-API-KEY: ' . $apiKey],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
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

$payload = ['action' => 'list_tickets'];
if ($eposta) $payload['eposta'] = $eposta;
$result = apiCall($apiUrl, $apiKey, $payload);
$tickets = ($result['success'] ?? false) ? ($result['data'] ?? []) : [];
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Destek Talepleri - {{KAYNAK_AD}}</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body { background: #f0f2f5; font-family: 'Segoe UI', sans-serif; }
        .destek-header { background: linear-gradient(135deg, #1e3a5f 0%, #2d5a8e 100%); color: #fff; padding: 2rem 0; }
        .ticket-card { transition: all .2s; cursor: pointer; border-left: 4px solid transparent; }
        .ticket-card:hover { box-shadow: 0 4px 15px rgba(0,0,0,.1); border-left-color: #2d5a8e; transform: translateY(-1px); }
    </style>
</head>
<body>

<div class="destek-header mb-4">
    <div class="container">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h3 class="mb-1"><i class="bi bi-headset me-2"></i>Destek Talepleri</h3>
                <p class="mb-0 opacity-75"><?= htmlspecialchars($eposta ?? 'Sabit Kullanıcı') ?></p>
            </div>
            <a href="destek-detay.php?yeni=1" class="btn btn-light">
                <i class="bi bi-plus-circle me-1"></i> Yeni Talep Oluştur
            </a>
        </div>
    </div>
</div>

<div class="container mb-5">
    <?php if (!($result['success'] ?? false) && !empty($result['message'])): ?>
        <div class="alert alert-danger">
            <i class="bi bi-exclamation-triangle me-1"></i><?= htmlspecialchars($result['message']) ?>
            <?php if (!empty($result['errors']) && is_array($result['errors'])): ?>
                <ul class="mb-0 mt-2">
                    <?php foreach ($result['errors'] as $err): ?>
                        <li><?= htmlspecialchars($err) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    <?php elseif (empty($tickets)): ?>
        <div class="alert alert-info"><i class="bi bi-info-circle me-1"></i> Henüz destek talebiniz bulunmamaktadır.</div>
    <?php else: ?>
        <div class="row g-3">
            <?php foreach ($tickets as $t): ?>
            <div class="col-12">
                <div class="card ticket-card" onclick="window.location='destek-detay.php?id=<?= (int)$t['Tickets_id'] ?>'">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <h6 class="mb-1">
                                    <span class="text-muted fw-normal">#<?= htmlspecialchars($t['Tickets_no'] ?? '') ?></span>
                                    <?= htmlspecialchars($t['Tickets_konu'] ?? '') ?>
                                </h6>
                                <div class="text-muted small">
                                    <i class="bi bi-calendar3 me-1"></i><?= htmlspecialchars($t['acilis_tarihi'] ?? '-') ?>
                                    <span class="mx-1">·</span>
                                    <i class="bi bi-chat-dots me-1"></i><?= (int)($t['mesaj_sayisi'] ?? 0) ?> mesaj
                                    <?php if (!empty($t['son_yanit_tarihi'])): ?>
                                    <span class="mx-1">·</span>
                                    <i class="bi bi-reply me-1"></i>Son yanıt: <?= htmlspecialchars($t['son_yanit_tarihi']) ?>
                                    <?php endif; ?>
                                    <?php
                                    // atanan_ad + atanan_soyad: Ticket'a atanan destek personeli
                                    $atanan = trim(($t['atanan_ad'] ?? '') . ' ' . ($t['atanan_soyad'] ?? ''));
                                    ?>
                                    <?php if ($atanan): ?>
                                    <span class="mx-1">·</span>
                                    <i class="bi bi-person-check me-1"></i><?= htmlspecialchars($atanan) ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="text-end ms-3">
                                <span class="badge" style="background:<?= htmlspecialchars($t['durum_renk'] ?? '#6c757d') ?>"><?= htmlspecialchars($t['durum_ad'] ?? '-') ?></span>
                                <br>
                                <span class="badge mt-1" style="background:<?= htmlspecialchars($t['oncelik_renk'] ?? '#6c757d') ?>;font-size:.7rem"><?= htmlspecialchars($t['oncelik_ad'] ?? '-') ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<!--
    destek_son_kontrol: Widget'ın badge sayacı için kullandığı localStorage key'i.
    Bu sayfaya her girişte güncellenir, böylece widget badge sıfırlanır.
    Widget'ın polling sistemi bu zamanı destek-bildirim.php'ye gönderir.
-->
<script>localStorage.setItem('destek_son_kontrol', new Date().toISOString());</script>
</body>
</html>
