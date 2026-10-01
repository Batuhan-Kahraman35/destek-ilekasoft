<?php
/**
 * Admin Panel - Ticket Detay
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
requireAuth();

$user = Auth::user();
$db   = Database::getInstance();

// Sayfa yetki kontrolü
// Detay sayfasının ayrı yetki tanımı yoktur; yetkiler ticket-liste.php üzerinden alınır.
$currentPageFile = basename($_SERVER['PHP_SELF']);
$pagePermissions = PageAuth::checkPagePermissions(
    $user['kullanici_id'],
    $user['departman_id'],
    'ticket-liste.php'
);

if (!$pagePermissions['has_access']) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => $pagePermissions['error'] ?? 'Erişim yetkiniz yok.']);
        exit;
    }
    PageAuth::accessDenied($pagePermissions['error'] ?? 'Bu sayfaya erişim yetkiniz bulunmamaktadır.');
}

$isAdmin = ($user['departman_id'] == 1);

// Sayfa bilgileri
$pageInfo = $db->fetchOne("
    SELECT s.sayfalar_sayfa_adi, s.sayfalar_aciklama, m.menuler_menu_adi AS menu_adi
    FROM Menu_Sayfalar s
    LEFT JOIN Menuler m ON s.sayfalar_menu_id = m.menuler_id
    WHERE s.sayfalar_sayfa_url LIKE ? AND s.sayfalar_durum = 1
", ['%' . $currentPageFile]);

$pageTitle = $pageInfo['sayfalar_sayfa_adi'] ?? 'Ticket Detayı';
$menuAdi   = $pageInfo['menu_adi']           ?? null;

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle    = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';

// =====================================================================
// AJAX İşlemleri
// =====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');

    try {
        $action   = $_POST['action']    ?? '';
        $ticketId = intval($_POST['ticket_id'] ?? 0);

        // -----------------------------------------------------------------
        // ticket_getir
        // -----------------------------------------------------------------
        if ($action === 'ticket_getir') {
            if ($ticketId <= 0) throw new Exception('Geçersiz ticket.');

            $ticket = $db->fetchOne("
                SELECT
                    t.Tickets_id,
                    t.Tickets_no,
                    t.Tickets_konu,
                    t.Tickets_atanan_departman_id,
                    t.Tickets_atanan_kullanici_id,
                    t.Tickets_olusturan_kullanici_id,
                    t.Tickets_kaynak_id,
                    kn.Ticket_Kaynak_ad,
                    kn.Ticket_Kaynak_tip,
                    CONVERT(VARCHAR(16), t.OlusturmaTarihi, 120)            AS acilis_tarihi,
                    CONVERT(VARCHAR(16), t.Tickets_son_yanit_tarihi, 120)   AS son_yanit_tarihi,
                    CONVERT(VARCHAR(16), t.Tickets_kapanis_tarihi, 120)     AS kapanis_tarihi,
                    k.Ticket_Kategoriler_id,   k.Ticket_Kategoriler_ad,   k.Ticket_Kategoriler_renk,
                    o.Ticket_Oncelikler_id,    o.Ticket_Oncelikler_ad,    o.Ticket_Oncelikler_renk,   o.Ticket_Oncelikler_ikon,
                    d.Ticket_Durumlar_id,      d.Ticket_Durumlar_ad,      d.Ticket_Durumlar_renk,     d.Ticket_Durumlar_ikon,
                    d.Ticket_Durumlar_kapatma_durumu,
                    ku.kullanici_ad AS olusturan_ad,
                    ku.kullanici_soyad AS olusturan_soyad,
                    ka.kullanici_ad   AS atanan_ad,
                    ka.kullanici_soyad AS atanan_soyad
                FROM Tickets t
                INNER JOIN Ticket_Kategoriler k ON t.Tickets_kategori_id = k.Ticket_Kategoriler_id
                INNER JOIN Ticket_Oncelikler  o ON t.Tickets_oncelik_id  = o.Ticket_Oncelikler_id
                INNER JOIN Ticket_Durumlar    d ON t.Tickets_durum_id    = d.Ticket_Durumlar_id
                LEFT  JOIN kullanicilar       ku ON t.Tickets_olusturan_kullanici_id = ku.kullanici_id
                LEFT  JOIN kullanicilar       ka ON t.Tickets_atanan_kullanici_id    = ka.kullanici_id
                LEFT  JOIN Ticket_Kaynak      kn ON t.Tickets_kaynak_id              = kn.Ticket_Kaynak_id
                WHERE t.Tickets_id = ? AND t.Durum = 1
            ", [$ticketId]);

            if (!$ticket) throw new Exception('Ticket bulunamadı.');

            // Yetki: kaynak / atanan yetkilerine göre erişim
            if (!PageAuth::ticketErisebilirMi($user['kullanici_id'], $pagePermissions, $ticket)) {
                throw new Exception('Bu ticket\'a erişim yetkiniz yok.');
            }

            // Açılış ekleri
            $ticket['ekler'] = $db->fetchAll("
                SELECT Ticket_Ekler_id, Ticket_Ekler_dosya_adi, Ticket_Ekler_dosya_yolu, Ticket_Ekler_dosya_boyutu, Ticket_Ekler_mime_turu
                FROM Ticket_Ekler
                WHERE Ticket_Ekler_ticket_id = ? AND Ticket_Ekler_mesaj_id IS NULL AND Durum = 1
            ", [$ticketId]);

            // CC listesi
            $ticket['cc_listesi'] = $db->fetchAll("
                SELECT cc.Ticket_CC_id, cc.Ticket_CC_kullanici_id,
                       k.kullanici_ad, k.kullanici_soyad, k.kullanici_email
                FROM Ticket_CC cc
                INNER JOIN kullanicilar k ON cc.Ticket_CC_kullanici_id = k.kullanici_id
                WHERE cc.Ticket_CC_ticket_id = ? AND cc.Durum = 1
                ORDER BY k.kullanici_ad, k.kullanici_soyad
            ", [$ticketId]);

            echo json_encode(['success' => true, 'data' => $ticket]);
            exit;
        }

        // -----------------------------------------------------------------
        // mesajlar_getir
        // -----------------------------------------------------------------
        if ($action === 'mesajlar_getir') {
            if ($ticketId <= 0) throw new Exception('Geçersiz ticket.');

            $whereIcNot = $isAdmin ? "" : "AND m.Ticket_Mesajlar_ic_not = 0";

            $mesajlar = $db->fetchAll("
                SELECT
                    m.Ticket_Mesajlar_id,
                    m.Ticket_Mesajlar_mesaj,
                    m.Ticket_Mesajlar_ic_not,
                    m.Ticket_Mesajlar_yazan_kul_id,
                    CONVERT(VARCHAR(16), m.OlusturmaTarihi, 120) AS tarih,
                    CASE WHEN m.GuncellemeTarihi > m.OlusturmaTarihi THEN 1 ELSE 0 END AS duzenlendi_mi,
                    COALESCE(m.Ticket_Mesajlar_yazan_ad, k.kullanici_ad) AS kullanici_ad,
                    COALESCE(m.Ticket_Mesajlar_yazan_soyad, k.kullanici_soyad) AS kullanici_soyad
                FROM Ticket_Mesajlar m
                LEFT JOIN kullanicilar k ON m.Ticket_Mesajlar_yazan_kul_id = k.kullanici_id
                WHERE m.Ticket_Mesajlar_ticket_id = ? AND m.Durum = 1 $whereIcNot
                ORDER BY m.OlusturmaTarihi ASC
            ", [$ticketId]);

            // Her mesajın eklerini al
            foreach ($mesajlar as &$mesaj) {
                $mesaj['ekler'] = $db->fetchAll("
                    SELECT Ticket_Ekler_id, Ticket_Ekler_dosya_adi, Ticket_Ekler_dosya_yolu, Ticket_Ekler_dosya_boyutu, Ticket_Ekler_mime_turu
                    FROM Ticket_Ekler
                    WHERE Ticket_Ekler_mesaj_id = ? AND Durum = 1
                ", [$mesaj['Ticket_Mesajlar_id']]);
            }
            unset($mesaj);

            echo json_encode(['success' => true, 'data' => $mesajlar]);
            exit;
        }

        // -----------------------------------------------------------------
        // mesaj_gonder
        // -----------------------------------------------------------------
        if ($action === 'mesaj_gonder') {
            if ($ticketId <= 0) throw new Exception('Geçersiz ticket.');

            $mesajText = trim($_POST['mesaj'] ?? '');
            $icNotMesaj = $isAdmin ? trim($_POST['ic_not_mesaj'] ?? '') : '';

            if (empty($mesajText) && empty($icNotMesaj)) throw new Exception('Mesaj veya İç Not boş olamaz.');

            // Ticket erişim kontrolü
            $ticket = $db->fetchOne("SELECT Tickets_id, Tickets_olusturan_kullanici_id, Tickets_atanan_kullanici_id, Tickets_kaynak_id FROM Tickets WHERE Tickets_id = ? AND Durum = 1", [$ticketId]);
            if (!$ticket) throw new Exception('Ticket bulunamadı.');
            if (!PageAuth::ticketErisebilirMi($user['kullanici_id'], $pagePermissions, $ticket)) {
                throw new Exception('Bu ticket\'a mesaj gönderme yetkiniz yok.');
            }

            $now = app_now();
            $mesajId = 0;

            if (!empty($mesajText)) {
                $mesajId = $db->insert('Ticket_Mesajlar', [
                    'Ticket_Mesajlar_ticket_id'    => $ticketId,
                    'Ticket_Mesajlar_mesaj'        => $mesajText,
                    'Ticket_Mesajlar_ic_not'       => 0,
                    'Ticket_Mesajlar_yazan_kul_id' => $user['kullanici_id'],
                    'OlusturanKullanici'           => $user['kullanici_id'],
                    'OlusturmaTarihi'              => $now,
                    'GuncelleyenKullanici'         => $user['kullanici_id'],
                    'GuncellemeTarihi'             => $now,
                    'Durum'                        => 1,
                ]);
            }

            if (!empty($icNotMesaj)) {
                $icNotId = $db->insert('Ticket_Mesajlar', [
                    'Ticket_Mesajlar_ticket_id'    => $ticketId,
                    'Ticket_Mesajlar_mesaj'        => $icNotMesaj,
                    'Ticket_Mesajlar_ic_not'       => 1,
                    'Ticket_Mesajlar_yazan_kul_id' => $user['kullanici_id'],
                    'OlusturanKullanici'           => $user['kullanici_id'],
                    'OlusturmaTarihi'              => $now,
                    'GuncelleyenKullanici'         => $user['kullanici_id'],
                    'GuncellemeTarihi'             => $now,
                    'Durum'                        => 1,
                ]);
                if ($mesajId == 0) {
                    $mesajId = $icNotId;
                }
            }

            // Durum ve Öncelik Güncelleme
            $durumId = intval($_POST['durum_id'] ?? 0);
            $oncelikId = intval($_POST['oncelik_id'] ?? 0);

            $ticketUpdate = [
                'Tickets_son_yanit_tarihi' => $now,
                'GuncelleyenKullanici'     => $user['kullanici_id'],
                'GuncellemeTarihi'         => $now,
            ];

            // Müşteriye yanıt yazıldıysa bekleyen cevap işaretini kaldır
            if (!empty($mesajText)) {
                $ticketUpdate['Tickets_musteri_cevap_var'] = 0;
            }

            if ($durumId > 0) {
                $ticketUpdate['Tickets_durum_id'] = $durumId;
                $durumRow = $db->fetchOne("SELECT Ticket_Durumlar_kapatma_durumu FROM Ticket_Durumlar WHERE Ticket_Durumlar_id = ? AND Durum = 1", [$durumId]);
                if ($durumRow && $durumRow['Ticket_Durumlar_kapatma_durumu']) {
                    $ticketUpdate['Tickets_kapanis_tarihi'] = $now;
                }
            }
            if ($oncelikId > 0) {
                $ticketUpdate['Tickets_oncelik_id'] = $oncelikId;
            }

            $db->update('Tickets', $ticketUpdate, ['Tickets_id' => $ticketId]);

            // Dosya ekleri
            if (isset($_FILES['dosyalar']['name']) && is_array($_FILES['dosyalar']['name'])) {
                $uploadDir = __DIR__ . '/../../assets/uploads/tickets/' . $ticketId . '/';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

                $izinliMime = ['image/jpeg','image/png','image/gif','application/pdf',
                               'application/msword','application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                               'application/vnd.ms-excel','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                               'text/plain'];

                foreach ($_FILES['dosyalar']['name'] as $i => $dosyaAdi) {
                    if (empty($dosyaAdi) || $_FILES['dosyalar']['error'][$i] !== UPLOAD_ERR_OK) continue;
                    $mime = mime_content_type($_FILES['dosyalar']['tmp_name'][$i]);
                    if (!in_array($mime, $izinliMime)) continue;
                    if ($_FILES['dosyalar']['size'][$i] > 10 * 1024 * 1024) continue; // 10MB

                    $ext       = pathinfo($dosyaAdi, PATHINFO_EXTENSION);
                    $guvenliAd = bin2hex(random_bytes(8)) . '.' . preg_replace('/[^a-z0-9]/i', '', $ext);
                    $hedefYol  = $uploadDir . $guvenliAd;

                    if (move_uploaded_file($_FILES['dosyalar']['tmp_name'][$i], $hedefYol)) {
                        $db->insert('Ticket_Ekler', [
                            'Ticket_Ekler_ticket_id'    => $ticketId,
                            'Ticket_Ekler_mesaj_id'     => $mesajId,
                            'Ticket_Ekler_dosya_adi'    => $dosyaAdi,
                            'Ticket_Ekler_dosya_yolu'   => 'assets/uploads/tickets/' . $ticketId . '/' . $guvenliAd,
                            'Ticket_Ekler_dosya_boyutu' => $_FILES['dosyalar']['size'][$i],
                            'Ticket_Ekler_mime_turu'    => $mime,
                            'OlusturanKullanici'        => $user['kullanici_id'],
                            'OlusturmaTarihi'           => $now,
                            'GuncelleyenKullanici'      => $user['kullanici_id'],
                            'GuncellemeTarihi'          => $now,
                            'Durum'                     => 1,
                        ]);
                    }
                }
            }

            // Müşteriye yanıt gönderildiyse (iç not değil) bildirim tetikle → mail/WhatsApp
            if (!empty($mesajText)) {
                require_once __DIR__ . '/../includes/TicketService.php';
                (new TicketService($db))->yeniMesajBildirimiTetikle($ticketId, $user['kullanici_id'], 'panel', $mesajText);
            }

            echo json_encode(['success' => true, 'message' => 'Mesaj gönderildi.', 'mesaj_id' => $mesajId]);
            exit;
        }

        // -----------------------------------------------------------------
        // mesaj_duzenle
        // -----------------------------------------------------------------
        if ($action === 'mesaj_duzenle') {
            $mesajId = intval($_POST['mesaj_id'] ?? 0);
            $yeniMesaj = trim($_POST['mesaj'] ?? '');
            if ($mesajId <= 0) throw new Exception('Geçersiz mesaj.');
            if (empty($yeniMesaj)) throw new Exception('Mesaj boş olamaz.');

            $mesajInfo = $db->fetchOne("SELECT Ticket_Mesajlar_yazan_kul_id FROM Ticket_Mesajlar WHERE Ticket_Mesajlar_id = ? AND Durum = 1", [$mesajId]);
            if (!$mesajInfo) throw new Exception('Mesaj bulunamadı.');

            // Sadece kendi mesajını düzenleyebilir
            if ($mesajInfo['Ticket_Mesajlar_yazan_kul_id'] != $user['kullanici_id']) {
                throw new Exception('Sadece kendi mesajınızı düzenleyebilirsiniz.');
            }

            $db->update('Ticket_Mesajlar', [
                'Ticket_Mesajlar_mesaj' => $yeniMesaj,
                'GuncelleyenKullanici'  => $user['kullanici_id'],
                'GuncellemeTarihi'      => app_now()
            ], ['Ticket_Mesajlar_id' => $mesajId]);

            echo json_encode(['success' => true, 'message' => 'Mesaj düzenlendi.']);
            exit;
        }

        // -----------------------------------------------------------------
        // durum_guncelle
        // -----------------------------------------------------------------
        if ($action === 'durum_guncelle') {
            if (!$pagePermissions['can_edit']) throw new Exception('Düzenleme yetkiniz yok.');
            if ($ticketId <= 0) throw new Exception('Geçersiz ticket.');

            $durumId = intval($_POST['durum_id'] ?? 0);
            if ($durumId <= 0) throw new Exception('Geçersiz durum.');

            $durum = $db->fetchOne("SELECT Ticket_Durumlar_kapatma_durumu FROM Ticket_Durumlar WHERE Ticket_Durumlar_id = ? AND Durum = 1", [$durumId]);
            if (!$durum) throw new Exception('Durum bulunamadı.');

            $updateData = [
                'Tickets_durum_id'    => $durumId,
                'GuncelleyenKullanici' => $user['kullanici_id'],
                'GuncellemeTarihi'    => app_now(),
            ];
            if ($durum['Ticket_Durumlar_kapatma_durumu']) {
                $updateData['Tickets_kapanis_tarihi'] = app_now();
                $updateData['Tickets_musteri_cevap_var'] = 0;
            }

            $db->update('Tickets', $updateData, ['Tickets_id' => $ticketId]);
            echo json_encode(['success' => true, 'message' => 'Durum güncellendi.']);
            exit;
        }

        // -----------------------------------------------------------------
        // atama_guncelle (sadece admin)
        // -----------------------------------------------------------------
        if ($action === 'atama_guncelle') {
            if (!$isAdmin) throw new Exception('Bu işlem için yetkiniz yok.');
            if ($ticketId <= 0) throw new Exception('Geçersiz ticket.');

            $kullaniciId = !empty($_POST['kullanici_id']) ? intval($_POST['kullanici_id']) : null;

            $eskiKayit    = $db->fetchOne("SELECT Tickets_atanan_kullanici_id FROM Tickets WHERE Tickets_id = ?", [$ticketId]);
            $eskiAtananId = $eskiKayit ? (int)$eskiKayit['Tickets_atanan_kullanici_id'] : 0;

            $db->update('Tickets', [
                'Tickets_atanan_kullanici_id' => $kullaniciId,
                'GuncelleyenKullanici'        => $user['kullanici_id'],
                'GuncellemeTarihi'            => app_now(),
            ], ['Tickets_id' => $ticketId]);

            // Başkasına atandıysa bildirim gönder
            require_once __DIR__ . '/../includes/TicketService.php';
            (new TicketService($db))->atamaBildirimiTetikle($ticketId, $kullaniciId, $user['kullanici_id'], $eskiAtananId);

            echo json_encode(['success' => true, 'message' => 'Atama güncellendi.']);
            exit;
        }

        // -----------------------------------------------------------------
        // oncelik_guncelle
        // -----------------------------------------------------------------
        if ($action === 'oncelik_guncelle') {
            if (!$pagePermissions['can_edit']) throw new Exception('Düzenleme yetkiniz yok.');
            if ($ticketId <= 0) throw new Exception('Geçersiz ticket.');

            $oncelikId = intval($_POST['oncelik_id'] ?? 0);
            if ($oncelikId <= 0) throw new Exception('Geçersiz öncelik.');

            $db->update('Tickets', [
                'Tickets_oncelik_id'  => $oncelikId,
                'GuncelleyenKullanici' => $user['kullanici_id'],
                'GuncellemeTarihi'    => app_now(),
            ], ['Tickets_id' => $ticketId]);

            echo json_encode(['success' => true, 'message' => 'Öncelik güncellendi.']);
            exit;
        }

        // -----------------------------------------------------------------
        // kategori_guncelle
        // -----------------------------------------------------------------
        if ($action === 'kategori_guncelle') {
            if (!$pagePermissions['can_edit']) throw new Exception('Düzenleme yetkiniz yok.');
            if ($ticketId <= 0) throw new Exception('Geçersiz ticket.');

            $kategoriId = intval($_POST['kategori_id'] ?? 0);
            if ($kategoriId <= 0) throw new Exception('Geçersiz kategori.');

            $kategori = $db->fetchOne("SELECT Ticket_Kategoriler_id FROM Ticket_Kategoriler WHERE Ticket_Kategoriler_id = ? AND Durum = 1", [$kategoriId]);
            if (!$kategori) throw new Exception('Kategori bulunamadı.');

            $db->update('Tickets', [
                'Tickets_kategori_id'  => $kategoriId,
                'GuncelleyenKullanici' => $user['kullanici_id'],
                'GuncellemeTarihi'     => app_now(),
            ], ['Tickets_id' => $ticketId]);

            echo json_encode(['success' => true, 'message' => 'Kategori güncellendi.']);
            exit;
        }

        // -----------------------------------------------------------------
        // select_options (durum/öncelik/kategori/personel listesi)
        // -----------------------------------------------------------------
        if ($action === 'select_options') {
            $durumlar        = $db->fetchAll("SELECT Ticket_Durumlar_id AS id, Ticket_Durumlar_ad AS ad, Ticket_Durumlar_renk AS renk FROM Ticket_Durumlar WHERE Durum = 1 ORDER BY Ticket_Durumlar_sira");
            $oncelikler      = $db->fetchAll("SELECT Ticket_Oncelikler_id AS id, Ticket_Oncelikler_ad AS ad, Ticket_Oncelikler_renk AS renk FROM Ticket_Oncelikler WHERE Durum = 1 ORDER BY Ticket_Oncelikler_sira");
            $kategoriler     = $db->fetchAll("SELECT Ticket_Kategoriler_id AS id, Ticket_Kategoriler_ad AS ad, Ticket_Kategoriler_renk AS renk FROM Ticket_Kategoriler WHERE Durum = 1 ORDER BY Ticket_Kategoriler_sira");
            $personeller     = $isAdmin ? $db->fetchAll("SELECT kullanici_id AS id, kullanici_ad + ' ' + kullanici_soyad AS ad FROM kullanicilar WHERE kullanici_durum = 1 ORDER BY kullanici_ad") : [];
            $gorevDurumlari  = $db->fetchAll("SELECT Ticket_Durumlar_id AS id, Ticket_Durumlar_ad AS ad, Ticket_Durumlar_renk AS renk, Ticket_Durumlar_kapatma_durumu AS kapatma FROM Ticket_Durumlar WHERE Durum = 1 ORDER BY Ticket_Durumlar_sira");

            echo json_encode(['success' => true, 'data' => compact('durumlar', 'oncelikler', 'kategoriler', 'personeller', 'gorevDurumlari')]);
            exit;
        }

        // -----------------------------------------------------------------
        // gorev_listele
        // -----------------------------------------------------------------
        if ($action === 'gorev_listele') {
            if ($ticketId <= 0) throw new Exception('Geçersiz ticket.');

            $gorevler = $db->fetchAll("
                SELECT
                    g.Ticket_Gorevler_id,
                    g.Ticket_Gorevler_baslik,
                    g.Ticket_Gorevler_aciklama,
                    g.Ticket_Gorevler_durum_id,
                    g.Ticket_Gorevler_sira,
                    d.Ticket_Durumlar_ad      AS durum_ad,
                    d.Ticket_Durumlar_renk    AS durum_renk,
                    d.Ticket_Durumlar_kapatma_durumu AS durum_kapatma,
                    k.kullanici_ad            AS atanan_ad,
                    k.kullanici_soyad         AS atanan_soyad,
                    g.Ticket_Gorevler_atanan_id
                FROM Ticket_Gorevler g
                INNER JOIN Ticket_Durumlar d ON g.Ticket_Gorevler_durum_id = d.Ticket_Durumlar_id
                LEFT  JOIN kullanicilar    k ON g.Ticket_Gorevler_atanan_id = k.kullanici_id
                WHERE g.Ticket_Gorevler_ticket_id = ?
                ORDER BY g.Ticket_Gorevler_sira, g.Ticket_Gorevler_id
            ", [$ticketId]);

            echo json_encode(['success' => true, 'data' => $gorevler]);
            exit;
        }

        // -----------------------------------------------------------------
        // gorev_ekle
        // -----------------------------------------------------------------
        if ($action === 'gorev_ekle') {
            if (!$pagePermissions['can_edit']) throw new Exception('Düzenleme yetkiniz yok.');
            if ($ticketId <= 0) throw new Exception('Geçersiz ticket.');

            $baslik    = trim($_POST['baslik']    ?? '');
            $aciklama  = trim($_POST['aciklama']  ?? '') ?: null;
            $atananId  = !empty($_POST['atanan_id']) ? intval($_POST['atanan_id']) : null;

            if (empty($baslik)) throw new Exception('Görev başlığı boş olamaz.');

            $now = app_now();
            $gorevId = $db->insert('Ticket_Gorevler', [
                'Ticket_Gorevler_ticket_id' => $ticketId,
                'Ticket_Gorevler_baslik'    => $baslik,
                'Ticket_Gorevler_aciklama'  => $aciklama,
                'Ticket_Gorevler_atanan_id' => $atananId,
                'Ticket_Gorevler_durum_id'  => 1,
                'Ticket_Gorevler_sira'      => 0,
                'OlusturanKullanici'        => $user['kullanici_id'],
                'OlusturmaTarihi'           => $now,
                'GuncelleyenKullanici'      => $user['kullanici_id'],
                'GuncellemeTarihi'          => $now,
            ]);

            echo json_encode(['success' => true, 'message' => 'Görev eklendi.', 'gorev_id' => $gorevId]);
            exit;
        }

        // -----------------------------------------------------------------
        // gorev_durum_guncelle
        // -----------------------------------------------------------------
        if ($action === 'gorev_durum_guncelle') {
            if (!$pagePermissions['can_edit']) throw new Exception('Düzenleme yetkiniz yok.');

            $gorevId = intval($_POST['gorev_id'] ?? 0);
            $durumId = intval($_POST['durum_id'] ?? 0);
            if ($gorevId <= 0) throw new Exception('Geçersiz görev.');
            if ($durumId <= 0) throw new Exception('Geçersiz durum.');

            // Görev bu ticket'a ait mi?
            $gorev = $db->fetchOne("SELECT Ticket_Gorevler_id FROM Ticket_Gorevler WHERE Ticket_Gorevler_id = ? AND Ticket_Gorevler_ticket_id = ?", [$gorevId, $ticketId]);
            if (!$gorev) throw new Exception('Görev bulunamadı.');

            $db->update('Ticket_Gorevler', [
                'Ticket_Gorevler_durum_id' => $durumId,
                'GuncelleyenKullanici'     => $user['kullanici_id'],
                'GuncellemeTarihi'         => app_now(),
            ], ['Ticket_Gorevler_id' => $gorevId]);

            echo json_encode(['success' => true, 'message' => 'Görev durumu güncellendi.']);
            exit;
        }

        // -----------------------------------------------------------------
        // gorev_sil
        // -----------------------------------------------------------------
        if ($action === 'gorev_sil') {
            if (!$pagePermissions['can_edit']) throw new Exception('Düzenleme yetkiniz yok.');

            $gorevId = intval($_POST['gorev_id'] ?? 0);
            if ($gorevId <= 0) throw new Exception('Geçersiz görev.');

            $gorev = $db->fetchOne("SELECT Ticket_Gorevler_id FROM Ticket_Gorevler WHERE Ticket_Gorevler_id = ? AND Ticket_Gorevler_ticket_id = ?", [$gorevId, $ticketId]);
            if (!$gorev) throw new Exception('Görev bulunamadı.');

            $db->execute("DELETE FROM Ticket_Gorevler WHERE Ticket_Gorevler_id = ?", [$gorevId]);

            echo json_encode(['success' => true, 'message' => 'Görev silindi.']);
            exit;
        }

        // -----------------------------------------------------------------
        // cc_ekle
        // -----------------------------------------------------------------
        if ($action === 'cc_ekle') {
            if (!$pagePermissions['can_edit']) throw new Exception('Düzenleme yetkiniz yok.');
            if ($ticketId <= 0) throw new Exception('Geçersiz ticket.');

            $ccKullaniciId = intval($_POST['kullanici_id'] ?? 0);
            if ($ccKullaniciId <= 0) throw new Exception('Geçersiz kullanıcı.');

            // Zaten ekli mi kontrol et
            $mevcut = $db->fetchOne("
                SELECT Ticket_CC_id FROM Ticket_CC 
                WHERE Ticket_CC_ticket_id = ? AND Ticket_CC_kullanici_id = ? AND Durum = 1
            ", [$ticketId, $ccKullaniciId]);
            if ($mevcut) throw new Exception('Bu kullanıcı zaten CC listesinde.');

            $db->insert('Ticket_CC', [
                'Ticket_CC_ticket_id'     => $ticketId,
                'Ticket_CC_kullanici_id'  => $ccKullaniciId,
                'OlusturanKullanici'      => $user['kullanici_id'],
                'OlusturmaTarihi'         => app_now(),
                'GuncelleyenKullanici'    => $user['kullanici_id'],
                'GuncellemeTarihi'        => app_now(),
                'Durum'                   => 1
            ]);

            // CC'ye eklenen kişiye bildirim gönder
            require_once __DIR__ . '/../includes/TicketService.php';
            (new TicketService($db))->ccBildirimiTetikle($ticketId, $ccKullaniciId, $user['kullanici_id']);

            echo json_encode(['success' => true, 'message' => 'Kullanıcı CC listesine eklendi.']);
            exit;
        }

        // -----------------------------------------------------------------
        // cc_sil
        // -----------------------------------------------------------------
        if ($action === 'cc_sil') {
            if (!$pagePermissions['can_edit']) throw new Exception('Düzenleme yetkiniz yok.');

            $ccId = intval($_POST['cc_id'] ?? 0);
            if ($ccId <= 0) throw new Exception('Geçersiz CC kaydı.');

            $db->update('Ticket_CC', [
                'Durum'                => 0,
                'GuncelleyenKullanici' => $user['kullanici_id'],
                'GuncellemeTarihi'     => app_now()
            ], ['Ticket_CC_id' => $ccId]);

            echo json_encode(['success' => true, 'message' => 'Kullanıcı CC listesinden çıkarıldı.']);
            exit;
        }

        throw new Exception('Geçersiz işlem.');

    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

// GET: Ticket ID kontrolü
$ticketId = intval($_GET['id'] ?? 0);
if ($ticketId <= 0) {
    header('Location: /admin/ticket-liste');
    exit;
}

// Hazır cevap şablonları (yalnızca personel/admin)
$cevapSablonlari = [];
if ($isAdmin) {
    $cevapSablonlari = $db->fetchAll(
        "SELECT Sablon_Id, Sablon_Ad, Sablon_Icerik FROM Ticket_Mesaj_Sablonlari WHERE Sablon_Tip='cevap' AND Durum=1 ORDER BY Sablon_Ad"
    );
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
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/admin/assets/css/custom.css">

    <style>
        .mesaj-metni { overflow-wrap: anywhere; }
        .mesaj-metni a { text-decoration: underline; }
        .mesaj-balonu {
            max-width: 85%;
            border-radius: 10px;
            padding: 0.75rem 1rem;
            margin-bottom: 1rem;
            position: relative;
        }
        .mesaj-gelen  { background: #f1f3f4; border-left: 3px solid #6c757d; }
        .mesaj-giden  { background: #e8f4fd; border-left: 3px solid #0d6efd; margin-left: auto; }
        .mesaj-ic-not { background: #fff8e1; border-left: 3px solid #ffc107; }
        .mesaj-meta   { font-size: 0.75rem; color: #6c757d; }
        .mesaj-ic-not-badge { font-size: 0.7rem; }
        .panel-bilgi label { font-size: 0.78rem; color: #6c757d; margin-bottom: 0; }
        .panel-bilgi .deger { font-size: 0.9rem; font-weight: 500; }
        .ek-dosya { font-size: 0.8rem; }
        #mesajAlani { max-height: 600px; overflow-y: auto; }
        .ticket-no-header { font-family: monospace; font-size: 0.9rem; color: #6c757d; }

        /* Dosya ekleme alanı (sürükle-bırak + Ctrl+V) */
        #dropZone {
            border: 2px dashed #ced4da;
            border-radius: 8px;
            padding: 1rem;
            text-align: center;
            cursor: pointer;
            background: #f8f9fa;
            transition: border-color .15s, background-color .15s;
        }
        #dropZone:hover { border-color: #0d6efd; background: #f1f7ff; }
        #dropZone.dz-aktif { border-color: #0d6efd; background: #e7f1ff; }
        #dropZone .dz-metin { font-size: 0.82rem; color: #6c757d; }
        .ek-onizleme-item {
            position: relative;
            width: 84px;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            padding: 4px;
            background: #fff;
            text-align: center;
        }
        .ek-onizleme-item img { width: 100%; height: 52px; object-fit: cover; border-radius: 4px; }
        .ek-onizleme-item .dosya-ikon { height: 52px; display: flex; align-items: center; justify-content: center; font-size: 1.6rem; color: #6c757d; }
        .ek-onizleme-item .dosya-ad {
            font-size: 0.66rem; line-height: 1.1; margin-top: 3px;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .ek-onizleme-item .btn-ek-kaldir {
            position: absolute; top: -8px; right: -8px;
            width: 20px; height: 20px; line-height: 1; padding: 0;
            border-radius: 50%;
        }
    </style>
</head>
<body class="layout-fixed sidebar-expand-lg sidebar-open bg-body-tertiary">
<div class="app-wrapper">

    <?php include __DIR__ . '/../includes/header.php'; ?>
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>

    <main class="app-main">
        <!-- Başlık -->
        <div class="app-content-header">
            <div class="container-fluid">
                <div class="row">
                    <div class="col-sm-6">
                        <h3 class="mb-0" id="ticket-konu-baslik">Yükleniyor...</h3>
                        <small class="text-muted ticket-no-header" id="ticket-no-baslik"></small>
                    </div>
                    <div class="col-sm-6 d-flex flex-column align-items-end gap-1">
                        <a href="/admin/ticket-liste" class="btn btn-secondary btn-sm">
                            <i class="bi bi-arrow-left me-1"></i> Listeye Dön
                        </a>
                        <ol class="breadcrumb mb-0">
                            <?php if ($menuAdi): ?>
                            <li class="breadcrumb-item"><?= htmlspecialchars($menuAdi) ?></li>
                            <?php endif; ?>
                            <li class="breadcrumb-item"><a href="/admin/ticket-liste">Ticket Listesi</a></li>
                            <li class="breadcrumb-item active">Detay</li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>

        <!-- İçerik -->
        <div class="app-content">
            <div class="container-fluid">
                <div class="row">

                    <!-- Sol: Mesaj Alanı -->
                    <div class="col-12 col-lg-8">

                        <!-- Ticket Açıklama -->
                        <div class="card mb-3" id="card-aciklama">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <span><i class="bi bi-file-text me-1"></i> Açıklama</span>
                                <span id="acilis-tarihi" class="text-muted small"></span>
                            </div>
                            <div class="card-body">
                                <div id="ticket-aciklama" class="mb-2">Yükleniyor...</div>
                                <div id="acilis-ekler"></div>
                            </div>
                        </div>

                        <!-- Mesajlar -->
                        <div class="card mb-3">
                            <div class="card-header">
                                <i class="bi bi-chat-dots me-1"></i> Mesajlar
                                <span class="badge text-bg-secondary ms-1" id="mesaj-sayisi-badge">0</span>
                            </div>
                            <div class="card-body p-2" id="mesajAlani">
                                <div class="text-center text-muted py-3" id="mesaj-yukleniyor">
                                    <i class="bi bi-hourglass-split me-1"></i> Yükleniyor...
                                </div>
                            </div>
                        </div>

                        <!-- Yanıt Formu -->
                        <div class="card" id="card-yanit">
                            <div class="card-header">
                                <i class="bi bi-reply me-1"></i> Yanıt Yaz
                            </div>
                            <div class="card-body">
                                <form id="formYanit" enctype="multipart/form-data">
                                    <?php if ($isAdmin): ?>
                                    <div class="mb-3">
                                        <label class="form-label text-warning mb-1"><i class="bi bi-sticky me-1"></i> İç Not <small>(sadece personel görebilir)</small></label>
                                        <textarea class="form-control border-warning" id="txtIcNot" name="ic_not_mesaj" rows="2" placeholder="Varsa iç notunuzu buraya yazın..."></textarea>
                                    </div>
                                    <div class="mb-2">
                                        <div class="d-flex justify-content-between align-items-end mb-1">
                                            <label class="form-label mb-0">Müşteriye Yanıt</label>
                                            <?php if (!empty($cevapSablonlari)): ?>
                                            <div style="min-width:240px">
                                                <select id="cevapSablonSelect" class="form-select form-select-sm">
                                                    <option value="">Hazır cevap ekle…</option>
                                                    <?php foreach ($cevapSablonlari as $cs): ?>
                                                    <option value="<?= (int)$cs['Sablon_Id'] ?>"><?= htmlspecialchars($cs['Sablon_Ad']) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                        <textarea class="form-control" id="txtMesaj" name="mesaj" rows="4" placeholder="Yanıtınızı yazın..."></textarea>
                                    </div>
                                    <?php else: ?>
                                    <textarea class="form-control mb-2" id="txtMesaj" name="mesaj" rows="4" placeholder="Yanıtınızı yazın..."></textarea>
                                    <?php endif; ?>
                                    <div class="mb-2">
                                        <label class="form-label small text-muted mb-1">
                                            Dosya Ekle (maks. 10MB, jpg/png/pdf/doc/xls/txt)
                                        </label>
                                        <div id="dropZone">
                                            <div><i class="bi bi-cloud-arrow-up fs-4 text-secondary"></i></div>
                                            <div class="dz-metin">
                                                Dosyaları buraya <strong>sürükleyip bırakın</strong>,
                                                <strong>Ctrl+V</strong> ile yapıştırın veya
                                                <span class="text-primary text-decoration-underline">tıklayıp seçin</span>
                                            </div>
                                        </div>
                                        <input type="file" id="inputDosyalar" class="d-none" multiple
                                               accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx,.xls,.xlsx,.txt">
                                        <div id="pasted-files-container" class="mt-2 d-flex flex-wrap gap-2"></div>
                                    </div>
                                    <button type="submit" class="btn btn-primary btn-sm" id="btnYanitGonder">
                                        <i class="bi bi-send me-1"></i> Gönder
                                    </button>
                                </form>
                            </div>
                        </div>

                    </div>

                    <!-- Sağ: Bilgi Paneli -->
                    <div class="col-12 col-lg-4">

                        <!-- Ticket Bilgileri (Durum, Öncelik, Atama, CC tek kart) -->
                        <div class="card mb-3 panel-bilgi">
                            <div class="card-header"><i class="bi bi-info-circle me-1"></i> Ticket Bilgileri</div>
                            <div class="card-body">
                                <!-- Durum -->
                                <div class="mb-3">
                                    <label><i class="bi bi-flag me-1"></i> Durum</label>
                                    <div class="d-flex align-items-center gap-2 mt-1">
                                        <span id="durum-badge" class="badge fs-6"></span>
                                        <?php if ($pagePermissions['can_edit'] ?? false): ?>
                                        <select id="durum-select" class="form-select form-select-sm select2-basic" style="max-width:160px"></select>
                                        <button class="btn btn-outline-primary btn-sm" type="button" id="btnDurumKaydet" title="Durumu Güncelle">
                                            <i class="bi bi-check2"></i>
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <!-- Atanan Personel -->
                                <div class="mb-3">
                                    <label><i class="bi bi-person-check me-1"></i> Atanan Personel</label>
                                    <div class="d-flex align-items-center gap-2 mt-1">
                                        <span id="atanan-ad" class="badge text-bg-secondary fs-6">Atanmadı</span>
                                        <?php if ($isAdmin): ?>
                                        <select id="atama-select" class="form-select form-select-sm select2-basic" style="max-width:160px">
                                            <option value="">Atama Yok</option>
                                        </select>
                                        <button class="btn btn-outline-primary btn-sm" type="button" id="btnAtamaKaydet" title="Atamayı Güncelle">
                                            <i class="bi bi-check2"></i>
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <!-- Öncelik -->
                                <div class="mb-3">
                                    <label><i class="bi bi-exclamation-triangle me-1"></i> Öncelik</label>
                                    <div class="d-flex align-items-center gap-2 mt-1">
                                        <span id="oncelik-badge" class="badge fs-6"></span>
                                        <?php if ($pagePermissions['can_edit'] ?? false): ?>
                                        <select id="oncelik-select" class="form-select form-select-sm select2-basic" style="max-width:160px"></select>
                                        <button class="btn btn-outline-primary btn-sm" type="button" id="btnOncelikKaydet" title="Önceliği Güncelle">
                                            <i class="bi bi-check2"></i>
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <!-- Kategori -->
                                <div class="mb-3">
                                    <label><i class="bi bi-tag me-1"></i> Kategori</label>
                                    <div class="d-flex align-items-center gap-2 mt-1">
                                        <span id="kategori-badge" class="badge fs-6"></span>
                                        <?php if ($pagePermissions['can_edit'] ?? false): ?>
                                        <select id="kategori-select" class="form-select form-select-sm select2-basic" style="max-width:160px"></select>
                                        <button class="btn btn-outline-primary btn-sm" type="button" id="btnKategoriKaydet" title="Kategoriyi Güncelle">
                                            <i class="bi bi-check2"></i>
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <hr>
                                <div class="mb-2">
                                    <label>Oluşturan</label>
                                    <div class="deger" id="olusturan-ad">-</div>
                                </div>
                                <div class="mb-2">
                                    <label>Kaynak</label>
                                    <div class="deger mt-1" id="kaynak-badge">-</div>
                                </div>
                                <div class="mb-2">
                                    <label>Açılış Tarihi</label>
                                    <div class="deger" id="acilis-tarihi-panel">-</div>
                                </div>
                                <div class="mb-2">
                                    <label>Son Yanıt</label>
                                    <div class="deger" id="son-yanit-panel">-</div>
                                </div>
                                <div id="kapanis-satir" class="mb-2" style="display:none">
                                    <label>Kapanış Tarihi</label>
                                    <div class="deger" id="kapanis-tarihi-panel">-</div>
                                </div>
                                <hr>
                                <!-- Bilgi (CC) -->
                                <div class="mb-1">
                                    <label class="d-flex justify-content-between align-items-center mb-1">
                                        <span><i class="bi bi-people me-1"></i> Bilgi (CC)</span>
                                        <span class="badge text-bg-secondary" id="cc-sayisi">0</span>
                                    </label>
                                    <div id="cc-listesi" class="mb-2"></div>
                                    <?php if ($pagePermissions['can_edit'] ?? false): ?>
                                    <div class="input-group input-group-sm">
                                        <select id="cc-ekle-select" class="form-select form-select-sm select2-cc">
                                            <option value="">Kullanıcı seç...</option>
                                        </select>
                                        <button class="btn btn-outline-primary" type="button" id="btnCcEkle" title="CC Ekle">
                                            <i class="bi bi-plus"></i>
                                        </button>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Görevler -->
                        <div class="card mb-3 panel-bilgi" id="card-gorevler">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <span><i class="bi bi-check2-square me-1"></i> Görevler</span>
                                <span class="badge text-bg-secondary" id="gorev-sayisi">0</span>
                            </div>
                            <div class="card-body">
                                <!-- İlerleme -->
                                <div class="mb-2" id="gorev-progress-wrap" style="display:none">
                                    <div class="d-flex justify-content-between small text-muted mb-1">
                                        <span>İlerleme</span>
                                        <span id="gorev-progress-text">0/0</span>
                                    </div>
                                    <div class="progress" style="height:6px">
                                        <div class="progress-bar bg-success" id="gorev-progress-bar" style="width:0%"></div>
                                    </div>
                                </div>
                                <!-- Liste -->
                                <div id="gorev-listesi" class="mb-3"></div>
                                <!-- Ekle Formu -->
                                <?php if ($pagePermissions['can_edit'] ?? false): ?>
                                <div id="gorev-ekle-wrap">
                                    <input type="text" class="form-control form-control-sm mb-1" id="gorevBaslik" placeholder="Görev başlığı *" maxlength="500">
                                    <textarea class="form-control form-control-sm mb-1" id="gorevAciklama" rows="2" placeholder="Açıklama (opsiyonel)"></textarea>
                                    <select id="gorevAtanan" class="form-select form-select-sm mb-2 select2-gorev-atanan">
                                        <option value="">Atanan kişi seç...</option>
                                    </select>
                                    <button class="btn btn-primary btn-sm w-100" id="btnGorevEkle">
                                        <i class="bi bi-plus-circle me-1"></i> Görev Ekle
                                    </button>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>


                    </div>
                </div>
            </div>
        </div>
    </main>

    <?php include __DIR__ . '/../includes/footer.php'; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11" crossorigin="anonymous"></script>
<script src="/admin/assets/js/adminlte.min.js"></script>
<script src="/admin/assets/js/custom.js"></script>

<script>
const TICKET_ID = <?= $ticketId ?>;
const IS_ADMIN  = <?= $isAdmin ? 'true' : 'false' ?>;
const PAGE_PATH = window.location.pathname;
const CEVAP_SABLONLARI = <?= json_encode($cevapSablonlari, JSON_UNESCAPED_UNICODE) ?>;

let selectOptions = {};
let mevcutDurumId = 0;
let mevcutKategoriId = 0;
let mevcutOncelikId  = 0;

// Hazır cevap şablonu: {{...}} değişkenlerini aktif ticket verisiyle doldur
function sablonDegiskenDoldur(icerik) {
    const t = window.AKTIF_TICKET || {};
    const bugun = new Date().toLocaleDateString('tr-TR');
    const harita = {
        '{{ticket_no}}':   t.Tickets_no || '',
        '{{ticket_id}}':   t.Tickets_id || TICKET_ID,
        '{{konu}}':        t.Tickets_konu || '',
        '{{kategori}}':    t.Ticket_Kategoriler_ad || '',
        '{{oncelik}}':     t.Ticket_Oncelikler_ad || '',
        '{{yazan_ad}}':    t.olusturan_ad || '',
        '{{yazan_soyad}}': t.olusturan_soyad || '',
        '{{tarih}}':       bugun,
        '{{link}}':        location.origin + '/admin/ticket-detay?id=' + (t.Tickets_id || TICKET_ID),
    };
    return String(icerik || '').replace(/\{\{[a-z_]+\}\}/g, function (m) {
        return (m in harita) ? harita[m] : m;
    });
}

$(function () {
    const $sel = $('#cevapSablonSelect');
    if (!$sel.length) return;
    $sel.select2({ theme: 'bootstrap-5', width: '100%', placeholder: 'Hazır cevap ekle…', allowClear: true });
    $sel.on('select2:select', function (e) {
        const id = parseInt(e.params.data.id, 10);
        const s = CEVAP_SABLONLARI.find(x => parseInt(x.Sablon_Id, 10) === id);
        if (!s) return;
        const ta = document.getElementById('txtMesaj');
        const metin = sablonDegiskenDoldur(s.Sablon_Icerik);
        const mevcut = ta.value;
        ta.value = mevcut ? (mevcut.replace(/\s*$/, '') + '\n\n' + metin) : metin;
        ta.focus();
        $sel.val('').trigger('change');
    });
});

function escHtml(str) {
    if (!str) return '';
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// Düz metni güvenli HTML'e çevirir: önce kaçış, sonra URL'leri link yapar, satır sonlarını korur
function metinHtml(str) {
    return escHtml(str)
        .replace(/\b((?:https?:\/\/|www\.)[^\s<]+)/gi, function (url) {
            // Cümle sonundaki noktalama linke dahil edilmez
            const son = url.match(/[.,;:!?)\]]+$/);
            const kuyruk = son ? son[0] : '';
            if (kuyruk) url = url.slice(0, -kuyruk.length);
            const href = /^www\./i.test(url) ? 'https://' + url : url;
            return `<a href="${href}" target="_blank" rel="noopener noreferrer">${url}</a>${kuyruk}`;
        })
        .replace(/\n/g, '<br>');
}

function postAjax(data, callback, formData) {
    const fd = formData || new FormData();
    if (!formData) {
        Object.entries(data).forEach(([k, v]) => fd.append(k, v));
    }
    $.ajax({
        url: PAGE_PATH,
        type: 'POST',
        data: fd,
        processData: false,
        contentType: false,
        success: function (res) {
            if (typeof res === 'string') { try { res = JSON.parse(res); } catch(e) {} }
            callback(res);
        },
        error: function () { Swal.fire('Hata', 'Sunucu bağlantı hatası.', 'error'); }
    });
}

function boyutFormat(bytes) {
    if (!bytes) return '';
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1024*1024) return (bytes/1024).toFixed(1) + ' KB';
    return (bytes/(1024*1024)).toFixed(1) + ' MB';
}

