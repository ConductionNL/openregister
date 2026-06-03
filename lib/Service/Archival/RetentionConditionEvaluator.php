<?php

/**
 * OpenRegister Retention Condition Evaluator
 *
 * Evaluates a minimal condition DSL: <field> <op> <literal>.
 * Used by RetentionEvaluator to apply rule-based retention overrides.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Archival
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/add-archival-annotation-support/tasks.md#task-4.1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Archival;

use InvalidArgumentException;
use Psr\Log\LoggerInterface;

/**
 * Evaluates single-clause retention conditions.
 *
 * Grammar: <field> <op> <literal>
 *   - field   : a dot-free key name present in the row array
 *   - op      : one of  <  <=  ==  !=  >=  >
 *   - literal : integer | float | "string" | 'string' | true | false | null
 *
 * Behaviour on edge cases:
 *   - Field absent from $row → returns false (no rule match; fall through to next rule / default).
 *   - Malformed condition   → throws InvalidArgumentException; callers must catch + log.
 */
class RetentionConditionEvaluator
{

    /**
     * Supported comparison operators.
     *
     * @var string[]
     */
    private const SUPPORTED_OPS = ['<=', '>=', '==', '!=', '<', '>'];

    /**
     * Constructor.
     *
     * @param LoggerInterface $logger Psr logger for debug tracing.
     */
    public function __construct(private readonly LoggerInterface $logger)
    {
    }//end __construct()

    /**
     * Evaluate a condition string against a row of field values.
     *
     * @param string $condition The condition expression, e.g. "statusCode < 400".
     * @param array  $row       Associative array of field name → value.
     *
     * @throws InvalidArgumentException When the condition cannot be parsed.
     *
     * @return bool True when the condition is satisfied, false otherwise.
     *
     * @spec openspec/changes/add-archival-annotation-support/tasks.md#task-4.1
     */
    public function evaluate(string $condition, array $row): bool
    {
        [$field, $op, $literal] = $this->parse(condition: $condition);

        // Unknown field → no match.
        if (array_key_exists($field, $row) === false) {
            $this->logger->debug(
                '[RetentionConditionEvaluator] Field not found in row, condition evaluates to false',
                ['field' => $field, 'condition' => $condition]
            );
            return false;
        }

        $rowValue = $row[$field];

        return $this->compare(left: $rowValue, op: $op, right: $literal);
    }//end evaluate()

    /**
     * Parse a condition string into [field, op, literal].
     *
     * @param string $condition The raw condition string.
     *
     * @throws InvalidArgumentException If the string does not match the grammar.
     *
     * @return array{0: string, 1: string, 2: mixed} [field, operator, parsed-literal].
     */
    private function parse(string $condition): array
    {
        $condition = trim($condition);

        // Try longest operators first (<=, >=, ==, !=) before single-char ones (< >).
        foreach (self::SUPPORTED_OPS as $op) {
            $pos = strpos($condition, $op);
            if ($pos === false || $pos === 0) {
                continue;
            }

            $field  = trim(substr($condition, 0, $pos));
            $rawLit = trim(substr($condition, $pos + strlen($op)));

            if ($field === '' || $rawLit === '') {
                break;
            }

            $literal = $this->parseLiteral(raw: $rawLit, condition: $condition);

            return [$field, $op, $literal];
        }

        $opsStr  = implode(', ', self::SUPPORTED_OPS);
        $details = "Expected: <field> <op> <literal> where op is one of: $opsStr";
        throw new InvalidArgumentException(
            "RetentionConditionEvaluator: cannot parse condition '$condition'. $details"
        );
    }//end parse()

    /**
     * Parse a raw literal string into its PHP value.
     *
     * Accepted forms: integer, float, "string", 'string', true, false, null.
     *
     * @param string $raw       The raw right-hand side token.
     * @param string $condition The full condition (for error messages).
     *
     * @throws InvalidArgumentException On unrecognised literal syntax.
     *
     * @return mixed The parsed PHP value.
     */
    private function parseLiteral(string $raw, string $condition): mixed
    {
        // Boolean / null keywords.
        if ($raw === 'true') {
            return true;
        }

        if ($raw === 'false') {
            return false;
        }

        if ($raw === 'null') {
            return null;
        }

        // Double-quoted string.
        if (str_starts_with($raw, '"') === true && str_ends_with($raw, '"') === true && strlen($raw) >= 2) {
            return substr($raw, 1, -1);
        }

        // Single-quoted string.
        if (str_starts_with($raw, "'") === true && str_ends_with($raw, "'") === true && strlen($raw) >= 2) {
            return substr($raw, 1, -1);
        }

        // Integer.
        if (ctype_digit(ltrim($raw, '-')) === true) {
            return (int) $raw;
        }

        // Float.
        if (is_numeric($raw) === true) {
            return (float) $raw;
        }

        throw new InvalidArgumentException(
            "RetentionConditionEvaluator: unrecognised literal '$raw' in condition '$condition'"
        );
    }//end parseLiteral()

    /**
     * Compare two values with the given operator.
     *
     * @param mixed  $left  Left-hand (row) value.
     * @param string $op    Comparison operator.
     * @param mixed  $right Right-hand (literal) value.
     *
     * @return bool Result of the comparison.
     */
    private function compare(mixed $left, string $op, mixed $right): bool
    {
        return match ($op) {
            '<'  => $left < $right,
            '<=' => $left <= $right,
            '==' => $left == $right,
            '!=' => $left != $right,
            '>=' => $left >= $right,
            '>'  => $left > $right,
            default => false,
        };
    }//end compare()
}//end class
