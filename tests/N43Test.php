<?php

declare(strict_types=1);

namespace Banks\Tests;

use Banks_N43;
use Banks_N43_Exception;
use DateTime;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Banks_N43::class)]
final class N43Test extends TestCase
{
    public function testParsesAccountHeader(): void
    {
        $n43 = $this->parse([
            $this->accountHeader(),
            $this->accountFooter(),
            $this->fileFooter(2),
        ]);

        self::assertCount(1, $n43->accounts);
        $account = $n43->accounts[0];

        self::assertSame('2100', $account->bank);
        self::assertSame('0418', $account->office);
        self::assertSame('0200051332', $account->account);
        self::assertSame('210004180200051332', $account->number);
        self::assertSame($this->timestamp('2024-01-01'), $account->date_start);
        self::assertSame($this->timestamp('2024-01-31'), $account->date_end);
        self::assertSame(Banks_N43::TYPE_CREDIT, $account->type);
        self::assertSame(1234.56, $account->balance_initial);
        self::assertSame('EUR', $account->currency);
        self::assertSame(3, $account->mode);
        self::assertSame('ACME SL', $account->owner_name);
        self::assertSame([], $account->entries);
    }

    public function testCalculatesSpanishIban(): void
    {
        $n43 = $this->parse([
            $this->accountHeader(),
            $this->accountFooter(),
            $this->fileFooter(2),
        ]);

        self::assertSame('ES9121000418450200051332', $n43->accounts[0]->IBAN);
    }

    public function testCalculatesIbanWithZeroControlDigits(): void
    {
        $n43 = $this->parse([
            $this->accountHeader(bank: '0000', office: '0000', account: '0000000000'),
            $this->accountFooter(),
            $this->fileFooter(2),
        ]);

        self::assertSame('ES8200000000000000000000', $n43->accounts[0]->IBAN);
    }

    public function testDebitInitialBalanceIsNegative(): void
    {
        $n43 = $this->parse([
            $this->accountHeader(type: '1', balance: '00000000100050'),
            $this->accountFooter(),
            $this->fileFooter(2),
        ]);

        self::assertSame(Banks_N43::TYPE_DEBIT, $n43->accounts[0]->type);
        self::assertSame(-1000.5, $n43->accounts[0]->balance_initial);
    }

    public function testUnknownAccountBalanceType(): void
    {
        $n43 = $this->parse([
            $this->accountHeader(type: '9'),
            $this->accountFooter(),
            $this->fileFooter(2),
        ]);

        self::assertSame(Banks_N43::TYPE_UNKNOWN, $n43->accounts[0]->type);
        self::assertSame(1234.56, $n43->accounts[0]->balance_initial);
    }

    public function testParsesEntry(): void
    {
        $n43 = $this->parse([
            $this->accountHeader(),
            $this->entry(),
            $this->accountFooter(),
            $this->fileFooter(3),
        ]);

        $entries = $n43->accounts[0]->entries;
        self::assertCount(1, $entries);
        $entry = $entries[0];

        self::assertSame('418', $entry->office);
        self::assertSame($this->timestamp('2024-01-15'), $entry->date);
        self::assertSame('240115', $entry->date_raw);
        self::assertSame($this->timestamp('2024-01-16'), $entry->date_value);
        self::assertSame('02', $entry->concept_common);
        self::assertSame('123', $entry->concept_own);
        self::assertSame(Banks_N43::TYPE_CREDIT, $entry->type);
        self::assertSame(250.75, $entry->amount);
        self::assertSame('12345', $entry->document);
        self::assertSame('987654', $entry->refererence_1);
        self::assertSame('REF2', $entry->refererence_2);
        self::assertSame($this->entry(), $entry->raw);
        self::assertSame([], $entry->concepts);
    }

    public function testParsesDebitEntry(): void
    {
        $n43 = $this->parse([
            $this->accountHeader(),
            $this->entry(type: '1'),
            $this->accountFooter(),
            $this->fileFooter(3),
        ]);

        $entry = $n43->accounts[0]->entries[0];
        self::assertSame(Banks_N43::TYPE_DEBIT, $entry->type);
        self::assertSame(250.75, $entry->amount);
    }

