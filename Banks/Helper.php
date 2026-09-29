<?php

declare(strict_types=1);

abstract class Banks_Helper
{
    /** @var array<non-empty-string, positive-int> */
    private static array $_currencies = [
        'EUR' => 978,
        'USD' => 840,
        'GBP' => 826,
        'JPY' => 392,
        'CNY' => 156,
    ];

    /**
     * @return positive-int
     */
    public static function currencyCodeToNumber(string $code): int
    {
        $currencyName = strtoupper($code);
        if (!isset(self::$_currencies[$currencyName])) {
            throw new InvalidArgumentException("Unrecognized currency '$code'");
        }

        return self::$_currencies[$currencyName];
    }

    /**
     * @return non-empty-string
     */
    public static function currencyNumberToCode(int $number): string
    {
        $code = array_search($number, self::$_currencies, true);
        if ($code === false) {
            throw new InvalidArgumentException("Unrecognized currency '$number'");
        }

        return $code;
    }

    /**
     * @deprecated Use currencyCodeToNumber()
     * @return positive-int
     */
    public static function currency_code2number(string $code): int
    {
        return self::currencyCodeToNumber($code);
    }

    /**
     * @deprecated Use currencyNumberToCode()
     * @return non-empty-string
     */
    public static function currency_number2code(int $number): string
    {
        return self::currencyNumberToCode($number);
    }
}
