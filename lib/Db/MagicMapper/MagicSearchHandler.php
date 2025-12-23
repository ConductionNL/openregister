<?php
/**
 * MagicMapper Search Handler
 *
 * This handler provides advanced search capabilities for dynamic schema-based tables.
 * It implements sophisticated search functionality including metadata filtering,
 * object field searching, full-text search, and complex query building for
 * dynamically created tables.
 *
 * KEY RESPONSIBILITIES:
 * - Dynamic table search operations
 * - Metadata and object field filtering
 * - Full-text search within dynamic tables
 * - Query optimization for schema-specific tables
 * - Integration with ObjectEntity conversion
 *
 * SEARCH CAPABILITIES:
 * - Metadata searches (register, schema, owner, organization, etc.)
 * - Object field searches (JSON property searches)
 * - Combined searches with complex boolean logic
 * - Optimized counting and sizing operations
 * - Support for pagination and sorting
 *
 * @category  Handler
 * @package   OCA\OpenRegister\Db\MagicMapper
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 *
 * @since 2.0.0 Initial implementation for MagicMapper search capabilities
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use Exception;
use RuntimeException;
use DateTime;

/**
 * Dynamic table search handler for MagicMapper
 *
 * This class provides comprehensive search functionality for dynamically created
 * schema-based tables, supporting all the search patterns available in ObjectEntityMapper
 * but optimized for schema-specific table structures.
 */
class MagicSearchHandler
{
    /**
     * Constructor for MagicSearchHandler
     *
     * @param IDBConnection   $db     Database connection for queries
     * @param LoggerInterface $logger Logger for debugging and error reporting
     */
    public function __construct(
        private readonly IDBConnection $db,
        private readonly LoggerInterface $logger
    ) {

    }//end __construct()

    /**
     * Search objects in a specific register-schema table using clean query structure
     *
     * This method provides the same search capabilities as ObjectEntityMapper::searchObjects()
     * but optimized for schema-specific dynamic tables.
     *
     * @param array    $query     Search query array with filters and options
     * @param Register $register  Register context for the search
     * @param Schema   $schema    Schema context for the search
     * @param string   $tableName Target dynamic table name
     *
     * @return \OCA\OpenRegister\Db\ObjectEntity[]|int Array of ObjectEntity objects or count if _count=true
     *
     * @throws \OCP\DB\Exception If a database error occurs
     *
     * @phpstan-param array<string, mixed> $query
     *
     * @psalm-param array<string, mixed> $query
     *
     * @psalm-return int|list<ObjectEntity>
     */
    public function searchObjects(array $query, Register $register, Schema $schema, string $tableName): array|int
    {
        // Extract options from query (prefixed with _).
        $limit          = $query['_limit'] ?? null;
        $offset         = $query['_offset'] ?? null;
        $order          = $query['_order'] ?? [];
        $search         = $query['_search'] ?? null;
        $includeDeleted = $query['_includeDeleted'] ?? false;
        $published      = $query['_published'] ?? false;
        $ids            = $query['_ids'] ?? null;
        $count          = $query['_count'] ?? false;

        // Extract metadata from @self.
        $metadataFilters = $query['@self'] ?? [];

        // Clean the query: remove @self and all properties prefixed with _.
        $objectFilters = array_filter(
                $query,
                function ($key) {
                    return $key !== '@self' && !str_starts_with($key, '_');
                },
                ARRAY_FILTER_USE_KEY
                );

        $queryBuilder = $this->db->getQueryBuilder();

        // Build base query - different for count vs search.
        if ($count === true) {
            $queryBuilder->selectAlias($queryBuilder->createFunction('COUNT(*)'), 'count')
                ->from($tableName, 't');
        } else {
            $queryBuilder->select('t.*')
                ->from($tableName, 't')
                ->setMaxResults($limit)
                ->setFirstResult($offset);
        }

        // Apply basic filters (deleted, published, etc.).
        $this->applyBasicFilters(qb: $queryBuilder, includeDeleted: $includeDeleted, published: $published);

        // Apply metadata filters.
        if (empty($metadataFilters) === false) {
            $this->applyMetadataFilters(qb: $queryBuilder, filters: $metadataFilters);
        }

        // Apply object field filters (schema-specific columns).
        if (empty($objectFilters) === false) {
            $this->applyObjectFilters(qb: $queryBuilder, filters: $objectFilters, schema: $schema);
        }

        // Apply ID filtering if provided.
        if ($ids !== null && empty($ids) === false) {
            $this->applyIdFilters(qb: $queryBuilder, ids: $ids);
        }

        // Apply full-text search if provided.
        if ($search !== null && trim($search) !== '') {
            $this->applyFullTextSearch(qb: $queryBuilder, search: trim($search), schema: $schema);
        }

        // Apply sorting (skip for count queries).
        if ($count === false && empty($order) === false) {
            $this->applySorting(qb: $queryBuilder, order: $order, schema: $schema);
        }

        // Execute query and return results.
        if ($count === true) {
            $result = $queryBuilder->executeQuery();
            return (int) $result->fetchOne();
        } else {
            return $this->executeSearchQuery(qb: $queryBuilder, register: $register, schema: $schema, tableName: $tableName);
        }

    }//end searchObjects()

