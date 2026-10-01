<?php
/**
 * Admin Panel - Kayit Sayfasi
 * Portal Ornek Yazilim
 */

require_once __DIR__ . '/auth.php';

// Zaten giris yapilmişsa ana sayfaya yçnlendir
if (Auth::check()) {
    redirect('index.php');
}

// site ayarlarini veritabanindan çek
$db = Database::getInstance();
$siteAyarlari = $db->fetchOne("
    SELECT TOP 1 site_ayarlari_footer_yazi, site_ayarlari_site_title 
    FROM dbo.tanim_site_ayarlari 
    ORDER BY site_ayarlari_id DESC
");

$footerYazi = '';
$siteTitle = 'Ornek Yazilim Portal';

if ($siteAyarlari) {
    if (!empty($siteAyarlari['site_ayarlari_footer_yazi'])) {
        $footerYazi = htmlspecialchars($siteAyarlari['site_ayarlari_footer_yazi']);
    } else {
        $footerYazi = '© ' . date('Y') . ' Ornek Yazilim. Tçm haklari saklidir.';
    }
    
    if (!empty($siteAyarlari['site_ayarlari_site_title'])) {
        $siteTitle = htmlspecialchars($siteAyarlari['site_ayarlari_site_title']);
    }
} else {
    $footerYazi = '© ' . date('Y') . ' Ornek Yazilim. Tçm haklari saklidir.';
}

// Şehirleri çek
$sehirler = $db->fetchAll("
    SELECT Sehirid, SehirAdi 
    FROM Sehirler 
    ORDER BY SehirAdi
");

$error = '';
$success = '';

// Form gçnderimi
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ad = trim($_POST['ad'] ?? '');
    $soyad = trim($_POST['soyad'] ?? '');
    $dogumtarihi = $_POST['dogum_tarihi'] ?? '';
    $telefon = trim($_POST['telefon'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $sehirid = $_POST['sehir_id'] ?? '';
    $sifre = $_POST['sifre'] ?? '';
    $sifreTekrar = $_POST['sifre_tekrar'] ?? '';
    
    // Validasyon
    if (empty($ad) || empty($soyad) || empty($email) || empty($sifre) || empty($sifreTekrar)) {
        $error = 'Lçtfen tçm zorunlu alanlari doldurun!';
    } elseif (!filter_var($email, filter_VALidATE_email)) {
        $error = 'Geçersiz e-posta adresi!';
    } elseif ($sifre !== $sifreTekrar) {
        $error = 'sifreler eşleşmiyor!';
    } elseif (strlen($sifre) < 6) {
        $error = 'sifre en az 6 karakter olmalidir!';
    } else {
        // email benzersizlik kontrolç
        $existingUser = $db->fetchOne("
            SELECT kullanici_id 
            FROM kullanicilar 
            WHERE kullanici_email = ?
        ", [$email]);
        
        if ($existingUser) {
            $error = 'Bu e-posta adresi zaten kullanilmaktadir!';
        } else {
            // Kayit oluştur
            try {
                $sifreHash = password_hash($sifre, PASSWORD_default);
                
                $result = $db->insert('kullanicilar', [
                    'kullanici_email' => $email,
                    'kullanici_sifre_hash' => $sifreHash,
                    'kullanici_ad' => $ad,
                    'kullanici_soyad' => $soyad,
                    'kullanici_telefon' => $telefon ?: null,
                    'kullanici_dogum_tarihi' => $dogumtarihi ?: null,
                    'kullanici_sehir_id' => $sehirid ?: null,
                    'kullanici_durum' => null,
                    'kullanici_olusturma_tarihi' => app_now(),
                    'kullanici_bes_durumu' => 0
                ]);
                
                if ($result) {
                    $success = 'Başvurunuz alindi! Yçnetici onayindan sonra giris yapabileceksiniz.';
                    // Formu temizle
                    $_POST = [];
                } else {
                    $error = 'Kayit oluşturulurken bir hata oluştu!';
                }
            } catch (Exception $e) {
                $error = 'bir hata oluştu: ' . $e->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kayit Ol - <?= $siteTitle ?></title>
    
    <!-- Google Font: Source Sans Pro -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/source-sans-3@5.0.12/index.css" integrity="sha256-tXJfXfp6Ewt1ilPzLDtQnJV4hclT9XuaZUKyUvmyr+Q=" crossorigin="anonymous">
    
    <!-- Bootstrap icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" crossorigin="anonymous">
    
    <!-- Select2 -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
    
    <!-- AdminLTE -->
    <link rel="stylesheet" href="assets/css/Adminlte.min.css">
    
    <style>
        .register-box {
            width: 450px;
        }
        @media (max-width: 576px) {
            .register-box {
                width: 90%;
            }
        }
    </style>
</head>
<body class="register-page bg-body-secondary">
    <div class="register-box">
        <div class="register-logo">
            <a href="login.php"><b><?= $siteTitle ?></b></a>
        </div>
        
        <div class="card">
            <div class="card-body register-card-body">
                <p class="login-box-msg">Yeni çyelik Başvurusu</p>
                
                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible">
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        <i class="bi bi-exclamation-triangle-fill"></i> <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>
                
                <?php if ($success): ?>
                    <div class="alert alert-success alert-dismissible">
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        <i class="bi bi-check-circle-fill"></i> <?= htmlspecialchars($success) ?>
                    </div>
                    <div class="text-center mt-3">
                        <a href="login.php" class="btn btn-primary">
                            <i class="bi bi-box-arrow-in-right"></i> giris Yap
                        </a>
                    </div>
                <?php else: ?>
                
                <form method="POST" action="" id="registerForm">
                    <div class="row">
                        <!-- Ad -->
                        <div class="col-md-6">
                            <div class="input-group mb-3">
                                <input 
                                    type="text" 
                                    class="form-control" 
                                    name="ad"
                                    placeholder="Ad *" 
                                    required
                                    value="<?= htmlspecialchars($_POST['ad'] ?? '') ?>"
                                >
                                <div class="input-group-text">
                                    <span class="bi bi-person"></span>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Soyad -->
                        <div class="col-md-6">
                            <div class="input-group mb-3">
                                <input 
                                    type="text" 
                                    class="form-control" 
                                    name="soyad"
                                    placeholder="Soyad *" 
                                    required
                                    value="<?= htmlspecialchars($_POST['soyad'] ?? '') ?>"
                                >
                                <div class="input-group-text">
                                    <span class="bi bi-person-fill"></span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- email -->
                    <div class="input-group mb-3">
                        <input 
                            type="email" 
                            class="form-control" 
                            name="email"
                            placeholder="E-posta *" 
                            required
                            value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                        >
                        <div class="input-group-text">
                            <span class="bi bi-envelope"></span>
                        </div>
                    </div>
                    
                    <!-- Telefon -->
                    <div class="input-group mb-3">
                        <input 
                            type="tel" 
                            class="form-control" 
                            name="telefon"
                            placeholder="Telefon (5XX XXX XX XX)"
                            pattern="[0-9]{10}"
                            maxlength="10"
                            value="<?= htmlspecialchars($_POST['telefon'] ?? '') ?>"
                        >
                        <div class="input-group-text">
                            <span class="bi bi-telephone"></span>
                        </div>
                    </div>
                    
                    <!-- Doğum tarihi -->
                    <div class="input-group mb-3">
                        <input 
                            type="date" 
                            class="form-control" 
                            name="dogum_tarihi"
                            placeholder="Doğum tarihi"
                            value="<?= htmlspecialchars($_POST['dogum_tarihi'] ?? '') ?>"
                        >
                        <div class="input-group-text">
                            <span class="bi bi-calendar"></span>
                        </div>
                    </div>
                    
                    <!-- Şehir -->
                    <div class="input-group mb-3">
                        <select class="form-select" name="sehir_id" id="sehir_id">
                            <option value="">Şehir Seçiniz</option>
                            <?php foreach ($sehirler as $sehir): ?>
                                <option value="<?= $sehir['Sehirid'] ?>" <?= (($_POST['sehir_id'] ?? '') == $sehir['Sehirid']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($sehir['SehirAdi']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="input-group-text">
                            <span class="bi bi-geo-alt"></span>
                        </div>
                    </div>
                    
                    <!-- sifre -->
                    <div class="input-group mb-3">
                        <input 
                            type="password" 
                            class="form-control" 
                            name="sifre"
                            placeholder="sifre (En az 6 karakter) *" 
                            required
                            minlength="6"
                        >
                        <div class="input-group-text">
                            <span class="bi bi-lock-fill"></span>
                        </div>
                    </div>
                    
                    <!-- sifre Tekrar -->
                    <div class="input-group mb-3">
                        <input 
                            type="password" 
                            class="form-control" 
                            name="sifre_tekrar"
                            placeholder="sifre Tekrar *" 
                            required
                            minlength="6"
                        >
                        <div class="input-group-text">
                            <span class="bi bi-lock"></span>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-12">
                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-person-plus"></i> Başvuru Yap
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
                
                <p class="mt-3 mb-0 text-center">
                    <a href="login.php" class="text-center">
                        <i class="bi bi-box-arrow-in-right"></i> Zaten hesabim var
                    </a>
                </p>
                
                <?php endif; ?>
                
                <p class="mt-3 mb-1 text-center">
                    <small class="text-muted"><?= $footerYazi ?></small>
                </p>
            </div>
        </div>
    </div>
    
    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js" crossorigin="anonymous"></script>
    
    <!-- Bootstrap 5 -->
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
    
    <!-- Select2 -->
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    
    <!-- AdminLTE -->
    <script src="assets/js/Adminlte.min.js"></script>
    
    <script>
        $(document).ready(function() {
            // Select2 başlat
            $('#sehir_id').select2({
                theme: 'bootstrap-5',
                width: '100%',
                placeholder: 'Şehir Seçiniz',
                allowClear: true,
                language: {
                    noResults: function() { return "Sonuç bulunamadi"; },
                    searching: function() { return "Araniyor..."; }
                }
            });
            
            // sifre eşleşme kontrolç
            $('form').on('submit', function(e) {
                const sifre = $('[name="sifre"]').val();
                const sifreTekrar = $('[name="sifre_tekrar"]').val();
                
                if (sifre !== sifreTekrar) {
                    e.preventdefault();
                    alert('sifreler eşleşmiyor!');
                    return false;
                }
            });
        });
    </script>
</body>
</html>
