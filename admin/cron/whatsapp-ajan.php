<?php
/**
 * WhatsApp Ajan - CLI Aracı
 *
 * Bir WhatsApp numarasının yazışma geçmişini Evolution API'den okur ve
 * onay sonrası aynı numaraya yanıt gönderir. Ticket ajanının WhatsApp sürümü.
 * Yalnızca komut satırından çalışır, web üzerinden erişilemez.
 *
 * Kullanım:
 *   php admin/cron/whatsapp-ajan.php detay 905551234567
 *   php admin/cron/whatsapp-ajan.php detay 05551234567 --limit=50
 *   php admin/cron/whatsapp-ajan.php yanit 905551234567 --mesaj-dosya=temp/wa.txt --onizleme
 *   php admin/cron/whatsapp-ajan.php yanit 905551234567 --mesaj-dosya=temp/wa.txt
 *
 * Seçenekler:
 *   --limit=N         detay komutunda çekilecek mesaj sayısı (varsayılan: 30)
 *   --proje=          Çalışılacak proje: Ticket_Kaynak kodu, adı veya host'u
 *                     (örn. --proje=DIGITURKORNEKYAZILIM veya --proje=digiturk.ornekproje.com)
 *                     Verilmezse proje seçilmez; ipuçları raporlanır, karar kullanıcıya sorulur.
 *   --mesaj-dosya=    Gönderilecek metni içeren dosya (UTF-8)
 *   --mesaj=          Metni doğrudan verir (kısa mesajlar için; dosya tercih edilir)
 *   --onizleme        Taslak modu: mesaj GÖNDERİLMEZ, gidecek içerik raporlanır
 *                     (--taslak / --dry-run eş anlamlı)
 *
 * Not: Evolution yalnızca kendi devreye girdiği tarihten sonraki mesajları saklar.
 * Daha eski yazışmalar API üzerinden görünmez.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Bu arac yalnizca komut satirindan calistirilabilir.');
}

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/EntegrasyonHelper.php';

const WA_VARSAYILAN_LIMIT = 30;

/**
 * Argümanları ayrıştırır: konumsal argümanlar + --anahtar=deger seçenekleri
 */
function argAyristir(array $argv): array
{
    $konumsal = [];
    $secenek  = [];

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
 * "0555 123 45 67", "+90 555...", "905551234567" girdilerini 905551234567 biçimine getirir.
 */
function numaraNormalle(string $girdi): string
{
    $rakam = preg_replace('/\D/', '', $girdi);

    if ($rakam === '') {
        return '';
    }

    // 05551234567 -> 5551234567
    if (strlen($rakam) === 11 && str_starts_with($rakam, '0')) {
        $rakam = substr($rakam, 1);
    }

    // 5551234567 -> 905551234567
    if (strlen($rakam) === 10) {
        $rakam = '90' . $rakam;
    }

    return $rakam;
}

/**
 * Varsayılan WHATSAPP kanalını ve Evolution bağlantı bilgilerini getirir.
 */
function whatsappKanaliGetir(Database $db): array
{
    $kanal = $db->fetchOne(
        "SELECT ek.*
         FROM EntegrasyonKanallari ek
         INNER JOIN Entegrasyon e ON ek.Kanal_Entegrasyon_Id = e.Entegrasyon_Id
         WHERE e.Entegrasyon_Kod = 'WHATSAPP'
           AND ek.Kanal_Varsayilan = 1
           AND ek.Kanal_Durum = 1
         ORDER BY ek.Kanal_Id"
    );

    if (!$kanal) {
        throw new Exception('Varsayilan WHATSAPP kanali bulunamadi veya pasif.');
    }

    $params = json_decode($kanal['Kanal_Parametreler'] ?? '{}', true) ?: [];

    return [
        'kanal_id' => (int)$kanal['Kanal_Id'],
        'base_url' => rtrim((string)$kanal['Kanal_Endpoint'], '/'),
        'instance' => $params['instance'] ?? 'default',
        'apikey'   => (string)$kanal['Kanal_Sifre'],
    ];
}

/**
 * Evolution API'ye istek atar.
 */
function evolutionCagir(array $kanal, string $yol, $govde = null): array
{
    $url = $kanal['base_url'] . $yol;

    $ayar = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'apikey: ' . $kanal['apikey']],
    ];

    if ($govde !== null) {
        $ayar[CURLOPT_POST]       = true;
        $ayar[CURLOPT_POSTFIELDS] = json_encode($govde, JSON_UNESCAPED_UNICODE);
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, $ayar);
    $cevap    = curl_exec($ch);
    $httpKod  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlHata = curl_error($ch);
    curl_close($ch);

    if ($curlHata) {
        throw new Exception("Evolution baglanti hatasi ({$yol}): {$curlHata}");
    }

    $json = json_decode((string)$cevap, true);

    if ($httpKod < 200 || $httpKod >= 300) {
        $mesaj = is_array($json) ? json_encode($json, JSON_UNESCAPED_UNICODE) : (string)$cevap;
        throw new Exception("Evolution HTTP {$httpKod} ({$yol}): " . mb_substr($mesaj, 0, 400));
    }

    return is_array($json) ? $json : [];
}

