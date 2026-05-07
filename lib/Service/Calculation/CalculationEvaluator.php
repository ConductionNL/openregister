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
use DateTimeInterface;
use OCA\OpenRegister\Service\Search\PlaceholderResolver;
use RuntimeException;
use Throwable;

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
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 */
class CalculationEvaluator
{
    /**
     * Constructor.
     *
     * @param PlaceholderResolver $placeholders Shared placeholder resolver for literal-string interpolation.
     *
     * @return void
     */
    public function __construct(
        private readonly PlaceholderResolver $placeholders
    ) {
    }//end __construct()

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
            'prop'       => $this->propValue(object: $object, args: $args),
            'lit'        => $this->placeholders->resolve($args),
            'concat'     => $this->concat(object: $object, args: $args),
            'if'         => $this->ifExpr(object: $object, args: $args),
            'not'        => !$this->boolEval(object: $object, expr: $args[0] ?? null),
            'and'        => $this->reduceBool(object: $object, args: $args, shortCircuit: true),
            'or'         => $this->reduceBool(object: $object, args: $args, shortCircuit: false),
            '+'          => $this->arith(object: $object, args: $args, reducer: fn($a, $b) => $a + $b, initial: 0),
            '-'          => $this->subOrNeg(object: $object, args: $args),
            '*'          => $this->arith(object: $object, args: $args, reducer: fn($a, $b) => $a * $b, initial: 1),
            '/'          => $this->divide(object: $object, args: $args),
            '%'          => $this->modulo(object: $object, args: $args),
            'eq', 'ne', 'lt', 'lte', 'gt', 'gte' => $this->compare(object: $object, args: $args, op: $op),
            'now'        => $this->now(),
            'diffDays'   => $this->diffDays(object: $object, args: $args),
            'formatDate' => $this->formatDate(object: $object, args: $args),
            default      => throw new EvaluationException(sprintf('Unknown operator "%s".', $op)),
        };
    }//end evaluate()

    /**
     * Resolve a property reference against the object payload.
     *
     * Supports dotted paths for nested values and `@self` system metadata.
     *
     * @param array<string, mixed> $object The object's stored data.
     * @param mixed                $args   Property name (string) or single-element array containing it.
     *
     * @return mixed The resolved value, or null when the path is missing.
     *
     * @throws EvaluationException When the property name is empty.
     */
    private function propValue(array $object, mixed $args): mixed
    {
        $name = is_string($args) === true ? $args : (is_array($args) === true ? (string) ($args[0] ?? '') : '');
        if ($name === '') {
            throw new EvaluationException('prop requires a non-empty field name.');
        }

        // Support dotted paths: `@self.created`, `parent.subfield`, etc.
        // The CalculationOnSaveListener injects `@self` system metadata so
        // calculations can reference `@self.created`, `@self.updated`, etc.
        if (strpos($name, '.') === false) {
            return ($object[$name] ?? null);
        }

        $parts   = explode('.', $name);
        $current = $object;
        foreach ($parts as $part) {
            if (is_array($current) === false || array_key_exists($part, $current) === false) {
                return null;
            }

            $current = $current[$part];
        }

        return $current;
    }//end propValue()

    /**
     * Concatenate the string forms of evaluated arguments.
     *
     * @param array<string, mixed> $object The object's stored data.
     * @param mixed                $args   Array of sub-expressions (or a single sub-expression).
     *
     * @return string The concatenated string.
     */
    private function concat(array $object, mixed $args): string
    {
        if (is_array($args) === false) {
            return (string) $this->evaluate(object: $object, expression: $args);
        }

        $parts = [];
        foreach ($args as $a) {
            $parts[] = (string) ($this->evaluate(object: $object, expression: $a) ?? '');
        }

        return implode('', $parts);
    }//end concat()

    /**
     * Conditional branching: (cond, then[, else]).
     *
     * @param array<string, mixed> $object The object's stored data.
     * @param mixed                $args   Argument array: [cond, then, else?].
     *
     * @return mixed The selected branch's evaluation, or null when no else branch.
     *
     * @throws EvaluationException When fewer than two arguments are supplied.
     */
    private function ifExpr(array $object, mixed $args): mixed
    {
        if (is_array($args) === false || count($args) < 2) {
            throw new EvaluationException('if requires (cond, then[, else]).');
        }

        $cond = $this->boolEval(object: $object, expr: $args[0]);
        if ($cond === true) {
            return $this->evaluate(object: $object, expression: $args[1]);
        }

        return count($args) >= 3 ? $this->evaluate(object: $object, expression: $args[2]) : null;
    }//end ifExpr()

    /**
     * Evaluate an expression and coerce the result to bool using truthy semantics.
     *
     * @param array<string, mixed> $object The object's stored data.
     * @param mixed                $expr   Sub-expression to evaluate.
     *
     * @return bool True when the value is non-empty and not zero/false/null.
     */
    private function boolEval(array $object, mixed $expr): bool
    {
        $v = $this->evaluate(object: $object, expression: $expr);
        return $v !== null && $v !== false && $v !== 0 && $v !== '0' && $v !== '';
    }//end boolEval()

    /**
     * Reduce a list of boolean expressions with AND/OR semantics.
     *
     * @param array<string, mixed> $object       The object's stored data.
     * @param mixed                $args         Array of sub-expressions to fold.
     * @param bool                 $shortCircuit True for AND (return false on first false), false for OR.
     *
     * @return bool The reduced result.
     */
    private function reduceBool(array $object, mixed $args, bool $shortCircuit): bool
    {
        if (is_array($args) === false) {
            return $shortCircuit;
        }

        foreach ($args as $a) {
            $v = $this->boolEval(object: $object, expr: $a);
            if ($shortCircuit === true && $v === false) {
                return false;
            }

            if ($shortCircuit === false && $v === true) {
                return true;
            }
        }

        return $shortCircuit;
    }//end reduceBool()

    /**
     * Reduce an array of numeric operands with a binary callback.
     *
     * @param array<string, mixed> $object  The object's stored data.
     * @param mixed                $args    Array of operand sub-expressions.
     * @param callable             $reducer Binary callback applied to (acc, operand).
     * @param int|float            $initial Initial accumulator value.
     *
     * @return int|float The reduced numeric value.
     *
     * @throws EvaluationException When args is not an array or an operand is non-numeric.
     */
    private function arith(array $object, mixed $args, callable $reducer, int|float $initial): int|float
    {
        if (is_array($args) === false) {
            throw new EvaluationException('Arithmetic requires an array of operands.');
        }

        $acc = $initial;
        foreach ($args as $a) {
            $v = $this->evaluate(object: $object, expression: $a);
            if (is_numeric($v) === false) {
                throw new EvaluationException('Arithmetic operand is not numeric.');
            }

            $acc = $reducer($acc, $v + 0);
        }

        return $acc;
    }//end arith()

    /**
     * Subtract operands or, with one operand, negate it.
     *
     * @param array<string, mixed> $object The object's stored data.
     * @param mixed                $args   Operand list (1+ entries).
     *
     * @return int|float The subtracted/negated result.
     *
     * @throws EvaluationException When args is empty or any operand is non-numeric.
     */
    private function subOrNeg(array $object, mixed $args): int|float
    {
        if (is_array($args) === false || count($args) === 0) {
            throw new EvaluationException('- requires at least one operand.');
        }

        $first = $this->evaluate(object: $object, expression: $args[0]);
        if (is_numeric($first) === false) {
            throw new EvaluationException('- first operand not numeric.');
        }

        if (count($args) === 1) {
            return -($first + 0);
        }

        $acc      = $first + 0;
        $argCount = count($args);
        for ($i = 1; $i < $argCount; $i++) {
            $v = $this->evaluate(object: $object, expression: $args[$i]);
            if (is_numeric($v) === false) {
                throw new EvaluationException('- operand not numeric.');
            }

            $acc -= $v + 0;
        }

        return $acc;
    }//end subOrNeg()

    /**
     * Divide the first operand by the second.
     *
     * @param array<string, mixed> $object The object's stored data.
     * @param mixed                $args   Two-operand list.
     *
     * @return float The quotient.
     *
     * @throws EvaluationException When fewer than two operands or the divisor is zero/non-numeric.
     */
    private function divide(array $object, mixed $args): float
    {
        if (is_array($args) === false || count($args) < 2) {
            throw new EvaluationException('/ requires two operands.');
        }

        $a = $this->evaluate(object: $object, expression: $args[0]);
        $b = $this->evaluate(object: $object, expression: $args[1]);
        if (is_numeric($a) === false || is_numeric($b) === false || (float) $b === 0.0) {
            throw new EvaluationException('/ requires non-zero numeric operands.');
        }

        return ((float) $a) / ((float) $b);
    }//end divide()

    /**
     * Modulo of the first operand by the second.
     *
     * @param array<string, mixed> $object The object's stored data.
     * @param mixed                $args   Two-operand list.
     *
     * @return int|float The remainder.
     *
     * @throws EvaluationException When fewer than two operands or the divisor is zero/non-numeric.
     */
    private function modulo(array $object, mixed $args): int|float
    {
        if (is_array($args) === false || count($args) < 2) {
            throw new EvaluationException('% requires two operands.');
        }

        $a = $this->evaluate(object: $object, expression: $args[0]);
        $b = $this->evaluate(object: $object, expression: $args[1]);
        if (is_numeric($a) === false || is_numeric($b) === false || (float) $b === 0.0) {
            throw new EvaluationException('% requires non-zero numeric operands.');
        }

        return fmod((float) $a, (float) $b);
    }//end modulo()

    /**
     * Compare two operands using the given operator.
     *
     * @param array<string, mixed> $object The object's stored data.
     * @param mixed                $args   Two-operand list.
     * @param string               $op     One of 'eq','ne','lt','lte','gt','gte'.
     *
     * @return bool The comparison result.
     *
     * @throws EvaluationException When fewer than two operands.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    private function compare(array $object, mixed $args, string $op): bool
    {
        if (is_array($args) === false || count($args) < 2) {
            throw new EvaluationException(sprintf('%s requires two operands.', $op));
        }

        $a = $this->normaliseForCompare(v: $this->evaluate(object: $object, expression: $args[0]));
        $b = $this->normaliseForCompare(v: $this->evaluate(object: $object, expression: $args[1]));
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
     * Coerce ISO-8601 date strings + DateTimeInterface values to integer timestamps.
     *
     * Coerce ISO-8601 date strings + DateTimeInterface values to integer
     * timestamps so ordering comparisons behave consistently. Other
     * scalars pass through unchanged.
     *
     * @param mixed $v The value to normalise.
     *
     * @return mixed The normalised value (int timestamp for dates, original otherwise).
     */
    private function normaliseForCompare(mixed $v): mixed
    {
        if ($v instanceof DateTimeInterface) {
            return $v->getTimestamp();
        }

        if (is_string($v) === true && preg_match('/^\d{4}-\d{2}-\d{2}/', $v) === 1) {
            try {
                return (new DateTimeImmutable($v))->getTimestamp();
            } catch (Throwable) {
                return $v;
            }
        }

        return $v;
    }//end normaliseForCompare()

    /**
     * Return the current timestamp as an immutable DateTime.
     *
     * @return DateTimeImmutable The current moment.
     */
    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now');
    }//end now()

    /**
     * Return the integer day difference between two date operands.
     *
     * @param array<string, mixed> $object The object's stored data.
     * @param mixed                $args   Two-operand list: (later, earlier).
     *
     * @return int|null The day difference, or null when either operand isn't a parseable date.
     *
     * @throws EvaluationException When fewer than two operands.
     */
    private function diffDays(array $object, mixed $args): ?int
    {
        if (is_array($args) === false || count($args) < 2) {
            throw new EvaluationException('diffDays requires (later, earlier).');
        }

        $later   = $this->toDateOrNull(v: $this->evaluate(object: $object, expression: $args[0]));
        $earlier = $this->toDateOrNull(v: $this->evaluate(object: $object, expression: $args[1]));
        if ($later === null || $earlier === null) {
            return null;
        }

        $diff = $later->getTimestamp() - $earlier->getTimestamp();
        return (int) floor($diff / 86400);
    }//end diffDays()

    /**
     * Format a date operand using a PHP date format string.
     *
     * @param array<string, mixed> $object The object's stored data.
     * @param mixed                $args   Two-operand list: (date, fmt).
     *
     * @return string|null The formatted string, or null when the date isn't parseable.
     *
     * @throws EvaluationException When fewer than two operands.
     */
    private function formatDate(array $object, mixed $args): ?string
    {
        if (is_array($args) === false || count($args) < 2) {
            throw new EvaluationException('formatDate requires (date, fmt).');
        }

        $date = $this->toDateOrNull(v: $this->evaluate(object: $object, expression: $args[0]));
        $fmt  = (string) $this->evaluate(object: $object, expression: $args[1]);
        return $date === null ? null : $date->format($fmt);
    }//end formatDate()

    /**
     * Coerce a value to DateTimeImmutable when possible.
     *
     * @param mixed $v The value to coerce.
     *
     * @return DateTimeImmutable|null The parsed date, or null when not parseable.
     *
     * @SuppressWarnings(PHPMD.StaticAccess)
     */
    private function toDateOrNull(mixed $v): ?DateTimeImmutable
    {
        if ($v instanceof DateTimeImmutable) {
            return $v;
        }

        if ($v instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($v);
        }

        if (is_string($v) === true && $v !== '') {
            try {
                return new DateTimeImmutable($v);
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }//end toDateOrNull()
}//end class
