<?php
/**
 * Cron Yönetim Sistemi - Ortak Kütüphane
 *
 * Cron ifade ayrıştırıcı, log yardımcıları, ayar okuma, overlap koruması,
 * telafi (catch-up) kontrolü ve görev dispatcher'ı burada toplanır.
 * runner.php, worker.php ve admin/pages/cron-yonetimi.php bu dosyayı include eder.
 */

require_once __DIR__ . '/../db.php';

/* ===================================================================
   AYARLAR
   =================================================================== */

/**
 * CronAyarlar tablosundan tek bir ayarı okur (istek bazlı cache'li).
 */
function cronAyar($db, string $anahtar, ?string $varsayilan = null): ?string
{
    static $cache = [];
    if (array_key_exists($anahtar, $cache)) {
        return $cache[$anahtar];
    }

    $satir = $db->fetchOne(
        "SELECT CronAyarlar_Deger FROM dbo.CronAyarlar WHERE CronAyarlar_Anahtar = ? AND Durum = 1",
        [$anahtar]
    );

    $deger = $satir['CronAyarlar_Deger'] ?? null;
    if ($deger === null || $deger === '') {
        $deger = $varsayilan;
    }

    return $cache[$anahtar] = $deger;
}

/**
 * Web üzerinden erişimde gizli anahtarı doğrular. CLI'da anahtar istenmez.
 */
function cronKeyDogrula($db): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }

    $beklenen = (string)cronAyar($db, 'cron_secret_key', '');
    $gelen    = (string)($_GET['key'] ?? '');

    if ($beklenen === '' || !hash_equals($beklenen, $gelen)) {
        http_response_code(403);
        exit('Yetkisiz erisim.');
    }
}

/* ===================================================================
   CRON İFADE AYRIŞTIRICI
   =================================================================== */

/**
 * 5 alanlı cron ifadesi verilen zamanla eşleşiyor mu?
 * Desteklenen: *, sabit değer, liste (0,30), aralık (1-5), adım (*\/15, 10-50/5)
 */
function cronEslesiyor(string $ifade, DateTime $dt): bool
{
    $parts = preg_split('/\s+/', trim($ifade));
    if (count($parts) !== 5) {
        return false;
    }

    [$dk, $sa, $gun, $ay, $hgn] = $parts;

    return cronAlanEslesiyor($dk,  (int)$dt->format('i'))
        && cronAlanEslesiyor($sa,  (int)$dt->format('G'))
        && cronAlanEslesiyor($gun, (int)$dt->format('j'))
        && cronAlanEslesiyor($ay,  (int)$dt->format('n'))
        && cronAlanEslesiyor($hgn, (int)$dt->format('w'));   // 0 = Pazar
}

function cronAlanEslesiyor(string $alan, int $deger): bool
{
    $alan = trim($alan);
    if ($alan === '*') {
        return true;
    }

    foreach (explode(',', $alan) as $parca) {
        $parca = trim($parca);
        if ($parca === '') {
            continue;
        }

        if (str_contains($parca, '/')) {
            [$aralik, $adim] = explode('/', $parca, 2);
            $adim = (int)$adim;
            if ($adim <= 0) {
                continue;
            }
            [$bas, $son] = $aralik === '*' ? [0, 59] : array_map('intval', array_pad(explode('-', $aralik), 2, 59));
            if ($deger >= $bas && $deger <= $son && ($deger - $bas) % $adim === 0) {
                return true;
            }
        } elseif (str_contains($parca, '-')) {
            [$bas, $son] = array_map('intval', explode('-', $parca));
            if ($deger >= $bas && $deger <= $son) {
                return true;
            }
        } elseif ((int)$parca === $deger) {
            return true;
        }
    }

    return false;
}

/**
 * Cron ifadesi geçerli mi? (panel form doğrulaması için)
 */
function cronIfadeGecerliMi(string $ifade): bool
{
    $parts = preg_split('/\s+/', trim($ifade));
    if (count($parts) !== 5) {
        return false;
    }

    foreach ($parts as $alan) {
        if (!preg_match('/^(\*|\d+)([-\/]\d+)?(,(\*|\d+)([-\/]\d+)?)*$/', trim($alan))) {
            return false;
        }
    }

    return true;
}

/* ===================================================================
   DİNAMİK TARİH PARAMETRELERİ
   =================================================================== */

/**
 * {dun}, {ay_basi} gibi yer tutucuları çalışma anındaki tarihe çevirir.
 */
