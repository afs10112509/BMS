<?php

namespace App\Support;

final class WhatsAppPhone
{
    /**
     * Normalisasi nomor Indonesia ke format 62xxxxxxxxxx (tanpa +).
     */
    public static function normalize(?string $raw): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $raw) ?? '';
        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, '62') && strlen($digits) >= 11) {
            return $digits;
        }

        if (str_starts_with($digits, '0') && strlen($digits) >= 10) {
            return '62'.substr($digits, 1);
        }

        if (str_starts_with($digits, '8') && strlen($digits) >= 9) {
            return '62'.$digits;
        }

        return null;
    }
}
