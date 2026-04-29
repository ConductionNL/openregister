<?php

/**
 * OpenRegister CalculationEvaluator
 *
 * Pure-function evaluator over a JSON-shaped expression AST. No I/O, no
 * DB access, no HTTP. Inputs: object payload + expression. Output:
 * typed value or EvaluationException.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Calculation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Calculation;

use DateTimeImmutable;
use OCA\OpenRegister\Service\Search\PlaceholderResolver;
use RuntimeException;

/**
 * Expression AST evaluator.
 *
 * Expression shape (JSON):
 * - Scalar literal: a bare string / int / float / bool / null
 * - Property ref:   { "prop": "fieldName" }
 * - Function call:  { "<op>": [<arg>, <arg>, ...] }
 *
 * v1 vocabulary (single-token op keys):
 * - prop, lit, concat, if, not, and, or
 * - +, -, *, /, %
 * - eq, ne, lt, lte, gt, gte
 * - now (no args), diffDays(later, earlier), formatDate(date, fmt)
 *
 * Placeholders inside literal strings (e.g. "$now", "$currentUser") are
 * resolved via the shared PlaceholderResolver.
 */
final class CalculationEvaluator
{

    public function __construct(
        private readonly PlaceholderResolver $placeholders
    ) {}//end __construct()

    /**
     * Evaluate an expression against an object payload.
     *
     * @param array<string, mixed> $object     The object's stored data.
     * @param mixed                $expression Expression AST (scalar literal or array).
     *
     * @return mixed The computed value.
     *
     * @throws EvaluationException When the expression is malformed or references unknown properties/operators.
     */
    public function evaluate(array $object, mixed $expression): mixed
    {
        if (is_array($expression) === false) {
            // Bare scalar — resolve placeholder strings, otherwise pass through.
            return $this->placeholders->resolve($expression);
        }

        if (count($expression) !== 1) {
            throw new EvaluationException('Expression must be a single-key object.');
        }

        $op   = (string) array_key_first($expression);
        $args = $expression[$op];

        return match ($op) {
            'prop'       => $this->propValue($object, $args),
            'lit'        => $this->placeholders->resolve($args),
            'concat'     => $this->concat($object, $args),
            'if'         => $this->ifExpr($object, $args),
            'not'        => !$this->boolEval($object, $args[0] ?? null),
            'and'        => $this->reduceBool($object, $args, true),
            'or'         => $this->reduceBool($object, $args, false),
            '+'          => $this->arith($object, $args, fn($a, $b) => $a + $b, 0),
            '-'          => $this->subOrNeg($object, $args),
            '*'          => $this->arith($object, $args, fn($a, $b) => $a * $b, 1),
            '/'          => $this->divide($object, $args),
            '%'          => $this->modulo($object, $args),
            'eq', 'ne', 'lt', 'lte', 'gt', 'gte' => $this->compare($object, $args, $op),
            'now'        => $this->now(),
            'diffDays'   => $this->diffDays($object, $args),
            'formatDate' => $this->formatDate($object, $args),
            default      => throw new EvaluationException(sprintf('Unknown operator "%s".', $op)),
        };
    }//end evaluate()

    /**
     * @param array<string, mixed> $object
     */
    private function propValue(array $object, mixed $args): mixed
    {
        $name = is_string($args) === true ? $args : (is_array($args) === true ? (string) ($args[0] ?? '') : '');
        if ($name === '') {
            throw new EvaluationException('prop requires a non-empty field name.');
        }
        return ($object[$name] ?? null);
    }//end propValue()

    /**
     * @param array<string, mixed> $object
     * @param array<int, mixed>    $args
     */
    private function concat(array $object, mixed $args): string
    {
        if (is_array($args) === false) {
            return (string) $this->evaluate($object, $args);
        }
        $parts = [];
        foreach ($args as $a) {
            $parts[] = (string) ($this->evaluate($object, $a) ?? '');
        }
        return implode('', $parts);
    }//end concat()

    /**
     * @param array<string, mixed> $object
     */
    private function ifExpr(array $object, mixed $args): mixed
    {
        if (is_array($args) === false || count($args) < 2) {
            throw new EvaluationException('if requires (cond, then[, else]).');
        }
        $cond = $this->boolEval($object, $args[0]);
        if ($cond === true) {
            return $this->evaluate($object, $args[1]);
        }
        return count($args) >= 3 ? $this->evaluate($object, $args[2]) : null;
    }//end ifExpr()

    /**
     * @param array<string, mixed> $object
     */
    private function boolEval(array $object, mixed $expr): bool
    {
        $v = $this->evaluate($object, $expr);
        return $v !== null && $v !== false && $v !== 0 && $v !== '0' && $v !== '';
    }//end boolEval()

    /**
     * @param array<string, mixed> $object
     * @param array<int, mixed>    $args
     */
    private function reduceBool(array $object, mixed $args, bool $shortCircuit): bool
    {
        if (is_array($args) === false) {
            return $shortCircuit;
        }
        foreach ($args as $a) {
            $v = $this->boolEval($object, $a);
            if ($shortCircuit === true && $v === false) { return false; }
            if ($shortCircuit === false && $v === true)  { return true;  }
        }
        return $shortCircuit;
    }//end reduceBool()

