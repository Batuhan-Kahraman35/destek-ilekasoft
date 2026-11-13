<?php
/**
 * MCP Sunucusu (Claude bağlayıcısı)
 *
 * McpOAuth   : OAuth 2.1 istemci kaydı, yetki kodu (PKCE S256), erişim/yenileme token'ı
 * McpAraclar : araç listesi (Mcp_Araclar + Menu_SayfaYetkileri), çağrı, işlem logu
 *
 * Token'lar ve kodlar yalnız SHA-256 özetiyle saklanır.
 */

require_once __DIR__ . '/TicketAjanServis.php';

class McpOAuth
{
    public const KOD_OMRU_SN       = 300;          // 5 dk
    public const ERISIM_OMRU_SN    = 3600;         // 1 saat
    public const YENILEME_OMRU_SN  = 2592000;      // 30 gün
    public const KAPSAM            = 'ticket';

    public static function tabanUrl(): string
    {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') === '443');
        $host  = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? 'destek.ornekproje.com')));
        return ($https ? 'https' : 'http') . '://' . $host;
    }

    public static function kaynakUrl(): string
    {
        return self::tabanUrl() . '/mcp';
    }

    public static function ozet(string $deger): string
    {
        return hash('sha256', $deger);
    }

    public static function rastgele(int $bayt = 32): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bayt)), '+/', '-_'), '=');
    }

    public static function istemciIp(): string
    {
        return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 50);
    }

    private static function tarih(int $ekSaniye = 0): string
    {
        return date('Y-m-d H:i:s', strtotime(app_now()) + $ekSaniye);
    }

    /**
     * Yönlendirme adresi: https veya yerel (localhost / 127.0.0.1) http.
     */
    public static function yonlendirmeGecerliMi(string $uri): bool
    {
        $p = parse_url($uri);
        if (!$p || empty($p['scheme']) || empty($p['host']) || isset($p['fragment'])) {
            return false;
        }
        $sema = strtolower($p['scheme']);
        $host = strtolower($p['host']);
        if ($sema === 'https') {
            return true;
        }
        return $sema === 'http' && in_array($host, ['localhost', '127.0.0.1', '[::1]'], true);
    }

    /** RFC 7591 dinamik istemci kaydı */
    public static function istemciKaydet(Database $db, array $istek): array
    {
        $uriler = $istek['redirect_uris'] ?? null;
        if (!is_array($uriler) || !$uriler || count($uriler) > 10) {
            throw new InvalidArgumentException('redirect_uris zorunlu (en fazla 10).');
        }
        foreach ($uriler as $u) {
            if (!is_string($u) || strlen($u) > 500 || !self::yonlendirmeGecerliMi($u)) {
                throw new InvalidArgumentException('Gecersiz redirect_uri: ' . (is_string($u) ? $u : '?'));
            }
        }

        $ad = mb_substr(trim((string)($istek['client_name'] ?? 'MCP istemcisi')), 0, 200, 'UTF-8');
        $clientId = 'mcp_' . self::rastgele(18);
        $now = app_now();

        $db->insert('Mcp_Istemciler', [
            'Mcp_Istemciler_ClientId'             => $clientId,
            'Mcp_Istemciler_Ad'                   => $ad,
            'Mcp_Istemciler_YonlendirmeAdresleri' => json_encode(array_values($uriler), JSON_UNESCAPED_SLASHES),
            'Mcp_Istemciler_KayitIp'              => self::istemciIp(),
            'OlusturmaTarihi'                     => $now,
            'GuncellemeTarihi'                    => $now,
            'Durum'                               => 1,
        ]);

        return [
            'client_id'                  => $clientId,
            'client_id_issued_at'        => time(),
            'client_name'                => $ad,
            'redirect_uris'              => array_values($uriler),
            'grant_types'                => ['authorization_code', 'refresh_token'],
            'response_types'             => ['code'],
            'token_endpoint_auth_method' => 'none',
        ];
    }

    public static function istemciGetir(Database $db, string $clientId): ?array
    {
        $satir = $db->fetchOne(
            "SELECT * FROM Mcp_Istemciler WHERE Mcp_Istemciler_ClientId = ? AND Durum = 1",
            [$clientId]
        );
        if (!$satir) {
            return null;
        }
        $satir['uriler'] = json_decode((string)$satir['Mcp_Istemciler_YonlendirmeAdresleri'], true) ?: [];
        return $satir;
    }

    /**
     * Yetkilendirme isteğini doğrular. Hata: redirect_uri güvenilir değilse ekrana, değilse yönlendirmeyle döner.
     * @return array normalize edilmiş istek + istemci
     */
    public static function yetkiIstegiDogrula(Database $db, array $q): array
    {
        $clientId = (string)($q['client_id'] ?? '');
        $istemci = $clientId !== '' ? self::istemciGetir($db, $clientId) : null;
        if (!$istemci) {
            throw new InvalidArgumentException('Tanimsiz istemci (client_id).');
        }

        $redirect = (string)($q['redirect_uri'] ?? '');
        if ($redirect === '' && count($istemci['uriler']) === 1) {
            $redirect = $istemci['uriler'][0];
        }
        if (!in_array($redirect, $istemci['uriler'], true)) {
            throw new InvalidArgumentException('redirect_uri istemci kaydiyla eslesmiyor.');
        }

        $istek = [
            'istemci'      => $istemci,
            'redirect_uri' => $redirect,
            'state'        => isset($q['state']) ? (string)$q['state'] : null,
            'scope'        => trim((string)($q['scope'] ?? self::KAPSAM)) ?: self::KAPSAM,
            'resource'     => isset($q['resource']) ? (string)$q['resource'] : null,
            'challenge'    => (string)($q['code_challenge'] ?? ''),
            'hata'         => null,
        ];

        if (($q['response_type'] ?? '') !== 'code') {
            $istek['hata'] = 'unsupported_response_type';
        } elseif ($istek['challenge'] === '' || ($q['code_challenge_method'] ?? '') !== 'S256'
            || !preg_match('/^[A-Za-z0-9\-_]{43,128}$/', $istek['challenge'])) {
            $istek['hata'] = 'invalid_request';
        } elseif ($istek['resource'] !== null && rtrim($istek['resource'], '/') !== self::kaynakUrl()) {
            $istek['hata'] = 'invalid_target';
        }

        return $istek;
    }

    public static function yonlendirmeAdresi(string $redirect, array $parametre): string
    {
        $parametre = array_filter($parametre, static fn($v) => $v !== null);
        return $redirect . (str_contains($redirect, '?') ? '&' : '?') . http_build_query($parametre);
    }

    public static function kodOlustur(Database $db, array $istek, int $kullaniciId): string
    {
        $kod = self::rastgele(32);
        $now = app_now();
        $db->insert('Mcp_YetkiKodlari', [
            'Mcp_YetkiKodlari_KodHash'           => self::ozet($kod),
            'Mcp_YetkiKodlari_Istemci_id'        => (int)$istek['istemci']['Mcp_Istemciler_id'],
            'Mcp_YetkiKodlari_kullanici_id'      => $kullaniciId,
            'Mcp_YetkiKodlari_YonlendirmeAdresi' => $istek['redirect_uri'],
            'Mcp_YetkiKodlari_KodMeydanOkuma'    => $istek['challenge'],
            'Mcp_YetkiKodlari_Kapsam'            => mb_substr($istek['scope'], 0, 200, 'UTF-8'),
            'Mcp_YetkiKodlari_Kaynak'            => $istek['resource'],
            'Mcp_YetkiKodlari_SonGecerlilik'     => self::tarih(self::KOD_OMRU_SN),
            'OlusturanKullanici'                 => $kullaniciId,
            'OlusturmaTarihi'                    => $now,
            'GuncelleyenKullanici'               => $kullaniciId,
            'GuncellemeTarihi'                   => $now,
            'Durum'                              => 1,
        ]);
        return $kod;
    }

    private static function tokenUret(Database $db, int $istemciId, int $kullaniciId, string $kapsam, ?int $tokenId = null): array
    {
        $erisim   = self::rastgele(32);
        $yenileme = self::rastgele(32);
        $now = app_now();

        $alanlar = [
            'Mcp_Tokenlar_ErisimHash'    => self::ozet($erisim),
            'Mcp_Tokenlar_ErisimBitis'   => self::tarih(self::ERISIM_OMRU_SN),
            'Mcp_Tokenlar_YenilemeHash'  => self::ozet($yenileme),
            'Mcp_Tokenlar_YenilemeBitis' => self::tarih(self::YENILEME_OMRU_SN),
            'GuncelleyenKullanici'       => $kullaniciId,
            'GuncellemeTarihi'           => $now,
        ];

        if ($tokenId) {
            $db->update('Mcp_Tokenlar', $alanlar, ['Mcp_Tokenlar_id' => $tokenId]);
        } else {
            $db->insert('Mcp_Tokenlar', $alanlar + [
                'Mcp_Tokenlar_Istemci_id'   => $istemciId,
                'Mcp_Tokenlar_kullanici_id' => $kullaniciId,
                'Mcp_Tokenlar_Kapsam'       => $kapsam,
                'OlusturanKullanici'        => $kullaniciId,
                'OlusturmaTarihi'           => $now,
                'Durum'                     => 1,
            ]);
        }

        return [
            'access_token'  => $erisim,
            'token_type'    => 'Bearer',
            'expires_in'    => self::ERISIM_OMRU_SN,
            'refresh_token' => $yenileme,
            'scope'         => $kapsam,
        ];
    }

    /** grant_type=authorization_code */
    public static function kodTakas(Database $db, array $p): array
    {
        $istemci = self::istemciGetir($db, (string)($p['client_id'] ?? ''));
        if (!$istemci) {
            throw new McpOAuthHata('invalid_client', 'Tanimsiz istemci.', 401);
        }

        $kod = $db->fetchOne(
            "SELECT * FROM Mcp_YetkiKodlari WHERE Mcp_YetkiKodlari_KodHash = ? AND Durum = 1",
            [self::ozet((string)($p['code'] ?? ''))]
        );
        if (!$kod || (int)$kod['Mcp_YetkiKodlari_Istemci_id'] !== (int)$istemci['Mcp_Istemciler_id']) {
            throw new McpOAuthHata('invalid_grant', 'Gecersiz yetki kodu.');
        }

        // Tek kullanım: önce işaretle, sonra kontrol et (yarış durumunda ikinci istek düşer)
        $isaretlenen = $db->fetchOne(
            "UPDATE Mcp_YetkiKodlari SET Mcp_YetkiKodlari_KullanimTarihi = ?, GuncellemeTarihi = ?
             OUTPUT INSERTED.Mcp_YetkiKodlari_id
             WHERE Mcp_YetkiKodlari_id = ? AND Mcp_YetkiKodlari_KullanimTarihi IS NULL",
            [app_now(), app_now(), (int)$kod['Mcp_YetkiKodlari_id']]
        );
        if (!$isaretlenen) {
            throw new McpOAuthHata('invalid_grant', 'Yetki kodu daha once kullanilmis.');
        }
        if (strtotime((string)self::tarihMetni($kod['Mcp_YetkiKodlari_SonGecerlilik'])) < strtotime(app_now())) {
            throw new McpOAuthHata('invalid_grant', 'Yetki kodunun suresi dolmus.');
        }
        if (($p['redirect_uri'] ?? $kod['Mcp_YetkiKodlari_YonlendirmeAdresi']) !== $kod['Mcp_YetkiKodlari_YonlendirmeAdresi']) {
            throw new McpOAuthHata('invalid_grant', 'redirect_uri eslesmiyor.');
        }

        $dogrulayici = (string)($p['code_verifier'] ?? '');
        $hesap = rtrim(strtr(base64_encode(hash('sha256', $dogrulayici, true)), '+/', '-_'), '=');
        if ($dogrulayici === '' || !hash_equals((string)$kod['Mcp_YetkiKodlari_KodMeydanOkuma'], $hesap)) {
            throw new McpOAuthHata('invalid_grant', 'PKCE dogrulamasi basarisiz.');
        }

        $kullaniciId = (int)$kod['Mcp_YetkiKodlari_kullanici_id'];
        if (!self::kullaniciAktifMi($db, $kullaniciId)) {
            throw new McpOAuthHata('invalid_grant', 'Kullanici aktif degil.');
        }

        return self::tokenUret($db, (int)$istemci['Mcp_Istemciler_id'], $kullaniciId, (string)($kod['Mcp_YetkiKodlari_Kapsam'] ?: self::KAPSAM));
    }

    /** grant_type=refresh_token (yenileme token'ı her kullanımda döndürülür) */
    public static function yenile(Database $db, array $p): array
    {
        $istemci = self::istemciGetir($db, (string)($p['client_id'] ?? ''));
        if (!$istemci) {
            throw new McpOAuthHata('invalid_client', 'Tanimsiz istemci.', 401);
        }

        $token = $db->fetchOne(
            "SELECT * FROM Mcp_Tokenlar WHERE Mcp_Tokenlar_YenilemeHash = ? AND Durum = 1 AND Mcp_Tokenlar_IptalTarihi IS NULL",
            [self::ozet((string)($p['refresh_token'] ?? ''))]
        );
        if (!$token || (int)$token['Mcp_Tokenlar_Istemci_id'] !== (int)$istemci['Mcp_Istemciler_id']
            || strtotime(self::tarihMetni($token['Mcp_Tokenlar_YenilemeBitis'])) < strtotime(app_now())) {
            throw new McpOAuthHata('invalid_grant', 'Gecersiz veya suresi dolmus yenileme tokeni.');
        }

        $kullaniciId = (int)$token['Mcp_Tokenlar_kullanici_id'];
        if (!self::kullaniciAktifMi($db, $kullaniciId)) {
            throw new McpOAuthHata('invalid_grant', 'Kullanici aktif degil.');
        }

        return self::tokenUret($db, (int)$istemci['Mcp_Istemciler_id'], $kullaniciId, (string)$token['Mcp_Tokenlar_Kapsam'], (int)$token['Mcp_Tokenlar_id']);
    }

    /**
     * Bearer token'ı doğrular, personeli döner. Geçersizse null.
     */
    public static function tokenDogrula(Database $db, string $erisim): ?array
    {
        if ($erisim === '') {
            return null;
        }
        $token = $db->fetchOne(
            "SELECT t.Mcp_Tokenlar_id, t.Mcp_Tokenlar_ErisimBitis,
                    k.kullanici_id, k.kullanici_ad, k.kullanici_soyad, k.kullanici_departman_id
             FROM Mcp_Tokenlar t
             JOIN kullanicilar k ON k.kullanici_id = t.Mcp_Tokenlar_kullanici_id AND k.kullanici_durum = 1
             WHERE t.Mcp_Tokenlar_ErisimHash = ? AND t.Durum = 1 AND t.Mcp_Tokenlar_IptalTarihi IS NULL",
            [self::ozet($erisim)]
        );
        if (!$token || strtotime(self::tarihMetni($token['Mcp_Tokenlar_ErisimBitis'])) < strtotime(app_now())) {
            return null;
        }

        $db->execute(
            "UPDATE Mcp_Tokenlar SET Mcp_Tokenlar_SonKullanim = ?, Mcp_Tokenlar_SonIp = ? WHERE Mcp_Tokenlar_id = ?",
            [app_now(), self::istemciIp(), (int)$token['Mcp_Tokenlar_id']]
        );
        return $token;
    }

    private static function kullaniciAktifMi(Database $db, int $kullaniciId): bool
    {
        return (bool)$db->fetchOne(
            "SELECT 1 AS var FROM kullanicilar WHERE kullanici_id = ? AND kullanici_durum = 1",
            [$kullaniciId]
        );
    }

    /** sqlsrv DateTime nesnesi veya metin → 'Y-m-d H:i:s' */
    public static function tarihMetni($deger): string
    {
        if ($deger instanceof DateTimeInterface) {
            return $deger->format('Y-m-d H:i:s');
        }
        return (string)$deger;
    }
}

