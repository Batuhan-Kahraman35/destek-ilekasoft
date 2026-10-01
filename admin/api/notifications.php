<?php
/**
 * Bildirim API - Header çanı
 *
 * Kaynaklar:
 *   1) dbo.Bildirimler  -> kullanıcıya ait + genel duyurular (push ile aynı kaynak)
 *   2) Türetilmiş uyarı -> onay bekleyen üyelik başvuruları (yalnızca yetkisi olan kullanıcıya)
 *
 * Aksiyonlar: get_notifications | get_count | mark_read | mark_all_read
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
require_once __DIR__ . '/../includes/Bildirim.php';
requireAuth();

header('Content-Type: application/json; charset=utf-8');

$db   = Database::getInstance();
$user = Auth::user();

$kullaniciId = (int)($user['kullanici_id'] ?? 0);
$departmanId = $user['departman_id'] ?? null;

/**
 * Üyelik başvurularını görme yetkisi (çanın kendisi artık herkese açık,
 * yalnızca bu türetilmiş uyarı yetkiye bağlı).
 */
function basvuruYetkisiVar($kullaniciId, $departmanId): bool
{
    if (!$kullaniciId || $departmanId === null) {
        return false;
    }
    $izin = PageAuth::checkPagePermissions($kullaniciId, $departmanId, 'personel-yonetimi.php');
    return !empty($izin['has_access']);
}

/** Bildirim tipi -> ikon/renk tanımları (DB'den, istek başına tek sorgu) */
function tipTanimlari($db): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $cache = [];
    foreach ($db->fetchAll("
        SELECT bildirim_tipi_kod AS kod, bildirim_tipi_ikon AS ikon, bildirim_tipi_renk AS renk
        FROM dbo.tanim_bildirim_tipleri WHERE Durum = 1
    ") as $t) {
        $cache[$t['kod']] = ['icon' => $t['ikon'], 'renk' => $t['renk']];
    }
    return $cache;
}

/** Dakika farkını okunabilir metne çevirir */
function zamanMetni(int $dakika): string
{
    if ($dakika < 1)    return 'az önce';
    if ($dakika < 60)   return $dakika . ' dakika önce';
    if ($dakika < 1440) return floor($dakika / 60) . ' saat önce';
    if ($dakika < 43200) return floor($dakika / 1440) . ' gün önce';
    return floor($dakika / 43200) . ' ay önce';
}

/** Onay bekleyen üyelik başvurularını çan formatında döner */
function basvuruBildirimleri($db): array
{
    $rows = $db->fetchAll("
        SELECT kullanici_id, kullanici_ad, kullanici_soyad, kullanici_email,
               DATEDIFF(MINUTE, kullanici_olusturma_tarihi, GETDATE()) AS dakika_once
        FROM kullanicilar
        WHERE kullanici_durum IS NULL
          AND kullanici_olusturma_tarihi >= DATEADD(DAY, -30, GETDATE())
        ORDER BY kullanici_olusturma_tarihi DESC
    ");

    $liste = [];
    foreach ($rows as $r) {
        $adSoyad = trim(($r['kullanici_ad'] ?? '') . ' ' . ($r['kullanici_soyad'] ?? ''));
        if ($adSoyad === '') {
            $adSoyad = (string)$r['kullanici_email'];
        }
        $liste[] = [
            'kaynak'  => 'basvuru',
            'id'      => (int)$r['kullanici_id'],
            'tip'     => 'basvuru',
            'baslik'  => 'Yeni Başvuru',
            'mesaj'   => $adSoyad . ' başvuruda bulundu',
            'zaman'   => zamanMetni((int)$r['dakika_once']),
            'dakika'  => (int)$r['dakika_once'],
            'link'    => '/admin/personel-yonetimi',
            'okundu'  => 0,
        ];
    }
    return $liste;
}

try {
    $action = $_GET['action'] ?? $_POST['action'] ?? '';

    /* ---------------------------------------------------------------- */
    if ($action === 'get_notifications') {
        $tipler = tipTanimlari($db);
        $liste  = [];

        foreach (Bildirim::listele($db, $kullaniciId, 20) as $b) {
            $tip = $b['bildirim_tip'] ?? 'info';
            $liste[] = [
                'kaynak' => 'bildirim',
                'id'     => (int)$b['bildirim_id'],
                'tip'    => $tip,
                'baslik' => $b['bildirim_baslik'],
                'mesaj'  => $b['bildirim_govde'],
                'zaman'  => zamanMetni((int)$b['dakika_once']),
                'dakika' => (int)$b['dakika_once'],
                'link'   => $b['bildirim_url'] ?: '/admin/ticket-liste',
                'okundu' => (int)$b['bildirim_okundu'],
            ];
        }

        if (basvuruYetkisiVar($kullaniciId, $departmanId)) {
            $liste = array_merge($liste, basvuruBildirimleri($db));
        }

        // İki kaynak birleştiği için tek sıralama: en yeni üstte
        usort($liste, fn($a, $b) => $a['dakika'] <=> $b['dakika']);
        $liste = array_slice($liste, 0, 20);

        // İkon ve renk tip tanımından eklenir
        foreach ($liste as &$o) {
            $o['icon'] = $tipler[$o['tip']]['icon'] ?? 'bi-bell';
            $o['renk'] = $tipler[$o['tip']]['renk'] ?? 'secondary';
        }
        unset($o);

        $okunmamis = count(array_filter($liste, fn($o) => (int)$o['okundu'] === 0));

        echo json_encode([
            'success' => true,
            'data'    => $liste,
            'count'   => $okunmamis,
            'toplam'  => count($liste),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /* ---------------------------------------------------------------- */
    if ($action === 'get_count') {
        $sayi = Bildirim::okunmamisSayisi($db, $kullaniciId);

        if (basvuruYetkisiVar($kullaniciId, $departmanId)) {
            $r = $db->fetchOne("
                SELECT COUNT(*) AS sayi FROM kullanicilar
                WHERE kullanici_durum IS NULL
                  AND kullanici_olusturma_tarihi >= DATEADD(DAY, -30, GETDATE())
            ");
            $sayi += (int)($r['sayi'] ?? 0);
        }

        echo json_encode(['success' => true, 'count' => $sayi]);
        exit;
    }

    /* ---------------------------------------------------------------- */
    if ($action === 'mark_read') {
        $bildirimId = (int)($_POST['bildirim_id'] ?? $_GET['bildirim_id'] ?? 0);
        if ($bildirimId <= 0) {
            throw new Exception('Geçersiz bildirim.');
        }
        Bildirim::okunduYap($db, $kullaniciId, $bildirimId);
        echo json_encode(['success' => true]);
        exit;
    }

    /* ---------------------------------------------------------------- */
    if ($action === 'mark_all_read') {
        Bildirim::okunduYap($db, $kullaniciId);
        echo json_encode(['success' => true, 'message' => 'Tüm bildirimler okundu olarak işaretlendi.']);
        exit;
    }

    throw new Exception('Geçersiz işlem.');

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
