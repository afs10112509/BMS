<?php

namespace App\Services\Attendance;

use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class AttendancePhotoStore
{
    public const DISK = 'local';

    public const MAX_BYTES = 350000; // ~350 KB setelah kompres klien

    /**
     * Simpan foto base64 (data URL atau murni). Return path relatif di disk local.
     */
    public function store(string $photoBase64, int $employeeId, string $dateYmd, string $side): string
    {
        $side = $side === 'out' ? 'out' : 'in';
        $raw = $photoBase64;
        if (str_contains($raw, 'base64,')) {
            $raw = substr($raw, strpos($raw, 'base64,') + 7);
        }
        $binary = base64_decode($raw, true);
        if ($binary === false || $binary === '') {
            throw ValidationException::withMessages([
                'photo' => 'Foto tidak valid.',
            ]);
        }
        if (strlen($binary) > self::MAX_BYTES) {
            throw ValidationException::withMessages([
                'photo' => 'Foto terlalu besar. Kompres hingga maksimal sekitar 350 KB.',
            ]);
        }

        $info = @getimagesizefromstring($binary);
        if ($info === false) {
            throw ValidationException::withMessages([
                'photo' => 'File harus berupa gambar.',
            ]);
        }

        $binary = $this->recompressJpeg($binary);

        $dir = 'attendance/'.$employeeId;
        $name = $dateYmd.'_'.$side.'_'.substr(sha1($binary), 0, 8).'.jpg';
        $path = $dir.'/'.$name;
        Storage::disk(self::DISK)->put($path, $binary);

        return $path;
    }

    public function delete(?string $path): void
    {
        if ($path && Storage::disk(self::DISK)->exists($path)) {
            Storage::disk(self::DISK)->delete($path);
        }
    }

    public function get(?string $path): ?string
    {
        if (! $path || ! Storage::disk(self::DISK)->exists($path)) {
            return null;
        }

        return Storage::disk(self::DISK)->get($path);
    }

    protected function recompressJpeg(string $binary): string
    {
        if (! function_exists('imagecreatefromstring')) {
            return $binary;
        }
        $img = @imagecreatefromstring($binary);
        if ($img === false) {
            return $binary;
        }
        $w = imagesx($img);
        $h = imagesy($img);
        $maxW = 960;
        if ($w > $maxW) {
            $nw = $maxW;
            $nh = (int) round($h * ($maxW / $w));
            $dst = imagecreatetruecolor($nw, $nh);
            imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
            imagedestroy($img);
            $img = $dst;
        }
        ob_start();
        imagejpeg($img, null, 72);
        imagedestroy($img);
        $out = ob_get_clean();

        return $out !== false && $out !== '' ? $out : $binary;
    }
}
