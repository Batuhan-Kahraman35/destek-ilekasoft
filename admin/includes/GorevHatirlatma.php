<?php
/**
 * Görev hatırlatmaları (Ticket_Gorevler + Ticket_Gorev_Alicilari)
 *
 * ticket-detay sayfası, ticket-ajan CLI aracı ve hatırlatma cron görevi
 * aynı kuralları bu sınıftan kullanır.
 *
 * Kurallar:
 *  - Kanallar Ticket_Sablon_Kanallari tablosundan gelir; PUSH (panel + push) her alıcıya eklenir.
 *  - Alıcı kaydı yoksa hatırlatma görevin atananına, atanan yoksa oluşturana gider.
 *  - Tarih değiştiğinde (kaydet / ertele) gönderim işaretleri sıfırlanır, hatırlatma yeniden gider.
 *  - Hızlı seçim / erteleme seçenekleri Tanim_Hatirlatma_Secenekleri tablosundan gelir:
 *      dakika doluysa  → şimdi + dakika
 *      değilse         → bugün + gun, saat
 */
class GorevHatirlatma
{
    public const PUSH_KOD = 'PUSH';

    /** Aktif hızlı seçim / erteleme seçenekleri */
    public static function secenekler(Database $db): array
    {
        return $db->fetchAll("
            SELECT Tanim_Hatirlatma_Secenekleri_id     AS id,
                   Tanim_Hatirlatma_Secenekleri_ad     AS ad,
                   Tanim_Hatirlatma_Secenekleri_dakika AS dakika,
                   Tanim_Hatirlatma_Secenekleri_gun    AS gun,
                   CONVERT(VARCHAR(5), Tanim_Hatirlatma_Secenekleri_saat, 108) AS saat
            FROM dbo.Tanim_Hatirlatma_Secenekleri
            WHERE Durum = 1
            ORDER BY Tanim_Hatirlatma_Secenekleri_sira, Tanim_Hatirlatma_Secenekleri_id
        ");
    }

    /** Seçeneği ID veya ad ile bulur (ad karşılaştırması büyük/küçük harf duyarsız) */
    public static function secenekBul(Database $db, string $deger): ?array
    {
        $deger = trim($deger);
        if ($deger === '') return null;

        foreach (self::secenekler($db) as $s) {
            if (ctype_digit($deger) && (int)$s['id'] === (int)$deger) return $s;
            if (mb_strtolower($s['ad'], 'UTF-8') === mb_strtolower($deger, 'UTF-8')) return $s;
        }
        return null;
    }

    /** Seçenekten hedef tarihi hesaplar (Y-m-d H:i:s) */
    public static function tarihHesapla(array $secenek, ?DateTimeImmutable $simdi = null): string
    {
        $simdi = $simdi ?? new DateTimeImmutable(app_now());

        if ($secenek['dakika'] !== null && $secenek['dakika'] !== '') {
            return $simdi->modify('+' . (int)$secenek['dakika'] . ' minutes')->format('Y-m-d H:i:00');
        }

        [$saat, $dakika] = array_map('intval', explode(':', $secenek['saat'] ?: '09:00'));
        return $simdi->modify('+' . (int)$secenek['gun'] . ' days')
                     ->setTime($saat, $dakika)
                     ->format('Y-m-d H:i:00');
    }

    /**
     * Kullanıcı girdisini tarihe çevirir: "2026-11-14 09:00", "2026-11-14T09:00", "14.11.2026 09:00"
     * veya hızlı seçim adı/ID'si. Geçersizse Exception.
     */
    public static function tarihCoz(Database $db, string $girdi): string
    {
        $girdi = trim($girdi);
        foreach (['Y-m-d H:i', 'Y-m-d\TH:i', 'Y-m-d H:i:s', 'd.m.Y H:i'] as $bicim) {
            $dt = DateTimeImmutable::createFromFormat('!' . $bicim, $girdi);
            if ($dt && $dt->format($bicim) === $girdi) {
                return $dt->format('Y-m-d H:i:00');
            }
        }

        $secenek = self::secenekBul($db, $girdi);
        if ($secenek) return self::tarihHesapla($secenek);

        $adlar = array_column(self::secenekler($db), 'ad');
        throw new Exception("Geçersiz hatırlatma tarihi: {$girdi}. Biçim: YYYY-AA-GG SS:DD veya: " . implode(', ', $adlar));
    }

    /** Aktif kanallar */
    public static function kanallar(Database $db): array
    {
        return $db->fetchAll("
            SELECT Ticket_Sablon_Kanallari_id   AS id,
                   Ticket_Sablon_Kanallari_kod  AS kod,
                   Ticket_Sablon_Kanallari_ad   AS ad,
                   Ticket_Sablon_Kanallari_renk AS renk
            FROM dbo.Ticket_Sablon_Kanallari
            WHERE Durum = 1
            ORDER BY Ticket_Sablon_Kanallari_sira
        ");
    }

    /** Kanalı ID veya kod ile bulur */
    public static function kanalBul(Database $db, string $deger): ?array
    {
        $deger = mb_strtoupper(trim($deger), 'UTF-8');
        foreach (self::kanallar($db) as $k) {
            if ((string)$k['id'] === $deger || mb_strtoupper($k['kod'], 'UTF-8') === $deger) return $k;
        }
        return null;
    }

    public static function pushKanalId(Database $db): int
    {
        $k = self::kanalBul($db, self::PUSH_KOD);
        if (!$k) throw new Exception('PUSH kanalı Ticket_Sablon_Kanallari tablosunda bulunamadı.');
        return (int)$k['id'];
    }

    /** Görevin alıcıları: [{kullanici_id, ad, kanallar:[{id,kod,ad,gonderim_tarihi,sonuc}]}] */
    public static function alicilariGetir(Database $db, int $gorevId): array
    {
        $satirlar = $db->fetchAll("
            SELECT a.Ticket_Gorev_Alicilari_kullanici_id AS kullanici_id,
                   LTRIM(RTRIM(ISNULL(k.kullanici_ad, '') + ' ' + ISNULL(k.kullanici_soyad, ''))) AS ad,
                   kn.Ticket_Sablon_Kanallari_id  AS kanal_id,
                   kn.Ticket_Sablon_Kanallari_kod AS kanal_kod,
                   kn.Ticket_Sablon_Kanallari_ad  AS kanal_ad,
                   CONVERT(VARCHAR(16), a.Ticket_Gorev_Alicilari_gonderim_tarihi, 120) AS gonderim_tarihi,
                   a.Ticket_Gorev_Alicilari_sonuc AS sonuc
            FROM dbo.Ticket_Gorev_Alicilari a
            INNER JOIN dbo.kullanicilar k             ON k.kullanici_id = a.Ticket_Gorev_Alicilari_kullanici_id
            INNER JOIN dbo.Ticket_Sablon_Kanallari kn ON kn.Ticket_Sablon_Kanallari_id = a.Ticket_Gorev_Alicilari_kanal_id
            WHERE a.Ticket_Gorev_Alicilari_gorev_id = ? AND a.Durum = 1
            ORDER BY k.kullanici_ad, kn.Ticket_Sablon_Kanallari_sira
        ", [$gorevId]);

        $sonuc = [];
        foreach ($satirlar as $s) {
            $kid = (int)$s['kullanici_id'];
            if (!isset($sonuc[$kid])) {
                $sonuc[$kid] = ['kullanici_id' => $kid, 'ad' => $s['ad'], 'kanallar' => []];
            }
            $sonuc[$kid]['kanallar'][] = [
                'id'              => (int)$s['kanal_id'],
                'kod'             => $s['kanal_kod'],
                'ad'              => $s['kanal_ad'],
                'gonderim_tarihi' => $s['gonderim_tarihi'],
                'sonuc'           => $s['sonuc'],
            ];
        }
        return array_values($sonuc);
    }

    /**
     * Hatırlatmayı kaydeder.
     *
     * @param string|null $tarih    Y-m-d H:i:s; null → hatırlatma kaldırılır (alıcılar da silinir)
     * @param array|null  $alicilar [kullanici_id => [kanal_id, ...]]; null → alıcılara dokunulmaz,
     *                              [] → alıcı kaydı yok (varsayılan alıcı: atanan / oluşturan)
     */
    public static function kaydet(Database $db, int $gorevId, ?string $tarih, ?array $alicilar, int $islemKullaniciId): void
    {
        $now = app_now();

        if ($tarih !== null && $tarih <= $now) {
            throw new Exception('Hatırlatma tarihi geçmişte olamaz.');
        }

        $conn = $db->getConnection();
        sqlsrv_begin_transaction($conn);
        try {
            $db->update('Ticket_Gorevler', [
                'Ticket_Gorevler_hatirlatma_tarihi'          => $tarih,
                'Ticket_Gorevler_hatirlatma_gonderim_tarihi' => null,
                'GuncelleyenKullanici'                       => $islemKullaniciId,
                'GuncellemeTarihi'                           => $now,
            ], ['Ticket_Gorevler_id' => $gorevId]);

            if ($tarih === null) {
                self::calistir($db, "DELETE FROM dbo.Ticket_Gorev_Alicilari WHERE Ticket_Gorev_Alicilari_gorev_id = ?", [$gorevId]);
            } elseif ($alicilar !== null) {
                self::alicilariYaz($db, $gorevId, $alicilar, $islemKullaniciId, $now);
            } else {
                self::gonderimSifirla($db, $gorevId, $islemKullaniciId, $now);
            }

            sqlsrv_commit($conn);
        } catch (Throwable $e) {
            sqlsrv_rollback($conn);
            throw $e;
        }
    }

    /** Ertele: yeni tarih yazılır, sayaç artar, gönderim işaretleri sıfırlanır */
    public static function ertele(Database $db, int $gorevId, string $yeniTarih, int $islemKullaniciId): void
    {
        $now = app_now();
        if ($yeniTarih <= $now) throw new Exception('Erteleme tarihi geçmişte olamaz.');

        $conn = $db->getConnection();
        sqlsrv_begin_transaction($conn);
        try {
            self::calistir($db, "
                UPDATE dbo.Ticket_Gorevler
                SET Ticket_Gorevler_hatirlatma_tarihi          = ?,
                    Ticket_Gorevler_hatirlatma_gonderim_tarihi = NULL,
                    Ticket_Gorevler_erteleme_sayisi            = Ticket_Gorevler_erteleme_sayisi + 1,
                    GuncelleyenKullanici = ?,
                    GuncellemeTarihi     = ?
                WHERE Ticket_Gorevler_id = ?
            ", [$yeniTarih, $islemKullaniciId, $now, $gorevId]);

            self::gonderimSifirla($db, $gorevId, $islemKullaniciId, $now);
            sqlsrv_commit($conn);
        } catch (Throwable $e) {
            sqlsrv_rollback($conn);
            throw $e;
        }
    }

    /**
     * Alıcı listesini doğrular: aktif kullanıcı + aktif kanal; PUSH her alıcıya eklenir.
     * @return array [kullanici_id => [kanal_id, ...]]
     */
    public static function alicilariDogrula(Database $db, array $alicilar): array
    {
        $pushId    = self::pushKanalId($db);
        $kanalIdler = array_map('intval', array_column(self::kanallar($db), 'id'));
        $temiz = [];

        foreach ($alicilar as $kid => $kanallar) {
            $kid = (int)$kid;
            if ($kid <= 0) continue;

            $k = $db->fetchOne("SELECT kullanici_id FROM dbo.kullanicilar WHERE kullanici_id = ? AND kullanici_durum = 1", [$kid]);
            if (!$k) throw new Exception("Alıcı bulunamadı veya pasif (ID: {$kid}).");

            $liste = [$pushId];
            foreach ((array)$kanallar as $kanalId) {
                $kanalId = (int)$kanalId;
                if (!in_array($kanalId, $kanalIdler, true)) throw new Exception("Geçersiz kanal (ID: {$kanalId}).");
                $liste[] = $kanalId;
            }
            $temiz[$kid] = array_values(array_unique($liste));
        }
        return $temiz;
    }

    private static function alicilariYaz(Database $db, int $gorevId, array $alicilar, int $islemKullaniciId, string $now): void
    {
        $alicilar = self::alicilariDogrula($db, $alicilar);

        self::calistir($db, "DELETE FROM dbo.Ticket_Gorev_Alicilari WHERE Ticket_Gorev_Alicilari_gorev_id = ?", [$gorevId]);

        foreach ($alicilar as $kid => $kanallar) {
            foreach ($kanallar as $kanalId) {
                $ok = $db->insert('Ticket_Gorev_Alicilari', [
                    'Ticket_Gorev_Alicilari_gorev_id'     => $gorevId,
                    'Ticket_Gorev_Alicilari_kullanici_id' => $kid,
                    'Ticket_Gorev_Alicilari_kanal_id'     => $kanalId,
                    'OlusturanKullanici'                  => $islemKullaniciId,
                    'OlusturmaTarihi'                     => $now,
                    'GuncelleyenKullanici'                => $islemKullaniciId,
                    'GuncellemeTarihi'                    => $now,
                    'Durum'                               => 1,
                ]);
                if ($ok === false) throw new Exception('Hatırlatma alıcısı kaydedilemedi.');
            }
        }
    }

    private static function calistir(Database $db, string $sql, array $params = []): void
    {
        if ($db->execute($sql, $params) === false) {
            throw new Exception('Hatırlatma kaydı güncellenemedi.');
        }
    }

    private static function gonderimSifirla(Database $db, int $gorevId, int $islemKullaniciId, string $now): void
    {
        self::calistir($db, "
            UPDATE dbo.Ticket_Gorev_Alicilari
            SET Ticket_Gorev_Alicilari_gonderim_tarihi = NULL,
                Ticket_Gorev_Alicilari_sonuc           = NULL,
                GuncelleyenKullanici = ?,
                GuncellemeTarihi     = ?
            WHERE Ticket_Gorev_Alicilari_gorev_id = ?
        ", [$islemKullaniciId, $now, $gorevId]);
    }
}
