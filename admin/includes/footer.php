<?php
/**
 * Admin Panel - Footer Component
 * Portal Örnek Yazılım
 */

// Veritabanından site ayarlarını çek
if (!isset($db)) {
    $db = Database::getInstance();
}

$footerYazi = '';
$siteAyarlari = null;

try {
    $siteAyarlari = $db->fetchOne("
        SELECT TOP 1 site_ayarlari_footer_yazi 
        FROM dbo.tanim_site_ayarlari 
        ORDER BY site_ayarlari_id DESC
    ");
} catch (Exception $e) {
    error_log("Footer SQL hatası: " . $e->getMessage());
}

if ($siteAyarlari && !empty($siteAyarlari['site_ayarlari_footer_yazi'])) {
    $footerYazi = htmlspecialchars($siteAyarlari['site_ayarlari_footer_yazi']);
} else {
    $footerYazi = '© ' . date('Y') . ' Örnek Yazılım Portal. Tüm hakları saklıdır.';
}

// En son versiyon numarasını çek
$sonVersiyon = null;
$versiyonNo = '1.0.0';

try {
    $sonVersiyon = $db->fetchOne("
        SELECT TOP 1 surum_versiyon 
        FROM Sistem_Surum_Notlari 
        ORDER BY surum_id DESC
    ");
    if ($sonVersiyon) {
        $versiyonNo = $sonVersiyon['surum_versiyon'];
    }
} catch (Exception $e) {
    error_log("Versiyon SQL hatası: " . $e->getMessage());
}
?>
<!--begin::Footer-->
<footer class="app-footer" style="display: block; visibility: visible; height: auto;">
    <div class="float-start">
        <strong><?= $footerYazi ?? '© 2026 Örnek Yazılım Portal' ?></strong>
    </div>
    <div class="float-end">
        <a href="/admin/surum-notlari" class="text-decoration-none" title="Sürüm notlarını görüntüle">
            <i class="bi bi-info-circle me-1"></i>Versiyon <?= htmlspecialchars($versiyonNo ?? '1.0.0') ?>
        </a>
    </div>
</footer>
<!--end::Footer-->

<?php if (isset($hasNotificationAccess) && $hasNotificationAccess): ?>
<!-- Bildirim Sistemi -->
<script>
// jQuery yüklenene kadar bekle
(function checkJQuery() {
    if (typeof jQuery !== 'undefined') {
        initNotifications();
    } else {
        setTimeout(checkJQuery, 50);
    }
})();

function initNotifications() {
    function esc(s) {
        return $('<div>').text(String(s == null ? '' : s)).html();
    }

    // Bildirimleri yükle
    function loadNotifications() {
        $.get('/admin/api/notifications.php?action=get_notifications', function(response) {
            if (!response.success) return;

            const count = response.count || 0;
            const bildirimler = response.data || [];

            // Badge yalnızca okunmamış sayısını gösterir
            const badge = $('#notificationBadge');
            if (count > 0) {
                badge.text(count > 9 ? '9+' : count).show();
            } else {
                badge.hide();
            }

            $('#notificationHeader').html(
                '<i class="bi bi-bell"></i> ' + count + ' okunmamış bildirim'
            );

            const liste = $('#notificationList');
            liste.empty();

            if (bildirimler.length === 0) {
                liste.html('<div class="dropdown-item text-center text-muted py-3">Yeni bildirim yok</div>');
                return;
            }

            bildirimler.forEach(function(b) {
                // Okunmamışlar vurgulanır
                const vurgu = b.okundu ? '' : 'fw-semibold bg-body-secondary';
                const govde = b.mesaj ? '<div class="small text-muted text-truncate">' + esc(b.mesaj) + '</div>' : '';

                liste.append(
                    '<a href="' + esc(b.link) + '" class="dropdown-item bildirim-oge ' + vurgu + '"' +
                        ' data-kaynak="' + esc(b.kaynak) + '" data-id="' + esc(b.id) + '">' +
                        '<div class="d-flex align-items-start gap-2">' +
                            '<i class="bi ' + esc(b.icon) + ' text-' + esc(b.renk) + ' mt-1"></i>' +
                            '<div class="flex-grow-1" style="min-width:0;">' +
                                '<div class="text-truncate">' + esc(b.baslik) + '</div>' +
                                govde +
                            '</div>' +
                            '<span class="text-muted small text-nowrap">' + esc(b.zaman) + '</span>' +
                        '</div>' +
                    '</a>' +
                    '<div class="dropdown-divider my-0"></div>'
                );
            });
        }).fail(function() {
            $('#notificationHeader').html('<i class="bi bi-bell"></i> Bildirimler yüklenemedi');
            $('#notificationList').html('<div class="dropdown-item text-center text-danger py-3">Hata oluştu</div>');
        });
    }

    $(document).ready(function() {
        loadNotifications();

        // Her 60 saniyede bir güncelle
        setInterval(loadNotifications, 60000);

        // Dropdown açıldığında yenile
        $('#notificationDropdown').on('click', loadNotifications);

        // Bildirime tıklanınca okundu işaretle (sayfa yönlenirken istek kaybolmasın diye keepalive)
        $(document).on('click', '.bildirim-oge', function() {
            const $o = $(this);
            if ($o.data('kaynak') !== 'bildirim') return;   // türetilmiş uyarıların okundu kaydı yok

            const id = $o.data('id');
            if (!id) return;

            fetch('/admin/api/notifications.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=mark_read&bildirim_id=' + encodeURIComponent(id),
                keepalive: true
            }).catch(function() {});
        });

        // Tümünü okundu işaretle
        $(document).on('click', '#notificationMarkAll', function(e) {
            e.preventDefault();
            e.stopPropagation();
            $.post('/admin/api/notifications.php', { action: 'mark_all_read' }, function(r) {
                if (r.success && typeof showToast === 'function') showToast(r.message, 'success');
                loadNotifications();
            });
        });
    });
}
</script>
<?php endif; ?>

<!-- PWA: manifest + meta etiketleri <head>'e enjekte edilir (tek nokta) -->
<script>
(function () {
    var h = document.head;
    if (!h) return;
    function ekle(etiket, nitelikler) {
        // Sayfa kendi <head>'inde aynı etiketi tanımladıysa üzerine yazma
        var anahtar = nitelikler.rel ? 'rel' : (nitelikler.name ? 'name' : null);
        if (anahtar && h.querySelector(etiket + '[' + anahtar + '="' + nitelikler[anahtar] + '"]')) return;
        var e = document.createElement(etiket);
        for (var k in nitelikler) e.setAttribute(k, nitelikler[k]);
        h.appendChild(e);
    }
    ekle('link', { rel: 'manifest', href: '/admin/manifest.php' });
    ekle('meta', { name: 'theme-color', content: '#0d6efd' });
    ekle('meta', { name: 'mobile-web-app-capable', content: 'yes' });
    ekle('meta', { name: 'apple-mobile-web-app-capable', content: 'yes' });
    ekle('meta', { name: 'apple-mobile-web-app-status-bar-style', content: 'default' });
    ekle('meta', { name: 'apple-mobile-web-app-title', content: 'Destek' });
    ekle('link', { rel: 'apple-touch-icon', href: '/admin/icon.php?size=192' });
})();
</script>
<script src="/admin/assets/js/pwa.js" defer></script>
<?php
// --- Oturum Sure Takibi ---
// Sure bitimine 30 sn kala uyari (uzatma / cikis), ayrica oturumu bitmis
// AJAX isteklerinin (401) yakalanmasi. Script kosulsuz yuklenir: kalan sure
// sifir olsa bile 401 yakalayicisinin devrede olmasi gerekir.
if (Auth::check()):
    $_oturumKalan = !empty($_SESSION['login_time'])
        ? oturumTimeoutSaniye() - (time() - $_SESSION['login_time'])
        : 0;
?>
<script>
window.OTURUM_TAKIP = {
    kalan: <?= max(0, (int)$_oturumKalan) ?>,
    omur:  <?= (int)oturumTimeoutSaniye() ?>
};
</script>
<script src="/admin/assets/js/oturum-takip.js?v=<?= @filemtime(__DIR__ . '/../assets/js/oturum-takip.js') ?>" defer></script>
<?php endif; ?>