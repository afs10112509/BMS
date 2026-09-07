<?php

namespace App\Services\Payroll;

use App\Models\Category;
use Illuminate\Support\Collection;

class KasbonCategoryLinker
{
    /**
     * Alias nama karyawan → suffix kategori "Kasbon …".
     *
     * @var array<string, list<string>>
     */
    public const NAME_ALIASES = [
        'gufroni' => ['roni', 'gufroni'],
        'ghufroni' => ['gufroni', 'roni'],
        'awaluddin' => ['awal'],
        'wirda safitri' => ['wirda'],
        'zulkifli' => ['uki', 'zuki'],
        'sisyen' => ['sisy'],
        'sisyen tundunaung' => ['sisy'],
        'muhammad akhsan' => ['akhsan'],
        'muhammad rafli' => ['rafli'],
    ];

    public static function isKasbonCategory(Category $category): bool
    {
        if ($category->type !== 'expense') {
            return false;
        }

        return str_starts_with(mb_strtolower(trim($category->name)), 'kasbon');
    }

    public static function suffix(string $categoryName): string
    {
        $n = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $categoryName) ?? $categoryName));
        $n = preg_replace('/^kasbon\s+/u', '', $n) ?? $n;

        return trim($n);
    }

    /**
     * @return list<string>
     */
    public static function employeeKeys(string $name): array
    {
        $n = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $name) ?? $name));
        $parts = array_values(array_filter(explode(' ', $n)));
        $keys = array_values(array_unique(array_filter(array_merge([$n], $parts))));

        foreach (self::NAME_ALIASES as $full => $aliases) {
            if ($n === $full || str_starts_with($n, $full.' ') || str_contains($n, $full)) {
                foreach ($aliases as $alias) {
                    $keys[] = $alias;
                }
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * @param  Collection<int, Category>  $categories
     * @param  list<int>  $takenCategoryIds
     */
    public static function suggest(string $employeeName, Collection $categories, array $takenCategoryIds = []): ?int
    {
        $keys = self::employeeKeys($employeeName);
        $taken = array_fill_keys($takenCategoryIds, true);

        foreach ($categories as $category) {
            if (! self::isKasbonCategory($category) || isset($taken[(int) $category->id])) {
                continue;
            }
            $suffix = self::suffix($category->name);
            if ($suffix !== '' && in_array($suffix, $keys, true)) {
                return (int) $category->id;
            }
        }

        return null;
    }
}
