<?php
/**
 * Ticket API dokumantasyon endpointi
 * action=full|meta|changelog
 */

require_once __DIR__ . '/../admin/db.php';
require_once __DIR__ . '/../admin/includes/TicketService.php';

header('Content-Type: application/json; charset=utf-8');

function docsResponse(array $data, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function docsApiKey(): string
{
    $apiKey = trim((string)($_SERVER['HTTP_X_API_KEY'] ?? ''));
    if ($apiKey !== '') {
        return $apiKey;
    }

    $authHeader = trim((string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
    if ($authHeader !== '' && preg_match('/Bearer\s+(.+)/i', $authHeader, $matches)) {
        return trim($matches[1]);
    }

    return '';
}

function appBaseUrl(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') === '443');
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host;
}

function buildActions(string $apiEndpoint): array
{
    return [
        [
            'action' => 'create_ticket',
            'method' => 'POST',
            'endpoint' => $apiEndpoint,
            'zorunlu_alanlar' => ['action', 'kullanici.kaynak_kullanici_id', 'konu', 'mesaj', 'kategori_id', 'oncelik_id'],
            'opsiyonel_alanlar' => ['kullanici.ad', 'kullanici.soyad', 'kullanici.eposta', 'kullanici.telefon', 'dosyalar[]', 'ekler[] (JSON/base64)'],
            'icerik_tipi' => ['application/json', 'multipart/form-data'],
            'aciklama' => 'Yeni ticket acilir. Ilk icerik Ticket_Mesajlar tablosuna mesaj olarak kaydedilir. multipart/form-data icin dosyalar[], JSON icin ekler[] (base64) desteklenir. Basarili response data icinde ek_sayisi ve ekler doner.'
        ],
        [
            'action' => 'reply_ticket',
            'method' => 'POST',
            'endpoint' => $apiEndpoint,
            'zorunlu_alanlar' => ['action', 'ticket_id', 'kullanici.kaynak_kullanici_id', 'mesaj'],
            'opsiyonel_alanlar' => ['dosyalar[]', 'ekler[] (JSON/base64)'],
            'icerik_tipi' => ['application/json', 'multipart/form-data'],
            'aciklama' => 'Mevcut ticketa cevap yazilir. multipart/form-data icin dosyalar[], JSON icin ekler[] (base64) desteklenir. Basarili response data icinde ek_sayisi ve ekler doner.'
        ],
        [
            'action' => 'ticket_detail',
            'method' => 'POST',
            'endpoint' => $apiEndpoint,
            'zorunlu_alanlar' => ['action', 'ticket_id', 'kullanici.kaynak_kullanici_id'],
            'opsiyonel_alanlar' => [],
            'icerik_tipi' => ['application/json'],
            'aciklama' => 'Ticket detayini dondurur.'
        ],
        [
            'action' => 'ticket_messages',
            'method' => 'POST',
            'endpoint' => $apiEndpoint,
            'zorunlu_alanlar' => ['action', 'ticket_id', 'kullanici.kaynak_kullanici_id'],
            'opsiyonel_alanlar' => [],
            'icerik_tipi' => ['application/json'],
            'aciklama' => 'Ticket mesajlarini listeler. Her mesaj kaydinda ekler alani doner.'
        ],
        [
            'action' => 'ticket_meta',
            'method' => 'POST',
            'endpoint' => $apiEndpoint,
            'zorunlu_alanlar' => ['action'],
            'opsiyonel_alanlar' => [],
            'icerik_tipi' => ['application/json'],
            'aciklama' => 'Aktif kategori, oncelik ve durum listelerini dondurur. create_ticket oncesi kullanilmasi onerilir.'
        ],
    ];
}

function buildSamples(): array
{
    return [
        'json_create_ticket' => [
            'action' => 'create_ticket',
            'kullanici' => [
                'kaynak_kullanici_id' => '245',
                'ad' => 'Batuhan',
                'soyad' => 'Kahraman',
                'eposta' => 'ornek@site.com',
                'telefon' => '5xxxxxxxxx'
            ],
            'konu' => 'API test ticket',
            'mesaj' => 'Harici siteden test kaydi',
            'kategori_id' => 1,
            'oncelik_id' => 2,
            'ekler' => [
                [
                    'dosya_adi' => 'ornek-log.txt',
                    'mime_turu' => 'text/plain',
                    'icerik_base64' => 'U2VsaGFtLCBidSBiaXIgZGVuZW1lIGRvc3lhc2lkaXIu'
                ]
            ]
        ],
        'multipart_create_ticket' => [
            'action' => 'create_ticket',
            'kullanici' => '{"kaynak_kullanici_id":"245","ad":"Batuhan","soyad":"Kahraman","eposta":"ornek@site.com","telefon":"5xxxxxxxxx"}',
            'konu' => 'Dosyali API ticket',
            'mesaj' => 'Bu ticket dosya eki ile aciliyor',
            'kategori_id' => '1',
            'oncelik_id' => '2',
            'dosyalar[]' => ['@C:/temp/ekran-goruntusu.png', '@C:/temp/log.txt']
        ],
        'multipart_reply_ticket' => [
            'action' => 'reply_ticket',
            'ticket_id' => '2',
            'kullanici' => '{"kaynak_kullanici_id":"245","ad":"Batuhan","soyad":"Kahraman","eposta":"ornek@site.com","telefon":"5xxxxxxxxx"}',
            'mesaj' => 'Harici siteden dosyali yanit testi',
            'dosyalar[]' => ['@C:/temp/cevap-ekran.png', '@C:/temp/teknik-log.txt']
        ],
        'json_reply_ticket' => [
            'action' => 'reply_ticket',
            'ticket_id' => 2,
            'kullanici' => [
                'kaynak_kullanici_id' => '245',
                'ad' => 'Batuhan',
                'soyad' => 'Kahraman',
                'eposta' => 'ornek@site.com',
                'telefon' => '5xxxxxxxxx'
            ],
            'mesaj' => 'JSON ile dosyali yanit testi',
            'ekler' => [
                [
                    'dosya_adi' => 'cevap-log.txt',
                    'mime_turu' => 'text/plain',
                    'icerik_base64' => 'QVBJIHlhbml0IGxvZyBkb3N5YXNp'
                ]
            ]
        ],
        'response_create_ticket' => [
            'success' => true,
            'message' => 'Ticket oluşturuldu.',
            'data' => [
                'ticket_id' => 2,
                'ticket_no' => 'TKT-20260429-0002',
                'ek_sayisi' => 1,
                'ekler' => [
                    [
                        'dosya_adi' => 'ekran-goruntusu.png',
                        'dosya_yolu' => 'assets/uploads/tickets/2/ab12cd34ef56.png',
                        'dosya_url' => 'https://destek.ornekproje.com/assets/uploads/tickets/2/ab12cd34ef56.png',
                        'dosya_boyutu' => 307200,
                        'mime_turu' => 'image/png'
                    ]
                ]
            ]
        ],
        'response_reply_ticket' => [
            'success' => true,
            'message' => 'Mesaj gönderildi.',
            'data' => [
                'mesaj_id' => 101,
                'tarih' => '2026-04-29 14:30:00',
                'ek_sayisi' => 1,
                'ekler' => [
                    [
                        'dosya_adi' => 'teknik-log.txt',
                        'dosya_yolu' => 'assets/uploads/tickets/2/ff45aa88bc10.txt',
                        'dosya_url' => 'https://destek.ornekproje.com/assets/uploads/tickets/2/ff45aa88bc10.txt',
                        'dosya_boyutu' => 2048,
                        'mime_turu' => 'text/plain'
                    ]
                ]
            ]
        ],
        'json_ticket_messages' => [
            'action' => 'ticket_messages',
            'ticket_id' => 2,
            'kullanici' => [
                'kaynak_kullanici_id' => '245',
                'ad' => 'Batuhan',
                'soyad' => 'Kahraman',
                'eposta' => 'ornek@site.com',
                'telefon' => '5xxxxxxxxx'
            ]
        ],
        'response_ticket_messages' => [
            'success' => true,
            'data' => [
                [
                    'Ticket_Mesajlar_id' => 101,
                    'Ticket_Mesajlar_mesaj' => 'Merhaba, ilk mesaj',
                    'Ticket_Mesajlar_ic_not' => 0,
                    'Ticket_Mesajlar_yazan_kul_id' => 25,
                    'tarih' => '2026-04-29 14:00',
                    'kullanici_ad' => 'Batuhan',
                    'kullanici_soyad' => 'Kahraman',
                    'ekler' => [
                        [
                            'id' => 77,
                            'dosya_adi' => 'log.txt',
                            'dosya_yolu' => 'assets/uploads/tickets/2/ab12cd34ef56.txt',
                            'dosya_url' => 'https://destek.ornekproje.com/assets/uploads/tickets/2/ab12cd34ef56.txt',
                            'dosya_boyutu' => 2048,
                            'mime_turu' => 'text/plain'
                        ]
                    ]
                ]
            ]
        ],
        'ticket_meta' => [
            'action' => 'ticket_meta'
        ]
    ];
}

function buildErrorCodes(): array
{
    return [
        ['http_code' => 401, 'mesaj' => 'API anahtarı bulunamadı.'],
        ['http_code' => 401, 'mesaj' => 'Geçersiz API anahtarı.'],
        ['http_code' => 400, 'mesaj' => 'Gönderilen verilerde eksik veya hatalı alanlar var.', 'ornek_errors' => ['action' => 'action alanı zorunludur.']],
        ['http_code' => 400, 'mesaj' => 'Gönderilen verilerde eksik veya hatalı alanlar var.', 'ornek_errors' => ['kullanici.eposta' => "'kullanici' nesnesi içerisinde 'eposta' alanı zorunludur."]],
        ['http_code' => 400, 'mesaj' => 'Gönderilen verilerde eksik veya hatalı alanlar var.', 'ornek_errors' => ['ticket_id' => "Root seviyesinde 'ticket_id' alanı zorunludur."]],
        ['http_code' => 404, 'mesaj' => 'Ticket bulunamadı.']
    ];
}

function normalizeSqlDate($value): ?string
{
    if ($value instanceof DateTimeInterface) {
        return $value->format(DateTimeInterface::ATOM);
    }

    if (is_string($value) && trim($value) !== '') {
        $timestamp = strtotime($value);
        if ($timestamp !== false) {
            return date(DateTimeInterface::ATOM, $timestamp);
        }
    }

    return null;
}

function fetchMeta(Database $db): array
{
    $row = $db->fetchOne(
        "SELECT TOP 1 surum_versiyon, surum_tarih, surum_tip, surum_baslik
         FROM Sistem_Surum_Notlari
         WHERE surum_durum = 1
         ORDER BY surum_id DESC"
    );

    return [
        'api_version' => $row['surum_versiyon'] ?? '1.0.0',
        'published_at' => normalizeSqlDate($row['surum_tarih'] ?? null),
        'release_type' => $row['surum_tip'] ?? 'FEATURE',
        'release_title' => $row['surum_baslik'] ?? 'Ticket API güncellemesi',
    ];
}

function fetchChangeLog(Database $db, int $limit = 20): array
{
    $limit = max(1, min(100, $limit));

    $rows = $db->fetchAll(
        "SELECT TOP {$limit}
            surum_versiyon,
            surum_tarih,
            surum_tip,
            surum_kategori,
            surum_modul,
            surum_baslik,
            surum_aciklama,
            surum_dosyalar
         FROM Sistem_Surum_Notlari
         WHERE surum_durum = 1
         ORDER BY surum_id DESC"
    );

    $changes = [];
    foreach ($rows as $row) {
        $dosyalar = [];
        if (!empty($row['surum_dosyalar'])) {
            $parsed = json_decode((string)$row['surum_dosyalar'], true);
            if (is_array($parsed)) {
                $dosyalar = $parsed;
            }
        }

        $changes[] = [
            'versiyon' => $row['surum_versiyon'] ?? null,
            'tarih' => normalizeSqlDate($row['surum_tarih'] ?? null),
            'tip' => $row['surum_tip'] ?? null,
            'kategori' => $row['surum_kategori'] ?? null,
            'modul' => $row['surum_modul'] ?? null,
            'baslik' => $row['surum_baslik'] ?? null,
            'aciklama' => $row['surum_aciklama'] ?? null,
            'dosyalar' => $dosyalar,
        ];
    }

    return $changes;
}

try {
    $apiKey = docsApiKey();
    if ($apiKey === '') {
        docsResponse(['success' => false, 'message' => 'API anahtarı bulunamadı.'], 401);
    }

    $db = Database::getInstance();
    $service = new TicketService($db);
    $kaynak = $service->getKaynakByApiKey($apiKey);

    if (!$kaynak) {
        docsResponse(['success' => false, 'message' => 'Geçersiz API anahtarı.'], 401);
    }

    $baseUrl = appBaseUrl();
    $apiEndpoint = $baseUrl . '/api/tickets.php';
    $docsEndpoint = $baseUrl . '/api/ticket-docs.php';

    $meta = fetchMeta($db);
    $actions = buildActions($apiEndpoint);
    $samples = buildSamples();
    $errors = buildErrorCodes();
    $docsHash = md5(json_encode([$meta, $actions, $samples, $errors], JSON_UNESCAPED_UNICODE));

    $action = strtolower(trim((string)($_GET['action'] ?? 'full')));
    $limit = (int)($_GET['limit'] ?? 20);

    if ($action === 'meta') {
        docsResponse([
            'success' => true,
            'data' => [
                'meta' => $meta,
                'docs_hash' => $docsHash,
                'docs_endpoint' => $docsEndpoint,
                'changelog_endpoint' => $docsEndpoint . '?action=changelog',
            ]
        ]);
    }

    if ($action === 'changelog') {
        docsResponse([
            'success' => true,
            'data' => [
                'meta' => $meta,
                'count' => $limit,
                'changes' => fetchChangeLog($db, $limit),
            ]
        ]);
    }

    docsResponse([
        'success' => true,
        'data' => [
            'meta' => $meta,
            'docs_hash' => $docsHash,
            'auth' => [
                'header' => 'X-API-KEY',
                'bearer' => 'Authorization: Bearer <API_KEY>',
            ],
            'endpoints' => [
                'ticket_api' => $apiEndpoint,
                'docs_meta' => $docsEndpoint . '?action=meta',
                'docs_changelog' => $docsEndpoint . '?action=changelog',
                'docs_full' => $docsEndpoint . '?action=full',
            ],
            'actions' => $actions,
            'samples' => $samples,
            'error_codes' => $errors,
            'changelog' => fetchChangeLog($db, $limit),
            'kaynak' => [
                'id' => (int)$kaynak['Ticket_Kaynak_id'],
                'ad' => $kaynak['Ticket_Kaynak_ad'] ?? null,
                'kod' => $kaynak['Ticket_Kaynak_kod'] ?? null,
                'tip' => $kaynak['Ticket_Kaynak_tip'] ?? null,
            ]
        ]
    ]);
} catch (Exception $e) {
    docsResponse([
        'success' => false,
        'message' => $e->getMessage(),
    ], 400);
}
