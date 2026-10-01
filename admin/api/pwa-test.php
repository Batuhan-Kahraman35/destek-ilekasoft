<?php
/**
 * PWA - Test bildirimi
 * Bildirimler tablosuna kayıt atar ve giriş yapmış kullanıcının cihazlarına push gönderir.
 */
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/Bildirim.php';

header('Content-Type: application/json; charset=utf-8');

if (!Auth::check()) {
    echo json_encode(['success' => false, 'message' => 'Oturum bulunamadı.']);
    exit;
}

$user        = Auth::user();
$kullaniciId = (int)($user['kullanici_id'] ?? 0);
$db          = Database::getInstance();

try {
    $aktifCihaz = $db->fetchOne(
        "SELECT COUNT(*) AS sayi FROM dbo.Bildirim_Abonelikleri WHERE abonelik_kullanici_id = ? AND Durum = 1",
        [$kullaniciId]
    );
    $cihazSayisi = (int)($aktifCihaz['sayi'] ?? 0);

    // Push'u ayrı tetikliyoruz ki gönderim sonucunu yanıtta raporlayabilelim
    $bildirimId = Bildirim::olustur($db, [
        'kullanici_id' => $kullaniciId,
        'baslik'       => 'Test Bildirimi',
        'govde'        => 'Push bildirimleri çalışıyor.',
        'url'          => '/admin/ticket-liste',
        'tip'          => 'basari',
        'olay'         => 'test',
        'push'         => true,
        'aninda'       => false,   // gönderim aşağıda tetiklenir, sonucu yanıtta raporlanır
        'olusturan'    => $kullaniciId,
    ]);

    $push = Bildirim::push($db, $bildirimId);

    echo json_encode([
        'success'     => true,
        'bildirim_id' => $bildirimId,
        'cihaz'       => $cihazSayisi,
        'gonderilen'  => $push['gonderilen'] ?? 0,
        'basarisiz'   => $push['basarisiz'] ?? 0,
        'hata'        => $push['hata'] ?? null,
        'message'     => $cihazSayisi === 0
            ? 'Bildirim kaydedildi ancak bu hesaba bağlı aktif cihaz yok. Önce "Bildirimleri Aç" ile izin verin.'
            : 'Bildirim oluşturuldu. Push gönderilen cihaz: ' . ($push['gonderilen'] ?? 0) . '/' . $cihazSayisi,
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
