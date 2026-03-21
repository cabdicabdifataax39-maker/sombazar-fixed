<?php
/**
 * SomBazar — TOTP (Time-based One-Time Password)
 * Google Authenticator uyumlu 2FA
 * RFC 6238 implementasyonu — harici kütüphane gerektirmez
 */

class TOTP {

    // Secret key üret (setup sırasında bir kez)
    public static function generateSecret(): string {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; // Base32
        $secret = '';
        for ($i = 0; $i < 32; $i++) {
            $secret .= $chars[random_int(0, 31)];
        }
        return $secret;
    }

    // Base32 decode
    private static function base32Decode(string $secret): string {
        $base32chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = strtoupper($secret);
        $buffer = 0;
        $bitsLeft = 0;
        $result = '';

        for ($i = 0; $i < strlen($secret); $i++) {
            $val = strpos($base32chars, $secret[$i]);
            if ($val === false) continue;
            $buffer = ($buffer << 5) | $val;
            $bitsLeft += 5;
            if ($bitsLeft >= 8) {
                $bitsLeft -= 8;
                $result .= chr(($buffer >> $bitsLeft) & 0xFF);
            }
        }
        return $result;
    }

    // TOTP kodu üret (30 saniyelik pencere)
    public static function getCode(string $secret, int $timeStep = null): string {
        $timeStep = $timeStep ?? floor(time() / 30);
        $key = self::base32Decode($secret);
        $msg = pack('N*', 0) . pack('N*', $timeStep);
        $hash = hash_hmac('sha1', $msg, $key, true);
        $offset = ord($hash[19]) & 0xF;
        $code = (
            ((ord($hash[$offset])   & 0x7F) << 24) |
            ((ord($hash[$offset+1]) & 0xFF) << 16) |
            ((ord($hash[$offset+2]) & 0xFF) <<  8) |
             (ord($hash[$offset+3]) & 0xFF)
        ) % 1000000;
        return str_pad((string)$code, 6, '0', STR_PAD_LEFT);
    }

    // Kodu doğrula (±1 pencere toleransı = 90 saniye)
    public static function verify(string $secret, string $code, int $tolerance = 1): bool {
        $code = preg_replace('/\D/', '', $code);
        if (strlen($code) !== 6) return false;

        $timeStep = floor(time() / 30);
        for ($i = -$tolerance; $i <= $tolerance; $i++) {
            if (hash_equals(self::getCode($secret, $timeStep + $i), $code)) {
                return true;
            }
        }
        return false;
    }

    // Google Authenticator QR URL'i
    public static function getQRUrl(string $secret, string $email, string $issuer = 'SomBazar'): string {
        $issuer  = rawurlencode($issuer);
        $email   = rawurlencode($email);
        $otpauth = "otpauth://totp/{$issuer}:{$email}?secret={$secret}&issuer={$issuer}&algorithm=SHA1&digits=6&period=30";
        return 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' . rawurlencode($otpauth);
    }
}