function kaynakBadge(ad, tip) {
    // Kaynak yoksa (Tickets_kaynak_id NULL) panelden açılmıştır
    if (!ad) {
        return '<span class="badge text-bg-secondary"><i class="bi bi-window me-1"></i>Panel</span>';
    }
    const t = (tip || '').toLowerCase();
    const ikon = t === 'email' ? 'bi-envelope' :
                 t === 'api'   ? 'bi-hdd-network' :
                 t === 'web'   ? 'bi-globe' :
                 'bi-tag';
    return `<span class="badge text-bg-info"><i class="bi ${ikon} me-1"></i>${escHtml(ad)}</span>`;
}

function eklerHtml(ekler) {
    if (!ekler || !ekler.length) return '';
    let html = '<div class="mt-2 d-flex flex-wrap gap-2">';
    ekler.forEach(function (ek) {
        const isim = escHtml(ek.Ticket_Ekler_dosya_adi);
        const yol  = '/' + ek.Ticket_Ekler_dosya_yolu;
        const boy  = boyutFormat(ek.Ticket_Ekler_dosya_boyutu);
        const mime = ek.Ticket_Ekler_mime_turu || '';
        const ikon = mime.startsWith('image') ? 'bi-image' :
                     mime.includes('pdf')     ? 'bi-file-pdf' :
                     mime.includes('word')    ? 'bi-file-word' :
                     mime.includes('excel') || mime.includes('spreadsheet') ? 'bi-file-excel' :
                     'bi-paperclip';
        html += `<a href="${yol}" target="_blank" class="btn btn-outline-secondary btn-sm ek-dosya">
                    <i class="bi ${ikon} me-1"></i>${isim}${boy ? ' <small class="text-muted">('+boy+')</small>' : ''}
                 </a>`;
    });
    html += '</div>';
    return html;
}

