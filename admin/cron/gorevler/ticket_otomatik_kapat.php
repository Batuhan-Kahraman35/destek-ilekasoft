<?php
/**
 * Görev: ticket_otomatik_kapat
 *
 * Belirtilen süre boyunca müşteri tarafından yanıtlanmayan destek taleplerini
 * otomatik olarak kapatır. Son mesajı panelden atılmış ve iç not olmayan
 * (yani müşteriye gönderilmiş bir yanıt olan) talepler kapatılır.
 *
 * Parametreler (hepsi zamanlayıcıdan seçilir, kodda sabit değer yoktur):
 *   saat             number       Kaç saat yanıt beklenecek
 *   hedef_durumlar   multiselect  Yalnızca bu durumlardaki talepler kapatılır (boş = tüm açık durumlar)
 *   kapanis_durum_id select       Talebin çekileceği durum (boş = ilk kapatma durumu)
 *   mesaj_sablon_id  select       Ticket'a eklenecek mesaj şablonu (boş = CronAyarlar'daki metin)
 */

require_once __DIR__ . '/../../includes/TicketService.php';

function gorev_ticket_otomatik_kapat(array $params, $db): array
{
    ob_start();

    $saat = (int)($params['saat'] ?? cronAyar($db, 'ticket_otomatik_kapat_saat', '12'));
    if ($saat < 1) {
        $saat = 1;
    }

    $islemKullaniciId = (int)cronAyar($db, 'ticket_otomatik_kapat_kullanici_id', '16');

    /* --- Mesaj metni: şablon seçildiyse ondan, yoksa ayar kaydından ---- */
    $sablonId   = (int)($params['mesaj_sablon_id'] ?? 0);
    $mesajMetni = '';
    $mesajKaynak = 'CronAyarlar';

    if ($sablonId > 0) {
        $sablon = $db->fetchOne(
            "SELECT Sablon_Ad, Sablon_Icerik FROM dbo.Ticket_Mesaj_Sablonlari WHERE Sablon_Id = ? AND Durum = 1",
            [$sablonId]
        );
        if (!$sablon) {
            return [
                'durum' => 2,
                'sonuc' => "Secilen mesaj sablonu bulunamadi veya pasif (Id: {$sablonId}).",
                'cikti' => ob_get_clean(),
            ];
        }
        $mesajMetni  = (string)$sablon['Sablon_Icerik'];
        $mesajKaynak = 'Sablon: ' . $sablon['Sablon_Ad'];
    } else {
        $mesajMetni = (string)cronAyar($db, 'ticket_otomatik_kapat_mesaj', '');
    }

    if (trim($mesajMetni) === '') {
        return [
            'durum' => 2,
            'sonuc' => 'Mesaj metni bos. Sablon secin veya CronAyarlar.ticket_otomatik_kapat_mesaj degerini doldurun.',
            'cikti' => ob_get_clean(),
        ];
    }

    /* --- Kapanış durumu ------------------------------------------------ */
    $kapanisDurumId = (int)($params['kapanis_durum_id'] ?? 0);

    if ($kapanisDurumId > 0) {
        $kontrol = $db->fetchOne("
            SELECT Ticket_Durumlar_id, Ticket_Durumlar_ad
            FROM dbo.Ticket_Durumlar
            WHERE Ticket_Durumlar_id = ? AND Durum = 1 AND Ticket_Durumlar_kapatma_durumu = 1
        ", [$kapanisDurumId]);

        if (!$kontrol) {
            return [
                'durum' => 2,
                'sonuc' => "Secilen kapanis durumu gecersiz: talebi kapatan bir durum degil (Id: {$kapanisDurumId}).",
                'cikti' => ob_get_clean(),
            ];
        }
        $kapanisDurumAd = (string)$kontrol['Ticket_Durumlar_ad'];
    } else {
        $varsayilan = $db->fetchOne("
            SELECT TOP 1 Ticket_Durumlar_id, Ticket_Durumlar_ad
            FROM dbo.Ticket_Durumlar
            WHERE Durum = 1 AND Ticket_Durumlar_kapatma_durumu = 1
            ORDER BY Ticket_Durumlar_sira ASC
        ");
        if (!$varsayilan) {
            return [
                'durum' => 2,
                'sonuc' => 'Kapanis durumu bulunamadi (Ticket_Durumlar).',
                'cikti' => ob_get_clean(),
            ];
        }
        $kapanisDurumId = (int)$varsayilan['Ticket_Durumlar_id'];
        $kapanisDurumAd = (string)$varsayilan['Ticket_Durumlar_ad'];
    }

    /* --- Hedef durumlar ------------------------------------------------ */
    $hedefDurumlar = $params['hedef_durumlar'] ?? [];
    if (is_string($hedefDurumlar)) {
        $hedefDurumlar = $hedefDurumlar === '' ? [] : explode(',', $hedefDurumlar);
    }
    $hedefDurumlar = array_values(array_filter(array_map('intval', (array)$hedefDurumlar)));

    $durumKosulu = '';
    $sorguParams = [$saat];

    if ($hedefDurumlar) {
        $durumKosulu = ' AND t.Tickets_durum_id IN (' . implode(',', array_fill(0, count($hedefDurumlar), '?')) . ')';
        $sorguParams = array_merge($sorguParams, $hedefDurumlar);
    }

    // Hedef durum secilmisse bu, "bu durumdakiler kosulsuz kapansin" iradesidir;
    // son mesaji kimin yazdigina bakilmaz. Secim yapilmamissa (tum acik durumlar
    // taranirken) yalnizca son yaniti biz vermissek kapatilir - aksi halde
    // musterinin cevaplanmamis sorusu kendi ihmalimiz yuzunden kapatilir.
    $sonMesajKontrolu = empty($hedefDurumlar);

    echo "Bekleme suresi   : {$saat} saat\n";
    echo "Kapanis durumu   : {$kapanisDurumAd} (#{$kapanisDurumId})\n";
    echo "Hedef durumlar   : " . ($hedefDurumlar ? implode(', ', $hedefDurumlar) : 'tum acik durumlar') . "\n";
    echo "Kapatma kurali   : " . ($sonMesajKontrolu ? 'yalnizca son yanit bizdense' : 'son yazan kim olursa olsun') . "\n";
    echo "Mesaj kaynagi    : {$mesajKaynak}\n\n";

    /* --- Aday talepler -------------------------------------------------- */
    // Referans tarih kademeli secilir: Tickets_son_yanit_tarihi eski kayitlarda NULL
    // olabiliyor; NULL bir tarih hicbir karsilastirmayi gecmedigi icin o talepler
    // sessizce elenirdi. Sirasiyla son yanit -> son musteri mesaji -> talep acilisi.
    $tickets = $db->fetchAll("
        SELECT t.Tickets_id
        FROM Tickets t
        INNER JOIN Ticket_Durumlar d ON t.Tickets_durum_id = d.Ticket_Durumlar_id
        WHERE d.Ticket_Durumlar_kapatma_durumu = 0
          AND t.Durum = 1
          AND COALESCE(
                  t.Tickets_son_yanit_tarihi,
                  (SELECT MAX(m.OlusturmaTarihi)
                     FROM Ticket_Mesajlar m
                    WHERE m.Ticket_Mesajlar_ticket_id = t.Tickets_id
                      AND m.Durum = 1
                      AND m.Ticket_Mesajlar_ic_not = 0),
                  t.OlusturmaTarihi
              ) < DATEADD(HOUR, -?, GETDATE())
          {$durumKosulu}
    ", $sorguParams);

    echo "Aday talep sayisi: " . count($tickets) . "\n";

    // İşlem kullanıcısının adı
    $islemKullanicisi = $db->fetchOne(
        "SELECT kullanici_ad, kullanici_soyad FROM kullanicilar WHERE kullanici_id = ?",
        [$islemKullaniciId]
    );
    $yazanAd    = $islemKullanicisi['kullanici_ad']    ?? 'Sistem';
    $yazanSoyad = $islemKullanicisi['kullanici_soyad'] ?? '';

    $ticketService = new TicketService($db);
    $sayac  = 0;
    $atlama = 0;

    foreach ($tickets as $ticket) {
        $ticketId = (int)$ticket['Tickets_id'];

        if ($sonMesajKontrolu) {
            // Konuşan tarafı belirlerken iç notlar hariç tutulur: iç not müşteriye
            // gitmez, dolayısıyla "son sözü kim söyledi" sorusunu etkilememelidir.
            // Aynı saniyeye denk gelen iki mesajda doğru olanı seçmek için Id ile ikinci sıralama.
            $sonMesaj = $db->fetchOne("
                SELECT TOP 1 Ticket_Mesajlar_kaynak
                FROM Ticket_Mesajlar
                WHERE Ticket_Mesajlar_ticket_id = ?
                  AND Durum = 1
                  AND Ticket_Mesajlar_ic_not = 0
                ORDER BY OlusturmaTarihi DESC, Ticket_Mesajlar_id DESC
            ", [$ticketId]);

            // Müşteriye giden son mesaj panelden atılmışsa (top müşteride) kapat
            if (!$sonMesaj || trim((string)$sonMesaj['Ticket_Mesajlar_kaynak']) !== 'panel') {
                $atlama++;
                continue;
            }
        }

        $ticketService->ticketMesajEkle([
            'ticket_id'          => $ticketId,
            'mesaj'              => $mesajMetni,
            'ic_not'             => 0,
            'yazan_kullanici_id' => $islemKullaniciId,
            'islem_kullanici_id' => $islemKullaniciId,
            'yazan_ad'           => $yazanAd,
            'yazan_soyad'        => $yazanSoyad,
            'mesaj_kaynak'       => 'panel',
        ]);

        $db->update('Tickets', [
            'Tickets_durum_id'          => $kapanisDurumId,
            'Tickets_kapanis_tarihi'    => app_now(),
            'Tickets_musteri_cevap_var' => 0,
            'GuncelleyenKullanici'      => $islemKullaniciId,
            'GuncellemeTarihi'          => app_now(),
        ], ['Tickets_id' => $ticketId]);

        echo "  [KAPATILDI] Ticket #{$ticketId}\n";
        $sayac++;
    }

    echo "Atlanan (son yanit musteriden): {$atlama}\n";
    echo "[OK] {$sayac} talep otomatik kapatildi.\n";

    return [
        'durum' => 1,
        'sonuc' => "{$sayac} talep kapatildi, {$atlama} atlandi ({$saat} saat, hedef: {$kapanisDurumAd})",
        'cikti' => ob_get_clean(),
    ];
}
