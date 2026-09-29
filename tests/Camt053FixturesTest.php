<?php

declare(strict_types=1);

namespace Banks\Tests;

use Banks_Camt053;
use Banks_Camt053_Exception;
use DateTime;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests with sample files from other open source CAMT parsers and with files written for these tests.
 * See tests/fixtures/camt053/README.md for their sources and licenses.
 */
#[CoversClass(Banks_Camt053::class)]
final class Camt053FixturesTest extends TestCase
{
    private const string FIXTURES = __DIR__ . '/fixtures/camt053';

    /**
     * @return iterable<string, array{string, string, ?string, float, string, string, int}>
     */
    public static function genkgoProvider(): iterable
    {
        yield 'v2 minimal ultimate' => ['camt053.v2.minimal.ultimate.xml', 'NL26VAYB8060476890', null, -27.0, '2007-10-18', '2007-10-18', 1];
        yield 'v2 all balance types' => ['camt053.v2.all-balance-types.xml', 'CH2801234000123456789', 'FINPETROL', 8.08, '2014-12-31', '2014-12-31', 1];
        yield 'v2 five decimals' => ['camt053.v2.five.decimals.xml', 'NL26VAYB8060476890', null, 27.05, '2014-12-31', '2014-12-31', 1];
        yield 'v3' => ['camt053.v3.xml', 'NL26VAYB8060476890', 'COMPANY BVBA', -27.0, '2007-10-18', '2007-10-18', 1];
        yield 'v4' => ['camt053.v4.xml', 'NL26VAYB8060476890', 'FINPETROL', -2700.0, '2007-10-18', '2007-10-18', 1];
        yield 'v8' => ['camt053.v8.xml', 'NL26VAYB8060476890', 'FINPETROL', -2700.0, '2007-10-18', '2007-10-18', 1];
    }

    #[DataProvider('genkgoProvider')]
    public function testReadsGenkgoStatement(string $fixture, string $iban, ?string $owner, float $balance, string $dateStart, string $dateEnd, int $entries): void
    {
        $statements = $this->parse("genkgo/{$fixture}")->statements;

        self::assertCount(1, $statements);
        $statement = $statements[0];
        self::assertSame($iban, $statement->iban);
        self::assertSame('EUR', $statement->currency);
        self::assertSame($owner, $statement->owner);
        self::assertSame($balance, $statement->balance);
        self::assertSame(self::timestamp($dateStart), $statement->dateStart);
        self::assertSame(self::timestamp($dateEnd), $statement->dateEnd);
        self::assertCount($entries, $statement->entries);

        $entry = $statement->entries[0];
        self::assertSame(8.85, $entry->amount);
        self::assertSame(self::timestamp('2014-12-31'), $entry->date);
        self::assertSame(self::timestamp('2015-01-02'), $entry->valueDate);
        self::assertNotEmpty($entry->subjects);
    }

    public function testReadsReferenceAndCounterparty(): void
    {
        $entry = $this->parse('genkgo/camt053.v4.xml')->statements[0]->entries[0];

        self::assertSame('AAAASESS-FP-CN_98765/01', $entry->reference);
        self::assertSame(['NAME NAME', '4654654654654654'], $entry->subjects);
    }

    public function testReadsMultipleStatements(): void
    {
        $statements = $this->parse('genkgo/camt053.v2.multi.statement.xml')->statements;

        self::assertCount(2, $statements);
        self::assertSame(27.0, $statements[0]->balance);
        self::assertSame(['Transaction Description 1'], $statements[0]->entries[0]->subjects);
        self::assertSame(20.0, $statements[1]->balance);
        self::assertSame(-7.0, $statements[1]->entries[0]->amount);
        self::assertSame(['Company Name 2', 'Transaction Description 2'], $statements[1]->entries[0]->subjects);
    }

    public function testRejectsFileWithoutStatements(): void
    {
        $this->expectException(Banks_Camt053_Exception::class);
        $this->expectExceptionCode(Banks_Camt053_Exception::NO_STATEMENTS);

        $this->parse('genkgo/camt053.v2.wrong.xml');
    }

