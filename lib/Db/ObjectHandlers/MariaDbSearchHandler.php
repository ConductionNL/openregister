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

use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * MariaDB Search Handler
 *
 * Handles database-specific JSON search operations for MariaDB/MySQL databases.
 * This class encapsulates all MariaDB-specific logic for searching within JSON fields.
 *
 * @package OCA\OpenRegister\Db\ObjectHandlers
 */
class MariaDbSearchHandler
{

    /**
     * Apply metadata filters to the query builder
     *
     * Handles filtering on metadata fields (those in @self) like register, schema, etc.
     *
     * @param IQueryBuilder $queryBuilder The query builder to modify
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
        try {
            $mainFields = ['register', 'schema', 'uuid', 'name', 'description', 'uri', 'version', 'folder', 'application', 'organisation', 'owner', 'size', 'schemaVersion', 'created', 'updated', 'published', 'depublished'];
            $dateFields = ['created', 'updated', 'published', 'depublished'];
            $textFields = ['name', 'description', 'uri', 'folder', 'application', 'organisation', 'owner', 'schemaVersion'];

        foreach ($metadataFilters as $field => $value) {
            // Only process fields that are actual metadata fields
            if (in_array($field, $mainFields) === false) {
                continue;
            }

            // Handle special null checks
            if ($value === 'IS NOT NULL') {
                $queryBuilder->andWhere($queryBuilder->expr()->isNotNull($field));
                continue;
            }

            if ($value === 'IS NULL') {
                $queryBuilder->andWhere($queryBuilder->expr()->isNull($field));
                continue;
            }

            // Handle complex operators for text fields
            if (in_array($field, $textFields) && is_array($value)) {
                foreach ($value as $operator => $operatorValue) {
                    switch ($operator) {
                        case '~': // Contains
                            $queryBuilder->andWhere(
                                $queryBuilder->expr()->like($field, $queryBuilder->createNamedParameter('%' . $operatorValue . '%'))
                            );
                            break;
                        case '^': // Starts with
                            $queryBuilder->andWhere(
                                $queryBuilder->expr()->like($field, $queryBuilder->createNamedParameter($operatorValue . '%'))
                            );
                            break;
                        case '$': // Ends with
                            $queryBuilder->andWhere(
                                $queryBuilder->expr()->like($field, $queryBuilder->createNamedParameter('%' . $operatorValue))
                            );
                            break;
                        case 'ne': // Not equals
                            $queryBuilder->andWhere(
                                $queryBuilder->expr()->neq($field, $queryBuilder->createNamedParameter($operatorValue))
                            );
                            break;
                        case '===': // Case sensitive equals
                            $queryBuilder->andWhere(
                                $queryBuilder->expr()->eq($field, $queryBuilder->createNamedParameter($operatorValue))
                            );
                            break;
                        case 'exists': // Field exists (not null and not empty)
                            if ($operatorValue === 'true' || $operatorValue === true) {
                                $queryBuilder->andWhere(
                                    $queryBuilder->expr()->andX(
                                        $queryBuilder->expr()->isNotNull($field),
                                        $queryBuilder->expr()->neq($field, $queryBuilder->createNamedParameter(''))
                                    )
                                );
                            } else {
                                $queryBuilder->andWhere(
                                    $queryBuilder->expr()->orX(
                                        $queryBuilder->expr()->isNull($field),
                                        $queryBuilder->expr()->eq($field, $queryBuilder->createNamedParameter(''))
                                    )
                                );
                            }
                            break;
                        case 'empty': // Field is empty
                            if ($operatorValue === 'true' || $operatorValue === true) {
                                $queryBuilder->andWhere(
                                    $queryBuilder->expr()->eq($field, $queryBuilder->createNamedParameter(''))
                                );
                            } else {
                                $queryBuilder->andWhere(
                                    $queryBuilder->expr()->neq($field, $queryBuilder->createNamedParameter(''))
                                );
                            }
                            break;
                        case 'null': // Field is null
                            if ($operatorValue === 'true' || $operatorValue === true) {
                                $queryBuilder->andWhere($queryBuilder->expr()->isNull($field));
                            } else {
                                $queryBuilder->andWhere($queryBuilder->expr()->isNotNull($field));
                            }
                            break;
                        default:
                            // For non-text operators or unsupported operators, treat as regular array (IN clause)
                            if (is_numeric($operator)) {
                                // This is a regular array, not an operator array
                                $queryBuilder->andWhere(
                                    $queryBuilder->expr()->in(
                                        $field,
                                        $queryBuilder->createNamedParameter($value, \Doctrine\DBAL\Connection::PARAM_STR_ARRAY)
                                    )
                                );
                                break 2; // Break out of both switch and foreach
                            }
                            break;
                    }
                }
                continue;
            }

            // Handle complex operators for date fields
            if (in_array($field, $dateFields) && is_array($value)) {
                foreach ($value as $operator => $operatorValue) {
                    // CRITICAL FIX: Convert PHP-friendly operator names back to SQL operators
                    // Frontend sends 'gte', 'lte', etc. because PHP's $_GET can't handle >= in array keys
                    $sqlOperator = $operator;
                    if ($operator === 'gte') $sqlOperator = '>=';
                    else if ($operator === 'lte') $sqlOperator = '<=';
                    else if ($operator === 'gt') $sqlOperator = '>';
                    else if ($operator === 'lt') $sqlOperator = '<';
                    else if ($operator === 'ne') $sqlOperator = '!=';
                    else if ($operator === 'eq') $sqlOperator = '=';
                    
                    // Normalize the filter value for date fields to a consistent format
                    $normalizedValue = $operatorValue;
                    if (in_array($field, ['created', 'updated', 'published', 'depublished'])) {
                        try {
                            // Convert to database format: Y-m-d H:i:s (2025-06-25 21:46:59)
                            $dateTime = new \DateTime($operatorValue);
                            $normalizedValue = $dateTime->format('Y-m-d H:i:s');
                        } catch (\Exception $e) {
                            // Fall back to original value if date parsing fails
                            $normalizedValue = $operatorValue;
                        }
                    }

                    switch ($sqlOperator) {
                        case '>=':
                            // For date fields, ensure proper datetime comparison
                            if (in_array($field, ['created', 'updated', 'published', 'depublished'])) {
                                // Use simple string comparison since both sides are in Y-m-d H:i:s format
                                $queryBuilder->andWhere(
                                    $queryBuilder->expr()->gte($field, $queryBuilder->createNamedParameter($normalizedValue))
                                );
                            } else {
                                $queryBuilder->andWhere(
                                    $queryBuilder->expr()->gte($field, $queryBuilder->createNamedParameter($operatorValue))
                                );
                            }
                            break;
                        case '<=':
                            // For date fields, ensure proper datetime comparison
                            if (in_array($field, ['created', 'updated', 'published', 'depublished'])) {
                                $queryBuilder->andWhere(
                                    $queryBuilder->expr()->lte($field, $queryBuilder->createNamedParameter($normalizedValue))
                                );
                            } else {
                                $queryBuilder->andWhere(
                                    $queryBuilder->expr()->lte($field, $queryBuilder->createNamedParameter($operatorValue))
                                );
                            }
                            break;
                        case '>':
                            // For date fields, ensure proper datetime comparison
                            if (in_array($field, ['created', 'updated', 'published', 'depublished'])) {
                                $queryBuilder->andWhere(
                                    $queryBuilder->expr()->gt($field, $queryBuilder->createNamedParameter($normalizedValue))
                                );
                            } else {
                                $queryBuilder->andWhere(
                                    $queryBuilder->expr()->gt($field, $queryBuilder->createNamedParameter($operatorValue))
                                );
                            }
                            break;
                        case '<':
                            // For date fields, ensure proper datetime comparison
                            if (in_array($field, ['created', 'updated', 'published', 'depublished'])) {
                                $queryBuilder->andWhere(
                                    $queryBuilder->expr()->lt($field, $queryBuilder->createNamedParameter($normalizedValue))
                                );
                            } else {
                                $queryBuilder->andWhere(
                                    $queryBuilder->expr()->lt($field, $queryBuilder->createNamedParameter($operatorValue))
                                );
                            }
                            break;
                        case '=':
                            // For date fields, ensure proper datetime comparison
                            if (in_array($field, ['created', 'updated', 'published', 'depublished'])) {
                                $queryBuilder->andWhere(
                                    $queryBuilder->expr()->eq($field, $queryBuilder->createNamedParameter($normalizedValue))
                                );
                            } else {
                                $queryBuilder->andWhere(
                                    $queryBuilder->expr()->eq($field, $queryBuilder->createNamedParameter($operatorValue))
                                );
                            }
                            break;
                        default:
                            // For non-date operators or unsupported operators, treat as regular array (IN clause)
                            if (is_numeric($operator)) {
                                // This is a regular array, not an operator array
                                $queryBuilder->andWhere(
                                    $queryBuilder->expr()->in(
                                        $field,
                                        $queryBuilder->createNamedParameter($value, \Doctrine\DBAL\Connection::PARAM_STR_ARRAY)
                                    )
                                );
                                break 2; // Break out of both switch and foreach
                            }
                            break;
                    }
                }
                continue;
            }

            // Handle array values (one of search) for non-date fields or simple arrays
            if (is_array($value) === true) {
                if (in_array($field, $textFields)) {
                    // Case-insensitive array search for text fields
                    $orConditions = $queryBuilder->expr()->orX();
                    foreach ($value as $arrayValue) {
                        $orConditions->add(
                            $queryBuilder->expr()->eq(
                                $queryBuilder->createFunction('LOWER(' . $field . ')'),
                                $queryBuilder->createNamedParameter(strtolower($arrayValue))
                            )
                        );
                    }
                    $queryBuilder->andWhere($orConditions);
                } else {
                    $queryBuilder->andWhere(
                        $queryBuilder->expr()->in(
                            $field,
                            $queryBuilder->createNamedParameter($value, \Doctrine\DBAL\Connection::PARAM_STR_ARRAY)
                        )
                    );
                }
            } else {
                // Handle single values - use case-insensitive comparison for text fields
                if (in_array($field, $textFields)) {
                    $queryBuilder->andWhere(
                        $queryBuilder->expr()->eq(
                            $queryBuilder->createFunction('LOWER(' . $field . ')'),
                            $queryBuilder->createNamedParameter(strtolower($value))
                        )
                    );
                } else {
                    $queryBuilder->andWhere(
                        $queryBuilder->expr()->eq($field, $queryBuilder->createNamedParameter($value))
                    );
                }
            }
        }

            return $queryBuilder;

        } catch (\Exception $e) {
            // Re-throw the exception to maintain original behavior
            throw $e;
        }

    }//end applyMetadataFilters()


    /**
     * Apply JSON object filters to the query builder
     *
     * Handles filtering on JSON object fields using MariaDB JSON functions.
     *
     * @param IQueryBuilder $queryBuilder The query builder to modify
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
            $this->applyJsonFieldFilter($queryBuilder, $field, $value);
        }

        return $queryBuilder;

    }//end applyObjectFilters()


    /**
     * Apply a filter on a specific JSON field
     *
     * Applies case-insensitive filtering for string values and exact matching for other types.
     *
     * @param IQueryBuilder $queryBuilder The query builder to modify
     * @param string        $field The JSON field path (e.g., 'name' or 'address.city')
     * @param mixed         $value The value to filter by
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
     */
    private function applyJsonFieldFilter(IQueryBuilder $queryBuilder, string $field, mixed $value): void
    {
        // Build the JSON path - convert dot notation to JSON path
        $jsonPath = '$.' . str_replace('.', '.', $field);

        // Handle special null checks
        if ($value === 'IS NOT NULL') {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->isNotNull(
                    $queryBuilder->createFunction(
                        'JSON_EXTRACT(`object`, ' . $queryBuilder->createNamedParameter($jsonPath) . ')'
                    )
                )
            );
            return;
        }

