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
 * Service class for handling MySQL JSON operations
 *
 * This class provides methods for querying and filtering JSON data stored in MySQL,
 * including complex filtering, searching, ordering and aggregation functionality.
 */
class MySQLJsonService implements IDatabaseJsonService
{


    /**
     * Add ordering to a query based on JSON fields.
     *
     * @param IQueryBuilder $builder The query builder instance
     * @param array         $order   Array of field => direction pairs for ordering
     *
     * @return IQueryBuilder The modified query builder
     */
    public function orderJson(IQueryBuilder $builder, array $order=[]): IQueryBuilder
    {
        // Loop through each ordering field and direction.
        foreach ($order as $item => $direction) {
            // Create parameters for the JSON path and sort direction.
            $builder->createNamedParameter(value: "$.$item", placeHolder: ":path$item");
            $builder->createNamedParameter(value: $direction, placeHolder: ":direction$item");

            // Add ORDER BY clause using JSON_UNQUOTE and JSON_EXTRACT.
            $builder->orderBy($builder->createFunction("json_unquote(json_extract(object, :path$item))"), $direction);
        }

        return $builder;

    }//end orderJson()


    /**
     * Add full-text search functionality for JSON fields.
     *
     * @param IQueryBuilder $builder The query builder instance
     * @param string|null   $search  The search term to look for
     *
     * @return IQueryBuilder The modified query builder
     */
    public function searchJson(IQueryBuilder $builder, ?string $search=null): IQueryBuilder
    {
        if ($search !== null) {
            // Create parameter for the search term with wildcards.
            $builder->createNamedParameter(value: "%$search%", placeHolder: ':search');
            // Add WHERE clause to search case-insensitive across all JSON fields.
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
            $parsedFilter = $this->parseFilter($filter);

            // Create parameter for JSON path.
            $builder->createNamedParameter(
                value: "$.$parsedFilter",
                placeHolder: ":path$filter"
            );

            if (is_array($value) === true && array_is_list($value) === false) {
                // Handle complex filters (after/before).
                $builder = $this->jsonFilterArray(builder: $builder, filter: $filter, values: $value);
                continue;
            } else if (is_array($value) === true) {
                // Handle array of values with IN clause and contains check.
                $builder->createNamedParameter(
                    value: $value,
                    type: IQueryBuilder::PARAM_STR_ARRAY,
                    placeHolder: ":value$filter"
                );
                $builder->andWhere(
                    "(json_unquote(json_extract(object, :path$filter)) IN (:value$filter))".$this->getMultipleContains($value, $filter, $builder)
                );
                continue;
            }

			// Handle simple equality filter.
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
     * @return array Array of facets with counts for each field
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
            $builder = $this->filterJson($builder, $filters);
            $builder = $this->searchJson($builder, $search);

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
