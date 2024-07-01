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
     * @var Banks_N43_Account[]
     */
    public array $accounts = [];

    protected Banks_N43_Account $_currentAccount;

    protected Banks_N43_Entry $_currentEntry;

    protected int $_recordCount;


    /**
     * Representa un adeudo de tu cuenta
     */
    public const TYPE_DEBIT = 'debit';

    /**
     * Representa un abono en tu cuenta
     */
    public const TYPE_CREDIT = 'credit';

    public const TYPE_UNKNOWN = 'unknown';

    public function parse(string $content): void
    {
        $content = mb_convert_encoding($content, 'UTF-8', 'ISO-8859-1');

        $this->_recordCount = 0;
        foreach (explode("\n", $content) as $line) {
            if (!$line) {
                continue;
            }

            $code = intval(substr($line, 0, 2));

            $method_name = "_parse_record_{$code}";
            if (method_exists($this, $method_name)) {
                $this->$method_name($line);
            } else {
                throw new Banks_N43_Exception("Invalid record type '{$code}' in line {$this->_recordCount}");
            }

            $this->_recordCount++;
        }
    }

    /**
     * Entrada 11 - Registro cabecera de cuenta (obligatorio)
     */
    protected function _parse_record_11(string $line): Banks_N43_Account
    {
        $data = [
            'bank' => substr($line, 2, 4),
            'office' => substr($line, 6, 4),
            'account' => substr($line, 10, 10),
            'number' => substr($line, 2, 18),
            'date_start' => self::_parse_date(substr($line, 20, 6)),
            'date_end' => self::_parse_date(substr($line, 26, 6)),
            'type' => substr($line, 32, 1) == 1 ? self::TYPE_DEBIT : (substr($line, 32, 1) == 2 ? self::TYPE_CREDIT : self::TYPE_UNKNOWN),
            'balance_initial' => floatval(substr($line, 33, 12) . '.' . substr($line, 45, 2)),
            'currency' => Banks_Helper::currency_number2code(intval(substr($line, 47, 3))),
            'mode' => substr($line, 50, 1),// 1, 2 o 3
            'owner_name' => trim(substr($line, 51, 26)),
            'entries' => []
        ];
        $data['IBAN'] = self::_cccToIBAN('ES', $data['bank'] . $data['office'] . self::_calculateCccDigit($data['bank'], $data['office'], $data['account']) . $data['account']);

        if ($data['type'] == self::TYPE_DEBIT) {
            $data['balance_initial'] *= -1;
        }

        $account = new Banks_N43_Account();
        foreach ($data as $k => $v) {
            $account->$k = $v;
        }

        $this->_currentAccount = $account;
        $this->accounts[] = $account;

        return $account;
    }

    private static function _calculateCccDigit(string $entidad, string $oficina, string $cuenta)
    {
        // Dígito de control
        $dc = "";
        $pesos = array(6, 3, 7, 9, 10, 5, 8, 4, 2, 1);

        foreach (array($entidad . $oficina, $cuenta) as $cadena) {
            $suma = 0;
            for ($i = 0, $len = strlen($cadena); $i < $len; $i++) {
                $suma += $pesos[$i] * substr($cadena, $len - $i - 1, 1);
            }
            $digito = 11 - $suma % 11;
            if ($digito == 11) {
                $digito = 0;
            } elseif ($digito == 10) {
                $digito = 1;
            }
            $dc .= $digito;
        }

        return $dc;
    }

    private static function _cccToIBAN(string $codigoPais, string $ccc): string
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
            'Z' => '35'
        ];
        $dividendo = $ccc . $pesos[substr($codigoPais, 0, 1)] . $pesos[substr($codigoPais, 1, 1)] . '00';
        $digitoControl = 98 - bcmod($dividendo, '97');
        if (strlen((string)$digitoControl) == 1) {
            $digitoControl = "0{$digitoControl}";
        }
        return $codigoPais . $digitoControl . $ccc;
    }

    /**
     * Entrada 22 - Registro principal de movimiento (obligatorio)
     */
    protected function _parse_record_22(string $line): Banks_N43_Entry
    {
        $data = [
            'office' => ltrim(substr($line, 6, 4), '0'),
            'date' => self::_parse_date(substr($line, 10, 6)),
            'date_raw' => substr($line, 10, 6),
            'date_value' => self::_parse_date(substr($line, 16, 6)),
            'concept_common' => substr($line, 22, 2),
            'concept_own' => substr($line, 24, 3),
            'type' => substr($line, 27, 1) == 1 ? self::TYPE_DEBIT : (substr($line, 27, 1) == 2 ? self::TYPE_CREDIT : self::TYPE_UNKNOWN),
            'amount' => floatval(substr($line, 28, 12) . '.' . substr($line, 40, 2)),
            'document' => ltrim(substr($line, 42, 10), '0'),
            'refererence_1' => ltrim(substr($line, 52, 12), '0'),
            'refererence_2' => trim(substr($line, 64, 16)),
            'raw' => $line,
            'concepts' => []
        ];

        $entry = new Banks_N43_Entry();
        foreach ($data as $k => $v) {
            $entry->$k = $v;
        }

        $this->_currentEntry = $entry;
        $this->_currentAccount->entries[] = $entry;

        return $entry;
    }


    /**
     * Entrada 23 - Registros complementarios de concepto (opcionales y hasta un máximo de 5)
     */
    protected function _parse_record_23(string $line): Banks_N43_Entry
    {
        $this->_currentEntry->concepts[substr($line, 2, 2)] = trim(substr($line, 4));

        return $this->_currentEntry;
    }


    /**
     * Entrada 24 - Registro complementario de información de equivalencia del importe (opcional y sin valor contable)
     */
    protected function _parse_record_24(string $line): Banks_N43_Entry
    {
        $this->_currentEntry->currency_eq = Banks_Helper::currency_number2code(substr($line, 4, 3));
        $this->_currentEntry->amount_eq = floatval(substr($line, 7, 12) . '.' . substr($line, 19, 2));

        return $this->_currentEntry;
    }


    /**
     * Entrada 33 - Registro final de cuenta
     */
    protected function _parse_record_33(string $line): Banks_N43_Account
    {
        $this->_currentAccount->balance_end = floatval(substr($line, 59, 12) . '.' . substr($line, 71, 2));


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

        return $this->_currentAccount;
    }

    /**
     * Entrada 88 - Registro de fin de archivo
     *
     * @throws Banks_N43_Exception
     */
    protected function _parse_record_88(string $line): void
    {
        $record_count = substr($line, 20, 6);

        if ($record_count != $this->_recordCount) {
            throw new Banks_N43_Exception("Number of records ({$this->_recordCount}) doesn't match with the defined in the last record ({$record_count}).");
        }
    }

    private static function _parse_date(string $date): int
    {
        return DateTime::createFromFormat('ymd', $date)->setTime(0, 0, 0)->getTimestamp();
    }
}

class Banks_N43_Exception extends Exception
{

}

/**
 * @property int $bank
 * @property int $office
 * @property int $account
 * @property int $number
 * @property string IBAN
 * @property int $date_start
 * @property int $date_end
 * @property string $type
 * @property float $balance_initial
 * @property float $balance_end
 * @property string $currency
 * @property int $mode
 * @property string $owner_name
 * @property Banks_N43_Entry[] $entries
 */
class Banks_N43_Account extends stdClass
{
}


/**
 * @property int $office
 * @property int $date     Fecha de la operación, en formato marca temporal UNIX
 * @property string $date_raw Fecha de la operación, en formato original
 * @property int $date_value
 * @property string $concept_common
 * @property string $concept_own
 * @property string $type     (debit or credit)
 * @property float $amount
 * @property int $document
 * @property string $refererence_1
 * @property string $refererence_2
 * @property string $raw      Registro completo sin procesar
 * @property string[] $concepts
 */
class Banks_N43_Entry extends stdClass
{
}
