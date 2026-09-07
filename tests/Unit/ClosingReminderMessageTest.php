<?php

namespace Tests\Unit;

use App\Services\Closing\ClosingReminderService;
use PHPUnit\Framework\TestCase;

class ClosingReminderMessageTest extends TestCase
{
    public function test_compose_message_mentions_yesterday_qty(): void
    {
        $service = new ClosingReminderService;
        $text = $service->composeMessage([
            'name' => 'Siti',
            'branch_name' => 'Konter A',
            'missing' => false,
            'yesterday_qty' => 3,
            'month_qty' => 10,
            'target' => 31,
            'pct' => 32.3,
            'tercapai' => false,
        ], 'Kamis, 13 Agustus 2026', 'Agustus 2026', true);

        $this->assertStringContainsString('Halo Siti', $text);
        $this->assertStringContainsString('Closing kemarin', $text);
        $this->assertStringContainsString(': 3', $text);
        $this->assertStringContainsString('10 / 31', $text);
        $this->assertStringContainsString('sudah tercatat', $text);
    }

    public function test_compose_message_asks_to_input_when_missing(): void
    {
        $service = new ClosingReminderService;
        $text = $service->composeMessage([
            'name' => 'Roni',
            'branch_name' => 'Konter A',
            'missing' => true,
            'yesterday_qty' => null,
            'month_qty' => 4,
            'target' => 31,
            'pct' => 12.9,
            'tercapai' => false,
        ], 'Kamis, 13 Agustus 2026', 'Agustus 2026', true);

        $this->assertStringContainsString('belum diinput', $text);
        $this->assertStringContainsString('Mohon segera input closing kemarin', $text);
    }
}
