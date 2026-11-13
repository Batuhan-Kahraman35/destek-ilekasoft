<?php
/**
 * OAuth dinamik istemci kaydı (RFC 7591). claude.ai bağlayıcı eklenirken kendini kaydeder.
 * Kayıt erişim vermez; erişim yalnız personelin yetkilendirme ekranında izin vermesiyle oluşur.
 */

require_once __DIR__ . '/../../admin/db.php';
require_once __DIR__ . '/../../admin/includes/McpSunucu.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'invalid_request', 'error_description' => 'POST gerekli']);
    exit;
}

$istek = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($istek)) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid_client_metadata', 'error_description' => 'JSON govde gerekli']);
    exit;
}

try {
    $sonuc = McpOAuth::istemciKaydet(Database::getInstance(), $istek);
    http_response_code(201);
    echo json_encode($sonuc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid_redirect_uri', 'error_description' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('MCP register: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'server_error']);
}
