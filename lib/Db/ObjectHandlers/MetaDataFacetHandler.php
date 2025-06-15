<?php

/**
 * OpenRegister MetaData Facet Handler
 *
 * This file contains the handler for managing metadata facets (table columns)
 * in the OpenRegister application.
 *
 * @category Handler
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
use OCP\IDBConnection;

/**
 * Handler for metadata facets (ObjectEntity table columns)
 *
 * This handler provides faceting capabilities for metadata fields like
 * register, schema, owner, organisation, created, updated, etc.
 */
class MetaDataFacetHandler
{

    /**
     * Constructor for the MetaDataFacetHandler
     *
     * @param IDBConnection $db The database connection
     */
    public function __construct(
        private readonly IDBConnection $db
    ) {
    }//end __construct()


    /**
     * Get terms facet for a metadata field
     *
     * Returns unique values and their counts for categorical metadata fields.
     *
     * @param string $field     The metadata field name (register, schema, owner, etc.)
     * @param array  $baseQuery Base query filters to apply
     *
     * @phpstan-param string $field
     * @phpstan-param array<string, mixed> $baseQuery
     *
     * @psalm-param string $field
     * @psalm-param array<string, mixed> $baseQuery
     *
     * @throws \OCP\DB\Exception If a database error occurs
     *
     * @return array Terms facet data with buckets containing key, results, and label
     */
    public function getTermsFacet(string $field, array $baseQuery = []): array
    {
        $queryBuilder = $this->db->getQueryBuilder();
        
        // Build aggregation query
        $queryBuilder->select($field, $queryBuilder->createFunction('COUNT(*) as doc_count'))
            ->from('openregister_objects')
            ->where($queryBuilder->expr()->isNotNull($field))
            ->groupBy($field)
            ->orderBy('doc_count', 'DESC'); // Note: Still using doc_count in ORDER BY as it's the SQL alias

        // Apply base filters (this would be implemented to apply the base query filters)
        $this->applyBaseFilters($queryBuilder, $baseQuery);

        $result = $queryBuilder->executeQuery();
        $buckets = [];

        while ($row = $result->fetch()) {
            $key = $row[$field];
            $label = $this->getFieldLabel($field, $key);
            
            $buckets[] = [
                'key' => $key,
                'results' => (int) $row['doc_count'],
                'label' => $label
            ];
        }

        return [
            'type' => 'terms',
            'buckets' => $buckets
        ];

    }//end getTermsFacet()


    /**
     * Get date histogram facet for a metadata field
     *
     * Returns time-based buckets with counts for date metadata fields.
     *
     * @param string $field     The metadata field name (created, updated, etc.)
     * @param string $interval  The histogram interval (day, week, month, year)
     * @param array  $baseQuery Base query filters to apply
     *
     * @phpstan-param string $field
     * @phpstan-param string $interval
     * @phpstan-param array<string, mixed> $baseQuery
     *
     * @psalm-param string $field
     * @psalm-param string $interval
     * @psalm-param array<string, mixed> $baseQuery
     *
     * @throws \OCP\DB\Exception If a database error occurs
     *
     * @return array Date histogram facet data
     */
    public function getDateHistogramFacet(string $field, string $interval, array $baseQuery = []): array
    {
        $queryBuilder = $this->db->getQueryBuilder();
        
        // Build date histogram query based on interval
        $dateFormat = $this->getDateFormatForInterval($interval);
        
        $queryBuilder->selectAlias(
                $queryBuilder->createFunction("DATE_FORMAT($field, '$dateFormat')"),
                'date_key'
            )
            ->selectAlias($queryBuilder->createFunction('COUNT(*)'), 'doc_count')
            ->from('openregister_objects')
            ->where($queryBuilder->expr()->isNotNull($field))
            ->groupBy('date_key')
            ->orderBy('date_key', 'ASC');

        // Apply base filters
        $this->applyBaseFilters($queryBuilder, $baseQuery);

        $result = $queryBuilder->executeQuery();
        $buckets = [];

        while ($row = $result->fetch()) {
            $buckets[] = [
                'key' => $row['date_key'],
                'results' => (int) $row['doc_count']
            ];
        }

        return [
            'type' => 'date_histogram',
            'interval' => $interval,
            'buckets' => $buckets
        ];

    }//end getDateHistogramFacet()


