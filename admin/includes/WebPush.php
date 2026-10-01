<?php
/**
 * WebPush - Bağımlılıksız Web Push gönderici (VAPID + RFC 8291 aes128gcm)
 *
 * Yalnızca openssl + curl kullanır, composer paketi gerektirmez.
 *
 * Kullanım:
 *   $vapid  = ['public' => '<base64url>', 'private' => '<PEM>', 'subject' => 'mailto:...'];
 *   $sonuc  = WebPush::gonder($endpoint, $p256dh, $auth, $payloadJson, $vapid);
 *   // $sonuc['kod'] => HTTP durum kodu (201 başarılı, 404/410 abonelik ölmüş)
 */
class WebPush
{
    /**
     * openssl.cnf yolu.
     * Windows/Plesk'te sistemde geçerli bir openssl.cnf bulunmadığından
     * openssl_pkey_new() bu dosya verilmezse false döner.
     */
    private static function opensslConf(): array
    {
        return ['config' => __DIR__ . '/openssl.cnf'];
    }

    /** base64url kodla */
    private static function b64u($data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /** base64url çöz */
    private static function b64uDecode(string $data): string
    {
        $data = strtr($data, '-_', '+/');
        $pad  = strlen($data) % 4;
        if ($pad) {
            $data .= str_repeat('=', 4 - $pad);
        }
        return base64_decode($data);
    }

    /** Ham 65 baytlık EC noktasından (P-256) openssl public key üretir */
    private static function pointToPublicKey(string $point65)
    {
        // P-256 icin sabit SubjectPublicKeyInfo öneki + ham nokta
        $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $point65;
        $pem = "-----BEGIN PUBLIC KEY-----\n"
             . chunk_split(base64_encode($der), 64, "\n")
             . "-----END PUBLIC KEY-----\n";
        return openssl_pkey_get_public($pem);
    }

    /** ECDSA DER imzasını JOSE ham formatına (R||S, 64 bayt) çevirir */
    private static function derToRaw(string $der): string
    {
        $offset = 0;
        if (ord($der[$offset++]) !== 0x30) {
            throw new Exception('Geçersiz imza (SEQUENCE bekleniyordu).');
        }
        if (ord($der[$offset]) & 0x80) {
            $offset += (ord($der[$offset]) & 0x7f) + 1;
        } else {
            $offset++;
        }

        if (ord($der[$offset++]) !== 0x02) {
            throw new Exception('Geçersiz imza (R bileşeni).');
        }
        $rlen = ord($der[$offset++]);
        $r = substr($der, $offset, $rlen);
        $offset += $rlen;

        if (ord($der[$offset++]) !== 0x02) {
            throw new Exception('Geçersiz imza (S bileşeni).');
        }
        $slen = ord($der[$offset++]);
        $s = substr($der, $offset, $slen);

        // Her iki bileşen de 32 bayta normalize edilir
        $r = str_pad(ltrim($r, "\x00"), 32, "\x00", STR_PAD_LEFT);
        $s = str_pad(ltrim($s, "\x00"), 32, "\x00", STR_PAD_LEFT);

        return $r . $s;
    }

    /** VAPID JWT üretir (ES256) */
    private static function vapidJwt(string $endpoint, array $vapid): string
    {
        $p = parse_url($endpoint);
        if (empty($p['scheme']) || empty($p['host'])) {
            throw new Exception('Geçersiz push endpoint adresi.');
        }
        $aud = $p['scheme'] . '://' . $p['host'];

        $header  = self::b64u(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $payload = self::b64u(json_encode([
            'aud' => $aud,
            'exp' => time() + 12 * 3600,
            'sub' => $vapid['subject'],
        ], JSON_UNESCAPED_SLASHES));
        $input = $header . '.' . $payload;

        $pkey = openssl_pkey_get_private($vapid['private']);
        if (!$pkey) {
            throw new Exception('VAPID özel anahtarı okunamadı.');
        }

        $der = '';
        if (!openssl_sign($input, $der, $pkey, OPENSSL_ALGO_SHA256)) {
            throw new Exception('VAPID JWT imzalanamadı.');
        }

        return $input . '.' . self::b64u(self::derToRaw($der));
    }

    /** Payload'ı RFC 8291 (aes128gcm) ile şifreler, gönderilecek gövdeyi döner */
    private static function sifrele(string $p256dh, string $auth, string $payload): string
    {
        $uaPublic   = self::b64uDecode($p256dh);   // 65 bayt
        $authSecret = self::b64uDecode($auth);     // 16 bayt

        // Bu gönderime özel (efemeral) sunucu anahtar çifti
        $as = openssl_pkey_new(array_merge(
            ['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'],
            self::opensslConf()
        ));
        if (!$as) {
            throw new Exception('Efemeral EC anahtarı üretilemedi (openssl.cnf kontrol edin).');
        }

        $asDetails = openssl_pkey_get_details($as);
        $asPublic  = "\x04"
                   . str_pad($asDetails['ec']['x'], 32, "\x00", STR_PAD_LEFT)
                   . str_pad($asDetails['ec']['y'], 32, "\x00", STR_PAD_LEFT);

        // ECDH ortak sırrı
        $uaKey = self::pointToPublicKey($uaPublic);
        if (!$uaKey) {
            throw new Exception('Abonelik public anahtarı çözülemedi.');
        }
        $ecdh = openssl_pkey_derive($uaKey, $as, 32);
        if ($ecdh === false) {
            throw new Exception('ECDH anahtar türetmesi başarısız.');
        }

        // RFC 8291: giriş anahtar materyali
        $keyInfo = "WebPush: info\x00" . $uaPublic . $asPublic;
        $ikm     = hash_hkdf('sha256', $ecdh, 32, $keyInfo, $authSecret);

        // RFC 8188: içerik şifreleme anahtarı + nonce
        $salt  = random_bytes(16);
        $cek   = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\x00", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\x00", $salt);

        // Tek kayıt: düz metin || 0x02 (son kayıt ayracı)
        $tag = '';
        $ciphertext = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
        if ($ciphertext === false) {
            throw new Exception('Payload şifrelenemedi.');
        }
        $ciphertext .= $tag;

        // Gövde başlığı: salt(16) | rs(4) | idlen(1) | keyid(asPublic 65)
        $rs = 4096;
        return $salt . pack('N', $rs) . chr(strlen($asPublic)) . $asPublic . $ciphertext;
    }

    /**
     * Bildirimi push servisine gönderir.
     *
     * @return array ['kod' => int HTTP kodu, 'hata' => string|null]
     */
    public static function gonder(string $endpoint, string $p256dh, string $auth, string $payloadJson, array $vapid): array
    {
        try {
            $body = self::sifrele($p256dh, $auth, $payloadJson);
            $jwt  = self::vapidJwt($endpoint, $vapid);
        } catch (Exception $e) {
            return ['kod' => 0, 'hata' => $e->getMessage()];
        }

        $headers = [
            'Authorization: vapid t=' . $jwt . ', k=' . $vapid['public'],
            'Content-Encoding: aes128gcm',
            'Content-Type: application/octet-stream',
            'TTL: 86400',
        ];

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $resp = curl_exec($ch);
        $kod  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        return ['kod' => $kod, 'hata' => $err ?: ($kod >= 400 ? (string)$resp : null)];
    }
}
