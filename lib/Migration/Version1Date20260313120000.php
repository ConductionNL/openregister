<?php

/**
 * Database migration to drop the legacy blob objects table.
 *
 * Only drops the table if the background migration job has completed
 * (blob_migration_complete flag is true) AND the table contains zero rows.
 * This ensures no data loss during the transition from blob to magic tables.
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author  Conduction Development Team <info@conduction.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use Psr\Log\LoggerInterface;

/**
 * Drops oc_openregister_objects table after blob migration is confirmed complete.
 *
 * Safety checks:
 * 1. Table must exist (no-op if already dropped)
 * 2. appconfig blob_migration_complete must be 'true'
 * 3. Table must contain zero rows
 *
 * If any check fails, the migration logs a WARNING and skips the drop.
 *
 * @package OCA\OpenRegister\Migration
 */
class Version1Date20260313120000 extends SimpleMigrationStep
{
    /**
     * Constructor
     *
     * @param IDBConnection   $db        Database connection for row count check
     * @param IAppConfig      $appConfig App config for migration status check
     * @param LoggerInterface $logger    Logger for warnings
     */
    public function __construct(
        private readonly IDBConnection $db,
        private readonly IAppConfig $appConfig,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Change the database schema.
     *
     * @param IOutput $output        Migration output
     * @param Closure $schemaClosure Schema closure
     * @param array   $options       Migration options
     *
     * @return ISchemaWrapper|null The updated schema or null if no changes
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        // @var ISchemaWrapper $schema
        $schema = $schemaClosure();

        // Check 1: Table must exist.
        if ($schema->hasTable('openregister_objects') === false) {
            $this->logger->info(
                message: '[BlobTableDrop] Table openregister_objects does not exist, nothing to drop',
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
            return null;
        }

        // Check 2: Migration must be marked complete.
        $migrationComplete = $this->appConfig->getValueString(
            app: 'openregister',
            key: 'blob_migration_complete',
            default: 'false'
        );

        if ($migrationComplete !== 'true') {
            $this->logger->warning(
                message: '[BlobTableDrop] Blob migration not complete, skipping table drop',
                context: [
                    'file'              => __FILE__,
                    'line'              => __LINE__,
                    'migrationComplete' => $migrationComplete,
                ]
            );
            $output->warning('Blob migration not complete — skipping openregister_objects table drop');
            return null;
        }

        // Check 3: Table must have zero rows.
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select($qb->createFunction('COUNT(*) as cnt'))
                ->from('openregister_objects');
            $result = $qb->executeQuery();
            $row    = $result->fetch();
            $result->closeCursor();
            $rowCount = (int) ($row['cnt'] ?? 0);
        } catch (\Exception $e) {
            $this->logger->warning(
                message: '[BlobTableDrop] Could not count rows, skipping table drop',
                context: [
                    'file'  => __FILE__,
                    'line'  => __LINE__,
                    'error' => $e->getMessage(),
                ]
            );
            $output->warning('Could not verify blob table is empty — skipping drop');
            return null;
        }

        if ($rowCount > 0) {
            $this->logger->warning(
                message: '[BlobTableDrop] Blob table still has rows, skipping table drop',
                context: [
                    'file'     => __FILE__,
                    'line'     => __LINE__,
                    'rowCount' => $rowCount,
                ]
            );
            $output->warning("Blob table still has {$rowCount} rows — skipping drop");
            return null;
        }

        // All checks passed — drop the table.
        $schema->dropTable('openregister_objects');

        $this->logger->info(
            message: '[BlobTableDrop] Dropping openregister_objects table (migration complete, zero rows)',
            context: ['file' => __FILE__, 'line' => __LINE__]
        );

        return $schema;

    }//end changeSchema()
}//end class
