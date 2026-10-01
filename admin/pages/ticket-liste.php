<?php
/**
 * Admin Panel - Ticket Listesi
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
requireAuth();

$user = Auth::user();
$db = Database::getInstance();

// Sayfa yetki kontrolü
$currentPageFile = basename($_SERVER['PHP_SELF']);
$pagePermissions = PageAuth::checkPagePermissions(
    $user['kullanici_id'],
    $user['departman_id'],
    $currentPageFile
);

if (!$pagePermissions['has_access']) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => $pagePermissions['error'] ?? 'Bu sayfaya erişim yetkiniz bulunmamaktadır.']);
        exit;
    }
    PageAuth::accessDenied($pagePermissions['error'] ?? 'Bu sayfaya erişim yetkiniz bulunmamaktadır.');
}

// Sayfa bilgileri
$pageInfo = $db->fetchOne("
    SELECT s.sayfalar_sayfa_adi, s.sayfalar_aciklama, m.menuler_menu_adi AS menu_adi
    FROM Menu_Sayfalar s
    LEFT JOIN Menuler m ON s.sayfalar_menu_id = m.menuler_id
    WHERE s.sayfalar_sayfa_url LIKE ? AND s.sayfalar_durum = 1
", ['%' . $currentPageFile]);

$pageTitle   = $pageInfo['sayfalar_sayfa_adi'] ?? 'Ticket Listesi';
$pageDesc    = $pageInfo['sayfalar_aciklama']  ?? '';
$menuAdi     = $pageInfo['menu_adi']           ?? null;

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle    = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';

$isAdmin = ($user['departman_id'] == 1);

// Kaynak / atanan yetkisine göre ticket görünürlük filtresi
$ticketYetkiWhere = PageAuth::getTicketSqlWhere(
    $user['kullanici_id'],
    $pagePermissions,
    't.Tickets_kaynak_id',
    't.Tickets_atanan_kullanici_id',
    't.Tickets_olusturan_kullanici_id'
);

// =====================================================================
// AJAX İşlemleri
// =====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');

    try {
        $action = $_POST['action'] ?? '';

        // -----------------------------------------------------------------
        // stats
        // -----------------------------------------------------------------
        if ($action === 'stats') {
            $baseWhere = "t.Durum = 1";
            $params    = [];

            if (!$isAdmin) {
                $baseWhere .= $ticketYetkiWhere;
            }

            $toplam  = $db->fetchOne("SELECT COUNT(*) AS sayi FROM Tickets t WHERE $baseWhere", $params)['sayi'] ?? 0;
            $acik    = $db->fetchOne("SELECT COUNT(*) AS sayi FROM Tickets t INNER JOIN Ticket_Durumlar d ON t.Tickets_durum_id = d.Ticket_Durumlar_id WHERE $baseWhere AND d.Ticket_Durumlar_kapatma_durumu = 0", $params)['sayi'] ?? 0;
            $kapali  = $db->fetchOne("SELECT COUNT(*) AS sayi FROM Tickets t INNER JOIN Ticket_Durumlar d ON t.Tickets_durum_id = d.Ticket_Durumlar_id WHERE $baseWhere AND d.Ticket_Durumlar_kapatma_durumu = 1", $params)['sayi'] ?? 0;
            $acil    = $db->fetchOne("SELECT COUNT(*) AS sayi FROM Tickets t INNER JOIN Ticket_Oncelikler o ON t.Tickets_oncelik_id = o.Ticket_Oncelikler_id WHERE $baseWhere AND o.Ticket_Oncelikler_ad = N'Acil'", $params)['sayi'] ?? 0;
            $cevap   = $db->fetchOne("SELECT COUNT(*) AS sayi FROM Tickets t WHERE $baseWhere AND t.Tickets_musteri_cevap_var = 1", $params)['sayi'] ?? 0;

            echo json_encode(['success' => true, 'data' => compact('toplam', 'acik', 'kapali', 'acil', 'cevap')]);
            exit;
        }

        // -----------------------------------------------------------------
        // liste
        // -----------------------------------------------------------------
        if ($action === 'liste') {
            $whereConditions = ["t.Durum = 1"];
            $params = [];

            // Yetki filtresi
            if (!$isAdmin && $ticketYetkiWhere !== '') {
                // getTicketSqlWhere " AND (...)" formatinda doner, kosul listesine ciplak eklenir
                $whereConditions[] = '(' . substr(trim($ticketYetkiWhere), 4) . ')';
            }

            // Filtreler
            $durumId    = $_POST['durum_id']    ?? 'acik'; // Varsayılan olarak sadece açık olanlar gelsin
            $oncelikId  = $_POST['oncelik_id']  ?? '';
            $kategoriId = $_POST['kategori_id'] ?? '';
            $atananId   = $_POST['atanan_id']   ?? '';
            $search     = trim($_POST['search'] ?? '');
            $tarihBas   = $_POST['tarih_bas']   ?? '';
            $tarihBit   = $_POST['tarih_bit']   ?? '';

            if ($durumId === 'acik') {
                $whereConditions[] = "d.Ticket_Durumlar_kapatma_durumu = 0";
            } elseif ($durumId === 'cevap') {
                $whereConditions[] = "t.Tickets_musteri_cevap_var = 1";
            } elseif ($durumId === 'kapali') {
                $whereConditions[] = "d.Ticket_Durumlar_kapatma_durumu = 1";
            } elseif ($durumId !== 'tumu' && $durumId !== '') {
                $whereConditions[] = "t.Tickets_durum_id = ?";
                $params[] = intval($durumId);
            }
            if ($oncelikId !== '') {
                $whereConditions[] = "t.Tickets_oncelik_id = ?";
                $params[] = intval($oncelikId);
            }
            if ($kategoriId !== '') {
                $whereConditions[] = "t.Tickets_kategori_id = ?";
                $params[] = intval($kategoriId);
            }
            if ($atananId !== '') {
                $whereConditions[] = "t.Tickets_atanan_kullanici_id = ?";
                $params[] = intval($atananId);
            }
            if ($search !== '') {
                $whereConditions[] = "(t.Tickets_no LIKE ? OR t.Tickets_konu LIKE ?)";
                $params[] = "%$search%";
                $params[] = "%$search%";
            }
            if ($tarihBas !== '') {
                $whereConditions[] = "t.OlusturmaTarihi >= ?";
                $params[] = $tarihBas . ' 00:00:00';
            }
            if ($tarihBit !== '') {
                $whereConditions[] = "t.OlusturmaTarihi <= ?";
                $params[] = $tarihBit . ' 23:59:59';
            }

            $whereClause = implode(" AND ", $whereConditions);

            $liste = $db->fetchAll("
                SELECT
                    t.Tickets_id,
                    t.Tickets_no,
                    t.Tickets_konu,
                    t.Tickets_atanan_kullanici_id,
                    t.Tickets_olusturan_kullanici_id,
                    t.Tickets_kategori_id,
                    t.Tickets_oncelik_id,
                    t.Tickets_durum_id,
                    t.Tickets_musteri_cevap_var,
                    CONVERT(VARCHAR(16), t.OlusturmaTarihi, 120)       AS acilis_tarihi,
                    CONVERT(VARCHAR(16), t.Tickets_son_yanit_tarihi, 120) AS son_yanit_tarihi,
                    k.Ticket_Kategoriler_ad,
                    k.Ticket_Kategoriler_renk,
                    o.Ticket_Oncelikler_ad,
                    o.Ticket_Oncelikler_renk,
                    o.Ticket_Oncelikler_ikon,
                    d.Ticket_Durumlar_ad,
                    d.Ticket_Durumlar_renk,
                    d.Ticket_Durumlar_ikon,
                    d.Ticket_Durumlar_kapatma_durumu,
                    ku.kullanici_ad AS olusturan_ad,
                    ku.kullanici_soyad AS olusturan_soyad,
                    ka.kullanici_ad   AS atanan_ad,
                    ka.kullanici_soyad AS atanan_soyad,
                    tk.Ticket_Kaynak_kod,
                    (SELECT COUNT(*) FROM Ticket_Mesajlar m WHERE m.Ticket_Mesajlar_ticket_id = t.Tickets_id AND m.Durum = 1) AS mesaj_sayisi
                FROM Tickets t
                INNER JOIN Ticket_Kategoriler k ON t.Tickets_kategori_id = k.Ticket_Kategoriler_id
                INNER JOIN Ticket_Oncelikler  o ON t.Tickets_oncelik_id  = o.Ticket_Oncelikler_id
                INNER JOIN Ticket_Durumlar    d ON t.Tickets_durum_id    = d.Ticket_Durumlar_id
                LEFT  JOIN kullanicilar       ku ON t.Tickets_olusturan_kullanici_id = ku.kullanici_id
                LEFT  JOIN kullanicilar       ka ON t.Tickets_atanan_kullanici_id    = ka.kullanici_id
                LEFT  JOIN Ticket_Kaynak      tk ON t.Tickets_kaynak_id              = tk.Ticket_Kaynak_id
                WHERE $whereClause
                ORDER BY t.OlusturmaTarihi DESC
            ", $params);

            echo json_encode(['success' => true, 'data' => $liste]);
            exit;
        }

        // -----------------------------------------------------------------
        // filter_options (Filtre dropdown verileri)
        // -----------------------------------------------------------------
        if ($action === 'filter_options') {
            $durumlar    = $db->fetchAll("SELECT Ticket_Durumlar_id AS id, Ticket_Durumlar_ad AS ad FROM Ticket_Durumlar WHERE Durum = 1 ORDER BY Ticket_Durumlar_sira");
            $oncelikler  = $db->fetchAll("SELECT Ticket_Oncelikler_id AS id, Ticket_Oncelikler_ad AS ad FROM Ticket_Oncelikler WHERE Durum = 1 ORDER BY Ticket_Oncelikler_sira");
            $kategoriler = $db->fetchAll("SELECT Ticket_Kategoriler_id AS id, Ticket_Kategoriler_ad AS ad FROM Ticket_Kategoriler WHERE Durum = 1 ORDER BY Ticket_Kategoriler_sira");
            $personeller = $db->fetchAll("SELECT kullanici_id AS id, kullanici_ad + ' ' + kullanici_soyad AS ad FROM kullanicilar WHERE kullanici_durum = 1 ORDER BY kullanici_ad");

            echo json_encode(['success' => true, 'data' => compact('durumlar', 'oncelikler', 'kategoriler', 'personeller')]);
            exit;
        }

        // -----------------------------------------------------------------
        // durum_guncelle
        // -----------------------------------------------------------------
        if ($action === 'durum_guncelle') {
            if (!$pagePermissions['can_edit']) throw new Exception('Düzenleme yetkiniz bulunmamaktadır.');

            $ticketId = intval($_POST['ticket_id'] ?? 0);
            $durumId  = intval($_POST['durum_id']  ?? 0);
            if ($ticketId <= 0 || $durumId <= 0) throw new Exception('Geçersiz parametre.');

            $durum = $db->fetchOne("SELECT Ticket_Durumlar_kapatma_durumu FROM Ticket_Durumlar WHERE Ticket_Durumlar_id = ? AND Durum = 1", [$durumId]);
            if (!$durum) throw new Exception('Geçersiz durum.');

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
        // kategori_guncelle
        // -----------------------------------------------------------------
        if ($action === 'kategori_guncelle') {
            if (!$pagePermissions['can_edit']) throw new Exception('Düzenleme yetkiniz bulunmamaktadır.');

            $ticketId   = intval($_POST['ticket_id']   ?? 0);
            $kategoriId = intval($_POST['kategori_id'] ?? 0);
            if ($ticketId <= 0 || $kategoriId <= 0) throw new Exception('Geçersiz parametre.');

            $kategori = $db->fetchOne("SELECT Ticket_Kategoriler_id FROM Ticket_Kategoriler WHERE Ticket_Kategoriler_id = ? AND Durum = 1", [$kategoriId]);
            if (!$kategori) throw new Exception('Geçersiz kategori.');

            $db->update('Tickets', [
                'Tickets_kategori_id'  => $kategoriId,
                'GuncelleyenKullanici' => $user['kullanici_id'],
                'GuncellemeTarihi'     => app_now(),
            ], ['Tickets_id' => $ticketId]);

            echo json_encode(['success' => true, 'message' => 'Kategori güncellendi.']);
            exit;
        }

        // -----------------------------------------------------------------
        // oncelik_guncelle
        // -----------------------------------------------------------------
        if ($action === 'oncelik_guncelle') {
            if (!$pagePermissions['can_edit']) throw new Exception('Düzenleme yetkiniz bulunmamaktadır.');

            $ticketId  = intval($_POST['ticket_id']  ?? 0);
            $oncelikId = intval($_POST['oncelik_id'] ?? 0);
            if ($ticketId <= 0 || $oncelikId <= 0) throw new Exception('Geçersiz parametre.');

            $oncelik = $db->fetchOne("SELECT Ticket_Oncelikler_id FROM Ticket_Oncelikler WHERE Ticket_Oncelikler_id = ? AND Durum = 1", [$oncelikId]);
            if (!$oncelik) throw new Exception('Geçersiz öncelik.');

            $db->update('Tickets', [
                'Tickets_oncelik_id'   => $oncelikId,
                'GuncelleyenKullanici' => $user['kullanici_id'],
                'GuncellemeTarihi'     => app_now(),
            ], ['Tickets_id' => $ticketId]);

            echo json_encode(['success' => true, 'message' => 'Öncelik güncellendi.']);
            exit;
        }

        // -----------------------------------------------------------------
        // atama_guncelle
        // -----------------------------------------------------------------
        if ($action === 'atama_guncelle') {
            if (!$pagePermissions['can_edit']) throw new Exception('Düzenleme yetkiniz bulunmamaktadır.');

            $ticketId    = intval($_POST['ticket_id']   ?? 0);
            $kullaniciId = !empty($_POST['kullanici_id']) ? intval($_POST['kullanici_id']) : null;
            if ($ticketId <= 0) throw new Exception('Geçersiz ticket.');

            $eskiKayit   = $db->fetchOne("SELECT Tickets_atanan_kullanici_id FROM Tickets WHERE Tickets_id = ?", [$ticketId]);
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
        // ticket_sil
        // -----------------------------------------------------------------
        if ($action === 'ticket_sil') {
            if (!$pagePermissions['can_delete']) throw new Exception('Silme yetkiniz bulunmamaktadır.');

            $ticketId = intval($_POST['ticket_id'] ?? 0);
            if ($ticketId <= 0) throw new Exception('Geçersiz ticket.');

            $db->update('Tickets', [
                'Durum'               => 0,
                'GuncelleyenKullanici' => $user['kullanici_id'],
                'GuncellemeTarihi'    => app_now(),
            ], ['Tickets_id' => $ticketId]);

            echo json_encode(['success' => true, 'message' => 'Ticket silindi.']);
            exit;
        }

        // -----------------------------------------------------------------
        // ticket_birlestir
        // -----------------------------------------------------------------
        if ($action === 'ticket_birlestir') {
            if (!$isAdmin) throw new Exception('Bu işlem için yetkiniz yok.');

            $anaTicketId = intval($_POST['ana_ticket_id'] ?? 0);
            $kaynakIds = $_POST['kaynak_ids'] ?? [];

            if ($anaTicketId <= 0) throw new Exception('Ana ticket seçilmedi.');
            if (empty($kaynakIds)) throw new Exception('Kaynak ticket seçilmedi.');

            $anaTicket = $db->fetchOne("SELECT Tickets_id, Tickets_no, Tickets_olusturan_kullanici_id FROM Tickets WHERE Tickets_id = ? AND Durum = 1", [$anaTicketId]);
            if (!$anaTicket) throw new Exception('Ana ticket bulunamadı.');

            $kapatmaDurumu = $db->fetchOne("SELECT Ticket_Durumlar_id FROM Ticket_Durumlar WHERE Ticket_Durumlar_kapatma_durumu = 1 AND Durum = 1 ORDER BY Ticket_Durumlar_sira");
            if (!$kapatmaDurumu) throw new Exception('Kapatma durumu bulunamadı.');

            $now = app_now();
            $toplamMesaj = 0;
            $birlestirilen = [];

            foreach ($kaynakIds as $kid) {
                $kid = intval($kid);
                if ($kid <= 0 || $kid === $anaTicketId) continue;

                $kaynak = $db->fetchOne("SELECT Tickets_id, Tickets_no, Tickets_olusturan_kullanici_id FROM Tickets WHERE Tickets_id = ? AND Durum = 1", [$kid]);
                if (!$kaynak) continue;

                // Mesaj sayısını al
                $mSayi = $db->fetchOne("SELECT COUNT(*) as s FROM Ticket_Mesajlar WHERE Ticket_Mesajlar_ticket_id = ? AND Durum = 1", [$kid]);
                $toplamMesaj += intval($mSayi['s'] ?? 0);

                // Mesajları ana ticket'a taşı
                $db->query("UPDATE Ticket_Mesajlar SET Ticket_Mesajlar_ticket_id = ? WHERE Ticket_Mesajlar_ticket_id = ? AND Durum = 1", [$anaTicketId, $kid]);

                // Açılış eklerini taşı
                $db->query("UPDATE Ticket_Ekler SET Ticket_Ekler_ticket_id = ? WHERE Ticket_Ekler_ticket_id = ? AND Durum = 1", [$anaTicketId, $kid]);

                // Ana ticket'a iç not ekle
                $db->insert('Ticket_Mesajlar', [
                    'Ticket_Mesajlar_ticket_id'    => $anaTicketId,
                    'Ticket_Mesajlar_mesaj'        => '📎 ' . $kaynak['Tickets_no'] . ' numaralı ticket bu talep ile birleştirildi.',
                    'Ticket_Mesajlar_ic_not'       => 1,
                    'Ticket_Mesajlar_yazan_kul_id' => $user['kullanici_id'],
                    'OlusturanKullanici' => $user['kullanici_id'],
                    'OlusturmaTarihi'   => $now,
                    'GuncelleyenKullanici' => $user['kullanici_id'],
                    'GuncellemeTarihi'  => $now,
                    'Durum' => 1
                ]);

                // Kaynak oluşturanı CC'ye ekle (ana ticket oluşturanı ile aynı değilse)
                if ($kaynak['Tickets_olusturan_kullanici_id']
                    && $kaynak['Tickets_olusturan_kullanici_id'] != $anaTicket['Tickets_olusturan_kullanici_id']) {
                    $ccVar = $db->fetchOne("SELECT Ticket_CC_id FROM Ticket_CC WHERE Ticket_CC_ticket_id = ? AND Ticket_CC_kullanici_id = ? AND Durum = 1", [$anaTicketId, $kaynak['Tickets_olusturan_kullanici_id']]);
                    if (!$ccVar) {
                        $db->insert('Ticket_CC', [
                            'Ticket_CC_ticket_id'    => $anaTicketId,
                            'Ticket_CC_kullanici_id' => $kaynak['Tickets_olusturan_kullanici_id'],
                            'OlusturanKullanici' => $user['kullanici_id'],
                            'OlusturmaTarihi'   => $now,
                            'GuncelleyenKullanici' => $user['kullanici_id'],
                            'GuncellemeTarihi'  => $now,
                            'Durum' => 1
                        ]);
                    }
                }

                // Kaynak ticket'a kapanış notu ekle
                $db->insert('Ticket_Mesajlar', [
                    'Ticket_Mesajlar_ticket_id'    => $kid,
                    'Ticket_Mesajlar_mesaj'        => '📎 Bu talep ' . $anaTicket['Tickets_no'] . ' ile birleştirildi.',
                    'Ticket_Mesajlar_ic_not'       => 1,
                    'Ticket_Mesajlar_yazan_kul_id' => $user['kullanici_id'],
                    'OlusturanKullanici' => $user['kullanici_id'],
                    'OlusturmaTarihi'   => $now,
                    'GuncelleyenKullanici' => $user['kullanici_id'],
                    'GuncellemeTarihi'  => $now,
                    'Durum' => 1
                ]);

                // Kaynak ticket'ı kapat
                $db->update('Tickets', [
                    'Tickets_durum_id'      => $kapatmaDurumu['Ticket_Durumlar_id'],
                    'Tickets_kapanis_tarihi' => $now,
                    'Tickets_musteri_cevap_var' => 0,
                    'GuncelleyenKullanici'  => $user['kullanici_id'],
                    'GuncellemeTarihi'      => $now
                ], ['Tickets_id' => $kid]);

                $birlestirilen[] = $kaynak['Tickets_no'];
            }

            // Ana ticket son yanıt tarihini güncelle
            $db->update('Tickets', [
                'Tickets_son_yanit_tarihi' => $now,
                'GuncelleyenKullanici'     => $user['kullanici_id'],
                'GuncellemeTarihi'         => $now
            ], ['Tickets_id' => $anaTicketId]);

            echo json_encode([
                'success' => true,
                'message' => count($birlestirilen) . ' ticket birleştirildi, ' . $toplamMesaj . ' mesaj aktarıldı.',
                'ana_ticket_id' => $anaTicketId
            ]);
            exit;
        }

        // -----------------------------------------------------------------
        // ticket_ayir_mesajlar (ayırma modalı için mesaj listesi)
        // -----------------------------------------------------------------
        if ($action === 'ticket_ayir_mesajlar') {
            if (!$isAdmin) throw new Exception('Bu işlem için yetkiniz yok.');

            $ticketId = intval($_POST['ticket_id'] ?? 0);
            if ($ticketId <= 0) throw new Exception('Geçersiz ticket.');

            $ticket = $db->fetchOne("
                SELECT Tickets_id, Tickets_no, Tickets_konu, Tickets_kategori_id,
                       Tickets_oncelik_id, Tickets_atanan_kullanici_id
                FROM Tickets WHERE Tickets_id = ? AND Durum = 1
            ", [$ticketId]);
            if (!$ticket) throw new Exception('Ticket bulunamadı.');

            $mesajlar = $db->fetchAll("
                SELECT
                    m.Ticket_Mesajlar_id,
                    m.Ticket_Mesajlar_mesaj,
                    m.Ticket_Mesajlar_ic_not,
                    CONVERT(VARCHAR(16), m.OlusturmaTarihi, 120) AS tarih,
                    COALESCE(m.Ticket_Mesajlar_yazan_ad, k.kullanici_ad) AS kullanici_ad,
                    COALESCE(m.Ticket_Mesajlar_yazan_soyad, k.kullanici_soyad) AS kullanici_soyad,
                    (SELECT COUNT(*) FROM Ticket_Ekler e
                      WHERE e.Ticket_Ekler_mesaj_id = m.Ticket_Mesajlar_id AND e.Durum = 1) AS ek_sayisi
                FROM Ticket_Mesajlar m
                LEFT JOIN kullanicilar k ON m.Ticket_Mesajlar_yazan_kul_id = k.kullanici_id
                WHERE m.Ticket_Mesajlar_ticket_id = ? AND m.Durum = 1
                ORDER BY m.OlusturmaTarihi ASC, m.Ticket_Mesajlar_id ASC
            ", [$ticketId]);

            echo json_encode(['success' => true, 'ticket' => $ticket, 'mesajlar' => $mesajlar]);
            exit;
        }

        // -----------------------------------------------------------------
        // ticket_ayir
        // -----------------------------------------------------------------
        if ($action === 'ticket_ayir') {
            if (!$isAdmin) throw new Exception('Bu işlem için yetkiniz yok.');

            $ticketId   = intval($_POST['ticket_id'] ?? 0);
            $yeniKonu   = trim($_POST['yeni_konu'] ?? '');
            $kategoriId = intval($_POST['kategori_id'] ?? 0);
            $oncelikId  = intval($_POST['oncelik_id'] ?? 0);
            $atananId   = ($_POST['atanan_id'] ?? '') !== '' ? intval($_POST['atanan_id']) : null;
            $parcalar   = json_decode($_POST['parcalar'] ?? '[]', true);

            if ($ticketId <= 0) throw new Exception('Geçersiz ticket.');
            if ($yeniKonu === '') throw new Exception('Yeni talep konusu boş olamaz.');
            if (!is_array($parcalar) || count($parcalar) === 0) throw new Exception('Ayrılacak mesaj seçilmedi.');

            $kaynak = $db->fetchOne("SELECT * FROM Tickets WHERE Tickets_id = ? AND Durum = 1", [$ticketId]);
            if (!$kaynak) throw new Exception('Ticket bulunamadı.');

            if ($kategoriId <= 0) $kategoriId = intval($kaynak['Tickets_kategori_id']);
            if ($oncelikId <= 0)  $oncelikId  = intval($kaynak['Tickets_oncelik_id']);

            $ilkDurum = $db->fetchOne("
                SELECT TOP 1 Ticket_Durumlar_id FROM Ticket_Durumlar
                WHERE Durum = 1 AND Ticket_Durumlar_kapatma_durumu = 0
                ORDER BY Ticket_Durumlar_sira ASC
            ");
            if (!$ilkDurum) throw new Exception('Aktif durum tanımı bulunamadı.');

            // Kaynakta en az bir mesaj kalmalı
            $toplamMesaj = intval($db->fetchOne(
                "SELECT COUNT(*) AS s FROM Ticket_Mesajlar WHERE Ticket_Mesajlar_ticket_id = ? AND Durum = 1",
                [$ticketId]
            )['s'] ?? 0);

            $tamTasinan = 0;
            foreach ($parcalar as $p) {
                if (trim((string)($p['kalan'] ?? '')) === '') $tamTasinan++;
            }
            if ($toplamMesaj - $tamTasinan < 1) {
                throw new Exception('Kaynak talepte en az bir mesaj kalmalıdır. Tüm mesajları ayıramazsınız.');
            }

            $now   = app_now();
            $tarih = date('Ymd');
            $sayac = $db->fetchOne(
                "SELECT COUNT(*) AS sayi FROM Tickets WHERE CONVERT(VARCHAR(8), OlusturmaTarihi, 112) = ?",
                [$tarih]
            );
            $yeniNo = 'TKT-' . $tarih . '-' . str_pad((string)(intval($sayac['sayi'] ?? 0) + 1), 4, '0', STR_PAD_LEFT);

            // Yeni ticket
            $yeniTicketData = [
                'Tickets_no'                     => $yeniNo,
                'Tickets_konu'                   => $yeniKonu,
                'Tickets_kategori_id'            => $kategoriId,
                'Tickets_oncelik_id'             => $oncelikId,
                'Tickets_durum_id'               => intval($ilkDurum['Ticket_Durumlar_id']),
                'Tickets_atanan_kullanici_id'    => $atananId,
                'Tickets_olusturan_kullanici_id' => $kaynak['Tickets_olusturan_kullanici_id'],
                'Tickets_son_yanit_tarihi'       => $now,
                'OlusturmaTarihi'                => $now,
                'GuncelleyenKullanici'           => $user['kullanici_id'],
                'GuncellemeTarihi'               => $now,
                'Durum'                          => 1,
            ];
            if (array_key_exists('Tickets_kaynak_id', $kaynak) && $kaynak['Tickets_kaynak_id'] !== null) {
                $yeniTicketData['Tickets_kaynak_id'] = $kaynak['Tickets_kaynak_id'];
            }
            if (array_key_exists('Tickets_bildirim_yolu_id', $kaynak) && $kaynak['Tickets_bildirim_yolu_id'] !== null) {
                $yeniTicketData['Tickets_bildirim_yolu_id'] = $kaynak['Tickets_bildirim_yolu_id'];
            }

            $yeniTicketId = intval($db->insert('Tickets', $yeniTicketData));
            if ($yeniTicketId <= 0) throw new Exception('Yeni talep oluşturulamadı.');

            $tasinanMesaj = 0;
            $bolunenMesaj = 0;

            foreach ($parcalar as $p) {
                $mesajId = intval($p['id'] ?? 0);
                if ($mesajId <= 0) continue;

                $mesaj = $db->fetchOne(
                    "SELECT * FROM Ticket_Mesajlar WHERE Ticket_Mesajlar_id = ? AND Ticket_Mesajlar_ticket_id = ? AND Durum = 1",
                    [$mesajId, $ticketId]
                );
                if (!$mesaj) continue;

                $yeniMetin  = trim((string)($p['metin'] ?? ''));
                $kalanMetin = trim((string)($p['kalan'] ?? ''));
                $ekHedef    = ($p['ekler'] ?? 'yeni') === 'kaynak' ? 'kaynak' : 'yeni';

                if ($yeniMetin === '') continue;

                if ($kalanMetin === '') {
                    // Mesajın tamamı taşınıyor - kayıt yeni ticket'a geçer
                    $db->update('Ticket_Mesajlar', [
                        'Ticket_Mesajlar_ticket_id' => $yeniTicketId,
                        'Ticket_Mesajlar_mesaj'     => $yeniMetin,
                        'GuncelleyenKullanici'      => $user['kullanici_id'],
                        'GuncellemeTarihi'          => $now,
                    ], ['Ticket_Mesajlar_id' => $mesajId]);

                    if ($ekHedef === 'yeni') {
                        $db->query(
                            "UPDATE Ticket_Ekler SET Ticket_Ekler_ticket_id = ? WHERE Ticket_Ekler_mesaj_id = ? AND Durum = 1",
                            [$yeniTicketId, $mesajId]
                        );
                    } else {
                        // Ekler kaynak talepte kalsın - mesaj bağı koparılır
                        $db->query(
                            "UPDATE Ticket_Ekler SET Ticket_Ekler_mesaj_id = NULL, Ticket_Ekler_ticket_id = ? WHERE Ticket_Ekler_mesaj_id = ? AND Durum = 1",
                            [$ticketId, $mesajId]
                        );
                    }

                    $tasinanMesaj++;
                } else {
                    // Mesaj bölünüyor - kaynakta kalan metin güncellenir, yeni ticket'a kopya insert edilir
                    $db->update('Ticket_Mesajlar', [
                        'Ticket_Mesajlar_mesaj' => $kalanMetin,
                        'GuncelleyenKullanici'  => $user['kullanici_id'],
                        'GuncellemeTarihi'      => $now,
                    ], ['Ticket_Mesajlar_id' => $mesajId]);

                    $yeniMesajId = intval($db->insert('Ticket_Mesajlar', [
                        'Ticket_Mesajlar_ticket_id'           => $yeniTicketId,
                        'Ticket_Mesajlar_mesaj'               => $yeniMetin,
                        'Ticket_Mesajlar_ic_not'              => intval($mesaj['Ticket_Mesajlar_ic_not'] ?? 0),
                        'Ticket_Mesajlar_yazan_kul_id'        => $mesaj['Ticket_Mesajlar_yazan_kul_id'],
                        'Ticket_Mesajlar_kaynak_kullanici_id' => $mesaj['Ticket_Mesajlar_kaynak_kullanici_id'] ?? null,
                        'Ticket_Mesajlar_yazan_ad'            => $mesaj['Ticket_Mesajlar_yazan_ad'] ?? null,
                        'Ticket_Mesajlar_yazan_soyad'         => $mesaj['Ticket_Mesajlar_yazan_soyad'] ?? null,
                        'Ticket_Mesajlar_kaynak'              => $mesaj['Ticket_Mesajlar_kaynak'] ?? 'panel',
                        'OlusturanKullanici'                  => $user['kullanici_id'],
                        'OlusturmaTarihi'                     => $mesaj['OlusturmaTarihi'],
                        'GuncelleyenKullanici'                => $user['kullanici_id'],
                        'GuncellemeTarihi'                    => $now,
                        'Durum'                               => 1,
                    ]));

                    if ($ekHedef === 'yeni' && $yeniMesajId > 0) {
                        $db->query(
                            "UPDATE Ticket_Ekler SET Ticket_Ekler_ticket_id = ?, Ticket_Ekler_mesaj_id = ? WHERE Ticket_Ekler_mesaj_id = ? AND Durum = 1",
                            [$yeniTicketId, $yeniMesajId, $mesajId]
                        );
                    }

                    $bolunenMesaj++;
                }
            }

            $tasinanEk = intval($db->fetchOne(
                "SELECT COUNT(*) AS s FROM Ticket_Ekler WHERE Ticket_Ekler_ticket_id = ? AND Durum = 1",
                [$yeniTicketId]
            )['s'] ?? 0);

            if ($tasinanMesaj === 0 && $bolunenMesaj === 0) {
                $db->update('Tickets', ['Durum' => 0, 'GuncellemeTarihi' => $now], ['Tickets_id' => $yeniTicketId]);
                throw new Exception('Ayrılabilecek mesaj bulunamadı.');
            }

            // CC listesini kopyala
            $ccListe = $db->fetchAll(
                "SELECT Ticket_CC_kullanici_id FROM Ticket_CC WHERE Ticket_CC_ticket_id = ? AND Durum = 1",
                [$ticketId]
            );
            foreach ($ccListe as $cc) {
                $db->insert('Ticket_CC', [
                    'Ticket_CC_ticket_id'    => $yeniTicketId,
                    'Ticket_CC_kullanici_id' => $cc['Ticket_CC_kullanici_id'],
                    'OlusturanKullanici'     => $user['kullanici_id'],
                    'OlusturmaTarihi'        => $now,
                    'GuncelleyenKullanici'   => $user['kullanici_id'],
                    'GuncellemeTarihi'       => $now,
                    'Durum'                  => 1,
                ]);
            }

            $toplamAyrilan = $tasinanMesaj + $bolunenMesaj;

            // Yeni talebe iç not
            $db->insert('Ticket_Mesajlar', [
                'Ticket_Mesajlar_ticket_id'    => $yeniTicketId,
                'Ticket_Mesajlar_mesaj'        => '✂️ Bu talep ' . $kaynak['Tickets_no'] . ' numaralı talepten ayrıldı.',
                'Ticket_Mesajlar_ic_not'       => 1,
                'Ticket_Mesajlar_yazan_kul_id' => $user['kullanici_id'],
                'OlusturanKullanici'           => $user['kullanici_id'],
                'OlusturmaTarihi'              => $now,
                'GuncelleyenKullanici'         => $user['kullanici_id'],
                'GuncellemeTarihi'             => $now,
                'Durum'                        => 1,
            ]);

            // Kaynak talebe iç not
            $db->insert('Ticket_Mesajlar', [
                'Ticket_Mesajlar_ticket_id'    => $ticketId,
                'Ticket_Mesajlar_mesaj'        => '✂️ ' . $toplamAyrilan . ' mesaj ' . $yeniNo . ' numaralı yeni talebe ayrıldı.'
                                                  . ($bolunenMesaj > 0 ? ' (' . $bolunenMesaj . ' mesaj bölünerek aktarıldı.)' : ''),
                'Ticket_Mesajlar_ic_not'       => 1,
                'Ticket_Mesajlar_yazan_kul_id' => $user['kullanici_id'],
                'OlusturanKullanici'           => $user['kullanici_id'],
                'OlusturmaTarihi'              => $now,
                'GuncelleyenKullanici'         => $user['kullanici_id'],
                'GuncellemeTarihi'             => $now,
                'Durum'                        => 1,
            ]);

            $db->update('Tickets', [
                'GuncelleyenKullanici' => $user['kullanici_id'],
                'GuncellemeTarihi'     => $now,
            ], ['Tickets_id' => $ticketId]);

            // Ayrılan talep başkasına atandıysa bildirim gönder
            require_once __DIR__ . '/../includes/TicketService.php';
            (new TicketService($db))->atamaBildirimiTetikle($yeniTicketId, $atananId, $user['kullanici_id'], 0);

            echo json_encode([
                'success'        => true,
                'message'        => $toplamAyrilan . ' mesaj ve ' . $tasinanEk . ' ek ' . $yeniNo . ' numaralı yeni talebe ayrıldı.',
                'yeni_ticket_id' => $yeniTicketId,
                'yeni_ticket_no' => $yeniNo,
            ]);
            exit;
        }

        throw new Exception('Geçersiz işlem.');

    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
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
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/admin/assets/css/custom.css">

    <style>
        .ticket-no { font-family: monospace; font-size: 0.82rem; color: #6c757d; }
        .badge-oncelik, .badge-durum, .badge-kategori { font-size: 0.78rem; }
        .filter-collapse { background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 6px; padding: 1rem; margin-bottom: 1rem; }
        .table-actions { white-space: nowrap; }
        .hucre-duzenle { cursor: pointer; }
        .hucre-duzenle:hover { opacity: 0.75; text-decoration: underline; }
        tr.satir-cevap-geldi > td { background-color: #e7f5ff !important; }
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

        <!-- İçerik -->
        <div class="app-content">
            <div class="container-fluid">

                <!-- InfoBoxes -->
                <div class="row mb-3">
                    <div class="col-12 col-sm-6 col-md-3">
                        <div class="info-box">
                            <span class="info-box-icon text-bg-primary shadow-sm"><i class="bi bi-ticket-perforated"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Toplam Ticket</span>
                                <span class="info-box-number" id="stat-toplam">-</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-md-3">
                        <div class="info-box">
                            <span class="info-box-icon text-bg-warning shadow-sm"><i class="bi bi-circle"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Açık</span>
                                <span class="info-box-number" id="stat-acik">-</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-md-3">
                        <div class="info-box">
                            <span class="info-box-icon text-bg-success shadow-sm"><i class="bi bi-check-circle"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Kapalı</span>
                                <span class="info-box-number" id="stat-kapali">-</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-md-3">
                        <div class="info-box">
                            <span class="info-box-icon text-bg-danger shadow-sm"><i class="bi bi-exclamation-circle"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Acil</span>
                                <span class="info-box-number" id="stat-acil">-</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-md-3">
                        <div class="info-box" style="cursor:pointer;" id="stat-cevap-box" title="Cevap gelen talepleri listele">
                            <span class="info-box-icon text-bg-info shadow-sm"><i class="bi bi-reply-fill"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Cevap Geldi</span>
                                <span class="info-box-number" id="stat-cevap">-</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filtre Paneli -->
                <div class="card mb-3">
                    <div class="card-header" style="cursor:pointer;" data-bs-toggle="collapse" data-bs-target="#filtrePanel">
                        <h5 class="card-title mb-0">
                            <i class="bi bi-funnel me-1"></i> Filtrele
                            <i class="bi bi-chevron-down float-end"></i>
                        </h5>
                    </div>
                    <div class="collapse" id="filtrePanel">
                        <div class="card-body">
                            <div class="row g-2">
                                <div class="col-12 col-md-3">
                                    <label class="form-label">Durum</label>
                                    <select id="filtre-durum" class="form-select select2-basic">
                                        <option value="acik">Sadece Açık Ticketlar</option>
                                        <option value="cevap">Cevap Gelenler</option>
                                        <option value="kapali">Sadece Kapalı Ticketlar</option>
                                        <option value="tumu">Tümü</option>
                                        <optgroup label="Spesifik Durumlar" id="filtre-durum-optgroup"></optgroup>
                                    </select>
                                </div>
                                <div class="col-12 col-md-3">
                                    <label class="form-label">Öncelik</label>
                                    <select id="filtre-oncelik" class="form-select select2-basic">
                                        <option value="">Tümü</option>
                                    </select>
                                </div>
                                <div class="col-12 col-md-3">
                                    <label class="form-label">Kategori</label>
                                    <select id="filtre-kategori" class="form-select select2-basic">
                                        <option value="">Tümü</option>
                                    </select>
                                </div>
                                <?php if ($isAdmin): ?>
                                <div class="col-12 col-md-3">
                                    <label class="form-label">Atanan Personel</label>
                                    <select id="filtre-atanan" class="form-select select2-basic">
                                        <option value="">Tümü</option>
                                    </select>
                                </div>
                                <?php endif; ?>
                                <div class="col-12 col-md-3">
                                    <label class="form-label">Açılış Tarihi (Başlangıç)</label>
                                    <input type="date" id="filtre-tarih-bas" class="form-control">
                                </div>
                                <div class="col-12 col-md-3">
                                    <label class="form-label">Açılış Tarihi (Bitiş)</label>
                                    <input type="date" id="filtre-tarih-bit" class="form-control">
                                </div>
                                <div class="col-12 col-md-3">
                                    <label class="form-label">Arama (No / Konu)</label>
                                    <input type="text" id="filtre-search" class="form-control" placeholder="TKT-... veya konu ara">
                                </div>
                            </div>
                            <div class="mt-3 d-flex gap-2">
                                <button class="btn btn-primary btn-sm" id="btnFiltrele">
                                    <i class="bi bi-search me-1"></i> Filtrele
                                </button>
                                <button class="btn btn-secondary btn-sm" id="btnFiltreTemizle">
                                    <i class="bi bi-x-circle me-1"></i> Temizle
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tablo -->
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0"><i class="bi bi-list-ul me-1"></i> Ticket Listesi</h5>
                        <div class="d-flex gap-2 align-items-center">
                            <?php if ($isAdmin): ?>
                            <button class="btn btn-info btn-sm text-white" id="btnBirlestir" style="display:none;" onclick="ticketBirlestirModalAc()">
                                <i class="bi bi-union me-1"></i> Birleştir (<span id="seciliSayi">0</span>)
                            </button>
                            <button class="btn btn-warning btn-sm" id="btnAyir" style="display:none;" onclick="ticketAyirModalAc()">
                                <i class="bi bi-scissors me-1"></i> Ayır
                            </button>
                            <?php endif; ?>
                            <?php if ($pagePermissions['can_add'] ?? false): ?>
                            <a href="/admin/ticket-ekle" class="btn btn-success btn-sm">
                                <i class="bi bi-plus-circle me-1"></i> Yeni Ticket
                            </a>
                            <?php endif; ?>
                            <button id="btn-manual-refresh" class="btn btn-sm btn-outline-secondary" title="Şimdi Yenile">
                                <i class="bi bi-arrow-clockwise"></i>
                            </button>
                            <span class="badge text-bg-secondary" id="refresh-badge" style="font-size:0.7rem;min-width:32px;">60s</span>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table id="ticketTable" class="table table-hover table-bordered table-sm align-middle" style="width:100%">
                                <thead class="table-light">
                                    <tr>
                                        <th><input type="checkbox" id="checkAll" title="Tümünü Seç"></th>
                                        <th>Ticket No</th>
                                        <th>Konu</th>
                                        <th>Kategori</th>
                                        <th>Öncelik</th>
                                        <th>Durum</th>
                                        <th>Oluşturan</th>
                                        <th>Atanan</th>
                                        <th>Kaynak</th>
                                        <th>Mesaj</th>
                                        <th>Açılış</th>
                                        <th>İşlemler</th>
                                    </tr>
                                </thead>
                                <tbody id="ticketTableBody">
                                    <tr><td colspan="12" class="text-center text-muted py-4"><i class="bi bi-hourglass-split me-1"></i> Yükleniyor...</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </main>

    <?php include __DIR__ . '/../includes/footer.php'; ?>
</div>

<!-- Kategori Güncelle Modal -->
<div class="modal fade" id="modalKategori" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Kategori Güncelle</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="kategori-ticket-id">
                <label class="form-label">Yeni Kategori</label>
                <select id="kategori-select" class="form-select select2-modal"></select>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">İptal</button>
                <button type="button" class="btn btn-primary btn-sm" id="btnKategoriKaydet">Kaydet</button>
            </div>
        </div>
    </div>
</div>

<!-- Öncelik Güncelle Modal -->
<div class="modal fade" id="modalOncelik" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Öncelik Güncelle</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="oncelik-ticket-id">
                <label class="form-label">Yeni Öncelik</label>
                <select id="oncelik-select" class="form-select select2-modal"></select>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">İptal</button>
                <button type="button" class="btn btn-primary btn-sm" id="btnOncelikKaydet">Kaydet</button>
            </div>
        </div>
    </div>
</div>

<!-- Durum Güncelle Modal -->
<div class="modal fade" id="modalDurum" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Durum Güncelle</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="durum-ticket-id">
                <label class="form-label">Yeni Durum</label>
                <select id="durum-select" class="form-select select2-modal"></select>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">İptal</button>
                <button type="button" class="btn btn-primary btn-sm" id="btnDurumKaydet">Kaydet</button>
            </div>
        </div>
    </div>
</div>

<!-- Atama Modal -->
<?php if ($isAdmin): ?>
<div class="modal fade" id="modalAtama" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Personel Ata</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="atama-ticket-id">
                <label class="form-label">Personel</label>
                <select id="atama-select" class="form-select select2-modal">
                    <option value="">Atama Yok</option>
                </select>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-info btn-sm me-auto text-white" id="btnKendimeAta"><i class="bi bi-person-check"></i> Bana Ata</button>
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">İptal</button>
                <button type="button" class="btn btn-primary btn-sm" id="btnAtamaKaydet">Kaydet</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Ticket Ayırma Modal -->
<?php if ($isAdmin): ?>
<div class="modal fade" id="modalAyir" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-scissors me-1"></i> Talep Ayırma</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="ayir-ticket-id">

                <div class="alert alert-info py-2">
                    <i class="bi bi-info-circle-fill me-1"></i>
                    <span id="ayir-kaynak-bilgi"></span> talebinden seçtiğiniz mesajlar yeni bir talebe taşınır.
                    Bir mesajın yalnızca bir bölümünü ayırmak isterseniz <strong>Böl</strong> seçeneğini kullanın.
                </div>

                <div class="row g-2 mb-3">
                    <div class="col-12 col-md-6">
                        <label class="form-label fw-bold">Yeni Talep Konusu <span class="text-danger">*</span></label>
                        <input type="text" id="ayir-konu" class="form-control" maxlength="255">
                    </div>
                    <div class="col-12 col-md-2">
                        <label class="form-label fw-bold">Kategori</label>
                        <select id="ayir-kategori" class="form-select select2-modal"></select>
                    </div>
                    <div class="col-12 col-md-2">
                        <label class="form-label fw-bold">Öncelik</label>
                        <select id="ayir-oncelik" class="form-select select2-modal"></select>
                    </div>
                    <div class="col-12 col-md-2">
                        <label class="form-label fw-bold">Atanan</label>
                        <select id="ayir-atanan" class="form-select select2-modal">
                            <option value="">Atama Yok</option>
                        </select>
                    </div>
                </div>

                <label class="form-label fw-bold mb-2">Ayrılacak Mesajlar</label>
                <div id="ayir-mesaj-liste">
                    <div class="text-center text-muted py-4"><i class="bi bi-hourglass-split me-1"></i> Yükleniyor...</div>
                </div>
            </div>
            <div class="modal-footer">
                <span class="me-auto text-muted small"><span id="ayir-secili-sayi">0</span> mesaj seçildi</span>
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">İptal</button>
                <button type="button" class="btn btn-warning btn-sm" id="btnAyirKaydet">
                    <i class="bi bi-scissors me-1"></i> Ayır
                </button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Scripts -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11" crossorigin="anonymous"></script>
<script src="/admin/assets/js/adminlte.min.js"></script>
<script src="/admin/assets/js/custom.js"></script>

<script>
const PAGE_URL = '/admin/ticket-liste';
const IS_ADMIN = <?= $isAdmin ? 'true' : 'false' ?>;
const CAN_EDIT = <?= ($pagePermissions['can_edit'] ?? false) ? 'true' : 'false' ?>;
const CURRENT_USER_ID = <?= $user['kullanici_id'] ?? 0 ?>;

const REFRESH_SURE = 60;
let kalanSure = REFRESH_SURE;
let refreshTimer;

let _bilinen_ticket_ids = null;
let _ilk_yukleme = true;

let ticketTable;
let filterOptions = {};

// -------------------------------------------------------
// DataTable başlat
// -------------------------------------------------------
$(document).ready(function () {
    ticketTable = $('#ticketTable').DataTable({
        data: [],
        columns: [
            { data: null, title: '', orderable: false, searchable: false },
            { data: 'Tickets_no',       title: 'Ticket No' },
            { data: 'Tickets_konu',     title: 'Konu' },
            { data: 'Ticket_Kategoriler_ad', title: 'Kategori' },
            { data: 'Ticket_Oncelikler_ad',  title: 'Öncelik' },
            { data: 'Ticket_Durumlar_ad',    title: 'Durum' },
            { data: 'olusturan_ad',     title: 'Oluşturan' },
            { data: 'atanan_ad',        title: 'Atanan' },
            { data: 'Ticket_Kaynak_kod', title: 'Kaynak' },
            { data: 'mesaj_sayisi',     title: 'Mesaj' },
            { data: 'acilis_tarihi',    title: 'Açılış' },
            { data: null,               title: 'İşlemler', orderable: false }
        ],
        columnDefs: [
            {
                targets: 0,
                render: function (data, type, row) {
                    return `<input type="checkbox" class="ticket-check" value="${row.Tickets_id}" data-no="${row.Tickets_no}" data-konu="${escHtml(row.Tickets_konu)}">`;
                }
            },
            {
                targets: 1,
                render: function (data) {
                    return `<span class="ticket-no">${data}</span>`;
                }
            },
            {
                targets: 2,
                render: function (data, type, row) {
                    return `<a href="/admin/ticket-detay?id=${row.Tickets_id}" class="text-decoration-none fw-semibold">${escHtml(data)}</a>`;
                }
            },
            {
                targets: 3,
                render: function (data, type, row) {
                    const renk = row.Ticket_Kategoriler_renk || '#6c757d';
                    const cls  = CAN_EDIT ? ' hucre-duzenle' : '';
                    const evt  = CAN_EDIT ? ` onclick="kategoriGuncelle(${row.Tickets_id}, ${row.Tickets_kategori_id})" title="Kategoriyi değiştir"` : '';
                    return `<span class="badge badge-kategori${cls}" style="background-color:${renk}"${evt}>${escHtml(data)}</span>`;
                }
            },
            {
                targets: 4,
                render: function (data, type, row) {
                    const renk = row.Ticket_Oncelikler_renk || '#6c757d';
                    const ikon = row.Ticket_Oncelikler_ikon || 'bi bi-dash-circle';
                    const cls  = CAN_EDIT ? ' hucre-duzenle' : '';
                    const evt  = CAN_EDIT ? ` onclick="oncelikGuncelle(${row.Tickets_id}, ${row.Tickets_oncelik_id})" title="Önceliği değiştir"` : '';
                    return `<span class="badge badge-oncelik${cls}" style="background-color:${renk}"${evt}><i class="${ikon} me-1"></i>${escHtml(data)}</span>`;
                }
            },
            {
                targets: 5,
                render: function (data, type, row) {
                    const renk = row.Ticket_Durumlar_renk || '#6c757d';
                    const ikon = row.Ticket_Durumlar_ikon || 'bi bi-circle';
                    const cls  = CAN_EDIT ? ' hucre-duzenle' : '';
                    const evt  = CAN_EDIT ? ` onclick="durumGuncelle(${row.Tickets_id}, ${row.Tickets_durum_id})" title="Durumu değiştir"` : '';
                    return `<span class="badge badge-durum${cls}" style="background-color:${renk}"${evt}><i class="${ikon} me-1"></i>${escHtml(data)}</span>`;
                }
            },
            {
                targets: 6,
                render: function (data, type, row) {
                    const ad = (row.olusturan_ad || '') + ' ' + (row.olusturan_soyad || '');
                    return `<small>${escHtml(ad.trim() || '-')}</small>`;
                }
            },
            {
                targets: 7,
                render: function (data, type, row) {
                    const ad = (row.atanan_ad || '') + ' ' + (row.atanan_soyad || '');
                    const atananAd = ad.trim();
                    const tiklanir = IS_ADMIN && CAN_EDIT;
                    const cls = tiklanir ? ' hucre-duzenle' : '';
                    const evt = tiklanir ? ` onclick="atama(${row.Tickets_id}, ${row.Tickets_atanan_kullanici_id || 0})" title="Atamayı değiştir"` : '';
                    if (!atananAd) {
                        return `<small><span class="text-muted${cls}"${evt}>Atanmadı</span></small>`;
                    }
                    return `<small><span class="${cls.trim()}"${evt}>${escHtml(atananAd)}</span></small>`;
                }
            },
            {
                targets: 8,
                render: function (data) {
                    if (!data) return '<small class="text-muted">Panel</small>';
                    return `<span class="badge text-bg-dark">${escHtml(data)}</span>`;
                }
            },
            {
                targets: 9,
                render: function (data) {
                    return `<span class="badge text-bg-secondary">${data}</span>`;
                }
            },
            {
                targets: 11,
                render: function (data, type, row) {
                    let html = `<div class="table-actions d-flex gap-1">`;
                    html += `<a href="/admin/ticket-detay?id=${row.Tickets_id}" class="btn btn-info btn-xs btn-sm" title="Detay"><i class="bi bi-eye"></i></a>`;
                    html += `<button class="btn btn-danger btn-xs btn-sm" onclick="ticketSil(${row.Tickets_id})" title="Sil"><i class="bi bi-trash"></i></button>`;
                    html += `</div>`;
                    return html;
                }
            }
        ],
        language: {
            url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json'
        },
        order: [[10, 'desc']],
        pageLength: 25,
        responsive: true,
        createdRow: function (row, data) {
            if (data.Tickets_musteri_cevap_var == 1) {
                $(row).addClass('satir-cevap-geldi');
            }
        }
    });

    // Filtre seçeneklerini yükle
    loadFilterOptions();
    // İstatistikler
    loadStats();
    // Listeyi yükle
    loadListe();
    // Otomatik yenilemeyi başlat
    geriSayimBaslat();

    // Select2
    $('.select2-basic').select2({ theme: 'bootstrap-5', width: '100%' });

    // Butonlar
    $('#btnFiltrele').on('click', function () { loadListe(); geriSayimBaslat(); });
    $('#btnFiltreTemizle').on('click', function () {
        $('#filtre-durum').val('acik').trigger('change');
        $('#filtre-oncelik, #filtre-kategori, #filtre-atanan').val('').trigger('change');
        $('#filtre-tarih-bas, #filtre-tarih-bit, #filtre-search').val('');
        _bilinen_ticket_ids = null;
        _ilk_yukleme = true;
        loadListe();
        geriSayimBaslat();
    });

    // InfoBox: cevap gelenleri filtrele
    $('#stat-cevap-box').on('click', function () {
        $('#filtre-durum').val('cevap').trigger('change');
        loadListe();
        geriSayimBaslat();
    });

    // Manuel yenile
    $('#btn-manual-refresh').on('click', function () {
        loadListe();
        loadStats();
        geriSayimBaslat();
    });

    // Durum kaydet
    $('#btnDurumKaydet').on('click', saveDurum);
    // Kategori kaydet
    $('#btnKategoriKaydet').on('click', saveKategori);
    // Öncelik kaydet
    $('#btnOncelikKaydet').on('click', saveOncelik);
    // Atama kaydet
    $('#btnAtamaKaydet').on('click', saveAtama);
    
    // Kendime Ata
    $('#btnKendimeAta').on('click', function() {
        if (CURRENT_USER_ID > 0) {
            $('#atama-select').val(CURRENT_USER_ID).trigger('change');
            saveAtama();
        }
    });
});

// -------------------------------------------------------
// Otomatik Yenileme & Geri Sayım
// -------------------------------------------------------
function geriSayimBaslat() {
    clearInterval(refreshTimer);
    kalanSure = REFRESH_SURE;
    guncelGeriSayim();
    refreshTimer = setInterval(function () {
        kalanSure--;
        guncelGeriSayim();
        if (kalanSure <= 0) {
            kalanSure = REFRESH_SURE;
            loadListe();
            loadStats();
        }
    }, 1000);
}

function guncelGeriSayim() {
    $('#refresh-badge').text(kalanSure + 's');
    if (kalanSure <= 10) {
        $('#refresh-badge').removeClass('text-bg-secondary text-bg-info').addClass('text-bg-warning');
    } else {
        $('#refresh-badge').removeClass('text-bg-warning text-bg-info').addClass('text-bg-secondary');
    }
}

// -------------------------------------------------------
// Ses Bildirimi
// -------------------------------------------------------
let _audioCtx = null;
function getAudioCtx() {
    if (!_audioCtx) _audioCtx = new (window.AudioContext || window.webkitAudioContext)();
    return _audioCtx;
}

function sesCaldir() {
    try {
        const ctx = getAudioCtx();
        [880, 1100].forEach(function (frekans, i) {
            const osc  = ctx.createOscillator();
            const gain = ctx.createGain();
            osc.connect(gain);
            gain.connect(ctx.destination);
            osc.type = 'sine';
            osc.frequency.setValueAtTime(frekans, ctx.currentTime + i * 0.18);
            gain.gain.setValueAtTime(0.4, ctx.currentTime + i * 0.18);
            gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + i * 0.18 + 0.5);
            osc.start(ctx.currentTime + i * 0.18);
            osc.stop(ctx.currentTime + i * 0.18 + 0.5);
        });
    } catch (e) { console.warn('Ses çalınamadı:', e); }
}

function yeniTicketBildirim(sayi, ilkKonu) {
    sesCaldir();
    Swal.fire({
        toast: true,
        position: 'top-end',
        icon: 'info',
        title: sayi + ' yeni ticket açıldı',
        text: ilkKonu ? '"' + ilkKonu + '"' : '',
        showConfirmButton: false,
        timer: 6000,
        timerProgressBar: true,
    });
    if (Notification && Notification.permission === 'granted') {
        new Notification('🎫 Yeni Ticket', {
            body: sayi + ' yeni ticket açıldı' + (ilkKonu ? ': ' + ilkKonu : ''),
            icon: '/admin/assets/img/favicon.png'
        });
    }
}

// -------------------------------------------------------
// Yardımcılar
// -------------------------------------------------------
function escHtml(str) {
    if (!str) return '';
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function postAjax(data, callback) {
    $.post(window.location.pathname, data, function (res) {
        if (typeof res === 'string') { try { res = JSON.parse(res); } catch(e) {} }
        callback(res);
    }).fail(function () {
        Swal.fire('Hata', 'Sunucu bağlantı hatası.', 'error');
    });
}

// -------------------------------------------------------
// İstatistikler
// -------------------------------------------------------
function loadStats() {
    postAjax({ action: 'stats' }, function (res) {
        if (res.success) {
            $('#stat-toplam').text(res.data.toplam);
            $('#stat-acik').text(res.data.acik);
            $('#stat-kapali').text(res.data.kapali);
            $('#stat-acil').text(res.data.acil);
            $('#stat-cevap').text(res.data.cevap);
        }
    });
}

// -------------------------------------------------------
// Filtre seçenekleri
// -------------------------------------------------------
function loadFilterOptions() {
    postAjax({ action: 'filter_options' }, function (res) {
        if (!res.success) return;
        filterOptions = res.data;

        res.data.durumlar.forEach(function (d) {
            $('#durum-select').append(`<option value="${d.id}">${escHtml(d.ad)}</option>`);
            $('#filtre-durum-optgroup').append(`<option value="${d.id}">${escHtml(d.ad)}</option>`);
        });
        res.data.oncelikler.forEach(function (d) {
            $('#filtre-oncelik, #ayir-oncelik, #oncelik-select').append(`<option value="${d.id}">${escHtml(d.ad)}</option>`);
        });
        res.data.kategoriler.forEach(function (d) {
            $('#filtre-kategori, #ayir-kategori, #kategori-select').append(`<option value="${d.id}">${escHtml(d.ad)}</option>`);
        });
        if (IS_ADMIN) {
            res.data.personeller.forEach(function (d) {
                $('#filtre-atanan, #atama-select, #ayir-atanan').append(`<option value="${d.id}">${escHtml(d.ad)}</option>`);
            });
        }

        // Select2 modal olanlar
        $('.select2-modal').select2({ theme: 'bootstrap-5', width: '100%', dropdownParent: $('.modal.show') });
    });
}

// -------------------------------------------------------
// Liste
// -------------------------------------------------------
function loadListe() {
    $('#ticketTableBody').html('<tr><td colspan="10" class="text-center py-4"><i class="bi bi-hourglass-split me-1"></i> Yükleniyor...</td></tr>');

    const params = {
        action:      'liste',
        durum_id:    $('#filtre-durum').val()    || 'acik',
        oncelik_id:  $('#filtre-oncelik').val()  || '',
        kategori_id: $('#filtre-kategori').val() || '',
        atanan_id:   IS_ADMIN ? ($('#filtre-atanan').val() || '') : '',
        tarih_bas:   $('#filtre-tarih-bas').val() || '',
        tarih_bit:   $('#filtre-tarih-bit').val() || '',
        search:      $('#filtre-search').val()   || ''
    };

    postAjax(params, function (res) {
        if (!res.success) {
            Swal.fire('Hata', res.message, 'error');
            return;
        }

        // Yeni ticket kontrolü (yalnızca "açık" filtresi aktifken)
        const aktifDurum = $('#filtre-durum').val() || 'acik';
        if (aktifDurum === 'acik' && res.data && res.data.length > 0) {
            const gelenIds = new Set(res.data.map(r => r.Tickets_id));
            if (!_ilk_yukleme && _bilinen_ticket_ids !== null) {
                const yeniIdler = [...gelenIds].filter(id => !_bilinen_ticket_ids.has(id));
                if (yeniIdler.length > 0) {
                    const ilkYeni = res.data.find(r => yeniIdler.includes(r.Tickets_id));
                    yeniTicketBildirim(yeniIdler.length, ilkYeni ? ilkYeni.Tickets_konu : '');
                }
            }
            _bilinen_ticket_ids = gelenIds;
        } else if (aktifDurum !== 'acik') {
            _bilinen_ticket_ids = null; // farklı filtre seçiliyse sıfırla
        }
        _ilk_yukleme = false;

        ticketTable.clear().rows.add(res.data).draw();
        $('#checkAll').prop('checked', false);
        seciliGuncelle();
        loadStats();
    });
}

// -------------------------------------------------------
// Durum güncelle
// -------------------------------------------------------
function durumGuncelle(ticketId, mevcutId) {
    $('#durum-ticket-id').val(ticketId);
    $('#durum-select').val(mevcutId || '');
    const modal = new bootstrap.Modal(document.getElementById('modalDurum'));
    modal.show();
    setTimeout(function () {
        $('#durum-select').select2({ theme: 'bootstrap-5', width: '100%', dropdownParent: $('#modalDurum') });
        $('#durum-select').val(mevcutId || '').trigger('change');
    }, 300);
}

// -------------------------------------------------------
// Kategori güncelle
// -------------------------------------------------------
function kategoriGuncelle(ticketId, mevcutId) {
    $('#kategori-ticket-id').val(ticketId);
    $('#kategori-select').val(mevcutId || '');
    const modal = new bootstrap.Modal(document.getElementById('modalKategori'));
    modal.show();
    setTimeout(function () {
        $('#kategori-select').select2({ theme: 'bootstrap-5', width: '100%', dropdownParent: $('#modalKategori') });
        $('#kategori-select').val(mevcutId || '').trigger('change');
    }, 300);
}

function saveKategori() {
    const ticketId   = $('#kategori-ticket-id').val();
    const kategoriId = $('#kategori-select').val();
    if (!kategoriId) { Swal.fire('Uyarı', 'Lütfen bir kategori seçin.', 'warning'); return; }

    postAjax({ action: 'kategori_guncelle', ticket_id: ticketId, kategori_id: kategoriId }, function (res) {
        if (res.success) {
            bootstrap.Modal.getInstance(document.getElementById('modalKategori')).hide();
            Swal.fire({ icon: 'success', title: 'Güncellendi', timer: 1500, showConfirmButton: false });
            loadListe();
        } else {
            Swal.fire('Hata', res.message, 'error');
        }
    });
}

// -------------------------------------------------------
// Öncelik güncelle
// -------------------------------------------------------
function oncelikGuncelle(ticketId, mevcutId) {
    $('#oncelik-ticket-id').val(ticketId);
    $('#oncelik-select').val(mevcutId || '');
    const modal = new bootstrap.Modal(document.getElementById('modalOncelik'));
    modal.show();
    setTimeout(function () {
        $('#oncelik-select').select2({ theme: 'bootstrap-5', width: '100%', dropdownParent: $('#modalOncelik') });
        $('#oncelik-select').val(mevcutId || '').trigger('change');
    }, 300);
}

function saveOncelik() {
    const ticketId  = $('#oncelik-ticket-id').val();
    const oncelikId = $('#oncelik-select').val();
    if (!oncelikId) { Swal.fire('Uyarı', 'Lütfen bir öncelik seçin.', 'warning'); return; }

    postAjax({ action: 'oncelik_guncelle', ticket_id: ticketId, oncelik_id: oncelikId }, function (res) {
        if (res.success) {
            bootstrap.Modal.getInstance(document.getElementById('modalOncelik')).hide();
            Swal.fire({ icon: 'success', title: 'Güncellendi', timer: 1500, showConfirmButton: false });
            loadListe();
        } else {
            Swal.fire('Hata', res.message, 'error');
        }
    });
}

function saveDurum() {
    const ticketId = $('#durum-ticket-id').val();
    const durumId  = $('#durum-select').val();
    if (!durumId) { Swal.fire('Uyarı', 'Lütfen bir durum seçin.', 'warning'); return; }

    postAjax({ action: 'durum_guncelle', ticket_id: ticketId, durum_id: durumId }, function (res) {
        if (res.success) {
            bootstrap.Modal.getInstance(document.getElementById('modalDurum')).hide();
            Swal.fire({ icon: 'success', title: 'Güncellendi', timer: 1500, showConfirmButton: false });
            loadListe();
        } else {
            Swal.fire('Hata', res.message, 'error');
        }
    });
}

// -------------------------------------------------------
// Atama güncelle
// -------------------------------------------------------
function atama(ticketId, mevcutId) {
    $('#atama-ticket-id').val(ticketId);
    $('#atama-select').val(mevcutId || '');
    const modal = new bootstrap.Modal(document.getElementById('modalAtama'));
    modal.show();
    setTimeout(function () {
        $('#atama-select').select2({ theme: 'bootstrap-5', width: '100%', dropdownParent: $('#modalAtama') });
        $('#atama-select').val(mevcutId || '').trigger('change');
    }, 300);
}

function saveAtama() {
    const ticketId    = $('#atama-ticket-id').val();
    const kullaniciId = $('#atama-select').val();

    postAjax({ action: 'atama_guncelle', ticket_id: ticketId, kullanici_id: kullaniciId }, function (res) {
        if (res.success) {
            bootstrap.Modal.getInstance(document.getElementById('modalAtama')).hide();
            Swal.fire({ icon: 'success', title: 'Atama güncellendi', timer: 1500, showConfirmButton: false });
            loadListe();
        } else {
            Swal.fire('Hata', res.message, 'error');
        }
    });
}

// -------------------------------------------------------
// Ticket sil
// -------------------------------------------------------
function ticketSil(ticketId) {
    Swal.fire({
        title: 'Emin misiniz?',
        text: 'Bu ticket silinecek.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Evet, Sil',
        cancelButtonText: 'İptal',
        confirmButtonColor: '#dc3545'
    }).then(function (result) {
        if (result.isConfirmed) {
            postAjax({ action: 'ticket_sil', ticket_id: ticketId }, function (res) {
                if (res.success) {
                    Swal.fire({ icon: 'success', title: 'Silindi', timer: 1500, showConfirmButton: false });
                    loadListe();
                } else {
                    Swal.fire('Hata', res.message, 'error');
                }
            });
        }
    });
}

// -------------------------------------------------------
// Checkbox yönetimi
// -------------------------------------------------------
$(document).on('change', '#checkAll', function() {
    const checked = $(this).prop('checked');
    $('.ticket-check').prop('checked', checked);
    seciliGuncelle();
});

$(document).on('change', '.ticket-check', function() {
    const total = $('.ticket-check').length;
    const checked = $('.ticket-check:checked').length;
    $('#checkAll').prop('checked', total === checked && total > 0);
    seciliGuncelle();
});

function seciliGuncelle() {
    const sayi = $('.ticket-check:checked').length;
    $('#seciliSayi').text(sayi);
    $('#btnBirlestir').toggle(sayi >= 2);
    $('#btnAyir').toggle(sayi === 1);
}

// -------------------------------------------------------
// Ticket Birleştirme
// -------------------------------------------------------
function ticketBirlestirModalAc() {
    const secililar = [];
    $('.ticket-check:checked').each(function() {
        secililar.push({
            id: $(this).val(),
            no: $(this).data('no'),
            konu: $(this).data('konu')
        });
    });

    if (secililar.length < 2) {
        Swal.fire('Uyarı', 'En az 2 ticket seçmelisiniz.', 'warning');
        return;
    }

    let radioRows = secililar.map((t, i) => 
        `<div class="form-check mb-2 p-2 border rounded ${i === 0 ? 'bg-light' : ''}">
            <input class="form-check-input" type="radio" name="anaTicket" id="anaT_${t.id}" value="${t.id}" ${i === 0 ? 'checked' : ''}>
            <label class="form-check-label w-100" for="anaT_${t.id}">
                <span class="ticket-no fw-bold">${escHtml(t.no)}</span>
                <span class="ms-2">${escHtml(t.konu)}</span>
            </label>
        </div>`
    ).join('');

    Swal.fire({
        title: '<i class="bi bi-union me-1"></i> Ticket Birleştirme',
        html: `
            <div class="text-start">
                <div class="alert alert-info mb-3">
                    <i class="bi bi-info-circle-fill me-1"></i>
                    <strong>${secililar.length} ticket</strong> seçildi. Ana ticket'\u0131 seçin, diğer ticketların mesajları bu ticket'a taşınacaktır.
                </div>
                <label class="form-label fw-bold mb-2">Ana Ticket (hedef):</label>
                ${radioRows}
                <div class="alert alert-warning mt-3 mb-0">
                    <i class="bi bi-exclamation-triangle-fill me-1"></i>
                    <small>Diğer ticketların <strong>mesajları</strong> ve <strong>ekleri</strong> ana ticket'a taşınacak,
                    oluşturanlar <strong>CC</strong>'ye eklenecek ve kaynak ticketlar <strong>kapatılacaktır</strong>.</small>
                </div>
            </div>
        `,
        icon: null,
        showCancelButton: true,
        confirmButtonText: '<i class="bi bi-union me-1"></i> Birleştir',
        cancelButtonText: 'İptal',
        confirmButtonColor: '#17a2b8',
        width: '600px',
        preConfirm: () => {
            return $('input[name="anaTicket"]:checked').val();
        }
    }).then(result => {
        if (result.isConfirmed) {
            const anaId = result.value;
            const kaynakIds = secililar.filter(t => t.id != anaId).map(t => t.id);
            ticketBirlestirUygula(anaId, kaynakIds);
        }
    });
}

function ticketBirlestirUygula(anaId, kaynakIds) {
    Swal.fire({ title: 'Birleştiriliyor...', text: 'Lütfen bekleyin.', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

    const fd = new FormData();
    fd.append('action', 'ticket_birlestir');
    fd.append('ana_ticket_id', anaId);
    kaynakIds.forEach(id => fd.append('kaynak_ids[]', id));

    $.ajax({
        url: window.location.pathname,
        type: 'POST',
        data: fd,
        processData: false,
        contentType: false,
        success: function(res) {
            if (typeof res === 'string') { try { res = JSON.parse(res); } catch(e) {} }
            if (res.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'Birleştirildi!',
                    text: res.message,
                    confirmButtonText: 'Ana Ticket\'a Git',
                    showCancelButton: true,
                    cancelButtonText: 'Listede Kal'
                }).then(r => {
                    if (r.isConfirmed) {
                        window.location.href = '/admin/ticket-detay?id=' + res.ana_ticket_id;
                    } else {
                        loadListe();
                    }
                });
            } else {
                Swal.fire('Hata', res.message, 'error');
            }
        },
        error: function() {
            Swal.fire('Hata', 'Sunucu bağlantı hatası.', 'error');
        }
    });
}

// -------------------------------------------------------
// Ticket Ayırma
// -------------------------------------------------------
function ticketAyirModalAc() {
    const secili = $('.ticket-check:checked');
    if (secili.length !== 1) {
        Swal.fire('Uyarı', 'Ayırmak için tek bir talep seçmelisiniz.', 'warning');
        return;
    }

    const ticketId = secili.val();
    const ticketNo = secili.data('no');
    const konu     = secili.data('konu');

    $('#ayir-ticket-id').val(ticketId);
    $('#ayir-kaynak-bilgi').html('<strong>' + escHtml(ticketNo) + '</strong>');
    $('#ayir-konu').val(konu + ' (Ayrıldı)');
    $('#ayir-secili-sayi').text('0');
    $('#ayir-mesaj-liste').html('<div class="text-center text-muted py-4"><i class="bi bi-hourglass-split me-1"></i> Yükleniyor...</div>');

    const modal = new bootstrap.Modal(document.getElementById('modalAyir'));
    modal.show();

    postAjax({ action: 'ticket_ayir_mesajlar', ticket_id: ticketId }, function (res) {
        if (!res.success) {
            Swal.fire('Hata', res.message, 'error');
            return;
        }

        $('#ayir-kategori').val(res.ticket.Tickets_kategori_id || '').trigger('change');
        $('#ayir-oncelik').val(res.ticket.Tickets_oncelik_id || '').trigger('change');
        $('#ayir-atanan').val(res.ticket.Tickets_atanan_kullanici_id || '').trigger('change');

        $('#ayir-mesaj-liste').html(ayirMesajlariRender(res.mesajlar));
        ayirSayiGuncelle();

        setTimeout(function () {
            $('#ayir-kategori, #ayir-oncelik, #ayir-atanan').select2({
                theme: 'bootstrap-5', width: '100%', dropdownParent: $('#modalAyir')
            });
        }, 300);
    });
}

function ayirMesajlariRender(mesajlar) {
    if (!mesajlar || mesajlar.length === 0) {
        return '<div class="alert alert-warning mb-0">Bu talepte ayrılabilecek mesaj yok.</div>';
    }

    return mesajlar.map(function (m) {
        const id      = m.Ticket_Mesajlar_id;
        const metin   = m.Ticket_Mesajlar_mesaj || '';
        const icNot   = parseInt(m.Ticket_Mesajlar_ic_not) === 1;
        const ekSayi  = parseInt(m.ek_sayisi) || 0;
        const kisi    = ((m.kullanici_ad || '') + ' ' + (m.kullanici_soyad || '')).trim() || 'Bilinmiyor';
        const onizle  = metin.length > 180 ? metin.substring(0, 180) + '...' : metin;

        const ekBadge  = ekSayi > 0 ? `<span class="badge text-bg-secondary ms-1"><i class="bi bi-paperclip"></i> ${ekSayi}</span>` : '';
        const notBadge = icNot ? '<span class="badge text-bg-warning ms-1">İç Not</span>' : '';

        const ekSecim = ekSayi > 0 ? `
            <div class="mt-2 p-2 bg-light border rounded">
                <span class="small fw-bold me-2"><i class="bi bi-paperclip"></i> ${ekSayi} ek nereye gitsin?</span>
                <div class="form-check form-check-inline">
                    <input class="form-check-input ayir-ek" type="radio" name="ayirEk_${id}" id="ayirEkY_${id}" value="yeni" checked>
                    <label class="form-check-label small" for="ayirEkY_${id}">Yeni talep</label>
                </div>
                <div class="form-check form-check-inline">
                    <input class="form-check-input ayir-ek" type="radio" name="ayirEk_${id}" id="ayirEkK_${id}" value="kaynak">
                    <label class="form-check-label small" for="ayirEkK_${id}">Bu talepte kalsın</label>
                </div>
            </div>` : '';

        return `
        <div class="card mb-2 ayir-mesaj" data-id="${id}">
            <div class="card-header py-2">
                <div class="form-check form-switch mb-0">
                    <input class="form-check-input ayir-sec" type="checkbox" role="switch" id="ayirSec_${id}">
                    <label class="form-check-label" for="ayirSec_${id}">
                        <strong>${escHtml(kisi)}</strong>
                        <span class="text-muted small ms-1">${escHtml(m.tarih || '')}</span>
                        ${notBadge}${ekBadge}
                    </label>
                </div>
            </div>
            <div class="card-body py-2">
                <div class="ayir-onizleme text-muted small" style="white-space:pre-wrap">${escHtml(onizle)}</div>

                <div class="ayir-detay" style="display:none">
                    <div class="mb-2">
                        <div class="form-check form-check-inline">
                            <input class="form-check-input ayir-mod" type="radio" name="ayirMod_${id}" id="ayirModT_${id}" value="tam" checked>
                            <label class="form-check-label small" for="ayirModT_${id}">Mesajın tamamını taşı</label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input ayir-mod" type="radio" name="ayirMod_${id}" id="ayirModB_${id}" value="bol">
                            <label class="form-check-label small" for="ayirModB_${id}">İçeriği böl</label>
                        </div>
                    </div>

                    <div class="ayir-tam-alan">
                        <label class="form-label small fw-bold mb-1">Yeni talebe gidecek metin</label>
                        <textarea class="form-control form-control-sm ayir-tam-metin" rows="4">${escHtml(metin)}</textarea>
                    </div>

                    <div class="ayir-bol-alan" style="display:none">
                        <label class="form-label small fw-bold mb-1">Orijinal metin — ayırmak istediğiniz kısmı seçin</label>
                        <textarea class="form-control form-control-sm ayir-orijinal mb-1" rows="4">${escHtml(metin)}</textarea>
                        <button type="button" class="btn btn-outline-warning btn-sm mb-2 ayir-secimi-aktar">
                            <i class="bi bi-arrow-down-square me-1"></i> Seçili kısmı yeni talebe aktar
                        </button>
                        <div class="row g-2">
                            <div class="col-12 col-md-6">
                                <label class="form-label small fw-bold mb-1 text-warning-emphasis">
                                    <i class="bi bi-scissors"></i> Yeni talebe gidecek
                                </label>
                                <textarea class="form-control form-control-sm ayir-yeni-metin" rows="5"></textarea>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label small fw-bold mb-1">
                                    <i class="bi bi-inbox"></i> Bu talepte kalacak
                                </label>
                                <textarea class="form-control form-control-sm ayir-kalan-metin" rows="5"></textarea>
                            </div>
                        </div>
                    </div>

                    ${ekSecim}
                </div>
            </div>
        </div>`;
    }).join('');
}

// Mesaj seçimi
$(document).on('change', '.ayir-sec', function () {
    const kart = $(this).closest('.ayir-mesaj');
    const acik = $(this).prop('checked');
    kart.find('.ayir-detay').toggle(acik);
    kart.find('.ayir-onizleme').toggle(!acik);
    kart.toggleClass('border-warning', acik);
    ayirSayiGuncelle();
});

// Mod değişimi
$(document).on('change', '.ayir-mod', function () {
    const kart = $(this).closest('.ayir-mesaj');
    const bol  = $(this).val() === 'bol';
    kart.find('.ayir-bol-alan').toggle(bol);
    kart.find('.ayir-tam-alan').toggle(!bol);

    // Böl moduna ilk geçişte kalan alanı orijinal metinle doldur
    if (bol && kart.find('.ayir-kalan-metin').val() === '' && kart.find('.ayir-yeni-metin').val() === '') {
        kart.find('.ayir-kalan-metin').val(kart.find('.ayir-orijinal').val());
    }
});

// Seçili kısmı aktar
$(document).on('click', '.ayir-secimi-aktar', function () {
    const kart = $(this).closest('.ayir-mesaj');
    const ta   = kart.find('.ayir-orijinal')[0];
    const bas  = ta.selectionStart;
    const bit  = ta.selectionEnd;

    if (bas === bit) {
        Swal.fire('Uyarı', 'Önce metin içinden ayırmak istediğiniz kısmı seçin.', 'warning');
        return;
    }

    const tum    = ta.value;
    const secili = tum.substring(bas, bit).trim();
    const kalan  = (tum.substring(0, bas) + tum.substring(bit)).replace(/\n{3,}/g, '\n\n').trim();

    kart.find('.ayir-yeni-metin').val(secili);
    kart.find('.ayir-kalan-metin').val(kalan);
});

function ayirSayiGuncelle() {
    $('#ayir-secili-sayi').text($('.ayir-sec:checked').length);
}

$(document).on('click', '#btnAyirKaydet', function () {
    const ticketId = $('#ayir-ticket-id').val();
    const konu     = $('#ayir-konu').val().trim();

    if (konu === '') {
        Swal.fire('Uyarı', 'Yeni talep konusu boş olamaz.', 'warning');
        return;
    }

    const parcalar = [];
    let hata = '';
    let tamTasinan = 0;

    $('.ayir-sec:checked').each(function () {
        const kart = $(this).closest('.ayir-mesaj');
        const id   = kart.data('id');
        const mod  = kart.find('.ayir-mod:checked').val();
        const ek   = kart.find('.ayir-ek:checked').val() || 'yeni';

        if (mod === 'bol') {
            const yeni  = kart.find('.ayir-yeni-metin').val().trim();
            const kalan = kart.find('.ayir-kalan-metin').val().trim();
            if (yeni === '' || kalan === '') {
                hata = 'Bölünen mesajlarda hem "yeni talebe gidecek" hem "bu talepte kalacak" metin dolu olmalıdır.';
                return false;
            }
            parcalar.push({ id: id, metin: yeni, kalan: kalan, ekler: ek });
        } else {
            const metin = kart.find('.ayir-tam-metin').val().trim();
            if (metin === '') {
                hata = 'Taşınacak mesaj metni boş olamaz.';
                return false;
            }
            tamTasinan++;
            parcalar.push({ id: id, metin: metin, kalan: '', ekler: ek });
        }
    });

    if (hata !== '') { Swal.fire('Uyarı', hata, 'warning'); return; }
    if (parcalar.length === 0) { Swal.fire('Uyarı', 'En az bir mesaj seçmelisiniz.', 'warning'); return; }
    if (tamTasinan >= $('.ayir-mesaj').length) {
        Swal.fire('Uyarı', 'Tüm mesajları taşıyamazsınız, kaynak talepte en az bir mesaj kalmalıdır.', 'warning');
        return;
    }

    Swal.fire({
        title: 'Talep ayrılsın mı?',
        html: `<strong>${parcalar.length} mesaj</strong> yeni bir talebe taşınacak.`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Evet, Ayır',
        cancelButtonText: 'İptal',
        confirmButtonColor: '#ffc107'
    }).then(function (r) {
        if (!r.isConfirmed) return;

        Swal.fire({ title: 'Ayrılıyor...', text: 'Lütfen bekleyin.', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

        const fd = new FormData();
        fd.append('action', 'ticket_ayir');
        fd.append('ticket_id', ticketId);
        fd.append('yeni_konu', konu);
        fd.append('kategori_id', $('#ayir-kategori').val() || '');
        fd.append('oncelik_id', $('#ayir-oncelik').val() || '');
        fd.append('atanan_id', $('#ayir-atanan').val() || '');
        fd.append('parcalar', JSON.stringify(parcalar));

        $.ajax({
            url: window.location.pathname,
            type: 'POST',
            data: fd,
            processData: false,
            contentType: false,
            success: function (res) {
                if (typeof res === 'string') { try { res = JSON.parse(res); } catch (e) {} }
                if (res.success) {
                    bootstrap.Modal.getInstance(document.getElementById('modalAyir')).hide();
                    Swal.fire({
                        icon: 'success',
                        title: 'Ayrıldı!',
                        text: res.message,
                        confirmButtonText: 'Yeni Talebe Git',
                        showCancelButton: true,
                        cancelButtonText: 'Listede Kal'
                    }).then(function (r2) {
                        if (r2.isConfirmed) {
                            window.location.href = '/admin/ticket-detay?id=' + res.yeni_ticket_id;
                        } else {
                            loadListe();
                        }
                    });
                } else {
                    Swal.fire('Hata', res.message, 'error');
                }
            },
            error: function () {
                Swal.fire('Hata', 'Sunucu bağlantı hatası.', 'error');
            }
        });
    });
});
</script>
</body>
</html>
