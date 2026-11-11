<?php
/**
 * Görev: gorev_hatirlatma_gonder
 *
 * Zamanı gelmiş ticket görevi hatırlatmalarını gönderir.
 *
 * Akış:
 *  1. Ticket_Gorevler_hatirlatma_tarihi <= şimdi ve gönderilmemiş görevler alınır.
 *  2. Görev önce "sahiplenilir" (gonderim_tarihi yazılır, OUTPUT ile kontrol edilir);
 *     aynı anda çalışan iki tur aynı hatırlatmayı iki kez göndermez.
 *  3. Görev tamamlanmışsa (durum kapatma) gönderilmez, yalnız işaretlenir.
 *  4. Alıcılar Ticket_Gorev_Alicilari'ndan (personel × kanal) okunur; kayıt yoksa
 *     görevin atananına, atanan yoksa oluşturana yalnız PUSH gider.
 *  5. Metinler Ticket_Mesaj_Sablonlari'ndan gelir (Sablon_Tip=bildirim, Sablon_Olay=gorev_hatirlatma,
 *     kanal başına bir şablon). Şablonu olmayan kanal atlanır ve sonuç alanına yazılır.
 *  6. Her alıcı-kanal sonucu Ticket_Gorev_Alicilari_sonuc / _gonderim_tarihi alanlarına yazılır.
 *
 * Şablon değişkenleri:
 *   {{gorev_baslik}} {{gorev_aciklama}} {{hatirlatma_tarihi}} {{erteleme_sayisi}} {{alici_ad}}
 *   {{ticket_no}} {{ticket_id}} {{konu}} {{link}}
 *
 * Parametreler:
 *   parti_boyutu  number  Bir turda işlenecek azami görev sayısı
 */

require_once __DIR__ . '/../../includes/GorevHatirlatma.php';
require_once __DIR__ . '/../../includes/Bildirim.php';
require_once __DIR__ . '/../../includes/EntegrasyonHelper.php';

const GOREV_HATIRLATMA_OLAY = 'gorev_hatirlatma';

