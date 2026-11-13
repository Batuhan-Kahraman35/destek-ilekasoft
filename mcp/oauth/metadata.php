<?php
/**
 * OAuth metadata (web.config rewrite ile sunulur)
 *   /.well-known/oauth-protected-resource[/mcp]  → ?tip=kaynak   (RFC 9728)
 *   /.well-known/oauth-authorization-server      → ?tip=sunucu   (RFC 8414)
 *   /.well-known/openid-configuration            → ?tip=sunucu
 */

require_once __DIR__ . '/../../admin/db.php';
require_once __DIR__ . '/../../admin/includes/McpSunucu.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: public, max-age=3600');

$taban = McpOAuth::tabanUrl();

if (($_GET['tip'] ?? '') === 'kaynak') {
    echo json_encode([
        'resource'                 => McpOAuth::kaynakUrl(),
        'authorization_servers'    => [$taban],
        'scopes_supported'         => [McpOAuth::KAPSAM],
        'bearer_methods_supported' => ['header'],
        'resource_name'            => 'OrnekYazilim Destek',
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'issuer'                                => $taban,
    'authorization_endpoint'                => $taban . '/admin/mcp-yetkilendir.php',
    'token_endpoint'                        => $taban . '/mcp/oauth/token.php',
    'registration_endpoint'                 => $taban . '/mcp/oauth/register.php',
    'scopes_supported'                      => [McpOAuth::KAPSAM],
    'response_types_supported'              => ['code'],
    'grant_types_supported'                 => ['authorization_code', 'refresh_token'],
    'code_challenge_methods_supported'      => ['S256'],
    'token_endpoint_auth_methods_supported' => ['none'],
], JSON_UNESCAPED_SLASHES);
