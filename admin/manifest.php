<?php
/**
 * PWA Web App Manifest (Dinamik)
 * Baslik dbo.tanim_site_ayarlari tablosundan cekilir, ikonlar icon.php uzerinden uretilir.
 * Kullanim: <link rel="manifest" href="/admin/manifest.php">
 */

require_once __DIR__ . '/db.php';

header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=3600');

$db = Database::getInstance();

$ayar = $db->fetchOne("
    SELECT TOP 1 site_ayarlari_site_title
    FROM dbo.tanim_site_ayarlari
    ORDER BY site_ayarlari_id DESC
");

$siteTitle = trim((string)($ayar['site_ayarlari_site_title'] ?? '')) !== ''
    ? $ayar['site_ayarlari_site_title']
    : 'Destek Örnek Yazılım';

$temaRengi     = '#0d6efd';
$arkaplanRengi = '#ffffff';

$manifest = [
    'id'               => '/admin/',
    'name'             => $siteTitle,
    'short_name'       => mb_substr($siteTitle, 0, 12),
    'description'      => $siteTitle . ' destek talebi yönetim paneli',
    'start_url'        => '/admin/ticket-liste?source=pwa',
    'scope'            => '/admin/',
    'display'          => 'standalone',
    'orientation'      => 'any',
    'theme_color'      => $temaRengi,
    'background_color' => $arkaplanRengi,
    'lang'             => 'tr',
    'dir'              => 'ltr',
    'icons' => [
        ['src' => '/admin/icon.php?size=192',            'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => '/admin/icon.php?size=512',            'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => '/admin/icon.php?size=192&maskable=1', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'maskable'],
        ['src' => '/admin/icon.php?size=512&maskable=1', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
    ],
    'shortcuts' => [
        [
            'name'  => 'Talep Listesi',
            'url'   => '/admin/ticket-liste',
            'icons' => [['src' => '/admin/icon.php?size=192', 'sizes' => '192x192', 'type' => 'image/png']],
        ],
        [
            'name'  => 'Yeni Talep',
            'url'   => '/admin/ticket-ekle',
            'icons' => [['src' => '/admin/icon.php?size=192', 'sizes' => '192x192', 'type' => 'image/png']],
        ],
    ],
];

echo json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