// -------------------------------------------------------
// Sayfa yüklenince
// -------------------------------------------------------
$(document).ready(function () {
    loadSelectOptions();
    loadTicket();

    // Select2
    $('.select2-basic').select2({ theme: 'bootstrap-5', width: '100%' });

    // -------------------------------------------------------
    // Dosya ekleme: sürükle-bırak + Ctrl+V + tıkla-seç
    // -------------------------------------------------------
    let secilenDosyalar = [];
    const IZINLI_UZANTILAR = ['jpg','jpeg','png','gif','pdf','doc','docx','xls','xlsx','txt'];
    const MAKS_BOYUT = 10 * 1024 * 1024;

    function dosyaIkonu(ad) {
        const uzanti = (ad.split('.').pop() || '').toLowerCase();
        if (uzanti === 'pdf') return 'bi-file-earmark-pdf text-danger';
        if (['doc','docx'].includes(uzanti)) return 'bi-file-earmark-word text-primary';
        if (['xls','xlsx'].includes(uzanti)) return 'bi-file-earmark-excel text-success';
        if (uzanti === 'txt') return 'bi-file-earmark-text';
        return 'bi-file-earmark';
    }

    function onizlemeCiz() {
        const kap = $('#pasted-files-container').empty();
        secilenDosyalar.forEach(function (f, i) {
            if (f === null) return;
            const gorsel = f.type.startsWith('image/');
            const ust = gorsel
                ? `<img src="${URL.createObjectURL(f)}" alt="">`
                : `<div class="dosya-ikon"><i class="bi ${dosyaIkonu(f.name)}"></i></div>`;
            kap.append(`
                <div class="ek-onizleme-item" data-index="${i}" title="${escHtml(f.name)} (${boyutFormat(f.size)})">
                    ${ust}
                    <div class="dosya-ad">${escHtml(f.name)}</div>
                    <button type="button" class="btn btn-danger btn-ek-kaldir" title="Kaldır">&times;</button>
                </div>`);
        });
    }

    function dosyalariEkle(dosyalar) {
        let eklenen = 0;
        const hatalar = [];
        Array.from(dosyalar || []).forEach(function (f) {
            const uzanti = (f.name.split('.').pop() || '').toLowerCase();
            if (!IZINLI_UZANTILAR.includes(uzanti)) { hatalar.push(f.name + ' – desteklenmeyen dosya türü'); return; }
            if (f.size > MAKS_BOYUT) { hatalar.push(f.name + ' – 10MB sınırını aşıyor'); return; }
            secilenDosyalar.push(f);
            eklenen++;
        });
        if (eklenen) {
            onizlemeCiz();
            Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: eklenen + ' dosya eklendi', showConfirmButton: false, timer: 1500 });
        }
        if (hatalar.length) {
            Swal.fire('Eklenemeyen dosyalar', hatalar.join('<br>'), 'warning');
        }
    }

    // Tıkla-seç
    $('#dropZone').on('click', function () { $('#inputDosyalar').trigger('click'); });
    $('#inputDosyalar').on('change', function () {
        dosyalariEkle(this.files);
        this.value = '';
    });

    // Sürükle-bırak
    $('#dropZone').on('dragover dragenter', function (e) {
        e.preventDefault(); e.stopPropagation();
        $(this).addClass('dz-aktif');
    }).on('dragleave dragend drop', function (e) {
        e.preventDefault(); e.stopPropagation();
        $(this).removeClass('dz-aktif');
    }).on('drop', function (e) {
        const dt = e.originalEvent.dataTransfer;
        if (dt && dt.files && dt.files.length) dosyalariEkle(dt.files);
    });

    // Tarayıcının sayfa dışına bırakılan dosyayı açmasını engelle
    $(document).on('dragover drop', function (e) {
        if (!$(e.target).closest('#dropZone').length) e.preventDefault();
    });

    // Panodan yapıştırma (Ctrl+V) — görsel ve dosya
    $(document).on('paste', function (e) {
        const items = (e.clipboardData || e.originalEvent.clipboardData || {}).items;
        if (!items) return;
        const yeniler = [];
        for (let i = 0; i < items.length; i++) {
            const item = items[i];
            if (item.kind !== 'file') continue;
            const blob = item.getAsFile();
            if (!blob) continue;
            if (blob.name && blob.name !== 'image.png') {
                yeniler.push(blob);
            } else {
                const uzanti = (item.type.split('/')[1] || 'png').replace('jpeg', 'jpg');
                yeniler.push(new File([blob], 'yapistirilan_' + Date.now() + '_' + i + '.' + uzanti, { type: item.type }));
            }
        }
        if (yeniler.length) {
            e.preventDefault();
            dosyalariEkle(yeniler);
        }
    });

    $(document).on('click', '.btn-ek-kaldir', function () {
        const index = $(this).closest('.ek-onizleme-item').data('index');
        secilenDosyalar[index] = null;
        onizlemeCiz();
    });

    // Yanıt formu
    $('#formYanit').on('submit', function (e) {
        e.preventDefault();
        const mesaj = $('#txtMesaj').val().trim();
        const icNotMesaj = $('#txtIcNot').length ? $('#txtIcNot').val().trim() : '';
        if (!mesaj && !icNotMesaj) { Swal.fire('Uyarı', 'Mesaj veya İç Not boş olamaz.', 'warning'); return; }

        const fd = new FormData(this);
        secilenDosyalar.forEach(function (f) {
            if (f !== null) fd.append('dosyalar[]', f);
        });
        fd.append('action', 'mesaj_gonder');
        fd.append('ticket_id', TICKET_ID);
        
        // Durum ve Öncelik bilgilerini ekle
        if ($('#durum-select').length) fd.append('durum_id', $('#durum-select').val());
        if ($('#oncelik-select').length) fd.append('oncelik_id', $('#oncelik-select').val());

        $('#btnYanitGonder').prop('disabled', true).html('<i class="bi bi-hourglass-split me-1"></i> Gönderiliyor...');
        postAjax({}, function (res) {
            $('#btnYanitGonder').prop('disabled', false).html('<i class="bi bi-send me-1"></i> Gönder');
            if (res.success) {
                $('#txtMesaj').val('');
                if ($('#txtIcNot').length) $('#txtIcNot').val('');
                $('#inputDosyalar').val('');
                $('#pasted-files-container').empty();
                secilenDosyalar = [];
                loadTicket(); // Ticket bilgilerini (başlık, durum vb.) yenile
                loadMesajlar();
            } else {
                Swal.fire('Hata', res.message, 'error');
            }
        }, fd);
    });


    // Atama kaydet
    $('#btnAtamaKaydet').on('click', function () {
        const kulId = $('#atama-select').val();
        postAjax({ action: 'atama_guncelle', ticket_id: TICKET_ID, kullanici_id: kulId }, function (res) {
            if (res.success) {
                Swal.fire({ icon:'success', title:'Atama güncellendi', timer:1500, showConfirmButton:false });
                loadTicket();
            } else { Swal.fire('Hata', res.message, 'error'); }
        });
    });

    // Durum Güncelle
    $('#btnDurumKaydet').on('click', function () {
        const durumId = parseInt($('#durum-select').val() || 0);
        if (!durumId) { Swal.fire('Uyarı', 'Lütfen bir durum seçin.', 'warning'); return; }
        if (durumId === mevcutDurumId) { Swal.fire({ toast:true, position:'top-end', icon:'info', title:'Durum zaten aynı', showConfirmButton:false, timer:1500 }); return; }

        const durum = (selectOptions.durumlar || []).find(d => d.id == durumId);
        Swal.fire({
            title: 'Durum güncellensin mi?',
            text: durum ? durum.ad + ' olarak işaretlenecek.' : '',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Evet',
            cancelButtonText: 'İptal'
        }).then(function (r) {
            if (!r.isConfirmed) { $('#durum-select').val(mevcutDurumId).trigger('change'); return; }
            postAjax({ action: 'durum_guncelle', ticket_id: TICKET_ID, durum_id: durumId }, function (res) {
                if (res.success) {
                    Swal.fire({ toast:true, position:'top-end', icon:'success', title:'Durum güncellendi', showConfirmButton:false, timer:1500 });
                    loadTicket();
                } else { Swal.fire('Hata', res.message, 'error'); }
            });
        });
    });

    // Öncelik Güncelle
    $('#btnOncelikKaydet').on('click', function () {
        const oncelikId = parseInt($('#oncelik-select').val() || 0);
        if (!oncelikId) { Swal.fire('Uyarı', 'Lütfen bir öncelik seçin.', 'warning'); return; }
        if (oncelikId === mevcutOncelikId) { Swal.fire({ toast:true, position:'top-end', icon:'info', title:'Öncelik zaten aynı', showConfirmButton:false, timer:1500 }); return; }

        const oncelik = (selectOptions.oncelikler || []).find(o => o.id == oncelikId);
        Swal.fire({
            title: 'Öncelik güncellensin mi?',
            text: oncelik ? oncelik.ad + ' olarak değiştirilecek.' : '',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Evet',
            cancelButtonText: 'İptal'
        }).then(function (r) {
            if (!r.isConfirmed) { $('#oncelik-select').val(mevcutOncelikId).trigger('change'); return; }
            postAjax({ action: 'oncelik_guncelle', ticket_id: TICKET_ID, oncelik_id: oncelikId }, function (res) {
                if (res.success) {
                    Swal.fire({ toast:true, position:'top-end', icon:'success', title:'Öncelik güncellendi', showConfirmButton:false, timer:1500 });
                    loadTicket();
                } else { Swal.fire('Hata', res.message, 'error'); }
            });
        });
    });

    // Kategori Güncelle
    $('#btnKategoriKaydet').on('click', function () {
        const kategoriId = parseInt($('#kategori-select').val() || 0);
        if (!kategoriId) { Swal.fire('Uyarı', 'Lütfen bir kategori seçin.', 'warning'); return; }
        if (kategoriId === mevcutKategoriId) { Swal.fire({ toast:true, position:'top-end', icon:'info', title:'Kategori zaten aynı', showConfirmButton:false, timer:1500 }); return; }

        const kategori = (selectOptions.kategoriler || []).find(k => k.id == kategoriId);
        Swal.fire({
            title: 'Kategori güncellensin mi?',
            text: kategori ? kategori.ad + ' olarak değiştirilecek.' : '',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Evet',
            cancelButtonText: 'İptal'
        }).then(function (r) {
            if (!r.isConfirmed) { $('#kategori-select').val(mevcutKategoriId).trigger('change'); return; }
            postAjax({ action: 'kategori_guncelle', ticket_id: TICKET_ID, kategori_id: kategoriId }, function (res) {
                if (res.success) {
                    Swal.fire({ toast:true, position:'top-end', icon:'success', title:'Kategori güncellendi', showConfirmButton:false, timer:1500 });
                    loadTicket();
                } else { Swal.fire('Hata', res.message, 'error'); }
            });
        });
    });

    // Hızlı işlemler (Çözüldü / Kapalı)
    $('#btnCoz').on('click', function () { hizliDurumGuncelle('Çözüldü'); });
    $('#btnKapat').on('click', function () { hizliDurumGuncelle('Kapalı'); });

    // Mesaj Düzenleme
    $(document).on('click', '.msj-duzenle-btn', function() {
        const btn = $(this);
        const msjId = btn.data('id');
        const rawMesaj = window.mesajCache ? window.mesajCache[msjId] : '';
        
        Swal.fire({
            title: 'Mesajı Düzenle',
            input: 'textarea',
            inputValue: rawMesaj,
            showCancelButton: true,
            confirmButtonText: '<i class="bi bi-check2 me-1"></i> Kaydet',
            cancelButtonText: 'İptal',
            inputValidator: (value) => {
                if (!value.trim()) {
                    return 'Mesaj boş olamaz!';
                }
            }
        }).then((result) => {
            if (result.isConfirmed) {
                postAjax({ action: 'mesaj_duzenle', mesaj_id: msjId, mesaj: result.value }, function(res) {
                    if (res.success) {
                        loadMesajlar();
                    } else {
                        Swal.fire('Hata', res.message, 'error');
                    }
                });
            }
        });
    });
});