/**
 * Evolution mesaj nesnesinden okunabilir metni çıkarır.
 */
function mesajMetniCoz(array $m): string
{
    $icerik = $m['message'] ?? [];

    $adaylar = [
        $icerik['conversation'] ?? null,
        $icerik['extendedTextMessage']['text'] ?? null,
        $icerik['imageMessage']['caption'] ?? null,
        $icerik['videoMessage']['caption'] ?? null,
        $icerik['documentMessage']['caption'] ?? null,
        $icerik['documentWithCaptionMessage']['message']['documentMessage']['caption'] ?? null,
        $icerik['buttonsResponseMessage']['selectedDisplayText'] ?? null,
        $icerik['listResponseMessage']['title'] ?? null,
        $icerik['reactionMessage']['text'] ?? null,
    ];

    foreach ($adaylar as $aday) {
        if (is_string($aday) && trim($aday) !== '') {
            return trim($aday);
        }
    }

    // Metin yoksa medya türünü bildir
    $tur = $m['messageType'] ?? 'bilinmeyen';
    $ek  = $icerik['documentMessage']['fileName']
        ?? $icerik['audioMessage']['mimetype']
        ?? $icerik['imageMessage']['mimetype']
        ?? null;

    return '[' . $tur . ($ek ? ': ' . $ek : '') . ']';
}

/**
 * Numaraya dair proje ipuçlarını toplar.
 *
 * Projeyi bu fonksiyon SECMEZ. Hangi projede calisilacagini kullanici (Batuhan) soyler;
 * --proje= ile verilmisse dogrulanip 'secilen' alanina yazilir. Burada uretilenler yalnizca
 * ipucudur ve tanimli Ticket_Kaynak host'lariyla eslesenlerle sinirlidir:
 *
 * 1) Numara destek panelindeki bir kullaniciya bagliysa, o kullanicinin ticketlarindaki kaynaklar.
 * 2) Yazisma metninde gecen ve tanimli bir kaynak host'una denk gelen ifadeler.
 *
 * Eslesmeyen domainler (ilovepdf.com gibi arac linkleri) proje sayilmaz, ayri alanda raporlanir.
 */