    /**
     * @param array<string, mixed> $object
     */
    private function arith(array $object, mixed $args, callable $reducer, int|float $initial): int|float
    {
        if (is_array($args) === false) {
            throw new EvaluationException('Arithmetic requires an array of operands.');
        }
        $acc = $initial;
        foreach ($args as $a) {
            $v = $this->evaluate($object, $a);
            if (is_numeric($v) === false) {
                throw new EvaluationException('Arithmetic operand is not numeric.');
            }
            $acc = $reducer($acc, $v + 0);
        }
        return $acc;
    }//end arith()

    /**
     * @param array<string, mixed> $object
     */
    private function subOrNeg(array $object, mixed $args): int|float
    {
        if (is_array($args) === false || count($args) === 0) {
            throw new EvaluationException('- requires at least one operand.');
        }
        $first = $this->evaluate($object, $args[0]);
        if (is_numeric($first) === false) {
            throw new EvaluationException('- first operand not numeric.');
        }
        if (count($args) === 1) {
            return -($first + 0);
        }
        $acc = $first + 0;
        for ($i = 1; $i < count($args); $i++) {
            $v = $this->evaluate($object, $args[$i]);
            if (is_numeric($v) === false) {
                throw new EvaluationException('- operand not numeric.');
            }
            $acc -= $v + 0;
        }
        return $acc;
    }//end subOrNeg()

    /**
     * @param array<string, mixed> $object
     */
    private function divide(array $object, mixed $args): float
    {
        if (is_array($args) === false || count($args) < 2) {
            throw new EvaluationException('/ requires two operands.');
        }
        $a = $this->evaluate($object, $args[0]);
        $b = $this->evaluate($object, $args[1]);
        if (is_numeric($a) === false || is_numeric($b) === false || (float) $b === 0.0) {
            throw new EvaluationException('/ requires non-zero numeric operands.');
        }
        return ((float) $a) / ((float) $b);
    }//end divide()

    /**
     * @param array<string, mixed> $object
     */
    private function modulo(array $object, mixed $args): int|float
    {
        if (is_array($args) === false || count($args) < 2) {
            throw new EvaluationException('% requires two operands.');
        }
        $a = $this->evaluate($object, $args[0]);
        $b = $this->evaluate($object, $args[1]);
        if (is_numeric($a) === false || is_numeric($b) === false || (float) $b === 0.0) {
            throw new EvaluationException('% requires non-zero numeric operands.');
        }
        return fmod((float) $a, (float) $b);
    }//end modulo()

    /**
     * @param array<string, mixed> $object
     */
    private function compare(array $object, mixed $args, string $op): bool
    {
        if (is_array($args) === false || count($args) < 2) {
            throw new EvaluationException(sprintf('%s requires two operands.', $op));
        }
        $a = $this->normaliseForCompare($this->evaluate($object, $args[0]));
        $b = $this->normaliseForCompare($this->evaluate($object, $args[1]));
        return match ($op) {
            'eq'  => $a == $b,
            'ne'  => $a != $b,
            'lt'  => $a !== null && $b !== null && $a < $b,
            'lte' => $a !== null && $b !== null && $a <= $b,
            'gt'  => $a !== null && $b !== null && $a > $b,
            'gte' => $a !== null && $b !== null && $a >= $b,
            default => false,
        };
    }//end compare()

    /**
     * Coerce ISO-8601 date strings + DateTimeInterface values to integer
     * timestamps so ordering comparisons behave consistently. Other
     * scalars pass through unchanged.
     */
    private function normaliseForCompare(mixed $v): mixed
    {
        if ($v instanceof \DateTimeInterface) {
            return $v->getTimestamp();
        }
        if (is_string($v) === true && preg_match('/^\d{4}-\d{2}-\d{2}/', $v) === 1) {
            try {
                return (new \DateTimeImmutable($v))->getTimestamp();
            } catch (\Throwable) {
                return $v;
            }
        }
        return $v;
    }//end normaliseForCompare()

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now');
    }//end now()

    /**
     * @param array<string, mixed> $object
     */
    private function diffDays(array $object, mixed $args): ?int
    {
        if (is_array($args) === false || count($args) < 2) {
            throw new EvaluationException('diffDays requires (later, earlier).');
        }
        $later   = $this->toDateOrNull($this->evaluate($object, $args[0]));
        $earlier = $this->toDateOrNull($this->evaluate($object, $args[1]));
        if ($later === null || $earlier === null) {
            return null;
        }
        $diff = $later->getTimestamp() - $earlier->getTimestamp();
        return (int) floor($diff / 86400);
    }//end diffDays()

    /**
     * @param array<string, mixed> $object
     */
    private function formatDate(array $object, mixed $args): ?string
    {
        if (is_array($args) === false || count($args) < 2) {
            throw new EvaluationException('formatDate requires (date, fmt).');
        }
        $date = $this->toDateOrNull($this->evaluate($object, $args[0]));
        $fmt  = (string) $this->evaluate($object, $args[1]);
        return $date === null ? null : $date->format($fmt);
    }//end formatDate()

    private function toDateOrNull(mixed $v): ?DateTimeImmutable
    {
        if ($v instanceof DateTimeImmutable) { return $v; }
        if ($v instanceof \DateTimeInterface) { return DateTimeImmutable::createFromInterface($v); }
        if (is_string($v) === true && $v !== '') {
            try { return new DateTimeImmutable($v); } catch (\Throwable) { return null; }
        }
        return null;
    }//end toDateOrNull()

}//end class