function hizliDurumGuncelle(durumAdi) {
    if (!selectOptions.durumlar) return;
    const durum = selectOptions.durumlar.find(d => d.ad === durumAdi);
    if (!durum) { Swal.fire('Uyarı', durumAdi + ' durumu bulunamadı.', 'warning'); return; }

    Swal.fire({
        title: durumAdi + ' olarak işaretle?',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Evet',
        cancelButtonText: 'İptal'
    }).then(function (r) {
        if (r.isConfirmed) {
            postAjax({ action: 'durum_guncelle', ticket_id: TICKET_ID, durum_id: durum.id }, function (res) {
                if (res.success) {
                    Swal.fire({ icon:'success', title:'Güncellendi', timer:1500, showConfirmButton:false });
                    loadTicket();
                } else { Swal.fire('Hata', res.message, 'error'); }
            });
        }
    });
}

// -------------------------------------------------------
// Select seçenekleri
// -------------------------------------------------------
function loadSelectOptions() {
    postAjax({ action: 'select_options', ticket_id: TICKET_ID }, function (res) {
        if (!res.success) return;
        selectOptions = res.data;

        res.data.durumlar.forEach(function (d) {
            $('#durum-select').append(`<option value="${d.id}">${escHtml(d.ad)}</option>`);
        });
        res.data.oncelikler.forEach(function (d) {
            $('#oncelik-select').append(`<option value="${d.id}">${escHtml(d.ad)}</option>`);
        });
        (res.data.kategoriler || []).forEach(function (d) {
            $('#kategori-select').append(`<option value="${d.id}">${escHtml(d.ad)}</option>`);
        });
        if (IS_ADMIN) {
            res.data.personeller.forEach(function (d) {
                $('#atama-select').append(`<option value="${d.id}">${escHtml(d.ad)}</option>`);
            });
        }
        $('.select2-basic').select2({ theme: 'bootstrap-5', width: '100%' });

        // Ticket bilgisi seçenekler yüklenmeden geldiyse seçili değerleri tazele
        const t = window.AKTIF_TICKET;
        if (t) {
            $('#durum-select').val(t.Ticket_Durumlar_id).trigger('change');
            $('#oncelik-select').val(t.Ticket_Oncelikler_id).trigger('change');
            $('#kategori-select').val(t.Ticket_Kategoriler_id).trigger('change');
            if (IS_ADMIN && t.Tickets_atanan_kullanici_id) {
                $('#atama-select').val(t.Tickets_atanan_kullanici_id).trigger('change');
            }
        }
    });
}