class McpOAuthHata extends Exception
{
    public string $kod;
    public int $http;

    public function __construct(string $kod, string $mesaj, int $http = 400)
    {
        parent::__construct($mesaj);
        $this->kod = $kod;
        $this->http = $http;
    }
}

class McpAraclar
{
    private const YETKI_ANAHTARI = ['gor' => 'can_view', 'ekle' => 'can_add', 'duzenle' => 'can_edit', 'sil' => 'can_delete'];

    private Database $db;
    private array $kullanici;
    private ?int $tokenId;
    private array $yetkiOnbellek = [];

    public function __construct(Database $db, array $kullanici, ?int $tokenId = null)
    {
        $this->db = $db;
        $this->kullanici = $kullanici;
        $this->tokenId = $tokenId;
    }

    /** Araç giriş şemaları (yapı koddadır; ad, başlık, açıklama ve yetki Mcp_Araclar tablosundan gelir) */
    private static function semalar(): array
    {
        $ticket   = ['type' => 'string', 'description' => 'Ticket ID veya ticket-detay linki'];
        $onizleme = ['type' => 'boolean', 'description' => 'true: hicbir kayit yazilmaz, bildirim gitmez; yalniz yapilacak islemin plani doner'];
        $durum    = ['type' => 'string', 'description' => 'Durum ID veya adi (ticket_secenekler)'];
        $oncelik  = ['type' => 'string', 'description' => 'Oncelik ID veya adi'];
        $kategori = ['type' => 'string', 'description' => 'Kategori ID veya adi'];
        $atanan   = ['type' => 'string', 'description' => '"ben", "bos", kullanici_id veya ad soyad. Yonetici olmayan personel yalniz "ben" kullanabilir'];
        $hatirlatma = ['type' => 'string', 'description' => '"2026-11-14 09:00", "14.11.2026 09:00" veya hizli secim adi ("1 saat sonra", "Yarin 09:00")'];
        $alici    = ['type' => 'string', 'description' => 'Virgulle: "ben", kullanici_id, ad soyad. Verilmezse atanan, o da yoksa siz'];
        $kanal    = ['type' => 'string', 'description' => 'Virgulle WHATSAPP, MAIL (PUSH her aliciya otomatik eklenir)'];

        $nesne = static fn(array $ozellik, array $zorunlu = []) => array_filter([
            'type' => 'object', 'properties' => $ozellik ?: new stdClass(), 'required' => $zorunlu ?: null,
            'additionalProperties' => false,
        ], static fn($v) => $v !== null);

        return [
            'ticket_secenekler' => $nesne([]),
            'ticket_ara' => $nesne([
                'ifade' => ['type' => 'string', 'description' => 'Aranacak kelimeler; her kelime konu veya bir mesajda gecmeli'],
                'proje' => ['type' => 'string', 'description' => 'Kaynak kodu (PORTAL) veya host (portal.ornekproje.com)'],
                'durum' => ['type' => 'string', 'enum' => ['acik', 'kapali', 'hepsi']],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
            ], ['ifade']),
            'ticket_detay' => $nesne(['ticket' => $ticket], ['ticket']),
            'ticket_ek' => $nesne([
                'ticket' => $ticket,
                'ek_id'  => ['type' => 'integer', 'description' => 'ticket_detay > ekler[].ek_id'],
            ], ['ticket', 'ek_id']),
            'ticket_yanit' => $nesne([
                'ticket'   => $ticket,
                'mesaj'    => ['type' => 'string', 'description' => 'Musteriye gidecek yanit (bildirim tetikler)'],
                'ic_not'   => ['type' => 'string', 'description' => 'Yalniz ekibin gordugu ic not (bildirim tetiklemez)'],
                'durum'    => $durum, 'oncelik' => $oncelik, 'atanan' => $atanan,
                'onizleme' => $onizleme,
            ], ['ticket']),
            'ticket_guncelle' => $nesne([
                'ticket' => $ticket, 'durum' => $durum, 'oncelik' => $oncelik, 'kategori' => $kategori, 'atanan' => $atanan,
                'onizleme' => $onizleme,
            ], ['ticket']),
            'ticket_ac' => $nesne([
                'konu'     => ['type' => 'string', 'maxLength' => 200, 'description' => 'Takip ticketlari icin "[Takip] <Firma> - <is>"'],
                'mesaj'    => ['type' => 'string', 'description' => 'Ilk mesaj'],
                'ic_not'   => ['type' => 'string'],
                'proje'    => ['type' => 'string', 'description' => 'Kaynak kodu veya host'],
                'kategori' => $kategori, 'oncelik' => $oncelik, 'durum' => $durum, 'atanan' => $atanan,
                'zorla'    => ['type' => 'boolean', 'description' => 'Ayni konulu ticket olsa da ac'],
                'onizleme' => $onizleme,
            ], ['konu', 'mesaj']),
            'gorev_ekle' => $nesne([
                'ticket' => $ticket,
                'baslik' => ['type' => 'string'],
                'aciklama' => ['type' => 'string'],
                'atanan' => $atanan, 'hatirlatma' => $hatirlatma, 'alici' => $alici, 'kanal' => $kanal,
                'onizleme' => $onizleme,
            ], ['ticket', 'baslik']),
            'gorev_ertele' => $nesne([
                'ticket' => $ticket,
                'gorev'  => ['type' => 'integer', 'description' => 'ticket_detay > gorevler[].gorev_id'],
                'hatirlatma' => $hatirlatma, 'alici' => $alici, 'kanal' => $kanal,
                'onizleme' => $onizleme,
            ], ['ticket', 'gorev', 'hatirlatma']),
        ];
    }