        if ($value === 'IS NULL') {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->isNull(
                    $queryBuilder->createFunction(
                        'JSON_EXTRACT(`object`, ' . $queryBuilder->createNamedParameter($jsonPath) . ')'
                    )
                )
            );
            return;
        }

        // Handle array values (one of search)
        if (is_array($value) === true) {
            $orConditions = $queryBuilder->expr()->orX();
            
            foreach ($value as $arrayValue) {
                // Use case-insensitive comparison for string values
                if (is_string($arrayValue)) {
                    // Check for exact match (single value)
                    $orConditions->add(
                        $queryBuilder->expr()->eq(
                            $queryBuilder->createFunction(
                                'LOWER(JSON_UNQUOTE(JSON_EXTRACT(`object`, ' . $queryBuilder->createNamedParameter($jsonPath) . ')))'
                            ),
                            $queryBuilder->createNamedParameter(strtolower($arrayValue))
                        )
                    );
                    
                    // Check if the value exists within an array using JSON_CONTAINS (case-insensitive)
                    $orConditions->add(
                        $queryBuilder->expr()->eq(
                            $queryBuilder->createFunction("JSON_CONTAINS(LOWER(JSON_EXTRACT(`object`, " . $queryBuilder->createNamedParameter($jsonPath) . ")), " . $queryBuilder->createNamedParameter(json_encode(strtolower($arrayValue))) . ")"),
                            $queryBuilder->createNamedParameter(1)
                        )
                    );
                } else {
                    // Exact match for non-string values (numbers, booleans, etc.)
                    $orConditions->add(
                        $queryBuilder->expr()->eq(
                            $queryBuilder->createFunction(
                                'JSON_UNQUOTE(JSON_EXTRACT(`object`, ' . $queryBuilder->createNamedParameter($jsonPath) . '))'
                            ),
                            $queryBuilder->createNamedParameter($arrayValue)
                        )
                    );
                    
                    // Check if the value exists within an array using JSON_CONTAINS
                    $orConditions->add(
                        $queryBuilder->expr()->eq(
                            $queryBuilder->createFunction("JSON_CONTAINS(JSON_EXTRACT(`object`, " . $queryBuilder->createNamedParameter($jsonPath) . "), " . $queryBuilder->createNamedParameter(json_encode($arrayValue)) . ")"),
                            $queryBuilder->createNamedParameter(1)
                        )
                    );
                }
            }
            
            $queryBuilder->andWhere($orConditions);
        } else {
            // Handle single values - use case-insensitive comparison for strings
            if (is_string($value)) {
                $singleValueConditions = $queryBuilder->expr()->orX();
                
                // Check for exact match (single value)
                $singleValueConditions->add(
                    $queryBuilder->expr()->eq(
                        $queryBuilder->createFunction(
                            'LOWER(JSON_UNQUOTE(JSON_EXTRACT(`object`, ' . $queryBuilder->createNamedParameter($jsonPath) . ')))'
                        ),
                        $queryBuilder->createNamedParameter(strtolower($value))
                    )
                );
                
                // Check if the value exists within an array using JSON_CONTAINS (case-insensitive)
                $singleValueConditions->add(
                    $queryBuilder->expr()->eq(
                        $queryBuilder->createFunction("JSON_CONTAINS(LOWER(JSON_EXTRACT(`object`, " . $queryBuilder->createNamedParameter($jsonPath) . ")), " . $queryBuilder->createNamedParameter(json_encode(strtolower($value))) . ")"),
                        $queryBuilder->createNamedParameter(1)
                    )
                );
                
                $queryBuilder->andWhere($singleValueConditions);
            } else {
                // Exact match for non-string values (numbers, booleans, etc.)
                $singleValueConditions = $queryBuilder->expr()->orX();
                
                // Check for exact match (single value)
                $singleValueConditions->add(
                    $queryBuilder->expr()->eq(
                        $queryBuilder->createFunction(
                            'JSON_UNQUOTE(JSON_EXTRACT(`object`, ' . $queryBuilder->createNamedParameter($jsonPath) . '))'
                        ),
                        $queryBuilder->createNamedParameter($value)
                    )
                );
                
                // Check if the value exists within an array using JSON_CONTAINS
                $singleValueConditions->add(
                    $queryBuilder->expr()->eq(
                        $queryBuilder->createFunction("JSON_CONTAINS(JSON_EXTRACT(`object`, " . $queryBuilder->createNamedParameter($jsonPath) . "), " . $queryBuilder->createNamedParameter(json_encode($value)) . ")"),
                        $queryBuilder->createNamedParameter(1)
                    )
                );
                
                $queryBuilder->andWhere($singleValueConditions);
            }
        }

    }//end applyJsonFieldFilter()


    /**
     * Apply full-text search on JSON object
     *
     * Performs a case-insensitive full-text search within the JSON object field.
     * Supports multiple search terms separated by ' OR ' for OR logic.
     *
     * @param IQueryBuilder $queryBuilder The query builder to modify
     * @param string        $searchTerm The search term (can contain multiple terms separated by ' OR ')
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
        // Split search terms by ' OR ' to handle multiple search words
        $searchTerms = array_filter(
            array_map('trim', explode(' OR ', $searchTerm)),
            function ($term) {
                return empty($term) === false;
            }
        );

        // If no valid search terms, return the query builder unchanged
        if (empty($searchTerms) === true) {
            return $queryBuilder;
        }

        // Create OR conditions for each search term
        $orConditions = $queryBuilder->expr()->orX();

        foreach ($searchTerms as $term) {
            // Clean the search term - remove wildcards and convert to lowercase
            $cleanTerm = strtolower(trim($term));
            $cleanTerm = str_replace(['*', '%'], '', $cleanTerm);

            // Skip empty terms after cleaning
            if (empty($cleanTerm) === true) {
                continue;
            }

            // Use case-insensitive JSON_SEARCH with partial matching
            // This ensures the search is case-insensitive and supports partial matches
            $searchFunction = "JSON_SEARCH(LOWER(`object`), 'all', " . $queryBuilder->createNamedParameter('%' . $cleanTerm . '%') . ")";
            
            $orConditions->add(
                $queryBuilder->expr()->isNotNull(
                    $queryBuilder->createFunction($searchFunction)
                )
            );
        }

        // Add the OR conditions to the query if we have any valid terms
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
     * @param array         $sortFields Array of field => direction pairs
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
            // Validate direction
            $direction = strtoupper($direction);
            if (in_array($direction, ['ASC', 'DESC']) === false) {
                $direction = 'ASC';
            }

            // Build the JSON path
            $jsonPath = '$.' . str_replace('.', '.', $field);
            
            $queryBuilder->addOrderBy(
                $queryBuilder->createFunction(
                    'JSON_UNQUOTE(JSON_EXTRACT(`object`, ' . $queryBuilder->createNamedParameter($jsonPath) . '))'
                ),
                $direction
            );
        }

        return $queryBuilder;

    }//end applySorting()

}//end class 