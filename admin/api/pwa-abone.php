<?php
/**
 * PWA - Push aboneliği (cihaz adresi) kaydet / sil
 *
 * POST {action:'kaydet', endpoint, p256dh, auth}
 * POST {action:'sil',    endpoint}
 *
 * Bir kullanıcının birden çok cihazı olabilir; kayıt endpoint bazlıdır.
 */
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../db.php';

header('Content-Type: application/json; charset=utf-8');

if (!Auth::check()) {
    echo json_encode(['success' => false, 'message' => 'Oturum bulunamadı.']);
    exit;
}

$user        = Auth::user();
$kullaniciId = (int)($user['kullanici_id'] ?? 0);
$db          = Database::getInstance();

$input  = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$action = $input['action'] ?? 'kaydet';

try {
    if ($action === 'sil') {
        $endpoint = trim((string)($input['endpoint'] ?? ''));
        if ($endpoint === '') {
            throw new Exception('endpoint alanı gerekli.');
        }

        $db->execute("
            UPDATE dbo.Bildirim_Abonelikleri
            SET Durum = 0, GuncelleyenKullanici = ?, GuncellemeTarihi = GETDATE()
            WHERE abonelik_endpoint = ?
        ", [$kullaniciId, $endpoint]);

        echo json_encode(['success' => true, 'message' => 'Bildirim aboneliği iptal edildi.']);
        exit;
    }

    $endpoint = trim((string)($input['endpoint'] ?? ''));
    $p256dh   = trim((string)($input['p256dh'] ?? ''));
    $auth     = trim((string)($input['auth'] ?? ''));

    if ($endpoint === '' || $p256dh === '' || $auth === '') {
        throw new Exception('Eksik abonelik bilgisi.');
    }

    $tarayici = mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);

    // Aynı endpoint başka bir kullanıcıya kayıtlı olabilir (ortak cihaz) -> sahibi güncellenir
    $mevcut = $db->fetchOne(
        "SELECT abonelik_id FROM dbo.Bildirim_Abonelikleri WHERE abonelik_endpoint = ?",
        [$endpoint]
    );

    if ($mevcut) {
        $db->execute("
            UPDATE dbo.Bildirim_Abonelikleri
            SET abonelik_kullanici_id = ?, abonelik_p256dh = ?, abonelik_auth = ?,
                abonelik_tarayici = ?, Durum = 1,
                GuncelleyenKullanici = ?, GuncellemeTarihi = GETDATE()
            WHERE abonelik_id = ?
        ", [$kullaniciId, $p256dh, $auth, $tarayici, $kullaniciId, $mevcut['abonelik_id']]);
    } else {
        $db->insert('dbo.Bildirim_Abonelikleri', [
            'abonelik_kullanici_id' => $kullaniciId,
            'abonelik_endpoint'     => $endpoint,
            'abonelik_p256dh'       => $p256dh,
            'abonelik_auth'         => $auth,
            'abonelik_tarayici'     => $tarayici,
            'OlusturanKullanici'    => $kullaniciId,
            'OlusturmaTarihi'       => app_now(),
            'Durum'                 => 1,
        ]);
    }

    echo json_encode(['success' => true, 'message' => 'Bildirim aboneliği kaydedildi.']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