function gorev_gorev_hatirlatma_gonder(array $params, $db): array
{
    ob_start();

    $partiBoyutu = (int)($params['parti_boyutu'] ?? 50);
    if ($partiBoyutu < 1)   $partiBoyutu = 1;
    if ($partiBoyutu > 500) $partiBoyutu = 500;

    $now = app_now();

    $bekleyenler = $db->fetchAll("
        SELECT TOP (?) g.Ticket_Gorevler_id
        FROM dbo.Ticket_Gorevler g
        WHERE g.Durum = 1
          AND g.Ticket_Gorevler_hatirlatma_tarihi IS NOT NULL
          AND g.Ticket_Gorevler_hatirlatma_gonderim_tarihi IS NULL
          AND g.Ticket_Gorevler_hatirlatma_tarihi <= ?
        ORDER BY g.Ticket_Gorevler_hatirlatma_tarihi
    ", [$partiBoyutu, $now]);

    if (empty($bekleyenler)) {
        echo "[OK] Zamani gelen hatirlatma yok.\n";
        return ['durum' => 1, 'sonuc' => 'Bekleyen hatirlatma yok', 'cikti' => ob_get_clean()];
    }

    /* --- Ortak veriler --------------------------------------------------- */
    $siteUrl = rtrim((string)($db->fetchOne(
        "SELECT TOP 1 site_ayarlari_site_url FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC"
    )['site_ayarlari_site_url'] ?? ''), '/');

    $sablonlar = [];
    foreach ($db->fetchAll("
        SELECT Sablon_Kanal, Sablon_Konu, Sablon_Icerik
        FROM dbo.Ticket_Mesaj_Sablonlari
        WHERE Durum = 1 AND Sablon_Tip = 'bildirim' AND Sablon_Olay = ?
        ORDER BY Sablon_Id
    ", [GOREV_HATIRLATMA_OLAY]) as $s) {
        $sablonlar[$s['Sablon_Kanal']] ??= $s;   // kanal başına ilk şablon
    }

    $kanallar = [];
    foreach (GorevHatirlatma::kanallar($db) as $k) {
        $kanallar[(int)$k['id']] = $k;
    }
    $pushId = GorevHatirlatma::pushKanalId($db);

    $entegrasyon = new EntegrasyonHelper($db);

    echo "Zamani gelen gorev: " . count($bekleyenler) . "\n";

    $gorevSayisi = 0;
    $atlanan     = 0;
    $basarili    = 0;
    $basarisiz   = 0;

    foreach ($bekleyenler as $b) {
        $gorevId = (int)$b['Ticket_Gorevler_id'];

        /* --- Sahiplen: başka tur almışsa satır dönmez ------------------- */
        $sahip = $db->fetchOne("
            UPDATE dbo.Ticket_Gorevler
            SET Ticket_Gorevler_hatirlatma_gonderim_tarihi = ?
            OUTPUT inserted.Ticket_Gorevler_id
            WHERE Ticket_Gorevler_id = ? AND Ticket_Gorevler_hatirlatma_gonderim_tarihi IS NULL
        ", [$now, $gorevId]);
        if (!$sahip) {
            echo "  #{$gorevId} baska turda islendi, atlandi\n";
            continue;
        }

        $g = $db->fetchOne("
            SELECT g.Ticket_Gorevler_id, g.Ticket_Gorevler_baslik, g.Ticket_Gorevler_aciklama,
                   g.Ticket_Gorevler_atanan_id, g.OlusturanKullanici, g.Ticket_Gorevler_erteleme_sayisi,
                   CONVERT(VARCHAR(16), g.Ticket_Gorevler_hatirlatma_tarihi, 120) AS hatirlatma_tarihi,
                   d.Ticket_Durumlar_kapatma_durumu AS tamamlandi,
                   t.Tickets_id, t.Tickets_no, t.Tickets_konu
            FROM dbo.Ticket_Gorevler g
            INNER JOIN dbo.Tickets t          ON t.Tickets_id = g.Ticket_Gorevler_ticket_id
            INNER JOIN dbo.Ticket_Durumlar d  ON d.Ticket_Durumlar_id = g.Ticket_Gorevler_durum_id
            WHERE g.Ticket_Gorevler_id = ?
        ", [$gorevId]);

        if (!$g) {
            echo "  #{$gorevId} gorev/ticket bulunamadi\n";
            $atlanan++;
            continue;
        }
        if ((int)$g['tamamlandi'] === 1) {
            echo "  #{$gorevId} gorev tamamlanmis, gonderilmedi\n";
            $atlanan++;
            continue;
        }

        $gorevSayisi++;

        /* --- Alıcılar: [kullanici_id => [kanal_id, ...]] ----------------- */
        $kayitliAlicilar = GorevHatirlatma::alicilariGetir($db, $gorevId);
        $alicilar = [];
        foreach ($kayitliAlicilar as $a) {
            $alicilar[(int)$a['kullanici_id']] = array_column($a['kanallar'], 'id');
        }
        $kayitli = !empty($alicilar);
        if (!$kayitli) {
            $varsayilan = (int)($g['Ticket_Gorevler_atanan_id'] ?: $g['OlusturanKullanici']);
            if ($varsayilan > 0) $alicilar[$varsayilan] = [$pushId];
        }

        if (empty($alicilar)) {
            echo "  #{$gorevId} alici yok\n";
            continue;
        }

        $link = $siteUrl . '/ticket-detay?id=' . (int)$g['Tickets_id'];

        foreach ($alicilar as $kid => $kanalIdler) {
            $kisi = $db->fetchOne("
                SELECT kullanici_id, kullanici_ad, kullanici_soyad, kullanici_email, kullanici_telefon
                FROM dbo.kullanicilar WHERE kullanici_id = ? AND kullanici_durum = 1
            ", [$kid]);

            $vars = [
                '{{gorev_baslik}}'      => (string)$g['Ticket_Gorevler_baslik'],
                '{{gorev_aciklama}}'    => (string)($g['Ticket_Gorevler_aciklama'] ?? ''),
                '{{hatirlatma_tarihi}}' => date('d.m.Y H:i', strtotime($g['hatirlatma_tarihi'])),
                '{{erteleme_sayisi}}'   => (string)(int)$g['Ticket_Gorevler_erteleme_sayisi'],
                '{{alici_ad}}'          => trim(($kisi['kullanici_ad'] ?? '') . ' ' . ($kisi['kullanici_soyad'] ?? '')),
                '{{ticket_no}}'         => (string)$g['Tickets_no'],
                '{{ticket_id}}'         => (string)$g['Tickets_id'],
                '{{konu}}'              => (string)$g['Tickets_konu'],
                '{{link}}'              => $link,
            ];

            foreach ($kanalIdler as $kanalId) {
                $kod = $kanallar[(int)$kanalId]['kod'] ?? '';
                [$ok, $mesaj] = hatirlatmaKanalGonder($db, $entegrasyon, $kod, $kisi, $sablonlar[$kod] ?? null, $vars, $g, $link);

                $ok ? $basarili++ : $basarisiz++;
                echo "  #{$gorevId} -> {$kid} [{$kod}] " . ($ok ? 'OK' : 'HATA') . ": {$mesaj}\n";

                if ($kayitli) {
                    $db->execute("
                        UPDATE dbo.Ticket_Gorev_Alicilari
                        SET Ticket_Gorev_Alicilari_gonderim_tarihi = ?,
                            Ticket_Gorev_Alicilari_sonuc           = ?,
                            GuncellemeTarihi = ?
                        WHERE Ticket_Gorev_Alicilari_gorev_id = ?
                          AND Ticket_Gorev_Alicilari_kullanici_id = ?
                          AND Ticket_Gorev_Alicilari_kanal_id = ?
                    ", [$ok ? app_now() : null, mb_substr(($ok ? 'OK: ' : 'HATA: ') . $mesaj, 0, 500), app_now(), $gorevId, $kid, (int)$kanalId]);
                }
            }
        }
    }

    echo "[OK] Gorev: {$gorevSayisi}, atlanan: {$atlanan}, basarili gonderim: {$basarili}, basarisiz: {$basarisiz}\n";

    return [
        'durum' => ($basarili === 0 && $basarisiz > 0) ? 2 : 1,
        'sonuc' => "{$gorevSayisi} gorev, {$basarili} gonderim basarili, {$basarisiz} basarisiz, {$atlanan} atlandi",
        'cikti' => ob_get_clean(),
    ];
}

/**
 * Tek alıcıya tek kanaldan gönderir.
 * @return array [bool basarili, string mesaj]
 */
function hatirlatmaKanalGonder(Database $db, EntegrasyonHelper $entegrasyon, string $kod, ?array $kisi, ?array $sablon, array $vars, array $g, string $link): array
{
    if (!$kisi)   return [false, 'Kullanici bulunamadi veya pasif'];
    if (!$sablon) return [false, "Sablon yok ({$kod} / " . GOREV_HATIRLATMA_OLAY . ")"];

    $doldur = fn(string $metin, bool $html = false) => str_replace(
        array_keys($vars),
        $html ? array_map(fn($v) => htmlspecialchars($v, ENT_QUOTES, 'UTF-8'), array_values($vars)) : array_values($vars),
        $metin
    );

    $konu   = $doldur((string)($sablon['Sablon_Konu'] ?? ''));
    $icerik = (string)$sablon['Sablon_Icerik'];

    try {
        switch ($kod) {
            case 'PUSH':
                $id = Bildirim::olustur($db, [
                    'kullanici_id' => (int)$kisi['kullanici_id'],
                    'baslik'       => $konu !== '' ? $konu : 'Görev Hatırlatması',
                    'govde'        => $doldur($icerik),
                    'url'          => $link,
                    'tip'          => 'uyari',
                    'ticket_id'    => (int)$g['Tickets_id'],
                    'olay'         => GOREV_HATIRLATMA_OLAY,
                    'push'         => true,
                    'olusturan'    => (int)$g['OlusturanKullanici'] ?: null,
                ]);
                return [true, "Bildirim #{$id}"];

            case 'MAIL':
                $eposta = trim((string)$kisi['kullanici_email']);
                if ($eposta === '') return [false, 'E-posta adresi yok'];
                $r = $entegrasyon->mailGonder($eposta, $konu, nl2br($doldur($icerik, true)));
                return [(bool)($r['success'] ?? false), (string)($r['message'] ?? '')];

            case 'WHATSAPP':
                $telefon = trim((string)$kisi['kullanici_telefon']);
                if ($telefon === '') return [false, 'Telefon yok'];
                $r = $entegrasyon->whatsappGonder($telefon, $doldur($icerik));
                return [(bool)($r['success'] ?? false), (string)($r['message'] ?? '')];

            default:
                return [false, "Desteklenmeyen kanal: {$kod}"];
        }
    } catch (Throwable $e) {
        return [false, $e->getMessage()];
    }
}
