<?php
/**
 * MCP uç noktası (Streamable HTTP, oturumsuz, yalnız JSON yanıt)
 *
 * claude.ai > Ayarlar > Bağlayıcılar > Özel bağlayıcı ekle: https://destek.ornekproje.com/mcp
 * Kimlik: OAuth 2.1 (Bearer). Araçlar personelin Menu_SayfaYetkileri kayıtlarına göre listelenir.
 */

require_once __DIR__ . '/../admin/db.php';
require_once __DIR__ . '/../admin/includes/McpSunucu.php';

const MCP_SURUMLER = ['2025-11-25', '2025-06-18', '2025-03-26'];

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type, Mcp-Session-Id, Mcp-Protocol-Version');
header('Access-Control-Expose-Headers: WWW-Authenticate, Mcp-Session-Id');
header('Access-Control-Allow-Methods: POST, GET, DELETE, OPTIONS');

$yontem = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($yontem === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function mcpJson(int $http, $govde = null): void
{
    http_response_code($http);
    if ($govde !== null) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($govde, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    exit;
}

function mcpHata($id, int $kod, string $mesaj): array
{
    return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $kod, 'message' => $mesaj]];
}

// Kimlik doğrulama
$yetkiBasligi = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
$token = preg_match('/^Bearer\s+(\S+)$/i', $yetkiBasligi, $m) ? $m[1] : '';

$db = Database::getInstance();
$kullanici = McpOAuth::tokenDogrula($db, $token);
if (!$kullanici) {
    header('WWW-Authenticate: Bearer resource_metadata="' . McpOAuth::tabanUrl() . '/.well-known/oauth-protected-resource"'
        . ($token !== '' ? ', error="invalid_token"' : ''));
    mcpJson(401, ['error' => $token !== '' ? 'invalid_token' : 'unauthorized']);
}

// Sunucudan istemciye akış (SSE) ve oturum sonlandırma desteklenmiyor
if ($yontem !== 'POST') {
    header('Allow: POST, OPTIONS');
    mcpJson(405);
}

$istek = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($istek)) {
    mcpJson(400, mcpHata(null, -32700, 'Parse error'));
}

$araclar = new McpAraclar($db, $kullanici, (int)$kullanici['Mcp_Tokenlar_id']);

/**
 * Tek JSON-RPC mesajını işler. Bildirim (id yok) ise null döner.
 */
function mcpIsle(array $mesaj, McpAraclar $araclar, array $kullanici): ?array
{
    $id = $mesaj['id'] ?? null;
    $bildirim = !array_key_exists('id', $mesaj);
    $yontem = (string)($mesaj['method'] ?? '');
    $p = is_array($mesaj['params'] ?? null) ? $mesaj['params'] : [];

    if (($mesaj['jsonrpc'] ?? '') !== '2.0' || $yontem === '') {
        return $bildirim ? null : mcpHata($id, -32600, 'Invalid Request');
    }
    if ($bildirim) {
        return null; // notifications/initialized, notifications/cancelled ...
    }

    switch ($yontem) {
        case 'initialize':
            $istenen = (string)($p['protocolVersion'] ?? '');
            $ad = trim($kullanici['kullanici_ad'] . ' ' . $kullanici['kullanici_soyad']);
            return ['jsonrpc' => '2.0', 'id' => $id, 'result' => [
                'protocolVersion' => in_array($istenen, MCP_SURUMLER, true) ? $istenen : MCP_SURUMLER[0],
                'capabilities'    => ['tools' => ['listChanged' => false]],
                'serverInfo'      => ['name' => 'ornek-destek', 'title' => 'OrnekYazilim Destek', 'version' => '1.27.0'],
                'instructions'    => "OrnekYazilim destek sistemi. Bagli personel: {$ad}. "
                    . 'Islemler bu personelin adina ve panel yetkileriyle yapilir. '
                    . 'Musteriye gidecek yanit (mesaj) bildirim tetikler; ic_not yalniz ekipte gorunur. '
                    . 'Kayit yazan araclarda once onizleme=true ile plani kullaniciya gosterip onay alin, sonra onizleme olmadan cagirin. '
                    . 'Durum/oncelik/personel ID gerekirse ticket_secenekler kullanin.',
            ]];

        case 'ping':
            return ['jsonrpc' => '2.0', 'id' => $id, 'result' => new stdClass()];

        case 'tools/list':
            return ['jsonrpc' => '2.0', 'id' => $id, 'result' => ['tools' => $araclar->listele()]];

        case 'tools/call':
            $ad = (string)($p['name'] ?? '');
            $arg = is_array($p['arguments'] ?? null) ? $p['arguments'] : [];
            if ($ad === '') {
                return mcpHata($id, -32602, 'name zorunlu');
            }
            return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $araclar->cagir($ad, $arg)];

        case 'resources/list':
            return ['jsonrpc' => '2.0', 'id' => $id, 'result' => ['resources' => []]];

        case 'prompts/list':
            return ['jsonrpc' => '2.0', 'id' => $id, 'result' => ['prompts' => []]];
    }

    return mcpHata($id, -32601, "Method not found: {$yontem}");
}

try {
    // Toplu istek (eski sürümler) veya tek mesaj
    if (array_is_list($istek)) {
        $yanitlar = array_values(array_filter(array_map(
            static fn($m) => is_array($m) ? mcpIsle($m, $araclar, $kullanici) : mcpHata(null, -32600, 'Invalid Request'),
            $istek
        )));
        $yanitlar ? mcpJson(200, $yanitlar) : mcpJson(202);
    }

    $yanit = mcpIsle($istek, $araclar, $kullanici);
    $yanit === null ? mcpJson(202) : mcpJson(200, $yanit);
} catch (Throwable $e) {
    error_log('MCP hata: ' . $e->getMessage());
    mcpJson(500, mcpHata($istek['id'] ?? null, -32603, 'Internal error'));
}