    /**
     * Apply basic filters like deleted and published status
     *
     * @param IQueryBuilder $qb             Query builder to modify
     * @param bool          $includeDeleted Whether to include deleted objects
     * @param bool          $published      Whether to filter for published objects only
     *
     * @return void
     */
    private function applyBasicFilters(IQueryBuilder $qb, bool $includeDeleted, bool $published): void
    {
        // Handle deleted filter.
        if ($includeDeleted === false) {
            $qb->andWhere($qb->expr()->isNull('t._deleted'));
        }

        // Handle published filter.
        if ($published === true) {
            $now = (new DateTime())->format('Y-m-d H:i:s');
            $qb->andWhere(
                $qb->expr()->andX(
                    $qb->expr()->isNotNull('t._published'),
                    $qb->expr()->lte('t._published', $qb->createNamedParameter($now)),
                    $qb->expr()->orX(
                        $qb->expr()->isNull('t._depublished'),
                        $qb->expr()->gt('t._depublished', $qb->createNamedParameter($now))
                    )
                )
            );
        }

    }//end applyBasicFilters()

    /**
     * Apply metadata filters to the query
     *
     * @param IQueryBuilder $qb      Query builder to modify
     * @param array         $filters Metadata filters to apply
     *
     * @return void
     */
    private function applyMetadataFilters(IQueryBuilder $qb, array $filters): void
    {
        foreach ($filters as $field => $value) {
            $columnName = '_'.$field;
            // Metadata columns are prefixed with _.
            if ($value === 'IS NOT NULL') {
                $qb->andWhere($qb->expr()->isNotNull("t.{$columnName}"));
            } else if ($value === 'IS NULL') {
                $qb->andWhere($qb->expr()->isNull("t.{$columnName}"));
            } else if (is_array($value) === true) {
                $qb->andWhere($qb->expr()->in("t.{$columnName}", $qb->createNamedParameter($value, \Doctrine\DBAL\Connection::PARAM_STR_ARRAY)));
            } else {
                $qb->andWhere($qb->expr()->eq("t.{$columnName}", $qb->createNamedParameter($value)));
            }
        }

    }//end applyMetadataFilters()

    /**
     * Apply object field filters based on schema properties
     *
     * @param IQueryBuilder $qb      Query builder to modify
     * @param array         $filters Object field filters to apply
     * @param Schema        $schema  Schema for column mapping
     *
     * @return void
     */
    private function applyObjectFilters(IQueryBuilder $qb, array $filters, Schema $schema): void
    {
        $properties = $schema->getProperties();

        foreach ($filters as $field => $value) {
            // Check if this field exists as a column in the schema.
            if (($properties[$field] ?? null) !== null) {
                $columnName = $this->sanitizeColumnName($field);

                if ($value === 'IS NOT NULL') {
                    $qb->andWhere($qb->expr()->isNotNull("t.{$columnName}"));
                } else if ($value === 'IS NULL') {
                    $qb->andWhere($qb->expr()->isNull("t.{$columnName}"));
                } else if (is_array($value) === true) {
                    $qb->andWhere($qb->expr()->in("t.{$columnName}", $qb->createNamedParameter($value, \Doctrine\DBAL\Connection::PARAM_STR_ARRAY)));
                } else {
                    $qb->andWhere($qb->expr()->eq("t.{$columnName}", $qb->createNamedParameter($value)));
                }
            }
        }

    }//end applyObjectFilters()

