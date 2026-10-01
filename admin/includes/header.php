<?php
/**
 * Admin Panel - Header Component
 * Portal Örnek Yazılım
 */

// $user değişkeni ana sayfada tanımlanmalı
if (!isset($user)) {
    die('Header component requires $user variable to be set.');
}

// Bildirim çanı tüm oturum açmış kullanıcılara gösterilir.
// İçerik kullanıcıya özeldir (Bildirimler tablosu); yetki gerektiren türetilmiş
// uyarıları (ör. üyelik başvuruları) api/notifications.php ayrıca filtreler.
$hasNotificationAccess = !empty($user['kullanici_id']);
?>
<!--begin::Header-->
<nav class="app-header navbar navbar-expand bg-body">
    <!--begin::Container-->
    <div class="container-fluid">
        <!--begin::Start Navbar Links-->
        <ul class="navbar-nav">
            <li class="nav-item">
                <a class="nav-link" data-lte-toggle="sidebar" href="#" role="button">
                    <i class="bi bi-list"></i>
                </a>
            </li>
            <li class="nav-item d-none d-md-block">
                <a href="/admin/anasayfa" class="nav-link">Ana Sayfa</a>
            </li>
        </ul>
        <!--end::Start Navbar Links-->
        
        <!--begin::End Navbar Links-->
        <ul class="navbar-nav ms-auto">
            <?php if ($hasNotificationAccess): ?>
            <!--begin::Notifications Dropdown Menu-->
            <li class="nav-item dropdown">
                <a class="nav-link" data-bs-toggle="dropdown" href="#" id="notificationDropdown">
                    <i class="bi bi-bell"></i>
                    <span class="navbar-badge badge text-bg-warning" id="notificationBadge" style="display: none;">0</span>
                </a>
                <div class="dropdown-menu dropdown-menu-lg dropdown-menu-end" id="notificationMenu">
                    <span class="dropdown-item dropdown-header" id="notificationHeader">
                        <i class="bi bi-bell"></i> Bildirimler yükleniyor...
                    </span>
                    <div class="dropdown-divider"></div>
                    <div id="notificationList">
                        <!-- Bildirimler buraya gelecek -->
                    </div>
                    <div class="dropdown-divider"></div>
                    <a href="#" class="dropdown-item dropdown-footer" id="notificationMarkAll">
                        Tümünü okundu işaretle
                    </a>
                </div>
            </li>
            <!--end::Notifications Dropdown Menu-->
            <?php endif; ?>
            
            <!--begin::Fullscreen Toggle-->
            <li class="nav-item">
                <a class="nav-link" href="#" data-lte-toggle="fullscreen">
                    <i data-lte-icon="maximize" class="bi bi-arrows-fullscreen"></i>
                    <i data-lte-icon="minimize" class="bi bi-fullscreen-exit" style="display: none"></i>
                </a>
            </li>
            <!--end::Fullscreen Toggle-->
            
            <!--begin::User Menu Dropdown-->
            <li class="nav-item dropdown user-menu">
                <a href="#" class="nav-link dropdown-toggle" data-bs-toggle="dropdown">
                    <img
                        src="/admin/assets/images/user-avatar.png"
                        class="user-image rounded-circle shadow"
                        alt="<?= htmlspecialchars($user['name']) ?>"
                        onerror="this.src='data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%22160%22 height=%22160%22%3E%3Crect fill=%22%23667eea%22 width=%22160%22 height=%22160%22/%3E%3Ctext fill=%22%23fff%22 font-family=%22Arial%22 font-size=%2260%22 x=%2250%25%22 y=%2250%25%22 text-anchor=%22middle%22 dy=%22.3em%22%3E<?= strtoupper(substr($user['name'] ?? 'U', 0, 1)) ?>%3C/text%3E%3C/svg%3E'"
                    />
                    <span class="d-none d-md-inline"><?= htmlspecialchars($user['name']) ?></span>
                </a>
                <ul class="dropdown-menu dropdown-menu-lg dropdown-menu-end">
                    <!--begin::User Image-->
                    <li class="user-header text-bg-primary">
                        <img
                            src="/admin/assets/images/user-avatar.png"
                            class="rounded-circle shadow"
                            alt="<?= htmlspecialchars($user['name']) ?>"
                            onerror="this.src='data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%22160%22 height=%22160%22%3E%3Crect fill=%22%23ffffff%22 width=%22160%22 height=%22160%22/%3E%3Ctext fill=%22%23667eea%22 font-family=%22Arial%22 font-size=%2260%22 x=%2250%25%22 y=%2250%25%22 text-anchor=%22middle%22 dy=%22.3em%22%3E<?= strtoupper(substr($user['name'] ?? 'U', 0, 1)) ?>%3C/text%3E%3C/svg%3E'"
                        />
                        <p>
                            <?= htmlspecialchars($user['name']) ?>
                            <small><?= htmlspecialchars($user['email']) ?></small>
                        </p>
                    </li>
                    <!--end::User Image-->
                    
                    <!--begin::Menu Body-->
                    <li class="user-body">
                        <div class="row">
                            <div class="col-12 text-center">
                                <small class="text-muted">
                                    <i class="bi bi-clock"></i> Son giriş: <?= date('d.m.Y H:i:s', $_SESSION['login_time']) ?>
                                </small>
                            </div>
                        </div>
                    </li>
                    <!--end::Menu Body-->
                    
                    <!--begin::Menu Footer-->
                    <li class="user-footer">
                        <a href="/admin/profil" class="btn btn-default btn-flat">Profil</a>
                        <a href="/admin/logout.php" class="btn btn-default btn-flat float-end">Çıkış Yap</a>
                    </li>
                    <!--end::Menu Footer-->
                </ul>
            </li>
            <!--end::User Menu Dropdown-->
        </ul>
        <!--end::End Navbar Links-->
    </div>
    <!--end::Container-->
</nav>
<!--end::Header-->
