<?php

declare(strict_types=1);

namespace Banks\Tests;

use Banks_N43;
use Banks_N43_Account;
use DateTime;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests with real sample files from other open source N43 parsers.
 * See tests/fixtures/n43/README.md for their sources and licenses.
 */
#[CoversClass(Banks_N43::class)]
final class N43FixturesTest extends TestCase
{
    private const string FIXTURES = __DIR__ . '/fixtures/n43';

    /**
     * @return iterable<string, array{string}>
     */
    public static function fixtureProvider(): iterable
    {
        yield 'sequra/example1' => ['sequra/example1.n43'];
        yield 'sequra/example2' => ['sequra/example2.n43'];
        yield 'sergief/movements' => ['sergief/movements.n43'];
    }

    #[DataProvider('fixtureProvider')]
    public function testAccountsHaveValidIban(string $fixture): void
    {
        $accounts = $this->parse($fixture)->accounts;

        self::assertNotEmpty($accounts);
        foreach ($accounts as $account) {
            self::assertMatchesRegularExpression('/^ES\d{22}$/', $account->IBAN);
            self::assertSame($account->account, substr($account->IBAN, -10));
            self::assertSame('1', bcmod(self::ibanToDigits($account->IBAN), '97'), "Invalid IBAN checksum: {$account->IBAN}");
        }
    }

    #[DataProvider('fixtureProvider')]
    public function testEntriesAreConsistent(string $fixture): void
    {
        foreach ($this->parse($fixture)->accounts as $account) {
            self::assertNotEmpty($account->entries);
            foreach ($account->entries as $entry) {
                self::assertContains($entry->type, [Banks_N43::TYPE_DEBIT, Banks_N43::TYPE_CREDIT]);
                self::assertGreaterThan(0, $entry->amount);
                self::assertSame(self::timestamp($entry->date_raw), $entry->date);
                self::assertStringStartsWith('22', $entry->raw);
                self::assertLessThanOrEqual(80, strlen(rtrim($entry->raw, "\r")));
                self::assertSame(trim($entry->refererence_1), $entry->refererence_1);
                self::assertSame(trim($entry->refererence_2), $entry->refererence_2);
            }
        }
    }

    #[DataProvider('fixtureProvider')]
    public function testLineEndingsDoNotChangeTheResult(string $fixture): void
    {
        $content = $this->read($fixture);
        $crlf = new Banks_N43();
        $crlf->parse(str_replace(["\r\n", "\n"], ["\n", "\r\n"], $content));
        $lf = new Banks_N43();
        $lf->parse(str_replace("\r\n", "\n", $content));

        self::assertEquals(self::withoutRaw($lf), self::withoutRaw($crlf));
    }

    /**
     * CRLF line endings, lines shorter than 80 characters, no end of file (88) record.
     */
    public function testSequraExample1(): void
    {
        $n43 = $this->parse('sequra/example1.n43');

        self::assertCount(1, $n43->accounts);
        $account = $n43->accounts[0];
        self::assertSame('9999', $account->bank);
        self::assertSame('1111', $account->office);
        self::assertSame('0123456789', $account->account);
        self::assertSame('ES1799991111710123456789', $account->IBAN);
        self::assertSame(self::timestamp('040804'), $account->date_start);
        self::assertSame(self::timestamp('040905'), $account->date_end);
        self::assertSame(Banks_N43::TYPE_CREDIT, $account->type);
        self::assertSame(1234.56, $account->balance_initial);
        self::assertSame(788899999999.99, $account->balance_end);
        self::assertSame('EUR', $account->currency);
        self::assertSame('MY ACCOUNT', $account->owner_name);

        self::assertSame(
            [
                ['6700', '040408', 12.34, ['01' => 'XXXXXXXXX']],
                ['6700', '040408', 12.34, ['01' => 'A28152585']],
                ['1128', '040405', 12.34, ['01' => 'XXXXXXXXX']],
                ['1127', '040805', 12.34, ['01' => 'REF XXXXXXXXX']],
            ],
            array_map(
                static fn($entry) => [$entry->office, $entry->date_raw, $entry->amount, $entry->concepts],
                $account->entries,
            ),
        );
        self::assertSame('02', $account->entries[3]->concept_common);
        self::assertSame('009', $account->entries[3]->concept_own);
    }

    /**
     * LF line endings, two concept (23) records per entry, no account end (33) or end of file (88) records.
     */
    public function testSequraExample2(): void
    {
        $n43 = $this->parse('sequra/example2.n43');

        self::assertCount(1, $n43->accounts);
        $account = $n43->accounts[0];
        self::assertSame('0081', $account->bank);
        self::assertSame('4797', $account->office);
        self::assertSame('6995216857', $account->account);
        self::assertSame('ES0700814797516995216857', $account->IBAN);
        self::assertSame(self::timestamp('250317'), $account->date_start);
        self::assertSame(self::timestamp('250317'), $account->date_end);
        self::assertSame(86145.71, $account->balance_initial);
        self::assertSame('SEQURA WORLDWIDE S.A.', $account->owner_name);
        self::assertFalse(isset($account->balance_end));

        self::assertCount(4, $account->entries);
        [$transfer, $ownTransfer, $bizum] = $account->entries;

        self::assertSame(Banks_N43::TYPE_CREDIT, $transfer->type);
        self::assertSame(97.26, $transfer->amount);
        self::assertSame('7013', $transfer->office);
        self::assertSame(self::timestamp('250317'), $transfer->date);
        self::assertSame(self::timestamp('250314'), $transfer->date_value);
        self::assertSame('04', $transfer->concept_common);
        self::assertSame('007', $transfer->concept_own);
        self::assertSame('6871166755', $transfer->document);
        self::assertSame('TRANSFERENCI', $transfer->refererence_1);
        self::assertSame('A48555633617', $transfer->refererence_2);
        self::assertSame(
            ['01' => str_pad('MARIA NARANJO ENFLOR', 38) . 'A48555633617', '02' => '01826874'],
            $transfer->concepts,
        );

        self::assertSame(74.33, $ownTransfer->amount);
        self::assertSame('SEQURA', $ownTransfer->refererence_2);
        self::assertSame(['01' => 'LAURA MARTINEZ PEREZ', '02' => '01822011'], $ownTransfer->concepts);

        self::assertSame(82.25, $bizum->amount);
        self::assertSame('99', $bizum->concept_common);
        self::assertSame('051', $bizum->concept_own);
        self::assertSame('', $bizum->document);
        self::assertSame('BIZUM', $bizum->refererence_1);
        self::assertSame('', $bizum->refererence_2);

        self::assertSame(88.94, $account->entries[3]->amount);
    }

