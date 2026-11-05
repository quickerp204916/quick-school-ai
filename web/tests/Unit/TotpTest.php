<?php

namespace Tests\Unit;

use App\Core\Support\DocumentNumber;
use App\Core\Support\Totp;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class TotpTest extends TestCase
{
    private const RFC_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'; // base32 of "12345678901234567890"

    public function test_matches_rfc_6238_sha1_vectors(): void
    {
        $this->assertSame('94287082', Totp::code(self::RFC_SECRET, 59, 8));
        $this->assertSame('07081804', Totp::code(self::RFC_SECRET, 1111111109, 8));
        $this->assertSame('89005924', Totp::code(self::RFC_SECRET, 1234567890, 8));
    }

    public function test_verify_accepts_one_step_of_drift_only(): void
    {
        $secret = Totp::generateSecret();
        $now = 1_800_000_000;

        $this->assertTrue(Totp::verify($secret, Totp::code($secret, $now), $now));
        $this->assertTrue(Totp::verify($secret, Totp::code($secret, $now - 30), $now));
        $this->assertFalse(Totp::verify($secret, Totp::code($secret, $now - 90), $now));
        $this->assertFalse(Totp::verify($secret, 'abcdef', $now));
    }

    public function test_base32_round_trip(): void
    {
        $this->assertSame('12345678901234567890', Totp::base32Decode(self::RFC_SECRET));
        $this->assertSame(self::RFC_SECRET, Totp::base32Encode('12345678901234567890'));
    }

    public function test_indian_financial_year(): void
    {
        $this->assertSame('2026-27', DocumentNumber::financialYear(CarbonImmutable::parse('2026-04-01')));
        $this->assertSame('2025-26', DocumentNumber::financialYear(CarbonImmutable::parse('2026-03-31')));
    }
}
