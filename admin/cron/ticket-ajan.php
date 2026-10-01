<?php
/**
 * Ticket Ajan - CLI Aracı
 *
 * Destek talebini okumak ve yanıt/iç not göndermek için kullanılır.
 * Yalnızca komut satırından çalışır, web üzerinden erişilemez.
 *
 * Kullanım:
 *   php admin/cron/ticket-ajan.php detay 246
 *   php admin/cron/ticket-ajan.php detay https://destek.ornekproje.com/admin/ticket-detay?id=246
 *   php admin/cron/ticket-ajan.php yanit 246 --mesaj-dosya=temp/yanit.txt --icnot-dosya=temp/icnot.txt
 *   php admin/cron/ticket-ajan.php yanit 246 --icnot-dosya=temp/icnot.txt --durum=3
 *   php admin/cron/ticket-ajan.php guncelle 246 --durum=3 --atanan=ben
 *   php admin/cron/ticket-ajan.php guncelle 246 --atanan=bos --oncelik=2
 *   php admin/cron/ticket-ajan.php yanit 246 --mesaj-dosya=temp/yanit.txt --durum=Cozuldu --atanan=ben --onizleme
 *
 * Seçenekler:
 *   --kaynak=KOD      Ajanın kullanacağı kaynak kodu (varsayılan: BATUHAN)
 *   --mesaj-dosya=    Müşteriye gidecek yanıt metnini içeren dosya
 *   --icnot-dosya=    Yalnızca ekipte görünecek iç not metnini içeren dosya
 *   --durum=ID        Ticket durumunu günceller (opsiyonel)
 *   --oncelik=ID      Ticket önceliğini günceller (opsiyonel)
 *   --kategori=ID     Ticket kategorisini günceller (yalnizca guncelle komutunda)
 *   --atanan=X        Personel atar. X: kullanici_id | "ben" (kaynak kullanicisi) | "bos" (atamayi kaldirir)
 *   --onizleme        Taslak modu: hicbir kayit yazilmaz, bildirim gonderilmez, plan raporlanir
 *
 * Not: yanit komutunda --atanan verilmezse ve ticket kimseye atanmamissa, ticket yaniti
 * yazan kullaniciya otomatik atanir. Ticket zaten atanmissa atamaya dokunulmaz.
 * Otomatik atamayi istemiyorsaniz --atanan=bos kullanin.
 *   --vhost-root=     Proje dizini araması için kök (varsayılan: d:/Inetpub/vhosts)
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Bu arac yalnizca komut satirindan calistirilabilir.');
}

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/TicketService.php';

const AJAN_VARSAYILAN_KAYNAK = 'BATUHAN';

/**
 * Argümanları ayrıştırır: konumsal argümanlar + --anahtar=deger seçenekleri
 */
function argAyristir(array $argv): array
{
    $konumsal = [];
    $secenek = [];

    foreach (array_slice($argv, 1) as $arg) {
        if (str_starts_with($arg, '--')) {
            $parca = explode('=', substr($arg, 2), 2);
            $secenek[$parca[0]] = $parca[1] ?? '1';
        } else {
            $konumsal[] = $arg;
        }
    }

    return ['konumsal' => $konumsal, 'secenek' => $secenek];
}

/**
 * "246" veya ".../ticket-detay?id=246" girdisinden ticket id'sini çıkarır.
 */
function ticketIdCoz(string $girdi): int
{
    $girdi = trim($girdi);

    if (ctype_digit($girdi)) {
        return (int)$girdi;
    }

    if (preg_match('/[?&]id=(\d+)/i', $girdi, $eslesme)) {
        return (int)$eslesme[1];
    }

    if (preg_match('/(\d+)\s*$/', $girdi, $eslesme)) {
        return (int)$eslesme[1];
    }

    return 0;
}

/**
 * Kaynağın api_url alanındaki domaine karşılık gelen yerel proje dizinini arar.
 */
function projeDiziniBul(?string $apiUrl, string $vhostRoot): ?array
{
    if (empty($apiUrl)) {
        return null;
    }

    $host = parse_url($apiUrl, PHP_URL_HOST);
    if (!$host) {
        return null;
    }

    $host = strtolower($host);
    $vhostRoot = rtrim(str_replace('\\', '/', $vhostRoot), '/');

    // open_basedir kisiti nedeniyle bu betik yalnizca kendi domain agacini gorebilir.
    // Diger projelerin dizinini cozmek ajanin (Claude) isidir; burada yalnizca host bildirilir.
    if ($vhostRoot === '' || !is_dir($vhostRoot)) {
        return [
            'host' => $host,
            'dizin' => null,
            'not' => 'Dizin cozumu ajana birakildi (open_basedir kisiti). Host bilgisi yeterli.',
        ];
    }

    $adaylar = [];

    // 1) vhosts/<host>/httpdocs  (ana domain)
    $adaylar[] = $vhostRoot . '/' . $host . '/httpdocs';
    $adaylar[] = $vhostRoot . '/' . $host;

    // 2) vhosts/<ust-domain>/<host>  (alt domain)
    $parcalar = explode('.', $host);
    for ($i = 1; $i < count($parcalar) - 1; $i++) {
        $ustDomain = implode('.', array_slice($parcalar, $i));
        $adaylar[] = $vhostRoot . '/' . $ustDomain . '/' . $host;
        $adaylar[] = $vhostRoot . '/' . $ustDomain . '/httpdocs';
    }

    foreach ($adaylar as $aday) {
        if (is_dir($aday)) {
            return ['host' => $host, 'dizin' => str_replace('\\', '/', realpath($aday)), 'not' => null];
        }
    }

    return ['host' => $host, 'dizin' => null, 'not' => 'Yerel dizin bulunamadi. Denenen: ' . implode(', ', $adaylar)];
}

