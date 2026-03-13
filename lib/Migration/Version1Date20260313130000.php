<?php

/**
 * Database migration to drop _published and _depublished columns from magic tables.
 *
 * The published/depublished metadata system has been replaced by RBAC conditional
 * rules with the $now dynamic variable. This migration cleans up the legacy columns
 * from all dynamically-created magic tables.
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author  Conduction Development Team <dev@conduction.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use Psr\Log\LoggerInterface;

/**
 * Drops _published and _depublished columns from all magic tables.
 *
 * Magic tables follow the naming pattern: oc_or_{register}_{schema}
 * This migration iterates all such tables and removes the deprecated columns.
 *
 * @package OCA\OpenRegister\Migration
 */
class Version1Date20260313130000 extends SimpleMigrationStep
{
    /**
     * Constructor
     *
     * @param IDBConnection   $db     Database connection
     * @param LoggerInterface $logger Logger for migration progress
     */
    public function __construct(
        private readonly IDBConnection $db,
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
        /** @var ISchemaWrapper $schema */
        $schema  = $schemaClosure();
        $changed = false;

        // Find all magic tables (pattern: or_{register}_{schema}).
        foreach ($schema->getTableNames() as $tableName) {
            // Magic tables start with 'or_' prefix (after oc_ is stripped by Nextcloud).
            if (str_starts_with($tableName, 'or_') === false) {
                continue;
            }

            $table = $schema->getTable($tableName);

            // Drop _published column if it exists.
            if ($table->hasColumn('_published') === true) {
                $table->dropColumn('_published');
                $changed = true;
                $this->logger->info(
                    message: "[PublishedColumnDrop] Dropped _published from {$tableName}",
                    context: ['file' => __FILE__, 'line' => __LINE__]
                );
            }

            // Drop _depublished column if it exists.
            if ($table->hasColumn('_depublished') === true) {
                $table->dropColumn('_depublished');
                $changed = true;
                $this->logger->info(
                    message: "[PublishedColumnDrop] Dropped _depublished from {$tableName}",
                    context: ['file' => __FILE__, 'line' => __LINE__]
                );
            }

            // Drop _published index if it exists.
            if ($table->hasIndex('idx__published') === true) {
                $table->dropIndex('idx__published');
                $this->logger->info(
                    message: "[PublishedColumnDrop] Dropped idx__published from {$tableName}",
                    context: ['file' => __FILE__, 'line' => __LINE__]
                );
            }
        }//end foreach

        // Also drop published/depublished from main objects table.
        $objectsTable = 'openregister_objects';
        if ($schema->hasTable($objectsTable) === true) {
            $table = $schema->getTable($objectsTable);

            if ($table->hasColumn('published') === true) {
                $table->dropColumn('published');
                $changed = true;
                $this->logger->info(
                    message: "[PublishedColumnDrop] Dropped published from {$objectsTable}",
                    context: ['file' => __FILE__, 'line' => __LINE__]
                );
            }

            if ($table->hasColumn('depublished') === true) {
                $table->dropColumn('depublished');
                $changed = true;
                $this->logger->info(
                    message: "[PublishedColumnDrop] Dropped depublished from {$objectsTable}",
                    context: ['file' => __FILE__, 'line' => __LINE__]
                );
            }

            // Drop published-related indexes.
            $publishedIndexes = [
                'objects_published_idx',
                'objects_depublished_idx',
                'objects_published_depublished_idx',
                'objects_register_schema_published_idx',
                'objects_register_published_idx',
                'objects_schema_published_idx',
                'objects_org_published_idx',
                'objects_created_published_idx',
                'objects_updated_published_idx',
            ];
            foreach ($publishedIndexes as $indexName) {
                if ($table->hasIndex($indexName) === true) {
                    $table->dropIndex($indexName);
                    $this->logger->info(
                        message: "[PublishedColumnDrop] Dropped index {$indexName}",
                        context: ['file' => __FILE__, 'line' => __LINE__]
                    );
                }
            }
        }//end if

        if ($changed === false) {
            $output->info('No tables with published/depublished columns found');
            return null;
        }

        return $schema;
    }//end changeSchema()
}//end class
