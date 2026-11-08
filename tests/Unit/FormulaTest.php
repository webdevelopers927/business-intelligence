<?php

use App\Domain\Metrics\Formula\Formula;
use App\Domain\Metrics\Formula\FormulaException;

it('evaluates arithmetic with correct precedence', function (string $formula, array $vars, ?float $expected) {
    expect(Formula::parse($formula)->evaluate($vars))->toEqualWithDelta($expected, 1e-9);
})->with([
    'precedence' => ['2 + 3 * 4', [], 14.0],
    'parentheses' => ['(2 + 3) * 4', [], 20.0],
    'left associative minus' => ['10 - 4 - 3', [], 3.0],
    'left associative divide' => ['100 / 10 / 2', [], 5.0],
    'unary minus' => ['-revenue + 10', ['revenue' => 4], 6.0],
    'double negation' => ['--5', [], 5.0],
    'decimals' => ['.5 * 4 + 1.25', [], 3.25],
    'metric ratio' => ['net_profit / revenue * 100', ['net_profit' => 25, 'revenue' => 200], 12.5],
    'case-insensitive identifiers' => ['Revenue / Orders', ['revenue' => 300, 'orders' => 3], 100.0],
]);

it('returns null for division by zero and missing data', function () {
    expect(Formula::parse('revenue / orders')->evaluate(['revenue' => 10, 'orders' => 0]))->toBeNull()
        ->and(Formula::parse('revenue / (orders - orders)')->evaluate(['revenue' => 10, 'orders' => 5]))->toBeNull()
        ->and(Formula::parse('revenue * 2')->evaluate(['revenue' => null]))->toBeNull()
        ->and(Formula::parse('revenue * 2')->evaluate([]))->toBeNull();
});

it('lists referenced identifiers once, in order', function () {
    expect(Formula::parse('(net_profit + cogs) / revenue - cogs / revenue')->identifiers())->toBe(['net_profit', 'cogs', 'revenue']);
});

it('prints a canonical form that parses back identically', function (string $input, string $canonical) {
    $formula = Formula::parse($input);

    expect($formula->normalized())->toBe($canonical)
        ->and(Formula::parse($canonical)->ast)->toBe($formula->ast);
})->with([
    ['net_profit/revenue*100', 'net_profit / revenue * 100'],
    ['(a+b)*c', '(a + b) * c'],
    ['a-(b-c)', 'a - (b - c)'],
    ['a/(b*c)', 'a / (b * c)'],
    ['-(a+b)', '-(a + b)'],
    ['0.50*a', '0.5 * a'],
]);

it('rejects anything that is not restricted arithmetic', function (string $input, string $message) {
    expect(fn () => Formula::parse($input))->toThrow(FormulaException::class, $message);
})->with([
    'empty' => ['  ', 'Enter a formula'],
    'function call' => ['abs(revenue)', 'Unexpected "("'],
    'php' => ['system("ls")', '""" is not allowed'],
    'php without quotes' => ['system(ls)', 'Unexpected "("'],
    'sql' => ['revenue; DROP TABLE users', '";" is not allowed'],
    'quote' => ["revenue + 'x'", '"\'" is not allowed'],
    'exponent' => ['revenue ^ 2', '"^" is not allowed'],
    'dangling operator' => ['revenue +', 'ends unexpectedly'],
    'unclosed paren' => ['(revenue + cogs', 'never closed'],
    'stray close' => ['revenue)', 'Unexpected ")"'],
    'two values' => ['revenue cogs', 'Unexpected "cogs"'],
    'bad number' => ['1.2.3', 'not a valid number'],
    'too long' => [str_repeat('revenue + ', 40).'1', 'limited to'],
    'too many tokens' => [implode('+', array_fill(0, 40, '1')), 'at most 64'],
    'too deep' => [str_repeat('(', 20).'1'.str_repeat(')', 20), 'nested too deeply'],
]);

it('reports where the problem is', function () {
    try {
        Formula::parse('revenue + $cogs');
    } catch (FormulaException $e) {
        expect($e->position)->toBe(10);

        return;
    }

    $this->fail('Expected a FormulaException.');
});