    /**
     * CRLF line endings, complete file with account end (33) and end of file (88) records.
     */
    public function testSergiefMovements(): void
    {
        $n43 = $this->parse('sergief/movements.n43');

        self::assertCount(1, $n43->accounts);
        $account = $n43->accounts[0];
        self::assertSame('1111', $account->bank);
        self::assertSame('2222', $account->office);
        self::assertSame('3333444412', $account->account);
        self::assertSame('ES4811112222033333444412', $account->IBAN);
        self::assertSame(self::timestamp('200203'), $account->date_start);
        self::assertSame(self::timestamp('200210'), $account->date_end);
        self::assertSame(2463.43, $account->balance_initial);
        self::assertSame(2301.59, $account->balance_end);
        self::assertSame('ACCOUNT NAME ************', $account->owner_name);
        self::assertCount(16, $account->entries);

        // The account end (33) record has 15 debits for 661.84 and 1 credit for 500.00
        $debits = array_filter($account->entries, static fn($entry) => $entry->type === Banks_N43::TYPE_DEBIT);
        $credits = array_filter($account->entries, static fn($entry) => $entry->type === Banks_N43::TYPE_CREDIT);
        self::assertCount(15, $debits);
        self::assertCount(1, $credits);
        $debitTotal = round(array_sum(array_map(static fn($entry) => $entry->amount, $debits)), 2);
        $creditTotal = round(array_sum(array_map(static fn($entry) => $entry->amount, $credits)), 2);
        self::assertSame(661.84, $debitTotal);
        self::assertSame(500.0, $creditTotal);
        self::assertSame($account->balance_end, round($account->balance_initial + $creditTotal - $debitTotal, 2));

        $first = $account->entries[0];
        self::assertSame('2222', $first->office);
        self::assertSame(self::timestamp('200203'), $first->date);
        self::assertSame(self::timestamp('200204'), $first->date_value);
        self::assertSame('12', $first->concept_common);
        self::assertSame('408', $first->concept_own);
        self::assertSame(23.99, $first->amount);
        self::assertSame('', $first->document);
        self::assertSame('', $first->refererence_1);
        self::assertSame('1234567890123456', $first->refererence_2);
        self::assertSame(['01' => 'COMPRA TARG 1234XXXXXXXX3456 SHOP TO BUY SEVERAL THINGS IN THERE.'], $first->concepts);

        $insurance = $account->entries[1];
        self::assertSame(70.29, $insurance->amount);
        self::assertSame('AHSOWMSOWI87', $insurance->refererence_1);
        self::assertSame('65SJWISU76WU', $insurance->refererence_2);

        $payroll = array_values($credits)[0];
        self::assertSame(500.0, $payroll->amount);
        self::assertSame('15', $payroll->concept_common);
        self::assertSame(['01' => 'PAYROLL'], $payroll->concepts);
    }

    private function parse(string $fixture): Banks_N43
    {
        $n43 = new Banks_N43();
        $n43->parse($this->read($fixture));

        return $n43;
    }

    private function read(string $fixture): string
    {
        $content = file_get_contents(self::FIXTURES . '/' . $fixture);
        self::assertIsString($content);

        return $content;
    }

    private static function timestamp(string $date): int
    {
        $dateTime = DateTime::createFromFormat('!ymd', $date);
        self::assertInstanceOf(DateTime::class, $dateTime);

        return $dateTime->setTime(12, 0)->getTimestamp();
    }

    /**
     * Independent IBAN check (ISO 13616): move the first 4 characters to the end and replace letters with numbers.
     */
    private static function ibanToDigits(string $iban): string
    {
        $digits = '';
        foreach (str_split(substr($iban, 4) . substr($iban, 0, 4)) as $char) {
            $digits .= ctype_alpha($char) ? (string)(ord($char) - ord('A') + 10) : $char;
        }

        return $digits;
    }

    /**
     * @return list<Banks_N43_Account>
     */
    private static function withoutRaw(Banks_N43 $n43): array
    {
        $accounts = [];
        foreach ($n43->accounts as $account) {
            $copy = clone $account;
            $copy->entries = [];
            foreach ($account->entries as $entry) {
                $entryCopy = clone $entry;
                $entryCopy->raw = '';
                $copy->entries[] = $entryCopy;
            }
            $accounts[] = $copy;
        }

        return $accounts;
    }
}