    /** Aktif araç tanımları + bağlı sayfa dosyası */
    private function tanimlar(): array
    {
        return $this->db->fetchAll(
            "SELECT a.Mcp_Araclar_Ad AS ad, a.Mcp_Araclar_Baslik AS baslik, a.Mcp_Araclar_Aciklama AS aciklama,
                    a.Mcp_Araclar_Yetki AS yetki, a.Mcp_Araclar_Yazma AS yazma,
                    RTRIM(LTRIM(s.sayfalar_sayfa_url)) AS sayfa
             FROM Mcp_Araclar a
             JOIN Menu_Sayfalar s ON s.sayfalar_id = a.Mcp_Araclar_sayfa_id AND s.sayfalar_durum = 1
             WHERE a.Durum = 1
             ORDER BY a.Mcp_Araclar_Sira, a.Mcp_Araclar_id"
        ) ?: [];
    }

    private function sayfaYetkisi(string $sayfa): array
    {
        return $this->yetkiOnbellek[$sayfa] ??= PageAuth::checkPagePermissions(
            (int)$this->kullanici['kullanici_id'],
            (int)$this->kullanici['kullanici_departman_id'],
            $sayfa
        );
    }

    private function yetkiliMi(array $tanim): bool
    {
        $y = $this->sayfaYetkisi($tanim['sayfa']);
        $anahtar = self::YETKI_ANAHTARI[$tanim['yetki']] ?? null;
        return !empty($y['has_access']) && $anahtar !== null && !empty($y[$anahtar]);
    }