// -------------------------------------------------------
// Ticket bilgileri
// -------------------------------------------------------
function loadTicket() {
    postAjax({ action: 'ticket_getir', ticket_id: TICKET_ID }, function (res) {
        if (!res.success) {
            Swal.fire('Hata', res.message, 'error');
            return;
        }
        const t = res.data;
        window.AKTIF_TICKET = t;

        $('#ticket-konu-baslik').text(t.Tickets_konu);
        $('#ticket-no-baslik').text(t.Tickets_no);
        document.title = t.Tickets_no + ' - ' + t.Tickets_konu;

        $('#card-aciklama').hide();

        // Durum badge
        $('#durum-badge').text(t.Ticket_Durumlar_ad)
            .attr('style', 'background-color:' + (t.Ticket_Durumlar_renk || '#6c757d'));
        mevcutDurumId = parseInt(t.Ticket_Durumlar_id);
        $('#durum-select').val(t.Ticket_Durumlar_id).trigger('change');

        // Öncelik badge
        $('#oncelik-badge').html(`<i class="${t.Ticket_Oncelikler_ikon || 'bi bi-dash-circle'} me-1"></i>${escHtml(t.Ticket_Oncelikler_ad)}`)
            .attr('style', 'background-color:' + (t.Ticket_Oncelikler_renk || '#6c757d'));
        mevcutOncelikId = parseInt(t.Ticket_Oncelikler_id);
        $('#oncelik-select').val(t.Ticket_Oncelikler_id).trigger('change');

        // Kategori
        $('#kategori-badge').html(`<i class="bi bi-tag me-1"></i>${escHtml(t.Ticket_Kategoriler_ad)}`)
            .attr('style', 'background-color:' + (t.Ticket_Kategoriler_renk || '#6c757d'));
        mevcutKategoriId = parseInt(t.Ticket_Kategoriler_id);
        $('#kategori-select').val(t.Ticket_Kategoriler_id).trigger('change');

        // Bilgi
        const olAd = ((t.olusturan_ad || '') + ' ' + (t.olusturan_soyad || '')).trim() || '-';
        if (t.Tickets_olusturan_kullanici_id) {
            $('#olusturan-ad').html(`<a href="/admin/personel-form?id=${t.Tickets_olusturan_kullanici_id}"><i class="bi bi-person-circle me-1"></i>${escHtml(olAd)}</a>`);
        } else {
            $('#olusturan-ad').text(olAd);
        }

        // Kaynak
        $('#kaynak-badge').html(kaynakBadge(t.Ticket_Kaynak_ad, t.Ticket_Kaynak_tip));
        $('#acilis-tarihi-panel').text(t.acilis_tarihi || '-');
        $('#son-yanit-panel').text(t.son_yanit_tarihi || '-');

        if (t.kapanis_tarihi) {
            $('#kapanis-satir').show();
            $('#kapanis-tarihi-panel').text(t.kapanis_tarihi);
        } else {
            $('#kapanis-satir').hide();
        }

        // Atanan
        if (IS_ADMIN) {
            const atAd = ((t.atanan_ad || '') + ' ' + (t.atanan_soyad || '')).trim();
            $('#atanan-ad').text(atAd || 'Atanmadı');
            if (t.Tickets_atanan_kullanici_id) {
                $('#atama-select').val(t.Tickets_atanan_kullanici_id).trigger('change');
            }
        }

        // Kapalıysa yanıt formunu gizle
        if (t.Ticket_Durumlar_kapatma_durumu) {
            $('#card-yanit').hide();
        } else {
            $('#card-yanit').show();
        }

        loadMesajlar();
        loadCC(t.cc_listesi || []);
        loadGorevler();
    });
}

