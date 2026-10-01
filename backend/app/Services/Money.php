<?php

namespace App\Services;

use InvalidArgumentException;

final class Money
{
    public static function toCents(string $value): int
    {
        if (! preg_match('/^-?\d+(?:\.\d{1,2})?$/', $value)) {
            throw new InvalidArgumentException('Valor monetário inválido.');
        }
        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '-');
        [$whole,$decimal] = array_pad(explode('.', $value, 2), 2, '');
        $cents = ((int) $whole * 100) + (int) str_pad($decimal, 2, '0');

        return $negative ? -$cents : $cents;
    }

    public static function fromCents(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);

        return $sign.intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    /** @return list<int> */
    public static function split(int $totalCents, int $parts): array
    {
        if ($parts < 1) {
            throw new InvalidArgumentException('Parcelas inválidas.');
        } $base = intdiv($totalCents, $parts);
        $remainder = $totalCents % $parts;

        return array_map(fn ($i) => $base + ($i < $remainder ? 1 : 0), range(0, $parts - 1));
    }
}