    /** tools/list: yalnız personelin yetkisi olan araçlar */
    public function listele(): array
    {
        $semalar = self::semalar();
        $liste = [];
        foreach ($this->tanimlar() as $t) {
            if (!isset($semalar[$t['ad']]) || !$this->yetkiliMi($t)) {
                continue;
            }
            $yazma = (int)$t['yazma'] === 1;
            $liste[] = [
                'name'        => $t['ad'],
                'title'       => $t['baslik'],
                'description' => $t['aciklama'],
                'inputSchema' => $semalar[$t['ad']],
                'annotations' => [
                    'title'           => $t['baslik'],
                    'readOnlyHint'    => !$yazma,
                    'destructiveHint' => $yazma,
                    'idempotentHint'  => !$yazma,
                    'openWorldHint'   => false,
                ],
            ];
        }
        return $liste;
    }

    private function ajan(array $tanim): array
    {
        $k = [
            'kullanici_id'           => (int)$this->kullanici['kullanici_id'],
            'kullanici_ad'           => $this->kullanici['kullanici_ad'],
            'kullanici_soyad'        => $this->kullanici['kullanici_soyad'],
            'kullanici_departman_id' => (int)$this->kullanici['kullanici_departman_id'],
        ];
        return ['kullanici' => $k, 'sahip' => $k, 'yetki' => $this->sayfaYetkisi($tanim['sayfa'])];
    }