/**
 * Ajanın kullanacağı kaynağı ve bağlı kullanıcıyı getirir.
 */
function ajanKaynagiGetir(Database $db, string $kaynakKod): array
{
    $kaynak = $db->fetchOne(
        "SELECT Ticket_Kaynak_id, Ticket_Kaynak_ad, Ticket_Kaynak_kod, Ticket_Kaynak_kullanici_id, Ticket_Kaynak_api_url
         FROM Ticket_Kaynak
         WHERE Ticket_Kaynak_kod = ? AND Durum = 1",
        [$kaynakKod]
    );

    if (!$kaynak) {
        throw new Exception("Kaynak bulunamadi veya pasif: {$kaynakKod}");
    }

    $kullaniciId = (int)($kaynak['Ticket_Kaynak_kullanici_id'] ?? 0);
    if ($kullaniciId <= 0) {
        throw new Exception("'{$kaynakKod}' kaynagina bagli kullanici tanimli degil. Kaynak yonetimi ekranindan kullanici secin.");
    }

    $kullanici = $db->fetchOne(
        "SELECT kullanici_id, kullanici_ad, kullanici_soyad, kullanici_departman_id
         FROM kullanicilar
         WHERE kullanici_id = ? AND kullanici_durum = 1",
        [$kullaniciId]
    );

    if (!$kullanici) {
        throw new Exception("Kaynaga bagli kullanici aktif degil (kullanici_id={$kullaniciId}).");
    }

    return ['kaynak' => $kaynak, 'kullanici' => $kullanici];
}

/**
 * --onizleme / --taslak bayragini okur.
 * Onizleme modunda hicbir kayit yazilmaz, bildirim tetiklenmez;
 * yalnizca yapilacak islemin plani dondurulur.
 */
function onizlemeMi(array $secenek): bool
{
    foreach (['onizleme', 'taslak', 'dry-run', 'prova'] as $anahtar) {
        if (!isset($secenek[$anahtar])) {
            continue;
        }
        $deger = mb_strtolower(trim((string)$secenek[$anahtar]), 'UTF-8');
        if (!in_array($deger, ['0', 'hayir', 'false'], true)) {
            return true;
        }
    }
    return false;
}

/**
 * Uzun metinleri onizleme ciktisi icin kisaltir.
 */
function metinOzeti(string $metin, int $limit = 400): array
{
    $duz = trim(preg_replace('/\s+/u', ' ', strip_tags($metin)) ?? '');

    return [
        'karakter' => mb_strlen($metin, 'UTF-8'),
        'html'     => $metin !== strip_tags($metin),
        'onizleme' => mb_strlen($duz, 'UTF-8') > $limit
            ? mb_substr($duz, 0, $limit, 'UTF-8') . ' …'
            : $duz,
    ];
}
/**
 * Turkce karakterleri sadelestirip kucuk harfe cevirir (isim eslestirme icin).
 */
function tanimNormalize(string $metin): string
{
    $metin = mb_strtolower(trim($metin), 'UTF-8');
    $metin = strtr($metin, [
        'ç' => 'c', 'ğ' => 'g', 'ı' => 'i', 'i̇' => 'i', 'ö' => 'o', 'ş' => 's', 'ü' => 'u', 'â' => 'a', 'î' => 'i', 'û' => 'u',
    ]);
    return preg_replace('/[^a-z0-9]+/', '', $metin) ?? '';
}

/**
 * --durum / --oncelik / --kategori degerini ID'ye cevirir.
 * Deger sayi ise dogrudan ID, degilse tanim adiyla eslestirilir.
 * Boylece ID ogrenmek icin ayrica SQL sormaya gerek kalmaz.
 *
 * @param string $tablo  Ticket_Durumlar | Ticket_Oncelikler | Ticket_Kategoriler
 * @param string $onek   Ticket_Durumlar | Ticket_Oncelikler | Ticket_Kategoriler (kolon oneki)
 * @return array{id:int, ad:string, satir:array}|null
 */
