<?php
/**
 * Kaynaga ozel ticket entegrasyon istemcisi indirir
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
requireAuth();

$user = Auth::user();
$db = Database::getInstance();

if (($user['departman_id'] ?? 0) != 1) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Bu islem icin yetkiniz yok.';
    exit;
}

$kaynakId = isset($_GET['kaynak_id']) ? (int)$_GET['kaynak_id'] : 0;
if ($kaynakId <= 0) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Gecersiz kaynak_id.';
    exit;
}

$kaynak = $db->fetchOne(
    "SELECT Ticket_Kaynak_id, Ticket_Kaynak_ad, Ticket_Kaynak_kod, Ticket_Kaynak_api_url, Ticket_Kaynak_api_key, Durum
     FROM Ticket_Kaynak
     WHERE Ticket_Kaynak_id = ?",
    [$kaynakId]
);

if (!$kaynak) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Kaynak bulunamadi.';
    exit;
}

if ((int)($kaynak['Durum'] ?? 0) !== 1) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Kaynak pasif durumda. Once aktif edin.';
    exit;
}

$apiBase = rtrim((string)($kaynak['Ticket_Kaynak_api_url'] ?? ''), '/');
$apiUrl = $apiBase . '/api/tickets.php';

$kaynakKod = (string)($kaynak['Ticket_Kaynak_kod'] ?? 'KAYNAK');
$kaynakAd = (string)($kaynak['Ticket_Kaynak_ad'] ?? 'Kaynak');
$apiKey = (string)($kaynak['Ticket_Kaynak_api_key'] ?? '');

$esc = static function (string $value): string {
    return str_replace(["\\", "'"], ["\\\\", "\\'"], $value);
};

$template = <<<'PHP'
<?php
/**
 * Ticket API Entegrasyon Istemcisi
 * Otomatik olusturulma tarihi: {{DATE}}
 * Kaynak: {{KAYNAK_AD}} ({{KAYNAK_KOD}})
 */

class TicketApiClient
{
    private string $apiUrl = '{{API_URL}}';
    private string $apiKey = '{{API_KEY}}';
    private string $kaynakKod = '{{KAYNAK_KOD}}';

    public function getKaynakKod(): string
    {
        return $this->kaynakKod;
    }

    public function createTicket(array $kullanici, string $konu, string $mesaj, int $kategoriId, int $oncelikId, array $dosyaYollari = []): array
    {
        $payload = [
            'action' => 'create_ticket',
            'kullanici' => $kullanici,
            'konu' => $konu,
            'mesaj' => $mesaj,
            'kategori_id' => $kategoriId,
            'oncelik_id' => $oncelikId
        ];

        if (!empty($dosyaYollari)) {
            return $this->requestMultipart($payload, $dosyaYollari);
        }

        return $this->request($payload);
    }

    public function replyTicket(int $ticketId, array $kullanici, string $mesaj, array $dosyaYollari = []): array
    {
        $payload = [
            'action' => 'reply_ticket',
            'ticket_id' => $ticketId,
            'kullanici' => $kullanici,
            'mesaj' => $mesaj
        ];

        if (!empty($dosyaYollari)) {
            return $this->requestMultipart($payload, $dosyaYollari);
        }

        return $this->request($payload);
    }

    public function ticketDetail(int $ticketId, array $kullanici): array
    {
        return $this->request([
            'action' => 'ticket_detail',
            'ticket_id' => $ticketId,
            'kullanici' => $kullanici
        ]);
    }

    public function ticketMessages(int $ticketId, array $kullanici): array
    {
        return $this->request([
            'action' => 'ticket_messages',
            'ticket_id' => $ticketId,
            'kullanici' => $kullanici
        ]);
    }

    public function ticketMeta(): array
    {
        return $this->request([
            'action' => 'ticket_meta'
        ]);
    }

