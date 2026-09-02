<?php

namespace App\Services;

class TotpService
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function generateSecret(): string
    {
        $bits = '';
        foreach (str_split(random_bytes(20)) as $byte) $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        $secret = '';
        foreach (str_split($bits, 5) as $chunk) {
            if (strlen($chunk) < 5) $chunk = str_pad($chunk, 5, '0');
            $secret .= self::ALPHABET[bindec($chunk)];
        }
        return $secret;
    }

    public function verify(string $secret, string $code, int $window = 1): bool
    {
        if (! preg_match('/^\d{6}$/', $code)) return false;
        $counter = intdiv(time(), 30);
        for ($offset = -$window; $offset <= $window; $offset++) {
            if (hash_equals($this->code($secret, $counter + $offset), $code)) return true;
        }
        return false;
    }

    public function provisioningUri(string $secret, string $email): string
    {
        $issuer = 'Royal Hotspot';
        return 'otpauth://totp/'.rawurlencode($issuer.':'.$email).'?'.http_build_query([
            'secret' => $secret, 'issuer' => $issuer, 'algorithm' => 'SHA1', 'digits' => 6, 'period' => 30,
        ]);
    }

    private function code(string $secret, int $counter): string
    {
        $key = $this->decodeBase32($secret);
        $binaryCounter = pack('N2', intdiv($counter, 4294967296), $counter % 4294967296);
        $hash = hash_hmac('sha1', $binaryCounter, $key, true);
        $offset = ord($hash[19]) & 0x0f;
        $value = unpack('N', substr($hash, $offset, 4))[1] & 0x7fffffff;
        return str_pad((string) ($value % 1000000), 6, '0', STR_PAD_LEFT);
    }

    private function decodeBase32(string $secret): string
    {
        $bits = '';
        foreach (str_split(strtoupper($secret)) as $char) {
            $position = strpos(self::ALPHABET, $char);
            if ($position === false) continue;
            $bits .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
        }
        $bytes = '';
        foreach (str_split($bits, 8) as $chunk) if (strlen($chunk) === 8) $bytes .= chr(bindec($chunk));
        return $bytes;
    }
}