function tanimCoz(Database $db, string $tablo, string $onek, ?string $deger, string $etiket): ?array
{
    if ($deger === null || trim($deger) === '' || trim($deger) === '0') {
        return null;
    }

    $deger = trim($deger);
    $satirlar = $db->fetchAll(
        "SELECT * FROM {$tablo} WHERE Durum = 1 ORDER BY {$onek}_sira"
    ) ?: [];

    if (!$satirlar) {
        throw new Exception("{$etiket} tanimi bulunamadi (aktif kayit yok).");
    }

    $idKolon = $onek . '_id';
    $adKolon = $onek . '_ad';

    // 1) Sayisal deger -> dogrudan ID
    if (ctype_digit($deger)) {
        foreach ($satirlar as $satir) {
            if ((int)$satir[$idKolon] === (int)$deger) {
                return ['id' => (int)$satir[$idKolon], 'ad' => (string)$satir[$adKolon], 'satir' => $satir];
            }
        }
        throw new Exception("{$etiket} bulunamadi veya pasif: {$deger}");
    }

    // 2) Ada gore tam eslesme
    $hedef = tanimNormalize($deger);
    foreach ($satirlar as $satir) {
        if (tanimNormalize((string)$satir[$adKolon]) === $hedef) {
            return ['id' => (int)$satir[$idKolon], 'ad' => (string)$satir[$adKolon], 'satir' => $satir];
        }
    }

    // 3) Ada gore kismi eslesme (tek adaya dusuyorsa kabul et)
    $adaylar = [];
    foreach ($satirlar as $satir) {
        if (str_contains(tanimNormalize((string)$satir[$adKolon]), $hedef)) {
            $adaylar[] = $satir;
        }
    }
    if (count($adaylar) === 1) {
        return ['id' => (int)$adaylar[0][$idKolon], 'ad' => (string)$adaylar[0][$adKolon], 'satir' => $adaylar[0]];
    }

    $liste = implode(', ', array_map(
        static fn($r) => $r[$idKolon] . '=' . $r[$adKolon],
        count($adaylar) > 1 ? $adaylar : $satirlar
    ));
    $onEk = count($adaylar) > 1 ? "{$etiket} icin birden fazla eslesme" : "{$etiket} bulunamadi";
    throw new Exception("{$onEk}: '{$deger}'. Secenekler: {$liste}");
}
/**
 * --atanan degerini kullanici_id'ye cevirir.
 * Donus: null = dokunma, 0 = atamayi kaldir, >0 = ilgili kullanici
 */
function atananCoz(Database $db, ?string $deger, array $ajan): ?int
{
    if ($deger === null || trim($deger) === '') {
        return null;
    }

    $deger = mb_strtolower(trim($deger), 'UTF-8');

    if (in_array($deger, ['bos', 'bosalt', 'kaldir', 'yok', 'null', '0'], true)) {
        return 0;
    }

    if (in_array($deger, ['ben', 'kendim', 'ajan'], true)) {
        return (int)$ajan['kullanici']['kullanici_id'];
    }

    if (ctype_digit($deger)) {
        $kullaniciId = (int)$deger;
        $kullanici = $db->fetchOne(
            "SELECT kullanici_id FROM kullanicilar WHERE kullanici_id = ? AND kullanici_durum = 1",
            [$kullaniciId]
        );
        if (!$kullanici) {
            throw new Exception("Personel bulunamadi veya pasif: kullanici_id={$kullaniciId}");
        }
        return $kullaniciId;
    }

    // Ad soyad ile eslestir (ID sormaya gerek kalmasin)
    $personeller = $db->fetchAll(
        "SELECT kullanici_id, kullanici_ad, kullanici_soyad
         FROM kullanicilar WHERE kullanici_durum = 1 ORDER BY kullanici_ad"
    ) ?: [];

    $hedef = tanimNormalize($deger);
    $adaylar = [];
    foreach ($personeller as $personel) {
        $tamAd = tanimNormalize($personel['kullanici_ad'] . ' ' . ($personel['kullanici_soyad'] ?? ''));
        if ($tamAd === $hedef) {
            return (int)$personel['kullanici_id'];
        }
        if (str_contains($tamAd, $hedef)) {
            $adaylar[] = $personel;
        }
    }

    if (count($adaylar) === 1) {
        return (int)$adaylar[0]['kullanici_id'];
    }

    $liste = implode(', ', array_map(
        static fn($p) => $p['kullanici_id'] . '=' . trim($p['kullanici_ad'] . ' ' . ($p['kullanici_soyad'] ?? '')),
        count($adaylar) > 1 ? $adaylar : $personeller
    ));
    $onEk = count($adaylar) > 1 ? 'Personel icin birden fazla eslesme' : 'Personel bulunamadi';
    throw new Exception("{$onEk}: '{$deger}'. Secenekler: {$liste}");
}

