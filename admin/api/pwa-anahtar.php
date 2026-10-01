<?php
/**
 * PWA - VAPID public anahtarını istemciye döner (pushManager.subscribe için).
 */
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/PwaHelper.php';

header('Content-Type: application/json; charset=utf-8');

if (!Auth::check()) {
    echo json_encode(['success' => false, 'message' => 'Oturum bulunamadı.']);
    exit;
}

$vapid = PwaHelper::vapid(Database::getInstance());

if (!$vapid) {
    echo json_encode(['success' => false, 'message' => 'VAPID yapılandırılmamış.']);
    exit;
}

echo json_encode(['success' => true, 'publicKey' => $vapid['public']]);