// -------------------------------------------------------
// Mesajlar
// -------------------------------------------------------
function loadMesajlar() {
    postAjax({ action: 'mesajlar_getir', ticket_id: TICKET_ID }, function (res) {
        if (!res.success) return;

        const container = $('#mesajAlani');
        container.empty();
        $('#mesaj-sayisi-badge').text(res.data.length);

        if (!res.data.length) {
            container.html('<div class="text-center text-muted py-3"><i class="bi bi-chat-dots me-1"></i> Henüz mesaj yok.</div>');
            return;
        }

        window.mesajCache = {};

        res.data.forEach(function (m) {
            window.mesajCache[m.Ticket_Mesajlar_id] = m.Ticket_Mesajlar_mesaj;
            const benimMi = (m.Ticket_Mesajlar_yazan_kul_id == <?= $user['kullanici_id'] ?>);
            const icNot   = m.Ticket_Mesajlar_ic_not == 1;
            const sinif   = icNot ? 'mesaj-ic-not' : (benimMi ? 'mesaj-giden' : 'mesaj-gelen');
            const adSoyad = ((m.kullanici_ad || '') + ' ' + (m.kullanici_soyad || '')).trim() || 'Sistem';
            const duzenlendiText = m.duzenlendi_mi == 1 ? '<span class="text-muted ms-2 fst-italic" style="font-size: 0.7rem;">(düzenlendi)</span>' : '';

            let html = `<div class="mesaj-balonu ${sinif}" id="mesaj-kutu-${m.Ticket_Mesajlar_id}">`;
            
            if (benimMi) {
                html += `<div class="position-absolute top-0 end-0 mt-2 me-2">
                             <button class="btn btn-sm btn-link text-muted p-0 msj-duzenle-btn" data-id="${m.Ticket_Mesajlar_id}" title="Düzenle">
                                 <i class="bi bi-pencil-square"></i>
                             </button>
                         </div>`;
            }

            if (icNot) {
                html += `<span class="badge text-bg-warning mesaj-ic-not-badge mb-1"><i class="bi bi-sticky me-1"></i> İç Not</span>`;
            }
            
            html += `<div class="mesaj-metni pt-2 pb-1">${metinHtml(m.Ticket_Mesajlar_mesaj)}</div>`;
            html += eklerHtml(m.ekler);
            html += `<div class="mesaj-meta mt-1"><i class="bi bi-person me-1"></i>${escHtml(adSoyad)} &nbsp;|&nbsp; <i class="bi bi-clock me-1"></i>${m.tarih} ${duzenlendiText}</div>`;
            html += `</div>`;
            container.append(html);
        });

        // Aşağı kaydır
        container.scrollTop(container[0].scrollHeight);
    });
}

