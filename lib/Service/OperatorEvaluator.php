<?php

/**
 * Operator Evaluator
 *
 * Evaluates MongoDB-style comparison operators used in RBAC match conditions.
 * Supports $eq, $ne, $in, $nin, $exists, $gt, $gte, $lt, $lte operators.
 *
 * Extracted from PropertyRbacHandler / ConditionMatcher to keep class
 * complexity manageable.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 *
 * @since 2.0.0 Extracted from PropertyRbacHandler
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use Psr\Log\LoggerInterface;

/**
 * Evaluates MongoDB-style comparison operators for RBAC condition matching
 *
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 */
class OperatorEvaluator
{
    /**
     * Constructor for OperatorEvaluator
     *
     * @param LoggerInterface $logger Logger for debugging
     */
    public function __construct(
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Check if a value matches all operator conditions
     *
     * @param mixed $value     Object value
     * @param array $operators Operator conditions (e.g. ['$gt' => 5, '$lt' => 10])
     *
     * @return bool True if value matches all operators
     */
    public function valueMatchesOperator(mixed $value, array $operators): bool
    {
        foreach ($operators as $operator => $operand) {
            if ($this->applySingleOperator(value: $value, operator: $operator, operand: $operand) === false) {
                return false;
            }
        }//end foreach

        return true;
    }//end valueMatchesOperator()

    /**
     * Apply a single operator check against a value
     *
     * @param mixed  $value    Object value
     * @param string $operator Operator name (e.g. '$eq', '$gt')
     * @param mixed  $operand  Operand to compare against
     *
     * @return bool True if value satisfies the operator condition
     */
    private function applySingleOperator(mixed $value, string $operator, mixed $operand): bool
    {
        switch ($operator) {
            case '$eq':
                return $this->operatorEquals(value: $value, operand: $operand);

            case '$ne':
                return $this->operatorNotEquals(value: $value, operand: $operand);

            case '$in':
                return $this->operatorIn(value: $value, operand: $operand);

            case '$nin':
                return $this->operatorNotIn(value: $value, operand: $operand);

            case '$exists':
                return $this->operatorExists(value: $value, operand: $operand);

            case '$gt':
                return $this->operatorGreaterThan(value: $value, operand: $operand);

            case '$gte':
                return $this->operatorGreaterThanOrEqual(value: $value, operand: $operand);

            case '$lt':
                return $this->operatorLessThan(value: $value, operand: $operand);

            case '$lte':
                return $this->operatorLessThanOrEqual(value: $value, operand: $operand);

            default:
                // Fail-closed on unknown operators to match the SQL path.
                // MagicRbacHandler::buildSingleOperatorCondition returns null for
                // unknown operators; applyRbacFilters then produces no SQL clause
                // that could satisfy the rule, and the row is excluded. Returning
                // true here would grant access on malformed rules (fail-open),
                // creating a list-vs-find security drift.
                $this->logger->warning(
                    message: '[OperatorEvaluator] Unknown operator — rejecting match',
                    context: ['file' => __FILE__, 'line' => __LINE__, 'operator' => $operator]
                );
                return false;
        }//end switch
    }//end applySingleOperator()

    /**
     * Check $eq operator: value must strictly equal operand
     *
     * @param mixed $value   Object value
     * @param mixed $operand Expected value
     *
     * @return bool True if value equals operand
     */
    private function operatorEquals(mixed $value, mixed $operand): bool
    {
        return $value === $operand;
    }//end operatorEquals()

    /**
     * Check $ne operator: value must not equal operand
     *
     * SQL three-valued logic: NULL != X evaluates to NULL, which WHERE treats
     * as false (row filtered out). PHP must match: if value is null, return
     * false unless operand is also null (i.e. $ne: null explicitly asking for
     * "has a value").
     *
     * @param mixed $value   Object value
     * @param mixed $operand Value to exclude
     *
     * @return bool True if value does not equal operand
     */
    private function operatorNotEquals(mixed $value, mixed $operand): bool
    {
        if ($value === null && $operand !== null) {
            return false;
        }

        return $value !== $operand;
    }//end operatorNotEquals()

    /**
     * Check $in operator: value must be in the operand array
     *
     * SQL three-valued logic: NULL IN (...) evaluates to NULL, which WHERE treats
     * as false (row filtered out). PHP's in_array happens to return false when
     * looking up null in most arrays, but returns true for an array containing
     * null — which would diverge from SQL. Explicitly reject null values to
     * keep list and find verdicts aligned.
     *
     * @param mixed $value   Object value
     * @param mixed $operand Array of allowed values
     *
     * @return bool True if value is in operand array
     */
    private function operatorIn(mixed $value, mixed $operand): bool
    {
        if (is_array($operand) === false) {
            return false;
        }

        if ($value === null) {
            return false;
        }

        return in_array($value, $operand, true);
    }//end operatorIn()

    /**
     * Check $nin operator: value must not be in the operand array
     *
     * SQL three-valued logic: NULL NOT IN (...) evaluates to NULL, filtered out
     * by WHERE. Conservative semantics: if we cannot tell whether the value is
     * in the list (because it is null), deny the match.
     *
     * @param mixed $value   Object value
     * @param mixed $operand Array of excluded values
     *
     * @return bool True if value is not in operand array
     */
    private function operatorNotIn(mixed $value, mixed $operand): bool
    {
        if (is_array($operand) === false) {
            return true;
        }

        if ($value === null) {
            return false;
        }

        return in_array($value, $operand, true) === false;
    }//end operatorNotIn()

    /**
     * Check $exists operator: value must exist (or not) based on operand
     *
     * @param mixed $value   Object value
     * @param mixed $operand True to require existence, false to require absence
     *
     * @return bool True if existence matches expectation
     */
    private function operatorExists(mixed $value, mixed $operand): bool
    {
        if ($operand === true && $value === null) {
            return false;
        }

        if ($operand === false && $value !== null) {
            return false;
        }

        return true;
    }//end operatorExists()

    /**
     * Check $gt operator: value must be greater than operand
     *
     * Matches SQL three-valued logic: NULL <op> X evaluates to NULL, which is
     * filtered out by WHERE. PHP's loose comparison would coerce null to 0/""
     * and yield a misleading true/false — we suppress that.
     *
     * @param mixed $value   Object value
     * @param mixed $operand Threshold value
     *
     * @return bool True if value is strictly greater than operand
     */
    private function operatorGreaterThan(mixed $value, mixed $operand): bool
    {
        if ($value === null || $operand === null) {
            return false;
        }

        return $value > $operand;
    }//end operatorGreaterThan()

    /**
     * Check $gte operator: value must be greater than or equal to operand
     *
     * SQL three-valued logic applies — see operatorGreaterThan().
     *
     * @param mixed $value   Object value
     * @param mixed $operand Threshold value
     *
     * @return bool True if value is greater than or equal to operand
     */
    private function operatorGreaterThanOrEqual(mixed $value, mixed $operand): bool
    {
        if ($value === null || $operand === null) {
            return false;
        }

        return $value >= $operand;
    }//end operatorGreaterThanOrEqual()

    /**
     * Check $lt operator: value must be less than operand
     *
     * SQL three-valued logic applies — see operatorGreaterThan().
     *
     * @param mixed $value   Object value
     * @param mixed $operand Threshold value
     *
     * @return bool True if value is strictly less than operand
     */
    private function operatorLessThan(mixed $value, mixed $operand): bool
    {
        if ($value === null || $operand === null) {
            return false;
        }

        return $value < $operand;
    }//end operatorLessThan()

    /**
     * Check $lte operator: value must be less than or equal to operand
     *
     * SQL three-valued logic applies — see operatorGreaterThan(). This is the
     * operator that surfaced the reported `publishedAt: null` bug: PHP's loose
     * comparison treated `null <= '<datetime>'` as true (because null coerces
     * to an empty string which is lexicographically less than any non-empty
     * string), while SQL correctly filtered the row out.
     *
     * @param mixed $value   Object value
     * @param mixed $operand Threshold value
     *
     * @return bool True if value is less than or equal to operand
     */
    private function operatorLessThanOrEqual(mixed $value, mixed $operand): bool
    {
        if ($value === null || $operand === null) {
            return false;
        }

        return $value <= $operand;
    }//end operatorLessThanOrEqual()
}//end class
