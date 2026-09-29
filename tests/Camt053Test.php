<?php

declare(strict_types=1);

namespace Banks\Tests;

use Banks_Camt053;
use Banks_Camt053_Exception;
use DateTime;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Banks_Camt053::class)]
final class Camt053Test extends TestCase
{
    /**
     * @return iterable<string, array{string, int}>
     */
    public static function invalidFileProvider(): iterable
    {
        yield 'not xml' => ['not xml at all', Banks_Camt053_Exception::NOT_CAMT053];
        yield 'other iso 20022 message' => ['<?xml version="1.0"?><Document xmlns="urn:iso:std:iso:20022:tech:xsd:pain.001.001.03"><CstmrCdtTrfInitn/></Document>', Banks_Camt053_Exception::NOT_CAMT053];
        yield 'no namespace' => ['<Document><BkToCstmrStmt><Stmt/></BkToCstmrStmt></Document>', Banks_Camt053_Exception::NOT_CAMT053];
        yield 'no statements' => [self::document(''), Banks_Camt053_Exception::NO_STATEMENTS];
        yield 'no account' => [self::document('<Stmt><Acct><Id><IBAN>-</IBAN></Id></Acct></Stmt>'), Banks_Camt053_Exception::NO_ACCOUNT];
        yield 'invalid amount' => [self::statement('<Ntry><Amt>abc</Amt><BookgDt><Dt>2026-01-01</Dt></BookgDt></Ntry>'), Banks_Camt053_Exception::INVALID_AMOUNT];
        yield 'no date' => [self::statement('<Ntry><Amt>1.00</Amt></Ntry>'), Banks_Camt053_Exception::NO_DATE];
    }

    #[DataProvider('invalidFileProvider')]
    public function testRejectsInvalidFile(string $content, int $code): void
    {
        $this->expectException(Banks_Camt053_Exception::class);
        $this->expectExceptionCode($code);

        new Banks_Camt053()->parse($content);
    }

    public function testDoesNotExpandExternalEntities(): void
    {
        $content = '<?xml version="1.0"?><!DOCTYPE d [<!ENTITY x SYSTEM "file:///etc/passwd">]>'
            . self::document('<Stmt><Acct><Id><IBAN>&x;</IBAN></Id></Acct></Stmt>');

        $this->expectException(Banks_Camt053_Exception::class);
        $this->expectExceptionCode(Banks_Camt053_Exception::NO_ACCOUNT);

        new Banks_Camt053()->parse($content);
    }

    public function testNormalizesIban(): void
    {
        $camt = $this->parse(self::document('<Stmt><Acct><Id><IBAN> es91 2100 0418 4502 0005 1332 </IBAN></Id></Acct></Stmt>'));

        self::assertSame('ES9121000418450200051332', $camt->statements[0]->iban);
    }

    public function testStatementWithoutEntries(): void
    {
        $statement = $this->parse(self::statement(''))->statements[0];

        self::assertSame([], $statement->entries);
        self::assertNull($statement->currency);
        self::assertNull($statement->owner);
        self::assertNull($statement->balance);
        self::assertNull($statement->dateStart);
        self::assertNull($statement->dateEnd);
    }

    public function testPeriodFromEntriesWhenMissing(): void
    {
        $statement = $this->parse(self::statement(
            '<Ntry><Amt Ccy="USD">1.00</Amt><BookgDt><Dt>2026-03-10</Dt></BookgDt></Ntry>'
            . '<Ntry><Amt Ccy="USD">2.00</Amt><BookgDt><DtTm>2026-03-01T23:30:00</DtTm></BookgDt></Ntry>'
            . '<Ntry><Amt Ccy="USD">3.00</Amt><ValDt><Dt>2026-03-20</Dt></ValDt></Ntry>'
        ))->statements[0];

        self::assertSame(self::timestamp('2026-03-01'), $statement->dateStart);
        self::assertSame(self::timestamp('2026-03-20'), $statement->dateEnd);
        // Sin divisa de la cuenta, la del primer importe
        self::assertSame('USD', $statement->currency);
        // Sin fecha contable, la fecha valor
        self::assertSame(self::timestamp('2026-03-20'), $statement->entries[2]->date);
        self::assertSame(self::timestamp('2026-03-20'), $statement->entries[2]->valueDate);
    }

    public function testSkipsPendingAndInformationEntries(): void
    {
        $statement = $this->parse(self::statement(
            '<Ntry><Amt>1.00</Amt><Sts><Cd>PDNG</Cd></Sts><BookgDt><Dt>2026-03-10</Dt></BookgDt></Ntry>'
            . '<Ntry><Amt>2.00</Amt><Sts>info</Sts><BookgDt><Dt>2026-03-10</Dt></BookgDt></Ntry>'
            . '<Ntry><Amt>3.00</Amt><Sts>BOOK</Sts><BookgDt><Dt>2026-03-10</Dt></BookgDt></Ntry>'
        ))->statements[0];

        self::assertCount(1, $statement->entries);
        self::assertSame(3.0, $statement->entries[0]->amount);
    }

