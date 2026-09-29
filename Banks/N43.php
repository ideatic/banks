<?php

declare(strict_types=1);

/**
 * Importación y tratamiento de los extractos bancarios españoles que siguen la norma/cuaderno 43 de la 'Asociación Española de la Banca'.
 * Puede consultarse la especificación del formato en https://docs.bankinter.com/stf/plataformas/empresas/gestion/ficheros/formatos_fichero/norma_43_castellano.pdf.
 */
class Banks_N43
{
    /**
     * Cuentas leídas del fichero
     * @var list<Banks_N43_Account>
     */
    public array $accounts = [];

    protected ?Banks_N43_Account $_currentAccount = null;

    protected ?Banks_N43_Entry $_currentEntry = null;

    /** @var int<0, max> */
    protected int $_recordCount = 0;


    /**
     * Representa un adeudo de tu cuenta
     */
    public const string TYPE_DEBIT = 'debit';

    /**
     * Representa un abono en tu cuenta
     */
    public const string TYPE_CREDIT = 'credit';

    public const string TYPE_UNKNOWN = 'unknown';

    public function parse(string $content): void
    {
        $content = mb_convert_encoding($content, 'UTF-8', 'ISO-8859-1');

        $this->_recordCount = 0;
        foreach (explode("\n", $content) as $line) {
            if (trim($line) === '') {
                continue;
            }

            $code = substr($line, 0, 2);
            match ($code) {
                '11' => $this->parseRecord11($line),
                '22' => $this->parseRecord22($line),
                '23' => $this->parseRecord23($line),
                '24' => $this->parseRecord24($line),
                '33' => $this->parseRecord33($line),
                '88' => $this->parseRecord88($line),
                default => throw new Banks_N43_Exception("Invalid record type '{$code}' in line {$this->_recordCount}"),
            };

            $this->_recordCount++;
        }
    }

    /**
     * Entrada 11 - Registro cabecera de cuenta (obligatorio)
     */
    protected function parseRecord11(string $line): Banks_N43_Account
    {
        $data = [
            'bank' => substr($line, 2, 4),
            'office' => substr($line, 6, 4),
            'account' => substr($line, 10, 10),
            'number' => substr($line, 2, 18),
            'date_start' => self::parseDate(substr($line, 20, 6)),
            'date_end' => self::parseDate(substr($line, 26, 6)),
            'type' => self::parseType(substr($line, 32, 1)),
            'balance_initial' => floatval(substr($line, 33, 12) . '.' . substr($line, 45, 2)),
            'currency' => Banks_Helper::currencyNumberToCode(intval(substr($line, 47, 3))),
            'mode' => intval(substr($line, 50, 1)),// 1, 2 o 3
            'owner_name' => trim(substr($line, 51, 26)),
            'entries' => [],
        ];
        $data['IBAN'] = self::cccToIban(
            'ES',
            $data['bank'] . $data['office'] . self::calculateCccDigit($data['bank'], $data['office'], $data['account']) . $data['account']
        );

        if ($data['type'] === self::TYPE_DEBIT) {
            $data['balance_initial'] *= -1;
        }

        $account = new Banks_N43_Account();
        foreach ($data as $k => $v) {
            $account->$k = $v;
        }

        $this->_currentAccount = $account;
        $this->_currentEntry = null;
        $this->accounts[] = $account;

        return $account;
    }

    private static function calculateCccDigit(string $entidad, string $oficina, string $cuenta): string
    {
        // Dígito de control
        $dc = "";
        $weights = [6, 3, 7, 9, 10, 5, 8, 4, 2, 1];

        foreach ([$entidad . $oficina, $cuenta] as $cadena) {
            $sum = 0;
            for ($i = 0, $len = strlen($cadena); $i < $len; $i++) {
                $sum += $weights[$i] * intval(substr($cadena, $len - $i - 1, 1));
            }
            $digito = 11 - $sum % 11;
            if ($digito === 11) {
                $digito = 0;
            } elseif ($digito === 10) {
                $digito = 1;
            }
            $dc .= $digito;
        }

        return $dc;
    }

