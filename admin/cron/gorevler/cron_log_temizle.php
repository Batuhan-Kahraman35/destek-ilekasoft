<?php
/**
 * Görev: cron_log_temizle
 *
 * CronCalismaLog tablosundaki eski kayıtları siler (retention).
 * Tek DELETE tabloyu kilitler ve transaction log'unu şişirir; partiler hâlinde silinir.
 *
 * Parametreler:  gun (int, varsayılan CronAyarlar.log_saklama_gun, alt sınır 7)
 */

function gorev_cron_log_temizle(array $params, $db): array
{
    ob_start();

    $gun = (int)($params['gun'] ?? cronAyar($db, 'log_saklama_gun', '90'));
    if ($gun < 7) {
        $gun = 7;   // güvenlik alt sınırı
    }

    $toplam = 0;
    $tur    = 0;

    do {
        $ok = $db->execute("
            DELETE TOP (5000) FROM dbo.CronCalismaLog
            WHERE CronCalismaLog_BaslangicTarihi < DATEADD(DAY, -?, GETDATE())
        ", [$gun]);

        if ($ok === false) {
            return [
                'durum' => 2,
                'sonuc' => "Silme sorgusu basarisiz ({$toplam} kayit silindikten sonra).",
                'cikti' => ob_get_clean(),
            ];
        }

        // @@ROWCOUNT bir sonraki sorguda sifirlanir: DELETE'ten hemen sonra okunmali
        $satir   = $db->fetchOne("SELECT @@ROWCOUNT AS Adet");
        $silinen = (int)($satir['Adet'] ?? 0);

        $toplam += $silinen;
        $tur++;

        if ($silinen > 0) {
            echo "  Parti {$tur}: {$silinen} kayit silindi.\n";
        }
    } while ($silinen === 5000 && $tur < 200);

    echo "[OK] {$gun} gunden eski {$toplam} log kaydi silindi.\n";

    return [
        'durum' => 1,
        'sonuc' => "{$toplam} kayit silindi ({$gun} gun)",
        'cikti' => ob_get_clean(),
    ];
}
