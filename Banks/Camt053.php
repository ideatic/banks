<?php

declare(strict_types=1);

/**
 * Lectura de extractos bancarios en formato ISO 20022 CAMT.053 (XML), en cualquiera de sus versiones.
 *
 * La lectura es tolerante: algunos bancos (p. ej. BBVA) generan ficheros que no cumplen el XSD
 * (importes con coma decimal y signo, indicadores de débito/crédito propios, IBAN en Othr...)
 */
class Banks_Camt053
{
    /**
     * Extractos leídos del fichero
     * @var list<Banks_Camt053_Statement>
     */
    public array $statements = [];

    private const string NAMESPACE_PREFIX = 'urn:iso:std:iso:20022:tech:xsd:camt.053.';

    /** Valores que algunos bancos utilizan para indicar un campo vacío */
    private const array EMPTY_VALUES = ['', '-', 'NOTPROVIDED', 'NOT PROVIDED'];

    /** Estados de apuntes que todavía no están contabilizados */
    private const array IGNORED_STATUS = ['PDNG', 'INFO'];

    /** Tipos de saldo preferidos como saldo final cuando varios comparten fecha */
    private const array CLOSING_BALANCES = ['CLBD', 'CLAV', 'ITBD'];

    /**
     * @throws Banks_Camt053_Exception
     */
    public function parse(string $content): void
    {
        $this->statements = [];

        // LIBXML_NONET y sin LIBXML_NOENT: no se cargan entidades ni recursos externos (XXE)
        $previousErrors = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($content, SimpleXMLElement::class, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrors);

        if ($xml === false || !str_starts_with($xml->getNamespaces()[''] ?? '', self::NAMESPACE_PREFIX)) {
            throw new Banks_Camt053_Exception('Unsupported XML bank statement', Banks_Camt053_Exception::NOT_CAMT053);
        }

        $statements = [];
        foreach (self::_nodes($xml, 'BkToCstmrStmt/Stmt') as $stmt) {
            $statements[] = self::_readStatement($stmt);
        }

        if ($statements === []) {
            throw new Banks_Camt053_Exception('CAMT.053 file without statements', Banks_Camt053_Exception::NO_STATEMENTS);
        }

        $this->statements = $statements;
    }

    /**
     * @throws Banks_Camt053_Exception
     */
    private static function _readStatement(SimpleXMLElement $stmt): Banks_Camt053_Statement
    {
        $iban = self::_value($stmt, 'Acct/Id/IBAN') ?? self::_value($stmt, 'Acct/Id/Othr/Id');
        if (!isset($iban)) {
            throw new Banks_Camt053_Exception('CAMT.053 statement without account', Banks_Camt053_Exception::NO_ACCOUNT);
        }

        // Movimientos
        $entries = [];
        foreach (self::_nodes($stmt, 'Ntry') as $ntry) {
            $status = self::_value($ntry, 'Sts/Cd') ?? self::_value($ntry, 'Sts');
            if (in_array(strtoupper((string)$status), self::IGNORED_STATUS, true)) {
                continue;
            }

            $entries[] = self::_readEntry($ntry);
        }

        // Periodo del extracto
        $entryDates = array_map(static fn(Banks_Camt053_Entry $entry): int => $entry->date, $entries);
        $dateStart = self::_date($stmt, 'FrToDt/FrDtTm') ?? ($entryDates === [] ? null : min($entryDates));
        $dateEnd = self::_date($stmt, 'FrToDt/ToDtTm') ?? ($entryDates === [] ? null : max($entryDates));

        // Sin divisa de la cuenta, la del primer importe
        $firstAmountCurrency = (string)(self::_nodes($stmt, 'Ntry/Amt')[0]['Ccy'] ?? '');

        return new Banks_Camt053_Statement(
            iban: strtoupper(str_replace(' ', '', $iban)),
            currency: self::_value($stmt, 'Acct/Ccy') ?? ($firstAmountCurrency !== '' ? $firstAmountCurrency : null),
            owner: self::_value($stmt, 'Acct/Ownr/Nm'),
            balance: self::_readBalance($stmt),
            dateStart: $dateStart,
            dateEnd: $dateEnd,
            entries: $entries
        );
    }