function projeIpuclari(Database $db, string $numara, array $mesajlar, ?string $projeGirdi): array
{
    $adaylar = [];

    // --- 1) Telefon -> kullanici -> ticket kaynagi
    $son10 = substr($numara, -10);

    $kullanicilar = $db->fetchAll(
        "SELECT TOP 5 kullanici_id, kullanici_ad, kullanici_soyad, kullanici_telefon
         FROM kullanicilar
         WHERE kullanici_telefon IS NOT NULL
           AND REPLACE(REPLACE(REPLACE(REPLACE(kullanici_telefon,' ',''),'-',''),'(',''),')','') LIKE ?",
        ['%' . $son10]
    );

    $kullaniciBilgi = null;

    if ($kullanicilar) {
        $kullaniciBilgi = [
            'kullanici_id' => (int)$kullanicilar[0]['kullanici_id'],
            'ad_soyad'     => trim(($kullanicilar[0]['kullanici_ad'] ?? '') . ' ' . ($kullanicilar[0]['kullanici_soyad'] ?? '')),
            'telefon'      => $kullanicilar[0]['kullanici_telefon'],
            'eslesme'      => count($kullanicilar),
        ];

        $kaynaklar = $db->fetchAll(
            "SELECT TOP 5
                    kay.Ticket_Kaynak_ad      AS kaynak_ad,
                    kay.Ticket_Kaynak_kod     AS kaynak_kod,
                    kay.Ticket_Kaynak_api_url AS api_url,
                    COUNT(*)                  AS ticket_sayisi,
                    MAX(t.Tickets_id)         AS son_ticket_id
             FROM Tickets t
             INNER JOIN Ticket_Kaynak kay ON t.Tickets_kaynak_id = kay.Ticket_Kaynak_id
             WHERE t.Tickets_olusturan_kullanici_id = ? AND t.Durum = 1
             GROUP BY kay.Ticket_Kaynak_ad, kay.Ticket_Kaynak_kod, kay.Ticket_Kaynak_api_url
             ORDER BY COUNT(*) DESC",
            [$kullaniciBilgi['kullanici_id']]
        );

        foreach ($kaynaklar as $k) {
            $host = $k['api_url'] ? parse_url($k['api_url'], PHP_URL_HOST) : null;
            $adaylar[] = [
                'kaynak'        => 'ticket_gecmisi',
                'host'          => $host ? strtolower($host) : null,
                'kaynak_ad'     => $k['kaynak_ad'],
                'kaynak_kod'    => $k['kaynak_kod'],
                'ticket_sayisi' => (int)$k['ticket_sayisi'],
                'son_ticket_id' => (int)$k['son_ticket_id'],
            ];
        }
    }

    // --- Tanimli kaynaklar
    $tanimliKaynaklar = $db->fetchAll(
        "SELECT Ticket_Kaynak_ad AS ad, Ticket_Kaynak_kod AS kod, Ticket_Kaynak_api_url AS api_url
         FROM Ticket_Kaynak
         WHERE Durum = 1"
    );

    $kaynakListe = array_map(static function (array $k): array {
        return [
            'ad'   => $k['ad'],
            'kod'  => $k['kod'],
            'host' => $k['api_url'] ? strtolower((string)parse_url($k['api_url'], PHP_URL_HOST)) : null,
        ];
    }, $tanimliKaynaklar);

    // --- 2) Yazisma metni: yalnizca tanimli kaynaklarla eslesenler ipucu sayilir
    $tumMetin = mb_strtolower(implode(' ', array_column($mesajlar, 'metin')));

    foreach ($kaynakListe as $k) {
        $host   = (string)($k['host'] ?? '');
        $etiket = $host !== '' ? explode('.', $host)[0] : mb_strtolower((string)$k['ad']);

        if ($etiket === '' || mb_strlen($etiket) < 4) {
            continue;
        }

        if (str_contains($tumMetin, $etiket)) {
            $adaylar[] = [
                'kaynak'     => 'mesaj_metni',
                'host'       => $host !== '' ? $host : null,
                'kaynak_ad'  => $k['ad'],
                'kaynak_kod' => $k['kod'],
            ];
        }
    }

    // Tanimli kaynaklara denk gelmeyen domainler proje degildir, yalniz bilgi olarak doner
    $tanimliHostlar = array_filter(array_column($kaynakListe, 'host'));
    $digerDomainler = [];

    if (preg_match_all('/\b([a-z0-9][a-z0-9\-]*\.)+(com\.tr|org\.tr|com|net|org|biz)\b/u', $tumMetin, $eslesme)) {
        foreach (array_unique($eslesme[0]) as $host) {
            $sade = preg_replace('/^www\./', '', $host);
            if (!in_array($sade, $tanimliHostlar, true) && !in_array($host, $tanimliHostlar, true)) {
                $digerDomainler[] = $host;
            }
        }
    }

    // --- Benzersiz host listesi (yalnizca tanimli kaynaklardan)
    $hostlar = [];
    foreach ($adaylar as $a) {
        if (!empty($a['host'])) {
            $hostlar[$a['host']] = true;
        }
    }
    $hostlar = array_keys($hostlar);

    // --- Kullanicinin belirttigi proje
    $secilen   = null;
    $secimHata = null;

    if ($projeGirdi !== null && trim($projeGirdi) !== '') {
        $aranan = mb_strtolower(trim($projeGirdi));
        foreach ($kaynakListe as $k) {
            $host = (string)($k['host'] ?? '');
            if ($aranan === mb_strtolower((string)$k['kod'])
                || $aranan === mb_strtolower((string)$k['ad'])
                || ($host !== '' && ($aranan === $host || $aranan === preg_replace('/^www\./', '', $host)))
            ) {
                $secilen = $k;
                break;
            }
        }
        if ($secilen === null) {
            $secimHata = "'{$projeGirdi}' tanimli bir kaynakla eslesmedi. tum_kaynaklar listesine bakin.";
        }
    }

    return [
        'secilen'            => $secilen,
        'secim_hatasi'       => $secimHata,
        'kullanici'          => $kullaniciBilgi,
        'ipuclari'           => $adaylar,
        'ipucu_hostlar'      => $hostlar,
        'metindeki_diger_domainler' => array_values($digerDomainler),
        'not'                => $secilen !== null
            ? 'Proje kullanici tarafindan belirtildi; ipuclari yalniz dogrulama icindir.'
            : 'Proje belirtilmedi. Ipuclari kesin degildir; hangi projede calisilacagi Batuhan a sorulmali.',
        'tum_kaynaklar'      => $kaynakListe,
    ];
}