    /**
     * tools/call
     * @return array MCP CallToolResult
     */
    public function cagir(string $ad, array $arg): array
    {
        $baslangic = microtime(true);
        $tanim = null;
        foreach ($this->tanimlar() as $t) {
            if ($t['ad'] === $ad) {
                $tanim = $t;
                break;
            }
        }

        $ticketId = isset($arg['ticket']) ? ticketIdCoz((string)$arg['ticket']) : null;
        $onizleme = !empty($arg['onizleme']);

        try {
            if (!$tanim || !isset(self::semalar()[$ad])) {
                throw new Exception("Tanimsiz arac: {$ad}");
            }
            if (!$this->yetkiliMi($tanim)) {
                throw new Exception("Bu araci kullanma yetkiniz yok: {$ad}");
            }
            if (array_key_exists('ticket', $arg) && $ticketId <= 0) {
                throw new Exception('Gecerli bir ticket ID veya ticket-detay linki verin.');
            }

            $sonuc = $this->calistir($ad, $arg, $ticketId, $this->ajan($tanim));
            if ($ad === 'ticket_ac' && !empty($sonuc['ticket_id'])) {
                $ticketId = (int)$sonuc['ticket_id'];
            }
            $this->logla($ad, $ticketId, $onizleme, true, $arg, null, $baslangic);

            if (isset($sonuc['__icerik'])) {
                return ['content' => $sonuc['__icerik'], 'isError' => false];
            }
            return [
                'content'           => [['type' => 'text', 'text' => json_encode($sonuc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)]],
                'structuredContent' => $sonuc,
                'isError'           => false,
            ];
        } catch (Throwable $e) {
            $this->logla($ad, $ticketId ?: null, $onizleme, false, $arg, $e->getMessage(), $baslangic);
            return [
                'content' => [['type' => 'text', 'text' => $e->getMessage()]],
                'isError' => true,
            ];
        }
    }