    /**
     * Obtiene el saldo más reciente del extracto
     */
    private static function _readBalance(SimpleXMLElement $stmt): ?float
    {
        $balance = $balanceDate = null;
        $balanceIsClosing = false;

        foreach (self::_nodes($stmt, 'Bal') as $bal) {
            $date = self::_date($bal, 'Dt');
            $amount = self::_amount($bal);
            if (!isset($date, $amount)) {
                continue;
            }

            // Algunos bancos intercambian OPBD y CLBD, así que se toma el saldo con la fecha más reciente
            $isClosing = in_array(self::_value($bal, 'Tp/CdOrPrtry/Cd'), self::CLOSING_BALANCES, true);
            if (!isset($balanceDate) || $date > $balanceDate || ($date === $balanceDate && $isClosing && !$balanceIsClosing)) {
                $balance = $amount;
                $balanceDate = $date;
                $balanceIsClosing = $isClosing;
            }
        }

        return $balance;
    }

    /**
     * @throws Banks_Camt053_Exception
     */
    private static function _readEntry(SimpleXMLElement $ntry): Banks_Camt053_Entry
    {
        $amount = self::_amount($ntry);
        if (!isset($amount)) {
            throw new Banks_Camt053_Exception('CAMT.053 entry with an invalid amount', Banks_Camt053_Exception::INVALID_AMOUNT);
        }

        $date = self::_date($ntry, 'BookgDt') ?? self::_date($ntry, 'ValDt');
        if (!isset($date)) {
            throw new Banks_Camt053_Exception('CAMT.053 entry without date', Banks_Camt053_Exception::NO_DATE);
        }

        $transactions = self::_nodes($ntry, 'NtryDtls/TxDtls');

        // Referencia única asignada por el banco. El EndToEndId no se usa: lo asigna el ordenante y puede repetirse
        $reference = self::_value($ntry, 'AcctSvcrRef') ?? self::_value($ntry, 'NtryRef');
        if (!isset($reference) && count($transactions) === 1) {
            $reference = self::_value($transactions[0], 'Refs/AcctSvcrRef') ?? self::_value($transactions[0], 'Refs/TxId');
        }

        // Concepto: primero lo más útil para la conciliación (contraparte y concepto de la transferencia)
        $counterparties = $remittances = $structured = $additional = $mandates = [];
        foreach ($transactions as $transaction) {
            foreach ($amount < 0 ? ['UltmtCdtr', 'Cdtr'] : ['UltmtDbtr', 'Dbtr'] as $role) {
                $name = self::_value($transaction, "RltdPties/{$role}/Nm") ?? self::_value($transaction, "RltdPties/{$role}/Pty/Nm");
                if (isset($name)) {
                    $counterparties[] = $name;
                    break;
                }
            }

            array_push($remittances, ...self::_values($transaction, 'RmtInf/Ustrd'));
            array_push($structured, ...self::_values($transaction, 'RmtInf/Strd/CdtrRefInf/Ref'), ...self::_values($transaction, 'RmtInf/Strd/RfrdDocInf/Nb'));
            array_push($additional, ...self::_values($transaction, 'AddtlTxInf'));
            array_push($mandates, ...self::_values($transaction, 'Refs/MndtId'));
        }

        return new Banks_Camt053_Entry(
            reference: $reference,
            amount: $amount,
            date: $date,
            valueDate: self::_date($ntry, 'ValDt') ?? $date,
            subjects: array_values(array_unique([...$counterparties, ...$remittances, ...$structured, ...$additional, ...self::_values($ntry, 'AddtlNtryInf'), ...$mandates]))
        );
    }

    /**
     * Obtiene el importe con signo de un apunte o saldo
     */
    private static function _amount(SimpleXMLElement $node): ?float
    {
        $raw = str_replace(' ', '', self::_value($node, 'Amt') ?? '');
        $comma = strrpos($raw, ',');
        $dot = strrpos($raw, '.');
        if ($comma !== false && ($dot === false || $comma > $dot)) { // Coma decimal, con posible punto de miles
            $raw = str_replace(['.', ','], ['', '.'], $raw);
        } elseif ($comma !== false) { // Punto decimal, con coma de miles
            $raw = str_replace(',', '', $raw);
        }

        if (!is_numeric($raw)) {
            return null;
        }

        $amount = abs(round((float)$raw, 2));

        return str_starts_with($raw, '-') || self::_value($node, 'CdtDbtInd') === 'DBIT' ? -$amount : $amount;
    }

