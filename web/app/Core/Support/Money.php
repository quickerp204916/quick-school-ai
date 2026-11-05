<?php

namespace App\Core\Support;

use InvalidArgumentException;

/**
 * Exact INR amounts held as integer paise. Never construct from a float.
 */
final class Money
{
    private function __construct(public readonly int $paise)
    {
    }

    public static function paise(int $paise): self
    {
        return new self($paise);
    }

    /** Accepts "1234", "1234.5", "1234.50", "-12.30" or an int number of rupees. */
    public static function of(string|int $amount): self
    {
        if (is_int($amount)) {
            return new self($amount * 100);
        }

        $amount = trim($amount);
        if (! preg_match('/^(-)?(\d+)(?:\.(\d{1,2}))?$/', $amount, $m)) {
            throw new InvalidArgumentException("Not a valid amount: {$amount}");
        }

        $paise = ((int) $m[2]) * 100 + (int) str_pad($m[3] ?? '0', 2, '0');

        return new self($m[1] === '-' ? -$paise : $paise);
    }

    public static function zero(): self
    {
        return new self(0);
    }

    public function plus(self $other): self
    {
        return new self($this->paise + $other->paise);
    }

    public function minus(self $other): self
    {
        return new self($this->paise - $other->paise);
    }

    /** Percentage in basis points (1800 = 18%), rounded half up to the paisa. */
    public function percent(int $basisPoints): self
    {
        $sign = $this->paise < 0 ? -1 : 1;
        $abs = abs($this->paise) * $basisPoints;

        return new self($sign * intdiv($abs + 5000, 10000));
    }

    /** Divide evenly, rounding half up (used for yearly price per month). */
    public function dividedBy(int $parts): self
    {
        if ($parts < 1) {
            throw new InvalidArgumentException('Parts must be at least 1.');
        }

        $sign = $this->paise < 0 ? -1 : 1;

        return new self($sign * intdiv(abs($this->paise) * 2 + $parts, $parts * 2));
    }

    public function isZero(): bool
    {
        return $this->paise === 0;
    }

    public function isPositive(): bool
    {
        return $this->paise > 0;
    }

    public function greaterThanOrEqual(self $other): bool
    {
        return $this->paise >= $other->paise;
    }

    /** "1234.50", for DECIMAL(12,2) columns. */
    public function toDecimal(): string
    {
        $abs = abs($this->paise);

        return ($this->paise < 0 ? '-' : '').intdiv($abs, 100).'.'.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    /** "₹1,23,456.00" with Indian digit grouping. */
    public function format(): string
    {
        $abs = abs($this->paise);
        $rupees = (string) intdiv($abs, 100);

        if (strlen($rupees) > 3) {
            $last = substr($rupees, -3);
            $rest = substr($rupees, 0, -3);
            $rupees = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest).','.$last;
        }

        return ($this->paise < 0 ? '-' : '').'₹'.$rupees.'.'.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    public function __toString(): string
    {
        return $this->toDecimal();
    }
}
