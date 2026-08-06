<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private array $allowed = ['owner', 'pic', 'kasir', 'promotor', 'fronliner', 'teknisi'];

    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->jsonb('positions')->nullable()->after('position');
        });

        $rows = DB::table('employees')->select('id', 'position')->get();
        foreach ($rows as $row) {
            $codes = $this->inferPositions($row->position);
            $label = $this->labelsFor($codes);

            DB::table('employees')->where('id', $row->id)->update([
                'positions' => json_encode(array_values($codes)),
                'position' => $label !== '' ? $label : null,
            ]);
        }

        DB::statement("ALTER TABLE employees ALTER COLUMN positions SET DEFAULT '[]'::jsonb");
        DB::statement('ALTER TABLE employees ALTER COLUMN positions SET NOT NULL');
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('positions');
        });
    }

    /**
     * @return list<string>
     */
    private function inferPositions(?string $raw): array
    {
        $text = mb_strtolower(trim((string) $raw));
        if ($text === '') {
            return [];
        }

        $found = [];
        $map = [
            'owner' => ['owner', 'pemilik'],
            'pic' => ['pic'],
            'kasir' => ['kasir'],
            'promotor' => ['promotor'],
            'fronliner' => ['fronliner', 'frontliner', 'sales'],
            'teknisi' => ['teknisi', 'service'],
        ];

        foreach ($map as $code => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($text, $needle)) {
                    $found[] = $code;
                    break;
                }
            }
        }

        return array_values(array_intersect($this->allowed, $found));
    }

    /**
     * @param  list<string>  $codes
     */
    private function labelsFor(array $codes): string
    {
        $labels = [
            'owner' => 'Owner',
            'pic' => 'PIC',
            'kasir' => 'Kasir',
            'promotor' => 'Promotor',
            'fronliner' => 'Fronliner',
            'teknisi' => 'Teknisi',
        ];

        $out = [];
        foreach ($this->allowed as $code) {
            if (in_array($code, $codes, true)) {
                $out[] = $labels[$code];
            }
        }

        return implode(', ', $out);
    }
};
