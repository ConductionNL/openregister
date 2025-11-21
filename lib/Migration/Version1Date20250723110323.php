<?php
/**
 * OpenRegister Add Default Organisation Flag Migration
 *
 * This migration adds the is_default column to the organisations table
 * and ensures that at least one organisation is marked as default.
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use OCP\IDBConnection;

/**
 * Migration to add is_default column to organisations table
 */
class Version1Date20250723110323 extends SimpleMigrationStep
{
    /**
     * Database connection
     *
     * @var IDBConnection
     */
    private IDBConnection $connection;

    /**
     * Constructor
     *
     * @param IDBConnection $connection Database connection
     */
    public function __construct(IDBConnection $connection)
    {
        $this->connection = $connection;
    }


    /**
     * Pre-schema change operations
     *
     * @param IOutput                   $output
     * @param Closure(): ISchemaWrapper $schemaClosure
     * @param array                     $options
     *
     * @return void
     */
    public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    {
        // No pre-schema changes required.

    }//end preSchemaChange()

    /**
     * Apply schema changes for is_default column
     *
     * @param IOutput                   $output
     * @param Closure(): ISchemaWrapper $schemaClosure
     * @param array                     $options
     *
     * @return null|ISchemaWrapper
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // Add is_default field to organisations table.
        if ($schema->hasTable('openregister_organisations')) {
            $table = $schema->getTable('openregister_organisations');
            
            // Add is_default field (boolean flag for default organisation).
            if (!$table->hasColumn('is_default')) {
                $table->addColumn('is_default', Types::BOOLEAN, [
                    'notnull' => false,
                    'default' => false
                ]);
                $output->info('Added is_default column to organisations table');
            }
        }

        return $schema;
    }


    /**
     * Post-schema change operations
     *
     * @param IOutput                   $output
     * @param Closure(): ISchemaWrapper $schemaClosure
     * @param array                     $options
     *
     * @return void
     */
    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    {
        // No post-schema changes required.

    }//end postSchemaChange()
} 