    /**
     * Apply ID-based filtering (UUID, slug, etc.)
     *
     * @param IQueryBuilder $qb  Query builder to modify
     * @param array         $ids Array of IDs to filter by
     *
     * @return void
     */
    private function applyIdFilters(IQueryBuilder $qb, array $ids): void
    {
        $orX = $qb->expr()->orX();
        $orX->add($qb->expr()->in('t._uuid', $qb->createNamedParameter($ids, \Doctrine\DBAL\Connection::PARAM_STR_ARRAY)));
        $orX->add($qb->expr()->in('t._slug', $qb->createNamedParameter($ids, \Doctrine\DBAL\Connection::PARAM_STR_ARRAY)));
        $qb->andWhere($orX);

    }//end applyIdFilters()

    /**
     * Apply full-text search across relevant columns
     *
     * @param IQueryBuilder $qb     Query builder to modify
     * @param string        $search Search term
     * @param Schema        $schema Schema for determining searchable fields
     *
     * @return void
     */
    private function applyFullTextSearch(IQueryBuilder $qb, string $search, Schema $schema): void
    {
        $properties       = $schema->getProperties();
        $searchConditions = $qb->expr()->orX();

        // Search in text-based schema properties.
        foreach ($properties ?? [] as $field => $propertyConfig) {
            if (($propertyConfig['type'] ?? '') === 'string') {
                $columnName = $this->sanitizeColumnName($field);
                $searchConditions->add(
                    $qb->expr()->like("t.{$columnName}", $qb->createNamedParameter('%'.$search.'%'))
                );
            }
        }

        // Also search in metadata text fields.
        $searchConditions->add($qb->expr()->like('t._name', $qb->createNamedParameter('%'.$search.'%')));
        $searchConditions->add($qb->expr()->like('t._description', $qb->createNamedParameter('%'.$search.'%')));
        $searchConditions->add($qb->expr()->like('t._summary', $qb->createNamedParameter('%'.$search.'%')));

        $qb->andWhere($searchConditions);

    }//end applyFullTextSearch()

    /**
     * Apply sorting to the query
     *
     * @param IQueryBuilder $qb     Query builder to modify
     * @param array         $order  Sort order configuration
     * @param Schema        $schema Schema for column mapping
     *
     * @return void
     */
    private function applySorting(IQueryBuilder $qb, array $order, Schema $schema): void
    {
        $properties = $schema->getProperties();

        foreach ($order as $field => $direction) {
            $direction = strtoupper($direction);
            if (in_array($direction, ['ASC', 'DESC']) === false) {
                $direction = 'ASC';
            }

            if (str_starts_with($field, '@self.') === true) {
                // Metadata field sorting.
                $metadataField = '_'.str_replace('@self.', '', $field);
                $qb->addOrderBy("t.{$metadataField}", $direction);
            } else if (($properties[$field] ?? null) !== null) {
                // Schema property field sorting.
                $columnName = $this->sanitizeColumnName($field);
                $qb->addOrderBy("t.{$columnName}", $direction);
            }
        }

    }//end applySorting()

    /**
     * Execute search query and convert results to ObjectEntity objects
     *
     * @param IQueryBuilder $qb        Query builder to execute
     * @param Register      $register  Register context
     * @param Schema        $schema    Schema context
     * @param string        $tableName Table name for object conversion
     *
     * @return ObjectEntity[]
     *
     * @throws \OCP\DB\Exception If query execution fails
     *
     * @psalm-return list<ObjectEntity>
     */
    private function executeSearchQuery(IQueryBuilder $qb, Register $register, Schema $schema, string $tableName): array
    {
        $result  = $qb->executeQuery();
        $rows    = $result->fetchAll();
        $objects = [];

        foreach ($rows as $row) {
            $objectEntity = $this->convertRowToObjectEntity(row: $row, register: $register, schema: $schema, tableName: $tableName);
            if ($objectEntity !== null) {
                $objects[] = $objectEntity;
            }
        }

        return $objects;

    }//end executeSearchQuery()

