<?php
/**
 * PwaHelper - Web push için ortak yardımcılar
 *
 * VAPID anahtarları koda gömülmez; mevcut entegrasyon yapısından okunur:
 *   Entegrasyon (Entegrasyon_Kod = 'webpush')
 *     -> EntegrasyonKanallari (Kanal_Varsayilan = 1)
 *          Kanal_Parametreler = {"public":"...","private":"-----BEGIN EC PRIVATE KEY-----...","subject":"mailto:..."}
 */
class PwaHelper
{
    /** İstek başına VAPID önbelleği (aynı istekte tekrar tekrar sorgulanmasın) */
    private static $vapidCache = false;

    /**
     * VAPID yapılandırmasını döner; tanımlı değilse null.
     * @return array|null ['public'=>string, 'private'=>string(PEM), 'subject'=>string]
     */
    public static function vapid(Database $db): ?array
    {
        if (self::$vapidCache !== false) {
            return self::$vapidCache;
        }

        $kanal = $db->fetchOne("
            SELECT TOP 1 ek.Kanal_Parametreler
            FROM EntegrasyonKanallari ek
            INNER JOIN Entegrasyon e ON ek.Kanal_Entegrasyon_Id = e.Entegrasyon_Id
            WHERE e.Entegrasyon_Kod = 'webpush'
              AND e.Entegrasyon_Durum = 1
              AND ek.Kanal_Durum = 1
            ORDER BY ek.Kanal_Varsayilan DESC, ek.Kanal_Id
        ");

        if (!$kanal || empty($kanal['Kanal_Parametreler'])) {
            return self::$vapidCache = null;
        }

        $ayar = json_decode($kanal['Kanal_Parametreler'], true) ?: [];
        if (empty($ayar['public']) || empty($ayar['private'])) {
            return self::$vapidCache = null;
        }

        return self::$vapidCache = [
            'public'  => $ayar['public'],
            'private' => $ayar['private'],
            'subject' => $ayar['subject'] ?? 'mailto:destek@ornekproje.com',
        ];
    }

    /**
     * Bir kullanıcının aktif cihazlarına push gönderir.
     * Ölü abonelikler (404/410) otomatik pasife çekilir.
     *
     * @return array ['gonderilen'=>int, 'basarisiz'=>int, 'hata'=>string|null]
     */
    public static function kullaniciyaGonder(Database $db, int $kullaniciId, array $bildirim): array
    {
        $vapid = self::vapid($db);
        if (!$vapid) {
            return ['gonderilen' => 0, 'basarisiz' => 0, 'hata' => 'VAPID yapılandırılmamış.'];
        }
        if ($kullaniciId <= 0) {
            return ['gonderilen' => 0, 'basarisiz' => 0, 'hata' => 'Geçersiz kullanıcı.'];
        }

        $aboneler = $db->fetchAll("
            SELECT abonelik_id, abonelik_endpoint, abonelik_p256dh, abonelik_auth
            FROM dbo.Bildirim_Abonelikleri
            WHERE abonelik_kullanici_id = ? AND Durum = 1
        ", [$kullaniciId]);

        return self::aboneleregonder($db, $aboneler, $bildirim, $vapid);
    }

    /** Sistemdeki tüm aktif cihazlara push gönderir (genel duyuru) */
    public static function tumCihazlaraGonder(Database $db, array $bildirim): array
    {
        $vapid = self::vapid($db);
        if (!$vapid) {
            return ['gonderilen' => 0, 'basarisiz' => 0, 'hata' => 'VAPID yapılandırılmamış.'];
        }

        $aboneler = $db->fetchAll("
            SELECT abonelik_id, abonelik_endpoint, abonelik_p256dh, abonelik_auth
            FROM dbo.Bildirim_Abonelikleri
            WHERE Durum = 1
        ");

        return self::aboneleregonder($db, $aboneler, $bildirim, $vapid);
    }

    /** Verilen abonelik listesine push gönderir; ölü abonelikleri pasifler */
    private static function aboneleregonder(Database $db, array $aboneler, array $bildirim, array $vapid): array
    {
        require_once __DIR__ . '/WebPush.php';

        $gonderilen = 0;
        $basarisiz  = 0;
        $sonHata    = null;
        $payload    = json_encode($bildirim, JSON_UNESCAPED_UNICODE);

        foreach ($aboneler as $a) {
            $sonuc = WebPush::gonder(
                $a['abonelik_endpoint'],
                $a['abonelik_p256dh'],
                $a['abonelik_auth'],
                $payload,
                $vapid
            );

            if ($sonuc['kod'] >= 200 && $sonuc['kod'] < 300) {
                $gonderilen++;
                $db->execute(
                    "UPDATE dbo.Bildirim_Abonelikleri SET abonelik_son_kullanim = GETDATE() WHERE abonelik_id = ?",
                    [$a['abonelik_id']]
                );
                continue;
            }

            $basarisiz++;
            $sonHata = 'HTTP ' . $sonuc['kod'] . ($sonuc['hata'] ? ' - ' . mb_substr((string)$sonuc['hata'], 0, 200) : '');

            // Abonelik tarayıcı tarafında silinmiş: bir daha denenmesin
            if (in_array($sonuc['kod'], [404, 410], true)) {
                $db->execute(
                    "UPDATE dbo.Bildirim_Abonelikleri SET Durum = 0, GuncellemeTarihi = GETDATE() WHERE abonelik_id = ?",
                    [$a['abonelik_id']]
                );
            }
        }

        return [
            'gonderilen' => $gonderilen,
            'basarisiz'  => $basarisiz,
            'hata'       => $gonderilen === 0 ? $sonHata : null,
        ];
    }
}
