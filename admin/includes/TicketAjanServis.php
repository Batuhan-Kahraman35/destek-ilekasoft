<?php
/**
 * Ticket Ajan Servisi
 *
 * ticket-ajan.php (CLI) ve mcp/index.php (Claude bağlayıcısı) ortak komut katmanı.
 *
 * $ajan dizisi:
 *   kullanici : işlemi yazan hesap (CLI: AI kaynağı, MCP: bağlanan personel)
 *   sahip     : "ben" / otomatik atamada kullanılan personel (CLI: BATUHAN, MCP: bağlanan personel)
 *   yetki     : PageAuth::checkPagePermissions() çıktısı. null ise (CLI) yetki filtresi uygulanmaz;
 *               dolu ise (MCP) ticket erişimi ve atama panel kurallarıyla sınırlanır.
 */

require_once __DIR__ . '/TicketService.php';
require_once __DIR__ . '/GorevHatirlatma.php';
require_once __DIR__ . '/PageAuth.php';

/**
 * Yetki bağlamı varsa (MCP) ticket'a erişimi doğrular. CLI'da (yetki = null) kontrol yapılmaz.
 */
function ajanTicketErisimDogrula(Database $db, array $ajan, int $ticketId): void
{
    if (!isset($ajan['yetki']) || $ajan['yetki'] === null) {
        return;
    }

    $ticket = $db->fetchOne(
        "SELECT Tickets_id, Tickets_kaynak_id, Tickets_atanan_kullanici_id, Tickets_olusturan_kullanici_id
         FROM Tickets WHERE Tickets_id = ? AND Durum = 1",
        [$ticketId]
    );
    if (!$ticket) {
        throw new Exception("Ticket bulunamadi: {$ticketId}");
    }

    if (!PageAuth::ticketErisebilirMi((int)$ajan['kullanici']['kullanici_id'], $ajan['yetki'], $ticket)) {
        throw new Exception("Bu ticket'a erisim yetkiniz yok: {$ticketId}");
    }
}

/**
 * Panel kuralı: atamayı yalnız yönetici değiştirir. Yönetici olmayan personel (MCP) yalnız kendine atayabilir.
 * $atananId: null = dokunma, 0 = kaldır, >0 = kullanıcı
 */
function ajanAtamaYetkisiDogrula(array $ajan, ?int $atananId): void
{
    if ($atananId === null || !isset($ajan['yetki']) || $ajan['yetki'] === null || !empty($ajan['yetki']['is_admin'])) {
        return;
    }

    if ($atananId !== (int)$ajan['kullanici']['kullanici_id']) {
        throw new Exception('Atama yetkiniz yok: yalnizca kendinize atama yapabilirsiniz (atanan="ben").');
    }
}

/**
 * Durum / öncelik / kategori / personel / hatırlatma seçenekleri (ID + ad).
 */
