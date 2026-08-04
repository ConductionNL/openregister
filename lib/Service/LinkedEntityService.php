<?php

/**
 * LinkedEntityService
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 *
 * @spec openspec/specs/linked-entity-types/spec.md#requirement-metadata-columns-on-magic-tables
 * @spec openspec/specs/linked-entity-types/spec.md#requirement-metadata-columns-on-entity-tables
 * @spec openspec/specs/linked-entity-types/spec.md#requirement-reverse-lookup-across-tables
 * @spec openspec/specs/linked-entity-types/spec.md#requirement-remove-link-entities-and-mappers
 * @spec openspec/specs/linked-entity-types/spec.md
 * @spec openspec/specs/linked-entity-types/spec.md
 */

namespace OCA\OpenRegister\Service;

use Exception;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Service\Integration\IntegrationRegistry;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCP\DB\Exception as DbException;
use Psr\Log\LoggerInterface;

/**
 * Service for managing linked Nextcloud entities on OpenRegister objects and entities.
 *
 * Handles ad-hoc linking (from sidebars), unlinking, and reverse lookups across
 * all magic tables and entity tables.
 *
 * @category Service
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Service integrates multiple mappers for cross-table lookups
 */
class LinkedEntityService
{
    /**
     * Maximum number of magic tables to scan for reverse lookups (circuit breaker).
     */
    private const MAX_TABLES_TO_SCAN = 50;

    /**
     * Constructor for LinkedEntityService.
     *
     * Per `cleanup-linked-entity-type-map`, the legacy linked-type to
     * column-name map constant was removed; validation flows through
     * `IntegrationRegistry::isValidIntegrationId()` plus a small
     * legacy-id allow-list (see `validateType()`). The column name for
     * a known linked-type is the type id itself — historically the
     * removed map was a verbatim identity for every entry.
     *
     * @param MagicMapper             $magicMapper         Magic mapper for object operations
     * @param SchemaMapper            $schemaMapper        Schema mapper
     * @param RegisterMapper          $registerMapper      Register mapper
     * @param OrganisationMapper      $organisationMapper  Organisation mapper
     * @param IntegrationRegistry     $integrationRegistry Integration registry (authoritative
     *                                                     type-id source, post `cleanup-linked-entity-type-map`)
     * @param LoggerInterface         $logger              Logger
     * @param PermissionHandler       $permissionHandler   RBAC handler for write-permission checks (SEC-CTRL-4)
     * @param DeepLinkRegistryService $deepLinkRegistry    Deep-link registry service for cross-app deep links
     */
    public function __construct(
        private readonly MagicMapper $magicMapper,
        private readonly SchemaMapper $schemaMapper,
        private readonly RegisterMapper $registerMapper,
        private readonly OrganisationMapper $organisationMapper,
        private readonly IntegrationRegistry $integrationRegistry,
        private readonly LoggerInterface $logger,
        private readonly PermissionHandler $permissionHandler,
        private readonly DeepLinkRegistryService $deepLinkRegistry,
    ) {
    }//end __construct()

    /**
     * Assert the current user may write (update) the given object before mutating
     * its linked-entity columns.
     *
     * SEC-CTRL-4: addLink()/removeLink() previously only ran the read check inside
     * MagicMapper::find(), then called update() with no write-permission gate — so a
     * read-only user could mutate link columns. This resolves the object's schema and
     * runs the canonical `update` RBAC check, throwing NotAuthorizedException (403) on
     * denial.
     *
     * @param ObjectEntity $object The object about to be updated.
     *
     * @throws Exception When the schema cannot be resolved or the user lacks write permission.
     *
     * @return void
     */
    private function assertCanWriteObject(ObjectEntity $object): void
    {
        $schemaId = $object->getSchema();
        if ($schemaId === null) {
            throw new Exception('Cannot resolve schema for linked-entity write permission check.');
        }

        $schema = $this->schemaMapper->find($schemaId);

        $this->permissionHandler->checkPermission(
            schema: $schema,
            action: 'update',
            objectOwner: $object->getOwner(),
            object: $object
        );
    }//end assertCanWriteObject()

