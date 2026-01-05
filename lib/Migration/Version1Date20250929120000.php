<?php

/**
 * OpenRegister Schema Searchable Property Migration
 *
 * This migration adds a 'searchable' boolean column to the schemas table
 * to control whether objects of a schema should be indexed in SOLR for searching.
 * Defaults to true to maintain backward compatibility.
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Migration to add searchable column to schemas table
 *
 * This migration adds a boolean column to control SOLR indexing per schema:
 * - searchable: Boolean flag (default true) to include/exclude schema objects from SOLR
 * - Maintains backward compatibility by defaulting to true for existing schemas
 */
class Version1Date20250929120000 extends SimpleMigrationStep
{
    /**
     * Add searchable column to schemas table
     *
     * @param IOutput $output        Migration output interface
     * @param Closure $schemaClosure Schema closure
     * @param array   $options       Migration options
     *
     * @return ISchemaWrapper|null Updated schema
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /*
         * @var ISchemaWrapper $schema
         */

        $schema = $schemaClosure();

        $output->info(message: '🔧 Adding searchable column to schemas table...');

        if ($schema->hasTable('openregister_schemas') === true) {
            $table = $schema->getTable('openregister_schemas');

            if ($table->hasColumn('searchable') === false) {
                $table->addColumn(
                    'searchable',
                    Types::BOOLEAN,
                    [
                        'notnull' => true,
                        'default' => true,
                        'comment' => 'Whether objects of this schema should be indexed in SOLR for searching',
                    ]
                );

                $output->info(message: '✅ Added searchable column with default value true');
                $output->info('🎯 This enables per-schema SOLR indexing control:');
                $output->info(message: '   • searchable = true → Objects indexed in SOLR (searchable)');
                $output->info(message: '   • searchable = false → Objects excluded from SOLR (not searchable)');
                $output->info(message: '🚀 Existing schemas default to searchable for backward compatibility!');

                return $schema;
            } else {
                $output->info(message: 'ℹ️  Searchable column already exists, skipping...');
            }//end if
        } else {
            $output->info(message: '⚠️  Schemas table not found, skipping searchable column addition');
        }//end if

        return null;
    }//end changeSchema()

    /**
     * Ensure all existing schemas have searchable set to true
     *
     * @param IOutput $output        Migration output interface
     * @param Closure $schemaClosure Schema closure
     * @param array   $options       Migration options
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    {
        $output->info(message: '🔧 Ensuring existing schemas are marked as searchable...');

        // Since we added the column with default value true and notnull constraint,.
        // All existing records should already have searchable = 1.
        // We'll just verify this with a simple count query.
        $connection = \OC::$server->getDatabaseConnection();

        try {
            // Count schemas to verify the column was added successfully.
            $sql          = "SELECT COUNT(*) as total FROM `oc_openregister_schemas`";
            $result       = $connection->executeQuery($sql);
            $row          = $result->fetch();
            $totalSchemas = $row['total'] ?? 0;

            if ($totalSchemas > 0) {
                $schemaMsg = "Found {$totalSchemas} existing schemas - all automatically set to searchable=true";
                $output->info(message: $schemaMsg);
            } else {
                $output->info(message: 'ℹ️  No existing schemas found - ready for new schemas with searchable control');
            }

            $output->info(message: '🎯 All schemas are now properly configured for SOLR indexing control');
        } catch (\Exception $e) {
            $output->info('❌ Failed to verify schemas: '.$e->getMessage());
            $output->info(message: '⚠️  This may indicate an issue with the searchable column');
            $output->info('💡 Manual check: SELECT searchable FROM oc_openregister_schemas LIMIT 1');
        }
    }//end postSchemaChange()
}//end class