    /** MCP argümanlarını CLI seçenek dizisine çevirir */
    private static function secenek(array $arg, array $anahtarlar): array
    {
        $s = [];
        foreach ($anahtarlar as $a) {
            if (isset($arg[$a]) && $arg[$a] !== '' && $arg[$a] !== false) {
                $s[$a] = is_bool($arg[$a]) ? '1' : (string)$arg[$a];
            }
        }
        return $s;
    }

    private static function onizlemeUyarisi(array $sonuc): array
    {
        if (!empty($sonuc['onizleme'])) {
            $sonuc['uyari'] = 'ONIZLEME: hicbir kayit yazilmadi, bildirim gonderilmedi. Kaydetmek icin ayni araci onizleme olmadan cagirin.';
        }
        return $sonuc;
    }

    private function calistir(string $ad, array $arg, ?int $ticketId, array $ajan): array
    {
        $db = $this->db;
        $metin = static fn($k) => trim((string)($arg[$k] ?? ''));

        switch ($ad) {
            case 'ticket_secenekler':
                return ajanSecenekleriGetir($db);

            case 'ticket_ara':
                return komutAra($db, $metin('ifade'), self::secenek($arg, ['proje', 'durum', 'limit']), $ajan);

            case 'ticket_detay':
                $d = komutDetay($db, $ticketId, '', $ajan);
                foreach ($d['ekler'] as &$ek) {
                    $ek['url'] = !empty($ek['dosya_yolu']) ? McpOAuth::tabanUrl() . '/' . ltrim((string)$ek['dosya_yolu'], '/') : null;
                    unset($ek['yerel_yol'], $ek['dosya_yolu']);
                }
                unset($ek);
                $d['proje'] = isset($d['proje']['host']) ? ['host' => $d['proje']['host']] : null;
                $d['link'] = McpOAuth::tabanUrl() . '/admin/ticket-detay?id=' . $ticketId;
                return $d;

            case 'ticket_ek':
                return $this->ekGetir($ticketId, (int)($arg['ek_id'] ?? 0), $ajan);

            case 'ticket_yanit':
                $icNot = $metin('ic_not');
                if ($icNot !== '') {
                    $icNot .= "\n\n— Claude bağlayıcısı ile yazıldı";
                }
                return self::onizlemeUyarisi(komutYanit($db, $ticketId, $ajan, $metin('mesaj'), $icNot,
                    self::secenek($arg, ['durum', 'oncelik', 'atanan', 'onizleme'])));

            case 'ticket_guncelle':
                return self::onizlemeUyarisi(komutGuncelle($db, $ticketId, $ajan,
                    self::secenek($arg, ['durum', 'oncelik', 'kategori', 'atanan', 'onizleme'])));

            case 'ticket_ac':
                $icNot = $metin('ic_not');
                if ($icNot !== '') {
                    $icNot .= "\n\n— Claude bağlayıcısı ile yazıldı";
                }
                return self::onizlemeUyarisi(komutAc($db, $ajan, $metin('konu'), $metin('mesaj'), $icNot,
                    self::secenek($arg, ['proje', 'kategori', 'oncelik', 'durum', 'atanan', 'zorla', 'onizleme'])));

            case 'gorev_ekle':
                return self::onizlemeUyarisi(komutGorevEkle($db, $ticketId, $ajan,
                    self::secenek($arg, ['baslik', 'aciklama', 'atanan', 'hatirlatma', 'alici', 'kanal', 'onizleme']), ''));

            case 'gorev_ertele':
                return self::onizlemeUyarisi(komutGorevErtele($db, $ticketId, $ajan,
                    self::secenek($arg, ['gorev', 'hatirlatma', 'alici', 'kanal', 'onizleme'])));
        }

        throw new Exception("Arac uygulanmamis: {$ad}");
    }