    /**
     * Add a linked entity ID to an object's metadata column.
     *
     * @param string $objectUuid The object UUID
     * @param string $type       The linked entity type (e.g., 'mail', 'contacts')
     * @param string $entityId   The entity ID to add
     *
     * @throws Exception If the type is invalid or the object is not found
     *
     * @return array The updated linked IDs array
     *
     * @spec openspec/specs/linked-entity-types/spec.md#requirement-metadata-columns-on-entity-tables
     * @spec openspec/specs/linked-entity-types/spec.md
     */
    public function addLink(string $objectUuid, string $type, string $entityId): array
    {
        $this->validateType(type: $type);
        $columnName = $type;

        $object      = $this->magicMapper->find($objectUuid);
        $getter      = 'get'.ucfirst($columnName);
        $setter      = 'set'.ucfirst($columnName);
        $existingIds = $object->$getter() ?? [];

        // Idempotent: don't add if already present.
        if (in_array($entityId, $existingIds, true) === false) {
            // SEC-CTRL-4: enforce write (update) permission before mutating the object.
            $this->assertCanWriteObject(object: $object);
            $existingIds[] = $entityId;
            $object->$setter($existingIds);
            $this->magicMapper->update($object);
        }

        return $existingIds;
    }//end addLink()

    /**
     * Remove a linked entity ID from an object's metadata column.
     *
     * @param string $objectUuid The object UUID
     * @param string $type       The linked entity type
     * @param string $entityId   The entity ID to remove
     *
     * @throws Exception If the type is invalid or the object is not found
     *
     * @return array The updated linked IDs array
     *
     * @spec openspec/specs/linked-entity-types/spec.md#requirement-remove-link-entities-and-mappers
     * @spec openspec/specs/linked-entity-types/spec.md
     */
    public function removeLink(string $objectUuid, string $type, string $entityId): array
    {
        $this->validateType(type: $type);
        $columnName = $type;

        $object      = $this->magicMapper->find($objectUuid);
        $getter      = 'get'.ucfirst($columnName);
        $setter      = 'set'.ucfirst($columnName);
        $existingIds = $object->$getter() ?? [];

        $existingIds = array_values(
                array_filter(
            $existingIds,
            function ($id) use ($entityId) {
                return $id !== $entityId;
            }
        )
                );

        // SEC-CTRL-4: enforce write (update) permission before mutating the object.
        $this->assertCanWriteObject(object: $object);
        $object->$setter($existingIds);
        $this->magicMapper->update($object);

        return $existingIds;
    }//end removeLink()

    /**
     * Add a linked entity ID to a register's metadata column.
     *
     * @param string $registerUuid The register UUID
     * @param string $type         The linked entity type
     * @param string $entityId     The entity ID to add
     *
     * @throws Exception If the type is invalid
     *
     * @return array The updated linked IDs array
     *
     * @spec openspec/specs/linked-entity-types/spec.md#requirement-metadata-columns-on-entity-tables
     */
    public function addLinkToRegister(string $registerUuid, string $type, string $entityId): array
    {
        $this->validateType(type: $type);
        $columnName = $type;

        $registers = $this->registerMapper->findAll(filters: ['uuid' => $registerUuid]);
        if (empty($registers) === true) {
            throw new Exception("Register not found: $registerUuid");
        }

        $register    = $registers[0];
        $getter      = 'get'.ucfirst($columnName);
        $setter      = 'set'.ucfirst($columnName);
        $existingIds = $register->$getter() ?? [];

        if (in_array($entityId, $existingIds, true) === false) {
            $existingIds[] = $entityId;
            $register->$setter($existingIds);
            $this->registerMapper->update($register);
        }

        return $existingIds;
    }//end addLinkToRegister()

    /**
     * Add a linked entity ID to a schema's metadata column.
     *
     * @param string $schemaUuid The schema UUID
     * @param string $type       The linked entity type
     * @param string $entityId   The entity ID to add
     *
     * @throws Exception If the type is invalid
     *
     * @return array The updated linked IDs array
     *
     * @spec openspec/specs/linked-entity-types/spec.md#requirement-metadata-columns-on-entity-tables
     */
    public function addLinkToSchema(string $schemaUuid, string $type, string $entityId): array
    {
        $this->validateType(type: $type);
        $columnName = $type;

        $schemas = $this->schemaMapper->findAll(filters: ['uuid' => $schemaUuid]);
        if (empty($schemas) === true) {
            throw new Exception("Schema not found: $schemaUuid");
        }

        $schema      = $schemas[0];
        $getter      = 'get'.ucfirst($columnName);
        $setter      = 'set'.ucfirst($columnName);
        $existingIds = $schema->$getter() ?? [];

        if (in_array($entityId, $existingIds, true) === false) {
            $existingIds[] = $entityId;
            $schema->$setter($existingIds);
            $this->schemaMapper->update($schema);
        }

        return $existingIds;
    }//end addLinkToSchema()