/**
 * Durum / oncelik / kategori / atama guncellemelerini tek noktada uygular.
 * Panel davranisiyla birebir: kapatma durumunda kapanis tarihi yazilir,
 * atama degistiginde ticket_atandi bildirimi tetiklenir.
 *
 * @return array ['update' => Tickets tablosuna yazilacak alanlar, 'degisen' => ozet, 'atama' => [...]]
 */
function alanGuncellemeHazirla(Database $db, int $ticketId, array $ajan, array $secenek, string $now): array
{
    $durum    = tanimCoz($db, 'Ticket_Durumlar', 'Ticket_Durumlar', $secenek['durum'] ?? null, 'Durum');
    $oncelik  = tanimCoz($db, 'Ticket_Oncelikler', 'Ticket_Oncelikler', $secenek['oncelik'] ?? null, 'Oncelik');
    $kategori = tanimCoz($db, 'Ticket_Kategoriler', 'Ticket_Kategoriler', $secenek['kategori'] ?? null, 'Kategori');
    $atananId = atananCoz($db, $secenek['atanan'] ?? null, $ajan);

    $update  = [];
    $degisen = [];

    if ($durum) {
        $update['Tickets_durum_id'] = $durum['id'];
        if ($durum['satir']['Ticket_Durumlar_kapatma_durumu']) {
            $update['Tickets_kapanis_tarihi']    = $now;
            $update['Tickets_musteri_cevap_var'] = 0;
        }
        $degisen['durum'] = $durum['ad'];
    }

    if ($oncelik) {
        $update['Tickets_oncelik_id'] = $oncelik['id'];
        $degisen['oncelik'] = $oncelik['ad'];
    }

    if ($kategori) {
        $update['Tickets_kategori_id'] = $kategori['id'];
        $degisen['kategori'] = $kategori['ad'];
    }

    $atama = null;
    if ($atananId !== null) {
        $eskiKayit = $db->fetchOne(
            "SELECT Tickets_atanan_kullanici_id FROM Tickets WHERE Tickets_id = ?",
            [$ticketId]
        );
        $eskiAtananId = $eskiKayit ? (int)$eskiKayit['Tickets_atanan_kullanici_id'] : 0;

        $update['Tickets_atanan_kullanici_id'] = $atananId > 0 ? $atananId : null;

        if ($atananId > 0) {
            $atananRow = $db->fetchOne(
                "SELECT kullanici_ad, kullanici_soyad FROM kullanicilar WHERE kullanici_id = ?",
                [$atananId]
            );
            $degisen['atanan'] = trim(($atananRow['kullanici_ad'] ?? '') . ' ' . ($atananRow['kullanici_soyad'] ?? ''));
        } else {
            $degisen['atanan'] = null;
        }

        $atama = ['yeni' => $atananId, 'eski' => $eskiAtananId];
    }

    return ['update' => $update, 'degisen' => $degisen, 'atama' => $atama];
}

/**
 * Atama bildiriminin gonderilip gonderilmeyecegini yazmadan hesaplar (onizleme icin).
 */
function atamaBildirimiPlani(?array $atama, int $islemKullaniciId): string
{
    if (!$atama || $atama['yeni'] <= 0) {
        return 'gonderilmeyecek';
    }
    if ($atama['yeni'] === $atama['eski'] || $atama['yeni'] === $islemKullaniciId) {
        return 'gonderilmeyecek (kendine atama veya degisiklik yok)';
    }
    return 'gonderilecek (ticket_atandi)';
}

/**
 * Atama degistiyse bildirimi tetikler, sonuc metnini dondurur.
 */
function atamaBildirimiUygula(Database $db, int $ticketId, ?array $atama, int $islemKullaniciId): string
{
    if (!$atama || $atama['yeni'] <= 0) {
        return 'gonderilmedi';
    }

    if ($atama['yeni'] === $atama['eski'] || $atama['yeni'] === $islemKullaniciId) {
        return 'gonderilmedi (kendine atama veya degisiklik yok)';
    }

    (new TicketService($db))->atamaBildirimiTetikle($ticketId, $atama['yeni'], $islemKullaniciId, $atama['eski']);
    return 'tetiklendi (ticket_atandi)';
}

/**
 * guncelle komutu: mesaj yazmadan durum / oncelik / kategori / atama degistirir.
 */
