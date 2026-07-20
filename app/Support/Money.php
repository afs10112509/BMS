<?php

namespace App\Support;

/**
 * Aritmetika uang dengan BCMath (skala 2 desimal).
 * Hindari float untuk mutasi/penyimpanan; float hanya boleh di batas I/O jika terpaksa.
 */
final class Money
{
    public const SCALE = 2;

    /**
     * Normalisasi ke string desimal 2 digit (aman untuk kolom NUMERIC).
     */
    public static function of(float|int|string $value): string
    {
        if (is_string($value)) {
            $value = trim(str_replace(',', '.', $value));
            if ($value === '' || ! is_numeric($value)) {
                return number_format(0, self::SCALE, '.', '');
            }

            return bcadd($value, '0', self::SCALE);
        }

        // Input numerik dari JSON: format ketat 2 desimal (bukan raw float binary).
        return number_format((float) $value, self::SCALE, '.', '');
    }

    public static function add(float|int|string ...$values): string
    {
        $sum = '0.00';
        foreach ($values as $value) {
            $sum = bcadd($sum, self::of($value), self::SCALE);
        }

        return $sum;
    }

    public static function sub(float|int|string $left, float|int|string $right): string
    {
        return bcsub(self::of($left), self::of($right), self::SCALE);
    }

    public static function mul(float|int|string $left, float|int|string $right): string
    {
        return bcmul(self::of($left), self::of($right), self::SCALE);
    }

    /** Persentase: amount × (pct / 100), dibulatkan 2 desimal. */
    public static function percentOf(float|int|string $amount, float|int|string $pct): string
    {
        $ratio = bcdiv(self::of($pct), '100', 8);

        return bcmul(self::of($amount), $ratio, self::SCALE);
    }

    public static function equals(float|int|string $left, float|int|string $right): bool
    {
        return bccomp(self::of($left), self::of($right), self::SCALE) === 0;
    }
}
