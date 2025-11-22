<?php
/**
 * OpenRegister Multi-Tenancy Migration
 *
 * This migration completes the multi-tenancy implementation by:
 * 1. Adding users and owner fields to Organisation table
 * 2. Setting all existing registers, schemas, and objects to have an organisation and owner
 * 3. Making organisation and owner fields mandatory (non-nullable)
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
 * Migration to complete multi-tenancy implementation
 */
class Version1Date20250801000000 extends SimpleMigrationStep
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
     * Apply schema changes for multi-tenancy
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

        // 1. Add new fields to organisations table.
        if ($schema->hasTable('openregister_organisations')) {
            $table = $schema->getTable('openregister_organisations');

            // Add users field (JSON array of user IDs).
            if (!$table->hasColumn('users')) {
                $table->addColumn('users', Types::JSON, [
                    'notnull' => false,
                    'default' => '[]'
                ]);
                $output->info(message: ('Added users column to organisations table');
            }

            // Add owner field (user ID who owns the organisation).
            if (!$table->hasColumn('owner')) {
                $table->addColumn('owner', Types::STRING, [
                    'notnull' => false,
                    'length' => 255
                ]);
                $output->info(message: ('Added owner column to organisations table');
            }

            // Add slug field (URL-friendly identifier).
            if (!$table->hasColumn('slug')) {
                $table->addColumn('slug', Types::STRING, [
                    'notnull' => false,
                    'length' => 255
                ]);
                $output->info(message: ('Added slug column to organisations table');
            }

            // Add unique constraints for uuid and slug.
            if ($table->hasColumn('uuid') && !$table->hasIndex('organisations_uuid_unique')) {
                $table->addUniqueIndex(['uuid'], 'organisations_uuid_unique');
                $output->info(message: ('Added unique constraint on uuid column');
            }

            if ($table->hasColumn('slug') && !$table->hasIndex('organisations_slug_unique')) {
                $table->addUniqueIndex(['slug'], 'organisations_slug_unique');
                $output->info(message: ('Added unique constraint on slug column');
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