function komutGuncelle(Database $db, int $ticketId, array $ajan, array $secenek): array
{
    $ticket = $db->fetchOne(
        "SELECT Tickets_id FROM Tickets WHERE Tickets_id = ? AND Durum = 1",
        [$ticketId]
    );
    if (!$ticket) {
        throw new Exception("Ticket bulunamadi: {$ticketId}");
    }

    $kullaniciId = (int)$ajan['kullanici']['kullanici_id'];
    $now = app_now();

    $hazirlik = alanGuncellemeHazirla($db, $ticketId, $ajan, $secenek, $now);

    if (!$hazirlik['update']) {
        throw new Exception('Guncellenecek alan verilmedi. --durum, --oncelik, --kategori veya --atanan kullanin.');
    }

    $update = $hazirlik['update'];
    $update['GuncelleyenKullanici'] = $kullaniciId;
    $update['GuncellemeTarihi']     = $now;

    $islemYapan = trim($ajan['kullanici']['kullanici_ad'] . ' ' . ($ajan['kullanici']['kullanici_soyad'] ?? ''));

    if (onizlemeMi($secenek)) {
        return [
            'onizleme'    => true,
            'uyari'       => 'ONIZLEME: hicbir kayit yazilmadi. Gondermek icin --onizleme bayragini kaldirin.',
            'degisen'     => $hazirlik['degisen'],
            'tickets_update' => $update,
            'bildirim'    => atamaBildirimiPlani($hazirlik['atama'], $kullaniciId),
            'islem_yapan' => $islemYapan,
        ];
    }

    $db->update('Tickets', $update, ['Tickets_id' => $ticketId]);

    return [
        'onizleme' => false,
        'degisen'  => $hazirlik['degisen'],
        'bildirim' => atamaBildirimiUygula($db, $ticketId, $hazirlik['atama'], $kullaniciId),
        'tarih'    => $now,
        'islem_yapan' => $islemYapan,
    ];
}
/**
 * detay komutu: ticket + mesajlar (iç notlar dahil) + ekler + proje bilgisi
 */
