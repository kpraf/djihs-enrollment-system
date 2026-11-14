<?php

// Writes a fake ENROLLMENT_ENCODING workbook for the browser tests.
// Usage: php tests/e2e/fixtures/make-workbook.php <output.xlsx>

require __DIR__.'/../../../vendor/autoload.php';

use Tests\Support\EncodingWorkbook;

$out = $argv[1] ?? exit("Usage: php make-workbook.php <output.xlsx>\n");

$book = EncodingWorkbook::make();
$strands = ['HUMANITIES AND SOCIAL SCIENCES (HUMSS)', 'ACCOUNTANCY, BUSINESS AND MANAGEMENT (ABM)', 'IA-EIM NCII', 'ICT-CSS NCII', 'HE-COOKERY/BPP/FBS NCII'];

for ($i = 0; $i < 25; $i++) {
    $book->learner([
        'lrn' => 990000000000 + $i,
        'last' => 'IMPORTED',
        'first' => 'LEARNER '.chr(65 + $i),
        'birthdate' => sprintf('2008-%02d-%02d', ($i % 12) + 1, ($i % 27) + 1),
        'sex' => $i % 2 ? 'FEMALE' : 'MALE',
        'grade' => $i % 3 ? 'GRADE 11' : 'GRADE 12',
        'strand' => $strands[$i % 5],
        'track' => $i % 5 < 2 ? 'ACADEMIC' : 'TVL',
    ]);
}

// One row the importer must flag: an 11-digit LRN.
$book->learner(['lrn' => 99000000099, 'last' => 'IMPORTED', 'first' => 'SHORT LRN']);

$book->save($out);
echo "Wrote {$out}\n";
