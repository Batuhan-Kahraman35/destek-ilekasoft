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
 *   php admin/cron/ticket-ajan.php ara "Hepsiburada key" --proje=PORTAL --durum=acik
 *   php admin/cron/ticket-ajan.php ac --konu="[Takip] ..." --mesaj-dosya=temp/takip.txt --proje=PORTAL --durum=Beklemede --onizleme
 *   php admin/cron/ticket-ajan.php gorev-ekle 246 --baslik="Banka donusunu kontrol et" --aciklama-dosya=temp/gorev.txt --hatirlatma="Yarın 09:00" --atanan=ben --alici=ben --kanal=WHATSAPP --onizleme
 *   php admin/cron/ticket-ajan.php gorev-ertele 246 --gorev=15 --hatirlatma="1 saat sonra" --onizleme
 *
 * gorev-ekle:   ticket'a gorev ekler; --hatirlatma verilirse hatirlatma kurar. --alici verilmezse alici atanan,
 *               atanan yoksa ben. PUSH (panel + push) her aliciya eklenir; --kanal ile WHATSAPP / MAIL eklenir.
 * gorev-ertele: hatirlatmayi yeni tarihe tasir (sayac artar, gonderim sifirlanir); hatirlatma yoksa kurar.
 *
 * ara: konu ve mesajlarda (ic notlar dahil) arar; her kelime konu veya bir mesajda gecmelidir.
 *      Acik ve kapali tum ticket'lar doner. --proje kaynak kodu veya host olabilir.
 * ac:  yeni ticket acar (TicketService::ticketOlustur, panel ile ayni). Kategori/oncelik verilmezse
 *      Genel/Normal; --atanan verilmezse ben. Ayni konulu ticket varsa acilmaz (--zorla ile acilir).
 *
 * Seçenekler:
 *   --kaynak=KOD      Yanıtı yazacak kaynak kodu (varsayılan: AI → OrnekYazilim AI; kendi adınıza: --kaynak=BATUHAN)
 *   --mesaj-dosya=    Müşteriye gidecek yanıt metnini içeren dosya
 *   --icnot-dosya=    Yalnızca ekipte görünecek iç not metnini içeren dosya
 *   --ek-dosya=       Eklenecek dosya(lar), virgülle ayrılır (resim/PDF/Office/txt, en fazla 10 MB).
 *                     Müşteri yanıtı varsa ona, yoksa iç nota bağlanır.
 *   --durum=ID        Ticket durumunu günceller (opsiyonel)
 *   --oncelik=ID      Ticket önceliğini günceller (opsiyonel)
 *   --kategori=ID     Ticket kategorisini günceller (yalnizca guncelle komutunda)
 *   --atanan=X        Personel atar. X: kullanici_id | "ben" (BATUHAN kaynagi kullanicisi) | "ajan" (yaniti yazan hesap) | "bos" (atamayi kaldirir)
 *   --onizleme        Taslak modu: hicbir kayit yazilmaz, bildirim gonderilmez, plan raporlanir
 *
 * Not: yanit komutunda --atanan verilmezse ve ticket kimseye atanmamissa, ticket ajanin
 * sahibine (BATUHAN kaynagi kullanicisi) otomatik atanir. Ticket zaten atanmissa atamaya dokunulmaz.
 * Otomatik atamayi istemiyorsaniz --atanan=bos kullanin.
 *   --vhost-root=     Proje dizini araması için kök (varsayılan: d:/Inetpub/vhosts)
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Bu arac yalnizca komut satirindan calistirilabilir.');
}

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/TicketAjanServis.php';

