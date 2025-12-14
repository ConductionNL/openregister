<?php
/**
 * OpenRegister MySQLJsonService
 *
 * Service class for handling MySQL JSON operations in the OpenRegister application.
 *
 * This service provides methods for:
 * - Ordering JSON data
 * - Searching JSON data
 * - Filtering JSON data
 * - Aggregating JSON data
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

namespace OCA\OpenRegister\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * MySQLJsonService handles MySQL JSON operations
 *
 * Service class for handling MySQL JSON operations in the OpenRegister application.
 * This service provides methods for ordering, searching, filtering, and aggregating
 * JSON data stored in MySQL database columns.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */
class MySQLJsonService implements IDatabaseJsonService
{


    /**
     * Add ordering to a query based on JSON fields
     *
     * Adds ORDER BY clauses to query builder for sorting by JSON field values.
     * Uses MySQL JSON functions (JSON_EXTRACT and JSON_UNQUOTE) to extract and
     * convert JSON values for sorting.
     *
     * @param IQueryBuilder         $builder The query builder instance to modify
     * @param array<string, string> $order   Array of field => direction pairs for ordering
     *                                       (e.g., ['name' => 'ASC', 'created' => 'DESC'])
     *
     * @return IQueryBuilder The modified query builder with ORDER BY clauses added
     */
    public function orderJson(IQueryBuilder $builder, array $order=[]): IQueryBuilder
    {
        // Loop through each ordering field and direction.
        foreach ($order as $item => $direction) {
            // Step 1: Create named parameters for the JSON path and sort direction.
            // JSON path format: "$.fieldName" (e.g., "$.name").
            $builder->createNamedParameter(value: "$.$item", placeHolder: ":path$item");
            $builder->createNamedParameter(value: $direction, placeHolder: ":direction$item");

            // Step 2: Add ORDER BY clause using MySQL JSON functions.
            // JSON_EXTRACT extracts value from JSON, JSON_UNQUOTE converts to string for sorting.
            $builder->orderBy($builder->createFunction("json_unquote(json_extract(object, :path$item))"), $direction);
        }

        return $builder;

    }//end orderJson()


    /**
     * Add ordering to a query based on root-level database columns
     *
     * Adds ORDER BY clauses for sorting by root-level database columns (not JSON fields).
     * Used when sorting by non-JSON columns like id, created_at, etc.
     *
     * @param IQueryBuilder         $builder The query builder instance to modify
     * @param array<string, string> $order   Array of column => direction pairs for ordering
     *                                       (e.g., ['id' => 'ASC', 'created_at' => 'DESC'])
     *
     * @return IQueryBuilder The modified query builder with ORDER BY clauses added
     */
    public function orderInRoot(IQueryBuilder $builder, array $order=[]): IQueryBuilder
    {
        // Loop through each ordering column and direction.
        foreach ($order as $item => $direction) {
            // Add ORDER BY clause for root-level column (not JSON field).
            $builder->orderBy($item, $direction);
        }

        return $builder;

    }//end orderInRoot()


    /**
     * Add full-text search functionality for JSON fields
     *
     * Adds WHERE clause to search for a term within JSON data stored in the object column.
     * Uses MySQL JSON_SEARCH function for case-insensitive searching across all JSON values.
     * Supports partial matching with wildcards.
     *
     * @param IQueryBuilder $builder The query builder instance to modify
     * @param string|null   $search  The search term to look for (null = no search)
     *
     * @return IQueryBuilder The modified query builder with search WHERE clause added
     */
    public function searchJson(IQueryBuilder $builder, ?string $search=null): IQueryBuilder
    {
        // Only add search clause if search term is provided.
        if ($search !== null) {
            // Step 1: Create named parameter for the search term with wildcards.
            // Wildcards enable partial matching (e.g., "test" matches "testing").
            $builder->createNamedParameter(value: "%$search%", placeHolder: ':search');

            // Step 2: Add WHERE clause to search case-insensitive across all JSON fields.
            // JSON_SEARCH searches for value in JSON, LOWER() makes it case-insensitive.
            $builder->andWhere("JSON_SEARCH(LOWER(object), 'one', LOWER(:search)) IS NOT NULL");
        }

        return $builder;

    }//end searchJson()