function dinamikTarih(string $deger): string
{
    $bugun = new DateTime();

    $map = [
        '{bugun}'         => fn() => $bugun->format('d.m.Y'),
        '{dun}'           => fn() => (clone $bugun)->modify('-1 day')->format('d.m.Y'),
        '{7gun_once}'     => fn() => (clone $bugun)->modify('-7 days')->format('d.m.Y'),
        '{30gun_once}'    => fn() => (clone $bugun)->modify('-30 days')->format('d.m.Y'),
        '{ay_basi}'       => fn() => $bugun->format('01.m.Y'),
        '{ay_sonu}'       => fn() => (new DateTime('last day of this month'))->format('d.m.Y'),
        '{gecen_ay_basi}' => fn() => (new DateTime('first day of last month'))->format('d.m.Y'),
        '{gecen_ay_sonu}' => fn() => (new DateTime('last day of last month'))->format('d.m.Y'),
        '{3ay_once}'      => fn() => (clone $bugun)->modify('-3 months')->format('d.m.Y'),
        '{6ay_once}'      => fn() => (clone $bugun)->modify('-6 months')->format('d.m.Y'),
    ];

    return isset($map[$deger]) ? ($map[$deger])() : $deger;
}

function dinamikParamCoz(array $params): array
{
    return array_map(fn($v) => is_string($v) ? dinamikTarih($v) : $v, $params);
}

/* ===================================================================
   LOG YARDIMCILARI
   =================================================================== */

/**
 * Çalışma logunu açar (CalismaDurum = 0).
 *
 * @param int $tetikTur 1 = otomatik/CLI, 0 = manuel/web
 * @return int Oluşan log kaydının ID'si
 */
function cronLogOlustur($db, int $gorevId, ?int $zamanlamaId, array $params, int $tetikTur, ?int $kullanici = null): int
{
    $simdi = app_now();

    return (int)$db->insert('CronCalismaLog', [
        'CronCalismaLog_GorevId'             => $gorevId,
        'CronCalismaLog_ZamanlamaId'         => $zamanlamaId,
        'CronCalismaLog_BaslangicTarihi'     => $simdi,
        'CronCalismaLog_Parametreler'        => $params ? json_encode($params, JSON_UNESCAPED_UNICODE) : null,
        'CronCalismaLog_CalismaDurum'        => 0,
        'CronCalismaLog_TetikleyenTur'       => $tetikTur,
        'CronCalismaLog_TetikleyenKullanici' => $kullanici,
        'OlusturanKullanici'                 => (int)($kullanici ?? 0),
        'OlusturmaTarihi'                    => $simdi,
        'GuncelleyenKullanici'               => (int)($kullanici ?? 0),
        'GuncellemeTarihi'                   => $simdi,
        'Durum'                              => 1,
    ]);
}

/**
 * Çalışma logunu kapatır.
 *
 * @param int $durum 1 = başarılı, 2 = hata
 */
function cronLogBitir($db, int $logId, int $durum, string $sonuc, float $basZaman, string $cikti = ''): void
{
    $simdi = app_now();

    $db->update('CronCalismaLog', [
        'CronCalismaLog_BitisTarihi'  => $simdi,
        'CronCalismaLog_SureSaniye'   => (int)(microtime(true) - $basZaman),
        'CronCalismaLog_CalismaDurum' => $durum,
        'CronCalismaLog_Sonuc'        => mb_substr($sonuc, 0, 2000),
        'CronCalismaLog_Cikti'        => $cikti !== '' ? mb_substr($cikti, 0, 100000) : null,
        'GuncellemeTarihi'            => $simdi,
    ], ['CronCalismaLog_Id' => $logId]);
}

/* ===================================================================
   OVERLAP KORUMASI
   =================================================================== */

/**
 * Görevin açık kalmış (CalismaDurum = 0) log kaydını döner.
 * Zombi kayıtlar (MaxSureSn x 1.5 aşılmış) otomatik kapatılır ve null dönülür.
 *
 * @return array|null ['LogId' => int, 'GecenSaniye' => int, 'Baslangic' => string]
 */