function komutDetay(Database $db, int $ticketId, string $vhostRoot): array
{
    $ticket = $db->fetchOne(
        "SELECT
            t.Tickets_id,
            t.Tickets_no,
            t.Tickets_konu,
            CONVERT(VARCHAR(16), t.OlusturmaTarihi, 120)          AS acilis_tarihi,
            CONVERT(VARCHAR(16), t.Tickets_son_yanit_tarihi, 120) AS son_yanit_tarihi,
            CONVERT(VARCHAR(16), t.Tickets_kapanis_tarihi, 120)   AS kapanis_tarihi,
            kat.Ticket_Kategoriler_id  AS kategori_id,
            kat.Ticket_Kategoriler_ad  AS kategori_ad,
            onc.Ticket_Oncelikler_id   AS oncelik_id,
            onc.Ticket_Oncelikler_ad   AS oncelik_ad,
            dur.Ticket_Durumlar_id     AS durum_id,
            dur.Ticket_Durumlar_ad     AS durum_ad,
            dur.Ticket_Durumlar_kapatma_durumu,
            kay.Ticket_Kaynak_id       AS kaynak_id,
            kay.Ticket_Kaynak_ad       AS kaynak_ad,
            kay.Ticket_Kaynak_kod      AS kaynak_kod,
            kay.Ticket_Kaynak_api_url  AS kaynak_api_url,
            ac.kullanici_ad            AS acan_ad,
            ac.kullanici_soyad         AS acan_soyad,
            ac.kullanici_email         AS acan_email,
            at.kullanici_ad            AS atanan_ad,
            at.kullanici_soyad         AS atanan_soyad
         FROM Tickets t
         LEFT JOIN Ticket_Kategoriler kat ON t.Tickets_kategori_id = kat.Ticket_Kategoriler_id
         LEFT JOIN Ticket_Oncelikler  onc ON t.Tickets_oncelik_id  = onc.Ticket_Oncelikler_id
         LEFT JOIN Ticket_Durumlar    dur ON t.Tickets_durum_id    = dur.Ticket_Durumlar_id
         LEFT JOIN Ticket_Kaynak      kay ON t.Tickets_kaynak_id   = kay.Ticket_Kaynak_id
         LEFT JOIN kullanicilar       ac  ON t.Tickets_olusturan_kullanici_id = ac.kullanici_id
         LEFT JOIN kullanicilar       at  ON t.Tickets_atanan_kullanici_id    = at.kullanici_id
         WHERE t.Tickets_id = ? AND t.Durum = 1",
        [$ticketId]
    );

    if (!$ticket) {
        throw new Exception("Ticket bulunamadi: {$ticketId}");
    }

    $mesajlar = $db->fetchAll(
        "SELECT
            m.Ticket_Mesajlar_id     AS mesaj_id,
            m.Ticket_Mesajlar_mesaj  AS mesaj,
            m.Ticket_Mesajlar_ic_not AS ic_not,
            m.Ticket_Mesajlar_kaynak AS mesaj_kaynak,
            CONVERT(VARCHAR(16), m.OlusturmaTarihi, 120) AS tarih,
            COALESCE(m.Ticket_Mesajlar_yazan_ad, k.kullanici_ad)       AS yazan_ad,
            COALESCE(m.Ticket_Mesajlar_yazan_soyad, k.kullanici_soyad) AS yazan_soyad
         FROM Ticket_Mesajlar m
         LEFT JOIN kullanicilar k ON m.Ticket_Mesajlar_yazan_kul_id = k.kullanici_id
         WHERE m.Ticket_Mesajlar_ticket_id = ? AND m.Durum = 1
         ORDER BY m.OlusturmaTarihi ASC, m.Ticket_Mesajlar_id ASC",
        [$ticketId]
    ) ?: [];

    $ekler = $db->fetchAll(
        "SELECT
            Ticket_Ekler_id        AS ek_id,
            Ticket_Ekler_mesaj_id  AS mesaj_id,
            Ticket_Ekler_dosya_adi AS dosya_adi,
            Ticket_Ekler_dosya_yolu AS dosya_yolu,
            Ticket_Ekler_mime_turu AS mime_turu,
            Ticket_Ekler_dosya_boyutu AS dosya_boyutu
         FROM Ticket_Ekler
         WHERE Ticket_Ekler_ticket_id = ? AND Durum = 1
         ORDER BY Ticket_Ekler_id ASC",
        [$ticketId]
    ) ?: [];

    $projeKoku = str_replace('\\', '/', realpath(__DIR__ . '/../..'));

    foreach ($ekler as &$ek) {
        $ek['ic_not'] = 0;
        $ek['yerel_yol'] = !empty($ek['dosya_yolu'])
            ? $projeKoku . '/' . ltrim($ek['dosya_yolu'], '/')
            : null;
        $ek['mevcut'] = $ek['yerel_yol'] && is_file($ek['yerel_yol']);
    }
    unset($ek);

    // Ek hangi mesaja aitse iç not bayrağını ondan devral
    $mesajIcNot = [];
    foreach ($mesajlar as $m) {
        $mesajIcNot[(int)$m['mesaj_id']] = (int)$m['ic_not'];
    }
    foreach ($ekler as &$ek) {
        $mid = (int)($ek['mesaj_id'] ?? 0);
        $ek['ic_not'] = $mesajIcNot[$mid] ?? 0;
    }
    unset($ek);

    // Guncelleme komutlarinda kullanilacak ID'ler; ayrica SQL sormaya gerek kalmasin
    $secenekler = [
        'durumlar' => $db->fetchAll(
            "SELECT Ticket_Durumlar_id AS id, Ticket_Durumlar_ad AS ad,
                    Ticket_Durumlar_kapatma_durumu AS kapatma
             FROM Ticket_Durumlar WHERE Durum = 1 ORDER BY Ticket_Durumlar_sira"
        ) ?: [],
        'oncelikler' => $db->fetchAll(
            "SELECT Ticket_Oncelikler_id AS id, Ticket_Oncelikler_ad AS ad
             FROM Ticket_Oncelikler WHERE Durum = 1 ORDER BY Ticket_Oncelikler_sira"
        ) ?: [],
        'kategoriler' => $db->fetchAll(
            "SELECT Ticket_Kategoriler_id AS id, Ticket_Kategoriler_ad AS ad
             FROM Ticket_Kategoriler WHERE Durum = 1 ORDER BY Ticket_Kategoriler_sira"
        ) ?: [],
        'personeller' => $db->fetchAll(
            "SELECT kullanici_id AS id, kullanici_ad + ' ' + kullanici_soyad AS ad
             FROM kullanicilar WHERE kullanici_durum = 1 ORDER BY kullanici_ad"
        ) ?: [],
    ];

    return [
        'ticket' => $ticket,
        'proje' => projeDiziniBul($ticket['kaynak_api_url'] ?? null, $vhostRoot),
        'secenekler' => $secenekler,
        'mesajlar' => $mesajlar,
        'ekler' => $ekler,
        'ozet' => [
            'mesaj_sayisi' => count(array_filter($mesajlar, static fn($m) => (int)$m['ic_not'] === 0)),
            'ic_not_sayisi' => count(array_filter($mesajlar, static fn($m) => (int)$m['ic_not'] === 1)),
            'ek_sayisi' => count($ekler),
        ],
    ];
}

/**
 * Dosyadan metin okur, yoksa boş döner.
 */
function metinOku(?string $yol, string $projeKoku): string
{
    if (empty($yol)) {
        return '';
    }

    $tamYol = $yol;
    if (!is_file($tamYol)) {
        $tamYol = $projeKoku . '/' . ltrim(str_replace('\\', '/', $yol), '/');
    }

    if (!is_file($tamYol)) {
        throw new Exception("Metin dosyasi bulunamadi: {$yol}");
    }

    $icerik = file_get_contents($tamYol);
    if ($icerik === false) {
        throw new Exception("Metin dosyasi okunamadi: {$yol}");
    }

    return trim($icerik);
}

/**
 * yanit komutu: müşteri yanıtı ve/veya iç not ekler (panel davranışıyla birebir)
 */