    public function testParsesConcepts(): void
    {
        $n43 = $this->parse([
            $this->accountHeader(),
            $this->entry(),
            $this->concept('01', 'TRANSFERENCIA', 'CLIENTE'),
            $this->concept('02', 'FACTURA 123', ''),
            $this->accountFooter(),
            $this->fileFooter(5),
        ]);

        $concepts = $n43->accounts[0]->entries[0]->concepts;
        self::assertSame(['01', '02'], array_map('strval', array_keys($concepts)));
        self::assertSame(
            str_pad('TRANSFERENCIA', 38) . 'CLIENTE',
            $concepts['01'],
        );
        self::assertSame('FACTURA 123', $concepts['02']);
    }

    public function testParsesCurrencyEquivalence(): void
    {
        $n43 = $this->parse([
            $this->accountHeader(),
            $this->entry(),
            $this->equivalence('840', '00000000030000'),
            $this->accountFooter(),
            $this->fileFooter(4),
        ]);

        $entry = $n43->accounts[0]->entries[0];
        self::assertSame('USD', $entry->currency_eq);
        self::assertSame(300.0, $entry->amount_eq);
    }

    public function testParsesFinalBalance(): void
    {
        $n43 = $this->parse([
            $this->accountHeader(),
            $this->accountFooter(balance: '00000000148531'),
            $this->fileFooter(2),
        ]);

        self::assertSame(1485.31, $n43->accounts[0]->balance_end);
    }

    public function testDebitFinalBalanceIsNegative(): void
    {
        $n43 = $this->parse([
            $this->accountHeader(),
            $this->accountFooter(balanceSign: '1', balance: '00000000001000'),
            $this->fileFooter(2),
        ]);

        self::assertSame(-10.0, $n43->accounts[0]->balance_end);
    }

    public function testParsesSeveralAccounts(): void
    {
        $n43 = $this->parse([
            $this->accountHeader(),
            $this->entry(),
            $this->accountFooter(),
            $this->accountHeader(account: '0200051333', owner: 'OTRA SL'),
            $this->entry(amount: '00000000000100'),
            $this->entry(amount: '00000000000200'),
            $this->accountFooter(),
            $this->fileFooter(7),
        ]);

        self::assertCount(2, $n43->accounts);
        self::assertCount(1, $n43->accounts[0]->entries);
        self::assertCount(2, $n43->accounts[1]->entries);
        self::assertSame('OTRA SL', $n43->accounts[1]->owner_name);
        self::assertSame(1.0, $n43->accounts[1]->entries[0]->amount);
        self::assertSame(2.0, $n43->accounts[1]->entries[1]->amount);
    }

    public function testAcceptsWindowsLineEndings(): void
    {
        $content = implode("\r\n", [
            $this->accountHeader(),
            $this->entry(),
            $this->concept('01', 'PAGO', ''),
            $this->accountFooter(),
            $this->fileFooter(4),
        ]) . "\r\n";

        $n43 = new Banks_N43();
        $n43->parse($content);

        self::assertSame('ACME SL', $n43->accounts[0]->owner_name);
        self::assertSame('REF2', $n43->accounts[0]->entries[0]->refererence_2);
        self::assertSame('PAGO', $n43->accounts[0]->entries[0]->concepts['01']);
    }

    public function testConvertsLatin1ToUtf8(): void
    {
        $content = implode("\n", [
            $this->accountHeader(owner: 'MU' . "\xD1" . 'OZ SL'),
            $this->accountFooter(),
            $this->fileFooter(2),
        ]);

        $n43 = new Banks_N43();
        $n43->parse($content);

        self::assertSame('MUÑOZ SL', $n43->accounts[0]->owner_name);
    }

    public function testEmptyContentHasNoAccounts(): void
    {
        $n43 = new Banks_N43();
        $n43->parse('');

        self::assertSame([], $n43->accounts);
    }

    public function testRejectsUnknownRecordType(): void
    {
        $this->expectException(Banks_N43_Exception::class);
        $this->expectExceptionMessageIs("Invalid record type '99' in line 1");

        $this->parse([
            $this->accountHeader(),
            str_pad('99', 80),
        ]);
    }