/**
 * detay komutu: numaranın yazışma geçmişini ve proje tahminini JSON olarak döner.
 */
function komutDetay(Database $db, string $numaraGirdi, array $secenek): array
{
    $numara = numaraNormalle($numaraGirdi);

    if ($numara === '') {
        throw new Exception('Gecerli bir telefon numarasi verilmedi.');
    }

    $limit = max(1, min(200, (int)($secenek['limit'] ?? WA_VARSAYILAN_LIMIT)));
    $jid   = $numara . '@s.whatsapp.net';
    $kanal = whatsappKanaliGetir($db);

    // WhatsApp LID'e geçmiş bireysel sohbetlerde key.remoteJid "<lid>@lid" olur,
    // numara ise key.remoteJidAlt alanında durur. Yalnız remoteJid ile arandığında
    // bu sohbetler hiç bulunamaz. Bu yüzden iki alan da sorgulanıp birleştirilir.
    $kayitlar = [];
    $toplam   = 0;
    foreach (['remoteJid', 'remoteJidAlt'] as $alan) {
        $cevap = evolutionCagir(
            $kanal,
            "/chat/findMessages/{$kanal['instance']}",
            ['where' => (object)['key' => (object)[$alan => $jid]], 'limit' => $limit]
        );

        $gelen   = $cevap['messages']['records'] ?? $cevap['records'] ?? [];
        $toplam += (int)($cevap['messages']['total'] ?? count($gelen));

        foreach ($gelen as $m) {
            // Aynı mesaj iki sorgudan da dönebilir; WhatsApp mesaj anahtarı ile tekilleştir
            $anahtar = (string)($m['key']['id'] ?? $m['id'] ?? uniqid('', true));
            $kayitlar[$anahtar] = $m;
        }
    }

    $kayitlar = array_values($kayitlar);
    $toplam   = max($toplam, count($kayitlar));

    $mesajlar = [];
    foreach ($kayitlar as $m) {
        $zaman = (int)($m['messageTimestamp'] ?? 0);
        $mesajlar[] = [
            'tarih' => $zaman > 0 ? date('Y-m-d H:i', $zaman) : null,
            'zaman' => $zaman,
            'yon'   => !empty($m['key']['fromMe']) ? 'giden' : 'gelen',
            'kisi'  => $m['pushName'] ?? null,
            'tur'   => $m['messageType'] ?? null,
            'metin' => mesajMetniCoz($m),
        ];
    }

    usort($mesajlar, static fn(array $a, array $b): int => $a['zaman'] <=> $b['zaman']);

    // Kisi adi: gelen mesajlardaki pushName
    $kisiAdi = null;
    foreach ($mesajlar as $m) {
        if ($m['yon'] === 'gelen' && !empty($m['kisi'])) {
            $kisiAdi = $m['kisi'];
            break;
        }
    }

    $sonGelen = null;
    foreach (array_reverse($mesajlar) as $m) {
        if ($m['yon'] === 'gelen') {
            $sonGelen = $m;
            break;
        }
    }

    return [
        'komut'        => 'detay',
        'numara'       => $numara,
        'jid'          => $jid,
        'kisi'         => $kisiAdi,
        'kanal'        => ['endpoint' => $kanal['base_url'], 'instance' => $kanal['instance']],
        'mesaj_sayisi' => count($mesajlar),
        'toplam_kayit' => $toplam,
        'son_gelen'    => $sonGelen,
        'mesajlar'     => $mesajlar,
        'proje'        => projeIpuclari($db, $numara, $mesajlar, $secenek['proje'] ?? null),
        'uyari'        => $mesajlar
            ? null
            : 'Bu numara icin Evolution veritabaninda kayit yok. Evolution yalnizca devreye girdigi tarihten sonrasini saklar.',
    ];
}

