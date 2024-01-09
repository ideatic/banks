# Banks

Sencilla librería para la gestión de ficheros relacionados con bancos, (Cuaderno 43, SEPA, etc.)

## Ejemplo de uso

```
<?php

$file = new Banks_N43();
$file->parse($content);

$entries = [];

foreach ($file->accounts as $account) {
    foreach ($account->entries as $entry) {
        $entries[] = [
            'date'     => $entry->date,
            'name'     => trim("{$entry->refererence_1} {$entry->refererence_2}"),
            'amount'   => $entry->type == Banks_N43::TYPE_DEBIT ? (-1 * $entry->amount) : $entry->amount,
            'subjects' => array_filter(array_filter($entry->concepts,'trim'))
        ];
    }
}
```