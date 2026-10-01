<?php
/**
 * Harici ticket API
 * 
 * Endpointler:
 *   create_ticket  - Yeni ticket oluşturur (kullanici{} nesnesi zorunlu)
 *   reply_ticket   - Ticket'a yanıt ekler (kaynak_kullanici_id yeterli)
 *   list_tickets   - Kullanıcının tüm ticketlarını listeler
 *   ticket_detail  - Ticket detayı + mesajları döner
 *   ticket_meta    - Kategori, öncelik, durum listelerini döner
 */

require_once __DIR__ . '/../admin/db.php';
require_once __DIR__ . '/../admin/includes/TicketService.php';
require_once __DIR__ . '/../admin/includes/Validator.php';

header('Content-Type: application/json; charset=utf-8');

function apiResponse(array $data, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function requestData(): array
{
    $data = [];

    if (!empty($_POST) && is_array($_POST)) {
        $data = $_POST;
    }

    $raw = file_get_contents('php://input');
    if (is_string($raw) && trim($raw) !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $data = array_merge($data, $decoded);
        }
    }

    // multipart/form-data kullanılırken kullanici alanı JSON string gelebilir.
    if (isset($data['kullanici']) && is_string($data['kullanici'])) {
        $kullaniciDecoded = json_decode($data['kullanici'], true);
        if (is_array($kullaniciDecoded)) {
            $data['kullanici'] = $kullaniciDecoded;
        }
    }

    return $data;
}

