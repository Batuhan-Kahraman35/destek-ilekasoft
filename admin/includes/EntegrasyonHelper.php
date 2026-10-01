<?php
/**
 * Entegrasyon Helper Sınıfı
 * Mail (Gmail SMTP), WhatsApp (Evolution API), SMS (Mobilisim)
 *
 * Kullanım:
 *   $helper = new EntegrasyonHelper($db);
 *   $helper->mailGonder('alici@ornek.com', 'Konu', '<b>Mesaj</b>');
 *   $helper->whatsappGonder('905551234567', 'Mesaj');
 *   $helper->smsGonder('905551234567', 'Mesaj');
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

require_once __DIR__ . '/../../vendor/autoload.php';

class EntegrasyonHelper
{
    private $db;
    private $kanallar = [];  // kod => kanal satırı

    public function __construct($db)
    {
        $this->db = $db;
    }

    // ─────────────────────────────────────────────
    // KANAL
    // ─────────────────────────────────────────────

    private function getKanal(string $kod): array
    {
        if (isset($this->kanallar[$kod])) {
            return $this->kanallar[$kod];
        }

        $kanal = $this->db->fetchOne("
            SELECT ek.*, e.Entegrasyon_Kod
            FROM EntegrasyonKanallari ek
            INNER JOIN Entegrasyon e ON ek.Kanal_Entegrasyon_Id = e.Entegrasyon_Id
            WHERE e.Entegrasyon_Kod = ?
              AND ek.Kanal_Varsayilan = 1
              AND ek.Kanal_Durum = 1
            ORDER BY ek.Kanal_Id
        ", [$kod]);

        if (!$kanal) {
            throw new Exception("{$kod} entegrasyon kanalı bulunamadı veya pasif.");
        }

        if (!empty($kanal['Kanal_Parametreler'])) {
            $kanal['params'] = json_decode($kanal['Kanal_Parametreler'], true) ?? [];
        } else {
            $kanal['params'] = [];
        }

        $this->kanallar[$kod] = $kanal;
        return $kanal;
    }

    // ─────────────────────────────────────────────
    // LOG
    // ─────────────────────────────────────────────

    private function log(
        int    $kanalId,
        string $islem,
        string $alici,
        string $konu,
        string $mesaj,
               $istek,
               $yanit,
        string $durum,
        string $hataMesaj = null,
        int    $sureMsStart = null
    ): void {
        $sure = $sureMsStart !== null ? (int)((microtime(true) * 1000) - $sureMsStart) : null;

        try {
            $this->db->insert('EntegrasyonLoglari', [
                'Log_Kanal_Id'     => $kanalId,
                'Log_Islem'        => $islem,
                'Log_Alici'        => $alici,
                'Log_Konu'         => $konu,
                'Log_Mesaj'        => mb_substr($mesaj, 0, 500),
                'Log_Istek'        => $istek  ? json_encode($istek,  JSON_UNESCAPED_UNICODE) : null,
                'Log_Yanit'        => $yanit  ? json_encode($yanit,  JSON_UNESCAPED_UNICODE) : null,
                'Log_Durum'        => $durum,
                'Log_Hata_Mesaj'   => $hataMesaj,
                'Log_Sure_Ms'      => $sure,
                'Log_IP'           => $_SERVER['REMOTE_ADDR'] ?? null,
                'Log_Kullanici_Id' => $_SESSION['kullanici_id'] ?? null,
            ]);
        } catch (Exception $e) {
            error_log("EntegrasyonLog kaydı hatası: " . $e->getMessage());
        }
    }

    // ─────────────────────────────────────────────
    // MAIL
    // ─────────────────────────────────────────────

    /**
     * Mail gönderir.
     *
     * @param string|array $alici  'mail@ornek.com' veya ['mail@ornek.com' => 'Ad Soyad', ...]
     * @param string       $konu
     * @param string       $icerik HTML içerik
     * @param array        $ekler  [['path' => '/tam/yol', 'name' => 'dosya.pdf'], ...]
     * @return array ['success' => bool, 'message' => string]
     */
    public function mailGonder($alici, string $konu, string $icerik, array $ekler = []): array
    {
        $baslangic = (int)(microtime(true) * 1000);

        try {
            $kanal  = $this->getKanal('MAIL');
            $params = $kanal['params'];

            $port       = (int)($params['port'] ?? 465);
            $sifrele    = strtoupper($params['encryption'] ?? 'SSL');
            $fromName   = $params['from_name'] ?? 'Örnek Yazılım';

            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Host       = $kanal['Kanal_Endpoint'];
            $mail->SMTPAuth   = true;
            $mail->Username   = $kanal['Kanal_Kullanici'];
            $mail->Password   = $kanal['Kanal_Sifre'];
            $mail->SMTPSecure = ($sifrele === 'TLS') ? PHPMailer::ENCRYPTION_STARTTLS : PHPMailer::ENCRYPTION_SMTPS;
            $mail->Port       = $port;
            $mail->CharSet    = 'UTF-8';

            $mail->setFrom($kanal['Kanal_Kullanici'], $fromName);

            // Alıcılar
            if (is_array($alici)) {
                foreach ($alici as $adres => $ad) {
                    if (is_int($adres)) {
                        $mail->addAddress($ad);
                    } else {
                        $mail->addAddress($adres, $ad);
                    }
                }
                $aliciStr = implode(', ', array_keys($alici));
            } else {
                $mail->addAddress($alici);
                $aliciStr = $alici;
            }

            // Ekler
            foreach ($ekler as $ek) {
                if (!empty($ek['path']) && file_exists($ek['path'])) {
                    $mail->addAttachment($ek['path'], $ek['name'] ?? basename($ek['path']));
                }
            }

            $mail->isHTML(true);
            $mail->Subject = $konu;
            $mail->Body    = $icerik;
            $mail->AltBody = strip_tags($icerik);

            $mail->send();

            // Son kullanım güncelle
            $this->db->execute(
                "UPDATE EntegrasyonKanallari SET Kanal_Son_Kullanim = GETDATE() WHERE Kanal_Id = ?",
                [$kanal['Kanal_Id']]
            );

            $this->log(
                $kanal['Kanal_Id'], 'MAIL_GONDER',
                $aliciStr, $konu, $icerik,
                ['to' => $aliciStr, 'subject' => $konu],
                ['status' => 'sent'],
                'SUCCESS', null, $baslangic
            );

            return ['success' => true, 'message' => 'Mail başarıyla gönderildi.'];

        } catch (PHPMailerException $e) {
            $mesaj = 'PHPMailer hatası: ' . $e->getMessage();
            $this->log(
                $kanal['Kanal_Id'] ?? 0, 'MAIL_GONDER',
                is_array($alici) ? implode(',', array_keys($alici)) : $alici,
                $konu, $icerik, null, null, 'ERROR', $mesaj, $baslangic
            );
            return ['success' => false, 'message' => $mesaj];
        } catch (Exception $e) {
            $this->log(
                $kanal['Kanal_Id'] ?? 0, 'MAIL_GONDER',
                is_array($alici) ? implode(',', array_keys($alici)) : $alici,
                $konu, $icerik, null, null, 'ERROR', $e->getMessage(), $baslangic
            );
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Belirli bir kanal ID'si ile tek alıcıya mail gönderir (Toplu mail cron için).
     *
     * @param int    $kanalId  EntegrasyonKanallari.Kanal_Id
     * @param string $aliciEmail
     * @param string $aliciAd
     * @param string $konu
     * @param string $icerik  HTML içerik
     * @param array  $ekler   [['path' => '/tam/yol', 'name' => 'dosya.pdf'], ...]
     * @return array ['success' => bool, 'message' => string]
     */
    public function mailGonderKanalIle(int $kanalId, string $aliciEmail, string $aliciAd, string $konu, string $icerik, array $ekler = []): array
    {
        $baslangic = (int)(microtime(true) * 1000);

        try {
            $kanal = $this->db->fetchOne("
                SELECT ek.*, e.Entegrasyon_Kod
                FROM EntegrasyonKanallari ek
                INNER JOIN Entegrasyon e ON ek.Kanal_Entegrasyon_Id = e.Entegrasyon_Id
                WHERE ek.Kanal_Id = ? AND ek.Kanal_Durum = 1
            ", [$kanalId]);

            if (!$kanal) {
                throw new Exception("Kanal bulunamadı veya pasif. (ID: {$kanalId})");
            }

            $params   = !empty($kanal['Kanal_Parametreler']) ? (json_decode($kanal['Kanal_Parametreler'], true) ?? []) : [];
            $port     = (int)($params['port'] ?? 465);
            $sifrele  = strtoupper($params['encryption'] ?? 'SSL');
            $fromName = $params['from_name'] ?? 'Örnek Yazılım';

            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Host       = $kanal['Kanal_Endpoint'];
            $mail->SMTPAuth   = true;
            $mail->Username   = $kanal['Kanal_Kullanici'];
            $mail->Password   = $kanal['Kanal_Sifre'];
            $mail->SMTPSecure = ($sifrele === 'TLS') ? PHPMailer::ENCRYPTION_STARTTLS : PHPMailer::ENCRYPTION_SMTPS;
            $mail->Port       = $port;
            $mail->CharSet    = 'UTF-8';
            $mail->XMailer    = ' '; // PHPMailer imzasını gizle (spam filtresi)

            $mail->setFrom($kanal['Kanal_Kullanici'], $fromName);
            $mail->addAddress($aliciEmail, $aliciAd);

            // Spam önleme: List-Unsubscribe header
            $mail->addCustomHeader('List-Unsubscribe', '<mailto:' . $kanal['Kanal_Kullanici'] . '?subject=Unsubscribe>');
            $mail->addCustomHeader('Precedence', 'bulk');

            foreach ($ekler as $ek) {
                if (!empty($ek['path']) && file_exists($ek['path'])) {
                    $mail->addAttachment($ek['path'], $ek['name'] ?? basename($ek['path']));
                }
            }

            $mail->isHTML(true);
            $mail->Subject = $konu;
            $mail->Body    = $icerik . '<!-- mid:' . bin2hex(random_bytes(8)) . ' ts:' . time() . ' -->';
            $mail->AltBody = strip_tags($icerik);

            $mail->send();

            $this->db->execute(
                "UPDATE EntegrasyonKanallari SET Kanal_Son_Kullanim = GETDATE() WHERE Kanal_Id = ?",
                [$kanalId]
            );

            $this->log(
                $kanalId, 'TOPLU_MAIL',
                $aliciEmail, $konu, $icerik,
                ['to' => $aliciEmail, 'subject' => $konu],
                ['status' => 'sent'],
                'SUCCESS', null, $baslangic
            );

            return ['success' => true, 'message' => 'Mail başarıyla gönderildi.'];

        } catch (PHPMailerException $e) {
            $mesaj = 'PHPMailer hatası: ' . $e->getMessage();
            $this->log(
                $kanalId, 'TOPLU_MAIL',
                $aliciEmail, $konu, $icerik, null, null, 'ERROR', $mesaj, $baslangic
            );
            return ['success' => false, 'message' => $mesaj];
        } catch (Exception $e) {
            $this->log(
                $kanalId, 'TOPLU_MAIL',
                $aliciEmail, $konu, $icerik, null, null, 'ERROR', $e->getMessage(), $baslangic
            );
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // ─────────────────────────────────────────────
    // WHATSAPP (Evolution API)
    // ─────────────────────────────────────────────

    /**
     * WhatsApp mesajı gönderir.
     *
     * @param string $telefon  Uluslararası format: 905551234567 (+ olmadan)
     * @param string $mesaj
     * @return array ['success' => bool, 'message' => string, 'data' => array]
     */
    public function whatsappGonder(string $telefon, string $mesaj): array
    {
        $baslangic = (int)(microtime(true) * 1000);

        try {
            $kanal    = $this->getKanal('WHATSAPP');
            $params   = $kanal['params'];
            $instance = $params['instance'] ?? 'default';
            $baseUrl  = rtrim($kanal['Kanal_Endpoint'], '/');
            $apiKey   = $kanal['Kanal_Sifre'];

            // Telefon numarası sadece rakam olmalı
            $telefon = preg_replace('/\D/', '', $telefon);

            $url = "{$baseUrl}/message/sendText/{$instance}";

            $payload = [
                'number'  => $telefon,
                'text'    => $mesaj,
                'options' => ['delay' => 1200]
            ];

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode($payload),
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'apikey: ' . $apiKey,
                ],
                CURLOPT_TIMEOUT        => 30,
            ]);

            $response   = curl_exec($ch);
            $httpCode   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError  = curl_error($ch);
            curl_close($ch);

            if ($curlError) {
                throw new Exception("cURL hatası: {$curlError}");
            }

            $yanit = json_decode($response, true) ?? ['raw' => $response];

            if ($httpCode >= 200 && $httpCode < 300) {
                $this->db->execute(
                    "UPDATE EntegrasyonKanallari SET Kanal_Son_Kullanim = GETDATE() WHERE Kanal_Id = ?",
                    [$kanal['Kanal_Id']]
                );

                $this->log(
                    $kanal['Kanal_Id'], 'WA_GONDER',
                    $telefon, '', $mesaj,
                    $payload, $yanit,
                    'SUCCESS', null, $baslangic
                );

                return ['success' => true, 'message' => 'WhatsApp mesajı gönderildi.', 'data' => $yanit];
            } else {
                $hata = $yanit['message'] ?? $yanit['error'] ?? "HTTP {$httpCode}";
                $this->log(
                    $kanal['Kanal_Id'], 'WA_GONDER',
                    $telefon, '', $mesaj,
                    $payload, $yanit,
                    'ERROR', (string)$httpCode, $hata, $baslangic
                );
                return ['success' => false, 'message' => $hata, 'data' => $yanit];
            }

        } catch (Exception $e) {
            $this->log(
                $kanal['Kanal_Id'] ?? 0, 'WA_GONDER',
                $telefon, '', $mesaj, null, null,
                'ERROR', null, $e->getMessage(), $baslangic
            );
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * WhatsApp instance durumunu kontrol eder.
     * @return array ['success' => bool, 'state' => string, 'data' => array]
     */
    public function whatsappDurumKontrol(): array
    {
        $baslangic = (int)(microtime(true) * 1000);

        try {
            $kanal    = $this->getKanal('WHATSAPP');
            $params   = $kanal['params'];
            $instance = $params['instance'] ?? 'default';
            $baseUrl  = rtrim($kanal['Kanal_Endpoint'], '/');
            $apiKey   = $kanal['Kanal_Sifre'];

            $url = "{$baseUrl}/instance/connectionState/{$instance}";

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => ['apikey: ' . $apiKey],
                CURLOPT_TIMEOUT        => 10,
            ]);

            $response  = curl_exec($ch);
            $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($curlError) {
                throw new Exception("cURL hatası: {$curlError}");
            }

            $yanit = json_decode($response, true) ?? ['raw' => $response];
            $state = $yanit['instance']['state'] ?? $yanit['state'] ?? 'unknown';

            $this->log(
                $kanal['Kanal_Id'], 'WA_DURUM',
                $instance, '', '',
                ['instance' => $instance], $yanit,
                ($httpCode === 200 ? 'SUCCESS' : 'ERROR'),
                ($httpCode !== 200 ? (string)$httpCode : null),
                ($httpCode !== 200 ? "HTTP {$httpCode}" : null),
                $baslangic
            );

            return [
                'success' => $httpCode === 200,
                'state'   => $state,
                'data'    => $yanit
            ];

        } catch (Exception $e) {
            return ['success' => false, 'state' => 'error', 'message' => $e->getMessage()];
        }
    }

    // ─────────────────────────────────────────────
    // SMS (Mobilisim)
    // ─────────────────────────────────────────────

    /**
     * SMS gönderir.
     *
     * @param string|array $telefon  '905551234567' veya ['905551234567','905559876543']
     * @param string       $mesaj
     * @return array ['success' => bool, 'message' => string, 'data' => array]
     */
    public function smsGonder($telefon, string $mesaj): array
    {
        $baslangic = (int)(microtime(true) * 1000);

        try {
            $kanal      = $this->getKanal('SMS');
            $params     = $kanal['params'];
            $originator = $params['originator'] ?? 'BILGI';
            $action     = $params['action']     ?? '12';
            $url        = rtrim($kanal['Kanal_Endpoint'], '/') . '/';

            // Numara(lar) hazırla
            if (is_array($telefon)) {
                $numbers = implode(',', array_map(fn($t) => preg_replace('/\D/', '', $t), $telefon));
            } else {
                $numbers = preg_replace('/\D/', '', $telefon);
            }

            // XML gövdesi
            $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
            $xml .= '<MainmsgBody>' . "\n";
            $xml .= '    <UserName>' . htmlspecialchars($kanal['Kanal_Kullanici']) . '</UserName>' . "\n";
            $xml .= '    <PassWord>'  . htmlspecialchars($kanal['Kanal_Sifre'])    . '</PassWord>'  . "\n";
            $xml .= '    <Action>'    . htmlspecialchars($action)                  . '</Action>'    . "\n";
            $xml .= '    <Mesgbody>'  . htmlspecialchars($mesaj)                   . '</Mesgbody>'  . "\n";
            $xml .= '    <Numbers>'   . htmlspecialchars($numbers)                 . '</Numbers>'   . "\n";
            $xml .= '    <Originator>'. htmlspecialchars($originator)              . '</Originator>'. "\n";
            $xml .= '    <SDate></SDate>' . "\n";
            $xml .= '</MainmsgBody>';

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $xml,
                CURLOPT_HTTPHEADER     => ['Content-Type: text/xml; charset=UTF-8'],
                CURLOPT_TIMEOUT        => 30,
            ]);

            $response  = curl_exec($ch);
            $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($curlError) {
                throw new Exception("cURL hatası: {$curlError}");
            }

            // XML yanıtını parse et
            $yanit   = ['raw' => $response];
            $basarili = false;
            $hataMsg  = null;

            try {
                $xmlObj = new SimpleXMLElement($response);
                $kod    = (string)($xmlObj->Code ?? $xmlObj->ResponseCode ?? '');
                $status = (string)($xmlObj->Status ?? $xmlObj->Message ?? '');
                $yanit  = ['code' => $kod, 'status' => $status, 'raw' => $response];
                // Mobilisim başarı kodu genellikle 0 veya "0"
                $basarili = ($kod === '0' || $kod === '' || $httpCode === 200);
                if (!$basarili) {
                    $hataMsg = "Kod:{$kod} - {$status}";
                }
            } catch (Exception $xmlEx) {
                $basarili = ($httpCode >= 200 && $httpCode < 300);
                if (!$basarili) {
                    $hataMsg  = "HTTP {$httpCode}: {$response}";
                }
            }

            $this->db->execute(
                "UPDATE EntegrasyonKanallari SET Kanal_Son_Kullanim = GETDATE() WHERE Kanal_Id = ?",
                [$kanal['Kanal_Id']]
            );

            $this->log(
                $kanal['Kanal_Id'], 'SMS_GONDER',
                $numbers, '', $mesaj,
                ['xml' => $xml], $yanit,
                ($basarili ? 'SUCCESS' : 'ERROR'),
                $basarili ? null : (string)$httpCode,
                $hataMsg, $baslangic
            );

            return $basarili
                ? ['success' => true,  'message' => 'SMS gönderildi.',   'data' => $yanit]
                : ['success' => false, 'message' => $hataMsg ?? 'Hata.', 'data' => $yanit];

        } catch (Exception $e) {
            $this->log(
                $kanal['Kanal_Id'] ?? 0, 'SMS_GONDER',
                is_array($telefon) ? implode(',', $telefon) : $telefon,
                '', $mesaj, null, null,
                'ERROR', null, $e->getMessage(), $baslangic
            );
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // ─────────────────────────────────────────────
    // BAĞLANTI TEST
    // ─────────────────────────────────────────────

    /**
     * Belirli bir entegrasyonun bağlantısını test eder.
     * @param string $kod  MAIL | WHATSAPP | SMS
     * @return array
     */
    public function baglantiTest(string $kod): array
    {
        switch (strtoupper($kod)) {
            case 'MAIL':
                return $this->mailBaglantiTest();
            case 'WHATSAPP':
                return $this->whatsappDurumKontrol();
            case 'SMS':
                return $this->smsBaglantiTest();
            default:
                return ['success' => false, 'message' => 'Bilinmeyen entegrasyon kodu.'];
        }
    }

    private function mailBaglantiTest(): array
    {
        $baslangic = (int)(microtime(true) * 1000);
        try {
            $kanal  = $this->getKanal('MAIL');
            $params = $kanal['params'];

            $port    = (int)($params['port'] ?? 465);
            $sifrele = strtoupper($params['encryption'] ?? 'SSL');

            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->SMTPDebug  = 0;
            $mail->Host       = $kanal['Kanal_Endpoint'];
            $mail->Port       = $port;
            $mail->SMTPAuth   = true;
            $mail->Username   = $kanal['Kanal_Kullanici'];
            $mail->Password   = $kanal['Kanal_Sifre'];
            $mail->SMTPSecure = ($sifrele === 'TLS') ? PHPMailer::ENCRYPTION_STARTTLS : PHPMailer::ENCRYPTION_SMTPS;

            $sonuc = $mail->smtpConnect();
            $mail->smtpClose();

            $sure = (int)(microtime(true) * 1000) - $baslangic;

            $this->log(
                $kanal['Kanal_Id'], 'MAIL_TEST',
                '', '', '',
                ['host' => $kanal['Kanal_Endpoint'], 'port' => $port],
                ['connected' => $sonuc],
                $sonuc ? 'SUCCESS' : 'ERROR',
                $sonuc ? null : 'Bağlantı reddedildi.',
                null, $baslangic
            );

            return $sonuc
                ? ['success' => true,  'message' => "SMTP bağlantısı başarılı ({$sure}ms)"]
                : ['success' => false, 'message' => 'SMTP bağlantısı reddedildi.'];

        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    private function smsBaglantiTest(): array
    {
        // Mobilisim bakiye/hesap sorgusu (Action=2)
        $baslangic = (int)(microtime(true) * 1000);
        try {
            $kanal = $this->getKanal('SMS');
            $url   = rtrim($kanal['Kanal_Endpoint'], '/') . '/';

            $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
            $xml .= '<MainmsgBody>';
            $xml .= '<UserName>' . htmlspecialchars($kanal['Kanal_Kullanici']) . '</UserName>';
            $xml .= '<PassWord>'  . htmlspecialchars($kanal['Kanal_Sifre'])    . '</PassWord>';
            $xml .= '<Action>2</Action>';  // Bakiye sorgula
            $xml .= '</MainmsgBody>';

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $xml,
                CURLOPT_HTTPHEADER     => ['Content-Type: text/xml; charset=UTF-8'],
                CURLOPT_TIMEOUT        => 10,
            ]);

            $response  = curl_exec($ch);
            $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            $sure = (int)(microtime(true) * 1000) - $baslangic;

            if ($curlError) {
                throw new Exception("cURL: {$curlError}");
            }

            $this->log(
                $kanal['Kanal_Id'], 'SMS_TEST',
                '', '', '',
                ['url' => $url], ['raw' => $response],
                ($httpCode === 200 ? 'SUCCESS' : 'ERROR'),
                ($httpCode !== 200 ? (string)$httpCode : null),
                ($httpCode !== 200 ? "HTTP {$httpCode}" : null),
                $baslangic
            );

            return ($httpCode === 200)
                ? ['success' => true,  'message' => "SMS API erişilebilir ({$sure}ms)", 'data' => $response]
                : ['success' => false, 'message' => "HTTP {$httpCode}"];

        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}