    /**
     * Ek dosya içeriği: görsel → image, metin → text, PDF/Office → resource blob (en fazla 5 MB).
     */
    private function ekGetir(int $ticketId, int $ekId, array $ajan): array
    {
        ajanTicketErisimDogrula($this->db, $ajan, $ticketId);

        $ek = $this->db->fetchOne(
            "SELECT e.Ticket_Ekler_dosya_adi AS ad, e.Ticket_Ekler_dosya_yolu AS yol, e.Ticket_Ekler_mime_turu AS mime,
                    ISNULL(m.Ticket_Mesajlar_ic_not, 0) AS ic_not
             FROM Ticket_Ekler e
             LEFT JOIN Ticket_Mesajlar m ON m.Ticket_Mesajlar_id = e.Ticket_Ekler_mesaj_id
             WHERE e.Ticket_Ekler_id = ? AND e.Ticket_Ekler_ticket_id = ? AND e.Durum = 1",
            [$ekId, $ticketId]
        );
        if (!$ek) {
            throw new Exception("Ek bulunamadi: {$ekId} (ticket {$ticketId})");
        }

        $kok = realpath(__DIR__ . '/../..');
        $yol = realpath($kok . '/' . ltrim(str_replace('\\', '/', (string)$ek['yol']), '/'));
        $uploads = realpath($kok . '/assets/uploads');
        if (!$yol || !$uploads || !str_starts_with($yol, $uploads) || !is_file($yol)) {
            throw new Exception("Ek dosyasi diskte bulunamadi: {$ek['ad']}");
        }
        if (filesize($yol) > 5 * 1024 * 1024) {
            throw new Exception("Ek 5 MB'dan buyuk, baglantidan acin: " . McpOAuth::tabanUrl() . '/' . ltrim((string)$ek['yol'], '/'));
        }

        $mime = (string)($ek['mime'] ?: mime_content_type($yol));
        $icerik = file_get_contents($yol);
        $bilgi = ['type' => 'text', 'text' => "{$ek['ad']} ({$mime}" . ((int)$ek['ic_not'] ? ', ic not eki' : '') . ')'];

        if (str_starts_with($mime, 'image/')) {
            return ['__icerik' => [$bilgi, ['type' => 'image', 'data' => base64_encode($icerik), 'mimeType' => $mime]]];
        }
        if (str_starts_with($mime, 'text/')) {
            return ['__icerik' => [$bilgi, ['type' => 'text', 'text' => mb_convert_encoding($icerik, 'UTF-8', 'UTF-8, Windows-1254, ISO-8859-9')]]];
        }
        return ['__icerik' => [$bilgi, ['type' => 'resource', 'resource' => [
            'uri' => McpOAuth::tabanUrl() . '/' . ltrim((string)$ek['yol'], '/'),
            'mimeType' => $mime,
            'blob' => base64_encode($icerik),
        ]]]];
    }