function cronCalisanKayit($db, int $gorevId, int $maxSureSn): ?array
{
    $kayit = $db->fetchOne("
        SELECT TOP 1
            CronCalismaLog_Id AS LogId,
            DATEDIFF(SECOND, CronCalismaLog_BaslangicTarihi, GETDATE()) AS GecenSaniye,
            CONVERT(VARCHAR(19), CronCalismaLog_BaslangicTarihi, 120) AS Baslangic
        FROM dbo.CronCalismaLog
        WHERE CronCalismaLog_GorevId = ?
          AND CronCalismaLog_CalismaDurum = 0
        ORDER BY CronCalismaLog_Id DESC
    ", [$gorevId]);

    if (!$kayit) {
        return null;
    }

    // Zombi eşiği: görevin kendi max süresinin 1.5 katı (en az 120 sn)
    $zombiEsik = max(120, (int)($maxSureSn * 1.5));

    if ((int)$kayit['GecenSaniye'] > $zombiEsik) {
        $simdi = app_now();
        $db->update('CronCalismaLog', [
            'CronCalismaLog_BitisTarihi'  => $simdi,
            'CronCalismaLog_SureSaniye'   => (int)$kayit['GecenSaniye'],
            'CronCalismaLog_CalismaDurum' => 2,
            'CronCalismaLog_Sonuc'        => 'Zombi kayit: ' . $zombiEsik . ' sn asildi, otomatik kapatildi.',
            'GuncellemeTarihi'            => $simdi,
        ], ['CronCalismaLog_Id' => (int)$kayit['LogId']]);

        return null;   // yol açıldı, yeni çalışma başlayabilir
    }

    return [
        'LogId'       => (int)$kayit['LogId'],
        'GecenSaniye' => (int)$kayit['GecenSaniye'],
        'Baslangic'   => (string)$kayit['Baslangic'],
    ];
}

/**
 * Görev şu anda çalışıyor mu? Çalışıyorsa tetik atlanmalıdır.
 * Kontrol GorevId bazlıdır: aynı göreve bağlı iki zamanlama da aynı kaynağa yazar.
 */
function cronCalisiyorMu($db, int $gorevId, int $maxSureSn): bool
{
    return cronCalisanKayit($db, $gorevId, $maxSureSn) !== null;
}

/* ===================================================================
   CATCH-UP (TELAFİ)
   =================================================================== */

/**
 * Son çalışmadan bu yana kaçırılmış bir tetik var mı?
 * Kaçırılan tetik sayısı ne olursa olsun EN FAZLA BİR KEZ telafi edilir.
 * SonCalisma NULL ise telafi YAPILMAZ: telafi bir referans noktası gerektirir,
 * aksi halde yeni eklenen zamanlama kurulum anında tetiklenir.
 */
function cronKacirilanTetik(string $ifade, ?string $sonCalisma, int $sinirSaat, DateTime $simdi): bool
{
    if ($sonCalisma === null || $sonCalisma === '') {
        return false;
    }

    if ($sinirSaat < 1) {
        $sinirSaat = 1;
    }

    $enErken = (clone $simdi)->modify("-{$sinirSaat} hours");

    $tarama = new DateTime($sonCalisma);
    $tarama->setTime((int)$tarama->format('G'), (int)$tarama->format('i'), 0);
    $tarama->modify('+1 minute');

    // Çok eski SonCalisma değerlerinde döngüyü sınırla
    if ($tarama < $enErken) {
        $tarama = clone $enErken;
    }

    $simdiDakika = (clone $simdi)->setTime((int)$simdi->format('G'), (int)$simdi->format('i'), 0);
    $adim = 0;

    while ($tarama < $simdiDakika && $adim < ($sinirSaat * 60) + 1) {
        if (cronEslesiyor($ifade, $tarama)) {
            return true;
        }
        $tarama->modify('+1 minute');
        $adim++;
    }

    return false;
}

/* ===================================================================
   GÖREV DISPATCHER
   =================================================================== */

/**
 * Görev kodundan görev satırını çeker.
 */
function cronGorevGetir($db, string $gorevKodu): ?array
{
    $satir = $db->fetchOne(
        "SELECT * FROM dbo.CronGorevler WHERE CronGorevler_GorevKodu = ? AND Durum = 1",
        [$gorevKodu]
    );

    return $satir ?: null;
}

/**
 * Görev kodunu gorevler/<kod>.php dosyasındaki gorev_<kod>() fonksiyonuna eşler.
 *
 * Görev sözleşmesi:
 *   function gorev_ornek(array $params, $db): array
 *       => ['durum' => 1|2, 'sonuc' => 'özet', 'cikti' => 'tam çıktı']
 *
 * @throws RuntimeException Görev kodu geçersiz, dosya yok veya fonksiyon tanımsızsa
 */
function gorevCalistir(string $gorevKodu, array $params, $db): array
{
    if (!preg_match('/^[a-z0-9_]+$/', $gorevKodu)) {
        throw new RuntimeException('Gecersiz gorev kodu: ' . $gorevKodu);
    }

    $dosya = __DIR__ . '/gorevler/' . $gorevKodu . '.php';
    if (!is_file($dosya)) {
        throw new RuntimeException('Gorev dosyasi bulunamadi: gorevler/' . $gorevKodu . '.php');
    }

    require_once $dosya;

    $fonksiyon = 'gorev_' . $gorevKodu;
    if (!function_exists($fonksiyon)) {
        throw new RuntimeException('Gorev fonksiyonu tanimli degil: ' . $fonksiyon . '()');
    }

    $sonuc = $fonksiyon($params, $db);

    if (!is_array($sonuc) || !isset($sonuc['durum'])) {
        throw new RuntimeException('Gorev beklenen dizi yapisini dondurmedi: ' . $fonksiyon . '()');
    }

    return [
        'durum' => (int)$sonuc['durum'],
        'sonuc' => (string)($sonuc['sonuc'] ?? ''),
        'cikti' => (string)($sonuc['cikti'] ?? ''),
    ];
}

/**
 * Parametre dropdown'larının seçenek kaynakları.
 *
 * Şemada "tip":"select" veya "multiselect" kullanan alanlar "kaynak" anahtarıyla
 * buradaki sorgulardan beslenir; seçenek listeleri koda gömülmez, DB'den gelir.
 * Yeni bir kaynak eklemek için buraya tek satır eklenir.
 *
 * @return array<string, array{sorgu: string, aciklama: string}>
 */
function cronParamKaynaklari(): array
{
    return [
        'ticket_durum_acik' => [
            'aciklama' => 'Ticket durumları — kapatmayan (açık) durumlar',
            'sorgu'    => "SELECT Ticket_Durumlar_id AS deger, Ticket_Durumlar_ad AS etiket
                             FROM dbo.Ticket_Durumlar
                            WHERE Durum = 1 AND Ticket_Durumlar_kapatma_durumu = 0
                            ORDER BY Ticket_Durumlar_sira",
        ],
        'ticket_durum_kapali' => [
            'aciklama' => 'Ticket durumları — talebi kapatan durumlar',
            'sorgu'    => "SELECT Ticket_Durumlar_id AS deger, Ticket_Durumlar_ad AS etiket
                             FROM dbo.Ticket_Durumlar
                            WHERE Durum = 1 AND Ticket_Durumlar_kapatma_durumu = 1
                            ORDER BY Ticket_Durumlar_sira",
        ],
        'ticket_sablon_cevap' => [
            'aciklama' => 'Mesaj şablonları — müşteriye gönderilen cevap şablonları',
            'sorgu'    => "SELECT Sablon_Id AS deger, Sablon_Ad AS etiket
                             FROM dbo.Ticket_Mesaj_Sablonlari
                            WHERE Durum = 1 AND Sablon_Tip = 'cevap'
                            ORDER BY Sablon_Ad",
        ],
    ];
}

/**
 * Tek bir kaynağın seçeneklerini döner: [['deger' => ..., 'etiket' => ...], ...]
 * Tanımsız kaynakta boş dizi döner (panel dropdown'ı boş kalır, hata vermez).
 */
function cronParamSecenek($db, string $kaynak): array
{
    $kaynaklar = cronParamKaynaklari();
    if (!isset($kaynaklar[$kaynak])) {
        return [];
    }

    return $db->fetchAll($kaynaklar[$kaynak]['sorgu']);
}

/**
 * Görevin parametre şemasına göre gelen değerleri doğrular ve temizler.
 * Panel, zamanlayıcı kaydı ve worker manuel tetiğinde kullanılır.
 *
 * Desteklenen tipler: text (varsayılan) · number · date · select · multiselect
 *
 * @param string|null $semaJson CronGorevler_Parametreler alanı
 * @throws RuntimeException Zorunlu parametre eksikse
 */
function cronParamDogrula(?string $semaJson, array $gelen): array
{
    $sema = json_decode($semaJson ?? '[]', true);
    if (!is_array($sema) || !$sema) {
        return $gelen;
    }

    $sonuc = [];
    foreach ($sema as $alan) {
        $ad = $alan['ad'] ?? null;
        if (!$ad) {
            continue;
        }

        $tip   = $alan['tip'] ?? 'text';
        $deger = $gelen[$ad] ?? null;

        if ($tip === 'multiselect') {
            // Panel dizi gönderir; CLI'da virgüllü metin de kabul edilir
            if (is_string($deger)) {
                $deger = $deger === '' ? [] : array_map('trim', explode(',', $deger));
            }
            $deger = is_array($deger) ? array_values(array_filter($deger, fn($v) => $v !== '' && $v !== null)) : [];

            if (!$deger) {
                if (!empty($alan['zorunlu'])) {
                    throw new RuntimeException('Zorunlu parametre eksik: ' . ($alan['etiket'] ?? $ad));
                }
                continue;   // boş çoklu seçim yazılmaz, görev varsayılan davranışını sürdürür
            }

            $sonuc[$ad] = array_map('intval', $deger);
            continue;
        }

        if ($deger === null || $deger === '') {
            if (!empty($alan['zorunlu'])) {
                throw new RuntimeException('Zorunlu parametre eksik: ' . ($alan['etiket'] ?? $ad));
            }
            if (array_key_exists('varsayilan', $alan)) {
                $sonuc[$ad] = $alan['varsayilan'];
            }
            continue;
        }

        $sonuc[$ad] = in_array($tip, ['number', 'select'], true) ? (int)$deger : $deger;
    }

    return $sonuc;
}
