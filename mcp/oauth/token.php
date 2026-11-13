<?php
/**
 * OAuth token uç noktası: authorization_code (PKCE S256) ve refresh_token
 */

require_once __DIR__ . '/../../admin/db.php';
require_once __DIR__ . '/../../admin/includes/McpSunucu.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Cache-Control: no-store');
header('Pragma: no-cache');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function tokenHata(string $kod, string $aciklama, int $http = 400): void
{
    http_response_code($http);
    echo json_encode(['error' => $kod, 'error_description' => $aciklama], JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    tokenHata('invalid_request', 'POST gerekli', 405);
}

// application/x-www-form-urlencoded (standart) veya JSON
$p = $_POST;
if (!$p) {
    $json = json_decode((string)file_get_contents('php://input'), true);
    $p = is_array($json) ? $json : [];
}

// Basic auth ile client_id gönderen istemciler (secret kullanılmaz)
if (empty($p['client_id']) && !empty($_SERVER['PHP_AUTH_USER'])) {
    $p['client_id'] = $_SERVER['PHP_AUTH_USER'];
}

try {
    $db = Database::getInstance();
    $tur = (string)($p['grant_type'] ?? '');

    if ($tur === 'authorization_code') {
        $sonuc = McpOAuth::kodTakas($db, $p);
    } elseif ($tur === 'refresh_token') {
        $sonuc = McpOAuth::yenile($db, $p);
    } else {
        tokenHata('unsupported_grant_type', 'Desteklenen: authorization_code, refresh_token');
    }

    echo json_encode($sonuc, JSON_UNESCAPED_SLASHES);
} catch (McpOAuthHata $e) {
    tokenHata($e->kod, $e->getMessage(), $e->http);
} catch (Throwable $e) {
    error_log('MCP token: ' . $e->getMessage());
    tokenHata('server_error', 'Sunucu hatasi', 500);
}