    /**
     * Reverse lookup: find all objects and entities linked to a specific entity.
     *
     * Scans magic tables (for schemas with the corresponding linkedType) and
     * entity tables (registers, schemas, organisations) for the given entity ID.
     *
     * @param string $type     The linked entity type (e.g., 'mail')
     * @param string $entityId The entity ID to search for
     *
     * @throws Exception If the type is invalid
     *
     * @return array Array of result objects with entityType, uuid, name, etc.
     *
     * @spec openspec/specs/linked-entity-types/spec.md#requirement-reverse-lookup-across-tables
     * @spec openspec/specs/linked-entity-types/spec.md
     */
    public function reverseLookup(string $type, string $entityId): array
    {
        $this->validateType(type: $type);
        $columnName = $type;
        $results    = [];

        // 1. Scan magic tables (objects).
        $results = array_merge($results, $this->scanMagicTables(type: $type, columnName: $columnName, entityId: $entityId));

        // 2. Scan entity tables.
        $results = array_merge($results, $this->scanEntityTables(columnName: $columnName, entityId: $entityId));

        return $results;
    }//end reverseLookup()

    /**
     * Scan magic tables for objects linked to the given entity.
     *
     * @param string $type       The linked entity type
     * @param string $columnName The column name to search
     * @param string $entityId   The entity ID to search for
     *
     * @return array Array of matching results
     *
     * @spec openspec/specs/linked-entity-types/spec.md#requirement-metadata-columns-on-magic-tables
     */
    private function scanMagicTables(string $type, string $columnName, string $entityId): array
    {
        $results = [];

        // Find schemas that declare this linkedType.
        // WARNING: RBAC and multitenancy are intentionally disabled here, so schema metadata (names,
        // slugs, linkedType declarations) and matched object UUIDs/names are returned cross-tenant to
        // any authenticated user calling GET /api/linked/{type}/{entityId}. There is currently NO
        // per-row access check before results are returned. This is intentional for the mail-sidebar
        // use-case where cross-tenant linking is required, but constitutes a cross-tenant data exposure
        // for multi-tenant SaaS deployments. A per-row access check should be added before this
        // endpoint is used in strict-isolation deployments. See TODO #1273.
        $allSchemas = $this->schemaMapper->findAll(_rbac: false, _multitenancy: false);
        $scanned    = 0;

        foreach ($allSchemas as $schema) {
            if ($scanned >= self::MAX_TABLES_TO_SCAN) {
                $this->logger->warning(
                    '[LinkedEntityService] Circuit breaker: max tables reached',
                    ['maxTables' => self::MAX_TABLES_TO_SCAN, 'type' => $type]
                );
                break;
            }

            $linkedTypes = $schema->getLinkedTypes();
            if (in_array($type, $linkedTypes, true) === false) {
                continue;
            }

            // Query this schema's magic table for the entity ID in the column.
            try {
                $objects = $this->magicMapper->findByLinkedEntity(
                    $schema,
                    '_'.$columnName,
                    $entityId
                );

                foreach ($objects as $object) {
                    $registerId = (int) $object->getRegister();
                    $schemaId   = (int) $schema->getId();

                    // Prefer the owning app's detail route (registered via the
                    // deep-link registry by leaf apps like pipelinq/procest);
                    // null falls the frontend back to OpenRegister's own page.
                    $deepLink = $this->deepLinkRegistry->resolveUrl(
                        registerId: $registerId,
                        schemaId: $schemaId,
                        objectData: [
                            'uuid'     => $object->getUuid(),
                            'register' => $registerId,
                            'schema'   => $schemaId,
                        ]
                    );

                    $results[] = [
                        'entityType' => 'object',
                        'uuid'       => $object->getUuid(),
                        'name'       => $object->getName(),
                        'schema'     => $schema->getTitle(),
                        'schemaId'   => $schemaId,
                        'schemaIcon' => $schema->getIcon(),
                        'register'   => $registerId,
                        'url'        => $deepLink,
                    ];
                }//end foreach
            } catch (Exception $e) {
                $this->logger->warning(
                    '[LinkedEntityService] Error scanning magic table',
                    ['schema' => $schema->getId(), 'error' => $e->getMessage()]
                );
            }//end try

            $scanned++;
        }//end foreach

        return $results;
    }//end scanMagicTables()

