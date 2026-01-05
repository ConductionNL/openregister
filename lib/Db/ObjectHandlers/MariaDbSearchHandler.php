<?php

/**
 * MariaDB Search Handler for OpenRegister Objects
 *
 * This file contains the class for handling MariaDB-specific search operations
 * for object entities in the OpenRegister application.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db\ObjectHandlers
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Db\ObjectHandlers;

use DateTime;
use Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * MariaDB Search Handler
 *
 * Handles database-specific JSON search operations for MariaDB/MySQL databases.
 * This class encapsulates all MariaDB-specific logic for searching within JSON fields.
 *
 * @package OCA\OpenRegister\Db\ObjectHandlers
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)     JSON search requires many query building methods
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Complex SQL/JSON query building logic
 * @SuppressWarnings(PHPMD.ElseExpression)
 */
class MariaDbSearchHandler
{
    /**
     * Main metadata fields that can be filtered on
     *
     * @var string[]
     */
    private const MAIN_FIELDS = [
        'register',
        'schema',
        'uuid',
        'name',
        'description',
        'uri',
        'version',
        'folder',
        'application',
        'organisation',
        'owner',
        'size',
        'schemaVersion',
        'created',
        'updated',
        'published',
        'depublished',
    ];

    /**
     * Date/time fields
     *
     * @var string[]
     */
    private const DATE_FIELDS = ['created', 'updated', 'published', 'depublished'];

    /**
     * Text fields that support case-insensitive comparison
     *
     * @var string[]
     */
    private const TEXT_FIELDS = [
        'name',
        'description',
        'uri',
        'folder',
        'application',
        'organisation',
        'owner',
        'schemaVersion',
    ];

    /**
     * Apply metadata filters to the query builder
     *
     * Handles filtering on metadata fields (those in @self) like register, schema, etc.
     * Uses table alias 'o.' to avoid ambiguous column references when JOINs are present.
     *
     * @param IQueryBuilder $queryBuilder    The query builder to modify
     * @param array         $metadataFilters Array of metadata filters
     *
     * @phpstan-param IQueryBuilder $queryBuilder
     * @phpstan-param array<string, mixed> $metadataFilters
     *
     * @psalm-param IQueryBuilder $queryBuilder
     * @psalm-param array<string, mixed> $metadataFilters
     *
     * @return IQueryBuilder The modified query builder
     */
    public function applyMetadataFilters(IQueryBuilder $queryBuilder, array $metadataFilters): IQueryBuilder
    {
        foreach ($metadataFilters as $field => $value) {
            if ($this->isValidMetadataField($field) === false) {
                continue;
            }

            $qualifiedField = 'o.'.$field;

            $nullApplied = $this->applyNullCheck(
                queryBuilder: $queryBuilder,
                qualifiedField: $qualifiedField,
                value: $value,
            );
            if ($nullApplied === true) {
                continue;
            }

            if ($this->isTextFieldWithArrayValue(field: $field, value: $value) === true) {
                $this->applyTextFieldOperators(
                    queryBuilder: $queryBuilder,
                    field: $field,
                    qualifiedField: $qualifiedField,
                    value: $value,
                );
                continue;
            }

            if ($this->isDateFieldWithArrayValue(field: $field, value: $value) === true) {
                $this->applyDateFieldOperators(
                    queryBuilder: $queryBuilder,
                    field: $field,
                    qualifiedField: $qualifiedField,
                    value: $value,
                );
                continue;
            }

            $logicalApplied = $this->applyLogicalOperators(
                queryBuilder: $queryBuilder,
                qualifiedField: $qualifiedField,
                value: $value,
            );
            if ($logicalApplied === true) {
                continue;
            }

            $this->applySimpleFilter(
                queryBuilder: $queryBuilder,
                field: $field,
                qualifiedField: $qualifiedField,
                value: $value,
            );
        }//end foreach

        return $queryBuilder;
    }//end applyMetadataFilters()

    /**
     * Check if field is a valid metadata field
     *
     * @param string $field Field name
     *
     * @return bool True if valid
     */
    private function isValidMetadataField(string $field): bool
    {
        return in_array($field, self::MAIN_FIELDS, true);
    }//end isValidMetadataField()

    /**
     * Check if field is a text field
     *
     * @param string $field Field name
     *
     * @return bool True if text field
     */
    private function isTextField(string $field): bool
    {
        return in_array($field, self::TEXT_FIELDS, true);
    }//end isTextField()

    /**
     * Check if field is a date field
     *
     * @param string $field Field name
     *
     * @return bool True if date field
     */
    private function isDateField(string $field): bool
    {
        return in_array($field, self::DATE_FIELDS, true);
    }//end isDateField()