const AJAN_VARSAYILAN_KAYNAK = 'AI';
const AJAN_SAHIP_KAYNAK      = 'BATUHAN';


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
        echo "  php admin/cron/ticket-ajan.php yanit <id|url> --mesaj-dosya=... --icnot-dosya=... [--ek-dosya=a.png,b.pdf] [--durum=] [--oncelik=] [--atanan=]\n";
        echo "  php admin/cron/ticket-ajan.php guncelle <id|url> [--durum=] [--oncelik=] [--kategori=] [--atanan=]\n";
        echo "  php admin/cron/ticket-ajan.php ara \"<ifade>\" [--proje=KOD|host] [--durum=acik|kapali|hepsi] [--limit=20]\n";
        echo "  php admin/cron/ticket-ajan.php ac --konu=\"...\" --mesaj-dosya=... [--icnot-dosya=...] [--proje=KOD|host] [--kategori=] [--oncelik=] [--durum=] [--atanan=] [--zorla]\n";
        echo "  php admin/cron/ticket-ajan.php gorev-ekle <id|url> --baslik=\"...\" [--aciklama=|--aciklama-dosya=] [--atanan=] [--hatirlatma=] [--alici=ben,12] [--kanal=WHATSAPP,MAIL]\n";
        echo "  php admin/cron/ticket-ajan.php gorev-ertele <id|url> --gorev=ID --hatirlatma=\"1 saat sonra\" [--alici=] [--kanal=]\n";
        echo "\n  --hatirlatma: \"2026-11-14 09:00\", \"14.11.2026 09:00\" veya hizli secim adi/ID'si (detay > secenekler.hatirlatma_secenekleri)\n";
        echo "  --kanal: PUSH her aliciya otomatik eklenir; WHATSAPP/MAIL icin kisinin telefon/e-postasi olmali\n";
        echo "\n  --durum/--oncelik/--kategori: ID veya tanim adi (ornek: --durum=Cozuldu)\n";
        echo "  --atanan: kullanici_id, ad soyad, ben (BATUHAN kaynagi), ajan (yaniti yazan hesap) veya bos\n";
        echo "  --kaynak: yaniti yazacak kaynak kodu (varsayilan: AI)\n";
        echo "  --onizleme: hicbir kayit yazilmaz, yalnizca yapilacak islem raporlanir (taslak)\n";
        exit(0);
    }

    $db = Database::getInstance();
    $projeKoku = str_replace('\\', '/', realpath(__DIR__ . '/../..'));

    // Ticket id gerektirmeyen komutlar
    if ($komut === 'ara') {
        $ifade = trim(implode(' ', array_slice($args['konumsal'], 1)));
        $sonuc = komutAra($db, $ifade, $args['secenek']);
        echo json_encode(['success' => true, 'data' => $sonuc], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;
        exit(0);
    }

    if ($komut === 'ac') {
        $kaynakKod = strtoupper($args['secenek']['kaynak'] ?? AJAN_VARSAYILAN_KAYNAK);
        $ajan = ajanGetir($db, $kaynakKod, AJAN_SAHIP_KAYNAK);

        $mesajText = metinOku($args['secenek']['mesaj-dosya'] ?? null, $projeKoku);
        $icNotText = metinOku($args['secenek']['icnot-dosya'] ?? null, $projeKoku);
        $sonuc = komutAc($db, $ajan, (string)($args['secenek']['konu'] ?? ''), $mesajText, $icNotText, $args['secenek']);
        echo json_encode(['success' => true, 'data' => $sonuc], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;
        exit(0);
    }

    $ticketId = ticketIdCoz($hedef);
    if ($ticketId <= 0) {
        throw new Exception('Gecerli bir ticket id veya ticket-detay linki verin.');
    }
    // vhost kökü: <kok>/<domain>/<subdomain>/admin/cron -> 4 seviye yukarı
    $vhostRoot = $args['secenek']['vhost-root'] ?? (realpath(__DIR__ . '/../../../..') ?: '');

    if ($komut === 'detay') {
        $cikti = komutDetay($db, $ticketId, $vhostRoot);
        echo json_encode(['success' => true, 'data' => $cikti], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;
        exit(0);
    }

    if ($komut === 'yanit') {
        $kaynakKod = strtoupper($args['secenek']['kaynak'] ?? AJAN_VARSAYILAN_KAYNAK);
        $ajan = ajanGetir($db, $kaynakKod, AJAN_SAHIP_KAYNAK);

        $mesajText = metinOku($args['secenek']['mesaj-dosya'] ?? null, $projeKoku);
        $icNotText = metinOku($args['secenek']['icnot-dosya'] ?? null, $projeKoku);
        $ekler = ekDosyalariHazirla($args['secenek']['ek-dosya'] ?? null, $projeKoku);
        $sonuc = komutYanit($db, $ticketId, $ajan, $mesajText, $icNotText, $args['secenek'], $ekler);
        echo json_encode(['success' => true, 'data' => $sonuc], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;
        exit(0);
    }

    if ($komut === 'guncelle') {
        $kaynakKod = strtoupper($args['secenek']['kaynak'] ?? AJAN_VARSAYILAN_KAYNAK);
        $ajan = ajanGetir($db, $kaynakKod, AJAN_SAHIP_KAYNAK);

        $sonuc = komutGuncelle($db, $ticketId, $ajan, $args['secenek']);
        echo json_encode(['success' => true, 'data' => $sonuc], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;
        exit(0);
    }

    if ($komut === 'gorev-ekle' || $komut === 'gorev-ertele') {
        $kaynakKod = strtoupper($args['secenek']['kaynak'] ?? AJAN_VARSAYILAN_KAYNAK);
        $ajan = ajanGetir($db, $kaynakKod, AJAN_SAHIP_KAYNAK);

        $sonuc = $komut === 'gorev-ekle'
            ? komutGorevEkle($db, $ticketId, $ajan, $args['secenek'], $projeKoku)
            : komutGorevErtele($db, $ticketId, $ajan, $args['secenek']);
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
