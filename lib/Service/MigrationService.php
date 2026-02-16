<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Service for migrating objects between blob storage and magic tables.
 *
 * Blob storage uses the `openregister_objects` table with JSON payload.
 * Magic tables are per-register/schema tables with dedicated SQL columns.
 */
class MigrationService
{
    public function __construct(
        private readonly ObjectEntityMapper $objectEntityMapper,
        private readonly MagicMapper $magicMapper,
        private readonly RegisterMapper $registerMapper,
        private readonly SchemaMapper $schemaMapper,
        private readonly IDBConnection $db,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Resolve register and schema from IDs or slugs.
     *
     * @param string|int $registerId Register ID or slug.
     * @param string|int $schemaId   Schema ID or slug.
     *
     * @return array{register: Register, schema: Schema}
     *
     * @throws \Exception If register or schema not found.
     */
    public function resolveRegisterAndSchema(string|int $registerId, string|int $schemaId): array
    {
        $register = $this->registerMapper->find(id: $registerId, _rbac: false, _multitenancy: false);
        $schema = $this->schemaMapper->find(id: $schemaId, _rbac: false, _multitenancy: false);

        return ['register' => $register, 'schema' => $schema];
    }

    /**
     * Get storage status for a register/schema combination.
     *
     * @param Register $register The register.
     * @param Schema   $schema   The schema.
     *
     * @return array Storage status with blob and magic table counts.
     */
    public function getStorageStatus(Register $register, Schema $schema): array
    {
        $blobCount = $this->objectEntityMapper->countAll(
            _filters: ['register' => $register->getId(), 'schema' => $schema->getId()],
            schema: $schema,
            register: $register
        );

        $magicTableExists = $this->magicMapper->existsTableForRegisterSchema(
            register: $register,
            schema: $schema
        );

        $magicCount = 0;
        if ($magicTableExists === true) {
            $magicCount = $this->magicMapper->countObjectsInRegisterSchemaTable(
                query: [],
                register: $register,
                schema: $schema
            );
        }

        return [
            'register' => [
                'id'   => $register->getId(),
                'name' => $register->getName(),
                'slug' => $register->getSlug(),
            ],
            'schema' => [
                'id'   => $schema->getId(),
                'name' => $schema->getName(),
                'slug' => $schema->getSlug(),
            ],
            'blobStorage' => [
                'count' => $blobCount,
            ],
            'magicTable' => [
                'exists' => $magicTableExists,
                'count'  => $magicCount,
            ],
        ];
    }

    /**
     * Migrate objects from blob storage to a magic table.
     *
     * @param Register $register  The register.
     * @param Schema   $schema    The schema.
     * @param int      $batchSize Number of objects per batch.
     * @param bool     $dryRun    If true, report what would happen without changes.
     *
     * @return array Migration report.
     */
    public function migrateToMagicTable(
        Register $register,
        Schema $schema,
        int $batchSize=100,
        bool $dryRun=false
    ): array {
        $report = [
            'direction' => 'to-magic',
            'dryRun'    => $dryRun,
            'total'     => 0,
            'migrated'  => 0,
            'skipped'   => 0,
            'failed'    => 0,
            'errors'    => [],
        ];

        // Ensure magic table exists.
        if ($dryRun === false) {
            $this->magicMapper->ensureTableForRegisterSchema(register: $register, schema: $schema);
        }

        // Count total source objects.
        $report['total'] = $this->objectEntityMapper->countAll(
            _filters: ['register' => $register->getId(), 'schema' => $schema->getId()],
            schema: $schema,
            register: $register
        );

        if ($report['total'] === 0) {
            return $report;
        }

        $offset = 0;

        while ($offset < $report['total']) {
            $objects = $this->objectEntityMapper->findAllDirectBlobStorage(
                limit: $batchSize,
                offset: $offset,
                register: $register,
                schema: $schema
            );

            if (count($objects) === 0) {
                break;
            }

            foreach ($objects as $entity) {
                try {
                    $uuid = $entity->getUuid();

                    // Check if already exists in magic table (idempotency).
                    $existsInTarget = $this->existsInMagicTable(
                        uuid: $uuid,
                        register: $register,
                        schema: $schema
                    );

                    if ($existsInTarget === true) {
                        $report['skipped']++;
                        continue;
                    }

                    if ($dryRun === true) {
                        $report['migrated']++;
                        continue;
                    }

                    // Insert into magic table without events.
                    $this->magicMapper->insertObjectEntity(
                        entity: $entity,
                        register: $register,
                        schema: $schema,
                        dispatchEvents: false
                    );

                    // Hard-delete from blob storage without events.
                    $this->objectEntityMapper->deleteEntity(entity: $entity);

                    $report['migrated']++;
                } catch (\Exception $e) {
                    $report['failed']++;
                    $report['errors'][] = [
                        'uuid'    => $entity->getUuid() ?? 'unknown',
                        'message' => $e->getMessage(),
                    ];
                    $this->logger->error(
                        '[MigrationService] Failed to migrate object to magic table',
                        [
                            'uuid'  => $entity->getUuid(),
                            'error' => $e->getMessage(),
                        ]
                    );
                }
            }

            // When not dry run, successfully migrated objects are deleted from source,
            // so we don't advance offset for those. Only advance by skipped+failed count,
            // but it's simpler to re-fetch from offset 0 since source is shrinking.
            if ($dryRun === true) {
                $offset += $batchSize;
            }
            // When not dry run, keep offset at 0 since source rows are being deleted.
        }

        return $report;
    }

    /**
     * Migrate objects from a magic table to blob storage.
     *
     * @param Register $register  The register.
     * @param Schema   $schema    The schema.
     * @param int      $batchSize Number of objects per batch.
     * @param bool     $dryRun    If true, report what would happen without changes.
     *
     * @return array Migration report.
     */
    public function migrateToBlobStorage(
        Register $register,
        Schema $schema,
        int $batchSize=100,
        bool $dryRun=false
    ): array {
        $report = [
            'direction' => 'to-blob',
            'dryRun'    => $dryRun,
            'total'     => 0,
            'migrated'  => 0,
            'skipped'   => 0,
            'failed'    => 0,
            'errors'    => [],
        ];

        // Check magic table exists.
        $magicTableExists = $this->magicMapper->existsTableForRegisterSchema(
            register: $register,
            schema: $schema
        );

        if ($magicTableExists === false) {
            return $report;
        }

        // Count total source objects.
        $report['total'] = $this->magicMapper->countObjectsInRegisterSchemaTable(
            query: [],
            register: $register,
            schema: $schema
        );

        if ($report['total'] === 0) {
            return $report;
        }

        $offset = 0;

        while ($offset < $report['total']) {
            $objects = $this->magicMapper->searchObjectsInRegisterSchemaTable(
                query: ['_limit' => $batchSize, '_offset' => $offset],
                register: $register,
                schema: $schema
            );

            if (count($objects) === 0) {
                break;
            }

            foreach ($objects as $entity) {
                try {
                    $uuid = $entity->getUuid();

                    // Check if already exists in blob storage (idempotency).
                    $existsInTarget = $this->existsInBlobStorage(
                        uuid: $uuid,
                        register: $register,
                        schema: $schema
                    );

                    if ($existsInTarget === true) {
                        $report['skipped']++;
                        continue;
                    }

                    if ($dryRun === true) {
                        $report['migrated']++;
                        continue;
                    }

                    // Reset auto-increment ID so QBMapper generates a new one.
                    // The UUID stays the same.
                    $entity->setId(null);

                    // Insert into blob storage without events.
                    $this->objectEntityMapper->insertEntity(entity: $entity);

                    // Hard-delete from magic table without events.
                    // Re-fetch the entity from magic table by UUID since we reset the ID.
                    $magicEntity = $this->magicMapper->findInRegisterSchemaTable(
                        identifier: $uuid,
                        register: $register,
                        schema: $schema,
                        rbac: false,
                        multitenancy: false
                    );
                    $this->magicMapper->deleteObjectEntity(
                        entity: $magicEntity,
                        register: $register,
                        schema: $schema,
                        hardDelete: true,
                        dispatchEvents: false
                    );

                    $report['migrated']++;
                } catch (\Exception $e) {
                    $report['failed']++;
                    $report['errors'][] = [
                        'uuid'    => $entity->getUuid() ?? 'unknown',
                        'message' => $e->getMessage(),
                    ];
                    $this->logger->error(
                        '[MigrationService] Failed to migrate object to blob storage',
                        [
                            'uuid'  => $entity->getUuid(),
                            'error' => $e->getMessage(),
                        ]
                    );
                }
            }

            // When not dry run, successfully migrated objects are deleted from source,
            // so keep offset at 0 since source rows are being deleted.
            if ($dryRun === true) {
                $offset += $batchSize;
            }
        }

        return $report;
    }

    /**
     * Check if an object with the given UUID exists in a magic table.
     */
    private function existsInMagicTable(string $uuid, Register $register, Schema $schema): bool
    {
        try {
            $this->magicMapper->findInRegisterSchemaTable(
                identifier: $uuid,
                register: $register,
                schema: $schema,
                rbac: false,
                multitenancy: false
            );
            return true;
        } catch (DoesNotExistException $e) {
            return false;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Check if an object with the given UUID exists in blob storage.
     */
    private function existsInBlobStorage(string $uuid, Register $register, Schema $schema): bool
    {
        try {
            $this->objectEntityMapper->findDirectBlobStorage(
                identifier: $uuid,
                register: $register,
                schema: $schema,
                _rbac: false,
                _multitenancy: false
            );
            return true;
        } catch (DoesNotExistException $e) {
            return false;
        } catch (\Exception $e) {
            return false;
        }
    }
}