    /**
     * Apply null check if value is IS NULL or IS NOT NULL
     *
     * @param IQueryBuilder $queryBuilder   Query builder
     * @param string        $qualifiedField Qualified field name
     * @param mixed         $value          Filter value
     *
     * @return bool True if null check was applied
     */
    private function applyNullCheck(IQueryBuilder $queryBuilder, string $qualifiedField, mixed $value): bool
    {
        if ($value === 'IS NOT NULL') {
            $queryBuilder->andWhere($queryBuilder->expr()->isNotNull($qualifiedField));
            return true;
        }

        if ($value === 'IS NULL') {
            $queryBuilder->andWhere($queryBuilder->expr()->isNull($qualifiedField));
            return true;
        }

        return false;
    }//end applyNullCheck()

    /**
     * Check if this is a text field with array value
     *
     * @param string $field Field name
     * @param mixed  $value Filter value
     *
     * @return bool True if text field with array
     */
    private function isTextFieldWithArrayValue(string $field, mixed $value): bool
    {
        return $this->isTextField($field) === true && is_array($value) === true;
    }//end isTextFieldWithArrayValue()

    /**
     * Check if this is a date field with array value
     *
     * @param string $field Field name
     * @param mixed  $value Filter value
     *
     * @return bool True if date field with array
     */
    private function isDateFieldWithArrayValue(string $field, mixed $value): bool
    {
        return $this->isDateField($field) === true && is_array($value) === true;
    }//end isDateFieldWithArrayValue()

    /**
     * Apply text field operators
     *
     * @param IQueryBuilder $queryBuilder   Query builder
     * @param string        $field          Field name
     * @param string        $qualifiedField Qualified field name
     * @param array         $value          Operator value pairs
     *
     * @return void
     */
    private function applyTextFieldOperators(
        IQueryBuilder $queryBuilder,
        string $field,
        string $qualifiedField,
        array $value,
    ): void {
        foreach ($value as $operator => $operatorValue) {
            if ($this->applyPatternOperator(
                queryBuilder: $queryBuilder,
                qualifiedField: $qualifiedField,
                operator: $operator,
                operatorValue: $operatorValue,
            ) === true
            ) {
                continue;
            }

            if ($this->applyExistenceOperator(
                queryBuilder: $queryBuilder,
                qualifiedField: $qualifiedField,
                operator: $operator,
                operatorValue: $operatorValue,
            ) === true
            ) {
                continue;
            }

            if ($this->applyTextLogicalOperator(
                queryBuilder: $queryBuilder,
                field: $field,
                qualifiedField: $qualifiedField,
                operator: $operator,
                operatorValue: $operatorValue,
            ) === true
            ) {
                return;
            }

            if (is_numeric($operator) === true) {
                $this->applyInClause(queryBuilder: $queryBuilder, qualifiedField: $qualifiedField, value: $value);
                return;
            }
        }//end foreach
    }//end applyTextFieldOperators()

    /**
     * Apply pattern operators (contains, starts with, ends with, not equals, case-sensitive equals)
     *
     * @param IQueryBuilder $queryBuilder   Query builder
     * @param string        $qualifiedField Qualified field name
     * @param string        $operator       Operator
     * @param mixed         $operatorValue  Value
     *
     * @return bool True if handled
     */
    private function applyPatternOperator(
        IQueryBuilder $queryBuilder,
        string $qualifiedField,
        string $operator,
        mixed $operatorValue,
    ): bool {
        $patternMap = [
            '~' => '%'.$operatorValue.'%',
            '^' => $operatorValue.'%',
            '$' => '%'.$operatorValue,
        ];

        if (isset($patternMap[$operator]) === true) {
            $param = $queryBuilder->createNamedParameter($patternMap[$operator]);
            $queryBuilder->andWhere($queryBuilder->expr()->like($qualifiedField, $param));
            return true;
        }

        if ($operator === 'ne') {
            $param = $queryBuilder->createNamedParameter($operatorValue);
            $queryBuilder->andWhere($queryBuilder->expr()->neq($qualifiedField, $param));
            return true;
        }

        if ($operator === '===') {
            $param = $queryBuilder->createNamedParameter($operatorValue);
            $queryBuilder->andWhere($queryBuilder->expr()->eq($qualifiedField, $param));
            return true;
        }

        return false;
    }//end applyPatternOperator()