    /**
     * @return iterable<string, array{string, string, float}>
     */
    public static function amountProvider(): iterable
    {
        yield 'credit' => ['12.50', 'CRDT', 12.5];
        yield 'debit indicator' => ['12.50', 'DBIT', -12.5];
        yield 'negative sign' => ['-12.50', 'UNCATEGORIZED', -12.5];
        yield 'comma decimal' => ['12,50', 'CRDT', 12.5];
        yield 'thousands point' => ['-1.234,56', 'CRDT', -1234.56];
        yield 'thousands comma' => ['1,234.56', 'CRDT', 1234.56];
        yield 'spaces' =>[' 1 234.56 ', 'DBIT', -1234.56];
        yield 'five decimals' => ['8.84999', 'CRDT', 8.85];
    }

    #[DataProvider('amountProvider')]
    public function testReadsAmount(string $amount, string $indicator, float $expected): void
    {
        $entry = $this->parse(self::statement(
            "<Ntry><Amt>{$amount}</Amt><CdtDbtInd>{$indicator}</CdtDbtInd><BookgDt><Dt>2026-03-10</Dt></BookgDt></Ntry>"
        ))->statements[0]->entries[0];

        self::assertSame($expected, $entry->amount);
    }

    public function testReferenceFromSingleTransaction(): void
    {
        $entries = $this->parse(self::statement(
            '<Ntry><Amt>1.00</Amt><BookgDt><Dt>2026-03-10</Dt></BookgDt><NtryDtls><TxDtls><Refs><AcctSvcrRef>TX-REF</AcctSvcrRef><TxId>TX-ID</TxId></Refs></TxDtls></NtryDtls></Ntry>'
            . '<Ntry><Amt>1.00</Amt><BookgDt><Dt>2026-03-10</Dt></BookgDt><NtryDtls><TxDtls><Refs><TxId>A</TxId></Refs></TxDtls><TxDtls><Refs><TxId>B</TxId></Refs></TxDtls></NtryDtls></Ntry>'
            . '<Ntry><NtryRef>ENTRY-REF</NtryRef><Amt>1.00</Amt><BookgDt><Dt>2026-03-10</Dt></BookgDt><NtryDtls><TxDtls><Refs><TxId>TX-ID</TxId></Refs></TxDtls></NtryDtls></Ntry>'
        ))->statements[0]->entries;

        self::assertSame('TX-REF', $entries[0]->reference);
        // Una remesa no toma la referencia de ninguna de sus operaciones
        self::assertNull($entries[1]->reference);
        self::assertSame('ENTRY-REF', $entries[2]->reference);
    }

    public function testSubjectsSkipEmptyValuesAndDuplicates(): void
    {
        $entry = $this->parse(self::statement(
            '<Ntry><Amt>1.00</Amt><BookgDt><Dt>2026-03-10</Dt></BookgDt><NtryDtls>'
            . '<TxDtls><RltdPties><Dbtr><Nm>NOTPROVIDED</Nm></Dbtr></RltdPties><RmtInf><Ustrd>Cuota</Ustrd><Ustrd>Not Provided</Ustrd></RmtInf></TxDtls>'
            . '<TxDtls><RltdPties><Dbtr><Nm> SOCIO </Nm></Dbtr></RltdPties><RmtInf><Ustrd>Cuota</Ustrd></RmtInf></TxDtls>'
            . '</NtryDtls></Ntry>'
        ))->statements[0]->entries[0];

        self::assertSame(['SOCIO', 'Cuota'], $entry->subjects);
    }

    public function testParseReplacesPreviousStatements(): void
    {
        $camt = $this->parse(self::statement(''));
        $camt->parse(self::document('<Stmt><Acct><Id><IBAN>A</IBAN></Id></Acct></Stmt><Stmt><Acct><Id><IBAN>B</IBAN></Id></Acct></Stmt>'));

        self::assertSame(['A', 'B'], array_map(static fn($statement) => $statement->iban, $camt->statements));
    }

    public function testFailedParseClearsPreviousStatements(): void
    {
        $camt = $this->parse(self::statement(''));
        try {
            $camt->parse(self::document(''));
            self::fail('Expected Banks_Camt053_Exception');
        } catch (Banks_Camt053_Exception) {
        }

        self::assertSame([], $camt->statements);
    }

    private function parse(string $content): Banks_Camt053
    {
        $camt = new Banks_Camt053();
        $camt->parse($content);

        return $camt;
    }

    private static function document(string $statements): string
    {
        return "<Document xmlns=\"urn:iso:std:iso:20022:tech:xsd:camt.053.001.02\"><BkToCstmrStmt>{$statements}</BkToCstmrStmt></Document>";
    }

    private static function statement(string $entries): string
    {
        return self::document("<Stmt><Acct><Id><IBAN>ES9121000418450200051332</IBAN></Id></Acct>{$entries}</Stmt>");
    }

    private static function timestamp(string $date): int
    {
        return new DateTime("{$date} 12:00:00")->getTimestamp();
    }
}
