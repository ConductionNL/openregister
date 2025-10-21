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
        try {
            $mainFields = ['register', 'schema', 'uuid', 'name', 'description', 'uri', 'version', 'folder', 'application', 'organisation', 'owner', 'size', 'schemaVersion', 'created', 'updated', 'published', 'depublished'];
            $dateFields = ['created', 'updated', 'published', 'depublished'];
            $textFields = ['name', 'description', 'uri', 'folder', 'application', 'organisation', 'owner', 'schemaVersion'];

            foreach ($metadataFilters as $field => $value) {
                // Only process fields that are actual metadata fields
                if (in_array($field, $mainFields) === false) {
                    continue;
                }

                // Use table alias to avoid ambiguous column references when JOINs are present
                $qualifiedField = 'o.'.$field;

                // Handle special null checks
                if ($value === 'IS NOT NULL') {
                    $queryBuilder->andWhere($queryBuilder->expr()->isNotNull($qualifiedField));
                    continue;
                }

                if ($value === 'IS NULL') {
                    $queryBuilder->andWhere($queryBuilder->expr()->isNull($qualifiedField));
                    continue;
                }

                // Handle complex operators for text fields
                if (in_array($field, $textFields) && is_array($value)) {
                    foreach ($value as $operator => $operatorValue) {
                        switch ($operator) {
                            case '~':
                                // Contains
                                $queryBuilder->andWhere(
                                $queryBuilder->expr()->like($qualifiedField, $queryBuilder->createNamedParameter('%'.$operatorValue.'%'))
                                );
                                break;
                            case '^':
                                // Starts with
                                $queryBuilder->andWhere(
                                $queryBuilder->expr()->like($qualifiedField, $queryBuilder->createNamedParameter($operatorValue.'%'))
                                );
                                break;
                            case '$':
                                // Ends with
                                $queryBuilder->andWhere(
                                $queryBuilder->expr()->like($qualifiedField, $queryBuilder->createNamedParameter('%'.$operatorValue))
                                );
                                break;
                            case 'ne':
                                // Not equals
                                $queryBuilder->andWhere(
                                $queryBuilder->expr()->neq($qualifiedField, $queryBuilder->createNamedParameter($operatorValue))
                                );
                                break;
                            case '===':
                                // Case sensitive equals
                                $queryBuilder->andWhere(
                                $queryBuilder->expr()->eq($qualifiedField, $queryBuilder->createNamedParameter($operatorValue))
                                );
                                break;
                            case 'exists':
                                // Field exists (not null and not empty)
                                if ($operatorValue === 'true' || $operatorValue === true) {
                                    $queryBuilder->andWhere(
                                    $queryBuilder->expr()->andX(
                                        $queryBuilder->expr()->isNotNull($qualifiedField),
                                        $queryBuilder->expr()->neq($qualifiedField, $queryBuilder->createNamedParameter(''))
                                    )
                                    );
                                } else {
                                    $queryBuilder->andWhere(
                                    $queryBuilder->expr()->orX(
                                        $queryBuilder->expr()->isNull($qualifiedField),
                                        $queryBuilder->expr()->eq($qualifiedField, $queryBuilder->createNamedParameter(''))
                                    )
                                    );
                                }
                                break;
                            case 'empty':
                                // Field is empty
                                if ($operatorValue === 'true' || $operatorValue === true) {
                                    $queryBuilder->andWhere(
                                    $queryBuilder->expr()->eq($qualifiedField, $queryBuilder->createNamedParameter(''))
                                    );
                                } else {
                                    $queryBuilder->andWhere(
                                    $queryBuilder->expr()->neq($qualifiedField, $queryBuilder->createNamedParameter(''))
                                    );
                                }
                                break;
                            case 'null':
                                // Field is null
                                if ($operatorValue === 'true' || $operatorValue === true) {
                                    $queryBuilder->andWhere($queryBuilder->expr()->isNull($qualifiedField));
                                } else {
                                    $queryBuilder->andWhere($queryBuilder->expr()->isNotNull($qualifiedField));
                                }
                                break;
                            default:
                                // For non-text operators or unsupported operators, treat as regular array (IN clause)
                                if (is_numeric($operator)) {
                                    // This is a regular array, not an operator array
                                    $queryBuilder->andWhere(
                                    $queryBuilder->expr()->in(
                                        $qualifiedField,
                                        $queryBuilder->createNamedParameter($value, \Doctrine\DBAL\Connection::PARAM_STR_ARRAY)
                                    )
                                    );
                                    break 2;
                                    // Break out of both switch and foreach
                                }
                                break;
                        }//end switch
                    }//end foreach

                    continue;
                }//end if

                // Handle complex operators for date fields
                if (in_array($field, $dateFields) && is_array($value)) {
                    foreach ($value as $operator => $operatorValue) {
                        // CRITICAL FIX: Convert PHP-friendly operator names back to SQL operators
                        // Frontend sends 'gte', 'lte', etc. because PHP's $_GET can't handle >= in array keys
                        $sqlOperator = $operator;
                        if ($operator === 'gte') {
                            $sqlOperator = '>=';
                        } else if ($operator === 'lte') {
                            $sqlOperator = '<=';
                        } else if ($operator === 'gt') {
                            $sqlOperator = '>';
                        } else if ($operator === 'lt') {
                            $sqlOperator = '<';
                        } else if ($operator === 'ne') {
                            $sqlOperator = '!=';
                        } else if ($operator === 'eq') {
                            $sqlOperator = '=';
                        }

                        // Normalize the filter value for date fields to a consistent format
                        $normalizedValue = $operatorValue;
                        if (in_array($field, ['created', 'updated', 'published', 'depublished'])) {
                            try {
                                // Convert to database format: Y-m-d H:i:s (2025-06-25 21:46:59)
                                $dateTime        = new \DateTime($operatorValue);
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
                                    $queryBuilder->expr()->gte($qualifiedField, $queryBuilder->createNamedParameter($normalizedValue))
                                    );
                                } else {
                                    $queryBuilder->andWhere(
                                    $queryBuilder->expr()->gte($qualifiedField, $queryBuilder->createNamedParameter($operatorValue))
                                    );
                                }
                                break;
                            case '<=':
                                // For date fields, ensure proper datetime comparison
                                if (in_array($field, ['created', 'updated', 'published', 'depublished'])) {
                                    $queryBuilder->andWhere(
                                    $queryBuilder->expr()->lte($qualifiedField, $queryBuilder->createNamedParameter($normalizedValue))
                                    );
                                } else {
                                    $queryBuilder->andWhere(
                                    $queryBuilder->expr()->lte($qualifiedField, $queryBuilder->createNamedParameter($operatorValue))
                                    );
                                }
                                break;
                            case '>':
                                // For date fields, ensure proper datetime comparison
                                if (in_array($field, ['created', 'updated', 'published', 'depublished'])) {
                                    $queryBuilder->andWhere(
                                    $queryBuilder->expr()->gt($qualifiedField, $queryBuilder->createNamedParameter($normalizedValue))
                                    );
                                } else {
                                    $queryBuilder->andWhere(
                                    $queryBuilder->expr()->gt($qualifiedField, $queryBuilder->createNamedParameter($operatorValue))
                                    );
                                }
                                break;
                            case '<':
                                // For date fields, ensure proper datetime comparison
                                if (in_array($field, ['created', 'updated', 'published', 'depublished'])) {
                                    $queryBuilder->andWhere(
                                    $queryBuilder->expr()->lt($qualifiedField, $queryBuilder->createNamedParameter($normalizedValue))
                                    );
                                } else {
                                    $queryBuilder->andWhere(
                                    $queryBuilder->expr()->lt($qualifiedField, $queryBuilder->createNamedParameter($operatorValue))
                                    );
                                }
                                break;
                            case '=':
                                // For date fields, ensure proper datetime comparison
                                if (in_array($field, ['created', 'updated', 'published', 'depublished'])) {
                                    $queryBuilder->andWhere(
                                    $queryBuilder->expr()->eq($qualifiedField, $queryBuilder->createNamedParameter($normalizedValue))
                                    );
                                } else {
                                    $queryBuilder->andWhere(
                                    $queryBuilder->expr()->eq($qualifiedField, $queryBuilder->createNamedParameter($operatorValue))
                                    );
                                }
                                break;
                            default:
                                // For non-date operators or unsupported operators, treat as regular array (IN clause)
                                if (is_numeric($operator)) {
                                    // This is a regular array, not an operator array
                                    $queryBuilder->andWhere(
                                    $queryBuilder->expr()->in(
                                        $qualifiedField,
                                        $queryBuilder->createNamedParameter($value, \Doctrine\DBAL\Connection::PARAM_STR_ARRAY)
                                    )
                                    );
                                    break 2;
                                    // Break out of both switch and foreach
                                }
                                break;
                        }//end switch
                    }//end foreach

                    continue;
                }//end if

                // Handle array values - support [and], [or] operators and comma-separated values
                if (is_array($value) === true) {
                    // Determine if this is an operator array ([and], [or]) or a simple value array
                    $hasOperatorKey = isset($value['and']) || isset($value['or']);
                    $isOrOperation = false;
                    $values = $value;
                    
                    if ($hasOperatorKey) {
                        // Handle explicit operator syntax: field[and]=val1,val2 or field[or]=val1,val2
                        if (isset($value['or'])) {
                            $isOrOperation = true;
                            $values = is_string($value['or']) ? array_map('trim', explode(',', $value['or'])) : (array) $value['or'];
                        } else if (isset($value['and'])) {
                            $isOrOperation = false;
                            $values = is_string($value['and']) ? array_map('trim', explode(',', $value['and'])) : (array) $value['and'];
                        }
                    } else {
                        // For simple arrays (field[]=val1&field[]=val2), default to AND for consistency
                        // This means: register[]=1&register[]=2 will return objects matching BOTH (typically zero results)
                        $isOrOperation = false;
                    }
                    
                    if (in_array($field, $textFields)) {
                        // Case-insensitive array search for text fields
                        if ($isOrOperation) {
                            // OR logic: match any value
                            $orConditions = $queryBuilder->expr()->orX();
                            foreach ($values as $arrayValue) {
                                $orConditions->add(
                                    $queryBuilder->expr()->eq(
                                        $queryBuilder->createFunction('LOWER('.$qualifiedField.')'),
                                        $queryBuilder->createNamedParameter(strtolower($arrayValue))
                                    )
                                );
                            }
                            $queryBuilder->andWhere($orConditions);
                        } else {
                            // AND logic: match all values (for single-value fields, this means exact match to one of them)
                            // For metadata fields like register/schema, this will typically return zero results if multiple values
                            foreach ($values as $arrayValue) {
                                $queryBuilder->andWhere(
                                    $queryBuilder->expr()->eq(
                                        $queryBuilder->createFunction('LOWER('.$qualifiedField.')'),
                                        $queryBuilder->createNamedParameter(strtolower($arrayValue))
                                    )
                                );
                            }
                        }
                    } else {
                        // Numeric/other fields
                        if ($isOrOperation) {
                            // OR logic: match any value (IN clause)
                            $queryBuilder->andWhere(
                                $queryBuilder->expr()->in(
                                    $qualifiedField,
                                    $queryBuilder->createNamedParameter($values, \Doctrine\DBAL\Connection::PARAM_STR_ARRAY)
                                )
                            );
                        } else {
                            // AND logic: match all values (multiple conditions)
                            // For single-value fields like register/schema, this creates impossible conditions
                            foreach ($values as $arrayValue) {
                                $queryBuilder->andWhere(
                                    $queryBuilder->expr()->eq($qualifiedField, $queryBuilder->createNamedParameter($arrayValue))
                                );
                            }
                        }
                    }//end if
                } else {
                    // Handle single values - use case-insensitive comparison for text fields
                    if (in_array($field, $textFields)) {
                        $queryBuilder->andWhere(
                        $queryBuilder->expr()->eq(
                            $queryBuilder->createFunction('LOWER('.$qualifiedField.')'),
                            $queryBuilder->createNamedParameter(strtolower($value))
                        )
                        );
                    } else {
                        $queryBuilder->andWhere(
                        $queryBuilder->expr()->eq($qualifiedField, $queryBuilder->createNamedParameter($value))
                        );
                    }
                }//end if
            }//end foreach

            return $queryBuilder;
        } catch (\Exception $e) {
            // Re-throw the exception to maintain original behavior
            throw $e;
        }//end try

    }//end applyMetadataFilters()


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
     */
    private function applyJsonFieldFilter(IQueryBuilder $queryBuilder, string $field, mixed $value): void
    {
        // Build the JSON path - convert dot notation to JSON path
        $jsonPath = '$.'.str_replace('.', '.', $field);

        // Handle special null checks
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

        // Handle array values - support [and], [or] operators and comma-separated values
        if (is_array($value) === true) {
            // Determine if this is an operator array ([and], [or]) or a simple value array
            $hasOperatorKey = isset($value['and']) || isset($value['or']);
            $isOrOperation = false;
            $values = $value;
            
            if ($hasOperatorKey) {
                // Handle explicit operator syntax: field[and]=val1,val2 or field[or]=val1,val2
                if (isset($value['or'])) {
                    $isOrOperation = true;
                    $values = is_string($value['or']) ? array_map('trim', explode(',', $value['or'])) : (array) $value['or'];
                } else if (isset($value['and'])) {
                    $isOrOperation = false;
                    $values = is_string($value['and']) ? array_map('trim', explode(',', $value['and'])) : (array) $value['and'];
                }
            } else {
                // For simple arrays (field[]=val1&field[]=val2), default to AND for consistency
                // This means: availableColours[]=red&availableColours[]=blue requires BOTH colors present
                $isOrOperation = false;
            }

            if ($isOrOperation) {
                // OR logic: match any value in the array
                $orConditions = $queryBuilder->expr()->orX();

                foreach ($values as $arrayValue) {
                    // Use case-insensitive comparison for string values
                    if (is_string($arrayValue)) {
                        // Check for exact match (single value)
                        $orConditions->add(
                            $queryBuilder->expr()->eq(
                                $queryBuilder->createFunction(
                                    'LOWER(JSON_UNQUOTE(JSON_EXTRACT(`object`, '.$queryBuilder->createNamedParameter($jsonPath).')))'
                                ),
                                $queryBuilder->createNamedParameter(strtolower($arrayValue))
                            )
                        );

                        // Check if the value exists within an array using JSON_CONTAINS (case-insensitive)
                        $orConditions->add(
                            $queryBuilder->expr()->eq(
                                $queryBuilder->createFunction("JSON_CONTAINS(LOWER(JSON_EXTRACT(`object`, ".$queryBuilder->createNamedParameter($jsonPath).")), ".$queryBuilder->createNamedParameter(json_encode(strtolower($arrayValue))).")"),
                                $queryBuilder->createNamedParameter(1)
                            )
                        );
                    } else {
                        // Exact match for non-string values (numbers, booleans, etc.)
                        $orConditions->add(
                            $queryBuilder->expr()->eq(
                                $queryBuilder->createFunction(
                                    'JSON_UNQUOTE(JSON_EXTRACT(`object`, '.$queryBuilder->createNamedParameter($jsonPath).'))'
                                ),
                                $queryBuilder->createNamedParameter($arrayValue)
                            )
                        );

                        // Check if the value exists within an array using JSON_CONTAINS
                        $orConditions->add(
                            $queryBuilder->expr()->eq(
                                $queryBuilder->createFunction("JSON_CONTAINS(JSON_EXTRACT(`object`, ".$queryBuilder->createNamedParameter($jsonPath)."), ".$queryBuilder->createNamedParameter(json_encode($arrayValue)).")"),
                                $queryBuilder->createNamedParameter(1)
                            )
                        );
                    }//end if
                }//end foreach

                $queryBuilder->andWhere($orConditions);
            } else {
                // AND logic: all values must be present
                // For array properties (e.g., availableColours), check that ALL values exist
                foreach ($values as $arrayValue) {
                    if (is_string($arrayValue)) {
                        // Create an OR condition for this specific value (either as single value or in array)
                        $valueCondition = $queryBuilder->expr()->orX();
                        
                        // Check for exact match (when property is a single value, not an array)
                        $valueCondition->add(
                            $queryBuilder->expr()->eq(
                                $queryBuilder->createFunction(
                                    'LOWER(JSON_UNQUOTE(JSON_EXTRACT(`object`, '.$queryBuilder->createNamedParameter($jsonPath).')))'
                                ),
                                $queryBuilder->createNamedParameter(strtolower($arrayValue))
                            )
                        );

                        // Check if the value exists within an array using JSON_CONTAINS (case-insensitive)
                        $valueCondition->add(
                            $queryBuilder->expr()->eq(
                                $queryBuilder->createFunction("JSON_CONTAINS(LOWER(JSON_EXTRACT(`object`, ".$queryBuilder->createNamedParameter($jsonPath).")), ".$queryBuilder->createNamedParameter(json_encode(strtolower($arrayValue))).")"),
                                $queryBuilder->createNamedParameter(1)
                            )
                        );
                        
                        // Each value must match (AND between values)
                        $queryBuilder->andWhere($valueCondition);
                    } else {
                        // Create an OR condition for this specific value
                        $valueCondition = $queryBuilder->expr()->orX();
                        
                        // Check for exact match (single value)
                        $valueCondition->add(
                            $queryBuilder->expr()->eq(
                                $queryBuilder->createFunction(
                                    'JSON_UNQUOTE(JSON_EXTRACT(`object`, '.$queryBuilder->createNamedParameter($jsonPath).'))'
                                ),
                                $queryBuilder->createNamedParameter($arrayValue)
                            )
                        );

                        // Check if the value exists within an array using JSON_CONTAINS
                        $valueCondition->add(
                            $queryBuilder->expr()->eq(
                                $queryBuilder->createFunction("JSON_CONTAINS(JSON_EXTRACT(`object`, ".$queryBuilder->createNamedParameter($jsonPath)."), ".$queryBuilder->createNamedParameter(json_encode($arrayValue)).")"),
                                $queryBuilder->createNamedParameter(1)
                            )
                        );
                        
                        // Each value must match (AND between values)
                        $queryBuilder->andWhere($valueCondition);
                    }//end if
                }//end foreach
            }//end if
        } else {
            // Handle single values - use case-insensitive comparison for strings
            if (is_string($value)) {
                $singleValueConditions = $queryBuilder->expr()->orX();

                // Check for exact match (single value)
                $singleValueConditions->add(
                    $queryBuilder->expr()->eq(
                        $queryBuilder->createFunction(
                            'LOWER(JSON_UNQUOTE(JSON_EXTRACT(`object`, '.$queryBuilder->createNamedParameter($jsonPath).')))'
                        ),
                        $queryBuilder->createNamedParameter(strtolower($value))
                    )
                );

                // Check if the value exists within an array using JSON_CONTAINS (case-insensitive)
                $singleValueConditions->add(
                    $queryBuilder->expr()->eq(
                        $queryBuilder->createFunction("JSON_CONTAINS(LOWER(JSON_EXTRACT(`object`, ".$queryBuilder->createNamedParameter($jsonPath).")), ".$queryBuilder->createNamedParameter(json_encode(strtolower($value))).")"),
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
                            'JSON_UNQUOTE(JSON_EXTRACT(`object`, '.$queryBuilder->createNamedParameter($jsonPath).'))'
                        ),
                        $queryBuilder->createNamedParameter($value)
                    )
                );

                // Check if the value exists within an array using JSON_CONTAINS
                $singleValueConditions->add(
                    $queryBuilder->expr()->eq(
                        $queryBuilder->createFunction("JSON_CONTAINS(JSON_EXTRACT(`object`, ".$queryBuilder->createNamedParameter($jsonPath)."), ".$queryBuilder->createNamedParameter(json_encode($value)).")"),
                        $queryBuilder->createNamedParameter(1)
                    )
                );

                $queryBuilder->andWhere($singleValueConditions);
            }//end if
        }//end if

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

            // Create a parameter for the search term to avoid SQL injection
            $searchParam = $queryBuilder->createNamedParameter('%'.$cleanTerm.'%');

            // Create OR conditions for each searchable field
            // PERFORMANCE OPTIMIZATION: Search indexed metadata columns first for best performance
            $termConditions = $queryBuilder->expr()->orX();

            // PRIORITY 1: Search in indexed metadata fields (FASTEST - uses database indexes)
            // These columns have indexes and provide the best search performance
            $indexedFields = [
                'o.name' => 'name',
                'o.summary' => 'summary', 
                'o.description' => 'description'
            ];
            
            foreach ($indexedFields as $columnName => $fieldName) {
                $termConditions->add(
                    $queryBuilder->expr()->like(
                        $queryBuilder->createFunction('LOWER('.$columnName.')'),
                        $queryBuilder->createNamedParameter('%'.$cleanTerm.'%')
                    )
                );
            }

            // PRIORITY 2: Search in other metadata fields (MODERATE - no indexes but direct column access)
            $otherMetadataFields = ['o.image'];
            foreach ($otherMetadataFields as $columnName) {
                $termConditions->add(
                    $queryBuilder->expr()->like(
                        $queryBuilder->createFunction('LOWER('.$columnName.')'),
                        $queryBuilder->createNamedParameter('%'.$cleanTerm.'%')
                    )
                );
            }

            // **PERFORMANCE OPTIMIZATION**: JSON search on object field DISABLED for performance
            // JSON_SEARCH on large object fields is extremely expensive (can add 500ms+ per query)
            // _search now only covers: name, description, summary for sub-500ms performance
            // 
            // If comprehensive JSON search is needed, use specific object field filters instead:
            // e.g., ?fieldName=searchTerm rather than ?_search=searchTerm
            //
            // Original code (DISABLED for performance):
            // $jsonSearchFunction = "JSON_SEARCH(LOWER(`object`), 'all', ".$searchParam.")";
            // $termConditions->add(
            //     $queryBuilder->expr()->isNotNull(
            //         $queryBuilder->createFunction($jsonSearchFunction)
            //     )
            // );

            // Add the term conditions to the main OR group
            $orConditions->add($termConditions);
        }//end foreach

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
            // Validate direction
            $direction = strtoupper($direction);
            if (in_array($direction, ['ASC', 'DESC']) === false) {
                $direction = 'ASC';
            }

            // Build the JSON path
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