    /**
     * Get range facet for a metadata field
     *
     * Returns range buckets with counts for numeric metadata fields.
     *
     * @param string $field     The metadata field name
     * @param array  $ranges    Range definitions with 'from' and/or 'to' keys
     * @param array  $baseQuery Base query filters to apply
     *
     * @phpstan-param string $field
     * @phpstan-param array<array<string, mixed>> $ranges
     * @phpstan-param array<string, mixed> $baseQuery
     *
     * @psalm-param string $field
     * @psalm-param array<array<string, mixed>> $ranges
     * @psalm-param array<string, mixed> $baseQuery
     *
     * @throws \OCP\DB\Exception If a database error occurs
     *
     * @return array Range facet data
     */
    public function getRangeFacet(string $field, array $ranges, array $baseQuery = []): array
    {
        $buckets = [];

        foreach ($ranges as $range) {
            $queryBuilder = $this->db->getQueryBuilder();
            
            $queryBuilder->selectAlias($queryBuilder->createFunction('COUNT(*)'), 'doc_count')
                ->from('openregister_objects')
                ->where($queryBuilder->expr()->isNotNull($field));

            // Apply range conditions
            if (isset($range['from'])) {
                $queryBuilder->andWhere($queryBuilder->expr()->gte($field, $queryBuilder->createNamedParameter($range['from'])));
            }
            if (isset($range['to'])) {
                $queryBuilder->andWhere($queryBuilder->expr()->lt($field, $queryBuilder->createNamedParameter($range['to'])));
            }

            // Apply base filters
            $this->applyBaseFilters($queryBuilder, $baseQuery);

            $result = $queryBuilder->executeQuery();
            $count = (int) $result->fetchOne();

            // Generate range key
            $key = $this->generateRangeKey($range);

            $bucket = [
                'key' => $key,
                'results' => $count
            ];

            if (isset($range['from'])) {
                $bucket['from'] = $range['from'];
            }
            if (isset($range['to'])) {
                $bucket['to'] = $range['to'];
            }

            $buckets[] = $bucket;
        }

        return [
            'type' => 'range',
            'buckets' => $buckets
        ];

    }//end getRangeFacet()


    /**
     * Apply base query filters to the query builder
     *
     * This method applies the base search filters to ensure facets
     * are calculated within the context of the current search.
     *
     * @param IQueryBuilder $queryBuilder The query builder to modify
     * @param array         $baseQuery    The base query filters
     *
     * @phpstan-param IQueryBuilder $queryBuilder
     * @phpstan-param array<string, mixed> $baseQuery
     *
     * @psalm-param IQueryBuilder $queryBuilder
     * @psalm-param array<string, mixed> $baseQuery
     *
     * @return void
     */
    private function applyBaseFilters(IQueryBuilder $queryBuilder, array $baseQuery): void
    {
        // Apply basic filters like deleted, published, etc.
        $includeDeleted = $baseQuery['_includeDeleted'] ?? false;
        $published = $baseQuery['_published'] ?? false;

        // By default, only include objects where 'deleted' is NULL unless $includeDeleted is true
        if ($includeDeleted === false) {
            $queryBuilder->andWhere($queryBuilder->expr()->isNull('deleted'));
        }

        // If published filter is set, only include objects that are currently published
        if ($published === true) {
            $now = (new \DateTime())->format('Y-m-d H:i:s');
            $queryBuilder->andWhere(
                $queryBuilder->expr()->andX(
                    $queryBuilder->expr()->isNotNull('published'),
                    $queryBuilder->expr()->lte('published', $queryBuilder->createNamedParameter($now)),
                    $queryBuilder->expr()->orX(
                        $queryBuilder->expr()->isNull('depublished'),
                        $queryBuilder->expr()->gt('depublished', $queryBuilder->createNamedParameter($now))
                    )
                )
            );
        }

        // Apply metadata filters from @self
        if (isset($baseQuery['@self']) && is_array($baseQuery['@self'])) {
            foreach ($baseQuery['@self'] as $field => $value) {
                if ($value === 'IS NOT NULL') {
                    $queryBuilder->andWhere($queryBuilder->expr()->isNotNull($field));
                } else if ($value === 'IS NULL') {
                    $queryBuilder->andWhere($queryBuilder->expr()->isNull($field));
                } else if (is_array($value)) {
                    $queryBuilder->andWhere($queryBuilder->expr()->in($field, $queryBuilder->createNamedParameter($value, \Doctrine\DBAL\Connection::PARAM_STR_ARRAY)));
                } else {
                    $queryBuilder->andWhere($queryBuilder->expr()->eq($field, $queryBuilder->createNamedParameter($value)));
                }
            }
        }

    }//end applyBaseFilters()


