<?php

declare(strict_types=1);

namespace Banks\Tests;

use Banks_Helper;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Banks_Helper::class)]
final class HelperTest extends TestCase
{
    /**
     * @return iterable<string, array{string, int}>
     */
    public static function currencyProvider(): iterable
    {
        yield 'euro' => ['EUR', 978];
        yield 'us dollar' => ['USD', 840];
        yield 'pound sterling' => ['GBP', 826];
        yield 'yen' => ['JPY', 392];
        yield 'yuan' => ['CNY', 156];
    }

    #[DataProvider('currencyProvider')]
    public function testCurrencyCodeToNumber(string $code, int $number): void
    {
        self::assertSame($number, Banks_Helper::currencyCodeToNumber($code));
    }

    #[DataProvider('currencyProvider')]
    public function testCurrencyNumberToCode(string $code, int $number): void
    {
        self::assertSame($code, Banks_Helper::currencyNumberToCode($number));
    }

    public function testCurrencyCodeToNumberIgnoresCase(): void
    {
        self::assertSame(978, Banks_Helper::currencyCodeToNumber('eur'));
        self::assertSame(840, Banks_Helper::currencyCodeToNumber('Usd'));
    }

    public function testCurrencyCodeToNumberRejectsUnknownCode(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs("Unrecognized currency 'XXX'");

        Banks_Helper::currencyCodeToNumber('XXX');
    }

    public function testCurrencyNumberToCodeRejectsUnknownNumber(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs("Unrecognized currency '999'");

        Banks_Helper::currencyNumberToCode(999);
    }

    /**
     * @deprecated Covers the deprecated snake_case aliases. Remove together with them.
     */
    public function testDeprecatedAliasesStillWork(): void
    {
        self::assertSame(978, Banks_Helper::currency_code2number('EUR'));
        self::assertSame('EUR', Banks_Helper::currency_number2code(978));
    }
}