function requestFiles(string $fieldName = 'dosyalar'): array
{
    if (empty($_FILES[$fieldName])) {
        return [];
    }

    $fileInput = $_FILES[$fieldName];
    $normalized = [];

    if (is_array($fileInput['name'])) {
        $count = count($fileInput['name']);
        for ($i = 0; $i < $count; $i++) {
            $normalized[] = [
                'name' => $fileInput['name'][$i] ?? '',
                'type' => $fileInput['type'][$i] ?? '',
                'tmp_name' => $fileInput['tmp_name'][$i] ?? '',
                'error' => $fileInput['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                'size' => $fileInput['size'][$i] ?? 0,
            ];
        }
    } else {
        $normalized[] = [
            'name' => $fileInput['name'] ?? '',
            'type' => $fileInput['type'] ?? '',
            'tmp_name' => $fileInput['tmp_name'] ?? '',
            'error' => $fileInput['error'] ?? UPLOAD_ERR_NO_FILE,
            'size' => $fileInput['size'] ?? 0,
        ];
    }

    return $normalized;
}

function requestJsonEkler(array $payload, string $fieldName = 'ekler'): array
{
    if (!array_key_exists($fieldName, $payload)) {
        return [];
    }

    $ekler = $payload[$fieldName];
    if (is_string($ekler)) {
        $decoded = json_decode($ekler, true);
        if (is_array($decoded)) {
            $ekler = $decoded;
        }
    }

    if (!is_array($ekler)) {
        return [];
    }

    // Tek obje geldiyse diziye sar.
    if (isset($ekler['dosya_adi']) || isset($ekler['name'])) {
        $ekler = [$ekler];
    }

    $normalized = [];
    foreach ($ekler as $ek) {
        if (!is_array($ek)) {
            continue;
        }

        $normalized[] = [
            'dosya_adi' => (string)($ek['dosya_adi'] ?? ($ek['name'] ?? '')),
            'mime_turu' => (string)($ek['mime_turu'] ?? ($ek['type'] ?? '')),
            'icerik_base64' => (string)($ek['icerik_base64'] ?? ($ek['content_base64'] ?? '')),
        ];
    }

    return $normalized;
}

function requestApiKey(): string
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

try {
    $payload = requestData();
    $dosyalar = array_merge(
        requestFiles('dosyalar'),
        requestJsonEkler($payload, 'ekler')
    );
    $action = trim((string)($payload['action'] ?? $_GET['action'] ?? ''));
    $apiKey = requestApiKey();

    if ($action === '') {
        apiResponse([
            'success' => false, 
            'message' => 'Gönderilen verilerde eksik veya hatalı alanlar var.', 
            'errors' => ['action' => 'action alanı zorunludur.']
        ], 400);
    }

    if ($apiKey === '') {
        apiResponse(['success' => false, 'message' => 'API anahtarı bulunamadı.'], 401);
    }

    $service = new TicketService();
    $kaynak = $service->getKaynakByApiKey($apiKey);

    if (!$kaynak) {
        apiResponse(['success' => false, 'message' => 'Geçersiz API anahtarı.'], 401);
    }

    $kaynakId = (int)$kaynak['Ticket_Kaynak_id'];
    $gecerliActionlar = ['create_ticket', 'reply_ticket', 'list_tickets', 'ticket_detail', 'ticket_meta'];

    if (!in_array($action, $gecerliActionlar, true)) {
        throw new Exception('Geçersiz işlem.');
    }

    // ─── ticket_meta: Kimlik doğrulama yok ───
    if ($action === 'ticket_meta') {
        apiResponse([
            'success' => true,
            'data' => $service->ticketMetaBilgisiGetir(),
        ]);
    }

    // ─── create_ticket ───
    if ($action === 'create_ticket') {
        $kullanici = is_array($payload['kullanici'] ?? null) ? $payload['kullanici'] : [];
        $sabitKullaniciId = !empty($kaynak['Ticket_Kaynak_kullanici_id']) ? (int)$kaynak['Ticket_Kaynak_kullanici_id'] : null;

        $validator = new Validator($payload);
        $validator->required('konu', "Root seviyesinde 'konu' alanı zorunludur.");
        $validator->required('mesaj', "Root seviyesinde 'mesaj' alanı zorunludur.");
        $validator->required('kategori_id', "Root seviyesinde 'kategori_id' alanı zorunludur.");
        $validator->required('oncelik_id', "Root seviyesinde 'oncelik_id' alanı zorunludur.");

        if (!$sabitKullaniciId) {
            $validator->required('kullanici.ad', "'kullanici' nesnesi içerisinde 'ad' alanı zorunludur.");
            $validator->required('kullanici.eposta', "'kullanici' nesnesi içerisinde 'eposta' alanı zorunludur.");
        }

        if ($validator->hasErrors()) {
            apiResponse([
                'success' => false,
                'message' => 'Gönderilen verilerde eksik veya hatalı alanlar var.',
                'errors' => $validator->getErrors()
            ], 400);
        }

        if ($sabitKullaniciId) {
            // Kaynağa sabit kullanıcı atanmış
            $dahiliKullaniciId = $sabitKullaniciId;
            $sabitKul = Database::getInstance()->fetchOne(
                "SELECT kullanici_ad, kullanici_soyad FROM kullanicilar WHERE kullanici_id = ?",
                [$sabitKullaniciId]
            );
            $yazanAd = $sabitKul['kullanici_ad'] ?? '';
            $yazanSoyad = $sabitKul['kullanici_soyad'] ?? '';
        } else {
            // Sabit kullanıcı yok, kullanici{} nesnesi zorunlu
            $dahiliKullaniciId = $service->kullaniciBulVeyaOlustur($kullanici);
            $yazanAd = $kullanici['ad'] ?? '';
            $yazanSoyad = $kullanici['soyad'] ?? '';
        }

        $sonuc = $service->ticketOlustur([
            'konu' => $payload['konu'] ?? '',
            'mesaj' => $payload['mesaj'] ?? '',
            'kategori_id' => $payload['kategori_id'] ?? 0,
            'oncelik_id' => $payload['oncelik_id'] ?? 0,
            'dosyalar' => $dosyalar,
            'kaynak_id' => $kaynakId,
            'yazan_ad' => $yazanAd,
            'yazan_soyad' => $yazanSoyad,
            'mesaj_kaynak' => 'api',
            'olusturan_kullanici_id' => $dahiliKullaniciId,
            'islem_kullanici_id' => $dahiliKullaniciId,
        ]);

        $sonuc['ekler'] = is_array($sonuc['ekler'] ?? null) ? $sonuc['ekler'] : [];
        $sonuc['ek_sayisi'] = array_key_exists('ek_sayisi', $sonuc)
            ? (int)$sonuc['ek_sayisi']
            : count($sonuc['ekler']);

        apiResponse([
            'success' => true,
            'message' => 'Ticket oluşturuldu.',
            'data' => $sonuc,
        ]);
    }

    // ─── Diğer endpointler: eposta ile kullanıcı bul ───
    $sabitKullaniciId = !empty($kaynak['Ticket_Kaynak_kullanici_id']) ? (int)$kaynak['Ticket_Kaynak_kullanici_id'] : null;

    if ($sabitKullaniciId) {
        $dahiliKullaniciId = $sabitKullaniciId;
    } else {
        $validator = new Validator($payload);
        $validator->required('eposta', "Root seviyesinde 'eposta' alanı zorunludur.");
        
        if ($validator->hasErrors()) {
            apiResponse([
                'success' => false,
                'message' => 'Gönderilen verilerde eksik veya hatalı alanlar var.',
                'errors' => $validator->getErrors()
            ], 400);
        }

        $eposta = trim((string)($payload['eposta'] ?? ''));

        $dahiliKullaniciId = $service->kullaniciBulByEposta($eposta);
        if ($dahiliKullaniciId === null) {
            if ($action === 'list_tickets') {
                apiResponse(['success' => true, 'data' => []]);
            }
            apiResponse(['success' => false, 'message' => 'Kullanıcı bulunamadı. Önce create_ticket ile kayıt oluşturun.'], 404);
        }
    }

    // ─── list_tickets ───
    if ($action === 'list_tickets') {
        apiResponse([
            'success' => true,
            'data' => $service->ticketListele($kaynakId, $dahiliKullaniciId),
        ]);
    }

    // ─── ticket_detail & reply_ticket: ticket_id zorunlu ───
    $validator = new Validator($payload);
    $validator->required('ticket_id', "Root seviyesinde 'ticket_id' alanı zorunludur.");
    $validator->numeric('ticket_id', "'ticket_id' alanı sayısal olmalıdır.");
    $validator->min('ticket_id', 1, "'ticket_id' alanı 1 veya daha büyük olmalıdır.");
    if ($action === 'reply_ticket') {
        $validator->required('mesaj', "Root seviyesinde 'mesaj' alanı zorunludur.");
    }

    if ($validator->hasErrors()) {
        apiResponse([
            'success' => false,
            'message' => 'Gönderilen verilerde eksik veya hatalı alanlar var.',
            'errors' => $validator->getErrors()
        ], 400);
    }

    $ticketId = (int)($payload['ticket_id'] ?? 0);

    $ticket = $service->ticketDetayGetir($ticketId, $kaynakId, $dahiliKullaniciId);
    if (!$ticket) {
        apiResponse(['success' => false, 'message' => 'Ticket bulunamadı.'], 404);
    }

    if ($action === 'ticket_detail') {
        apiResponse([
            'success' => true,
            'data' => $ticket,
        ]);
    }

    if ($action === 'reply_ticket') {
        $mesajSonuc = $service->ticketMesajEkle([
            'ticket_id' => $ticketId,
            'mesaj' => $payload['mesaj'] ?? '',
            'dosyalar' => $dosyalar,
            'yazan_kullanici_id' => $dahiliKullaniciId,
            'mesaj_kaynak' => 'api',
            'islem_kullanici_id' => $dahiliKullaniciId,
        ]);

        $mesajSonuc['ekler'] = is_array($mesajSonuc['ekler'] ?? null) ? $mesajSonuc['ekler'] : [];
        $mesajSonuc['ek_sayisi'] = array_key_exists('ek_sayisi', $mesajSonuc)
            ? (int)$mesajSonuc['ek_sayisi']
            : count($mesajSonuc['ekler']);

        apiResponse([
            'success' => true,
            'message' => 'Mesaj gönderildi.',
            'data' => $mesajSonuc,
        ]);
    }

    throw new Exception('Geçersiz işlem.');
} catch (Exception $e) {
    apiResponse([
        'success' => false,
        'message' => $e->getMessage(),
    ], 400);
}