/**
 * yanit komutu: numaraya WhatsApp mesajı gönderir (veya önizler).
 */
function komutYanit(Database $db, string $numaraGirdi, array $secenek): array
{
    $numara = numaraNormalle($numaraGirdi);

    if ($numara === '') {
        throw new Exception('Gecerli bir telefon numarasi verilmedi.');
    }

    $onizleme = isset($secenek['onizleme']) || isset($secenek['taslak']) || isset($secenek['dry-run']);

    $mesaj = '';
    if (!empty($secenek['mesaj-dosya'])) {
        $yol = $secenek['mesaj-dosya'];
        if (!is_file($yol)) {
            $yol = dirname(__DIR__, 2) . '/' . ltrim(str_replace('\\', '/', $secenek['mesaj-dosya']), '/');
        }
        if (!is_file($yol)) {
            throw new Exception("Mesaj dosyasi bulunamadi: {$secenek['mesaj-dosya']}");
        }
        $mesaj = trim((string)file_get_contents($yol));
    } elseif (!empty($secenek['mesaj']) && $secenek['mesaj'] !== '1') {
        $mesaj = trim((string)$secenek['mesaj']);
    }

    if ($mesaj === '') {
        throw new Exception('Gonderilecek metin bos. --mesaj-dosya= veya --mesaj= verin.');
    }

    $kanal = whatsappKanaliGetir($db);

    $rapor = [
        'komut'    => 'yanit',
        'mod'      => $onizleme ? 'ONIZLEME (hicbir mesaj gonderilmedi)' : 'GONDERIM',
        'numara'   => $numara,
        'jid'      => $numara . '@s.whatsapp.net',
        'kanal'    => ['endpoint' => $kanal['base_url'], 'instance' => $kanal['instance']],
        'karakter' => mb_strlen($mesaj),
        'satir'    => substr_count($mesaj, "\n") + 1,
        'mesaj'    => $mesaj,
    ];

    if ($onizleme) {
        $rapor['yapilacaklar'] = [
            "POST {$kanal['base_url']}/message/sendText/{$kanal['instance']} cagrilacak",
            'EntegrasyonLoglari tablosuna WA_GONDER kaydi yazilacak',
            'EntegrasyonKanallari.Kanal_Son_Kullanim guncellenecek',
        ];
        return $rapor;
    }

    $helper = new EntegrasyonHelper($db);
    $sonuc  = $helper->whatsappGonder($numara, $mesaj);

    $rapor['basarili'] = (bool)($sonuc['success'] ?? false);
    $rapor['sonuc']    = $sonuc['message'] ?? null;

    if (empty($sonuc['success'])) {
        $rapor['hata_detay'] = $sonuc['data'] ?? null;
    }

    return $rapor;
}

// ─────────────────────────────────────────────
// Giris noktasi
// ─────────────────────────────────────────────

$arg     = argAyristir($argv);
$komut   = strtolower($arg['konumsal'][0] ?? '');
$hedef   = implode(' ', array_slice($arg['konumsal'], 1));
$secenek = $arg['secenek'];

try {
    $db = Database::getInstance();

    $cikti = match ($komut) {
        'detay' => komutDetay($db, $hedef, $secenek),
        'yanit' => komutYanit($db, $hedef, $secenek),
        default => throw new Exception(
            "Bilinmeyen komut: '{$komut}'. Kullanilabilir: detay, yanit\n" .
            "  php admin/cron/whatsapp-ajan.php detay 905551234567\n" .
            "  php admin/cron/whatsapp-ajan.php yanit 905551234567 --mesaj-dosya=temp/wa.txt --onizleme"
        ),
    };

    echo json_encode($cikti, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);

} catch (Throwable $e) {
    echo json_encode(
        ['hata' => $e->getMessage()],
        JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
    ) . "\n";
    exit(1);
}