    private function request(array $payload): array
    {
        $ch = curl_init($this->apiUrl);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-API-KEY: ' . $this->apiKey
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT => 30
        ]);

        $raw = curl_exec($ch);

        if ($raw === false) {
            $error = curl_error($ch);
            curl_close($ch);
            return [
                'success' => false,
                'message' => 'Curl hatasi: ' . $error
            ];
        }

        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [
                'success' => false,
                'message' => 'JSON parse hatasi',
                'http_code' => $statusCode,
                'raw' => $raw
            ];
        }

        $decoded['http_code'] = $statusCode;
        return $decoded;
    }

    private function requestMultipart(array $payload, array $dosyaYollari): array
    {
        $fields = [
            'action' => (string)$payload['action'],
            'kullanici' => json_encode($payload['kullanici'], JSON_UNESCAPED_UNICODE),
        ];

        foreach ($payload as $key => $value) {
            if (in_array($key, ['action', 'kullanici'], true)) {
                continue;
            }
            $fields[$key] = (string)$value;
        }

        $index = 0;
        foreach ($dosyaYollari as $dosyaYolu) {
            if (!is_string($dosyaYolu) || $dosyaYolu === '' || !is_file($dosyaYolu)) {
                continue;
            }
            $fields['dosyalar[' . $index . ']'] = new CURLFile($dosyaYolu);
            $index++;
        }

        $ch = curl_init($this->apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'X-API-KEY: ' . $this->apiKey
            ],
            CURLOPT_POSTFIELDS => $fields,
            CURLOPT_TIMEOUT => 60
        ]);

        $raw = curl_exec($ch);
        if ($raw === false) {
            $error = curl_error($ch);
            curl_close($ch);
            return [
                'success' => false,
                'message' => 'Curl hatasi: ' . $error
            ];
        }

        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [
                'success' => false,
                'message' => 'JSON parse hatasi',
                'http_code' => $statusCode,
                'raw' => $raw
            ];
        }

        $decoded['http_code'] = $statusCode;
        return $decoded;
    }
}

/*
 * KULLANIM ORNEGI
 *
 * $client = new TicketApiClient();
 *
 * // 0) Kategori / oncelik listesini al (create_ticket oncesi kullan)
 * $meta = $client->ticketMeta();
 * // $meta['data']['kategoriler'] ve $meta['data']['oncelikler']
 *
 * $kullanici = [
 *     'kaynak_kullanici_id' => '245',
 *     'ad' => 'Batuhan',
 *     'soyad' => 'Kahraman',
 *     'eposta' => 'ornek@site.com',
 *     'telefon' => '5xxxxxxxxx'
 * ];
 *
 * // 1) Ticket ac (ilk icerik mesaj olarak kaydedilir)
 * $create = $client->createTicket($kullanici, 'API test ticket', 'Harici siteden test kaydi', 1, 2, [
 *     'C:/temp/ekran-goruntusu.png',
 *     'C:/temp/log.txt'
 * ]);
 * print_r($create);
 *
 * // 2) Ticketa mesaj ekle
 * if (!empty($create['success']) && !empty($create['data']['ticket_id'])) {
 *     $ticketId = (int)$create['data']['ticket_id'];
 *     $reply = $client->replyTicket($ticketId, $kullanici, 'Ilk yanit mesaji', [
 *         'C:/temp/ek-dokuman.pdf'
 *     ]);
 *     print_r($reply);
 * }
 */
PHP;

$rendered = strtr($template, [
    '{{DATE}}' => app_now(),
    '{{KAYNAK_AD}}' => $kaynakAd,
    '{{KAYNAK_KOD}}' => $kaynakKod,
    '{{API_URL}}' => $esc($apiUrl),
    '{{API_KEY}}' => $esc($apiKey)
]);

$fileName = 'ticket-entegrasyon-' . preg_replace('/[^a-zA-Z0-9_-]/', '-', $kaynakKod) . '.php';

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $fileName . '"');
header('Content-Length: ' . strlen($rendered));
echo $rendered;
exit;