    public function testRejectsRecordCountMismatch(): void
    {
        $this->expectException(Banks_N43_Exception::class);
        $this->expectExceptionMessageIs("Number of records (2) doesn't match with the defined in the last record (000005).");

        $this->parse([
            $this->accountHeader(),
            $this->accountFooter(),
            $this->fileFooter(5),
        ]);
    }

    public function testRejectsInvalidDate(): void
    {
        $this->expectException(Banks_N43_Exception::class);
        $this->expectExceptionMessageIs("Invalid date '241340'");

        $this->parse([
            $this->accountHeader(dateStart: '241340'),
        ]);
    }

    public function testRejectsEntryWithoutAccount(): void
    {
        $this->expectException(Banks_N43_Exception::class);
        $this->expectExceptionMessageIs('Record in line 0 requires a previous account header (11) record');

        $this->parse([
            $this->entry(),
        ]);
    }

    public function testRejectsConceptWithoutEntry(): void
    {
        $this->expectException(Banks_N43_Exception::class);
        $this->expectExceptionMessageIs('Record in line 1 requires a previous entry (22) record');

        $this->parse([
            $this->accountHeader(),
            $this->concept('01', 'PAGO', ''),
        ]);
    }

    public function testConceptDoesNotAttachToEntryOfPreviousAccount(): void
    {
        $this->expectException(Banks_N43_Exception::class);
        $this->expectExceptionMessageIs('Record in line 4 requires a previous entry (22) record');

        $this->parse([
            $this->accountHeader(),
            $this->entry(),
            $this->accountFooter(),
            $this->accountHeader(account: '0200051333'),
            $this->equivalence('840', '00000000030000'),
        ]);
    }

    public function testSkipsBlankLines(): void
    {
        $n43 = $this->parse([
            $this->accountHeader(),
            "\r",
            '   ',
            $this->accountFooter(),
            $this->fileFooter(2),
        ]);

        self::assertCount(1, $n43->accounts);
    }

    public function testRejectsUnknownCurrency(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs("Unrecognized currency '999'");

        $this->parse([
            $this->accountHeader(currency: '999'),
        ]);
    }

    /**
     * @param list<string> $lines
     */
    private function parse(array $lines): Banks_N43
    {
        $n43 = new Banks_N43();
        $n43->parse(implode("\n", $lines) . "\n");

        return $n43;
    }

    private function timestamp(string $date): int
    {
        return new DateTime("{$date} 12:00:00")->getTimestamp();
    }

    private function accountHeader(
        string $bank = '2100',
        string $office = '0418',
        string $account = '0200051332',
        string $dateStart = '240101',
        string $dateEnd = '240131',
        string $type = '2',
        string $balance = '00000000123456',
        string $currency = '978',
        string $mode = '3',
        string $owner = 'ACME SL',
    ): string {
        return $this->record(
            '11' . $bank . $office . $account . $dateStart . $dateEnd . $type . $balance . $currency . $mode
            . str_pad($owner, 26),
        );
    }

    private function entry(
        string $type = '2',
        string $amount = '00000000025075',
    ): string {
        return $this->record(
            '22' . '    ' . '0418' . '240115' . '240116' . '02' . '123' . $type . $amount
            . '0000012345' . '000000987654' . str_pad('REF2', 16),
        );
    }

    private function concept(string $code, string $first, string $second): string
    {
        return $this->record('23' . $code . str_pad($first, 38) . str_pad($second, 38));
    }

    private function equivalence(string $currency, string $amount): string
    {
        return $this->record('24' . '01' . $currency . $amount);
    }

    private function accountFooter(string $balanceSign = '2', string $balance = '00000000123456'): string
    {
        return $this->record(
            '33' . '2100' . '0418' . '0200051332'
            . '00000' . '00000000000000' . '00000' . '00000000000000'
            . $balanceSign . $balance . '978',
        );
    }

    private function fileFooter(int $recordCount): string
    {
        return $this->record('88' . str_repeat('9', 18) . str_pad((string)$recordCount, 6, '0', STR_PAD_LEFT));
    }

    private function record(string $content): string
    {
        self::assertLessThanOrEqual(80, strlen($content), 'N43 records are 80 characters long');

        return str_pad($content, 80);
    }
}
