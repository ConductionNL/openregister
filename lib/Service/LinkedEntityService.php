<?php

/**
 * LinkedEntityService
 *
 * @category Service
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 *
 * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-42
 * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-43
 * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-44
 * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-45
 * @spec openspec/changes/retrofit-annotate-openregister-2026-04-30/tasks.md#task-48
 * @spec openspec/changes/retrofit-annotate-openregister-2026-04-30/tasks.md#task-49
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
     * Valid linked entity types and their column names.
     */
    private const TYPE_COLUMN_MAP = [
        'mail'     => 'mail',
        'contacts' => 'contacts',
        'notes'    => 'notes',
        'todos'    => 'todos',
        'calendar' => 'calendar',
        'talk'     => 'talk',
        'deck'     => 'deck',
        'files'    => 'files',
    ];

    /**
     * Maximum number of magic tables to scan for reverse lookups (circuit breaker).
     */
    private const MAX_TABLES_TO_SCAN = 50;

    /**
     * Constructor for LinkedEntityService.
     *
     * @param MagicMapper        $magicMapper        Magic mapper for object operations
     * @param SchemaMapper       $schemaMapper       Schema mapper
     * @param RegisterMapper     $registerMapper     Register mapper
     * @param OrganisationMapper $organisationMapper Organisation mapper
     * @param LoggerInterface    $logger             Logger
     */
    public function __construct(
        private readonly MagicMapper $magicMapper,
        private readonly SchemaMapper $schemaMapper,
        private readonly RegisterMapper $registerMapper,
        private readonly OrganisationMapper $organisationMapper,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

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
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-43
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-30/tasks.md#task-48
     */
    public function addLink(string $objectUuid, string $type, string $entityId): array
    {
        $this->validateType(type: $type);
        $columnName = self::TYPE_COLUMN_MAP[$type];

        $object      = $this->magicMapper->find($objectUuid);
        $getter      = 'get'.ucfirst($columnName);
        $setter      = 'set'.ucfirst($columnName);
        $existingIds = $object->$getter() ?? [];

        // Idempotent: don't add if already present.
        if (in_array($entityId, $existingIds, true) === false) {
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
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-45
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-30/tasks.md#task-48
     */
    public function removeLink(string $objectUuid, string $type, string $entityId): array
    {
        $this->validateType(type: $type);
        $columnName = self::TYPE_COLUMN_MAP[$type];

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
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-43
     */
    public function addLinkToRegister(string $registerUuid, string $type, string $entityId): array
    {
        $this->validateType(type: $type);
        $columnName = self::TYPE_COLUMN_MAP[$type];

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
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-43
     */
    public function addLinkToSchema(string $schemaUuid, string $type, string $entityId): array
    {
        $this->validateType(type: $type);
        $columnName = self::TYPE_COLUMN_MAP[$type];

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
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-44
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-30/tasks.md#task-49
     */
    public function reverseLookup(string $type, string $entityId): array
    {
        $this->validateType(type: $type);
        $columnName = self::TYPE_COLUMN_MAP[$type];
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
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-42
     */
    private function scanMagicTables(string $type, string $columnName, string $entityId): array
    {
        $results = [];

        // Find schemas that declare this linkedType.
        $allSchemas = $this->schemaMapper->findAll();
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
                    $results[] = [
                        'entityType' => 'object',
                        'uuid'       => $object->getUuid(),
                        'name'       => $object->getName(),
                        'schema'     => $schema->getTitle(),
                        'schemaId'   => $schema->getId(),
                        'register'   => $object->getRegister(),
                    ];
                }
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
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-43
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
     * @param string $type The type to validate
     *
     * @throws Exception If the type is invalid
     *
     * @return void
     *
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-43
     */
    private function validateType(string $type): void
    {
        if (isset(self::TYPE_COLUMN_MAP[$type]) === false) {
            throw new Exception(
                "Invalid linked entity type '$type'. Valid types: ".implode(', ', array_keys(self::TYPE_COLUMN_MAP))
            );
        }
    }//end validateType()
}//end class