    /**
     * Apply existence operators (exists, empty, null)
     *
     * @param IQueryBuilder $queryBuilder   Query builder
     * @param string        $qualifiedField Qualified field name
     * @param string        $operator       Operator
     * @param mixed         $operatorValue  Value
     *
     * @return bool True if handled
     */
    private function applyExistenceOperator(
        IQueryBuilder $queryBuilder,
        string $qualifiedField,
        string $operator,
        mixed $operatorValue,
    ): bool {
        $isTrue = ($operatorValue === 'true' || $operatorValue === true);

        if ($operator === 'exists') {
            $this->applyExistsOperator(queryBuilder: $queryBuilder, qualifiedField: $qualifiedField, isTrue: $isTrue);
            return true;
        }

        if ($operator === 'empty') {
            $this->applyEmptyOperator(queryBuilder: $queryBuilder, qualifiedField: $qualifiedField, isTrue: $isTrue);
            return true;
        }

        if ($operator === 'null') {
            $this->applyNullOperator(queryBuilder: $queryBuilder, qualifiedField: $qualifiedField, isTrue: $isTrue);
            return true;
        }

        return false;
    }//end applyExistenceOperator()

    /**
     * Apply exists operator
     *
     * @param IQueryBuilder $queryBuilder   Query builder
     * @param string        $qualifiedField Qualified field name
     * @param bool          $isTrue         Whether checking for existence
     *
     * @return void
     */
    private function applyExistsOperator(IQueryBuilder $queryBuilder, string $qualifiedField, bool $isTrue): void
    {
        $emptyParam = $queryBuilder->createNamedParameter('');
        if ($isTrue === false) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->orX(
                    $queryBuilder->expr()->isNull($qualifiedField),
                    $queryBuilder->expr()->eq($qualifiedField, $emptyParam)
                )
            );
            return;
        }

        $queryBuilder->andWhere(
            $queryBuilder->expr()->andX(
                $queryBuilder->expr()->isNotNull($qualifiedField),
                $queryBuilder->expr()->neq($qualifiedField, $emptyParam)
            )
        );
    }//end applyExistsOperator()

    /**
     * Apply empty operator
     *
     * @param IQueryBuilder $queryBuilder   Query builder
     * @param string        $qualifiedField Qualified field name
     * @param bool          $isTrue         Whether checking for empty
     *
     * @return void
     */
    private function applyEmptyOperator(IQueryBuilder $queryBuilder, string $qualifiedField, bool $isTrue): void
    {
        $emptyParam = $queryBuilder->createNamedParameter('');
        if ($isTrue === true) {
            $queryBuilder->andWhere($queryBuilder->expr()->eq($qualifiedField, $emptyParam));
            return;
        }

        $queryBuilder->andWhere($queryBuilder->expr()->neq($qualifiedField, $emptyParam));
    }//end applyEmptyOperator()

    /**
     * Apply null operator
     *
     * @param IQueryBuilder $queryBuilder   Query builder
     * @param string        $qualifiedField Qualified field name
     * @param bool          $isTrue         Whether checking for null
     *
     * @return void
     */
    private function applyNullOperator(IQueryBuilder $queryBuilder, string $qualifiedField, bool $isTrue): void
    {
        if ($isTrue === true) {
            $queryBuilder->andWhere($queryBuilder->expr()->isNull($qualifiedField));
            return;
        }

        $queryBuilder->andWhere($queryBuilder->expr()->isNotNull($qualifiedField));
    }//end applyNullOperator()

    /**
     * Apply text logical operator (or/and)
     *
     * @param IQueryBuilder $queryBuilder   Query builder
     * @param string        $field          Field name
     * @param string        $qualifiedField Qualified field name
     * @param string        $operator       Operator (or/and)
     * @param mixed         $operatorValue  Values
     *
     * @return bool True if handled (should break)
     */
    private function applyTextLogicalOperator(
        IQueryBuilder $queryBuilder,
        string $field,
        string $qualifiedField,
        string $operator,
        mixed $operatorValue,
    ): bool {
        if ($operator !== 'or' && $operator !== 'and') {
            return false;
        }

        if (is_string($operatorValue) === true) {
            $values = array_map('trim', explode(',', $operatorValue));
        } else {
            $values = $operatorValue;
        }

        if (empty($values) === true) {
            return true;
        }

        if ($operator === 'or') {
            $orConditions = $queryBuilder->expr()->orX();
            foreach ($values as $val) {
                $orConditions->add(
                    $this->createTextEqualityCondition(
                        queryBuilder: $queryBuilder,
                        field: $field,
                        qualifiedField: $qualifiedField,
                        value: $val,
                    )
                );
            }

            $queryBuilder->andWhere($orConditions);

            return true;
        }

        foreach ($values as $val) {
            $queryBuilder->andWhere(
                $this->createTextEqualityCondition(
                    queryBuilder: $queryBuilder,
                    field: $field,
                    qualifiedField: $qualifiedField,
                    value: $val,
                )
            );
        }

        return true;
    }//end applyTextLogicalOperator()

    /**
     * Create text equality condition (case-insensitive for text fields)
     *
     * @param IQueryBuilder $queryBuilder   Query builder
     * @param string        $field          Field name
     * @param string        $qualifiedField Qualified field name
     * @param mixed         $value          Value
     *
     * @return mixed Condition expression
     */
    private function createTextEqualityCondition(
        IQueryBuilder $queryBuilder,
        string $field,
        string $qualifiedField,
        mixed $value,
    ): mixed {
        if ($this->isTextField($field) === false) {
            return $queryBuilder->expr()->eq($qualifiedField, $queryBuilder->createNamedParameter($value));
        }

        return $queryBuilder->expr()->eq(
            $queryBuilder->createFunction('LOWER('.$qualifiedField.')'),
            $queryBuilder->createNamedParameter(strtolower($value))
        );
    }//end createTextEqualityCondition()

    /**
     * Apply IN clause for array values
     *
     * @param IQueryBuilder $queryBuilder   Query builder
     * @param string        $qualifiedField Qualified field name
     * @param array         $value          Array of values
     *
     * @return void
     */
    private function applyInClause(IQueryBuilder $queryBuilder, string $qualifiedField, array $value): void
    {
        $param = $queryBuilder->createNamedParameter($value, \Doctrine\DBAL\Connection::PARAM_STR_ARRAY);
        $queryBuilder->andWhere($queryBuilder->expr()->in($qualifiedField, $param));
    }//end applyInClause()

    /**
     * Apply date field operators
     *
     * @param IQueryBuilder $queryBuilder   Query builder
     * @param string        $field          Field name
     * @param string        $qualifiedField Qualified field name
     * @param array         $value          Operator value pairs
     *
     * @return void
     */
    private function applyDateFieldOperators(
        IQueryBuilder $queryBuilder,
        string $field,
        string $qualifiedField,
        array $value,
    ): void {
        foreach ($value as $operator => $operatorValue) {
            $sqlOperator     = $this->convertToSqlOperator($operator);
            $normalizedValue = $this->normalizeDateValue(field: $field, value: $operatorValue);

            if ($this->applyComparisonOperator(
                queryBuilder: $queryBuilder,
                qualifiedField: $qualifiedField,
                operator: $sqlOperator,
                value: $normalizedValue,
            ) === true
            ) {
                continue;
            }

            if ($this->applyDateLogicalOperator(
                queryBuilder: $queryBuilder,
                qualifiedField: $qualifiedField,
                operator: $sqlOperator,
                operatorValue: $operatorValue,
            ) === true
            ) {
                return;
            }

            if (is_numeric($operator) === true) {
                $this->applyInClause(queryBuilder: $queryBuilder, qualifiedField: $qualifiedField, value: $value);
                return;
            }
        }//end foreach
    }//end applyDateFieldOperators()

    /**
     * Convert PHP-friendly operator to SQL operator
     *
     * @param string $operator PHP operator
     *
     * @return string SQL operator
     */
    private function convertToSqlOperator(string $operator): string
    {
        $operatorMap = [
            'gte' => '>=',
            'lte' => '<=',
            'gt'  => '>',
            'lt'  => '<',
            'ne'  => '!=',
            'eq'  => '=',
        ];

        return $operatorMap[$operator] ?? $operator;
    }//end convertToSqlOperator()

    /**
     * Normalize date value to database format
     *
     * @param string $field Field name
     * @param mixed  $value Value
     *
     * @return string Normalized value
     */
    private function normalizeDateValue(string $field, mixed $value): string
    {
        if ($this->isDateField($field) === false) {
            return $value;
        }

        try {
            $dateTime = new DateTime($value);
            return $dateTime->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            return $value;
        }
    }//end normalizeDateValue()

    /**
     * Apply comparison operator
     *
     * @param IQueryBuilder $queryBuilder   Query builder
     * @param string        $qualifiedField Qualified field name
     * @param string        $operator       SQL operator
     * @param mixed         $value          Value
     *
     * @return bool True if handled
     */
    private function applyComparisonOperator(
        IQueryBuilder $queryBuilder,
        string $qualifiedField,
        string $operator,
        mixed $value,
    ): bool {
        $operatorMethods = [
            '>=' => 'gte',
            '<=' => 'lte',
            '>'  => 'gt',
            '<'  => 'lt',
            '='  => 'eq',
        ];

        if (isset($operatorMethods[$operator]) === false) {
            return false;
        }

        $param  = $queryBuilder->createNamedParameter($value);
        $method = $operatorMethods[$operator];
        $queryBuilder->andWhere($queryBuilder->expr()->$method($qualifiedField, $param));
        return true;
    }//end applyComparisonOperator()

    /**
     * Apply date logical operator (or/and)
     *
     * @param IQueryBuilder $queryBuilder   Query builder
     * @param string        $qualifiedField Qualified field name
     * @param string        $operator       Operator
     * @param mixed         $operatorValue  Values
     *
     * @return bool True if handled (should break)
     */
    private function applyDateLogicalOperator(
        IQueryBuilder $queryBuilder,
        string $qualifiedField,
        string $operator,
        mixed $operatorValue,
    ): bool {
        if ($operator !== 'or' && $operator !== 'and') {
            return false;
        }

        if (is_string($operatorValue) === true) {
            $values = array_map('trim', explode(',', $operatorValue));
        } else {
            $values = $operatorValue;
        }

        if (empty($values) === true) {
            return true;
        }

        if ($operator === 'or') {
            $orConditions = $queryBuilder->expr()->orX();
            foreach ($values as $val) {
                $orConditions->add($queryBuilder->expr()->eq($qualifiedField, $queryBuilder->createNamedParameter($val)));
            }

            $queryBuilder->andWhere($orConditions);

            return true;
        }

        foreach ($values as $val) {
            $queryBuilder->andWhere($queryBuilder->expr()->eq($qualifiedField, $queryBuilder->createNamedParameter($val)));
        }

        return true;
    }//end applyDateLogicalOperator()

    /**
     * Apply logical operators for non-text, non-date fields
     *
     * @param IQueryBuilder $queryBuilder   Query builder
     * @param string        $qualifiedField Qualified field name
     * @param mixed         $value          Filter value
     *
     * @return bool True if handled
     */
    private function applyLogicalOperators(IQueryBuilder $queryBuilder, string $qualifiedField, mixed $value): bool
    {
        if (is_array($value) === false) {
            return false;
        }

        $hasOr  = ($value['or'] ?? null) !== null;
        $hasAnd = ($value['and'] ?? null) !== null;

        if ($hasOr === false && $hasAnd === false) {
            return false;
        }

        if ($hasAnd === true) {
            if (is_string($value['and']) === true) {
                $values = array_map('trim', explode(',', $value['and']));
            } else {
                $values = $value['and'];
            }

            foreach ($values as $val) {
                $queryBuilder->andWhere(
                    $queryBuilder->expr()->eq($qualifiedField, $queryBuilder->createNamedParameter($val))
                );
            }

            return true;
        }

        if (is_string($value['or']) === true) {
            $values = array_map('trim', explode(',', $value['or']));
        } else {
            $values = $value['or'];
        }

        $orConditions = $queryBuilder->expr()->orX();
        foreach ($values as $val) {
            $orConditions->add($queryBuilder->expr()->eq($qualifiedField, $queryBuilder->createNamedParameter($val)));
        }

        $queryBuilder->andWhere($orConditions);

        return true;
    }//end applyLogicalOperators()

    /**
     * Apply simple filter (single value or array)
     *
     * @param IQueryBuilder $queryBuilder   Query builder
     * @param string        $field          Field name
     * @param string        $qualifiedField Qualified field name
     * @param mixed         $value          Filter value
     *
     * @return void
     */
    private function applySimpleFilter(
        IQueryBuilder $queryBuilder,
        string $field,
        string $qualifiedField,
        mixed $value,
    ): void {
        if (is_array($value) === true) {
            $this->applyArrayFilter(
                queryBuilder: $queryBuilder,
                field: $field,
                qualifiedField: $qualifiedField,
                value: $value,
            );
            return;
        }

        $this->applySingleValueFilter(
            queryBuilder: $queryBuilder,
            field: $field,
            qualifiedField: $qualifiedField,
            value: $value,
        );
    }//end applySimpleFilter()

    /**
     * Apply array filter
     *
     * @param IQueryBuilder $queryBuilder   Query builder
     * @param string        $field          Field name
     * @param string        $qualifiedField Qualified field name
     * @param array         $value          Array of values
     *
     * @return void
     */
    private function applyArrayFilter(IQueryBuilder $queryBuilder, string $field, string $qualifiedField, array $value): void
    {
        if ($this->isTextField($field) === false) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->in(
                    $qualifiedField,
                    $queryBuilder->createNamedParameter($value, \Doctrine\DBAL\Connection::PARAM_STR_ARRAY)
                )
            );
            return;
        }

        $orConditions = $queryBuilder->expr()->orX();
        foreach ($value as $arrayValue) {
            $orConditions->add(
                $queryBuilder->expr()->eq(
                    $queryBuilder->createFunction('LOWER('.$qualifiedField.')'),
                    $queryBuilder->createNamedParameter(strtolower($arrayValue))
                )
            );
        }

        $queryBuilder->andWhere($orConditions);
    }//end applyArrayFilter()

    /**
     * Apply single value filter
     *
     * @param IQueryBuilder $queryBuilder   Query builder
     * @param string        $field          Field name
     * @param string        $qualifiedField Qualified field name
     * @param mixed         $value          Filter value
     *
     * @return void
     */
    private function applySingleValueFilter(
        IQueryBuilder $queryBuilder,
        string $field,
        string $qualifiedField,
        mixed $value,
    ): void {
        if ($this->isTextField($field) === false) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->eq($qualifiedField, $queryBuilder->createNamedParameter($value))
            );
            return;
        }

        $queryBuilder->andWhere(
            $queryBuilder->expr()->eq(
                $queryBuilder->createFunction('LOWER('.$qualifiedField.')'),
                $queryBuilder->createNamedParameter(strtolower($value))
            )
        );
    }//end applySingleValueFilter()

    /**
     * Apply JSON object filters to the query builder
     *
     * Handles filtering on JSON object fields using MariaDB JSON functions.
     *
     * @param IQueryBuilder $queryBuilder  The query builder to modify
     * @param array         $objectFilters Array of object filters
     *
     * @phpstan-param IQueryBuilder $queryBuilder
     * @phpstan-param array<string, mixed> $objectFilters
     *
     * @psalm-param IQueryBuilder $queryBuilder
     * @psalm-param array<string, mixed> $objectFilters
     *
     * @return IQueryBuilder The modified query builder
     */
    public function applyObjectFilters(IQueryBuilder $queryBuilder, array $objectFilters): IQueryBuilder
    {
        foreach ($objectFilters as $field => $value) {
            $this->applyJsonFieldFilter(queryBuilder: $queryBuilder, field: $field, value: $value);
        }

        return $queryBuilder;
    }//end applyObjectFilters()

    /**
     * Apply a filter on a specific JSON field
     *
     * Applies case-insensitive filtering for string values and exact matching for other types.
     *
     * @param IQueryBuilder $queryBuilder The query builder to modify
     * @param string        $field        The JSON field path (e.g., 'name' or 'address.city')
     * @param mixed         $value        The value to filter by
     *
     * @phpstan-param IQueryBuilder $queryBuilder
     * @phpstan-param string $field
     * @phpstan-param mixed $value
     *
     * @psalm-param IQueryBuilder $queryBuilder
     * @psalm-param string $field
     * @psalm-param mixed $value
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    private function applyJsonFieldFilter(IQueryBuilder $queryBuilder, string $field, mixed $value): void
    {
        // Build the JSON path - convert dot notation to JSON path.
        $jsonPath = '$.'.str_replace('.', '.', $field);

        // Handle special null checks.
        if ($value === 'IS NOT NULL') {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->isNotNull(
                    $queryBuilder->createFunction(
                        'JSON_EXTRACT(`object`, '.$queryBuilder->createNamedParameter($jsonPath).')'
                    )
                )
            );
            return;
        }

        if ($value === 'IS NULL') {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->isNull(
                    $queryBuilder->createFunction(
                        'JSON_EXTRACT(`object`, '.$queryBuilder->createNamedParameter($jsonPath).')'
                    )
                )
            );
            return;
        }

        // Handle array values (one of search).
        if (is_array($value) === true) {
            $orConditions = $queryBuilder->expr()->orX();

            foreach ($value as $arrayValue) {
                // Use case-insensitive comparison for string values.
                if (is_string($arrayValue) === false) {
                    // Exact match for non-string values (numbers, booleans, etc.).
                    $orConditions->add(
                        $queryBuilder->expr()->eq(
                            $queryBuilder->createFunction(
                                'JSON_UNQUOTE(JSON_EXTRACT(`object`, '.$queryBuilder->createNamedParameter($jsonPath).'))'
                            ),
                            $queryBuilder->createNamedParameter($arrayValue)
                        )
                    );

                    // Check if the value exists within an array using JSON_CONTAINS.
                    $jsonPathParam    = $queryBuilder->createNamedParameter($jsonPath);
                    $valueParam       = $queryBuilder->createNamedParameter(json_encode($arrayValue));
                    $jsonContainsFunc = "JSON_CONTAINS(JSON_EXTRACT(`object`, ".$jsonPathParam."), ".$valueParam.")";
                    $orConditions->add(
                        $queryBuilder->expr()->eq(
                            $queryBuilder->createFunction($jsonContainsFunc),
                            $queryBuilder->createNamedParameter(1)
                        )
                    );
                    continue;
                }//end if

                // Check for exact match (single value).
                $orConditions->add(
                    $queryBuilder->expr()->eq(
                        $queryBuilder->createFunction(
                            'LOWER(JSON_UNQUOTE(JSON_EXTRACT(`object`, '.$queryBuilder->createNamedParameter($jsonPath).')))'
                        ),
                        $queryBuilder->createNamedParameter(strtolower($arrayValue))
                    )
                );

                // Check if the value exists within an array using JSON_CONTAINS (case-insensitive).
                $pathParam         = $queryBuilder->createNamedParameter($jsonPath);
                $valParam          = $queryBuilder->createNamedParameter(json_encode(strtolower($arrayValue)));
                $funcString        = "JSON_CONTAINS(LOWER(JSON_EXTRACT(`object`, ".$pathParam.")), ".$valParam.")";
                $jsonContainsCaseI = $funcString;
                $orConditions->add(
                    $queryBuilder->expr()->eq(
                        $queryBuilder->createFunction($jsonContainsCaseI),
                        $queryBuilder->createNamedParameter(1)
                    )
                );
            }//end foreach

            $queryBuilder->andWhere($orConditions);
            return;
        }//end if

        // Handle single values - use case-insensitive comparison for strings.
        $singleValConds = $queryBuilder->expr()->orX();

        if (is_string($value) === false) {
            // Exact match for non-string values (numbers, booleans, etc.).
            // Check for exact match (single value).
            $singleValConds->add(
                $queryBuilder->expr()->eq(
                    $queryBuilder->createFunction(
                        'JSON_UNQUOTE(JSON_EXTRACT(`object`, '.$queryBuilder->createNamedParameter($jsonPath).'))'
                    ),
                    $queryBuilder->createNamedParameter($value)
                )
            );

            // Check if the value exists within an array using JSON_CONTAINS.
            $pathP = $queryBuilder->createNamedParameter($jsonPath);
            $valP  = $queryBuilder->createNamedParameter(json_encode($value));
            $jsonContainsExact = "JSON_CONTAINS(JSON_EXTRACT(`object`, ".$pathP."), ".$valP.")";
            $singleValConds->add(
                $queryBuilder->expr()->eq(
                    $queryBuilder->createFunction($jsonContainsExact),
                    $queryBuilder->createNamedParameter(1)
                )
            );

            $queryBuilder->andWhere($singleValConds);
            return;
        }//end if

        // Check for exact match (single value).
        $singleValConds->add(
            $queryBuilder->expr()->eq(
                $queryBuilder->createFunction(
                    'LOWER(JSON_UNQUOTE(JSON_EXTRACT(`object`, '.$queryBuilder->createNamedParameter($jsonPath).')))'
                ),
                $queryBuilder->createNamedParameter(strtolower($value))
            )
        );

        // Check if the value exists within an array using JSON_CONTAINS (case-insensitive).
        $jsonPathP           = $queryBuilder->createNamedParameter($jsonPath);
        $jsonValP            = $queryBuilder->createNamedParameter(json_encode(strtolower($value)));
        $jsonContainsCaseIns = "JSON_CONTAINS(LOWER(JSON_EXTRACT(`object`, ".$jsonPathP.")), ".$jsonValP.")";
        $singleValConds->add(
            $queryBuilder->expr()->eq(
                $queryBuilder->createFunction($jsonContainsCaseIns),
                $queryBuilder->createNamedParameter(1)
            )
        );

        $queryBuilder->andWhere($singleValConds);
    }//end applyJsonFieldFilter()

    /**
     * Apply full-text search on JSON object and metadata fields
     *
     * Performs a case-insensitive full-text search within the JSON object field and metadata fields.
     * Supports multiple search terms separated by ' OR ' for OR logic.
     *
     * Searches in the following fields:
     * - JSON object data (all fields within the object column)
     * - name (metadata field)
     * - description (metadata field)
     * - summary (metadata field)
     * - image (metadata field)
     *
     * @param IQueryBuilder $queryBuilder The query builder to modify
     * @param string        $searchTerm   The search term (can contain multiple terms separated by ' OR ')
     *
     * @phpstan-param IQueryBuilder $queryBuilder
     * @phpstan-param string $searchTerm
     *
     * @psalm-param IQueryBuilder $queryBuilder
     * @psalm-param string $searchTerm
     *
     * @return IQueryBuilder The modified query builder
     */
    public function applyFullTextSearch(IQueryBuilder $queryBuilder, string $searchTerm): IQueryBuilder
    {
        // Split search terms by ' OR ' to handle multiple search words.
        $searchTerms = array_filter(
            array_map('trim', explode(' OR ', $searchTerm)),
            function ($term) {
                return empty($term) === false;
            }
        );

        // If no valid search terms, return the query builder unchanged.
        if (empty($searchTerms) === true) {
            return $queryBuilder;
        }

        // Create OR conditions for each search term.
        $orConditions = $queryBuilder->expr()->orX();

        foreach ($searchTerms as $term) {
            // Clean the search term - remove wildcards and convert to lowercase.
            $cleanTerm = strtolower(trim($term));
            $cleanTerm = str_replace(['*', '%'], '', $cleanTerm);

            // Skip empty terms after cleaning.
            if (empty($cleanTerm) === true) {
                continue;
            }

            // Create OR conditions for each searchable field.
            // PERFORMANCE OPTIMIZATION: Search indexed metadata columns first for best performance.
            $termConditions = $queryBuilder->expr()->orX();

            // PRIORITY 1: Search in indexed metadata fields (FASTEST - uses database indexes).
            // These columns have indexes and provide the best search performance.
            $indexedFields = [
                'o.name'        => 'name',
                'o.summary'     => 'summary',
                'o.description' => 'description',
            ];

            foreach (array_keys($indexedFields) as $columnName) {
                $termConditions->add(
                    $queryBuilder->expr()->like(
                        $queryBuilder->createFunction('LOWER('.$columnName.')'),
                        $queryBuilder->createNamedParameter('%'.$cleanTerm.'%')
                    )
                );
            }

            // PRIORITY 2: Search in other metadata fields (MODERATE - no indexes but direct column access).
            $otherMetadataFields = ['o.image'];
            foreach ($otherMetadataFields as $columnName) {
                $termConditions->add(
                    $queryBuilder->expr()->like(
                        $queryBuilder->createFunction('LOWER('.$columnName.')'),
                        $queryBuilder->createNamedParameter('%'.$cleanTerm.'%')
                    )
                );
            }

            // **PERFORMANCE OPTIMIZATION**: JSON search on object field DISABLED for performance.
            // JSON_SEARCH on large object fields is extremely expensive (can add 500ms+ per query).
            // _search now only covers: name, description, summary for sub-500ms performance.
            //
            // If comprehensive JSON search is needed, use specific object field filters instead:.
            // E.g., ?fieldName=searchTerm rather than ?_search=searchTerm.
            //
            // Original code (DISABLED for performance):.
            // $jsonSearchFunction = "JSON_SEARCH(LOWER(`object`), 'all', ".$searchParam.")";
            // $termConditions->add(
            // $queryBuilder->expr()->isNotNull(
            // $queryBuilder->createFunction($jsonSearchFunction)
            // ).
            // );
            // Add the term conditions to the main OR group.
            $orConditions->add($termConditions);
        }//end foreach

        // Add the OR conditions to the query if we have any valid terms.
        if ($orConditions->count() > 0) {
            $queryBuilder->andWhere($orConditions);
        }

        return $queryBuilder;
    }//end applyFullTextSearch()

    /**
     * Apply sorting on JSON fields
     *
     * Handles sorting by JSON object fields using MariaDB JSON functions.
     *
     * @param IQueryBuilder $queryBuilder The query builder to modify
     * @param array         $sortFields   Array of field => direction pairs
     *
     * @phpstan-param IQueryBuilder $queryBuilder
     * @phpstan-param array<string, string> $sortFields
     *
     * @psalm-param IQueryBuilder $queryBuilder
     * @psalm-param array<string, string> $sortFields
     *
     * @return IQueryBuilder The modified query builder
     */
    public function applySorting(IQueryBuilder $queryBuilder, array $sortFields): IQueryBuilder
    {
        foreach ($sortFields as $field => $direction) {
            // Validate direction.
            $direction = strtoupper($direction);
            if (in_array($direction, ['ASC', 'DESC']) === false) {
                $direction = 'ASC';
            }

            // Build the JSON path.
            $jsonPath = '$.'.str_replace('.', '.', $field);

            $queryBuilder->addOrderBy(
                $queryBuilder->createFunction(
                    'JSON_UNQUOTE(JSON_EXTRACT(`object`, '.$queryBuilder->createNamedParameter($jsonPath).'))'
                ),
                $direction
            );
        }

        return $queryBuilder;
    }//end applySorting()
}//end class
