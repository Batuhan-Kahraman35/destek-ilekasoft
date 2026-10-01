<?php
/**
 * Cron Yönetim Sistemi - Worker (tek görev manuel çalıştırma)
 *
 *   CLI : php admin/cron/worker.php gorev=ticket_otomatik_kapat saat=12
 *         php admin/cron/worker.php zamanlama=1
 *   Web : https://DOMAIN/admin/cron/worker.php?gorev=KODU&key=SECRET&saat=12
 *         https://DOMAIN/admin/cron/worker.php?zamanlama=1&key=SECRET
 *
 * Uzun görevleri CLI'dan çalıştırın: web isteği IIS/FastCGI timeout'una takılır.
 */

require_once __DIR__ . '/tasks.php';

ignore_user_abort(true);
set_time_limit(600);

$cli = (PHP_SAPI === 'cli');
if (!$cli) {
    header('Content-Type: text/plain; charset=utf-8');
}

$db = Database::getInstance();
cronKeyDogrula($db);

/* -------------------------------------------------------------------
   Shutdown handler (runner.php ile aynı gerekçe - Bölüm 7.1)
   ------------------------------------------------------------------- */
$aktifLog = ['id' => null, 'bas' => null];

register_shutdown_function(function () use (&$aktifLog) {
    if (!$aktifLog['id']) {
        return;
    }

    $hata = error_get_last();
    $msg  = $hata && in_array($hata['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)
        ? 'Fatal: ' . $hata['message']
        : 'Gorev yarida kesildi (zaman asimi veya process sonlandirildi).';

    try {
        cronLogBitir(Database::getInstance(), (int)$aktifLog['id'], 2, $msg, (float)$aktifLog['bas']);
    } catch (Throwable) {
        // sessiz geç
    }
});

/* -------------------------------------------------------------------
   Girdi: CLI argümanları veya GET parametreleri
   ------------------------------------------------------------------- */
$girdi = $_GET;

if ($cli) {
    $girdi = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (str_contains($arg, '=')) {
            [$k, $v] = explode('=', $arg, 2);
            $girdi[trim($k)] = $v;
        }
    }
}

$gorevKodu  = trim((string)($girdi['gorev'] ?? ''));
$zamanlamaId = (int)($girdi['zamanlama'] ?? 0);

if ($gorevKodu === '' && $zamanlamaId <= 0) {
    exit("Kullanim: worker.php gorev=GOREV_KODU [param=deger ...]  |  worker.php zamanlama=ID\n");
}

/* -------------------------------------------------------------------
   Görev ve parametreleri çöz
   ------------------------------------------------------------------- */
$paramGirdi = $girdi;
unset($paramGirdi['gorev'], $paramGirdi['zamanlama'], $paramGirdi['key']);

if ($zamanlamaId > 0) {
    // Zamanlama tetiği: parametreler DB'deki SabitParametreler alanından gelir
    $z = $db->fetchOne("
        SELECT z.CronZamanlamalar_Id, z.CronZamanlamalar_Ad, z.CronZamanlamalar_GorevId,
               z.CronZamanlamalar_SabitParametreler,
               g.CronGorevler_GorevKodu, g.CronGorevler_Ad, g.CronGorevler_MaxSureSn
        FROM dbo.CronZamanlamalar z
        INNER JOIN dbo.CronGorevler g ON g.CronGorevler_Id = z.CronZamanlamalar_GorevId
        WHERE z.CronZamanlamalar_Id = ? AND z.Durum = 1 AND g.Durum = 1
    ", [$zamanlamaId]);

    if (!$z) {
        exit("Zamanlama bulunamadi veya pasif: {$zamanlamaId}\n");
    }

    $gorevKodu = (string)$z['CronGorevler_GorevKodu'];
    $gorevId   = (int)$z['CronZamanlamalar_GorevId'];
    $maxSureSn = (int)$z['CronGorevler_MaxSureSn'];
    $gorevAdi  = (string)$z['CronGorevler_Ad'];
    $params    = dinamikParamCoz(json_decode($z['CronZamanlamalar_SabitParametreler'] ?? '{}', true) ?: []);
} else {
    $gorev = cronGorevGetir($db, $gorevKodu);
    if (!$gorev) {
        exit("Gorev bulunamadi veya pasif: {$gorevKodu}\n");
    }

    $gorevId     = (int)$gorev['CronGorevler_Id'];
    $maxSureSn   = (int)$gorev['CronGorevler_MaxSureSn'];
    $gorevAdi    = (string)$gorev['CronGorevler_Ad'];
    $zamanlamaId = null;

    try {
        $params = dinamikParamCoz(cronParamDogrula($gorev['CronGorevler_Parametreler'], $paramGirdi));
    } catch (Throwable $e) {
        exit('Parametre hatasi: ' . $e->getMessage() . "\n");
    }
}

/* -------------------------------------------------------------------
   Overlap koruması
   ------------------------------------------------------------------- */
$acikKayit = cronCalisanKayit($db, $gorevId, $maxSureSn);
if ($acikKayit !== null) {
    exit("Bu gorev su anda calisiyor (baslangic: {$acikKayit['Baslangic']}). Tetik atlandi.\n");
}

/* -------------------------------------------------------------------
   Çalıştır
   ------------------------------------------------------------------- */
$tetikTur = $cli ? 1 : 0;
$logId    = cronLogOlustur($db, $gorevId, $zamanlamaId ?: null, $params, $tetikTur);
$bas      = microtime(true);

$aktifLog = ['id' => $logId, 'bas' => $bas];
set_time_limit($maxSureSn);

echo "Gorev: {$gorevAdi} ({$gorevKodu})\n";
echo "Parametreler: " . ($params ? json_encode($params, JSON_UNESCAPED_UNICODE) : '-') . "\n";
echo str_repeat('-', 60) . "\n";

try {
    $sonuc = gorevCalistir($gorevKodu, $params, $db);
    cronLogBitir($db, $logId, $sonuc['durum'], $sonuc['sonuc'], $bas, $sonuc['cikti']);

    echo $sonuc['cikti'];
    echo str_repeat('-', 60) . "\n";
    echo ($sonuc['durum'] === 1 ? '[OK] ' : '[HATA] ') . $sonuc['sonuc'] . "\n";
    echo 'Sure: ' . round(microtime(true) - $bas, 2) . " sn | Log Id: {$logId}\n";
} catch (Throwable $e) {
    cronLogBitir($db, $logId, 2, 'HATA: ' . get_class($e) . ': ' . $e->getMessage(), $bas);
    error_log('Cron worker hatasi (' . $gorevKodu . '): ' . $e->getMessage());
    echo '[HATA] ' . $e->getMessage() . "\n";
}

$aktifLog = ['id' => null, 'bas' => null];
