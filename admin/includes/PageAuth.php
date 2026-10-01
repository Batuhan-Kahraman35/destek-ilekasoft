<?php
/**
 * Sayfa Yetki Kontrolü
 * 
 * Kullanıcının mevcut sayfaya erişim yetkisini kontrol eder.
 * departman_id=1 (Administrator) için tüm yetkilere izin verir.
 * Diğer departmanlar için menu_sayfa_yetkiler tablosunu kontrol eder.
 */

class PageAuth {
    
    /**
     * Sayfa yetkilerini kontrol et
     * 
     * @param int $userId Kullanıcı ID
     * @param int $departmanId Departman ID
     * @param string $pageFile Sayfa dosya adı (örn: stok-yonetimi.php)
     * @return array Yetki bilgileri
     */
    public static function checkPagePermissions($userId, $departmanId, $pageFile) {
        // Administrator departmanı için tüm yetkiler açık
        if ($departmanId == 1) {
            return [
                'has_access' => true,
                'can_view' => true,
                'can_add' => true,
                'can_edit' => true,
                'can_delete' => true,
                'can_view_kaynak' => false, // Admin tüm kaynakları görür, kısıtlama yok
                'can_view_atanan' => false, // Admin tüm talepleri görür, kısıtlama yok
                'can_view_own_records' => false, // Admin tüm kayıtları görür
                'is_admin' => true
            ];
        }
        
        $db = Database::getInstance();
        
        // Sayfa ID'sini bul (RTRIM ile trailing space kontrolü)
        $page = $db->fetchOne("
            SELECT sayfalar_id 
            FROM Menu_Sayfalar 
            WHERE RTRIM(LTRIM(sayfalar_sayfa_url)) = RTRIM(LTRIM(?)) AND sayfalar_durum = 1
        ", [trim($pageFile)]);
        
        if (!$page) {
            // Sayfa tanımı yoksa erişim yok
            return [
                'has_access' => false,
                'can_view' => false,
                'can_add' => false,
                'can_edit' => false,
                'can_delete' => false,
                'can_view_kaynak' => false,
                'can_view_atanan' => false,
                'can_view_own_records' => false,
                'is_admin' => false,
                'error' => 'Sayfa tanımı bulunamadı'
            ];
        }
        
        // Departman yetkilerini kontrol et
        $permissions = $db->fetchOne("
            SELECT 
                gor,
                ekle,
                duzenle,
                sil,
                kaynak_gor,
                atanan_gor,
                kendi_kullanicini_gor
            FROM Menu_SayfaYetkileri
            WHERE departman_id = ? 
              AND sayfa_id = ? 
              AND durum = 1
        ", [$departmanId, $page['sayfalar_id']]);
        
        if (!$permissions) {
            // Yetki kaydı yoksa erişim yok
            return [
                'has_access' => false,
                'can_view' => false,
                'can_add' => false,
                'can_edit' => false,
                'can_delete' => false,
                'can_view_kaynak' => false,
                'can_view_atanan' => false,
                'can_view_own_records' => false,
                'is_admin' => false,
                'error' => 'Bu sayfaya erişim yetkiniz yok'
            ];
        }
        
        return [
            'has_access' => (bool)$permissions['gor'],
            'can_view' => (bool)$permissions['gor'],
            'can_add' => (bool)$permissions['ekle'],
            'can_edit' => (bool)$permissions['duzenle'],
            'can_delete' => (bool)$permissions['sil'],
            'can_view_kaynak' => (bool)$permissions['kaynak_gor'],
            'can_view_atanan' => (bool)$permissions['atanan_gor'],
            'can_view_own_records' => (bool)$permissions['kendi_kullanicini_gor'],
            'is_admin' => false
        ];
    }

    /**
     * Kullanıcıya atanmış aktif ticket kaynak ID'lerini döner.
     *
     * @param int $userId Kullanıcı ID
     * @return int[] Kaynak ID listesi
     */
    public static function getKullaniciKaynakIdleri($userId) {
        if ((int)$userId <= 0) {
            return [];
        }

        $db = Database::getInstance();
        $satirlar = $db->fetchAll("
            SELECT Ticket_Kaynak_Personel_kaynak_id AS kaynak_id
            FROM Ticket_Kaynak_Personel
            WHERE Ticket_Kaynak_Personel_kullanici_id = ? AND Durum = 1
        ", [(int)$userId]);

        return array_map(static function ($satir) {
            return (int)$satir['kaynak_id'];
        }, $satirlar);
    }

    /**
     * Ticket görünürlüğü için SQL WHERE eki üretir (admin için boş döner).
     *
     * Yetki kombinasyonları (yetkiler kısıtlayıcı değil, genişleticidir):
     *  - kaynak_gor + atanan_gor : atandığı kaynaklardaki VEYA kendisine atanmış talepler
     *  - sadece atanan_gor       : tüm kaynaklarda kendisine atanmış talepler
     *  - sadece kaynak_gor       : atandığı kaynaklardaki talepler VEYA kendisine atanmış talepler
     *  - hiçbiri                 : kendi oluşturduğu VEYA kendisine atanmış talepler
     *
     * Kaynağı NULL olan (panelden açılan) talepler kaynak koşuluna takılmaz;
     * atanan koşulu her zaman eklendiği için atanmış personelde görünür.
     *
     * @param int    $userId         Kullanıcı ID
     * @param array  $permissions    checkPagePermissions() çıktısı
     * @param string $kaynakField    Kaynak id kolonu (örn: t.Tickets_kaynak_id)
     * @param string $atananField    Atanan kullanıcı kolonu (örn: t.Tickets_atanan_kullanici_id)
     * @param string $olusturanField Oluşturan kullanıcı kolonu (örn: t.Tickets_olusturan_kullanici_id)
     * @return string
     */
    public static function getTicketSqlWhere($userId, $permissions, $kaynakField, $atananField, $olusturanField) {
        if (!empty($permissions['is_admin'])) {
            return '';
        }

        $userId = (int)$userId;
        $kaynakYetkisi = !empty($permissions['can_view_kaynak']);
        $atananYetkisi = !empty($permissions['can_view_atanan']);

        // Hiçbir özel yetki yoksa varsayılan davranış korunur
        if (!$kaynakYetkisi && !$atananYetkisi) {
            return ' AND (' . $olusturanField . ' = ' . $userId . ' OR ' . $atananField . ' = ' . $userId . ')';
        }

        $kosullar = [];

        if ($kaynakYetkisi) {
            $kaynakIdler = self::getKullaniciKaynakIdleri($userId);
            if (!empty($kaynakIdler)) {
                $kosullar[] = $kaynakField . ' IN (' . implode(',', $kaynakIdler) . ')';
            }
        }

        // Kendisine atanmış talep her koşulda görünür
        $kosullar[] = $atananField . ' = ' . $userId;

        return ' AND (' . implode(' OR ', $kosullar) . ')';
    }

    /**
     * Tek bir ticket kaydına erişim kontrolü (getTicketSqlWhere ile aynı mantık).
     *
     * @param int   $userId      Kullanıcı ID
     * @param array $permissions checkPagePermissions() çıktısı
     * @param array $ticket      Tickets satırı (kaynak, atanan ve oluşturan alanlarıyla)
     * @return bool
     */
    public static function ticketErisebilirMi($userId, $permissions, $ticket) {
        if (!empty($permissions['is_admin'])) {
            return true;
        }

        $userId = (int)$userId;
        $kaynakYetkisi = !empty($permissions['can_view_kaynak']);
        $atananYetkisi = !empty($permissions['can_view_atanan']);

        $atanan = (int)($ticket['Tickets_atanan_kullanici_id'] ?? 0);
        $olusturan = (int)($ticket['Tickets_olusturan_kullanici_id'] ?? 0);
        $kaynak = (int)($ticket['Tickets_kaynak_id'] ?? 0);

        if (!$kaynakYetkisi && !$atananYetkisi) {
            return $olusturan === $userId || $atanan === $userId;
        }

        // Kendisine atanmış talep her koşulda erişilebilir
        if ($atanan === $userId) {
            return true;
        }

        if ($kaynakYetkisi && $kaynak > 0 && in_array($kaynak, self::getKullaniciKaynakIdleri($userId), true)) {
            return true;
        }

        return false;
    }
    
    /**
     * Yetkisiz erişim durumunda hata sayfası göster
     */
    public static function accessDenied($message = 'Bu sayfaya erişim yetkiniz yok!') {
        http_response_code(403);
        ?>
        <!DOCTYPE html>
        <html lang="tr">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Erişim Engellendi</title>
            <link rel="stylesheet" href="/admin/assets/css/adminlte.min.css">
            <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
        </head>
        <body class="d-flex align-items-center justify-content-center" style="min-height: 100vh; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
            <div class="text-center text-white">
                <i class="bi bi-shield-x" style="font-size: 120px; opacity: 0.8;"></i>
                <h1 class="display-4 mt-4">403 - Erişim Engellendi</h1>
                <p class="lead mb-4"><?= htmlspecialchars($message) ?></p>
                <a href="/admin/anasayfa" class="btn btn-light btn-lg">
                    <i class="bi bi-house-door"></i> Ana Sayfaya Dön
                </a>
            </div>
        </body>
        </html>
        <?php
        exit;
    }
}
