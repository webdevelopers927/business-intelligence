<?php

namespace App\Domain\Metrics\Formula;

/**
 * Restricted arithmetic over metric identifiers (docs/ARCHITECTURE.md §7.5).
 *
 *   expr   := term (('+' | '-') term)*
 *   term   := factor (('*' | '/') factor)*
 *   factor := NUMBER | IDENT | '(' expr ')' | '-' factor
 *
 * Nothing user-supplied is ever passed to eval, SQL or JavaScript: input is
 * tokenized, parsed into a small array AST and evaluated by walking it. The
 * evaluator only knows four operators and number/variable lookups.
 *
 * AST nodes: ['num', float] | ['var', string] | ['neg', node] | ['bin', op, left, right]
 */
final class Formula
{
    public const MAX_LENGTH = 300;

    public const MAX_TOKENS = 64;

    public const MAX_DEPTH = 16;

    /** @var list<array{type: string, value: string, pos: int}> */
    private array $tokens = [];

    private int $cursor = 0;

    /** @param array<mixed> $ast */
    private function __construct(public readonly string $source, public readonly array $ast) {}

    public static function parse(string $source): self
    {
        $source = trim($source);
        if ($source === '') {
            throw new FormulaException('Enter a formula, for example net_profit / orders.');
        }
        if (mb_strlen($source) > self::MAX_LENGTH) {
            throw new FormulaException('Formulas are limited to '.self::MAX_LENGTH.' characters.');
        }

        $parser = new self($source, []);
        $parser->tokens = self::tokenize($source);
        if (count($parser->tokens) > self::MAX_TOKENS) {
            throw new FormulaException('The formula is too long; use at most '.self::MAX_TOKENS.' terms and operators.');
        }

        $ast = $parser->expression(0);
        if ($parser->cursor < count($parser->tokens)) {
            $token = $parser->tokens[$parser->cursor];
            throw new FormulaException("Unexpected \"{$token['value']}\".", $token['pos']);
        }

        return new self($source, $ast);
    }

    /** @return list<string> Metric identifiers referenced, in first-use order. */
    public function identifiers(): array
    {
        $found = [];
        $walk = function (array $node) use (&$walk, &$found): void {
            switch ($node[0]) {
                case 'var':
                    $found[$node[1]] = true;
                    break;
                case 'neg':
                    $walk($node[1]);
                    break;
                case 'bin':
                    $walk($node[2]);
                    $walk($node[3]);
                    break;
            }
        };
        $walk($this->ast);

        return array_keys($found);
    }

    /**
     * Evaluate with the given variable values. Null anywhere (missing data)
     * yields null; division by zero yields null — never ±∞ or NaN.
     *
     * @param  array<string, float|int|null>  $values
     */
    public function evaluate(array $values): ?float
    {
        $eval = function (array $node) use (&$eval, $values): ?float {
            switch ($node[0]) {
                case 'num':
                    return $node[1];
                case 'var':
                    $v = $values[$node[1]] ?? null;

                    return $v === null ? null : (float) $v;
                case 'neg':
                    $v = $eval($node[1]);

                    return $v === null ? null : -$v;
                default:
                    $l = $eval($node[2]);
                    $r = $eval($node[3]);
                    if ($l === null || $r === null) {
                        return null;
                    }

                    return match ($node[1]) {
                        '+' => $l + $r,
                        '-' => $l - $r,
                        '*' => $l * $r,
                        '/' => abs($r) < 1e-12 ? null : $l / $r,
                        default => throw new \LogicException("Unsupported operator [{$node[1]}]."),
                    };
            }
        };

        $result = $eval($this->ast);

        return $result === null || ! is_finite($result) ? null : $result;
    }