function komutYanit(Database $db, int $ticketId, array $ajan, string $mesajText, string $icNotText, array $secenek): array
{
    if ($mesajText === '' && $icNotText === '') {
        throw new Exception('Mesaj veya ic not bos olamaz.');
    }

    $ticket = $db->fetchOne(
        "SELECT Tickets_id FROM Tickets WHERE Tickets_id = ? AND Durum = 1",
        [$ticketId]
    );
    if (!$ticket) {
        throw new Exception("Ticket bulunamadi: {$ticketId}");
    }

    $kullaniciId = (int)$ajan['kullanici']['kullanici_id'];
    $now = app_now();
    $sonuc = ['onizleme' => false, 'mesaj_id' => null, 'ic_not_id' => null];

    // --atanan verilmediyse ve ticket kimseye atanmamissa, yaniti yazan kullaniciya atanir.
    // Ticket zaten birine atanmissa atamaya dokunulmaz. --atanan=bos ile bu davranis atlanabilir.
    $otomatikAtama = false;
    if (!isset($secenek['atanan']) || trim((string)$secenek['atanan']) === '') {
        $mevcut = $db->fetchOne(
            "SELECT Tickets_atanan_kullanici_id FROM Tickets WHERE Tickets_id = ?",
            [$ticketId]
        );
        if ((int)($mevcut['Tickets_atanan_kullanici_id'] ?? 0) <= 0) {
            $secenek['atanan'] = (string)$kullaniciId;
            $otomatikAtama = true;
        }
    }
    $sonuc['otomatik_atama'] = $otomatikAtama;

    // Onizleme: hicbir sey yazilmaz, yalnizca gonderilecek icerik ve DB etkisi raporlanir
    if (onizlemeMi($secenek)) {
        $hazirlik = alanGuncellemeHazirla($db, $ticketId, $ajan, $secenek, $now);

        $ticketUpdate = [
            'Tickets_son_yanit_tarihi' => $now,
            'GuncelleyenKullanici'     => $kullaniciId,
            'GuncellemeTarihi'         => $now,
        ];
        if ($mesajText !== '') {
            $ticketUpdate['Tickets_musteri_cevap_var'] = 0;
        }
        $ticketUpdate = array_merge($ticketUpdate, $hazirlik['update']);

        return [
            'onizleme' => true,
            'uyari'    => 'ONIZLEME: hicbir kayit yazilmadi, bildirim gonderilmedi. Gondermek icin --onizleme bayragini kaldirin.',
            'yazan'    => trim($ajan['kullanici']['kullanici_ad'] . ' ' . ($ajan['kullanici']['kullanici_soyad'] ?? '')),
            'otomatik_atama' => $otomatikAtama
                ? 'ticket atanmamisti, yaniti yazan kullaniciya atanacak'
                : false,
            'musteri_yaniti' => $mesajText !== '' ? metinOzeti($mesajText) : null,
            'ic_not'         => $icNotText !== '' ? metinOzeti($icNotText) : null,
            'degisen'        => $hazirlik['degisen'],
            'tickets_update' => $ticketUpdate,
            'bildirim'       => $mesajText !== ''
                ? 'gonderilecek (yeni mesaj: mail/WhatsApp)'
                : 'gonderilmeyecek (yalnizca ic not)',
            'atama_bildirimi' => atamaBildirimiPlani($hazirlik['atama'], $kullaniciId),
        ];
    }

    if ($mesajText !== '') {
        $sonuc['mesaj_id'] = (int)$db->insert('Ticket_Mesajlar', [
            'Ticket_Mesajlar_ticket_id'    => $ticketId,
            'Ticket_Mesajlar_mesaj'        => $mesajText,
            'Ticket_Mesajlar_ic_not'       => 0,
            'Ticket_Mesajlar_yazan_kul_id' => $kullaniciId,
            'Ticket_Mesajlar_kaynak'       => 'panel',
            'OlusturanKullanici'           => $kullaniciId,
            'OlusturmaTarihi'              => $now,
            'GuncelleyenKullanici'         => $kullaniciId,
            'GuncellemeTarihi'             => $now,
            'Durum'                        => 1,
        ]);
    }

    if ($icNotText !== '') {
        $sonuc['ic_not_id'] = (int)$db->insert('Ticket_Mesajlar', [
            'Ticket_Mesajlar_ticket_id'    => $ticketId,
            'Ticket_Mesajlar_mesaj'        => $icNotText,
            'Ticket_Mesajlar_ic_not'       => 1,
            'Ticket_Mesajlar_yazan_kul_id' => $kullaniciId,
            'Ticket_Mesajlar_kaynak'       => 'panel',
            'OlusturanKullanici'           => $kullaniciId,
            'OlusturmaTarihi'              => $now,
            'GuncelleyenKullanici'         => $kullaniciId,
            'GuncellemeTarihi'             => $now,
            'Durum'                        => 1,
        ]);
    }

    $ticketUpdate = [
        'Tickets_son_yanit_tarihi' => $now,
        'GuncelleyenKullanici'     => $kullaniciId,
        'GuncellemeTarihi'         => $now,
    ];

    // Müşteriye yanıt yazıldıysa bekleyen cevap işaretini kaldır
    if ($mesajText !== '') {
        $ticketUpdate['Tickets_musteri_cevap_var'] = 0;
    }

    // Durum / oncelik / kategori / atama guncellemeleri (guncelle komutuyla ortak akis)
    $hazirlik = alanGuncellemeHazirla($db, $ticketId, $ajan, $secenek, $now);
    $ticketUpdate = array_merge($ticketUpdate, $hazirlik['update']);
    $sonuc['degisen'] = $hazirlik['degisen'];

    $db->update('Tickets', $ticketUpdate, ['Tickets_id' => $ticketId]);

    $sonuc['atama_bildirimi'] = atamaBildirimiUygula($db, $ticketId, $hazirlik['atama'], $kullaniciId);

    // Müşteriye yanıt gönderildiyse bildirim tetikle (mail/WhatsApp)
    if ($mesajText !== '') {
        (new TicketService($db))->yeniMesajBildirimiTetikle($ticketId, $kullaniciId, 'panel', $mesajText);
        $sonuc['bildirim'] = 'tetiklendi';
    } else {
        $sonuc['bildirim'] = 'gonderilmedi (yalnizca ic not)';
    }

    $sonuc['tarih'] = $now;
    $sonuc['yazan'] = trim($ajan['kullanici']['kullanici_ad'] . ' ' . ($ajan['kullanici']['kullanici_soyad'] ?? ''));

    return $sonuc;
}

