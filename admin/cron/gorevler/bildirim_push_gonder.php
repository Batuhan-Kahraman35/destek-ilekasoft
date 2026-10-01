<?php
/**
 * Görev: bildirim_push_gonder
 *
 * Outbox deseni: Bildirimler tablosunda push'u istenmiş ama henüz gönderilememiş
 * (bildirim_push_gonderildi = 0) kayıtları yeniden gönderir.
 *
 * Bildirim oluşturulurken push zaten anında denenir; bu görev yalnızca o anda
 * ulaşılamayan (ağ hatası, push servisi 5xx) bildirimleri telafi eder. Başarılı
 * gönderim işaretçiyi 1 yaptığı için çift bildirim oluşmaz.
 *
 * Parametreler:
 *   parti_boyutu    number  Bir turda denenecek azami bildirim sayısı
 *   azami_yas_saat  number  Bu süreden eski bildirimler artık denenmez (bayat bildirim göndermemek için)
 */

require_once __DIR__ . '/../../includes/Bildirim.php';

function gorev_bildirim_push_gonder(array $params, $db): array
{
    ob_start();

    $partiBoyutu = (int)($params['parti_boyutu'] ?? 100);
    if ($partiBoyutu < 1)    $partiBoyutu = 1;
    if ($partiBoyutu > 1000) $partiBoyutu = 1000;

    $azamiYas = (int)($params['azami_yas_saat'] ?? 24);
    if ($azamiYas < 1) $azamiYas = 1;

    /* --- VAPID yoksa görev anlamsız ------------------------------------ */
    require_once __DIR__ . '/../../includes/PwaHelper.php';
    if (!PwaHelper::vapid($db)) {
        return [
            'durum' => 2,
            'sonuc' => 'VAPID yapilandirilmamis; push gonderilemez.',
            'cikti' => ob_get_clean(),
        ];
    }

    /* --- Bekleyen bildirimler ------------------------------------------
       Yalnızca aktif cihazı olan kullanıcıların kayıtları alınır; aksi hâlde
       hiç abone olmamış kullanıcıların bildirimleri her turda boşuna denenir.
       ------------------------------------------------------------------- */
    $bekleyenler = $db->fetchAll("
        SELECT TOP (?) b.bildirim_id, b.bildirim_kullanici_id, b.bildirim_baslik
        FROM dbo.Bildirimler b
        WHERE b.Durum = 1
          AND b.bildirim_push_gonder = 1
          AND b.bildirim_push_gonderildi = 0
          AND b.bildirim_kullanici_id IS NOT NULL
          AND b.OlusturmaTarihi >= DATEADD(HOUR, -?, GETDATE())
          AND EXISTS (
              SELECT 1 FROM dbo.Bildirim_Abonelikleri a
              WHERE a.abonelik_kullanici_id = b.bildirim_kullanici_id AND a.Durum = 1
          )
        ORDER BY b.bildirim_id
    ", [$partiBoyutu, $azamiYas]);

    if (empty($bekleyenler)) {
        echo "[OK] Bekleyen push bildirimi yok.\n";
        return [
            'durum' => 1,
            'sonuc' => 'Bekleyen bildirim yok',
            'cikti' => ob_get_clean(),
        ];
    }

    echo "Bekleyen bildirim: " . count($bekleyenler) . " (parti {$partiBoyutu}, azami yas {$azamiYas} saat)\n";

    $basarili = 0;
    $basarisiz = 0;
    $cihazToplam = 0;

    foreach ($bekleyenler as $b) {
        $bildirimId = (int)$b['bildirim_id'];

        try {
            $sonuc = Bildirim::push($db, $bildirimId);
        } catch (Throwable $e) {
            $basarisiz++;
            echo "  #{$bildirimId} HATA: " . $e->getMessage() . "\n";
            continue;
        }

        $gonderilen = (int)($sonuc['gonderilen'] ?? 0);
        if ($gonderilen > 0) {
            $basarili++;
            $cihazToplam += $gonderilen;
            echo "  #{$bildirimId} gonderildi ({$gonderilen} cihaz)\n";
        } else {
            $basarisiz++;
            echo "  #{$bildirimId} basarisiz: " . ($sonuc['hata'] ?? 'bilinmeyen') . "\n";
        }
    }

    echo "[OK] Basarili: {$basarili}, basarisiz: {$basarisiz}, toplam cihaz: {$cihazToplam}\n";

    return [
        // Tamamı başarısızsa uyarı durumu döner; kısmi başarı normal sayılır
        'durum' => ($basarili === 0 && $basarisiz > 0) ? 2 : 1,
        'sonuc' => "{$basarili} bildirim gonderildi ({$cihazToplam} cihaz), {$basarisiz} basarisiz",
        'cikti' => ob_get_clean(),
    ];
}