    /**
     * Get date format string for histogram interval
     *
     * @param string $interval The interval (day, week, month, year)
     *
     * @phpstan-param string $interval
     *
     * @psalm-param string $interval
     *
     * @return string MySQL date format string
     */
    private function getDateFormatForInterval(string $interval): string
    {
        switch ($interval) {
            case 'day':
                return '%Y-%m-%d';
            case 'week':
                return '%Y-%u';
            case 'month':
                return '%Y-%m';
            case 'year':
                return '%Y';
            default:
                return '%Y-%m';
        }

    }//end getDateFormatForInterval()


    /**
     * Generate a human-readable key for a range
     *
     * @param array $range Range definition with 'from' and/or 'to' keys
     *
     * @phpstan-param array<string, mixed> $range
     *
     * @psalm-param array<string, mixed> $range
     *
     * @return string Human-readable range key
     */
    private function generateRangeKey(array $range): string
    {
        if (isset($range['from']) && isset($range['to'])) {
            return $range['from'] . '-' . $range['to'];
        } else if (isset($range['from'])) {
            return $range['from'] . '+';
        } else if (isset($range['to'])) {
            return '0-' . $range['to'];
        } else {
            return 'all';
        }

    }//end generateRangeKey()


    /**
     * Get human-readable label for metadata field value
     *
     * @param string $field The metadata field name
     * @param mixed  $value The field value
     *
     * @phpstan-param string $field
     * @phpstan-param mixed $value
     *
     * @psalm-param string $field
     * @psalm-param mixed $value
     *
     * @return string Human-readable label
     */
    private function getFieldLabel(string $field, mixed $value): string
    {
        // For register and schema fields, try to get the actual name from database
        if ($field === 'register' && is_numeric($value)) {
            try {
                $qb = $this->db->getQueryBuilder();
                $qb->select('title')
                    ->from('openregister_registers')
                    ->where($qb->expr()->eq('id', $qb->createNamedParameter((int) $value)));
                $result = $qb->executeQuery();
                $title = $result->fetchOne();
                return $title ? (string) $title : "Register $value";
            } catch (\Exception $e) {
                return "Register $value";
            }
        }

        if ($field === 'schema' && is_numeric($value)) {
            try {
                $qb = $this->db->getQueryBuilder();
                $qb->select('title')
                    ->from('openregister_schemas')
                    ->where($qb->expr()->eq('id', $qb->createNamedParameter((int) $value)));
                $result = $qb->executeQuery();
                $title = $result->fetchOne();
                return $title ? (string) $title : "Schema $value";
            } catch (\Exception $e) {
                return "Schema $value";
            }
        }

        // For other fields, return the value as-is
        return (string) $value;

    }//end getFieldLabel()

}//end class 