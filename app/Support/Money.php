<?php

namespace App\Support;

use InvalidArgumentException;
use Stringable;

/**
 * An amount in integer minor units (laari, cents, halalas) with its currency.
 *
 * The only place that converts between whole units and minor units. Nothing
 * else multiplies or divides by 100, and nothing here converts between
 * currencies: that needs a rate somebody set, and lives with the rate.
 */
final class Money implements Stringable
{
    public const MINOR_UNITS = 100;

    private function __construct(
        public readonly int $minor,
        public readonly string $currency,
    ) {
        if (! Currency::isValid($currency)) {
            throw new InvalidArgumentException("Unknown currency [{$currency}].");
        }
    }

    public static function ofMinor(int $minor, string $currency): self
    {
        return new self($minor, $currency);
    }

    /**
     * From whole units as typed on a screen: "28500" or "299.50".
     * Refuses more than two decimals rather than rounding money silently.
     */
    public static function ofMajor(int|float|string $amount, string $currency): self
    {
        $text = trim((string) $amount);

        if (! preg_match('/^(-)?(\d+)(?:\.(\d{1,2}))?$/', str_replace(',', '', $text), $m)) {
            throw new InvalidArgumentException("[{$amount}] is not an amount with at most two decimals.");
        }

        $minor = ((int) $m[2]) * self::MINOR_UNITS + (int) str_pad($m[3] ?? '0', 2, '0');

        return new self(($m[1] ?? '') === '-' ? -$minor : $minor, $currency);
    }

    /** Whole units as a decimal string, for forms and exports: "28500.00". */
    public function major(): string
    {
        $abs = abs($this->minor);

        return ($this->minor < 0 ? '-' : '')
            .intdiv($abs, self::MINOR_UNITS).'.'.str_pad((string) ($abs % self::MINOR_UNITS), 2, '0', STR_PAD_LEFT);
    }

    /** "MVR 28,500" or "USD 299.50": decimals only when they carry something. */
    public function format(): string
    {
        $abs = abs($this->minor);
        $whole = number_format(intdiv($abs, self::MINOR_UNITS));
        $fraction = $abs % self::MINOR_UNITS;

        return ($this->minor < 0 ? '-' : '')
            .$this->currency.' '.$whole
            .($fraction === 0 ? '' : '.'.str_pad((string) $fraction, 2, '0', STR_PAD_LEFT));
    }

    public function add(Money $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minor + $other->minor, $this->currency);
    }

    public function subtract(Money $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minor - $other->minor, $this->currency);
    }

    public function isZero(): bool
    {
        return $this->minor === 0;
    }

    public function isNegative(): bool
    {
        return $this->minor < 0;
    }

    public function __toString(): string
    {
        return $this->format();
    }

    private function assertSameCurrency(Money $other): void
    {
        if ($other->currency !== $this->currency) {
            throw new InvalidArgumentException("Cannot combine {$this->currency} with {$other->currency} without a rate.");
        }
    }
}
