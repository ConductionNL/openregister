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
use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicOrganizationHandler;
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
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 */
class MagicSearchHandler
{
    /**
     * Constructor for MagicSearchHandler
     *
     * @param IDBConnection           $db                  Database connection for queries
     * @param LoggerInterface         $logger              Logger for debugging and error reporting
     * @param MagicRbacHandler        $rbacHandler         RBAC handler for access control
     * @param MagicOrganizationHandler $organizationHandler Organization handler for multi-tenancy
     */
    public function __construct(
        private readonly IDBConnection $db,
        private readonly LoggerInterface $logger,
        private readonly MagicRbacHandler $rbacHandler,
        private readonly MagicOrganizationHandler $organizationHandler
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
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    public function searchObjects(array $query, Register $register, Schema $schema, string $tableName): array|int
    {
        // Extract options from query (prefixed with _).
        $limit             = $query['_limit'] ?? null;
        $offset            = $query['_offset'] ?? null;
        $order             = $query['_order'] ?? [];
        $search            = $query['_search'] ?? null;
        $includeDeleted    = $query['_includeDeleted'] ?? false;
        $published         = $query['_published'] ?? false;
        $ids               = $query['_ids'] ?? null;
        $count             = $query['_count'] ?? false;
        $rbac              = $query['_rbac'] ?? true;
        $multitenancy      = $query['_multitenancy'] ?? true;
        $relationsContains = $query['_relations_contains'] ?? null;

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
        }

        if ($count === false) {
            $queryBuilder->select('t.*')
                ->from($tableName, 't')
                ->setMaxResults($limit)
                ->setFirstResult($offset);
        }

        // Apply basic filters (deleted, published, etc.).
        $this->applyBasicFilters(qb: $queryBuilder, includeDeleted: $includeDeleted, published: $published);

        // Apply multi-tenancy (organization) filtering if enabled.
        if ($multitenancy === true) {
            $this->organizationHandler->applyOrganizationFilter(
                qb: $queryBuilder,
                allowPublishedAccess: true,
                adminBypassEnabled: true
            );
        }

        // Apply RBAC filtering if enabled.
        if ($rbac === true) {
            $this->rbacHandler->applyRbacFilters(qb: $queryBuilder, schema: $schema, action: 'read');
        }

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

        // Apply relations contains filter if provided.
        if ($relationsContains !== null && empty($relationsContains) === false) {
            $this->applyRelationsContainsFilter(qb: $queryBuilder, uuid: $relationsContains);
        }

        // Apply sorting (skip for count queries).
        if ($count === false && empty($order) === false) {
            $this->applySorting(qb: $queryBuilder, order: $order, schema: $schema);
        }

        // Execute query and return results.
        if ($count === true) {
            $result = $queryBuilder->executeQuery();
            return (int) $result->fetchOne();
        }

        return $this->executeSearchQuery(qb: $queryBuilder, register: $register, schema: $schema, tableName: $tableName);
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
                $qb->andWhere(
                    $qb->expr()->in(
                        "t.{$columnName}",
                        $qb->createNamedParameter($value, \Doctrine\DBAL\Connection::PARAM_STR_ARRAY)
                    )
                );
                continue;
            }

            $qb->andWhere($qb->expr()->eq("t.{$columnName}", $qb->createNamedParameter($value)));
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
                $columnName   = $this->sanitizeColumnName($field);
                $propertyType = $properties[$field]['type'] ?? 'string';

                if ($value === 'IS NOT NULL') {
                    $qb->andWhere($qb->expr()->isNotNull("t.{$columnName}"));
                    continue;
                }

                if ($value === 'IS NULL') {
                    $qb->andWhere($qb->expr()->isNull("t.{$columnName}"));
                    continue;
                }

                // Handle array type columns (JSON arrays in PostgreSQL).
                if ($propertyType === 'array') {
                    $this->applyJsonArrayFilter(qb: $qb, columnName: $columnName, value: $value);
                    continue;
                }