    /**
     * Obtiene una fecha (`Dt` o `DtTm`, o el propio nodo) como marca temporal a las 12:00, igual que el formato N43
     */
    private static function _date(SimpleXMLElement $node, string $path): ?int
    {
        $value = self::_value($node, "{$path}/Dt") ?? self::_value($node, "{$path}/DtTm") ?? self::_value($node, $path);
        $date = isset($value) ? DateTime::createFromFormat('!Y-m-d', substr($value, 0, 10)) : false;

        return $date !== false ? $date->setTime(12, 0)->getTimestamp() : null;
    }

    /**
     * Obtiene el primer valor no vacío de la ruta indicada
     */
    private static function _value(SimpleXMLElement $node, string $path): ?string
    {
        return self::_values($node, $path)[0] ?? null;
    }

    /**
     * Obtiene los valores no vacíos de la ruta indicada
     *
     * @return list<string>
     */
    private static function _values(SimpleXMLElement $node, string $path): array
    {
        $values = [];
        foreach (self::_nodes($node, $path) as $child) {
            $value = mb_trim((string)$child);
            if (!in_array(strtoupper($value), self::EMPTY_VALUES, true)) {
                $values[] = $value;
            }
        }

        return $values;
    }

    /**
     * Obtiene los nodos de la ruta indicada (nombres separados por `/`), sin tener en cuenta la versión del espacio de nombres
     *
     * @return list<SimpleXMLElement>
     */
    private static function _nodes(SimpleXMLElement $node, string $path): array
    {
        $nodes = [$node];
        foreach (explode('/', $path) as $name) {
            $children = [];
            foreach ($nodes as $current) {
                $found = $current->{$name};
                if ($found instanceof SimpleXMLElement) {
                    foreach ($found as $child) {
                        $children[] = $child;
                    }
                }
            }
            $nodes = $children;
        }

        return $nodes;
    }
}

class Banks_Camt053_Exception extends Exception
{
    /** El fichero no es un XML CAMT.053 */
    public const int NOT_CAMT053 = 1;

    /** El fichero no contiene ningún extracto */
    public const int NO_STATEMENTS = 2;

    /** Un extracto no indica la cuenta bancaria */
    public const int NO_ACCOUNT = 3;

    /** Un apunte tiene un importe no válido */
    public const int INVALID_AMOUNT = 4;

    /** Un apunte no tiene fecha */
    public const int NO_DATE = 5;
}

final readonly class Banks_Camt053_Statement
{
    /**
     * @param string                    $iban      IBAN (o número de cuenta, si el banco no indica el IBAN), sin espacios y en mayúsculas
     * @param int|null                  $dateStart Inicio del periodo del extracto, o del primer apunte si no lo indica. Null si no hay ninguno
     * @param int|null                  $dateEnd   Fin del periodo del extracto, o del último apunte si no lo indica. Null si no hay ninguno
     * @param list<Banks_Camt053_Entry> $entries   Apuntes contabilizados (sin los pendientes ni los informativos)
     */
    public function __construct(
        public string $iban,
        public ?string $currency,
        public ?string $owner,
        public ?float $balance,
        public ?int $dateStart,
        public ?int $dateEnd,
        public array $entries,
    ) {
    }
}

final readonly class Banks_Camt053_Entry
{
    /**
     * @param string|null  $reference Referencia única asignada por el banco
     * @param float        $amount    Importe con signo: negativo para los cargos
     * @param int          $date      Fecha contable, marca temporal a las 12:00
     * @param int          $valueDate Fecha valor, marca temporal a las 12:00
     * @param list<string> $subjects  Textos del apunte, de más a menos útil para la conciliación: contraparte, concepto, referencias...
     */
    public function __construct(
        public ?string $reference,
        public float $amount,
        public int $date,
        public int $valueDate,
        public array $subjects,
    ) {
    }
}