    /**
     * Convert database row from dynamic table to ObjectEntity
     *
     * @param array    $row       Database row data
     * @param Register $register  Register context
     * @param Schema   $schema    Schema context
     * @param string   $tableName Target dynamic table name
     *
     * @return ObjectEntity|null ObjectEntity object or null if conversion fails
     */
    private function convertRowToObjectEntity(array $row, Register $register, Schema $schema, string $tableName=''): ?ObjectEntity
    {
        try {
            $objectEntity = new ObjectEntity();

            // Extract metadata (prefixed with _).
            $metadataData = [];
            $objectData   = [];

            foreach ($row as $column => $value) {
                if (str_starts_with($column, '_') === true) {
                    // Metadata column - remove prefix and map to ObjectEntity.
                    $metadataField = substr($column, 1);
                    $metadataData[$metadataField] = $value;
                } else {
                    // Schema property column - add to object data.
                    $objectData[$column] = $value;
                }
            }

            // Set metadata properties.
            if (($metadataData['uuid'] ?? null) !== null) {
                $objectEntity->setUuid($metadataData['uuid']);
            }

            if (($metadataData['name'] ?? null) !== null) {
                $objectEntity->setName($metadataData['name']);
            }

            if (($metadataData['description'] ?? null) !== null) {
                $objectEntity->setDescription($metadataData['description']);
            }

            if (($metadataData['summary'] ?? null) !== null) {
                $objectEntity->setSummary($metadataData['summary']);
            }

            if (($metadataData['image'] ?? null) !== null) {
                $objectEntity->setImage($metadataData['image']);
            }

            if (($metadataData['slug'] ?? null) !== null) {
                $objectEntity->setSlug($metadataData['slug']);
            }

            if (($metadataData['uri'] ?? null) !== null) {
                $objectEntity->setUri($metadataData['uri']);
            }

            if (($metadataData['owner'] ?? null) !== null) {
                $objectEntity->setOwner($metadataData['owner']);
            }

            if (($metadataData['organisation'] ?? null) !== null) {
                $objectEntity->setOrganisation($metadataData['organisation']);
            }

            if (($metadataData['created'] ?? null) !== null) {
                $objectEntity->setCreated(new DateTime($metadataData['created']));
            }

            if (($metadataData['updated'] ?? null) !== null) {
                $objectEntity->setUpdated(new DateTime($metadataData['updated']));
            }

            if (($metadataData['published'] ?? null) !== null) {
                $objectEntity->setPublished(new DateTime($metadataData['published']));
            }

            if (($metadataData['deleted'] ?? null) !== null) {
                // Convert deleted timestamp to array format expected by setDeleted.
                $deletedDateTime = new DateTime($metadataData['deleted']);
                $objectEntity->setDeleted(
                        [
                            'deleted'   => $deletedDateTime->format('c'),
                            'deletedBy' => $metadataData['deletedBy'] ?? null,
                        ]
                        );
            }

            if (($metadataData['depublished'] ?? null) !== null) {
                $objectEntity->setDepublished(new DateTime($metadataData['depublished']));
            }

            // Set register and schema.
            $objectEntity->setRegister((string) $register->getId());
            $objectEntity->setSchema((string) $schema->getId());

            // Set the object data.
            $objectEntity->setObject($objectData);

            return $objectEntity;
        } catch (\Exception $e) {
            $this->logger->error(
                    'Failed to convert row to ObjectEntity',
                    [
                        'error'     => $e->getMessage(),
                        'tableName' => $tableName,
                        'row'       => $row,
                    ]
                    );

            return null;
        }//end try

    }//end convertRowToObjectEntity()

    /**
     * Sanitize column name for safe database usage
     *
     * @param string $name Column name to sanitize
     *
     * @return string Sanitized column name
     */
    private function sanitizeColumnName(string $name): string
    {
        // Convert to lowercase and replace non-alphanumeric with underscores.
        $sanitized = strtolower(preg_replace('/[^a-zA-Z0-9_]/', '_', $name));

        // Ensure it starts with a letter or underscore.
        if (preg_match('/^[a-zA-Z_]/', $sanitized) === 0) {
            $sanitized = 'col_'.$sanitized;
        }

        // Limit length to 64 characters (MySQL limit).
        return substr($sanitized, 0, 64);

    }//end sanitizeColumnName()
}//end class