// ─────────────────────────────────────────────────────────────
// Giriş noktası
// ─────────────────────────────────────────────────────────────
try {
    $args = argAyristir($argv);
    $komut = strtolower($args['konumsal'][0] ?? '');
    $hedef = $args['konumsal'][1] ?? '';

    if ($komut === '' || $komut === 'yardim') {
        echo "Kullanim:\n";
        echo "  php admin/cron/ticket-ajan.php detay <id|url>\n";
        echo "  php admin/cron/ticket-ajan.php yanit <id|url> --mesaj-dosya=... --icnot-dosya=... [--durum=] [--oncelik=] [--atanan=]\n";
        echo "  php admin/cron/ticket-ajan.php guncelle <id|url> [--durum=] [--oncelik=] [--kategori=] [--atanan=]\n";
        echo "\n  --durum/--oncelik/--kategori: ID veya tanim adi (ornek: --durum=Cozuldu)\n";
        echo "  --atanan: kullanici_id, ad soyad, ben (kaynak kullanicisi) veya bos\n";
        echo "  --onizleme: hicbir kayit yazilmaz, yalnizca yapilacak islem raporlanir (taslak)\n";
        exit(0);
    }

    $ticketId = ticketIdCoz($hedef);
    if ($ticketId <= 0) {
        throw new Exception('Gecerli bir ticket id veya ticket-detay linki verin.');
    }

    $db = Database::getInstance();
    $projeKoku = str_replace('\\', '/', realpath(__DIR__ . '/../..'));
    // vhost kökü: <kok>/<domain>/<subdomain>/admin/cron -> 4 seviye yukarı
    $vhostRoot = $args['secenek']['vhost-root'] ?? (realpath(__DIR__ . '/../../../..') ?: '');

    if ($komut === 'detay') {
        $cikti = komutDetay($db, $ticketId, $vhostRoot);
        echo json_encode(['success' => true, 'data' => $cikti], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;
        exit(0);
    }

    if ($komut === 'yanit') {
        $kaynakKod = strtoupper($args['secenek']['kaynak'] ?? AJAN_VARSAYILAN_KAYNAK);
        $ajan = ajanKaynagiGetir($db, $kaynakKod);

        $mesajText = metinOku($args['secenek']['mesaj-dosya'] ?? null, $projeKoku);
        $icNotText = metinOku($args['secenek']['icnot-dosya'] ?? null, $projeKoku);
        $sonuc = komutYanit($db, $ticketId, $ajan, $mesajText, $icNotText, $args['secenek']);
        echo json_encode(['success' => true, 'data' => $sonuc], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;
        exit(0);
    }

    if ($komut === 'guncelle') {
        $kaynakKod = strtoupper($args['secenek']['kaynak'] ?? AJAN_VARSAYILAN_KAYNAK);
        $ajan = ajanKaynagiGetir($db, $kaynakKod);

        $sonuc = komutGuncelle($db, $ticketId, $ajan, $args['secenek']);
        echo json_encode(['success' => true, 'data' => $sonuc], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;
        exit(0);
    }

    throw new Exception("Gecersiz komut: {$komut}");
} catch (Throwable $e) {
    fwrite(STDERR, json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL);
    exit(1);
}
