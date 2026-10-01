<?php
/**
 * Destek Bildirim Proxy Endpoint
 * Kaynak: {{KAYNAK_AD}}
 * Bu dosya otomatik oluşturulmuştur.
 *
 * ═══════════════════════════════════════════════════════════════
 * AMAÇ:
 * ─────────────────────────────────────────────────────────────
 * Bu dosya, destek-widget.php tarafından AJAX ile çağrılır.
 * API key'i client-side'da açığa çıkarmamak için server-side
 * proxy olarak çalışır.
 *
 * list_tickets API çağrısı yaparak, son kontrol zamanından
 * sonra gelen yeni yanıtları filtreler ve sayısını döndürür.
 * ═══════════════════════════════════════════════════════════════
 *
 * ÇAĞRI ŞEKLİ:
 *   GET destek-bildirim.php?son_kontrol=2026-05-07T12:00:00
 *
 * DÖNDÜRDÜĞÜ JSON:
 *   {
 *     "success": true,
 *     "yeni_sayisi": 2,        ← Badge'de gösterilecek sayı
 *     "toplam": 5,             ← Toplam ticket sayısı
 *     "ticketler": [           ← Sadece yeni yanıtı olan ticket'lar
 *       { "id": 16, "no": "TKT-...", "konu": "...", "son_yanit": "..." }
 *     ]
 *   }
 *
 * API KEY GÜVENLİĞİ:
 *   API key bu dosyada server-side tutulur.
 *   Client-side JavaScript'te asla görünmez.
 * ═══════════════════════════════════════════════════════════════
 */
session_start();
header('Content-Type: application/json; charset=utf-8');

// ═══════════════════════════════════════════════════════════
// YAPILANDIRMA
// ═══════════════════════════════════════════════════════════
$apiKey = '{{API_KEY}}';
$apiUrl = '{{API_ENDPOINT}}';

{{SESSION_BLOCK}}

// ─── Oturum kontrolü ───
// Login olmamış kullanıcılar bu endpoint'e erişemez
if (!$eposta) {
    echo json_encode(['success' => false, 'message' => 'Oturum bulunamadı.']);
    exit;
}

// ═══════════════════════════════════════════════════════════
// API ÇAĞRISI
// ═══════════════════════════════════════════════════════════
function apiCall($url, $apiKey, $payload) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'X-API-KEY: ' . $apiKey],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $res  = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

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

// ─── list_tickets API çağrısı ───
$result = apiCall($apiUrl, $apiKey, ['action' => 'list_tickets', 'eposta' => $eposta]);

if (!($result['success'] ?? false)) {
    echo json_encode(['success' => false, 'message' => $result['message'] ?? 'API hatası.']);
    exit;
}

$tickets = $result['data'] ?? [];
$toplam  = count($tickets);

// ═══════════════════════════════════════════════════════════
// YENİ YANIT FİLTRESİ
// ═══════════════════════════════════════════════════════════
// son_kontrol: Kullanıcının destek sayfasını en son ziyaret ettiği zaman
// Bu değer client-side'da localStorage'da tutulur ve AJAX ile gönderilir
// son_yanit_tarihi > son_kontrol → yeni yanıt var demektir
$sonKontrol     = trim($_GET['son_kontrol'] ?? '');
$sonKontrolTime = $sonKontrol !== '' ? strtotime($sonKontrol) : 0;

$yeniSayisi  = 0;
$yeniTicketler = [];

foreach ($tickets as $t) {
    $sonYanitStr = $t['son_yanit_tarihi'] ?? null;
    if (!$sonYanitStr) continue;

    $sonYanitTime = strtotime($sonYanitStr);
    if ($sonKontrolTime > 0 && $sonYanitTime > $sonKontrolTime) {
        $yeniSayisi++;
        $yeniTicketler[] = [
            'id'        => (int)($t['Tickets_id'] ?? 0),
            'no'        => $t['Tickets_no'] ?? '',
            'konu'      => $t['Tickets_konu'] ?? '',
            'son_yanit' => $sonYanitStr,
        ];
    }
}

// ═══════════════════════════════════════════════════════════
// YANIT
// ═══════════════════════════════════════════════════════════
echo json_encode([
    'success'     => true,
    'yeni_sayisi' => $yeniSayisi,   // Badge'de gösterilecek sayı
    'toplam'      => $toplam,       // Toplam ticket sayısı
    'ticketler'   => $yeniTicketler, // Yeni yanıtı olan ticket'lar
], JSON_UNESCAPED_UNICODE);
