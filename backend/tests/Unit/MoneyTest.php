<?php

namespace Tests\Unit;

use App\Services\Money;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    #[Test]
    public function converte_valores_sem_ponto_flutuante(): void
    {
        self::assertSame(123456, Money::toCents('1234.56'));
        self::assertSame('1234.56', Money::fromCents(123456));
    }

    #[Test]
    public function distribui_centavos_residuais_deterministicamente(): void
    {
        self::assertSame([34, 33, 33], Money::split(100, 3));
        self::assertSame(100, array_sum(Money::split(100, 3)));
    }
}
