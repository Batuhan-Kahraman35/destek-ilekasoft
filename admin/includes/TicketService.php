<?php
/**
 * Ticket servis sınıfı
 */

require_once __DIR__ . '/../db.php';

class TicketService
{
    private Database $db;

    private const SISTEM_KULLANICI_ID = 0;
    private const MAKS_DOSYA_BOYUTU = 10485760; // 10 MB
    private const IZINLI_MIME = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'text/plain',
    ];

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?: Database::getInstance();
    }

    private function baseUrl(): string
    {
        $protoHeader = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
        if ($protoHeader !== '') {
            $scheme = explode(',', $protoHeader)[0];
            $scheme = trim($scheme);
        } else {
            $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') === '443');
            $scheme = $https ? 'https' : 'http';
        }

        $host = trim((string)($_SERVER['HTTP_X_FORWARDED_HOST'] ?? ''));
        if ($host === '') {
            $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
        }
        if ($host === '') {
            return '';
        }

        return $scheme . '://' . $host;
    }

    private function buildFileUrl(?string $relativePath): ?string
    {
        $path = trim((string)$relativePath);
        if ($path === '') {
            return null;
        }

        $base = $this->baseUrl();
        if ($base === '') {
            return null;
        }

        return $base . '/' . ltrim(str_replace('\\', '/', $path), '/');
    }

    public function getKaynakByApiKey(string $apiKey): ?array
    {
        if ($apiKey === '') {
            return null;
        }

        return $this->db->fetchOne(
            "SELECT TOP 1 * FROM Ticket_Kaynak WHERE Ticket_Kaynak_api_key = ? AND Durum = 1",
            [$apiKey]
        );
    }

    /**
     * E-posta ile kullanıcı bul veya oluştur.
     * create_ticket action'da kullanılır.
     */
    public function kullaniciBulVeyaOlustur(array $kullanici, ?int $islemKullaniciId = null): int
    {
        $ad = trim((string)($kullanici['ad'] ?? ''));
        $soyad = trim((string)($kullanici['soyad'] ?? ''));
        $eposta = trim((string)($kullanici['eposta'] ?? ''));
        $telefon = trim((string)($kullanici['telefon'] ?? ''));
        $now = app_now();
        $islemKullaniciId = $islemKullaniciId ?? self::SISTEM_KULLANICI_ID;

        if ($ad === '' || $eposta === '') {
            throw new Exception('Kullanıcı için ad ve eposta zorunludur.');
        }

        // E-posta ile ara
        $mevcut = $this->db->fetchOne(
            "SELECT TOP 1 kullanici_id
             FROM kullanicilar
             WHERE kullanici_email = ?
             ORDER BY kullanici_id ASC",
            [$eposta]
        );

        if ($mevcut) {
            $kullaniciId = (int)$mevcut['kullanici_id'];
            $this->db->update('kullanicilar', [
                'kullanici_ad' => $ad,
                'kullanici_soyad' => $soyad !== '' ? $soyad : null,
                'kullanici_telefon' => $telefon !== '' ? $telefon : null,
                'kullanici_guncelleme_tarihi' => $now,
                'kullanici_guncelleyen_id' => $islemKullaniciId,
            ], [
                'kullanici_id' => $kullaniciId,
            ]);

            return $kullaniciId;
        }

        // Yeni kullanıcı oluştur
        $dummySifre = password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT);
        return (int)$this->db->insert('kullanicilar', [
            'kullanici_email' => $eposta,
            'kullanici_sifre_hash' => $dummySifre,
            'kullanici_ad' => $ad,
            'kullanici_soyad' => $soyad !== '' ? $soyad : null,
            'kullanici_telefon' => $telefon !== '' ? $telefon : null,
            'kullanici_durum' => 1,
            'kullanici_tip' => 'harici',
            'kullanici_olusturma_tarihi' => $now,
            'kullanici_olusturan_id' => $islemKullaniciId,
            'kullanici_guncelleme_tarihi' => $now,
            'kullanici_guncelleyen_id' => $islemKullaniciId,
            'kullanici_sifre_degistirmeli' => 0,
        ]);
    }

    /**
     * E-posta ile dahili kullanıcı ID bulur.
     */
    public function kullaniciBulByEposta(string $eposta): ?int
    {
        $eposta = trim($eposta);
        if ($eposta === '') {
            return null;
        }

        $row = $this->db->fetchOne(
            "SELECT TOP 1 kullanici_id FROM kullanicilar WHERE kullanici_email = ? ORDER BY kullanici_id ASC",
            [$eposta]
        );

        return $row ? (int)$row['kullanici_id'] : null;
    }

    public function ticketOlustur(array $data): array
    {
        $konu = trim((string)($data['konu'] ?? ''));
        $ilkMesaj = trim((string)($data['mesaj'] ?? ($data['aciklama'] ?? '')));
        $kategoriId = (int)($data['kategori_id'] ?? 0);
        $oncelikId = (int)($data['oncelik_id'] ?? 0);
        $atananId = !empty($data['atanan_id']) ? (int)$data['atanan_id'] : null;
        $olusturanKullaniciId = array_key_exists('olusturan_kullanici_id', $data)
            ? (int)$data['olusturan_kullanici_id']
            : self::SISTEM_KULLANICI_ID;
        $islemKullaniciId = array_key_exists('islem_kullanici_id', $data)
            ? (int)$data['islem_kullanici_id']
            : $olusturanKullaniciId;
        $kaynakId = !empty($data['kaynak_id']) ? (int)$data['kaynak_id'] : null;
        $kaynakKullaniciId = !empty($data['kaynak_kullanici_id']) ? (int)$data['kaynak_kullanici_id'] : null;

        if ($konu === '') {
            throw new Exception('Konu boş olamaz.');
        }
        if ($ilkMesaj === '') {
            throw new Exception('Açıklama boş olamaz.');
        }
        if ($kategoriId <= 0) {
            throw new Exception('Kategori seçiniz.');
        }
        if ($oncelikId <= 0) {
            throw new Exception('Öncelik seçiniz.');
        }

        $kat = $this->db->fetchOne(
            "SELECT Ticket_Kategoriler_id FROM Ticket_Kategoriler WHERE Ticket_Kategoriler_id = ? AND Durum = 1",
            [$kategoriId]
        );
        if (!$kat) {
            throw new Exception('Geçersiz kategori.');
        }

        $onc = $this->db->fetchOne(
            "SELECT Ticket_Oncelikler_id FROM Ticket_Oncelikler WHERE Ticket_Oncelikler_id = ? AND Durum = 1",
            [$oncelikId]
        );
        if (!$onc) {
            throw new Exception('Geçersiz öncelik.');
        }

        $ilkDurum = $this->db->fetchOne(
            "SELECT TOP 1 Ticket_Durumlar_id
             FROM Ticket_Durumlar
             WHERE Durum = 1 AND Ticket_Durumlar_kapatma_durumu = 0
             ORDER BY Ticket_Durumlar_sira ASC"
        );
        if (!$ilkDurum) {
            throw new Exception('Aktif durum tanımı bulunamadı.');
        }

        $now = !empty($data['olusturma_tarihi']) ? trim((string)$data['olusturma_tarihi']) : app_now();
        $tarih = date('Ymd', strtotime($now));
        $sayac = $this->db->fetchOne(
            "SELECT COUNT(*) AS sayi FROM Tickets WHERE CONVERT(VARCHAR(8), OlusturmaTarihi, 112) = ?",
            [$tarih]
        );
        $ticketNo = 'TKT-' . $tarih . '-' . str_pad((string)(($sayac['sayi'] ?? 0) + 1), 4, '0', STR_PAD_LEFT);

        $insertData = [
            'Tickets_no' => $ticketNo,
            'Tickets_konu' => $konu,
            'Tickets_kategori_id' => $kategoriId,
            'Tickets_oncelik_id' => $oncelikId,
            'Tickets_durum_id' => (int)$ilkDurum['Ticket_Durumlar_id'],
            'Tickets_atanan_kullanici_id' => $atananId,
            'Tickets_olusturan_kullanici_id' => $olusturanKullaniciId,
            'OlusturmaTarihi' => $now,
            'GuncelleyenKullanici' => $islemKullaniciId,
            'GuncellemeTarihi' => $now,
            'Durum' => 1,
        ];

        if (!empty($data['bildirim_yolu_id'])) {
            $insertData['Tickets_bildirim_yolu_id'] = (int)$data['bildirim_yolu_id'];
        }
        if ($kaynakId !== null) {
            $insertData['Tickets_kaynak_id'] = $kaynakId;
        }

        $ticketId = (int)$this->db->insert('Tickets', $insertData);

        $ilkMesajId = (int)$this->db->insert('Ticket_Mesajlar', [
            'Ticket_Mesajlar_ticket_id' => $ticketId,
            'Ticket_Mesajlar_mesaj' => $ilkMesaj,
            'Ticket_Mesajlar_ic_not' => 0,
            'Ticket_Mesajlar_yazan_kul_id' => $olusturanKullaniciId,
            'Ticket_Mesajlar_kaynak_kullanici_id' => $kaynakKullaniciId,
            'Ticket_Mesajlar_yazan_ad' => trim((string)($data['yazan_ad'] ?? '')) !== '' ? trim((string)$data['yazan_ad']) : null,
            'Ticket_Mesajlar_yazan_soyad' => trim((string)($data['yazan_soyad'] ?? '')) !== '' ? trim((string)$data['yazan_soyad']) : null,
            'Ticket_Mesajlar_kaynak' => trim((string)($data['mesaj_kaynak'] ?? 'panel')) !== '' ? trim((string)$data['mesaj_kaynak']) : 'panel',
            'OlusturanKullanici' => $islemKullaniciId,
            'OlusturmaTarihi' => $now,
            'GuncelleyenKullanici' => $islemKullaniciId,
            'GuncellemeTarihi' => $now,
            'Durum' => 1,
        ]);

        $dosyaSonuc = $this->kaydetDosyaEkleri(
            $ticketId,
            $ilkMesajId,
            is_array($data['dosyalar'] ?? null) ? $data['dosyalar'] : [],
            $islemKullaniciId
        );

        // Bildirimleri tetikle
        $this->bildirimGonder(
            $ticketId,
            'yeni_ticket',
            $olusturanKullaniciId,
            $data['yazan_ad'] ?? null,
            $data['yazan_soyad'] ?? null,
            $data['mesaj_kaynak'] ?? 'panel',
            $ilkMesaj
        );

        return [
            'ticket_id' => $ticketId,
            'ticket_no' => $ticketNo,
            'ek_sayisi' => $dosyaSonuc['ek_sayisi'],
            'ekler' => $dosyaSonuc['ekler'],
        ];
    }

    public function ticketMesajEkle(array $data): array
    {
        $ticketId = (int)($data['ticket_id'] ?? 0);
        $mesaj = trim((string)($data['mesaj'] ?? ''));
        $icNot = !empty($data['ic_not']) ? 1 : 0;
        $yazanKullaniciId = array_key_exists('yazan_kullanici_id', $data)
            ? (int)$data['yazan_kullanici_id']
            : self::SISTEM_KULLANICI_ID;
        $islemKullaniciId = array_key_exists('islem_kullanici_id', $data)
            ? (int)$data['islem_kullanici_id']
            : $yazanKullaniciId;
        $kaynakKullaniciId = !empty($data['kaynak_kullanici_id']) ? (int)$data['kaynak_kullanici_id'] : null;
        $yazanAd = trim((string)($data['yazan_ad'] ?? ''));
        $yazanSoyad = trim((string)($data['yazan_soyad'] ?? ''));
        $mesajKaynak = trim((string)($data['mesaj_kaynak'] ?? 'panel'));

        if ($ticketId <= 0) {
            throw new Exception('Geçersiz ticket.');
        }
        if ($mesaj === '') {
            throw new Exception('Mesaj boş olamaz.');
        }

        $ticket = $this->db->fetchOne("SELECT Tickets_id FROM Tickets WHERE Tickets_id = ? AND Durum = 1", [$ticketId]);
        if (!$ticket) {
            throw new Exception('Ticket bulunamadı.');
        }

        $now = app_now();

        $mesajId = (int)$this->db->insert('Ticket_Mesajlar', [
            'Ticket_Mesajlar_ticket_id' => $ticketId,
            'Ticket_Mesajlar_mesaj' => $mesaj,
            'Ticket_Mesajlar_ic_not' => $icNot,
            'Ticket_Mesajlar_yazan_kul_id' => $yazanKullaniciId,
            'Ticket_Mesajlar_kaynak_kullanici_id' => $kaynakKullaniciId,
            'Ticket_Mesajlar_yazan_ad' => $yazanAd !== '' ? $yazanAd : null,
            'Ticket_Mesajlar_yazan_soyad' => $yazanSoyad !== '' ? $yazanSoyad : null,
            'Ticket_Mesajlar_kaynak' => $mesajKaynak !== '' ? $mesajKaynak : 'panel',
            'OlusturanKullanici' => $islemKullaniciId,
            'OlusturmaTarihi' => $now,
            'GuncelleyenKullanici' => $islemKullaniciId,
            'GuncellemeTarihi' => $now,
            'Durum' => 1,
        ]);

        $ticketUpdate = [
            'Tickets_son_yanit_tarihi' => $now,
            'GuncelleyenKullanici' => $islemKullaniciId,
            'GuncellemeTarihi' => $now,
        ];

        // Karşı taraftan (panel dışı kaynak) gelen cevap: durumu "müşteri cevabı"
        // bayraklı duruma çek ve listede işaretlensin diye bayrağı 1 yap.
        if (!$icNot && $mesajKaynak !== 'panel') {
            $cevapDurumu = $this->db->fetchOne(
                "SELECT TOP 1 Ticket_Durumlar_id FROM Ticket_Durumlar
                 WHERE Ticket_Durumlar_musteri_cevabi = 1 AND Durum = 1
                 ORDER BY Ticket_Durumlar_sira"
            );
            if ($cevapDurumu) {
                $ticketUpdate['Tickets_durum_id'] = (int)$cevapDurumu['Ticket_Durumlar_id'];
                $ticketUpdate['Tickets_kapanis_tarihi'] = null;
            }
            $ticketUpdate['Tickets_musteri_cevap_var'] = 1;
        }

        $this->db->update('Tickets', $ticketUpdate, [
            'Tickets_id' => $ticketId,
        ]);

        $dosyaSonuc = $this->kaydetDosyaEkleri(
            $ticketId,
            $mesajId,
            is_array($data['dosyalar'] ?? null) ? $data['dosyalar'] : [],
            $islemKullaniciId
        );

        // İç not değilse bildirimleri tetikle
        if (!$icNot) {
            $this->bildirimGonder(
                $ticketId,
                'yeni_mesaj',
                $yazanKullaniciId,
                $yazanAd,
                $yazanSoyad,
                $mesajKaynak,
                $mesaj
            );
        }

        return [
            'mesaj_id' => $mesajId,
            'tarih' => $now,
            'ek_sayisi' => $dosyaSonuc['ek_sayisi'],
            'ekler' => $dosyaSonuc['ekler'],
        ];
    }

    /**
     * @param array<int,array{name?:string,tmp_name?:string,size?:int,error?:int,type?:string,dosya_adi?:string,mime_turu?:string,icerik_base64?:string,content_base64?:string}> $dosyalar
     */
    private function kaydetDosyaEkleri(int $ticketId, ?int $mesajId, array $dosyalar, int $islemKullaniciId): array
    {
        if ($ticketId <= 0 || empty($dosyalar)) {
            return ['ek_sayisi' => 0, 'ekler' => []];
        }

        $uploadDir = __DIR__ . '/../../assets/uploads/tickets/' . $ticketId . '/';
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            throw new Exception('Dosya klasoru olusturulamadi.');
        }

        $now = app_now();
        $ekSayisi = 0;
        $ekler = [];

        foreach ($dosyalar as $dosya) {
            $dosyaAdi = trim((string)($dosya['name'] ?? ($dosya['dosya_adi'] ?? '')));
            $tmpName = (string)($dosya['tmp_name'] ?? '');
            $dosyaBoyutu = (int)($dosya['size'] ?? 0);
            $hata = (int)($dosya['error'] ?? UPLOAD_ERR_NO_FILE);
            $base64Icerik = trim((string)($dosya['icerik_base64'] ?? ($dosya['content_base64'] ?? '')));
            $isJsonEk = $base64Icerik !== '';

            if ($dosyaAdi === '') {
                continue;
            }

            $mime = '';
            $yazildi = false;

            $ext = strtolower((string)pathinfo($dosyaAdi, PATHINFO_EXTENSION));
            $ext = preg_replace('/[^a-z0-9]/', '', $ext);
            $guvenliAd = bin2hex(random_bytes(8)) . ($ext !== '' ? ('.' . $ext) : '');
            $hedefYol = $uploadDir . $guvenliAd;

            if ($isJsonEk) {
                if (strpos($base64Icerik, 'base64,') !== false) {
                    $base64Icerik = (string)substr($base64Icerik, strpos($base64Icerik, 'base64,') + 7);
                }

                $binary = base64_decode($base64Icerik, true);
                if ($binary === false) {
                    continue;
                }

                $dosyaBoyutu = strlen($binary);
                if ($dosyaBoyutu <= 0 || $dosyaBoyutu > self::MAKS_DOSYA_BOYUTU) {
                    continue;
                }

                $mime = trim((string)($dosya['mime_turu'] ?? ($dosya['type'] ?? '')));
                if ($mime === '' && function_exists('finfo_buffer')) {
                    $finfo = finfo_open(FILEINFO_MIME_TYPE);
                    if ($finfo !== false) {
                        $detected = finfo_buffer($finfo, $binary);
                        if (is_string($detected)) {
                            $mime = $detected;
                        }
                        finfo_close($finfo);
                    }
                }

                if (!in_array($mime, self::IZINLI_MIME, true)) {
                    continue;
                }

                $yazildi = file_put_contents($hedefYol, $binary) !== false;
            } else {
                if ($hata !== UPLOAD_ERR_OK || $tmpName === '') {
                    continue;
                }

                if (!is_uploaded_file($tmpName)) {
                    continue;
                }

                if ($dosyaBoyutu <= 0 || $dosyaBoyutu > self::MAKS_DOSYA_BOYUTU) {
                    continue;
                }

                if (function_exists('mime_content_type')) {
                    $mime = (string)mime_content_type($tmpName);
                }
                if ($mime === '') {
                    $mime = trim((string)($dosya['type'] ?? ''));
                }

                if (!in_array($mime, self::IZINLI_MIME, true)) {
                    continue;
                }

                $yazildi = move_uploaded_file($tmpName, $hedefYol);
            }

            if (!$yazildi) {
                continue;
            }

            $this->db->insert('Ticket_Ekler', [
                'Ticket_Ekler_ticket_id' => $ticketId,
                'Ticket_Ekler_mesaj_id' => $mesajId,
                'Ticket_Ekler_dosya_adi' => $dosyaAdi,
                'Ticket_Ekler_dosya_yolu' => 'assets/uploads/tickets/' . $ticketId . '/' . $guvenliAd,
                'Ticket_Ekler_dosya_boyutu' => $dosyaBoyutu,
                'Ticket_Ekler_mime_turu' => $mime !== '' ? $mime : null,
                'OlusturanKullanici' => $islemKullaniciId,
                'OlusturmaTarihi' => $now,
                'GuncelleyenKullanici' => $islemKullaniciId,
                'GuncellemeTarihi' => $now,
                'Durum' => 1,
            ]);

            $ekSayisi++;
            $ekler[] = [
                'dosya_adi' => $dosyaAdi,
                'dosya_yolu' => 'assets/uploads/tickets/' . $ticketId . '/' . $guvenliAd,
                'dosya_url' => $this->buildFileUrl('assets/uploads/tickets/' . $ticketId . '/' . $guvenliAd),
                'dosya_boyutu' => $dosyaBoyutu,
                'mime_turu' => $mime,
            ];
        }

        return ['ek_sayisi' => $ekSayisi, 'ekler' => $ekler];
    }

    public function ticketKaynakKullaniciBul(int $kaynakId, string $kaynakKullaniciId): ?array
    {
        if ($kaynakId <= 0 || $kaynakKullaniciId === '') {
            return null;
        }

        return $this->db->fetchOne(
            "SELECT TOP 1 ke.*, k.*
             FROM Ticket_Kaynak_KullaniciEsleme ke
             INNER JOIN kullanicilar k ON ke.Ticket_Kaynak_KullaniciEsleme_kullanici_id = k.kullanici_id
             WHERE ke.Ticket_Kaynak_KullaniciEsleme_kaynak_id = ?
               AND ke.Ticket_Kaynak_KullaniciEsleme_kaynak_kullanici_id = ?
               AND ke.Durum = 1",
            [$kaynakId, $kaynakKullaniciId]
        );
    }

    public function ticketDetayGetir(int $ticketId, int $kaynakId, int $kullaniciId): ?array
    {
        $ticket = $this->db->fetchOne(
            "SELECT
                t.Tickets_id,
                t.Tickets_no,
                t.Tickets_konu,
                CONVERT(VARCHAR(16), t.OlusturmaTarihi, 120) AS acilis_tarihi,
                CONVERT(VARCHAR(16), t.Tickets_son_yanit_tarihi, 120) AS son_yanit_tarihi,
                CONVERT(VARCHAR(16), t.Tickets_kapanis_tarihi, 120) AS kapanis_tarihi,
                k.Ticket_Kategoriler_id AS kategori_id,
                k.Ticket_Kategoriler_ad AS kategori_ad,
                o.Ticket_Oncelikler_id AS oncelik_id,
                o.Ticket_Oncelikler_ad AS oncelik_ad,
                d.Ticket_Durumlar_id AS durum_id,
                d.Ticket_Durumlar_ad AS durum_ad,
                d.Ticket_Durumlar_kapatma_durumu,
                ku.kullanici_ad AS olusturan_ad,
                ku.kullanici_soyad AS olusturan_soyad,
                ka.kullanici_ad AS atanan_ad,
                ka.kullanici_soyad AS atanan_soyad,
                CASE WHEN t.Tickets_olusturan_kullanici_id = ? THEN 0 ELSE 1 END AS cc_mi
            FROM Tickets t
            INNER JOIN Ticket_Kategoriler k ON t.Tickets_kategori_id = k.Ticket_Kategoriler_id
            INNER JOIN Ticket_Oncelikler o ON t.Tickets_oncelik_id = o.Ticket_Oncelikler_id
            INNER JOIN Ticket_Durumlar d ON t.Tickets_durum_id = d.Ticket_Durumlar_id
            LEFT JOIN kullanicilar ku ON t.Tickets_olusturan_kullanici_id = ku.kullanici_id
            LEFT JOIN kullanicilar ka ON t.Tickets_atanan_kullanici_id = ka.kullanici_id
            WHERE t.Tickets_id = ?
              AND t.Tickets_kaynak_id = ?
              AND (
                    t.Tickets_olusturan_kullanici_id = ?
                    OR EXISTS (
                        SELECT 1 FROM Ticket_CC cc
                        WHERE cc.Ticket_CC_ticket_id = t.Tickets_id
                          AND cc.Ticket_CC_kullanici_id = ?
                          AND cc.Durum = 1
                    )
                  )
              AND t.Durum = 1",
            [$kullaniciId, $ticketId, $kaynakId, $kullaniciId, $kullaniciId]
        );

        if (!$ticket) {
            return null;
        }

        // Mesajları da dahil et
        $ticket['mesajlar'] = $this->ticketMesajlariniGetir($ticketId, $kaynakId, $kullaniciId);

        return $ticket;
    }

    public function ticketListele(int $kaynakId, int $kullaniciId): array
    {
        return $this->db->fetchAll(
            "SELECT
                t.Tickets_id,
                t.Tickets_no,
                t.Tickets_konu,
                CONVERT(VARCHAR(16), t.OlusturmaTarihi, 120) AS acilis_tarihi,
                CONVERT(VARCHAR(16), t.Tickets_son_yanit_tarihi, 120) AS son_yanit_tarihi,
                d.Ticket_Durumlar_id AS durum_id,
                d.Ticket_Durumlar_ad AS durum_ad,
                d.Ticket_Durumlar_renk AS durum_renk,
                d.Ticket_Durumlar_kapatma_durumu,
                o.Ticket_Oncelikler_id AS oncelik_id,
                o.Ticket_Oncelikler_ad AS oncelik_ad,
                o.Ticket_Oncelikler_renk AS oncelik_renk,
                kt.Ticket_Kategoriler_id AS kategori_id,
                kt.Ticket_Kategoriler_ad AS kategori_ad,
                kt.Ticket_Kategoriler_renk AS kategori_renk,
                ku.kullanici_ad AS olusturan_ad,
                ku.kullanici_soyad AS olusturan_soyad,
                ka.kullanici_ad AS atanan_ad,
                ka.kullanici_soyad AS atanan_soyad,
                (SELECT COUNT(*) FROM Ticket_Mesajlar m WHERE m.Ticket_Mesajlar_ticket_id = t.Tickets_id AND m.Durum = 1 AND m.Ticket_Mesajlar_ic_not = 0) AS mesaj_sayisi,
                CASE WHEN t.Tickets_olusturan_kullanici_id = ? THEN 0 ELSE 1 END AS cc_mi
            FROM Tickets t
            INNER JOIN Ticket_Durumlar d ON t.Tickets_durum_id = d.Ticket_Durumlar_id
            INNER JOIN Ticket_Oncelikler o ON t.Tickets_oncelik_id = o.Ticket_Oncelikler_id
            LEFT JOIN Ticket_Kategoriler kt ON t.Tickets_kategori_id = kt.Ticket_Kategoriler_id
            LEFT JOIN kullanicilar ku ON t.Tickets_olusturan_kullanici_id = ku.kullanici_id
            LEFT JOIN kullanicilar ka ON t.Tickets_atanan_kullanici_id = ka.kullanici_id
            WHERE t.Tickets_kaynak_id = ?
              AND (
                    t.Tickets_olusturan_kullanici_id = ?
                    OR EXISTS (
                        SELECT 1 FROM Ticket_CC cc
                        WHERE cc.Ticket_CC_ticket_id = t.Tickets_id
                          AND cc.Ticket_CC_kullanici_id = ?
                          AND cc.Durum = 1
                    )
                  )
              AND t.Durum = 1
            ORDER BY t.OlusturmaTarihi DESC",
            [$kullaniciId, $kaynakId, $kullaniciId, $kullaniciId]
        ) ?: [];
    }

    /**
     * Kaynak kullanıcı ID (string) ile dahili kullanıcı ID'sini bulur.
     * create_ticket dışındaki endpointlerde kullanılır.
     */
    public function kaynakKullaniciBul(int $kaynakId, string $kaynakKullaniciId): ?int
    {
        if ($kaynakId <= 0 || $kaynakKullaniciId === '') {
            return null;
        }

        $esleme = $this->db->fetchOne(
            "SELECT TOP 1 Ticket_Kaynak_KullaniciEsleme_kullanici_id AS kullanici_id
             FROM Ticket_Kaynak_KullaniciEsleme
             WHERE Ticket_Kaynak_KullaniciEsleme_kaynak_id = ?
               AND Ticket_Kaynak_KullaniciEsleme_kaynak_kullanici_id = ?
               AND Durum = 1",
            [$kaynakId, $kaynakKullaniciId]
        );

        return $esleme ? (int)$esleme['kullanici_id'] : null;
    }

    public function ticketMetaBilgisiGetir(): array
    {
        $kategoriler = $this->db->fetchAll(
            "SELECT Ticket_Kategoriler_id AS id, Ticket_Kategoriler_ad AS ad, Ticket_Kategoriler_renk AS renk
             FROM Ticket_Kategoriler
             WHERE Durum = 1
             ORDER BY Ticket_Kategoriler_ad ASC"
        );

        $oncelikler = $this->db->fetchAll(
            "SELECT Ticket_Oncelikler_id AS id, Ticket_Oncelikler_ad AS ad, Ticket_Oncelikler_renk AS renk, Ticket_Oncelikler_ikon AS ikon
             FROM Ticket_Oncelikler
             WHERE Durum = 1
             ORDER BY Ticket_Oncelikler_id ASC"
        );

        $durumlar = $this->db->fetchAll(
            "SELECT Ticket_Durumlar_id AS id, Ticket_Durumlar_ad AS ad, Ticket_Durumlar_renk AS renk, Ticket_Durumlar_kapatma_durumu AS kapatma
             FROM Ticket_Durumlar
             WHERE Durum = 1
             ORDER BY Ticket_Durumlar_sira ASC"
        );

        return [
            'kategoriler' => $kategoriler ?: [],
            'oncelikler' => $oncelikler ?: [],
            'durumlar' => $durumlar ?: [],
        ];
    }

    public function ticketMesajlariniGetir(int $ticketId, int $kaynakId, int $kaynakKullaniciId): array
    {
        $mesajlar = $this->db->fetchAll(
            "SELECT
                m.Ticket_Mesajlar_id AS mesaj_id,
                m.Ticket_Mesajlar_mesaj AS mesaj,
                m.Ticket_Mesajlar_ic_not,
                m.Ticket_Mesajlar_yazan_kul_id,
                m.Ticket_Mesajlar_kaynak AS mesaj_kaynak,
                CONVERT(VARCHAR(16), m.OlusturmaTarihi, 120) AS tarih,
                COALESCE(m.Ticket_Mesajlar_yazan_ad, k.kullanici_ad) AS yazan_ad,
                COALESCE(m.Ticket_Mesajlar_yazan_soyad, k.kullanici_soyad) AS yazan_soyad
            FROM Ticket_Mesajlar m
            INNER JOIN Tickets t ON m.Ticket_Mesajlar_ticket_id = t.Tickets_id
            LEFT JOIN kullanicilar k ON m.Ticket_Mesajlar_yazan_kul_id = k.kullanici_id
            WHERE m.Ticket_Mesajlar_ticket_id = ?
              AND t.Tickets_kaynak_id = ?
              AND (
                    t.Tickets_olusturan_kullanici_id = ?
                    OR EXISTS (
                        SELECT 1 FROM Ticket_CC cc
                        WHERE cc.Ticket_CC_ticket_id = t.Tickets_id
                          AND cc.Ticket_CC_kullanici_id = ?
                          AND cc.Durum = 1
                    )
                  )
              AND m.Ticket_Mesajlar_ic_not = 0
              AND m.Durum = 1
            ORDER BY m.OlusturmaTarihi ASC",
            [$ticketId, $kaynakId, $kaynakKullaniciId, $kaynakKullaniciId]
        );

        if (empty($mesajlar)) {
            return [];
        }

        $ekler = $this->db->fetchAll(
            "SELECT
                e.Ticket_Ekler_id,
                e.Ticket_Ekler_mesaj_id,
                e.Ticket_Ekler_dosya_adi,
                e.Ticket_Ekler_dosya_yolu,
                e.Ticket_Ekler_dosya_boyutu,
                e.Ticket_Ekler_mime_turu
             FROM Ticket_Ekler e
             WHERE e.Ticket_Ekler_ticket_id = ?
               AND e.Ticket_Ekler_mesaj_id IS NOT NULL
               AND e.Durum = 1
             ORDER BY e.Ticket_Ekler_id ASC",
            [$ticketId]
        );

        $ekMap = [];
        foreach ($ekler as $ek) {
            $mesajId = (int)($ek['Ticket_Ekler_mesaj_id'] ?? 0);
            if ($mesajId <= 0) {
                continue;
            }

            if (!isset($ekMap[$mesajId])) {
                $ekMap[$mesajId] = [];
            }

            $ekMap[$mesajId][] = [
                'id' => (int)($ek['Ticket_Ekler_id'] ?? 0),
                'dosya_adi' => $ek['Ticket_Ekler_dosya_adi'] ?? null,
                'dosya_yolu' => $ek['Ticket_Ekler_dosya_yolu'] ?? null,
                'dosya_url' => $this->buildFileUrl($ek['Ticket_Ekler_dosya_yolu'] ?? null),
                'dosya_boyutu' => (int)($ek['Ticket_Ekler_dosya_boyutu'] ?? 0),
                'mime_turu' => $ek['Ticket_Ekler_mime_turu'] ?? null,
            ];
        }

        foreach ($mesajlar as &$mesaj) {
            $mesajId = (int)($mesaj['mesaj_id'] ?? 0);
            $mesaj['ekler'] = $ekMap[$mesajId] ?? [];
        }
        unset($mesaj);

        return $mesajlar;
    }

    /**
     * Admin panelden yazılan müşteri yanıtı için "yeni_mesaj" bildirimini tetikler.
     * (mesajKaynak = 'panel' → yalnızca ticket'ı açan kullanıcıya gider, adminlere gitmez.)
     */
    public function yeniMesajBildirimiTetikle(int $ticketId, ?int $yazanKullaniciId, string $mesajKaynak = 'panel', ?string $mesajMetni = null): void
    {
        $this->bildirimGonder($ticketId, 'yeni_mesaj', $yazanKullaniciId, null, null, $mesajKaynak, $mesajMetni);
    }

    /**
     * Ticket bir personele atandığında "ticket_atandi" bildirimini tetikler.
     * Kendine atama veya aynı kişiye tekrar atama durumunda bildirim gitmez.
     */
    public function atamaBildirimiTetikle(int $ticketId, ?int $yeniAtananId, ?int $atayanKullaniciId, ?int $eskiAtananId = null): void
    {
        $yeniAtananId = (int)$yeniAtananId;
        if ($yeniAtananId <= 0) return;
        if ($yeniAtananId === (int)$eskiAtananId) return;
        if ($yeniAtananId === (int)$atayanKullaniciId) return;

        $this->bildirimGonder($ticketId, 'ticket_atandi', $atayanKullaniciId, null, null, 'panel', null, $yeniAtananId);
    }

    /**
     * Kullanıcı CC listesine eklendiğinde "cc_eklendi" bildirimini tetikler.
     * Yalnızca yeni eklenen kişiye gider; kendini ekleyene bildirim gitmez.
     */
    public function ccBildirimiTetikle(int $ticketId, ?int $ccKullaniciId, ?int $ekleyenKullaniciId): void
    {
        $ccKullaniciId = (int)$ccKullaniciId;
        if ($ccKullaniciId <= 0) return;
        if ($ccKullaniciId === (int)$ekleyenKullaniciId) return;

        $this->bildirimGonder($ticketId, 'cc_eklendi', $ekleyenKullaniciId, null, null, 'panel', null, $ccKullaniciId);
    }

    private function bildirimGonder(int $ticketId, string $olay, ?int $tetikleyenKullaniciId, ?string $paramYazanAd, ?string $paramYazanSoyad, string $mesajKaynak, ?string $mesajMetni = null, ?int $hedefKullaniciId = null): void
    {
        try {
            $ticket = $this->db->fetchOne("
                SELECT
                    t.Tickets_id, t.Tickets_no, t.Tickets_konu, t.Tickets_atanan_kullanici_id, t.Tickets_olusturan_kullanici_id,
                    t.Tickets_kaynak_id,
                    CONVERT(VARCHAR(16), t.OlusturmaTarihi, 120) AS tarih,
                    k.Ticket_Kategoriler_ad,
                    o.Ticket_Oncelikler_ad,
                    d.Ticket_Durumlar_ad,
                    tk.Ticket_Kaynak_api_url,
                    tk.Ticket_Kaynak_destek_sayfasi
                FROM Tickets t
                LEFT JOIN Ticket_Kategoriler k ON t.Tickets_kategori_id = k.Ticket_Kategoriler_id
                LEFT JOIN Ticket_Oncelikler o ON t.Tickets_oncelik_id = o.Ticket_Oncelikler_id
                LEFT JOIN Ticket_Durumlar d ON t.Tickets_durum_id = d.Ticket_Durumlar_id
                LEFT JOIN Ticket_Kaynak tk ON t.Tickets_kaynak_id = tk.Ticket_Kaynak_id
                WHERE t.Tickets_id = ?
            ", [$ticketId]);

            if (!$ticket) return;

            $sablonlar = $this->db->fetchAll("SELECT * FROM Ticket_Mesaj_Sablonlari WHERE Sablon_Olay = ? AND Durum = 1", [$olay]);
            if (empty($sablonlar)) return;

            $yAd = $paramYazanAd;
            $ySoyad = $paramYazanSoyad;
            if (empty($yAd) && $tetikleyenKullaniciId > 0) {
                $kul = $this->db->fetchOne("SELECT kullanici_ad, kullanici_soyad FROM kullanicilar WHERE kullanici_id = ?", [$tetikleyenKullaniciId]);
                if ($kul) {
                    $yAd = $kul['kullanici_ad'];
                    $ySoyad = $kul['kullanici_soyad'];
                }
            }

            require_once __DIR__ . '/EntegrasyonHelper.php';
            require_once __DIR__ . '/Bildirim.php';
            $entegrasyon = new EntegrasyonHelper($this->db);

            $adminLink = "https://destek.ornekproje.com/admin/ticket-detay?id=" . $ticket['Tickets_id'];
            $kullaniciLink = '';
            if (!empty($ticket['Ticket_Kaynak_api_url']) && !empty($ticket['Ticket_Kaynak_destek_sayfasi'])) {
                $kullaniciLink = rtrim($ticket['Ticket_Kaynak_api_url'], '/') . '/' . ltrim($ticket['Ticket_Kaynak_destek_sayfasi'], '/');
            }

            $vars = [
                '{{ticket_no}}' => $ticket['Tickets_no'],
                '{{ticket_id}}' => $ticket['Tickets_id'],
                '{{konu}}' => $ticket['Tickets_konu'],
                '{{kategori}}' => $ticket['Ticket_Kategoriler_ad'] ?? '-',
                '{{oncelik}}' => $ticket['Ticket_Oncelikler_ad'] ?? '-',
                '{{durum}}' => $ticket['Ticket_Durumlar_ad'] ?? '-',
                '{{yazan_ad}}' => $yAd ?? '',
                '{{yazan_soyad}}' => $ySoyad ?? '',
                '{{tarih}}' => $ticket['tarih'],
                '{{mesaj}}' => $mesajMetni ?? ''
            ];

            // Alıcı tipi tanımları (link tipi vb.) DB'den okunur
            $aliciTipleri = [];
            foreach ($this->db->fetchAll("SELECT Ticket_Sablon_AliciTipleri_kod AS kod, Ticket_Sablon_AliciTipleri_link_tipi AS link_tipi FROM Ticket_Sablon_AliciTipleri WHERE Durum = 1") as $at) {
                $aliciTipleri[$at['kod']] = $at['link_tipi'];
            }

            $aliciCache = [];
            $gonderilen = [];

            foreach ($sablonlar as $s) {
                $aliciTipi = $s['Sablon_Alici_Tipi'] ?? '';
                if ($aliciTipi === '') continue;

                if (!array_key_exists($aliciTipi, $aliciCache)) {
                    $aliciCache[$aliciTipi] = $this->bildirimAlicilariGetir(
                        $aliciTipi, $ticket, $olay, $tetikleyenKullaniciId, $mesajKaynak, $hedefKullaniciId
                    );
                }
                $alicilar = $aliciCache[$aliciTipi];
                if (empty($alicilar)) continue;

                // PUSH bildirimi her zaman panele düşer; alıcı tipi "musteri" olsa bile
                // abone olan kişi bu panele giriş yapmış kullanıcıdır, link admin tarafını gösterir.
                $link = ($s['Sablon_Kanal'] === 'PUSH' || ($aliciTipleri[$aliciTipi] ?? 'admin') !== 'musteri')
                    ? $adminLink
                    : $kullaniciLink;

                $icerik = str_replace(array_keys($vars), array_values($vars), $s['Sablon_Icerik']);
                $icerik = str_replace('{{link}}', $link, $icerik);
                $konu   = str_replace(array_keys($vars), array_values($vars), $s['Sablon_Konu'] ?? '');
                $konu   = str_replace('{{link}}', $link, $konu);

                foreach ($alicilar as $alici) {
                    if ($s['Sablon_Kanal'] === 'MAIL') {
                        $hedef = trim((string)($alici['kullanici_email'] ?? ''));
                    } elseif ($s['Sablon_Kanal'] === 'WHATSAPP') {
                        $hedef = trim((string)($alici['kullanici_telefon'] ?? ''));
                    } elseif ($s['Sablon_Kanal'] === 'PUSH') {
                        // Push hedefi kullanıcının kendisidir; cihaz adresleri Bildirim_Abonelikleri'nden çözülür
                        $hedef = (string)(int)($alici['kullanici_id'] ?? 0);
                        if ($hedef === '0') continue;
                    } else {
                        continue;
                    }
                    if ($hedef === '') continue;

                    // Aynı olayda aynı kişiye aynı kanaldan tek bildirim
                    $anahtar = $s['Sablon_Kanal'] . '|' . mb_strtolower($hedef);
                    if (isset($gonderilen[$anahtar])) continue;
                    $gonderilen[$anahtar] = true;

                    if ($s['Sablon_Kanal'] === 'MAIL') {
                        $entegrasyon->mailGonder($hedef, $konu, nl2br($icerik));
                    } elseif ($s['Sablon_Kanal'] === 'WHATSAPP') {
                        $entegrasyon->whatsappGonder($hedef, $icerik);
                    } else {
                        // Bildirimler tablosuna yazar (çan) + kullanıcının cihazlarına push atar.
                        // Mail/WhatsApp'tan bağımsızdır; biri çalışmasa da push gider.
                        try {
                            Bildirim::olustur($this->db, [
                                'kullanici_id' => (int)$hedef,
                                'baslik'       => $konu !== '' ? $konu : 'Destek Bildirimi',
                                'govde'        => $icerik,
                                'url'          => $link,
                                'tip'          => 'info',
                                'ticket_id'    => (int)$ticket['Tickets_id'],
                                'olay'         => $olay,
                                'push'         => true,
                                'olusturan'    => $tetikleyenKullaniciId ?: null,
                            ]);
                        } catch (Exception $e) {
                            // Push hatası diğer kanalları durdurmamalı
                            error_log('Push bildirimi olusturulamadi (ticket ' . $ticketId . '): ' . $e->getMessage());
                        }
                    }
                }
            }
        } catch (Exception $e) {
            error_log("Bildirim Gönderme Hatası: " . $e->getMessage());
        }
    }

    /**
     * Şablonun alıcı tipine göre bildirim gönderilecek kullanıcı listesini döner.
     * Kendi eylemi için kimseye bildirim gitmez (tetikleyen kullanıcı listeden çıkarılır).
     */
    private function bildirimAlicilariGetir(string $aliciTipi, array $ticket, string $olay, ?int $tetikleyenKullaniciId, string $mesajKaynak, ?int $hedefKullaniciId): array
    {
        $atananId    = (int)($ticket['Tickets_atanan_kullanici_id'] ?? 0);
        $kaynakId    = (int)($ticket['Tickets_kaynak_id'] ?? 0);
        $hedefId     = (int)$hedefKullaniciId;
        $tetikleyen  = (int)$tetikleyenKullaniciId;
        $alicilar    = [];
        $tetikleyeniCikar = true;

        switch ($aliciTipi) {
            // Departman 1'deki tüm personeller
            case 'admin':
                if ($olay === 'yeni_mesaj') {
                    if ($mesajKaynak === 'panel') return [];
                    if ($atananId > 0) return [];   // atanmışsa bildirim atanan kişiye gider
                }
                $alicilar = $this->db->fetchAll("
                    SELECT kullanici_id, kullanici_email, kullanici_telefon
                    FROM kullanicilar
                    WHERE kullanici_departman_id = 1 AND kullanici_durum = 1
                ");
                break;

            // Ticket'ı açan müşteri kullanıcısı
            case 'kullanici':
                if ($olay === 'yeni_mesaj' && $mesajKaynak !== 'panel') return [];
                $alicilar = $this->bildirimKullaniciGetir([(int)($ticket['Tickets_olusturan_kullanici_id'] ?? 0)]);
                $tetikleyeniCikar = false;   // kendi açtığı talebin bildirimini alır
                break;

            // Ticket'ın atandığı personel
            case 'atanan':
                if ($olay === 'ticket_atandi') {
                    $alicilar = $this->bildirimKullaniciGetir([$hedefId]);
                    break;
                }
                if ($atananId <= 0) return [];
                if ($olay === 'yeni_mesaj' && $mesajKaynak === 'panel') return [];
                $alicilar = $this->bildirimKullaniciGetir([$atananId]);
                break;

            // Ticket'ın geldiği kaynağa atanmış personeller
            case 'kaynak_personel':
                if ($kaynakId <= 0) return [];
                if ($olay === 'yeni_mesaj' && $mesajKaynak === 'panel') return [];
                $alicilar = $this->db->fetchAll("
                    SELECT k.kullanici_id, k.kullanici_email, k.kullanici_telefon
                    FROM Ticket_Kaynak_Personel p
                    INNER JOIN kullanicilar k ON p.Ticket_Kaynak_Personel_kullanici_id = k.kullanici_id
                    WHERE p.Ticket_Kaynak_Personel_kaynak_id = ? AND p.Durum = 1 AND k.kullanici_durum = 1
                ", [$kaynakId]);
                break;

            // Bilgilendirme (CC) listesindeki kullanıcılar
            case 'cc':
                if ($olay === 'cc_eklendi') {
                    $alicilar = $this->bildirimKullaniciGetir([$hedefId]);
                    break;
                }
                $alicilar = $this->db->fetchAll("
                    SELECT k.kullanici_id, k.kullanici_email, k.kullanici_telefon
                    FROM Ticket_CC cc
                    INNER JOIN kullanicilar k ON cc.Ticket_CC_kullanici_id = k.kullanici_id
                    WHERE cc.Ticket_CC_ticket_id = ? AND cc.Durum = 1 AND k.kullanici_durum = 1
                ", [(int)$ticket['Tickets_id']]);
                break;

            default:
                return [];
        }

        if ($tetikleyeniCikar && $tetikleyen > 0) {
            $alicilar = array_values(array_filter($alicilar, function ($a) use ($tetikleyen) {
                return (int)($a['kullanici_id'] ?? 0) !== $tetikleyen;
            }));
        }

        return $alicilar;
    }

    private function bildirimKullaniciGetir(array $kullaniciIdler): array
    {
        $kullaniciIdler = array_values(array_unique(array_filter(array_map('intval', $kullaniciIdler))));
        if (empty($kullaniciIdler)) return [];

        $yerTutucu = implode(',', array_fill(0, count($kullaniciIdler), '?'));
        return $this->db->fetchAll("
            SELECT kullanici_id, kullanici_email, kullanici_telefon
            FROM kullanicilar
            WHERE kullanici_id IN ($yerTutucu) AND kullanici_durum = 1
        ", $kullaniciIdler);
    }
}