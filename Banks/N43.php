<?php

/**
 * Importación y tratamiento de los extractos bancarios españoles que siguen la norma/cuaderno 43 de la 'Asociación Española de la Banca'.
 * Puede consultarse la especificación del formato en https://docs.bankinter.com/stf/plataformas/empresas/gestion/ficheros/formatos_fichero/norma_43_castellano.pdf.
 */
class Banks_N43
{
    /**
     * Cuentas leídas del fichero
     * @var array
     */
    public $accounts;

    protected $_current_account, $_current_entry, $_record_count;


    /**
     * Representa un adeudo de tu cuenta
     */
    const TYPE_DEBIT = 'debit';

    /**
     * Representa un abono en tu cuenta
     */
    const TYPE_CREDIT = 'credit';

    const TYPE_UNKNOWN = 'unknown';

    public function parse($content)
    {
        $this->_record_count = 0;
        foreach (explode("\n", $content) as $line) {
            if (!$line) {
                continue;
            }

            $code = intval(substr($line, 0, 2));

            $method_name = "_parse_record_{$code}";
            if (method_exists($this, $method_name)) {
                $this->$method_name($line);
            } else {
                throw new Banks_N43_Exception("Invalid record type '$code' in line {$this->_record_count}");
            }

            $this->_record_count++;
        }
    }

    protected function _parse_record_11($line)
    {
        //Entrada 11 - Registro cabecera de cuenta (obligatorio)
        $account = [
            'bank'            => substr($line, 2, 4),
            'office'          => substr($line, 6, 4),
            'account'         => substr($line, 10, 10),
            'date_start'      => self::_parse_date(substr($line, 20, 6)),
            'date_end'        => self::_parse_date(substr($line, 26, 6)),
            'type'            => substr($line, 32, 1) == 1 ? self::TYPE_DEBIT : (substr($line, 32, 1) == 2 ? self::TYPE_CREDIT : self::TYPE_UNKNOWN),
            'balance_initial' => floatval(substr($line, 33, 12) . '.' . substr($line, 45, 2)),
            'currency'        => Banks_Helper::currency_number2code(substr($line, 47, 3)),
            'mode'            => substr($line, 50, 1),// 1, 2 o 3
            'owner_name'      => trim(substr($line, 51, 26)),
            'entries'         => []
        ];

        if ($account['type'] == self::TYPE_DEBIT) {
            $account['balance_initial'] *= -1;
        }

        $this->_current_account = &$account;
        $this->accounts[] = &$account;

        return $account;
    }


    protected function _parse_record_22($line)
    {
        //Entrada 22 - Registro principal de movimiento (obligatorio)
        $entry = [
            'office'         => substr($line, 6, 4),
            'date'           => self::_parse_date(substr($line, 10, 6)),
            'date_value'     => self::_parse_date(substr($line, 16, 6)),
            'concept_common' => substr($line, 22, 2),
            'concept_own'    => substr($line, 24, 3),
            'type'           => substr($line, 27, 1) == 1 ? self::TYPE_DEBIT : (substr($line, 27, 1) == 2 ? self::TYPE_CREDIT : self::TYPE_UNKNOWN),
            'amount'         => floatval(substr($line, 28, 12) . '.' . substr($line, 40, 2)),
            'document'       => substr($line, 42, 10),
            'refererence_1'  => ltrim(substr($line, 52, 12), '0'),
            'refererence_2'  => trim(substr($line, 64, 16)),
            'concepts'       => []
        ];

        $this->_current_entry =& $entry;
        $this->_current_account['entries'][] =& $entry;

        return $entry;
    }


    protected function _parse_record_23($line)
    {
        //Entrada 23 - Registros complementarios de concepto (opcionales y hasta un máximo de 5)
        $this->_current_entry['concepts'][substr($line, 2, 2)] = trim(substr($line, 4));

        return $this->_current_entry;
    }


    protected function _parse_record_24($line)
    {
        //Entrada 24 - Registro complementario de información de equivalencia del importe (opcional y sin valor contable)
        $this->_current_entry['currency_eq'] = Banks_Helper::currency_number2code(substr($line, 4, 3));
        $this->_current_entry['amount_eq'] = floatval(substr($line, 7, 12) . '.' . substr($line, 19, 2));

        return $this->_current_entry;
    }


    protected function _parse_record_33($line)
    {
        //Entrada 33 - Registro final de cuenta
        $this->_current_account['balance_end'] = floatval(substr($line, 59, 12) . '.' . substr($line, 71, 2));


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

        return $this->_current_account;
    }

    protected function _parse_record_88($line)
    {
        //Entrada 88 - Registro de fin de archivo

        if (substr($line, 20, 26) != $this->_record_count) {
            throw new Banks_N43_Exception("Number of records doesn't match with the defined in the last record.");
        }

        return $this->_current_entry;
    }

    private static function _parse_date($date)
    {
        return DateTime::createFromFormat('ymd', $date)->getTimestamp();
    }
}

class Banks_N43_Exception extends Exception
{

}