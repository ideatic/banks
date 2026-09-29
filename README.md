# Banks

Simple library to handle bank-related files:

- Norma 43 / Cuaderno 43 (`Banks_N43`)
- ISO 20022 CAMT.053 bank statements (`Banks_Camt053`)

## Usage example

### Norma 43

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

### CAMT.053

```
<?php

$file = new Banks_Camt053();
$file->parse($content);

$entries = [];

foreach ($file->statements as $statement) {
    foreach ($statement->entries as $entry) {
        $entries[] = [
            'date'     => $entry->date,
            'amount'   => $entry->amount, // Negative for debits
            'subjects' => $entry->subjects,
        ];
    }
}
```

## Development

```
composer install
composer test
composer phpstan
```