    /**
     * Add complex filters to the filter set.
     *
     * Handles special filter cases like 'after' and 'before' for date ranges,
     * as well as IN clauses for arrays of values.
     *
     * @param IQueryBuilder $builder The query builder instance
     * @param string        $filter  The filtered field
     * @param array         $values  The values to filter on
     *
     * @return IQueryBuilder The modified query builder
     */
    private function jsonFilterArray(IQueryBuilder $builder, string $filter, array $values): IQueryBuilder
    {
        foreach ($values as $key => $value) {
            switch ($key) {
                case 'after':
                case 'gte':
                case '>=':
                    // Add >= filter for dates after specified value.
                    $builder->createNamedParameter(
                        value: $value,
                        type: IQueryBuilder::PARAM_STR,
                        placeHolder: ":value{$filter}after"
                    );
                    $builder->andWhere("json_unquote(json_extract(object, :path$filter)) >= (:value{$filter}after)");
                    break;
                case 'before':
                case 'lte':
                case '<=':
                    // Add <= filter for dates before specified value.
                    $builder->createNamedParameter(
                        value: $value,
                        type: IQueryBuilder::PARAM_STR,
                        placeHolder: ":value{$filter}before"
                    );
                    $builder->andWhere("json_unquote(json_extract(object, :path$filter)) <= (:value{$filter}before)");
                    break;
                case 'strictly_after':
                case 'gt':
                case '>':
                    // Add >= filter for dates after specified value.
                    $builder->createNamedParameter(
                        value: $value,
                        type: IQueryBuilder::PARAM_STR,
                        placeHolder: ":value{$filter}after"
                    );
                    $builder->andWhere("json_unquote(json_extract(object, :path$filter)) > (:value{$filter}after)");
                    break;
                case 'strictly_before':
                case 'lt':
                case '<':
                    // Add <= filter for dates before specified value.
                    $builder->createNamedParameter(
                        value: $value,
                        type: IQueryBuilder::PARAM_STR,
                        placeHolder: ":value{$filter}before"
                    );
                    $builder->andWhere("json_unquote(json_extract(object, :path$filter)) < (:value{$filter}before)");
                    break;
                default:
                    if (is_array($value) === false) {
                        $value = explode(',', $value);
                    }

                    // Add IN clause for array of values.
                    $builder->createNamedParameter(
                        value: $value,
                        type: IQueryBuilder::PARAM_STR_ARRAY,
                        placeHolder: ":value{$filter}"
                    );
                    $builder
                        ->andWhere("json_unquote(json_extract(object, :path$filter)) IN (:value$filter)");
                    break;
            }//end switch
        }//end foreach

        return $builder;

    }//end jsonFilterArray()


    /**
     * Build a string to search multiple values in an array.
     *
     * Creates an OR condition for each value to check if it exists
     * within a JSON array field.
     *
     * @param array         $values  The values to search for
     * @param string        $filter  The field to filter on
     * @param IQueryBuilder $builder The query builder instance
     *
     * @return string The resulting OR conditions as a string
     */
    private function getMultipleContains(array $values, string $filter, IQueryBuilder $builder): string
    {
        $orString = '';
        foreach ($values as $key => $value) {
            // Create parameter for each value.
            $builder->createNamedParameter(value: $value, type: IQueryBuilder::PARAM_STR, placeHolder: ":value$filter$key");
            // Add OR condition checking if value exists in JSON array.
            $orString .= " OR json_contains(object, json_quote(:value$filter$key), :path$filter)";
        }

        return $orString;

    }//end getMultipleContains()


    /**
     * Parse filter in PHP style to MySQL style filter.
     *
     * @param string $filter The original filter
     *
     * @return string The parsed filter for MySQL
     */
    private function parseFilter(string $filter): string
    {
        $explodedFilter = explode(
            separator: '_',
            string: $filter
        );

        $explodedFilter = array_map(
            function ($field) {
                return "\"$field\"";
            },
            $explodedFilter
        );

        return implode(
            separator: '**.',
            array: $explodedFilter
        );

    }//end parseFilter()