// -------------------------------------------------------
// CC (Bilgi) Yönetimi
// -------------------------------------------------------
function loadCC(ccList) {
    const container = $('#cc-listesi');
    container.empty();
    $('#cc-sayisi').text(ccList.length);

    if (!ccList.length) {
        container.html('<div class="text-muted small">Henüz CC eklenmedi.</div>');
        return;
    }

    ccList.forEach(function(cc) {
        const adSoyad = ((cc.kullanici_ad || '') + ' ' + (cc.kullanici_soyad || '')).trim();
        const email = cc.kullanici_email || '';
        container.append(`
            <div class="d-flex justify-content-between align-items-center mb-1" id="cc-row-${cc.Ticket_CC_id}">
                <div>
                    <i class="bi bi-person me-1 text-muted"></i>
                    <span class="small fw-semibold">${escHtml(adSoyad)}</span>
                    <br><small class="text-muted ms-3">${escHtml(email)}</small>
                </div>
                <button class="btn btn-outline-danger btn-sm p-0 px-1" onclick="ccSil(${cc.Ticket_CC_id})" title="Çıkar">
                    <i class="bi bi-x"></i>
                </button>
            </div>
        `);
    });
}

function ccEkle() {
    const kullaniciId = $('#cc-ekle-select').val();
    if (!kullaniciId) {
        Swal.fire('Uyarı', 'Lütfen bir kullanıcı seçin.', 'warning');
        return;
    }

    postAjax({ action: 'cc_ekle', ticket_id: TICKET_ID, kullanici_id: kullaniciId }, function(res) {
        if (res.success) {
            Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: res.message, showConfirmButton: false, timer: 1500 });
            $('#cc-ekle-select').val('').trigger('change');
            loadTicket();
        } else {
            Swal.fire('Hata', res.message, 'error');
        }
    });
}