    /** Canonical spacing: "net_profit / orders * 100". */
    public function normalized(): string
    {
        $print = function (array $node, int $parentPrecedence = 0) use (&$print): string {
            switch ($node[0]) {
                case 'num':
                    return rtrim(rtrim(number_format($node[1], 6, '.', ''), '0'), '.');
                case 'var':
                    return $node[1];
                case 'neg':
                    return '-'.$print($node[1], 3);
                default:
                    $precedence = in_array($node[1], ['+', '-'], true) ? 1 : 2;
                    // Right operand of - and / needs parentheses at equal precedence.
                    $text = $print($node[2], $precedence).' '.$node[1].' '.$print($node[3], $precedence + (in_array($node[1], ['-', '/'], true) ? 1 : 0));

                    return $precedence < $parentPrecedence ? "({$text})" : $text;
            }
        };

        return $print($this->ast);
    }

    // ── Parsing ─────────────────────────────────────────────────────────

    /** @return list<array{type: string, value: string, pos: int}> */
    private static function tokenize(string $source): array
    {
        $tokens = [];
        $length = strlen($source);
        $i = 0;

        while ($i < $length) {
            $c = $source[$i];

            if (ctype_space($c)) {
                $i++;

                continue;
            }

            if (str_contains('+-*/()', $c)) {
                $tokens[] = ['type' => $c, 'value' => $c, 'pos' => $i];
                $i++;

                continue;
            }

            if (ctype_digit($c) || ($c === '.' && $i + 1 < $length && ctype_digit($source[$i + 1]))) {
                preg_match('/\G\d*\.?\d+(?:\.\d+)?/', $source, $m, 0, $i);
                if (substr_count($m[0], '.') > 1) {
                    throw new FormulaException("\"{$m[0]}\" is not a valid number.", $i);
                }
                $tokens[] = ['type' => 'num', 'value' => $m[0], 'pos' => $i];
                $i += strlen($m[0]);

                continue;
            }

            if (ctype_alpha($c) || $c === '_') {
                preg_match('/\G[A-Za-z_][A-Za-z0-9_]*/', $source, $m, 0, $i);
                $tokens[] = ['type' => 'ident', 'value' => strtolower($m[0]), 'pos' => $i];
                $i += strlen($m[0]);

                continue;
            }

            throw new FormulaException("\"{$c}\" is not allowed. Use metric names, numbers, + - * / and parentheses.", $i);
        }

        return $tokens;
    }

    /** @return array<mixed> */
    private function expression(int $depth): array
    {
        $this->guardDepth($depth);
        $node = $this->term($depth);
        while ($this->peek('+') || $this->peek('-')) {
            $op = $this->tokens[$this->cursor++]['value'];
            $node = ['bin', $op, $node, $this->term($depth)];
        }

        return $node;
    }

    /** @return array<mixed> */
    private function term(int $depth): array
    {
        $node = $this->factor($depth);
        while ($this->peek('*') || $this->peek('/')) {
            $op = $this->tokens[$this->cursor++]['value'];
            $node = ['bin', $op, $node, $this->factor($depth)];
        }

        return $node;
    }

    /** @return array<mixed> */
    private function factor(int $depth): array
    {
        $this->guardDepth($depth);
        $token = $this->tokens[$this->cursor] ?? null;

        if ($token === null) {
            throw new FormulaException('The formula ends unexpectedly — a value is missing.', strlen($this->source));
        }

        $this->cursor++;

        return match ($token['type']) {
            'num' => ['num', (float) $token['value']],
            'ident' => ['var', $token['value']],
            '-' => ['neg', $this->factor($depth + 1)],
            '(' => $this->group($depth, $token['pos']),
            default => throw new FormulaException("Unexpected \"{$token['value']}\" — expected a number or metric.", $token['pos']),
        };
    }

    /** @return array<mixed> */
    private function group(int $depth, int $openedAt): array
    {
        $node = $this->expression($depth + 1);
        if (! $this->peek(')')) {
            throw new FormulaException('A "(" is never closed.', $openedAt);
        }
        $this->cursor++;

        return $node;
    }

    private function peek(string $type): bool
    {
        return ($this->tokens[$this->cursor]['type'] ?? null) === $type;
    }

    private function guardDepth(int $depth): void
    {
        if ($depth > self::MAX_DEPTH) {
            throw new FormulaException('The formula is nested too deeply.');
        }
    }
}