    /**
     * Scan entity tables for entities linked to the given entity.
     *
     * @param string $columnName The column name to search
     * @param string $entityId   The entity ID to search for
     *
     * @return array Array of matching results
     *
     * @spec openspec/specs/linked-entity-types/spec.md#requirement-metadata-columns-on-entity-tables
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    private function scanEntityTables(string $columnName, string $entityId): array
    {
        $results = [];

        // Scan registers.
        try {
            $allRegisters = $this->registerMapper->findAll();
            foreach ($allRegisters as $register) {
                $getter = 'get'.ucfirst($columnName);
                $ids    = $register->$getter() ?? [];
                if (in_array($entityId, $ids, true) === true) {
                    $results[] = [
                        'entityType' => 'register',
                        'uuid'       => $register->getUuid(),
                        'name'       => $register->getTitle(),
                    ];
                }
            }
        } catch (Exception $e) {
            $this->logger->warning(
                '[LinkedEntityService] Error scanning registers',
                ['error' => $e->getMessage()]
            );
        }

        // Scan schemas.
        try {
            $allSchemas = $this->schemaMapper->findAll();
            foreach ($allSchemas as $schema) {
                $getter = 'get'.ucfirst($columnName);
                $ids    = $schema->$getter() ?? [];
                if (in_array($entityId, $ids, true) === true) {
                    $results[] = [
                        'entityType' => 'schema',
                        'uuid'       => $schema->getUuid(),
                        'name'       => $schema->getTitle(),
                    ];
                }
            }
        } catch (Exception $e) {
            $this->logger->warning(
                '[LinkedEntityService] Error scanning schemas',
                ['error' => $e->getMessage()]
            );
        }

        // Scan organisations.
        try {
            $allOrganisations = $this->organisationMapper->findAll();
            foreach ($allOrganisations as $organisation) {
                $getter = 'get'.ucfirst($columnName);
                $ids    = $organisation->$getter() ?? [];
                if (in_array($entityId, $ids, true) === true) {
                    $results[] = [
                        'entityType' => 'organisation',
                        'uuid'       => $organisation->getUuid(),
                        'name'       => $organisation->getName(),
                    ];
                }
            }
        } catch (Exception $e) {
            $this->logger->warning(
                '[LinkedEntityService] Error scanning organisations',
                ['error' => $e->getMessage()]
            );
        }

        return $results;
    }//end scanEntityTables()

    /**
     * Validate that the given type is a valid linked entity type.
     *
     * Per `cleanup-linked-entity-type-map`, validation flows through:
     *
     *   1. `IntegrationRegistry::isValidIntegrationId($type)` — the
     *      authoritative source.
     *   2. A small legacy-id allow-list (`legacyLinkedTypeIds()`) so
     *      pre-cleanup callers passing ids like `mail` (now `email` in
     *      the registry) or `todos` (now `tasks`) continue to work
     *      until consumers migrate.
     *
     * @param string $type The type to validate
     *
     * @throws Exception If the type is invalid
     *
     * @return void
     *
     * @spec openspec/specs/linked-entity-types/spec.md#requirement-metadata-columns-on-entity-tables
     * @spec openspec/specs/cleanup-linked-entity-type-map/spec.md "Registry-Driven Behaviour Unchanged"
     */
    private function validateType(string $type): void
    {
        if ($this->integrationRegistry->isValidIntegrationId($type) === true) {
            return;
        }

        $legacy = self::legacyLinkedTypeIds();
        if (in_array($type, $legacy, true) === true) {
            return;
        }

        $registered = $this->integrationRegistry->listIds();
        $combined   = array_unique(array_merge($legacy, $registered));
        sort($combined);
        throw new Exception(
            "Invalid linked entity type '$type'. Valid types: ".implode(', ', $combined)
        );
    }//end validateType()

    /**
     * Legacy linked-type id allow-list — internal implementation detail.
     *
     * Mirrors `Schema::legacyLinkedTypeIds()` (the cleanup also
     * removed `Schema`'s public linked-types constant). Kept private
     * and method-form so the values aren't exposed as a public symbol;
     * new linked-types MUST be added by registering an
     * `IntegrationProvider`, not by extending this list.
     *
     * @return array<int, string>
     *
     * @spec openspec/specs/cleanup-linked-entity-type-map/spec.md "Constants Removed"
     */
    private static function legacyLinkedTypeIds(): array
    {
        return [
            'files',
            'mail',
            'contacts',
            'notes',
            'todos',
            'calendar',
            'talk',
            'deck',
        ];
    }//end legacyLinkedTypeIds()
}//end class
