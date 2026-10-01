<?php
/**
 * Admin Panel - Adres Yönetimi
 * Ülke, Şehir ve İlçe kayıtlarının yönetimi
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
requireAuth();

$user = Auth::user();
$db = Database::getInstance();

// Sayfa Yetki kontrolü
$currentPagefile = basename($_SERVER['PHP_SELF']);
$pagePermissions = PageAuth::checkPagePermissions(
    $user['kullanici_id'],
    $user['departman_id'],
    $currentPagefile
);

// Sayfa erişim kontrolü
if (!$pagePermissions['has_access']) {
    PageAuth::accessDenied($pagePermissions['error'] ?? 'Bu sayfaya erişim yetkiniz bulunmamaktadır.');
}

// Mevcut sayfanın bilgilerini al
$pageinfo = $db->fetchOne("
    SELECT 
        s.sayfalar_sayfa_adi, 
        s.sayfalar_aciklama,
        m.menuler_menu_adi as menu_adi
    FROM Menu_Sayfalar s
    LEFT JOIN Menuler m ON s.sayfalar_menu_id = m.menuler_id
    WHERE s.sayfalar_sayfa_url LIKE ? AND s.sayfalar_durum = 1
", ['%' . $currentPagefile]);

$pageTitle = $pageinfo['sayfalar_sayfa_adi'] ?? 'Adres Yönetimi';
$menuAdi = $pageinfo['menu_adi'] ?? null;

// site title'ı çek
$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Ornek Yazilim Portal';

// Ülke listesi (şehir ekleme için)
$ulkeler = $db->fetchAll("SELECT UlkeId, UlkeAdi FROM Adres_Ulkeler ORDER BY UlkeAdi");

// Şehir listesi (ilçe ekleme için)
$sehirler = $db->fetchAll("SELECT SehirId, SehirAdi FROM Adres_Sehirler ORDER BY SehirAdi");

// AJAX işlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            // ==================== İSTATİSTİKLER ====================
            case 'stats':
                $stats = [
                    'toplam_ulke' => $db->fetchOne("SELECT COUNT(*) as sayi FROM Adres_Ulkeler")['sayi'] ?? 0,
                    'toplam_sehir' => $db->fetchOne("SELECT COUNT(*) as sayi FROM Adres_Sehirler")['sayi'] ?? 0,
                    'toplam_ilce' => $db->fetchOne("SELECT COUNT(*) as sayi FROM Adres_Ilceler")['sayi'] ?? 0,
                    'turkiye_sehir' => $db->fetchOne("SELECT COUNT(*) as sayi FROM Adres_Sehirler WHERE UlkeId = 1")['sayi'] ?? 0
                ];
                echo json_encode(['success' => true, 'data' => $stats]);
                break;
            
            // ==================== ÜLKE İŞLEMLERİ ====================
            case 'ulke_list':
                $list = $db->fetchAll("
                    SELECT u.*, 
                           (SELECT COUNT(*) FROM Adres_Sehirler WHERE UlkeId = u.UlkeId) as sehir_sayisi
                    FROM Adres_Ulkeler u
                    ORDER BY u.UlkeAdi
                ");
                echo json_encode(['success' => true, 'data' => $list]);
                break;
            
            case 'ulke_get':
                $id = (int)($_POST['id'] ?? 0);
                $item = $db->fetchOne("SELECT * FROM Adres_Ulkeler WHERE UlkeId = ?", [$id]);
                echo json_encode(['success' => true, 'data' => $item]);
                break;
            
            case 'ulke_save':
                if (!$pagePermissions['can_add'] && empty($_POST['UlkeId'])) {
                    throw new Exception('Ekleme yetkiniz yok.');
                }
                if (!$pagePermissions['can_edit'] && !empty($_POST['UlkeId'])) {
                    throw new Exception('Düzenleme yetkiniz yok.');
                }
                
                $id = (int)($_POST['UlkeId'] ?? 0);
                $data = [
                    'UlkeAdi' => trim($_POST['UlkeAdi'] ?? ''),
                    'IkiliKod' => strtoupper(trim($_POST['IkiliKod'] ?? '')) ?: '--',
                    'UcluKod' => strtoupper(trim($_POST['UcluKod'] ?? '')) ?: '---',
                    'TelKodu' => trim($_POST['TelKodu'] ?? '') ?: '-'
                ];
                
                if (empty($data['UlkeAdi'])) {
                    throw new Exception('Ülke adı zorunludur.');
                }
                
                if ($id > 0) {
                    $db->execute("UPDATE Adres_Ulkeler SET UlkeAdi=?, IkiliKod=?, UcluKod=?, TelKodu=? WHERE UlkeId=?",
                        [$data['UlkeAdi'], $data['IkiliKod'], $data['UcluKod'], $data['TelKodu'], $id]);
                    echo json_encode(['success' => true, 'message' => 'Ülke güncellendi.']);
                } else {
                    $db->execute("INSERT INTO Adres_Ulkeler (UlkeAdi, IkiliKod, UcluKod, TelKodu) VALUES (?, ?, ?, ?)",
                        [$data['UlkeAdi'], $data['IkiliKod'], $data['UcluKod'], $data['TelKodu']]);
                    echo json_encode(['success' => true, 'message' => 'Ülke eklendi.']);
                }
                break;
            
            case 'ulke_delete':
                if (!$pagePermissions['can_delete']) {
                    throw new Exception('Silme yetkiniz yok.');
                }
                $id = (int)($_POST['id'] ?? 0);
                
                // Bağlı şehir var mı kontrol et
                $sehirSayisi = $db->fetchOne("SELECT COUNT(*) as sayi FROM Adres_Sehirler WHERE UlkeId = ?", [$id])['sayi'];
                if ($sehirSayisi > 0) {
                    throw new Exception("Bu ülkeye bağlı $sehirSayisi şehir var. Önce şehirleri silin.");
                }
                
                $db->execute("DELETE FROM Adres_Ulkeler WHERE UlkeId = ?", [$id]);
                echo json_encode(['success' => true, 'message' => 'Ülke silindi.']);
                break;
            
            // ==================== ŞEHİR İŞLEMLERİ ====================
            case 'sehir_list':
                $ulkeid = $_POST['ulke_id'] ?? '';
                $sql = "
                    SELECT s.*, u.UlkeAdi,
                           (SELECT COUNT(*) FROM Adres_Ilceler WHERE SehirId = s.SehirId) as ilce_sayisi
                    FROM Adres_Sehirler s
                    LEFT JOIN Adres_Ulkeler u ON s.UlkeId = u.UlkeId
                ";
                $params = [];
                if ($ulkeid !== '') {
                    $sql .= " WHERE s.UlkeId = ?";
                    $params[] = $ulkeid;
                }
                $sql .= " ORDER BY s.SehirAdi";
                $list = $db->fetchAll($sql, $params);
                echo json_encode(['success' => true, 'data' => $list]);
                break;
            
            case 'sehir_get':
                $id = (int)($_POST['id'] ?? 0);
                $item = $db->fetchOne("SELECT * FROM Adres_Sehirler WHERE SehirId = ?", [$id]);
                echo json_encode(['success' => true, 'data' => $item]);
                break;
            
            case 'sehir_save':
                if (!$pagePermissions['can_add'] && empty($_POST['SehirId'])) {
                    throw new Exception('Ekleme yetkiniz yok.');
                }
                if (!$pagePermissions['can_edit'] && !empty($_POST['SehirId'])) {
                    throw new Exception('Düzenleme yetkiniz yok.');
                }
                
                $id = (int)($_POST['SehirId'] ?? 0);
                $data = [
                    'SehirAdi' => substr(trim($_POST['SehirAdi'] ?? ''), 0, 55),
                    'PlakaNo' => (int)(trim($_POST['PlakaNo'] ?? '')) ?: 0,
                    'TelefonKodu' => (int)(trim($_POST['TelefonKodu'] ?? '')) ?: 0,
                    'UlkeId' => (int)($_POST['UlkeId'] ?? 0) ?: null
                ];
                
                if (empty($data['SehirAdi'])) {
                    throw new Exception('Şehir adı zorunludur.');
                }
                
                if ($id > 0) {
                    $db->execute("UPDATE Adres_Sehirler SET SehirAdi=?, PlakaNo=?, TelefonKodu=?, UlkeId=? WHERE SehirId=?",
                        [$data['SehirAdi'], $data['PlakaNo'], $data['TelefonKodu'], $data['UlkeId'], $id]);
                    echo json_encode(['success' => true, 'message' => 'Şehir güncellendi.']);
                } else {
                    $db->execute("INSERT INTO Adres_Sehirler (SehirAdi, PlakaNo, TelefonKodu, UlkeId, RowNumber) VALUES (?, ?, ?, ?, 0)",
                        [$data['SehirAdi'], $data['PlakaNo'], $data['TelefonKodu'], $data['UlkeId']]);
                    echo json_encode(['success' => true, 'message' => 'Şehir eklendi.']);
                }
                break;
            
            case 'sehir_delete':
                if (!$pagePermissions['can_delete']) {
                    throw new Exception('Silme yetkiniz yok.');
                }
                $id = (int)($_POST['id'] ?? 0);
                
                // Bağlı ilçe var mı kontrol et
                $ilceSayisi = $db->fetchOne("SELECT COUNT(*) as sayi FROM Adres_Ilceler WHERE SehirId = ?", [$id])['sayi'];
                if ($ilceSayisi > 0) {
                    throw new Exception("Bu şehre bağlı $ilceSayisi ilçe var. Önce ilçeleri silin.");
                }
                
                $db->execute("DELETE FROM Adres_Sehirler WHERE SehirId = ?", [$id]);
                echo json_encode(['success' => true, 'message' => 'Şehir silindi.']);
                break;
            
            // ==================== İLÇE İŞLEMLERİ ====================
            case 'ilce_list':
                $sehirid = $_POST['sehir_id'] ?? '';
                $sql = "
                    SELECT i.*, s.SehirAdi as SehirAdiJoin
                    FROM Adres_Ilceler i
                    LEFT JOIN Adres_Sehirler s ON i.SehirId = s.SehirId
                ";
                $params = [];
                if ($sehirid !== '') {
                    $sql .= " WHERE i.SehirId = ?";
                    $params[] = $sehirid;
                }
                $sql .= " ORDER BY i.SehirAdi, i.IlceAdi";
                $list = $db->fetchAll($sql, $params);
                echo json_encode(['success' => true, 'data' => $list]);
                break;
            
            case 'ilce_get':
                $id = (int)($_POST['id'] ?? 0);
                $item = $db->fetchOne("SELECT * FROM Adres_Ilceler WHERE ilceId = ?", [$id]);
                echo json_encode(['success' => true, 'data' => $item]);
                break;
            
            case 'ilce_save':
                if (!$pagePermissions['can_add'] && empty($_POST['ilceId'])) {
                    throw new Exception('Ekleme yetkiniz yok.');
                }
                if (!$pagePermissions['can_edit'] && !empty($_POST['ilceId'])) {
                    throw new Exception('Düzenleme yetkiniz yok.');
                }
                
                $id = (int)($_POST['ilceId'] ?? 0);
                $sehirid = (int)($_POST['SehirId'] ?? 0);
                $ilceAdi = substr(trim($_POST['IlceAdi'] ?? ''), 0, 60);
                
                if (empty($ilceAdi)) {
                    throw new Exception('İlçe adı zorunludur.');
                }
                if ($sehirid <= 0) {
                    throw new Exception('Şehir seçimi zorunludur.');
                }
                
                if ($id > 0) {
                    $db->execute("UPDATE Adres_Ilceler SET IlceAdi=?, SehirId=? WHERE ilceId=?",
                        [$ilceAdi, $sehirid, $id]);
                    echo json_encode(['success' => true, 'message' => 'İlçe güncellendi.']);
                } else {
                    $db->execute("INSERT INTO Adres_Ilceler (IlceAdi, SehirId) VALUES (?, ?)",
                        [$ilceAdi, $sehirid]);
                    echo json_encode(['success' => true, 'message' => 'İlçe eklendi.']);
                }
                break;
            
            case 'ilce_delete':
                if (!$pagePermissions['can_delete']) {
                    throw new Exception('Silme yetkiniz yok.');
                }
                $id = (int)($_POST['id'] ?? 0);
                $db->execute("DELETE FROM Adres_Ilceler WHERE ilceId = ?", [$id]);
                echo json_encode(['success' => true, 'message' => 'İlçe silindi.']);
                break;
            
            default:
                throw new Exception('Geçersiz işlem.');
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> - <?= htmlspecialchars($siteTitle) ?></title>
    
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/source-sans-3@5.0.12/index.css" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" crossorigin="anonymous">
    <link rel="stylesheet" href="/admin/assets/css/adminlte.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/admin/assets/css/custom.css">
</head>
<body class="layout-fixed sidebar-expand-lg sidebar-open bg-body-tertiary">
    <div class="app-wrapper">
        <?php include __DIR__ . '/../includes/header.php'; ?>
        <?php include __DIR__ . '/../includes/sidebar.php'; ?>
        
        <main class="app-main">
            <!-- Sayfa Başlığı -->
            <div class="app-content-header">
                <div class="container-fluid">
                    <div class="row">
                        <div class="col-sm-6">
                            <h3 class="mb-0"><?= htmlspecialchars($pageTitle) ?></h3>
                        </div>
                        <div class="col-sm-6">
                            <ol class="breadcrumb float-sm-end">
                                <?php if ($menuAdi): ?>
                                <li class="breadcrumb-item"><?= htmlspecialchars($menuAdi) ?></li>
                                <?php endif; ?>
                                <li class="breadcrumb-item active"><?= htmlspecialchars($pageTitle) ?></li>
                            </ol>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Sayfa İçeriği -->
            <div class="app-content">
                <div class="container-fluid">
                    
                    <!-- Info Boxes -->
                    <div class="row mb-3">
                        <div class="col-md-3">
                            <div class="info-box text-bg-primary">
                                <span class="info-box-icon"><i class="bi bi-globe"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Toplam Ülke</span>
                                    <span class="info-box-number" id="stat-ulke">0</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="info-box text-bg-success">
                                <span class="info-box-icon"><i class="bi bi-building"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Toplam Şehir</span>
                                    <span class="info-box-number" id="stat-sehir">0</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="info-box text-bg-info">
                                <span class="info-box-icon"><i class="bi bi-geo-alt"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Toplam İlçe</span>
                                    <span class="info-box-number" id="stat-ilce">0</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="info-box text-bg-warning">
                                <span class="info-box-icon"><i class="bi bi-flag"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Türkiye Şehir</span>
                                    <span class="info-box-number" id="stat-turkiye">0</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Tab Navigation -->
                    <div class="card card-primary card-outline">
                        <div class="card-header">
                            <ul class="nav nav-tabs card-header-tabs" id="addressTabs" role="tablist">
                                <li class="nav-item">
                                    <button class="nav-link active" id="ulke-tab" data-bs-toggle="tab" data-bs-target="#ulke-panel" type="button">
                                        <i class="bi bi-globe me-1"></i> Ülkeler
                                    </button>
                                </li>
                                <li class="nav-item">
                                    <button class="nav-link" id="sehir-tab" data-bs-toggle="tab" data-bs-target="#sehir-panel" type="button">
                                        <i class="bi bi-building me-1"></i> Şehirler
                                    </button>
                                </li>
                                <li class="nav-item">
                                    <button class="nav-link" id="ilce-tab" data-bs-toggle="tab" data-bs-target="#ilce-panel" type="button">
                                        <i class="bi bi-geo-alt me-1"></i> İlçeler
                                    </button>
                                </li>
                            </ul>
                        </div>
                        <div class="card-body">
                            <div class="tab-content" id="addressTabContent">
                                
                                <!-- ÜLKELER TAB -->
                                <div class="tab-pane fade show active" id="ulke-panel" role="tabpanel">
                                    <div class="d-flex justify-content-between align-items-center mb-3">
                                        <h5 class="mb-0">Ülke Listesi</h5>
                                        <?php if ($pagePermissions['can_add']): ?>
                                        <button type="button" class="btn btn-primary btn-sm" onclick="openUlkeModal()">
                                            <i class="bi bi-plus-circle"></i> Yeni Ülke
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                    <div class="table-responsive">
                                        <table class="table table-bordered table-striped table-hover" id="ulkeTable">
                                            <thead>
                                                <tr>
                                                    <th style="width:60px">ID</th>
                                                    <th>Ülke Adı</th>
                                                    <th style="width:80px">İkili Kod</th>
                                                    <th style="width:80px">Üçlü Kod</th>
                                                    <th style="width:100px">Tel Kodu</th>
                                                    <th style="width:100px">Şehir Sayısı</th>
                                                    <th style="width:120px">İşlemler</th>
                                                </tr>
                                            </thead>
                                            <tbody id="ulkeTableBody"></tbody>
                                        </table>
                                    </div>
                                </div>
                                
                                <!-- ŞEHİRLER TAB -->
                                <div class="tab-pane fade" id="sehir-panel" role="tabpanel">
                                    <div class="d-flex justify-content-between align-items-center mb-3">
                                        <div class="d-flex align-items-center gap-3">
                                            <h5 class="mb-0">Şehir Listesi</h5>
                                            <select class="form-select form-select-sm" id="sehirFilterUlke" style="width:200px">
                                                <option value="">Tüm Ülkeler</option>
                                                <?php foreach ($ulkeler as $u): ?>
                                                <option value="<?= $u['UlkeId'] ?>"><?= htmlspecialchars($u['UlkeAdi']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <?php if ($pagePermissions['can_add']): ?>
                                        <button type="button" class="btn btn-primary btn-sm" onclick="openSehirModal()">
                                            <i class="bi bi-plus-circle"></i> Yeni Şehir
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                    <div class="table-responsive">
                                        <table class="table table-bordered table-striped table-hover" id="sehirTable">
                                            <thead>
                                                <tr>
                                                    <th style="width:60px">ID</th>
                                                    <th>Şehir Adı</th>
                                                    <th style="width:100px">Plaka No</th>
                                                    <th style="width:100px">Tel Kodu</th>
                                                    <th>Ülke</th>
                                                    <th style="width:100px">İlçe Sayısı</th>
                                                    <th style="width:120px">İşlemler</th>
                                                </tr>
                                            </thead>
                                            <tbody id="sehirTableBody"></tbody>
                                        </table>
                                    </div>
                                </div>
                                
                                <!-- İLÇELER TAB -->
                                <div class="tab-pane fade" id="ilce-panel" role="tabpanel">
                                    <div class="d-flex justify-content-between align-items-center mb-3">
                                        <div class="d-flex align-items-center gap-3">
                                            <h5 class="mb-0">İlçe Listesi</h5>
                                            <select class="form-select form-select-sm" id="ilceFilterSehir" style="width:200px">
                                                <option value="">Tüm Şehirler</option>
                                                <?php foreach ($sehirler as $s): ?>
                                                <option value="<?= $s['SehirId'] ?>"><?= htmlspecialchars($s['SehirAdi']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <?php if ($pagePermissions['can_add']): ?>
                                        <button type="button" class="btn btn-primary btn-sm" onclick="openIlceModal()">
                                            <i class="bi bi-plus-circle"></i> Yeni İlçe
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                    <div class="table-responsive">
                                        <table class="table table-bordered table-striped table-hover" id="ilceTable">
                                            <thead>
                                                <tr>
                                                    <th style="width:60px">ID</th>
                                                    <th>İlçe Adı</th>
                                                    <th>Şehir</th>
                                                    <th style="width:120px">İşlemler</th>
                                                </tr>
                                            </thead>
                                            <tbody id="ilceTableBody"></tbody>
                                        </table>
                                    </div>
                                </div>
                                
                            </div>
                        </div>
                    </div>
                    
                </div>
            </div>
        </main>
        
        <?php include __DIR__ . '/../includes/footer.php'; ?>
    </div>
    
    <!-- ÜLKE MODAL -->
    <div class="modal fade" id="ulkeModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="ulkeModalTitle">Yeni Ülke</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="ulkeForm">
                    <input type="hidden" name="UlkeId" id="ulke_id">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Ülke Adı *</label>
                            <input type="text" class="form-control" name="UlkeAdi" id="ulke_adi" required>
                        </div>
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label">İkili Kod</label>
                                <input type="text" class="form-control" name="IkiliKod" id="ulke_ikili" maxlength="2" placeholder="TR">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Üçlü Kod</label>
                                <input type="text" class="form-control" name="UcluKod" id="ulke_uclu" maxlength="3" placeholder="TUR">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Tel Kodu</label>
                                <input type="text" class="form-control" name="TelKodu" id="ulke_tel" placeholder="+90">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                        <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Kaydet</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- ŞEHİR MODAL -->
    <div class="modal fade" id="sehirModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="sehirModalTitle">Yeni Şehir</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="sehirForm">
                    <input type="hidden" name="SehirId" id="sehir_id">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Şehir Adı *</label>
                            <input type="text" class="form-control" name="SehirAdi" id="sehir_adi" required>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Plaka No</label>
                                <input type="text" class="form-control" name="PlakaNo" id="sehir_plaka" maxlength="3">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Telefon Kodu</label>
                                <input type="text" class="form-control" name="TelefonKodu" id="sehir_tel">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Ülke</label>
                            <select class="form-select" name="UlkeId" id="sehir_ulke">
                                <option value="">Ülke Seçiniz</option>
                                <?php foreach ($ulkeler as $u): ?>
                                <option value="<?= $u['UlkeId'] ?>"><?= htmlspecialchars($u['UlkeAdi']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                        <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Kaydet</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- İLÇE MODAL -->
    <div class="modal fade" id="ilceModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="ilceModalTitle">Yeni İlçe</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="ilceForm">
                    <input type="hidden" name="ilceId" id="ilce_id">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">İlçe Adı *</label>
                            <input type="text" class="form-control" name="IlceAdi" id="ilce_adi" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Şehir *</label>
                            <select class="form-select" name="SehirId" id="ilce_sehir" required>
                                <option value="">Şehir Seçiniz</option>
                                <?php foreach ($sehirler as $s): ?>
                                <option value="<?= $s['SehirId'] ?>"><?= htmlspecialchars($s['SehirAdi']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                        <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Kaydet</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.min.js"></script>
    <script src="/admin/assets/js/adminlte.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <script src="/admin/assets/js/custom.js"></script>
    
    <script>
    // Modals
    let ulkeModal, sehirModal, ilceModal;
    
    $(document).ready(function() {
        ulkeModal = new bootstrap.Modal(document.getElementById('ulkeModal'));
        sehirModal = new bootstrap.Modal(document.getElementById('sehirModal'));
        ilceModal = new bootstrap.Modal(document.getElementById('ilceModal'));
        
        // Select2
        $('#sehirFilterUlke, #ilceFilterSehir, #sehir_ulke, #ilce_sehir').select2({
            theme: 'bootstrap-5',
            width: '100%',
            placeholder: 'Seçiniz...',
            allowClear: true,
            language: {
                noResults: function() { return "Sonuç bulunamadı"; }
            }
        });
        
        // Modal içi Select2
        $('#sehir_ulke').select2({ dropdownParent: $('#sehirModal'), theme: 'bootstrap-5', width: '100%' });
        $('#ilce_sehir').select2({ dropdownParent: $('#ilceModal'), theme: 'bootstrap-5', width: '100%' });
        
        // İstatistikleri ve listeleri yükle
        loadStats();
        loadUlkeler();
        
        // Tab değiştiğinde ilgili listeyi yükle
        $('button[data-bs-toggle="tab"]').on('shown.bs.tab', function(e) {
            const target = $(e.target).attr('data-bs-target');
            if (target === '#sehir-panel') loadSehirler();
            else if (target === '#ilce-panel') loadIlceler();
        });
        
        // Filtre değişikliklerinde
        $('#sehirFilterUlke').on('change', loadSehirler);
        $('#ilceFilterSehir').on('change', loadIlceler);
        
        // Form submits
        $('#ulkeForm').on('submit', saveUlke);
        $('#sehirForm').on('submit', saveSehir);
        $('#ilceForm').on('submit', saveIlce);
    });
    
    // İstatistikler
    function loadStats() {
        $.post('', { action: 'stats' }, function(res) {
            if (res.success) {
                $('#stat-ulke').text(res.data.toplam_ulke);
                $('#stat-sehir').text(res.data.toplam_sehir);
                $('#stat-ilce').text(res.data.toplam_ilce);
                $('#stat-turkiye').text(res.data.turkiye_sehir);
            }
        });
    }
    
    // ==================== ÜLKE İŞLEMLERİ ====================
    function loadUlkeler() {
        $.post('', { action: 'ulke_list' }, function(res) {
            if (res.success) {
                let html = '';
                res.data.forEach(function(item) {
                    html += `<tr>
                        <td>${item.UlkeId}</td>
                        <td><strong>${item.UlkeAdi || ''}</strong></td>
                        <td>${item.IkiliKod || '-'}</td>
                        <td>${item.UcluKod || '-'}</td>
                        <td>${item.TelKodu || '-'}</td>
                        <td><span class="badge text-bg-info">${item.sehir_sayisi}</span></td>
                        <td>
                            <button class="btn btn-sm btn-warning" onclick="editUlke(${item.UlkeId})"><i class="bi bi-pencil"></i></button>
                            <button class="btn btn-sm btn-danger" onclick="deleteUlke(${item.UlkeId})"><i class="bi bi-trash"></i></button>
                        </td>
                    </tr>`;
                });
                $('#ulkeTableBody').html(html || '<tr><td colspan="7" class="text-center text-muted">Kayıt bulunamadı</td></tr>');
            }
        });
    }
    
    function openUlkeModal(id = 0) {
        $('#ulkeForm')[0].reset();
        $('#ulke_id').val(0);
        $('#ulkeModalTitle').text('Yeni Ülke');
        ulkeModal.show();
    }
    
    function editUlke(id) {
        $.post('', { action: 'ulke_get', id: id }, function(res) {
            if (res.success && res.data) {
                $('#ulke_id').val(res.data.UlkeId);
                $('#ulke_adi').val(res.data.UlkeAdi);
                $('#ulke_ikili').val(res.data.IkiliKod);
                $('#ulke_uclu').val(res.data.UcluKod);
                $('#ulke_tel').val(res.data.TelKodu);
                $('#ulkeModalTitle').text('Ülke Düzenle');
                ulkeModal.show();
            }
        });
    }
    
    function saveUlke(e) {
        e.preventDefault();
        $.post('', $('#ulkeForm').serialize() + '&action=ulke_save', function(res) {
            if (res.success) {
                showToast(res.message, 'success');
                ulkeModal.hide();
                loadUlkeler();
                loadStats();
            } else {
                showToast(res.message, 'error');
            }
        });
    }
    
    function deleteUlke(id) {
        confirmAction('Bu ülkeyi silmek istediğinize emin misiniz?', 'Bağlı şehirler varsa silinemez!', function() {
            $.post('', { action: 'ulke_delete', id: id }, function(res) {
                if (res.success) {
                    showSuccess('Silindi!', res.message);
                    loadUlkeler();
                    loadStats();
                } else {
                    showError('Hata!', res.message);
                }
            });
        });
    }
    
    // ==================== ŞEHİR İŞLEMLERİ ====================
    function loadSehirler() {
        const ulkeid = $('#sehirFilterUlke').val() || '';
        $.post('', { action: 'sehir_list', ulke_id: ulkeid }, function(res) {
            if (res.success) {
                let html = '';
                res.data.forEach(function(item) {
                    html += `<tr>
                        <td>${item.SehirId}</td>
                        <td><strong>${item.SehirAdi || ''}</strong></td>
                        <td>${item.PlakaNo || '-'}</td>
                        <td>${item.TelefonKodu || '-'}</td>
                        <td>${item.UlkeAdi || '-'}</td>
                        <td><span class="badge text-bg-info">${item.ilce_sayisi}</span></td>
                        <td>
                            <button class="btn btn-sm btn-warning" onclick="editSehir(${item.SehirId})"><i class="bi bi-pencil"></i></button>
                            <button class="btn btn-sm btn-danger" onclick="deleteSehir(${item.SehirId})"><i class="bi bi-trash"></i></button>
                        </td>
                    </tr>`;
                });
                $('#sehirTableBody').html(html || '<tr><td colspan="7" class="text-center text-muted">Kayıt bulunamadı</td></tr>');
            }
        });
    }
    
    function openSehirModal() {
        $('#sehirForm')[0].reset();
        $('#sehir_id').val(0);
        $('#sehir_ulke').val('').trigger('change.select2');
        $('#sehirModalTitle').text('Yeni Şehir');
        sehirModal.show();
    }
    
    function editSehir(id) {
        $.post('', { action: 'sehir_get', id: id }, function(res) {
            if (res.success && res.data) {
                $('#sehir_id').val(res.data.SehirId);
                $('#sehir_adi').val(res.data.SehirAdi);
                $('#sehir_plaka').val(res.data.PlakaNo);
                $('#sehir_tel').val(res.data.TelefonKodu);
                $('#sehir_ulke').val(res.data.UlkeId).trigger('change.select2');
                $('#sehirModalTitle').text('Şehir Düzenle');
                sehirModal.show();
            }
        });
    }
    
    function saveSehir(e) {
        e.preventDefault();
        $.post('', $('#sehirForm').serialize() + '&action=sehir_save', function(res) {
            if (res.success) {
                showToast(res.message, 'success');
                sehirModal.hide();
                loadSehirler();
                loadStats();
            } else {
                showToast(res.message, 'error');
            }
        });
    }
    
    function deleteSehir(id) {
        confirmAction('Bu şehri silmek istediğinize emin misiniz?', 'Bağlı ilçeler varsa silinemez!', function() {
            $.post('', { action: 'sehir_delete', id: id }, function(res) {
                if (res.success) {
                    showSuccess('Silindi!', res.message);
                    loadSehirler();
                    loadStats();
                } else {
                    showError('Hata!', res.message);
                }
            });
        });
    }
    
    // ==================== İLÇE İŞLEMLERİ ====================
    function loadIlceler() {
        const sehirid = $('#ilceFilterSehir').val() || '';
        $.post('', { action: 'ilce_list', sehir_id: sehirid }, function(res) {
            if (res.success) {
                let html = '';
                res.data.forEach(function(item) {
                    html += `<tr>
                        <td>${item.ilceId}</td>
                        <td><strong>${item.IlceAdi || ''}</strong></td>
                        <td>${item.SehirAdi || '-'}</td>
                        <td>
                            <button class="btn btn-sm btn-warning" onclick="editIlce(${item.ilceId})"><i class="bi bi-pencil"></i></button>
                            <button class="btn btn-sm btn-danger" onclick="deleteIlce(${item.ilceId})"><i class="bi bi-trash"></i></button>
                        </td>
                    </tr>`;
                });
                $('#ilceTableBody').html(html || '<tr><td colspan="4" class="text-center text-muted">Kayıt bulunamadı</td></tr>');
            }
        });
    }
    
    function openIlceModal() {
        $('#ilceForm')[0].reset();
        $('#ilce_id').val(0);
        $('#ilce_sehir').val('').trigger('change.select2');
        $('#ilceModalTitle').text('Yeni İlçe');
        ilceModal.show();
    }
    
    function editIlce(id) {
        $.post('', { action: 'ilce_get', id: id }, function(res) {
            if (res.success && res.data) {
                $('#ilce_id').val(res.data.ilceId);
                $('#ilce_adi').val(res.data.IlceAdi);
                $('#ilce_sehir').val(res.data.SehirId).trigger('change.select2');
                $('#ilceModalTitle').text('İlçe Düzenle');
                ilceModal.show();
            }
        });
    }
    
    function saveIlce(e) {
        e.preventDefault();
        $.post('', $('#ilceForm').serialize() + '&action=ilce_save', function(res) {
            if (res.success) {
                showToast(res.message, 'success');
                ilceModal.hide();
                loadIlceler();
                loadStats();
            } else {
                showToast(res.message, 'error');
            }
        });
    }
    
    function deleteIlce(id) {
        confirmAction('Bu ilçeyi silmek istediğinize emin misiniz?', null, function() {
            $.post('', { action: 'ilce_delete', id: id }, function(res) {
                if (res.success) {
                    showSuccess('Silindi!', res.message);
                    loadIlceler();
                    loadStats();
                } else {
                    showError('Hata!', res.message);
                }
            });
        });
    }
    </script>
</body>
</html>
