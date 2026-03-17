<?php
/**
 * MigrationService for OpenRegister.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Service for migrating objects between blob storage and magic tables.
 *
 * NOTE: Blob storage (ObjectEntityMapper) has been removed. This service
 * is retained for the status endpoint but migration is no longer possible.
 */
class MigrationService
{
    /**
     * Constructor.
     *
     * @param MagicMapper     $magicMapper    The magic mapper.
     * @param RegisterMapper  $registerMapper The register mapper.
     * @param SchemaMapper    $schemaMapper   The schema mapper.
     * @param IDBConnection   $db             The database connection.
     * @param LoggerInterface $logger         The logger.
     */
    public function __construct(
        private readonly MagicMapper $magicMapper,
        private readonly RegisterMapper $registerMapper,
        private readonly SchemaMapper $schemaMapper,
        private readonly IDBConnection $db,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

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
        $schema   = $this->schemaMapper->find(id: $schemaId, _rbac: false, _multitenancy: false);

        return ['register' => $register, 'schema' => $schema];
    }//end resolveRegisterAndSchema()

    /**
     * Get storage status for a register/schema combination.
     *
     * Returns only magic table information since blob storage has been removed.
     *
     * @param Register $register The register.
     * @param Schema   $schema   The schema.
     *
     * @return array Storage status with magic table counts.
     */
    public function getStorageStatus(Register $register, Schema $schema): array
    {
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
            'register'   => [
                'id'   => $register->getId(),
                'name' => $register->getTitle(),
                'slug' => $register->getSlug(),
            ],
            'schema'     => [
                'id'   => $schema->getId(),
                'name' => $schema->getTitle(),
                'slug' => $schema->getSlug(),
            ],
            'magicTable' => [
                'exists' => $magicTableExists,
                'count'  => $magicCount,
            ],
        ];
    }//end getStorageStatus()

    /**
     * Migrate objects from blob storage to a magic table.
     *
     * NOTE: Blob storage (ObjectEntityMapper) has been removed. Use the
     * BlobMigrationJob background job instead, which reads directly from the
     * raw oc_openregister_objects table via IDBConnection.
     *
     * @param Register $register  The register.
     * @param Schema   $schema    The schema.
     * @param int      $batchSize Number of objects per batch.
     * @param bool     $dryRun    If true, report what would happen without changes.
     *
     * @return array Migration report indicating blob storage is no longer available.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function migrateToMagicTable(
        Register $register,
        Schema $schema,
        int $batchSize=100,
        bool $dryRun=false
    ): array {
        return [
            'direction' => 'to-magic',
            'dryRun'    => $dryRun,
            'total'     => 0,
            'migrated'  => 0,
            'skipped'   => 0,
            'failed'    => 0,
            'errors'    => [],
            'message'   => 'Blob storage mapper has been removed. Use BlobMigrationJob background job instead.',
        ];
    }//end migrateToMagicTable()

    /**
     * Migrate objects from a magic table to blob storage.
     *
     * NOTE: Blob storage (ObjectEntityMapper) has been removed. Reverse migration
     * to blob storage is no longer supported.
     *
     * @param Register $register  The register.
     * @param Schema   $schema    The schema.
     * @param int      $batchSize Number of objects per batch.
     * @param bool     $dryRun    If true, report what would happen without changes.
     *
     * @return array Migration report indicating blob storage is no longer available.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function migrateToBlobStorage(
        Register $register,
        Schema $schema,
        int $batchSize=100,
        bool $dryRun=false
    ): array {
        return [
            'direction' => 'to-blob',
            'dryRun'    => $dryRun,
            'total'     => 0,
            'migrated'  => 0,
            'skipped'   => 0,
            'failed'    => 0,
            'errors'    => [],
            'message'   => 'Blob storage mapper has been removed. Reverse migration is no longer supported.',
        ];
    }//end migrateToBlobStorage()
}//end class
