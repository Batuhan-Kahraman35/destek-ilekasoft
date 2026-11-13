<?php
/**
 * MCP OAuth yetkilendirme ekranı (authorization_endpoint)
 *
 * 1) claude.ai OAuth parametreleriyle gelir → doğrulanır, oturuma saklanır, temiz adrese yönlendirilir
 * 2) Giriş yoksa panel girişine gider, giriş sonrası buraya döner
 * 3) Personel izin verirse tek kullanımlık kod üretilip claude.ai'ye geri yönlendirilir
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/McpSunucu.php';

$db = Database::getInstance();

function yetkiHataEkrani(string $mesaj): void
{
    http_response_code(400);
    $GLOBALS['hataMesaji'] = $mesaj;
}

function yetkiYonlendir(string $adres): void
{
    header('Location: ' . $adres, true, 302);
    exit;
}

$hataMesaji = null;
$istek = null;
$anahtar = null;

// 1) OAuth parametreleriyle ilk geliş
if (isset($_GET['client_id'])) {
    try {
        $dogrulanan = McpOAuth::yetkiIstegiDogrula($db, $_GET);
    } catch (InvalidArgumentException $e) {
        $dogrulanan = null;
        yetkiHataEkrani($e->getMessage());
    }

    if ($dogrulanan) {
        if ($dogrulanan['hata']) {
            yetkiYonlendir(McpOAuth::yonlendirmeAdresi($dogrulanan['redirect_uri'], [
                'error' => $dogrulanan['hata'], 'state' => $dogrulanan['state'], 'iss' => McpOAuth::tabanUrl(),
            ]));
        }
        $anahtar = bin2hex(random_bytes(12));
        $_SESSION['mcp_oauth'] = array_slice($_SESSION['mcp_oauth'] ?? [], -4, null, true);
        $_SESSION['mcp_oauth'][$anahtar] = ['q' => $_GET, 'zaman' => time()];
        yetkiYonlendir('/admin/mcp-yetkilendir.php?istek=' . $anahtar);
    }
}

// 2) Oturumdaki istek
if ($hataMesaji === null) {
    $anahtar = preg_replace('/[^a-f0-9]/', '', (string)($_GET['istek'] ?? $_POST['istek'] ?? ''));
    $kayit = $_SESSION['mcp_oauth'][$anahtar] ?? null;

    if (!$kayit || time() - (int)$kayit['zaman'] > 900) {
        yetkiHataEkrani('Yetkilendirme istegi bulunamadi veya suresi doldu. Claude uzerinden baglanmayi yeniden baslatin.');
    } else {
        requireAuth();
        try {
            $istek = McpOAuth::yetkiIstegiDogrula($db, $kayit['q']);
        } catch (InvalidArgumentException $e) {
            yetkiHataEkrani($e->getMessage());
        }
    }
}

$kullanici = null;
$araclar = [];
if ($istek) {
    $kullanici = $db->fetchOne(
        "SELECT kullanici_id, kullanici_ad, kullanici_soyad, kullanici_departman_id, kullanici_email
         FROM kullanicilar WHERE kullanici_id = ? AND kullanici_durum = 1",
        [(int)$_SESSION['user_id']]
    );
    if (!$kullanici) {
        yetkiHataEkrani('Kullanici aktif degil.');
        $istek = null;
    } else {
        $araclar = (new McpAraclar($db, $kullanici))->listele();
    }
}

// 3) Karar
if ($istek && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!hash_equals((string)($_SESSION['mcp_csrf'] ?? ''), (string)($_POST['csrf'] ?? ''))) {
        yetkiHataEkrani('Guvenlik dogrulamasi basarisiz. Sayfayi yenileyip tekrar deneyin.');
        $istek = null;
    } else {
        unset($_SESSION['mcp_oauth'][$anahtar]);
        if (($_POST['karar'] ?? '') === 'izin' && $araclar) {
            $kod = McpOAuth::kodOlustur($db, $istek, (int)$kullanici['kullanici_id']);
            yetkiYonlendir(McpOAuth::yonlendirmeAdresi($istek['redirect_uri'], [
                'code' => $kod, 'state' => $istek['state'], 'iss' => McpOAuth::tabanUrl(),
            ]));
        }
        yetkiYonlendir(McpOAuth::yonlendirmeAdresi($istek['redirect_uri'], [
            'error' => 'access_denied', 'state' => $istek['state'], 'iss' => McpOAuth::tabanUrl(),
        ]));
    }
}

$_SESSION['mcp_csrf'] = $_SESSION['mcp_csrf'] ?? bin2hex(random_bytes(16));

$siteAyar = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyar['site_ayarlari_site_title'] ?? 'OrnekYazilim Destek';
$e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$hedefHost = $istek ? parse_url($istek['redirect_uri'], PHP_URL_HOST) : '';
?>
<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Claude Erişim İzni | <?= $e($siteTitle) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/source-sans-3@5.0.12/index.css" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" crossorigin="anonymous">
    <link rel="stylesheet" href="assets/css/adminlte.min.css">
    <style>.login-box { width: 440px; max-width: 100%; } .arac-liste { max-height: 220px; overflow-y: auto; }</style>
</head>
<body class="login-page bg-body-secondary">
<div class="login-box">
    <div class="login-logo"><b><?= $e($siteTitle) ?></b></div>
    <div class="card">
        <div class="card-body login-card-body">
            <?php if ($hataMesaji !== null || !$istek): ?>
                <div class="alert alert-danger mb-0">
                    <i class="bi bi-exclamation-triangle me-1"></i><?= $e($hataMesaji ?? 'Gecersiz istek.') ?>
                </div>
            <?php else: ?>
                <p class="login-box-msg mb-2"><i class="bi bi-shield-lock me-1"></i>Claude erişim izni</p>
                <p class="mb-2">
                    <b><?= $e($istek['istemci']['Mcp_Istemciler_Ad'] ?: 'MCP istemcisi') ?></b>
                    (<span class="text-secondary"><?= $e($hedefHost) ?></span>) destek hesabınıza erişmek istiyor.
                </p>
                <div class="border rounded p-2 mb-3 small">
                    <div><i class="bi bi-person me-1"></i><?= $e(trim($kullanici['kullanici_ad'] . ' ' . $kullanici['kullanici_soyad'])) ?></div>
                    <div class="text-secondary"><?= $e($kullanici['kullanici_email']) ?></div>
                </div>

                <?php if ($araclar): ?>
                    <p class="small mb-1">İşlemler <b>sizin adınıza</b> ve panel yetkilerinizle yapılır. Kullanılabilecek araçlar:</p>
                    <ul class="list-group list-group-flush small mb-3 arac-liste">
                        <?php foreach ($araclar as $a): ?>
                            <li class="list-group-item px-1 py-1">
                                <i class="bi <?= $a['annotations']['readOnlyHint'] ? 'bi-eye text-info' : 'bi-pencil-square text-warning' ?> me-1"></i>
                                <?= $e($a['title']) ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <div class="alert alert-warning small">Hesabınızın Claude ile kullanabileceği bir yetkisi yok. Yöneticinize başvurun.</div>
                <?php endif; ?>

                <form method="post" action="/admin/mcp-yetkilendir.php">
                    <input type="hidden" name="istek" value="<?= $e($anahtar) ?>">
                    <input type="hidden" name="csrf" value="<?= $e($_SESSION['mcp_csrf']) ?>">
                    <div class="d-flex gap-2">
                        <button type="submit" name="karar" value="ret" class="btn btn-outline-secondary w-50">Reddet</button>
                        <button type="submit" name="karar" value="izin" class="btn btn-primary w-50" <?= $araclar ? '' : 'disabled' ?>>İzin ver</button>
                    </div>
                </form>
                <p class="small text-secondary mt-3 mb-0">Bağlantıyı istediğiniz zaman personel ekranından iptal edebilirsiniz.</p>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>