    /**
     * Add JSON filtering to a query.
     *
     * Handles various filter types including:
     * - Complex filters (after/before)
     * - Array filters
     * - Simple equality filters
     * - Special @self.deleted.* filters for deleted object properties
     *
     * @param IQueryBuilder $builder The query builder instance
     * @param array         $filters Array of filters to apply
     *
     * @return IQueryBuilder The modified query builder
     */
    public function filterJson(IQueryBuilder $builder, array $filters): IQueryBuilder
    {
        // Remove special system fields from filters.
        unset($filters['register'], $filters['schema'], $filters['updated'], $filters['created'], $filters['_queries']);

        foreach ($filters as $filter => $value) {
            // Handle special @self.deleted filters.
            if (str_starts_with($filter, '@self.deleted') === true) {
                $builder = $this->handleSelfDeletedFilter(builder: $builder, filter: $filter, value: $value);
                continue;
            }

            $parsedFilter = $this->parseFilter($filter);

            // Create parameter for JSON path.
            $builder->createNamedParameter(
                value: "$.$parsedFilter",
                placeHolder: ":path$filter"
            );

            if ($value === 'IS NULL') {
                $builder->andWhere(
                    "json_unquote(json_extract(object, :path$filter)) = 'null' OR json_unquote(json_extract(object, :path$filter)) IS NULL"
                );
                continue;
            } else if ($value === 'IS NOT NULL') {
                $builder->andWhere(
                    "json_unquote(json_extract(object, :path$filter)) != 'null' AND json_unquote(json_extract(object, :path$filter)) IS NOT NULL"
                );
                continue;
            }

            if (is_array($value) === true) {
                if (array_is_list($value) === false) {
                    // Handle complex filters (after/before).
                    $builder = $this->jsonFilterArray(builder: $builder, filter: $filter, values: $value);
                    continue;
                }

                // Handle array of values with IN clause and contains check.
                $builder->createNamedParameter(
                value: $value,
                type: IQueryBuilder::PARAM_STR_ARRAY,
                placeHolder: ":value$filter"
                );
                $builder->andWhere(
                "(json_unquote(json_extract(object, :path$filter)) IN (:value$filter))".$this->getMultipleContains(values: $value, filter: $filter, builder: $builder)
                );
                continue;
            }

            // Handle simple equality filter.
            // After handling arrays and special string values, $value can still be bool, string, int, float, etc.
            //
            if (is_bool($value) === true) {
                $builder->createNamedParameter(
                    value: $value,
                    type: IQueryBuilder::PARAM_BOOL,
                    placeHolder: ":value$filter"
                );
            } else {
                $builder->createNamedParameter(
                    value: $value,
                    placeHolder: ":value$filter"
                );
            }

            $builder->andWhere(
                "json_extract(object, :path$filter) = :value$filter OR json_contains(json_extract(object, :path$filter), json_quote(:value$filter))"
            );
        }//end foreach

        return $builder;

    }//end filterJson()


