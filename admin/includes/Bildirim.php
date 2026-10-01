<?php
/**
 * Bildirim - Merkezi bildirim yönetimi
 *
 * Tüm panel bildirimleri dbo.Bildirimler tablosunda toplanır; kayıt hem header
 * çanını hem web push gönderimini besler.
 *
 * Kullanım:
 *   Bildirim::olustur($db, ['kullanici_id'=>5, 'baslik'=>'...', 'govde'=>'...', 'url'=>'/admin/ticket-detay?id=12']);
 *   Bildirim::olusturCoklu($db, [5,6,7], $veri);
 *   Bildirim::genelDuyuru($db, $veri);
 */
require_once __DIR__ . '/PwaHelper.php';

class Bildirim
{
    /**
     * Yeni bildirim oluşturur; push açıksa kullanıcının cihazlarına anında gönderir.
     *
     * @param array $veri kullanici_id, baslik, govde, url, tip (info|basari|uyari|hata),
     *                    ticket_id, olay, olusturan,
     *                    push   (bool) bu bildirim push olarak gönderilsin mi (kalıcı; outbox bunu kullanır)
     *                    aninda (bool) oluşturma anında denensin mi; false ise cron telafi görevine bırakılır
     * @return int Oluşan bildirim_id
     */
    public static function olustur(Database $db, array $veri): int
    {
        // "push" bildirimin kalıcı niteliğidir; "aninda" yalnızca gönderim zamanını belirler.
        // İkisi ayrı tutulmazsa ertelenen bildirimler outbox sorgusunun dışında kalır.
        $pushGonder = array_key_exists('push', $veri) ? (int)(bool)$veri['push'] : 1;
        $aninda     = array_key_exists('aninda', $veri) ? (bool)$veri['aninda'] : true;
        $olusturan  = (int)($veri['olusturan'] ?? ($veri['kullanici_id'] ?? 0));
        $kullaniciId = !empty($veri['kullanici_id']) ? (int)$veri['kullanici_id'] : null;

        $bildirimId = $db->insert('dbo.Bildirimler', [
            'bildirim_kullanici_id'    => $kullaniciId,
            'bildirim_baslik'          => mb_substr((string)($veri['baslik'] ?? ''), 0, 200),
            'bildirim_govde'           => mb_substr((string)($veri['govde'] ?? ''), 0, 1000),
            'bildirim_url'             => mb_substr((string)($veri['url'] ?? '/admin/ticket-liste'), 0, 500),
            'bildirim_tip'             => $veri['tip'] ?? 'info',
            'bildirim_ticket_id'       => !empty($veri['ticket_id']) ? (int)$veri['ticket_id'] : null,
            'bildirim_olay'            => $veri['olay'] ?? null,
            'bildirim_okundu'          => 0,
            'bildirim_push_gonder'     => $pushGonder,
            'bildirim_push_gonderildi' => 0,
            'OlusturanKullanici'       => $olusturan ?: null,
            'OlusturmaTarihi'          => app_now(),
            'Durum'                    => 1,
        ]);

        if ($pushGonder && $aninda && $kullaniciId) {
            self::push($db, (int)$bildirimId);
        }

        return (int)$bildirimId;
    }

    /**
     * Bildirimi ilgili kullanıcının cihazlarına push olarak gönderir ve
     * başarılıysa outbox işaretçisini (bildirim_push_gonderildi) 1 yapar.
     */
    public static function push(Database $db, int $bildirimId): array
    {
        $b = $db->fetchOne("SELECT * FROM dbo.Bildirimler WHERE bildirim_id = ?", [$bildirimId]);
        if (!$b || empty($b['bildirim_kullanici_id'])) {
            return ['gonderilen' => 0, 'basarisiz' => 0];
        }

        $sonuc = PwaHelper::kullaniciyaGonder($db, (int)$b['bildirim_kullanici_id'], [
            'baslik' => $b['bildirim_baslik'],
            'govde'  => $b['bildirim_govde'],
            'url'    => $b['bildirim_url'],
            'tag'    => 'bildirim-' . $bildirimId,
        ]);

        if (($sonuc['gonderilen'] ?? 0) > 0) {
            $db->execute(
                "UPDATE dbo.Bildirimler SET bildirim_push_gonderildi = 1, GuncellemeTarihi = GETDATE() WHERE bildirim_id = ?",
                [$bildirimId]
            );
        }

        return $sonuc;
    }

