<?php

namespace Tests\Unit;

use App\Support\WhatsAppPhone;
use PHPUnit\Framework\TestCase;

class WhatsAppPhoneTest extends TestCase
{
    public function test_normalizes_local_and_international_numbers(): void
    {
        $this->assertSame('628123456789', WhatsAppPhone::normalize('08123456789'));
        $this->assertSame('628123456789', WhatsAppPhone::normalize('+62 812-3456-789'));
        $this->assertSame('628123456789', WhatsAppPhone::normalize('8123456789'));
    }

    public function test_rejects_empty_or_invalid(): void
    {
        $this->assertNull(WhatsAppPhone::normalize(null));
        $this->assertNull(WhatsAppPhone::normalize(''));
        $this->assertNull(WhatsAppPhone::normalize('abc'));
        $this->assertNull(WhatsAppPhone::normalize('123'));
    }
}
