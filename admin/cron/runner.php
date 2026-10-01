<?php
/**
 * Cron Yönetim Sistemi - Runner
 *
 * Plesk Cron tarafından DAKİKADA BİR çağrılır. Aktif zamanlamaları tarar,
 * cron ifadesi eşleşenleri (ve telafi gerekenleri) sırayla çalıştırır.
 *
 *   CLI : php admin/cron/runner.php
 *   Web : https://DOMAIN/admin/cron/runner.php?key=SECRET
 */

require_once __DIR__ . '/tasks.php';

ignore_user_abort(true);
set_time_limit(600);            // runner toplam sınırı; her görev kendi limitini ayrıca uygular

$cli = (PHP_SAPI === 'cli');
if (!$cli) {
    header('Content-Type: text/plain; charset=utf-8');
}

$db = Database::getInstance();
cronKeyDogrula($db);

/* -------------------------------------------------------------------
   Shutdown handler - zombiyi doğuran anda yakala
   set_time_limit aşımı ve fatal error yakalanabilir Throwable üretmez;
   bu durumda cronLogBitir() hiç çağrılmaz ve log CalismaDurum=0 asılı kalır.
   ------------------------------------------------------------------- */
$aktifLog = ['id' => null, 'bas' => null, 'ad' => null];

register_shutdown_function(function () use (&$aktifLog) {
    if (!$aktifLog['id']) {
        return;   // açık log yok, normal çıkış
    }

    $hata = error_get_last();
    $msg  = $hata && in_array($hata['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)
        ? 'Fatal: ' . $hata['message']
        : 'Gorev yarida kesildi (zaman asimi veya process sonlandirildi).';

    try {
        cronLogBitir(Database::getInstance(), (int)$aktifLog['id'], 2, $msg, (float)$aktifLog['bas']);
    } catch (Throwable) {
        // shutdown sırasında DB bağlantısı da düşmüş olabilir; log kaybını sessiz geç
    }
});

/* -------------------------------------------------------------------
   Aktif zamanlamalar
   ------------------------------------------------------------------- */
$simdi    = new DateTime();
$buDakika = $simdi->format('Y-m-d H:i:00');

$zamanlamalar = $db->fetchAll("
    SELECT
        z.CronZamanlamalar_Id,
        z.CronZamanlamalar_GorevId,
        z.CronZamanlamalar_Ad,
        z.CronZamanlamalar_CronIfadesi,
        z.CronZamanlamalar_SabitParametreler,
        z.CronZamanlamalar_TelafiEt,
        z.CronZamanlamalar_TelafiSaatSiniri,
        CONVERT(VARCHAR(19), z.CronZamanlamalar_SonCalisma, 120) AS SonCalismaMetin,
        g.CronGorevler_GorevKodu,
        g.CronGorevler_Ad AS GorevAdi,
        g.CronGorevler_MaxSureSn
    FROM dbo.CronZamanlamalar z
    INNER JOIN dbo.CronGorevler g ON g.CronGorevler_Id = z.CronZamanlamalar_GorevId
    WHERE z.Durum = 1
      AND g.Durum = 1
      AND z.CronZamanlamalar_BaslangicTarihi <= GETDATE()
      AND (z.CronZamanlamalar_BitisTarihi IS NULL OR z.CronZamanlamalar_BitisTarihi > GETDATE())
    ORDER BY z.CronZamanlamalar_Id
");

echo "[{$buDakika}] Taranan zamanlama: " . count($zamanlamalar) . "\n";

$calisan = 0;

foreach ($zamanlamalar as $z) {
    $ifade      = (string)$z['CronZamanlamalar_CronIfadesi'];
    $gorevId    = (int)$z['CronZamanlamalar_GorevId'];
    $maxSureSn  = (int)$z['CronGorevler_MaxSureSn'];
    $etiket     = $z['CronZamanlamalar_Ad'] . ' (' . $z['CronGorevler_GorevKodu'] . ')';

    // 1) Normal eşleşme
    $tetik  = cronEslesiyor($ifade, $simdi);
    $telafi = false;

    // 2) Eşleşme yoksa telafi kontrolü
    if (!$tetik && (int)$z['CronZamanlamalar_TelafiEt'] === 1) {
        $telafi = cronKacirilanTetik(
            $ifade,
            $z['SonCalismaMetin'],
            (int)$z['CronZamanlamalar_TelafiSaatSiniri'],
            $simdi
        );
        $tetik = $telafi;
    }

    if (!$tetik) {
        continue;
    }

    // 3) Aynı dakika çift tetik kilidi
    if (!empty($z['SonCalismaMetin']) && $z['SonCalismaMetin'] >= $buDakika) {
        echo "  ATLANDI (ayni dakika calismis): {$etiket}\n";
        continue;
    }

    // 4) Overlap koruması - SonCalisma güncellemesinden ÖNCE
    $acikKayit = cronCalisanKayit($db, $gorevId, $maxSureSn);
    if ($acikKayit !== null) {
        echo "  ATLANDI (gorev calisiyor, baslangic: {$acikKayit['Baslangic']}): {$etiket}\n";
        continue;
    }

    // 5) Kilidi al
    $db->update('CronZamanlamalar', [
        'CronZamanlamalar_SonCalisma' => app_now(),
        'GuncellemeTarihi'            => app_now(),
    ], ['CronZamanlamalar_Id' => (int)$z['CronZamanlamalar_Id']]);

    // 6) Çalıştır
    $params = dinamikParamCoz(json_decode($z['CronZamanlamalar_SabitParametreler'] ?? '{}', true) ?: []);
    $logId  = cronLogOlustur($db, $gorevId, (int)$z['CronZamanlamalar_Id'], $params, 1);
    $bas    = microtime(true);

    $aktifLog = ['id' => $logId, 'bas' => $bas, 'ad' => $z['CronZamanlamalar_Ad']];
    set_time_limit($maxSureSn);   // her görevde sayacı sıfırla (runner sıralı çalışır)

    $onek = $telafi ? '[TELAFI] ' : '';
    echo "  CALISIYOR: {$onek}{$etiket}\n";

    try {
        $sonuc = gorevCalistir((string)$z['CronGorevler_GorevKodu'], $params, $db);
        cronLogBitir($db, $logId, $sonuc['durum'], $onek . $sonuc['sonuc'], $bas, $sonuc['cikti']);
        echo "  " . ($sonuc['durum'] === 1 ? 'OK' : 'HATA') . ": " . $sonuc['sonuc'] . "\n";
    } catch (Throwable $e) {
        cronLogBitir($db, $logId, 2, 'HATA: ' . get_class($e) . ': ' . $e->getMessage(), $bas);
        error_log('Cron gorev hatasi (' . $z['CronGorevler_GorevKodu'] . '): ' . $e->getMessage());
        echo "  HATA: " . $e->getMessage() . "\n";
    }

    $aktifLog = ['id' => null, 'bas' => null, 'ad' => null];   // ZORUNLU: handler son görevi ezmesin
    $calisan++;
}

echo "[{$buDakika}] Calistirilan gorev: {$calisan}\n";