function ajanSecenekleriGetir(Database $db): array
{
    return [
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
        'hatirlatma_secenekleri' => array_map(
            static fn($s) => ['id' => $s['id'], 'ad' => $s['ad'], 'tarih' => GorevHatirlatma::tarihHesapla($s)],
            GorevHatirlatma::secenekler($db)
        ),
        'hatirlatma_kanallari' => array_map(
            static fn($k) => ['id' => $k['id'], 'kod' => $k['kod'], 'ad' => $k['ad']],
            GorevHatirlatma::kanallar($db)
        ),
    ];
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
 * Ajanı ve ajanın sahibini getirir.
 * kullanici: yanıtı yazan hesap (AI kaynağı), sahip: "ben" / otomatik atamada kullanılan personel (BATUHAN kaynağı).
 */
function ajanGetir(Database $db, string $kaynakKod, string $sahipKod): array
{
    $ajan = ajanKaynagiGetir($db, $kaynakKod);
    $ajan['sahip'] = $kaynakKod === $sahipKod
        ? $ajan['kullanici']
        : ajanKaynagiGetir($db, $sahipKod)['kullanici'];

    return $ajan;
}

/**
 * Atama bildiriminde "kendine atama" sayılan kullanıcılar: işlemi yapan hesap ve ajanın sahibi.
 */
function kendiKullaniciIdleri(array $ajan): array
{
    return array_unique([(int)$ajan['kullanici']['kullanici_id'], (int)$ajan['sahip']['kullanici_id']]);
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

    if (in_array($deger, ['ben', 'kendim'], true)) {
        return (int)$ajan['sahip']['kullanici_id'];
    }

    if ($deger === 'ajan') {
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
    ajanAtamaYetkisiDogrula($ajan, $atananId);

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
function atamaBildirimiPlani(?array $atama, array $kendiIdler): string
{
    if (!$atama || $atama['yeni'] <= 0) {
        return 'gonderilmeyecek';
    }
    if ($atama['yeni'] === $atama['eski'] || in_array($atama['yeni'], $kendiIdler, true)) {
        return 'gonderilmeyecek (kendine atama veya degisiklik yok)';
    }
    return 'gonderilecek (ticket_atandi)';
}

/**
 * Atama degistiyse bildirimi tetikler, sonuc metnini dondurur.
 */
function atamaBildirimiUygula(Database $db, int $ticketId, ?array $atama, int $islemKullaniciId, array $kendiIdler): string
{
    if (!$atama || $atama['yeni'] <= 0) {
        return 'gonderilmedi';
    }

    if ($atama['yeni'] === $atama['eski'] || in_array($atama['yeni'], $kendiIdler, true)) {
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
    ajanTicketErisimDogrula($db, $ajan, $ticketId);

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
            'bildirim'    => atamaBildirimiPlani($hazirlik['atama'], kendiKullaniciIdleri($ajan)),
            'islem_yapan' => $islemYapan,
        ];
    }

    $db->update('Tickets', $update, ['Tickets_id' => $ticketId]);

    return [
        'onizleme' => false,
        'degisen'  => $hazirlik['degisen'],
        'bildirim' => atamaBildirimiUygula($db, $ticketId, $hazirlik['atama'], $kullaniciId, kendiKullaniciIdleri($ajan)),
        'tarih'    => $now,
        'islem_yapan' => $islemYapan,
    ];
}
/**
 * detay komutu: ticket + mesajlar (iç notlar dahil) + ekler + proje bilgisi
 */
function komutDetay(Database $db, int $ticketId, string $vhostRoot, ?array $ajan = null): array
{
    if ($ajan !== null) {
        ajanTicketErisimDogrula($db, $ajan, $ticketId);
    }

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
    $secenekler = ajanSecenekleriGetir($db);

    $gorevler = $db->fetchAll(
        "SELECT g.Ticket_Gorevler_id AS gorev_id,
                g.Ticket_Gorevler_baslik AS baslik,
                g.Ticket_Gorevler_aciklama AS aciklama,
                d.Ticket_Durumlar_ad AS durum,
                LTRIM(RTRIM(ISNULL(k.kullanici_ad, '') + ' ' + ISNULL(k.kullanici_soyad, ''))) AS atanan,
                CONVERT(VARCHAR(16), g.Ticket_Gorevler_hatirlatma_tarihi, 120)          AS hatirlatma_tarihi,
                CONVERT(VARCHAR(16), g.Ticket_Gorevler_hatirlatma_gonderim_tarihi, 120) AS hatirlatma_gonderim_tarihi,
                g.Ticket_Gorevler_erteleme_sayisi AS erteleme_sayisi,
                CONVERT(VARCHAR(16), g.OlusturmaTarihi, 120) AS olusturma_tarihi
         FROM Ticket_Gorevler g
         LEFT JOIN Ticket_Durumlar d ON d.Ticket_Durumlar_id = g.Ticket_Gorevler_durum_id
         LEFT JOIN kullanicilar    k ON k.kullanici_id = g.Ticket_Gorevler_atanan_id
         WHERE g.Ticket_Gorevler_ticket_id = ? AND g.Durum = 1
         ORDER BY g.Ticket_Gorevler_sira, g.Ticket_Gorevler_id",
        [$ticketId]
    ) ?: [];
    foreach ($gorevler as &$g) {
        $g['alicilar'] = $g['hatirlatma_tarihi'] ? GorevHatirlatma::alicilariGetir($db, (int)$g['gorev_id']) : [];
    }
    unset($g);

    return [
        'ticket' => $ticket,
        'proje' => projeDiziniBul($ticket['kaynak_api_url'] ?? null, $vhostRoot),
        'secenekler' => $secenekler,
        'gorevler' => $gorevler,
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
 * --ek-dosya seçeneğindeki dosyaları doğrular (virgülle birden fazla dosya verilebilir).
 * Panel ile aynı kurallar: izinli MIME listesi, en fazla 10 MB.
 */
function ekDosyalariHazirla(?string $deger, string $projeKoku): array
{
    if (empty($deger)) {
        return [];
    }

    $izinliMime = ['image/jpeg', 'image/png', 'image/gif', 'application/pdf',
                   'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                   'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                   'text/plain'];

    $ekler = [];
    foreach (array_filter(array_map('trim', explode(',', $deger))) as $yol) {
        $tamYol = $yol;
        if (!is_file($tamYol)) {
            $tamYol = $projeKoku . '/' . ltrim(str_replace('\\', '/', $yol), '/');
        }
        if (!is_file($tamYol)) {
            throw new Exception("Ek dosya bulunamadi: {$yol}");
        }

        $mime = (string)mime_content_type($tamYol);
        if (!in_array($mime, $izinliMime, true)) {
            throw new Exception("Ek dosya turu izinli degil ({$mime}): {$yol}");
        }

        $boyut = (int)filesize($tamYol);
        if ($boyut <= 0 || $boyut > 10 * 1024 * 1024) {
            throw new Exception("Ek dosya boyutu gecersiz (en fazla 10 MB): {$yol}");
        }

        $ekler[] = ['yol' => $tamYol, 'ad' => basename($tamYol), 'mime' => $mime, 'boyut' => $boyut];
    }

    return $ekler;
}

/**
 * Doğrulanmış ekleri ticket klasörüne kopyalar ve Ticket_Ekler'e mesaja bağlı kaydeder
 */
function ekDosyalariKaydet(Database $db, int $ticketId, int $mesajId, array $ekler, int $kullaniciId, string $now): array
{
    $uploadDir = __DIR__ . '/../../assets/uploads/tickets/' . $ticketId . '/';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
        throw new Exception('Dosya klasoru olusturulamadi.');
    }

    $kayitlar = [];
    foreach ($ekler as $ek) {
        $ext = preg_replace('/[^a-z0-9]/', '', strtolower((string)pathinfo($ek['ad'], PATHINFO_EXTENSION)));
        $guvenliAd = bin2hex(random_bytes(8)) . ($ext !== '' ? '.' . $ext : '');
        if (!copy($ek['yol'], $uploadDir . $guvenliAd)) {
            throw new Exception("Ek dosya kopyalanamadi: {$ek['ad']}");
        }

        $kayitlar[] = (int)$db->insert('Ticket_Ekler', [
            'Ticket_Ekler_ticket_id'    => $ticketId,
            'Ticket_Ekler_mesaj_id'     => $mesajId,
            'Ticket_Ekler_dosya_adi'    => $ek['ad'],
            'Ticket_Ekler_dosya_yolu'   => 'assets/uploads/tickets/' . $ticketId . '/' . $guvenliAd,
            'Ticket_Ekler_dosya_boyutu' => $ek['boyut'],
            'Ticket_Ekler_mime_turu'    => $ek['mime'],
            'OlusturanKullanici'        => $kullaniciId,
            'OlusturmaTarihi'           => $now,
            'GuncelleyenKullanici'      => $kullaniciId,
            'GuncellemeTarihi'          => $now,
            'Durum'                     => 1,
        ]);
    }

    return $kayitlar;
}

/**
 * yanit komutu: müşteri yanıtı ve/veya iç not ekler (panel davranışıyla birebir)
 */
function komutYanit(Database $db, int $ticketId, array $ajan, string $mesajText, string $icNotText, array $secenek, array $ekler = []): array
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
    ajanTicketErisimDogrula($db, $ajan, $ticketId);

    $kullaniciId = (int)$ajan['kullanici']['kullanici_id'];
    $now = app_now();
    $sonuc = ['onizleme' => false, 'mesaj_id' => null, 'ic_not_id' => null];

    // --atanan verilmediyse ve ticket kimseye atanmamissa, ajanin sahibine atanir.
    // Ticket zaten birine atanmissa atamaya dokunulmaz. --atanan=bos ile bu davranis atlanabilir.
    $otomatikAtama = false;
    if (!isset($secenek['atanan']) || trim((string)$secenek['atanan']) === '') {
        $mevcut = $db->fetchOne(
            "SELECT Tickets_atanan_kullanici_id FROM Tickets WHERE Tickets_id = ?",
            [$ticketId]
        );
        if ((int)($mevcut['Tickets_atanan_kullanici_id'] ?? 0) <= 0) {
            $secenek['atanan'] = (string)$ajan['sahip']['kullanici_id'];
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
                ? 'ticket atanmamisti, ' . trim($ajan['sahip']['kullanici_ad'] . ' ' . ($ajan['sahip']['kullanici_soyad'] ?? '')) . ' kullanicisina atanacak'
                : false,
            'musteri_yaniti' => $mesajText !== '' ? metinOzeti($mesajText) : null,
            'ic_not'         => $icNotText !== '' ? metinOzeti($icNotText) : null,
            'ekler'          => array_map(fn($ek) => $ek['ad'] . ' (' . $ek['mime'] . ', ' . $ek['boyut'] . ' bayt)', $ekler),
            'ek_hedef'       => empty($ekler) ? null : ($mesajText !== '' ? 'musteri yaniti' : 'ic not'),
            'degisen'        => $hazirlik['degisen'],
            'tickets_update' => $ticketUpdate,
            'bildirim'       => $mesajText !== ''
                ? 'gonderilecek (yeni mesaj: mail/WhatsApp)'
                : 'gonderilmeyecek (yalnizca ic not)',
            'atama_bildirimi' => atamaBildirimiPlani($hazirlik['atama'], kendiKullaniciIdleri($ajan)),
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

        if (!empty($ekler)) {
            $sonuc['ek_idler'] = ekDosyalariKaydet($db, $ticketId, $sonuc['mesaj_id'], $ekler, $kullaniciId, $now);
        }
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

        // Müşteri yanıtı yoksa ekler iç nota bağlanır (müşteri iç not eklerini görmez)
        if (!empty($ekler) && $mesajText === '') {
            $sonuc['ek_idler'] = ekDosyalariKaydet($db, $ticketId, $sonuc['ic_not_id'], $ekler, $kullaniciId, $now);
        }
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

    $sonuc['atama_bildirimi'] = atamaBildirimiUygula($db, $ticketId, $hazirlik['atama'], $kullaniciId, kendiKullaniciIdleri($ajan));

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

/**
 * --proje degerini Ticket_Kaynak kaydina cevirir.
 * Deger kaynak kodu (PORTAL) veya host (portal.ornekproje.com) olabilir.
 */
function projeKaynagiCoz(Database $db, ?string $deger): ?array
{
    if ($deger === null || trim($deger) === '') {
        return null;
    }

    $deger = mb_strtolower(trim($deger), 'UTF-8');
    $host  = parse_url(str_contains($deger, '://') ? $deger : 'https://' . $deger, PHP_URL_HOST) ?: $deger;

    $kaynaklar = $db->fetchAll(
        "SELECT Ticket_Kaynak_id, Ticket_Kaynak_kod, Ticket_Kaynak_ad, Ticket_Kaynak_api_url
         FROM Ticket_Kaynak WHERE Durum = 1 ORDER BY Ticket_Kaynak_id"
    ) ?: [];

    $adaylar = [];
    foreach ($kaynaklar as $k) {
        if (mb_strtolower((string)$k['Ticket_Kaynak_kod'], 'UTF-8') === $deger) {
            return $k;
        }
        $kHost = strtolower((string)parse_url((string)$k['Ticket_Kaynak_api_url'], PHP_URL_HOST));
        if ($kHost !== '' && $kHost === $host) {
            $adaylar[] = $k;
        }
    }

    if (count($adaylar) === 1) {
        return $adaylar[0];
    }

    $liste = implode(', ', array_map(
        static fn($k) => $k['Ticket_Kaynak_kod'] . '=' . ($k['Ticket_Kaynak_api_url'] ?? '-'),
        $adaylar ?: $kaynaklar
    ));
    $onEk = $adaylar ? 'Proje icin birden fazla kaynak (kod verin)' : 'Proje kaynagi bulunamadi';
    throw new Exception("{$onEk}: '{$deger}'. Secenekler: {$liste}");
}

/**
 * ara komutu: konu ve mesajlarda (ic notlar dahil) arar.
 * Birden fazla kelime verilirse her kelime konu veya herhangi bir mesajda gecmelidir.
 * Acik ve kapali tum ticket'lar doner (--durum=acik|kapali ile daraltilabilir).
 */
function komutAra(Database $db, string $ifade, array $secenek, ?array $ajan = null): array
{
    $kelimeler = array_values(array_filter(preg_split('/\s+/u', trim($ifade)) ?: [], static fn($k) => $k !== ''));
    if (!$kelimeler) {
        throw new Exception('Aranacak ifade verin. Ornek: ara "Hepsiburada key"');
    }

    $where  = ['t.Durum = 1'];
    $params = [];

    foreach ($kelimeler as $kelime) {
        $kalip = '%' . str_replace(['[', '%', '_'], ['[[]', '[%]', '[_]'], $kelime) . '%';
        $where[] = "(t.Tickets_konu LIKE ? OR EXISTS (
                        SELECT 1 FROM Ticket_Mesajlar m
                        WHERE m.Ticket_Mesajlar_ticket_id = t.Tickets_id AND m.Durum = 1
                          AND m.Ticket_Mesajlar_mesaj LIKE ?))";
        $params[] = $kalip;
        $params[] = $kalip;
    }

    $proje = projeKaynagiCoz($db, $secenek['proje'] ?? null);
    if ($proje) {
        $where[]  = 't.Tickets_kaynak_id = ?';
        $params[] = (int)$proje['Ticket_Kaynak_id'];
    }

    $durumFiltre = mb_strtolower(trim((string)($secenek['durum'] ?? 'hepsi')), 'UTF-8');
    if ($durumFiltre === 'acik') {
        $where[] = 'ISNULL(dur.Ticket_Durumlar_kapatma_durumu, 0) = 0';
    } elseif ($durumFiltre === 'kapali') {
        $where[] = 'dur.Ticket_Durumlar_kapatma_durumu = 1';
    }

    // Yetki bağlamı varsa (MCP) panel listesiyle aynı görünürlük filtresi
    $yetkiFiltre = ($ajan !== null && ($ajan['yetki'] ?? null) !== null)
        ? PageAuth::getTicketSqlWhere((int)$ajan['kullanici']['kullanici_id'], $ajan['yetki'], 't.Tickets_kaynak_id', 't.Tickets_atanan_kullanici_id', 't.Tickets_olusturan_kullanici_id')
        : '';

    $limit = max(1, min(100, (int)($secenek['limit'] ?? 20)));

    $satirlar = $db->fetchAll(
        "SELECT TOP {$limit}
            t.Tickets_id   AS id,
            t.Tickets_no   AS no,
            t.Tickets_konu AS konu,
            dur.Ticket_Durumlar_ad AS durum,
            CAST(ISNULL(dur.Ticket_Durumlar_kapatma_durumu, 0) AS INT) AS kapali,
            kay.Ticket_Kaynak_kod AS proje,
            LTRIM(RTRIM(ISNULL(at.kullanici_ad, '') + ' ' + ISNULL(at.kullanici_soyad, ''))) AS atanan,
            CONVERT(VARCHAR(16), t.OlusturmaTarihi, 120) AS acilis,
            CONVERT(VARCHAR(16), COALESCE(t.Tickets_son_yanit_tarihi, t.GuncellemeTarihi, t.OlusturmaTarihi), 120) AS son_hareket
         FROM Tickets t
         LEFT JOIN Ticket_Durumlar dur ON t.Tickets_durum_id  = dur.Ticket_Durumlar_id
         LEFT JOIN Ticket_Kaynak   kay ON t.Tickets_kaynak_id = kay.Ticket_Kaynak_id
         LEFT JOIN kullanicilar    at  ON t.Tickets_atanan_kullanici_id = at.kullanici_id
         WHERE " . implode(' AND ', $where) . $yetkiFiltre . "
         ORDER BY COALESCE(t.Tickets_son_yanit_tarihi, t.GuncellemeTarihi, t.OlusturmaTarihi) DESC",
        $params
    ) ?: [];

    foreach ($satirlar as &$s) {
        $s['eslesme'] = array_values(array_filter($kelimeler, static fn($k) => mb_stripos((string)$s['konu'], $k, 0, 'UTF-8') !== false))
            ? 'konu' : 'mesaj';
        $s['kapali'] = (bool)$s['kapali'];
    }
    unset($s);

    return [
        'ifade'    => $ifade,
        'proje'    => $proje['Ticket_Kaynak_kod'] ?? null,
        'durum'    => $durumFiltre,
        'adet'     => count($satirlar),
        'limit'    => $limit,
        'ticketlar' => $satirlar,
    ];
}

/**
 * Verilmeyen kategori/oncelik icin varsayilan tanim (sira olarak ilk aktif kayit, ya da ada gore).
 */
function tanimVarsayilan(Database $db, string $tablo, string $onek, ?string $deger, string $varsayilanAd, string $etiket): array
{
    $sonuc = tanimCoz($db, $tablo, $onek, $deger, $etiket);
    if ($sonuc) {
        return $sonuc;
    }

    try {
        $sonuc = tanimCoz($db, $tablo, $onek, $varsayilanAd, $etiket);
    } catch (Exception $e) {
        $sonuc = null;
    }
    if ($sonuc) {
        return $sonuc;
    }

    $ilk = $db->fetchOne("SELECT TOP 1 * FROM {$tablo} WHERE Durum = 1 ORDER BY {$onek}_sira");
    if (!$ilk) {
        throw new Exception("{$etiket} tanimi bulunamadi (aktif kayit yok).");
    }
    return ['id' => (int)$ilk[$onek . '_id'], 'ad' => (string)$ilk[$onek . '_ad'], 'satir' => $ilk];
}

/**
 * ac komutu: yeni ticket acar (panel/TicketService ile ayni akis).
 * Ilk mesaj --mesaj-dosya, istege bagli ilk iç not --icnot-dosya.
 * --atanan verilmezse ticket ajanin sahibine (ben) atanir; --durum verilirse acilistan sonra uygulanir.
 */
function komutAc(Database $db, array $ajan, string $konu, string $mesajText, string $icNotText, array $secenek): array
{
    $konu = trim($konu);
    if ($konu === '') {
        throw new Exception('Konu bos olamaz (--konu="...").');
    }
    if (mb_strlen($konu, 'UTF-8') > 200) {
        throw new Exception('Konu en fazla 200 karakter olabilir.');
    }
    if ($mesajText === '') {
        throw new Exception('Ilk mesaj bos olamaz (--mesaj-dosya=...).');
    }

    $kategori = tanimVarsayilan($db, 'Ticket_Kategoriler', 'Ticket_Kategoriler', $secenek['kategori'] ?? null, 'Genel', 'Kategori');
    $oncelik  = tanimVarsayilan($db, 'Ticket_Oncelikler', 'Ticket_Oncelikler', $secenek['oncelik'] ?? null, 'Normal', 'Oncelik');
    $durum    = tanimCoz($db, 'Ticket_Durumlar', 'Ticket_Durumlar', $secenek['durum'] ?? null, 'Durum');
    $proje    = projeKaynagiCoz($db, $secenek['proje'] ?? null);

    $atananId = atananCoz($db, ($secenek['atanan'] ?? '') !== '' ? $secenek['atanan'] : 'ben', $ajan);
    ajanAtamaYetkisiDogrula($ajan, $atananId);

    // Ayni konuyla acik ticket varsa yenisi acilmaz (mevcut ticket'a yanit yazilmali)
    $mukerrer = $db->fetchAll(
        "SELECT t.Tickets_id AS id, t.Tickets_no AS no, dur.Ticket_Durumlar_ad AS durum
         FROM Tickets t
         LEFT JOIN Ticket_Durumlar dur ON t.Tickets_durum_id = dur.Ticket_Durumlar_id
         WHERE t.Durum = 1 AND t.Tickets_konu = ?",
        [$konu]
    ) ?: [];
    if ($mukerrer && empty($secenek['zorla'])) {
        throw new Exception('Ayni konulu ticket zaten var: ' . implode(', ', array_map(
            static fn($r) => '#' . $r['id'] . ' ' . $r['no'] . ' (' . $r['durum'] . ')', $mukerrer
        )) . '. Yeni kayit icin "yanit" komutunu kullanin (yine de acmak icin --zorla).');
    }

    $kullaniciId = (int)$ajan['kullanici']['kullanici_id'];
    $yazan = trim($ajan['kullanici']['kullanici_ad'] . ' ' . ($ajan['kullanici']['kullanici_soyad'] ?? ''));
    $atananAd = null;
    if ($atananId) {
        $r = $db->fetchOne("SELECT kullanici_ad, kullanici_soyad FROM kullanicilar WHERE kullanici_id = ?", [$atananId]);
        $atananAd = trim(($r['kullanici_ad'] ?? '') . ' ' . ($r['kullanici_soyad'] ?? ''));
    }

    $plan = [
        'konu'     => $konu,
        'proje'    => $proje ? $proje['Ticket_Kaynak_kod'] . ' (' . ($proje['Ticket_Kaynak_api_url'] ?? '-') . ')' : null,
        'kategori' => $kategori['ad'],
        'oncelik'  => $oncelik['ad'],
        'durum'    => $durum['ad'] ?? 'ilk acik durum',
        'atanan'   => $atananAd,
        'acan'     => $yazan,
        'ilk_mesaj' => metinOzeti($mesajText),
        'ic_not'   => $icNotText !== '' ? metinOzeti($icNotText) : null,
    ];

    if (onizlemeMi($secenek)) {
        return [
            'onizleme' => true,
            'uyari'    => 'ONIZLEME: ticket acilmadi, bildirim gonderilmedi. Acmak icin --onizleme bayragini kaldirin.',
            'plan'     => $plan,
            'bildirim' => 'yeni_ticket bildirimi tetiklenecek (bildirim kurallarina gore)',
        ];
    }

    $service = new TicketService($db);
    $olustur = $service->ticketOlustur([
        'konu'                   => $konu,
        'mesaj'                  => $mesajText,
        'kategori_id'            => $kategori['id'],
        'oncelik_id'             => $oncelik['id'],
        'atanan_id'              => $atananId ?: null,
        'olusturan_kullanici_id' => $kullaniciId,
        'islem_kullanici_id'     => $kullaniciId,
        'kaynak_id'              => $proje ? (int)$proje['Ticket_Kaynak_id'] : null,
        'mesaj_kaynak'           => 'panel',
    ]);
    $ticketId = (int)$olustur['ticket_id'];

    $sonuc = ['onizleme' => false, 'ticket_id' => $ticketId, 'ticket_no' => $olustur['ticket_no'], 'plan' => $plan];

    // Ilk iç not ve durum: yanit komutuyla ayni akis (iç notta bildirim gitmez)
    if ($icNotText !== '' || $durum) {
        $ek = ['atanan' => (string)($atananId ?: 'bos')];
        if ($durum) {
            $ek['durum'] = (string)$durum['id'];
        }
        if ($icNotText !== '') {
            $sonuc['ic_not'] = komutYanit($db, $ticketId, $ajan, '', $icNotText, $ek);
        } else {
            $sonuc['guncelle'] = komutGuncelle($db, $ticketId, $ajan, $ek);
        }
    }

    $sonuc['link'] = 'https://destek.ornekproje.com/admin/ticket-detay?id=' . $ticketId;
    return $sonuc;
}

// ─────────────────────────────────────────────────────────────
// Görev + hatırlatma komutları
// ─────────────────────────────────────────────────────────────

/**
 * --alici ve --kanal seceneklerinden [kullanici_id => [kanal_id]] uretir.
 * Kanal verilmezse yalniz PUSH; telefonu/e-postasi olmayan kisiye WHATSAPP/MAIL secilirse hata.
 *
 * @param int $varsayilanAlici --alici verilmezse kullanilacak kisi
 */
function gorevAlicilariHazirla(Database $db, array $secenek, array $ajan, int $varsayilanAlici): array
{
    $aliciIdler = [];
    $aliciGirdi = trim((string)($secenek['alici'] ?? ''));
    if ($aliciGirdi !== '') {
        foreach (array_filter(array_map('trim', explode(',', $aliciGirdi))) as $parca) {
            $kid = atananCoz($db, $parca, $ajan);
            if (!$kid) throw new Exception("Gecersiz alici: {$parca}");
            $aliciIdler[] = $kid;
        }
    } else {
        $aliciIdler[] = $varsayilanAlici;
    }

    $pushId  = GorevHatirlatma::pushKanalId($db);
    $kanallar = [];
    foreach (array_filter(array_map('trim', explode(',', (string)($secenek['kanal'] ?? '')))) as $parca) {
        $k = GorevHatirlatma::kanalBul($db, $parca);
        if (!$k) {
            $liste = implode(', ', array_map(static fn($x) => $x['id'] . '=' . $x['kod'], GorevHatirlatma::kanallar($db)));
            throw new Exception("Kanal bulunamadi: '{$parca}'. Secenekler: {$liste}");
        }
        $kanallar[(int)$k['id']] = $k;
    }

    $sonuc = [];
    foreach (array_unique($aliciIdler) as $kid) {
        $kisi = $db->fetchOne(
            "SELECT kullanici_ad, kullanici_soyad, kullanici_telefon, kullanici_email
             FROM kullanicilar WHERE kullanici_id = ? AND kullanici_durum = 1",
            [$kid]
        );
        if (!$kisi) throw new Exception("Alici bulunamadi veya pasif: kullanici_id={$kid}");
        $ad = trim($kisi['kullanici_ad'] . ' ' . ($kisi['kullanici_soyad'] ?? ''));

        $liste = [$pushId];
        foreach ($kanallar as $kanalId => $k) {
            $kod = strtoupper($k['kod']);
            if ($kod === 'WHATSAPP' && trim((string)$kisi['kullanici_telefon']) === '') {
                throw new Exception("{$ad} icin WhatsApp secilemez: telefon kaydi yok.");
            }
            if ($kod === 'MAIL' && trim((string)$kisi['kullanici_email']) === '') {
                throw new Exception("{$ad} icin e-posta secilemez: e-posta kaydi yok.");
            }
            $liste[] = $kanalId;
        }
        $sonuc[$kid] = ['ad' => $ad, 'kanallar' => array_values(array_unique($liste))];
    }
    return $sonuc;
}

/** Plan raporu icin kanal ID'lerini kodlara cevirir */
function gorevAliciOzeti(Database $db, array $alicilar): array
{
    $kodlar = array_column(GorevHatirlatma::kanallar($db), 'kod', 'id');
    $ozet = [];
    foreach ($alicilar as $kid => $a) {
        $ozet[] = [
            'kullanici_id' => $kid,
            'ad'           => $a['ad'],
            'kanallar'     => array_map(static fn($id) => $kodlar[$id] ?? (string)$id, $a['kanallar']),
        ];
    }
    return $ozet;
}

/**
 * gorev-ekle: ticket'a gorev ekler, istenirse hatirlatma kurar.
 * Hatirlatma verilip --alici verilmezse alici: atanan, o da yoksa ajanin sahibi (ben).
 */
function komutGorevEkle(Database $db, int $ticketId, array $ajan, array $secenek, string $projeKoku): array
{
    $ticket = $db->fetchOne("SELECT Tickets_id, Tickets_no FROM Tickets WHERE Tickets_id = ? AND Durum = 1", [$ticketId]);
    if (!$ticket) throw new Exception("Ticket bulunamadi: {$ticketId}");
    ajanTicketErisimDogrula($db, $ajan, $ticketId);

    $baslik = trim((string)($secenek['baslik'] ?? ''));
    if ($baslik === '') throw new Exception('--baslik zorunlu.');

    $aciklama = trim((string)($secenek['aciklama'] ?? ''));
    if ($aciklama === '' && !empty($secenek['aciklama-dosya'])) {
        $aciklama = metinOku($secenek['aciklama-dosya'], $projeKoku);
    }
    $aciklama = $aciklama !== '' ? $aciklama : null;

    $atananId = atananCoz($db, $secenek['atanan'] ?? null, $ajan) ?: null;

    $tarih = null;
    if (trim((string)($secenek['hatirlatma'] ?? '')) !== '') {
        $tarih = GorevHatirlatma::tarihCoz($db, (string)$secenek['hatirlatma']);
        if ($tarih <= app_now()) throw new Exception('Hatirlatma tarihi gecmiste olamaz.');
    } elseif (!empty($secenek['alici']) || !empty($secenek['kanal'])) {
        throw new Exception('--alici / --kanal icin --hatirlatma gerekli.');
    }

    $alicilar = $tarih !== null
        ? gorevAlicilariHazirla($db, $secenek, $ajan, $atananId ?: (int)$ajan['sahip']['kullanici_id'])
        : [];

    $kullaniciId = (int)$ajan['kullanici']['kullanici_id'];
    $atananAd = null;
    if ($atananId) {
        $r = $db->fetchOne("SELECT kullanici_ad, kullanici_soyad FROM kullanicilar WHERE kullanici_id = ?", [$atananId]);
        $atananAd = trim(($r['kullanici_ad'] ?? '') . ' ' . ($r['kullanici_soyad'] ?? ''));
    }

    $plan = [
        'ticket_no'  => $ticket['Tickets_no'],
        'baslik'     => $baslik,
        'aciklama'   => $aciklama,
        'atanan'     => $atananAd,
        'hatirlatma' => $tarih,
        'alicilar'   => gorevAliciOzeti($db, $alicilar),
        'olusturan'  => trim($ajan['kullanici']['kullanici_ad'] . ' ' . ($ajan['kullanici']['kullanici_soyad'] ?? '')),
    ];

    if (onizlemeMi($secenek)) {
        return [
            'onizleme' => true,
            'uyari'    => 'ONIZLEME: hicbir kayit yazilmadi. Kaydetmek icin --onizleme bayragini kaldirin.',
            'plan'     => $plan,
        ];
    }

    $now = app_now();
    $gorevId = (int)$db->insert('Ticket_Gorevler', [
        'Ticket_Gorevler_ticket_id'         => $ticketId,
        'Ticket_Gorevler_baslik'            => $baslik,
        'Ticket_Gorevler_aciklama'          => $aciklama,
        'Ticket_Gorevler_atanan_id'         => $atananId,
        'Ticket_Gorevler_durum_id'          => 1,
        'Ticket_Gorevler_sira'              => 0,
        'Ticket_Gorevler_hatirlatma_tarihi' => $tarih,
        'OlusturanKullanici'                => $kullaniciId,
        'OlusturmaTarihi'                   => $now,
        'GuncelleyenKullanici'              => $kullaniciId,
        'GuncellemeTarihi'                  => $now,
        'Durum'                             => 1,
    ]);

    if ($alicilar) {
        try {
            GorevHatirlatma::kaydet($db, $gorevId, $tarih, array_map(static fn($a) => $a['kanallar'], $alicilar), $kullaniciId);
        } catch (Throwable $e) {
            // Alicilar yazilamadiysa yarim gorev birakma
            $db->execute("DELETE FROM Ticket_Gorevler WHERE Ticket_Gorevler_id = ?", [$gorevId]);
            throw $e;
        }
    }

    return [
        'onizleme' => false,
        'gorev_id' => $gorevId,
        'plan'     => $plan,
        'link'     => 'https://destek.ornekproje.com/admin/ticket-detay?id=' . $ticketId,
    ];
}

/**
 * gorev-ertele: hatirlatmayi yeni tarihe tasir. Gorevde hatirlatma yoksa kurar (sayac artmaz).
 * --alici / --kanal verilirse alicilar da yeniden yazilir.
 */
function komutGorevErtele(Database $db, int $ticketId, array $ajan, array $secenek): array
{
    $gorevId = (int)($secenek['gorev'] ?? 0);
    if ($gorevId <= 0) throw new Exception('--gorev=ID zorunlu.');
    ajanTicketErisimDogrula($db, $ajan, $ticketId);

    $gorev = $db->fetchOne(
        "SELECT Ticket_Gorevler_id, Ticket_Gorevler_baslik, Ticket_Gorevler_atanan_id, Ticket_Gorevler_erteleme_sayisi,
                CONVERT(VARCHAR(16), Ticket_Gorevler_hatirlatma_tarihi, 120) AS hatirlatma_tarihi
         FROM Ticket_Gorevler
         WHERE Ticket_Gorevler_id = ? AND Ticket_Gorevler_ticket_id = ? AND Durum = 1",
        [$gorevId, $ticketId]
    );
    if (!$gorev) throw new Exception("Gorev bulunamadi: {$gorevId} (ticket {$ticketId})");

    if (trim((string)($secenek['hatirlatma'] ?? '')) === '') throw new Exception('--hatirlatma zorunlu.');
    $tarih = GorevHatirlatma::tarihCoz($db, (string)$secenek['hatirlatma']);
    if ($tarih <= app_now()) throw new Exception('Hatirlatma tarihi gecmiste olamaz.');

    $alicilar = null;
    if (!empty($secenek['alici']) || !empty($secenek['kanal'])) {
        $varsayilan = (int)($gorev['Ticket_Gorevler_atanan_id'] ?: $ajan['sahip']['kullanici_id']);
        $alicilar = gorevAlicilariHazirla($db, $secenek, $ajan, $varsayilan);
    }

    $islem = $gorev['hatirlatma_tarihi'] ? 'ertele' : 'kur';
    if ($alicilar !== null) $islem = 'kaydet (alicilar yeniden yazilir)';

    $plan = [
        'gorev_id'       => $gorevId,
        'baslik'         => $gorev['Ticket_Gorevler_baslik'],
        'eski_tarih'     => $gorev['hatirlatma_tarihi'],
        'yeni_tarih'     => $tarih,
        'islem'          => $islem,
        'erteleme_sayisi'=> (int)$gorev['Ticket_Gorevler_erteleme_sayisi'] + ($gorev['hatirlatma_tarihi'] ? 1 : 0),
        'alicilar'       => $alicilar !== null ? gorevAliciOzeti($db, $alicilar) : GorevHatirlatma::alicilariGetir($db, $gorevId),
    ];

    if (onizlemeMi($secenek)) {
        return [
            'onizleme' => true,
            'uyari'    => 'ONIZLEME: hicbir kayit yazilmadi. Kaydetmek icin --onizleme bayragini kaldirin.',
            'plan'     => $plan,
        ];
    }

    $kullaniciId = (int)$ajan['kullanici']['kullanici_id'];
    if ($gorev['hatirlatma_tarihi']) {
        GorevHatirlatma::ertele($db, $gorevId, $tarih, $kullaniciId);
        if ($alicilar !== null) {
            GorevHatirlatma::kaydet($db, $gorevId, $tarih, array_map(static fn($a) => $a['kanallar'], $alicilar), $kullaniciId);
        }
    } else {
        GorevHatirlatma::kaydet(
            $db, $gorevId, $tarih,
            $alicilar !== null ? array_map(static fn($a) => $a['kanallar'], $alicilar) : null,
            $kullaniciId
        );
    }

    return ['onizleme' => false, 'plan' => $plan];
}