    /**
     * Aynı bildirimi birden çok kullanıcı için oluşturur.
     * @return array ['bildirim'=>int oluşan kayıt sayısı, 'push'=>int push alan cihaz sayısı]
     */
    public static function olusturCoklu(Database $db, array $kullaniciIdler, array $veri): array
    {
        $pushIstenen    = array_key_exists('push', $veri) ? (bool)$veri['push'] : true;
        $bildirimSayisi = 0;
        $pushToplam     = 0;

        foreach (array_unique(array_map('intval', $kullaniciIdler)) as $kid) {
            if ($kid <= 0) continue;

            $veri['kullanici_id'] = $kid;
            $veri['push']         = $pushIstenen;   // kalıcı nitelik korunur (outbox telafisi için)
            $veri['aninda']       = false;          // gönderim aşağıda tek sefer tetiklenir
            $bid = self::olustur($db, $veri);
            $bildirimSayisi++;

            if ($pushIstenen) {
                $r = self::push($db, $bid);
                $pushToplam += ($r['gonderilen'] ?? 0);
            }
        }

        return ['bildirim' => $bildirimSayisi, 'push' => $pushToplam];
    }

    /**
     * Genel duyuru: tek broadcast kaydı (kullanici_id NULL -> herkesin çanında görünür)
     * ve istenirse tüm aktif cihazlara push.
     */
    public static function genelDuyuru(Database $db, array $veri): array
    {
        $pushIstenen = array_key_exists('push', $veri) ? (bool)$veri['push'] : true;

        $veri['kullanici_id'] = null;
        $veri['push']         = $pushIstenen;
        $veri['aninda']       = false;
        $bid = self::olustur($db, $veri);

        $push = ['gonderilen' => 0];
        if ($pushIstenen) {
            $push = PwaHelper::tumCihazlaraGonder($db, [
                'baslik' => $veri['baslik'] ?? '',
                'govde'  => $veri['govde'] ?? '',
                'url'    => $veri['url'] ?? '/admin/ticket-liste',
                'tag'    => 'bildirim-' . $bid,
            ]);
            if (($push['gonderilen'] ?? 0) > 0) {
                $db->execute(
                    "UPDATE dbo.Bildirimler SET bildirim_push_gonderildi = 1, GuncellemeTarihi = GETDATE() WHERE bildirim_id = ?",
                    [$bid]
                );
            }
        }

        return ['bildirim_id' => $bid, 'push' => $push['gonderilen'] ?? 0];
    }

    /** Kullanıcının bildirimlerini listeler (kendine ait + genel duyurular) */
    public static function listele(Database $db, int $kullaniciId, int $limit = 20): array
    {
        return $db->fetchAll("
            SELECT TOP (?)
                   bildirim_id, bildirim_baslik, bildirim_govde, bildirim_url,
                   bildirim_tip, bildirim_okundu, bildirim_ticket_id, bildirim_olay,
                   CONVERT(VARCHAR(19), OlusturmaTarihi, 120) AS olusturma_tarihi,
                   DATEDIFF(MINUTE, OlusturmaTarihi, GETDATE()) AS dakika_once
            FROM dbo.Bildirimler
            WHERE Durum = 1
              AND (bildirim_kullanici_id = ? OR bildirim_kullanici_id IS NULL)
            ORDER BY bildirim_id DESC
        ", [$limit, $kullaniciId]);
    }

    /** Okunmamış bildirim sayısı */
    public static function okunmamisSayisi(Database $db, int $kullaniciId): int
    {
        $r = $db->fetchOne("
            SELECT COUNT(*) AS sayi
            FROM dbo.Bildirimler
            WHERE Durum = 1 AND bildirim_okundu = 0
              AND (bildirim_kullanici_id = ? OR bildirim_kullanici_id IS NULL)
        ", [$kullaniciId]);

        return (int)($r['sayi'] ?? 0);
    }

    /** Tek bildirimi veya kullanıcının tüm bildirimlerini okundu işaretler */
    public static function okunduYap(Database $db, int $kullaniciId, ?int $bildirimId = null): void
    {
        if ($bildirimId) {
            $db->execute("
                UPDATE dbo.Bildirimler
                SET bildirim_okundu = 1, bildirim_okunma_tarihi = GETDATE()
                WHERE bildirim_id = ?
                  AND (bildirim_kullanici_id = ? OR bildirim_kullanici_id IS NULL)
            ", [$bildirimId, $kullaniciId]);
            return;
        }

        $db->execute("
            UPDATE dbo.Bildirimler
            SET bildirim_okundu = 1, bildirim_okunma_tarihi = GETDATE()
            WHERE bildirim_okundu = 0
              AND (bildirim_kullanici_id = ? OR bildirim_kullanici_id IS NULL)
        ", [$kullaniciId]);
    }
}