    /**
     * Handle @self.deleted.* filters for deleted object properties
     *
     * @param IQueryBuilder $builder The query builder instance
     * @param string        $filter  The filter key (e.g., '@self.deleted.deletedBy')
     * @param mixed         $value   The filter value
     *
     * @return IQueryBuilder The modified query builder
     */
    private function handleSelfDeletedFilter(IQueryBuilder $builder, string $filter, $value): IQueryBuilder
    {
        if ($filter === '@self.deleted') {
            // Handle @self.deleted filter (check if object is deleted or not).
            if ($value === 'IS NOT NULL') {
                $builder->andWhere($builder->expr()->isNotNull('o.deleted'));
            } else if ($value === 'IS NULL') {
                $builder->andWhere($builder->expr()->isNull('o.deleted'));
            }

            return $builder;
        }

        // Handle specific deleted properties like @self.deleted.deletedBy.
        $deletedProperty = str_replace('@self.deleted.', '', $filter);

        // Create parameter name for this specific deleted filter.
        $paramName = str_replace('@self.deleted.', 'deleted_', $filter);
        $paramName = str_replace('.', '_', $paramName);

        if ($value === 'IS NOT NULL') {
            // Check if the deleted property exists and is not null.
            $builder->andWhere(
                $builder->expr()->isNotNull(
                    $builder->createFunction(
                        "JSON_UNQUOTE(JSON_EXTRACT(o.deleted, '$.$deletedProperty'))"
                    )
                )
            );
        } else if ($value === 'IS NULL') {
            // Check if the deleted property is null or doesn't exist.
            $builder->andWhere(
                $builder->expr()->isNull(
                    $builder->createFunction(
                        "JSON_UNQUOTE(JSON_EXTRACT(o.deleted, '$.$deletedProperty'))"
                    )
                )
            );
        } else if (is_array($value) === true) {
            // Handle array filters for deleted properties.
            if (array_is_list($value) === false) {
                // Handle complex filters (after/before) for deleted properties.
                foreach ($value as $op => $filterValue) {
                    $opParamName = $paramName.'_'.$op;
                    $builder->createNamedParameter(
                        value: $filterValue,
                        type: IQueryBuilder::PARAM_STR,
                        placeHolder: ":$opParamName"
                    );

                    switch ($op) {
                        case 'after':
                        case 'gte':
                        case '>=':
                            $builder->andWhere("JSON_UNQUOTE(JSON_EXTRACT(o.deleted, '$.$deletedProperty')) >= :$opParamName");
                            break;
                        case 'before':
                        case 'lte':
                        case '<=':
                            $builder->andWhere("JSON_UNQUOTE(JSON_EXTRACT(o.deleted, '$.$deletedProperty')) <= :$opParamName");
                            break;
                        case 'strictly_after':
                        case 'gt':
                        case '>':
                            $builder->andWhere("JSON_UNQUOTE(JSON_EXTRACT(o.deleted, '$.$deletedProperty')) > :$opParamName");
                            break;
                        case 'strictly_before':
                        case 'lt':
                        case '<':
                            $builder->andWhere("JSON_UNQUOTE(JSON_EXTRACT(o.deleted, '$.$deletedProperty')) < :$opParamName");
                            break;
                    }//end switch
                }//end foreach
            } else {
                // Handle IN array for deleted properties.
                $builder->createNamedParameter(
                    value: $value,
                    type: IQueryBuilder::PARAM_STR_ARRAY,
                    placeHolder: ":$paramName"
                );
                $builder->andWhere("JSON_UNQUOTE(JSON_EXTRACT(o.deleted, '$.$deletedProperty')) IN (:$paramName)");
            }//end if
        } else {
            // Handle simple equality filter for deleted properties.
            $builder->createNamedParameter(
                value: $value,
                placeHolder: ":$paramName"
            );
            $builder->andWhere("JSON_UNQUOTE(JSON_EXTRACT(o.deleted, '$.$deletedProperty')) = :$paramName");
        }//end if

        return $builder;

    }//end handleSelfDeletedFilter()


    /**
     * Get aggregations (facets) for specified fields.
     *
     * Returns counts of unique values for each specified field,
     * filtered by the provided filters and search term.
     *
     * @param IQueryBuilder $builder  The query builder instance
     * @param array         $fields   Fields to get aggregations for
     * @param int           $register Register ID to filter by
     * @param int           $schema   Schema ID to filter by
     * @param array         $filters  Additional filters to apply
     * @param string|null   $search   Optional search term
     *
     * @return array[] Array of facets with counts for each field
     *
     * @psalm-return array<array>
     */
    public function getAggregations(IQueryBuilder $builder, array $fields, int $register, int $schema, array $filters=[], ?string $search=null): array
    {
        $facets = [];

        foreach ($fields as $field) {
            // Create parameter for JSON path.
            $builder->createNamedParameter(
                value: "$.$field",
                placeHolder: ":$field"
            );

            // Build base query for aggregation.
            $builder
                ->selectAlias(
                    $builder->createFunction("json_unquote(json_extract(object, :$field))"),
                    '_id'
                )
                ->selectAlias($builder->createFunction("count(*)"), 'count')
                ->from('openregister_objects')
                ->where(
                    $builder->expr()->eq(
                        'register',
                        $builder->createNamedParameter($register, IQueryBuilder::PARAM_INT)
                    ),
                    $builder->expr()->eq(
                        'schema',
                        $builder->createNamedParameter($schema, IQueryBuilder::PARAM_INT)
                    ),
                )
                ->groupBy('_id');

            // Apply filters and search.
            $builder = $this->filterJson(builder: $builder, filters: $filters);
            $builder = $this->searchJson(builder: $builder, search: $search);

            // Execute query and store results.
            $result         = $builder->executeQuery();
            $facets[$field] = $result->fetchAll();

            // Reset builder for next field.
            $builder->resetQueryParts();
            $builder->setParameters([]);
        }//end foreach

        return $facets;

    }//end getAggregations()


}//end class