function ccSil(ccId) {
    Swal.fire({
        title: 'CC\'den çıkarmak istediğinize emin misiniz?',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Evet, Çıkar',
        cancelButtonText: 'İptal',
        confirmButtonColor: '#dc3545'
    }).then(function(result) {
        if (result.isConfirmed) {
            postAjax({ action: 'cc_sil', cc_id: ccId, ticket_id: TICKET_ID }, function(res) {
                if (res.success) {
                    Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: res.message, showConfirmButton: false, timer: 1500 });
                    loadTicket();
                } else {
                    Swal.fire('Hata', res.message, 'error');
                }
            });
        }
    });
}

// -------------------------------------------------------
// Görevler
// -------------------------------------------------------
function loadGorevler() {
    postAjax({ action: 'gorev_listele', ticket_id: TICKET_ID }, function(res) {
        if (!res.success) return;
        const gorevler = res.data;
        const container = $('#gorev-listesi');
        container.empty();
        $('#gorev-sayisi').text(gorevler.length);

        if (!gorevler.length) {
            container.html('<div class="text-muted small">Henüz görev eklenmedi.</div>');
            $('#gorev-progress-wrap').hide();
            return;
        }

        // İlerleme hesapla
        const toplam    = gorevler.length;
        const tamamladi = gorevler.filter(g => g.durum_kapatma == 1).length;
        const yuzde     = toplam > 0 ? Math.round((tamamladi / toplam) * 100) : 0;
        $('#gorev-progress-wrap').show();
        $('#gorev-progress-text').text(tamamladi + '/' + toplam);
        $('#gorev-progress-bar').css('width', yuzde + '%');

        gorevler.forEach(function(g) {
            const tamamlandi   = g.durum_kapatma == 1;
            const atananAd     = g.atanan_ad ? ((g.atanan_ad || '') + ' ' + (g.atanan_soyad || '')).trim() : null;
            const renk         = g.durum_renk || '#6c757d';
            const durumSecenek = (selectOptions.gorevDurumlari || []).map(d =>
                `<option value="${d.id}" ${d.id == g.Ticket_Gorevler_durum_id ? 'selected' : ''}>${escHtml(d.ad)}</option>`
            ).join('');

            container.append(`
                <div class="border rounded p-2 mb-2 gorev-satir" id="gorev-row-${g.Ticket_Gorevler_id}">
                    <div class="d-flex align-items-start gap-2">
                        <div class="flex-grow-1">
                            <div class="fw-semibold small ${tamamlandi ? 'text-decoration-line-through text-muted' : ''}">${escHtml(g.Ticket_Gorevler_baslik)}</div>
                            ${g.Ticket_Gorevler_aciklama ? `<div class="text-muted" style="font-size:0.75rem">${escHtml(g.Ticket_Gorevler_aciklama)}</div>` : ''}
                            ${atananAd ? `<div class="text-muted mt-1" style="font-size:0.72rem"><i class="bi bi-person me-1"></i>${escHtml(atananAd)}</div>` : ''}
                        </div>
                        <div class="d-flex flex-column align-items-end gap-1">
                            <span class="badge" style="background-color:${renk};font-size:0.7rem">${escHtml(g.durum_ad)}</span>
                        </div>
                    </div>
                    <div class="d-flex gap-1 mt-2">
                        <select class="form-select form-select-sm gorev-durum-select flex-grow-1" data-id="${g.Ticket_Gorevler_id}" style="font-size:0.75rem">${durumSecenek}</select>
                        <button class="btn btn-outline-primary btn-sm gorev-durum-kaydet" data-id="${g.Ticket_Gorevler_id}" title="Durumu Güncelle"><i class="bi bi-check2"></i></button>
                        <button class="btn btn-outline-danger btn-sm gorev-sil-btn" data-id="${g.Ticket_Gorevler_id}" title="Görevi Sil"><i class="bi bi-trash"></i></button>
                    </div>
                </div>
            `);
        });
    });
}

$(document).ready(function() {
    // CC ekle butonu
    $('#btnCcEkle').on('click', ccEkle);

    // CC Select2 - personel listesi yüklendikten sonra başlat
    function initCcSelect() {
        if (selectOptions.personeller) {
            const sel = $('#cc-ekle-select');
            sel.find('option:not(:first)').remove();
            selectOptions.personeller.forEach(function(p) {
                sel.append(`<option value="${p.id}">${escHtml(p.ad)}</option>`);
            });
            sel.select2({ theme: 'bootstrap-5', width: '100%', placeholder: 'Kullanıcı ara...', allowClear: true });
        } else {
            setTimeout(initCcSelect, 500);
        }
    }
    initCcSelect();

    // Görev Atanan Select2
    function initGorevAtananSelect() {
        if (selectOptions.personeller) {
            const sel = $('#gorevAtanan');
            selectOptions.personeller.forEach(function(p) {
                sel.append(`<option value="${p.id}">${escHtml(p.ad)}</option>`);
            });
            sel.select2({ theme: 'bootstrap-5', width: '100%', placeholder: 'Atanan kişi seç...', allowClear: true });
        } else {
            setTimeout(initGorevAtananSelect, 500);
        }
    }
    initGorevAtananSelect();

    // Görev Ekle
    $('#btnGorevEkle').on('click', function() {
        const baslik   = $('#gorevBaslik').val().trim();
        const aciklama = $('#gorevAciklama').val().trim();
        const atananId = $('#gorevAtanan').val();

        if (!baslik) {
            Swal.fire('Uyarı', 'Görev başlığı boş olamaz.', 'warning');
            return;
        }

        $(this).prop('disabled', true);
        postAjax({ action: 'gorev_ekle', ticket_id: TICKET_ID, baslik: baslik, aciklama: aciklama, atanan_id: atananId || '' }, function(res) {
            $('#btnGorevEkle').prop('disabled', false);
            if (res.success) {
                $('#gorevBaslik').val('');
                $('#gorevAciklama').val('');
                $('#gorevAtanan').val('').trigger('change');
                Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: 'Görev eklendi', showConfirmButton: false, timer: 1500 });
                loadGorevler();
            } else {
                Swal.fire('Hata', res.message, 'error');
            }
        });
    });

    // Görev Durum Güncelle
    $(document).on('click', '.gorev-durum-kaydet', function() {
        const gorevId = $(this).data('id');
        const durumId = $(`#gorev-row-${gorevId} .gorev-durum-select`).val();
        postAjax({ action: 'gorev_durum_guncelle', ticket_id: TICKET_ID, gorev_id: gorevId, durum_id: durumId }, function(res) {
            if (res.success) {
                Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: 'Durum güncellendi', showConfirmButton: false, timer: 1200 });
                loadGorevler();
            } else {
                Swal.fire('Hata', res.message, 'error');
            }
        });
    });

    // Görev Sil
    $(document).on('click', '.gorev-sil-btn', function() {
        const gorevId = $(this).data('id');
        Swal.fire({
            title: 'Görevi silmek istediğinize emin misiniz?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Evet, Sil',
            cancelButtonText: 'İptal',
            confirmButtonColor: '#dc3545'
        }).then(function(result) {
            if (result.isConfirmed) {
                postAjax({ action: 'gorev_sil', ticket_id: TICKET_ID, gorev_id: gorevId }, function(res) {
                    if (res.success) {
                        Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: 'Görev silindi', showConfirmButton: false, timer: 1200 });
                        loadGorevler();
                    } else {
                        Swal.fire('Hata', res.message, 'error');
                    }
                });
            }
        });
    });
});
</script>
</body>
</html>
