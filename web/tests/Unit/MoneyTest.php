<?php

namespace Tests\Unit;

use App\Core\Support\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_parses_rupee_strings_exactly(): void
    {
        $this->assertSame(123450, Money::of('1234.5')->paise);
        $this->assertSame(123405, Money::of('1234.05')->paise);
        $this->assertSame(-1230, Money::of('-12.30')->paise);
        $this->assertSame(500, Money::of(5)->paise);
    }

    public function test_rejects_bad_amounts(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::of('12.345');
    }

    public function test_percent_rounds_half_up_to_the_paisa(): void
    {
        $this->assertSame('180.00', Money::of('1000')->percent(1800)->toDecimal());
        $this->assertSame('269.91', Money::of('2999')->percent(900)->toDecimal());   // 269.910
        $this->assertSame('90.00', Money::of('999.99')->percent(900)->toDecimal());  // 89.9991
        $this->assertSame('0.01', Money::of('0.05')->percent(1800)->toDecimal());    // 0.009 rounds up
    }

    public function test_divides_yearly_price_by_twelve(): void
    {
        $this->assertSame('6665.83', Money::of('79990')->dividedBy(12)->toDecimal());
    }

    public function test_formats_with_indian_grouping(): void
    {
        $this->assertSame('₹1,23,45,678.90', Money::of('12345678.9')->format());
        $this->assertSame('₹999.00', Money::of('999')->format());
        $this->assertSame('₹1,000.00', Money::of('1000')->format());
    }
}