    public function testReadsCompleteStatement(): void
    {
        $statements = $this->parse('ideatic/complete.v8.xml')->statements;

        self::assertCount(1, $statements);
        $statement = $statements[0];
        self::assertSame('ES9121000418450200051332', $statement->iban);
        self::assertSame('EUR', $statement->currency);
        self::assertSame('ACME SL', $statement->owner);
        self::assertSame(-250.5, $statement->balance);
        self::assertSame(self::timestamp('2026-09-01'), $statement->dateStart);
        self::assertSame(self::timestamp('2026-09-28'), $statement->dateEnd);

        // El apunte pendiente (PDNG) no se lee
        self::assertCount(4, $statement->entries);
        [$transfer, $batch, $debit, $bare] = $statement->entries;

        // AcctSvcrRef antes que NtryRef; ordenante último antes que ordenante
        self::assertSame('BANKREF-0001', $transfer->reference);
        self::assertSame(121.0, $transfer->amount);
        self::assertSame(['GRUPO CLIENTE', 'Pago factura F-2026-0042', 'RF18539007547034', 'TRANSFERENCIA SEPA', 'ABONO TRANSFERENCIA'], $transfer->subjects);

        // Una remesa es un solo apunte, con los datos de todas sus operaciones
        self::assertSame('BANKREF-0002', $batch->reference);
        self::assertSame(300.0, $batch->amount);
        self::assertSame(
            ['SOCIO A', 'SOCIO B', 'SOCIO C', 'Cuota septiembre A', 'Cuota septiembre B', 'Cuota septiembre C', 'MAND-1', 'MAND-2', 'MAND-3'],
            $batch->subjects
        );

        // Cargo: beneficiario último, referencia de documento y TxId como referencia
        self::assertSame('TX-777', $debit->reference);
        self::assertSame(-1571.5, $debit->amount);
        self::assertSame(self::timestamp('2026-09-20'), $debit->date);
        self::assertSame(self::timestamp('2026-09-19'), $debit->valueDate);
        self::assertSame(['PROVEEDOR MATRIZ SA', 'A-555'], $debit->subjects);

        // El EndToEndId no es una referencia del banco
        self::assertNull($bare->reference);
        self::assertSame(-10.0, $bare->amount);
        self::assertSame([], $bare->subjects);
    }

    public function testReadsBbvaQuirks(): void
    {
        $statements = $this->parse('ideatic/bbva.v6.xml')->statements;

        self::assertCount(1, $statements);
        $statement = $statements[0];

        // IBAN en Othr, sin divisa de cuenta ni titular
        self::assertSame('ES9121000418450200051332', $statement->iban);
        self::assertSame('EUR', $statement->currency);
        self::assertNull($statement->owner);

        // OPBD y CLBD intercambiados: vale el saldo más reciente
        self::assertSame(1234.56, $statement->balance);
        self::assertSame(self::timestamp('2026-07-01'), $statement->dateStart);
        self::assertSame(self::timestamp('2026-10-04'), $statement->dateEnd);

        self::assertCount(3, $statement->entries);
        [$transfer, $payment, $unnamed] = $statement->entries;

        // Importe con coma decimal y signo; en un cargo la contraparte es el beneficiario; '-' es un valor vacío
        self::assertSame(-661.89, $transfer->amount);
        self::assertSame('260927000000000000000000000000000001', $transfer->reference);
        self::assertSame(self::timestamp('2026-09-28'), $transfer->date);
        self::assertSame(self::timestamp('2026-09-27'), $transfer->valueDate);
        self::assertSame(['OTRA CUENTA', 'TRASPASO A OTRA CUENTA'], $transfer->subjects);

        // En un abono la contraparte es el ordenante
        self::assertSame(147.97, $payment->amount);
        self::assertSame(['CLIENTE DE PRUEBA', 'Factura 2026-17'], $payment->subjects);

        // Importe con punto de miles
        self::assertSame(-2369.06, $unnamed->amount);
        self::assertSame([], $unnamed->subjects);
    }

    private function parse(string $fixture): Banks_Camt053
    {
        $content = file_get_contents(self::FIXTURES . "/{$fixture}");
        self::assertIsString($content);

        $camt = new Banks_Camt053();
        $camt->parse($content);

        return $camt;
    }

    private static function timestamp(string $date): int
    {
        return new DateTime("{$date} 12:00:00")->getTimestamp();
    }
}