    /**
     * @param non-empty-string $codigoPais
     * @return non-empty-string
     */
    private static function cccToIban(string $codigoPais, string $ccc): string
    {
        $pesos = [
            'A' => '10',
            'B' => '11',
            'C' => '12',
            'D' => '13',
            'E' => '14',
            'F' => '15',
            'G' => '16',
            'H' => '17',
            'I' => '18',
            'J' => '19',
            'K' => '20',
            'L' => '21',
            'M' => '22',
            'N' => '23',
            'O' => '24',
            'P' => '25',
            'Q' => '26',
            'R' => '27',
            'S' => '28',
            'T' => '29',
            'U' => '30',
            'V' => '31',
            'W' => '32',
            'X' => '33',
            'Y' => '34',
            'Z' => '35',
        ];
        $dividendo = $ccc . $pesos[substr($codigoPais, 0, 1)] . $pesos[substr($codigoPais, 1, 1)] . '00';
        $digitoControl = str_pad((string)(98 - intval(bcmod($dividendo, '97'))), 2, '0', STR_PAD_LEFT);

        return $codigoPais . $digitoControl . $ccc;
    }

    /**
     * Entrada 22 - Registro principal de movimiento (obligatorio)
     */
    protected function parseRecord22(string $line): Banks_N43_Entry
    {
        $data = [
            'office' => ltrim(trim(substr($line, 6, 4)), '0'),
            'date' => self::parseDate(substr($line, 10, 6)),
            'date_raw' => substr($line, 10, 6),
            'date_value' => self::parseDate(substr($line, 16, 6)),
            'concept_common' => substr($line, 22, 2),
            'concept_own' => substr($line, 24, 3),
            'type' => self::parseType(substr($line, 27, 1)),
            'amount' => floatval(substr($line, 28, 12) . '.' . substr($line, 40, 2)),
            'document' => ltrim(trim(substr($line, 42, 10)), '0'),
            'refererence_1' => ltrim(trim(substr($line, 52, 12)), '0'),
            'refererence_2' => trim(substr($line, 64, 16)),
            'raw' => $line,
            'concepts' => [],
        ];

        $entry = new Banks_N43_Entry();
        foreach ($data as $k => $v) {
            $entry->$k = $v;
        }

        $this->_currentEntry = $entry;
        $this->currentAccount()->entries[] = $entry;

        return $entry;
    }


    /**
     * Entrada 23 - Registros complementarios de concepto (opcionales y hasta un máximo de 5)
     */
    protected function parseRecord23(string $line): Banks_N43_Entry
    {
        $entry = $this->currentEntry();
        $entry->concepts[substr($line, 2, 2)] = trim(substr($line, 4));

        return $entry;
    }


    /**
     * Entrada 24 - Registro complementario de información de equivalencia del importe (opcional y sin valor contable)
     */
    protected function parseRecord24(string $line): Banks_N43_Entry
    {
        $entry = $this->currentEntry();
        $entry->currency_eq = Banks_Helper::currencyNumberToCode(intval(substr($line, 4, 3)));
        $entry->amount_eq = floatval(substr($line, 7, 12) . '.' . substr($line, 19, 2));

        return $entry;
    }


