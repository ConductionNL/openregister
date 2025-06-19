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
        $mainFields = ['register', 'schema', 'uuid', 'created', 'updated', 'owner', 'organisation', 'application'];

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

            // Handle array values (one of search)
            if (is_array($value) === true) {
                $queryBuilder->andWhere(
                    $queryBuilder->expr()->in(
                        $field,
                        $queryBuilder->createNamedParameter($value, \Doctrine\DBAL\Connection::PARAM_STR_ARRAY)
                    )
                );
            } else {
                // Handle single values
                $queryBuilder->andWhere(
                    $queryBuilder->expr()->eq($field, $queryBuilder->createNamedParameter($value))
                );
            }
        }

        return $queryBuilder;

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
                $orConditions->add(
                    $queryBuilder->expr()->eq(
                        $queryBuilder->createFunction(
                            'JSON_UNQUOTE(JSON_EXTRACT(`object`, ' . $queryBuilder->createNamedParameter($jsonPath) . '))'
                        ),
                        $queryBuilder->createNamedParameter($arrayValue)
                    )
                );
            }
            
            $queryBuilder->andWhere($orConditions);
        } else {
            // Handle single values
            $queryBuilder->andWhere(
                $queryBuilder->expr()->eq(
                    $queryBuilder->createFunction(
                        'JSON_UNQUOTE(JSON_EXTRACT(`object`, ' . $queryBuilder->createNamedParameter($jsonPath) . '))'
                    ),
                    $queryBuilder->createNamedParameter($value)
                )
            );
        }

    }//end applyJsonFieldFilter()


    /**
     * Apply full-text search on JSON object
     *
     * Performs a full-text search within the JSON object field.
     *
     * @param IQueryBuilder $queryBuilder The query builder to modify
     * @param string        $searchTerm The search term
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
        // Use JSON_SEARCH to find the search term anywhere in the JSON object
        $searchFunction = "JSON_SEARCH(`object`, 'all', " . $queryBuilder->createNamedParameter('%' . $searchTerm . '%') . ")";
        
        $queryBuilder->andWhere(
            $queryBuilder->expr()->isNotNull(
                $queryBuilder->createFunction($searchFunction)
            )
        );

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