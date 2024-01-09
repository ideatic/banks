<?php

abstract class Banks_Helper
{
    private static array $_currencies = [
        'EUR' => 978,
        'USD' => 840,
        'GBP' => 426,
        'JPY' => 392,
        'CNY' => 156,
    ];

  public static function currency_code2number(string $code): int
    {
        $currency_name = strtoupper($code);
        if (!isset(self::$_currencies[$currency_name])) {
            throw new InvalidArgumentException("Unrecognized currency '$code'");
        }

        return self::$_currencies[$code];
    }

  public static function currency_number2code(int $number): string
    {
        $code = array_search($number, self::$_currencies);
        if ($code === false) {
            throw new InvalidArgumentException("Unrecognized currency '$number'");
        }

        return $code;
    }
}