    /**
     * Entrada 33 - Registro final de cuenta
     */
    protected function parseRecord33(string $line): Banks_N43_Account
    {
        $account = $this->currentAccount();
        $account->balance_end = floatval(substr($line, 59, 12) . '.' . substr($line, 71, 2));
        if (substr($line, 58, 1) === '1') {
            $account->balance_end *= -1;
        }


        /*Comprobaciones*/
        /* # Group level checks
        debit_count = 0
        debit = 0.0
        credit_count = 0
        credit = 0.0
        for st_line in st_group['lines']:
            if st_line['importe'] < 0:
                debit_count += 1
                debit -= st_line['importe']
            else:
                credit_count += 1
                credit += st_line['importe']
        if st_group['num_debe'] != debit_count:
            raise exceptions.Warning(
                _("Number of debit records doesn't match with the defined in "
                  "the last record of account."))
        if st_group['num_haber'] != credit_count:
            raise exceptions.Warning(
                _('Error in C43 file'),
                _("Number of credit records doesn't match with the defined "
                  "in the last record of account."))
        if abs(st_group['debe'] - debit) > 0.005:
            raise exceptions.Warning(
                _('Error in C43 file'),
                _("Debit amount doesn't match with the defined in the last "
                  "record of account."))
        if abs(st_group['haber'] - credit) > 0.005:
            raise exceptions.Warning(
                _("Credit amount doesn't match with the defined in the last "
                  "record of account."))
        # Note: Only perform this check if the balance is defined on the file
        # record, as some banks may leave it empty (zero) on some circumstances
        # (like CaixaNova extracts for VISA credit cards).
        if st_group['saldo_fin'] and st_group['saldo_ini']:
            balance = st_group['saldo_ini'] + credit - debit
            if abs(st_group['saldo_fin'] - balance) > 0.005:
                raise exceptions.Warning(
                    _("Final balance amount = (initial balance + credit "
                      "- debit) doesn't match with the defined in the last "
                      "record of account."))*/

        return $account;
    }

    /**
     * Entrada 88 - Registro de fin de archivo
     *
     * @throws Banks_N43_Exception
     */
    protected function parseRecord88(string $line): void
    {
        $recordCount = substr($line, 20, 6);

        if (intval($recordCount) !== $this->_recordCount) {
            throw new Banks_N43_Exception("Number of records ({$this->_recordCount}) doesn't match with the defined in the last record ({$recordCount}).");
        }
    }

    /**
     * @throws Banks_N43_Exception
     */
    private function currentAccount(): Banks_N43_Account
    {
        return $this->_currentAccount ?? throw new Banks_N43_Exception("Record in line {$this->_recordCount} requires a previous account header (11) record");
    }

    /**
     * @throws Banks_N43_Exception
     */
    private function currentEntry(): Banks_N43_Entry
    {
        return $this->_currentEntry ?? throw new Banks_N43_Exception("Record in line {$this->_recordCount} requires a previous entry (22) record");
    }

    /**
     * @return self::TYPE_*
     */
    private static function parseType(string $type): string
    {
        return match ($type) {
            '1' => self::TYPE_DEBIT,
            '2' => self::TYPE_CREDIT,
            default => self::TYPE_UNKNOWN,
        };
    }

    /**
     * @throws Banks_N43_Exception
     */
    private static function parseDate(string $date): int
    {
        // Crear a las 12 del mediodía para evitar problemas con los desfases horarios
        $dateTime = DateTime::createFromFormat('!ymd', $date);
        if ($dateTime === false || $dateTime->format('ymd') !== $date) {
            throw new Banks_N43_Exception("Invalid date '{$date}'");
        }

        return $dateTime->setTime(12, 0, 0)->getTimestamp();
    }
}

class Banks_N43_Exception extends Exception {}

/**
 * @property string $bank
 * @property string $office
 * @property string $account
 * @property string $number
 * @property non-empty-string $IBAN
 * @property int $date_start
 * @property int $date_end
 * @property Banks_N43::TYPE_* $type
 * @property float $balance_initial
 * @property float $balance_end
 * @property non-empty-string $currency
 * @property int $mode
 * @property string $owner_name
 * @property list<Banks_N43_Entry> $entries
 */
class Banks_N43_Account extends stdClass {}


/**
 * @property string $office
 * @property int $date     Fecha de la operación, en formato marca temporal UNIX
 * @property string $date_raw Fecha de la operación, en formato original
 * @property int $date_value
 * @property string $concept_common
 * @property string $concept_own
 * @property Banks_N43::TYPE_* $type
 * @property float $amount
 * @property string $document
 * @property string $refererence_1
 * @property string $refererence_2
 * @property string $raw      Registro completo sin procesar
 * @property array<int|string, string> $concepts Conceptos complementarios, indexados por su código ('01' a '05')
 * @property non-empty-string $currency_eq Divisa del importe equivalente (registro 24)
 * @property float $amount_eq Importe equivalente (registro 24)
 */
class Banks_N43_Entry extends stdClass {}