    private function logla(string $arac, ?int $ticketId, bool $onizleme, bool $basarili, array $arg, ?string $hata, float $baslangic): void
    {
        try {
            $now = app_now();
            $kid = (int)$this->kullanici['kullanici_id'];
            $this->db->insert('Mcp_IslemLog', [
                'Mcp_IslemLog_kullanici_id' => $kid,
                'Mcp_IslemLog_Token_id'     => $this->tokenId,
                'Mcp_IslemLog_Arac'         => substr($arac, 0, 64),
                'Mcp_IslemLog_Ticket_id'    => $ticketId,
                'Mcp_IslemLog_Onizleme'     => $onizleme ? 1 : 0,
                'Mcp_IslemLog_Basarili'     => $basarili ? 1 : 0,
                'Mcp_IslemLog_Parametreler' => json_encode($arg, JSON_UNESCAPED_UNICODE),
                'Mcp_IslemLog_Hata'         => $hata !== null ? mb_substr($hata, 0, 1000, 'UTF-8') : null,
                'Mcp_IslemLog_SureMs'       => (int)round((microtime(true) - $baslangic) * 1000),
                'Mcp_IslemLog_Ip'           => McpOAuth::istemciIp(),
                'OlusturanKullanici'        => $kid,
                'OlusturmaTarihi'           => $now,
                'GuncelleyenKullanici'      => $kid,
                'GuncellemeTarihi'          => $now,
                'Durum'                     => 1,
            ]);
        } catch (Throwable $e) {
            error_log('Mcp_IslemLog yazilamadi: ' . $e->getMessage());
        }
    }
}