                if (is_array($value) === true) {
                    $qb->andWhere(
                        $qb->expr()->in(
                            "t.{$columnName}",
                            $qb->createNamedParameter($value, \Doctrine\DBAL\Connection::PARAM_STR_ARRAY)
                        )
                    );
                    continue;
                }

                $qb->andWhere($qb->expr()->eq("t.{$columnName}", $qb->createNamedParameter($value)));
            } else {
                // Property doesn't exist in this schema but a filter was requested.
                // Add a condition that always evaluates to false to return zero results.
                // This ensures multi-schema searches don't return unfiltered results
                // from schemas that lack the filtered property.
                $qb->andWhere('1 = 0');
            }//end if
        }//end foreach
    }//end applyObjectFilters()

    /**
     * Apply filter for JSON array columns using PostgreSQL jsonb operators
     *
     * @param IQueryBuilder $qb         Query builder to modify
     * @param string        $columnName Column name to filter
     * @param mixed         $value      Filter value (string or array of strings)
     *
     * @return void
     */
    private function applyJsonArrayFilter(IQueryBuilder $qb, string $columnName, mixed $value): void
    {
        // Normalize value to array.
        $values = [$value];
        if (is_array($value) === true) {
            $values = $value;
        }

        if (count($values) === 1) {
            // Single value: check if JSON array contains this value.
            // PostgreSQL: column::jsonb ? 'value' (checks if array contains the element).
            $jsonValue = json_encode($values[0]);
            $qb->andWhere(
                $qb->expr()->comparison(
                    x: "t.{$columnName}::jsonb",
                    operator: '@>',
                    y: $qb->createNamedParameter('['.$jsonValue.']')
                )
            );
            return;
        }

        // Multiple values: check if JSON array contains ANY of the values (OR logic).
        $orConditions = $qb->expr()->orX();
        foreach ($values as $v) {
            $jsonValue = json_encode($v);
            $orConditions->add(
                $qb->expr()->comparison(
                    x: "t.{$columnName}::jsonb",
                    operator: '@>',
                    y: $qb->createNamedParameter('['.$jsonValue.']')
                )
            );
        }

        $qb->andWhere($orConditions);
    }//end applyJsonArrayFilter()

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
     * Apply relations contains filter to find objects referencing a specific UUID
     *
     * This uses PostgreSQL's JSONB @> operator to check if the _relations array
     * contains the specified UUID.
     *
     * @param IQueryBuilder $qb   Query builder to modify
     * @param string        $uuid UUID to search for in relations
     *
     * @return void
     */
    private function applyRelationsContainsFilter(IQueryBuilder $qb, string $uuid): void
    {
        // Relations are stored as a JSON object like {"fieldName": "uuid", ...}.
        // Use EXISTS with jsonb_each_text to check if any VALUE equals the UUID.
        $qb->andWhere(
            'EXISTS (SELECT 1 FROM jsonb_each_text(t._relations) AS kv WHERE kv.value = '
            .$qb->createNamedParameter($uuid).')'
        );
    }//end applyRelationsContainsFilter()

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

        // Use lowercase search for case-insensitive matching.
        $lowerSearch      = strtolower($search);
        $searchPattern    = $qb->createNamedParameter('%'.$lowerSearch.'%');

        // Search in text-based schema properties (case-insensitive using LOWER()).
        foreach ($properties ?? [] as $field => $propertyConfig) {
            if (($propertyConfig['type'] ?? '') === 'string') {
                $columnName = $this->sanitizeColumnName($field);
                $searchConditions->add(
                    $qb->expr()->like(
                        $qb->createFunction("LOWER(t.{$columnName})"),
                        $searchPattern
                    )
                );
            }
        }

        // Also search in metadata text fields (case-insensitive using LOWER()).
        $searchConditions->add(
            $qb->expr()->like($qb->createFunction('LOWER(t._name)'), $searchPattern)
        );
        $searchConditions->add(
            $qb->expr()->like($qb->createFunction('LOWER(t._description)'), $searchPattern)
        );
        $searchConditions->add(
            $qb->expr()->like($qb->createFunction('LOWER(t._summary)'), $searchPattern)
        );

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
            $objectEntity = $this->convertRowToObjectEntity(
                row: $row,
                register: $register,
                schema: $schema,
                tableName: $tableName
            );
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
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     * @SuppressWarnings(PHPMD.NPathComplexity)       Row to entity conversion requires many field mappings
     */
    private function convertRowToObjectEntity(
        array $row,
        Register $register,
        Schema $schema,
        string $tableName=''
    ): ?ObjectEntity {
        try {
            $objectEntity = new ObjectEntity();

            // Extract metadata (prefixed with _).
            $metadataData = [];
            $objectData   = [];

            // Build property type map from schema for type conversion.
            $propertyTypes = [];
            foreach ($schema->getProperties() as $propName => $propDef) {
                $propertyTypes[$propName] = $propDef['type'] ?? 'string';
            }

            foreach ($row as $column => $value) {
                if (str_starts_with($column, '_') === true) {
                    // Metadata column - remove prefix and map to ObjectEntity.
                    $metadataField = substr($column, 1);
                    $metadataData[$metadataField] = $value;
                    continue;
                }

                // Convert column name from snake_case to camelCase property name.
                $propertyName = $this->columnNameToPropertyName($column);

                // Convert value based on schema property type.
                $propertyType = $propertyTypes[$propertyName] ?? 'string';
                $objectData[$propertyName] = $this->convertValueByType($value, $propertyType);
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
        // Convert camelCase to snake_case (must match MagicMapper::sanitizeColumnName).
        // Insert underscore before uppercase letters, then lowercase everything.
        $name = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $name);
        $name = strtolower($name);

        // Replace any remaining invalid characters with underscore.
        $name = preg_replace('/[^a-z0-9_]/', '_', $name);

        // Ensure it starts with a letter or underscore.
        if (preg_match('/^[a-z_]/', $name) === 0) {
            $name = 'col_'.$name;
        }

        // Remove consecutive underscores.
        $name = preg_replace('/_+/', '_', $name);

        // Remove trailing underscores.
        $name = rtrim($name, '_');

        // Limit length to 64 characters (MySQL limit).
        return substr($name, 0, 64);
    }//end sanitizeColumnName()

    /**
     * Convert snake_case column name to camelCase property name
     *
     * @param string $columnName Column name in snake_case
     *
     * @return string Property name in camelCase
     */
    private function columnNameToPropertyName(string $columnName): string
    {
        // Convert snake_case to camelCase.
        return lcfirst(str_replace('_', '', ucwords($columnName, '_')));
    }//end columnNameToPropertyName()

    /**
     * Convert value based on schema property type
     *
     * Schema type determines the conversion, not the data format.
     *
     * @param mixed  $value Value to convert
     * @param string $type  Schema property type (string, number, boolean, array, object, integer)
     *
     * @return mixed Converted value
     */
    private function convertValueByType(mixed $value, string $type): mixed
    {
        // Handle null values.
        if ($value === null) {
            return null;
        }

        // Convert based on schema type (schema is authoritative, not data format).
        switch ($type) {
            case 'array':
            case 'object':
                // Schema says this should be array/object - decode if it's a JSON string.
                if (is_string($value) === true) {
                    $decoded = json_decode($value, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        return $decoded;
                    }
                }
                // Already an array/object or failed to decode - return as-is.
                return $value;

            case 'number':
                // Schema says this should be a number (float).
                if (is_numeric($value) === true) {
                    return (float) $value;
                }
                return $value;

            case 'integer':
                // Schema says this should be an integer.
                if (is_numeric($value) === true) {
                    return (int) $value;
                }
                return $value;

            case 'boolean':
                // Schema says this should be a boolean.
                if (is_bool($value) === true) {
                    return $value;
                }
                if (is_string($value) === true) {
                    return in_array(strtolower($value), ['true', '1', 'yes'], true);
                }
                return (bool) $value;

            case 'string':
            default:
                // Schema says string or unknown type - return as-is.
                return $value;
        }
    }//end convertValueByType()
}//end class
