<?php

use App\Domain\Imports\Datasets\FieldDefinition;
use App\Domain\Imports\MappingSuggester;
use App\Domain\Imports\Readers\SpreadsheetReader;
use App\Domain\Imports\ValueParser;

it('parses numbers leniently but strictly rejects text', function (mixed $input, ?float $expected) {
    expect(ValueParser::decimal($input))->toBe($expected);
})->with([
    ['1,234.50', 1234.5],
    ['Rs 18,500', 18500.0],
    ['PKR 99', 99.0],
    ['(150.00)', -150.0],
    [' 12 ', 12.0],
    [42, 42.0],
    ['two', null],
    ['12abc', null],
    ['', null],
]);

it('parses dates in the chosen format and rejects impossible dates', function () {
    expect(ValueParser::date('31/03/2026', 'd/m/Y')?->toDateString())->toBe('2026-03-31')
        ->and(ValueParser::date('2026-03-31', 'Y-m-d')?->toDateString())->toBe('2026-03-31')
        ->and(ValueParser::datetime('2026-03-31 14:05', 'Y-m-d')?->format('Y-m-d H:i'))->toBe('2026-03-31 14:05')
        ->and(ValueParser::date('31/02/2026', 'd/m/Y'))->toBeNull()
        ->and(ValueParser::date('2026-13-01', 'Y-m-d'))->toBeNull()
        ->and(ValueParser::date(new DateTimeImmutable('2026-01-05'), 'd/m/Y')?->toDateString())->toBe('2026-01-05');
});

it('detects the date format from samples', function (array $samples, string $format) {
    expect(ValueParser::detectDateFormat($samples))->toBe($format);
})->with([
    'iso' => [['2026-11-01', '2026-11-25'], 'Y-m-d'],
    'day first' => [['25/10/2026', '03/11/2026'], 'd/m/Y'],
    'month first' => [['10/25/2026', '11/03/2026'], 'm/d/Y'],
    'dotted' => [['25.10.2026'], 'd.m.Y'],
]);

it('suggests a mapping from headers and aliases, using each column once', function () {
    $fields = [
        new FieldDefinition('reference', 'Transaction reference', aliases: ['order no', 'invoice']),
        new FieldDefinition('quantity', 'Quantity', FieldDefinition::DECIMAL, aliases: ['qty']),
        new FieldDefinition('unit_price', 'Unit price', FieldDefinition::DECIMAL, aliases: ['price']),
        new FieldDefinition('discount', 'Discount', FieldDefinition::DECIMAL),
    ];

    expect((new MappingSuggester)->suggest($fields, ['Invoice', 'QTY', 'Unit Price (PKR)', 'Notes']))->toBe([
        'reference' => 'Invoice',
        'quantity' => 'QTY',
        'unit_price' => 'Unit Price (PKR)',
        'discount' => null,
    ]);
});

it('normalizes blank, duplicate and BOM-prefixed headers', function () {
    expect(SpreadsheetReader::normalizeHeaders(["\u{FEFF}Date", 'Amount', '', 'Amount', null]))
        ->toBe(['Date', 'Amount', 'Column 3', 'Amount (2)', 'Column 5']);
});

it('reads semicolon-delimited CSV and skips blank lines', function () {
    $path = tempnam(sys_get_temp_dir(), 'orbit').'.csv';
    file_put_contents($path, "Date;Amount\n2026-11-01;100\n\n2026-11-02;200\n");

    $reader = new SpreadsheetReader($path, 'csv');

    expect($reader->headers())->toBe(['Date', 'Amount'])
        ->and(iterator_to_array($reader->rows()))->toBe([
            2 => ['Date' => '2026-11-01', 'Amount' => '100'],
            4 => ['Date' => '2026-11-02', 'Amount' => '200'],
        ])
        ->and($reader->countRows())->toBe(2);
});
