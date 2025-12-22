<?php

/**
 * OpenRegister Migration Version1Date20251222000000
 *
 * Migration to fix NULL required field in openregister_schemas table.
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Fix NULL required field in schemas table
 *
 * This migration fixes a bug where the 'required' field in the schemas table
 * was being stored as NULL instead of an empty JSON array '[]'. This caused
 * validation errors during object creation with the message:
 * "required must be an array of strings"
 *
 * The migration updates all existing schemas to set required='[]' where it is NULL.
 *
 * @category  Migration
 * @package   OCA\OpenRegister\Migration
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 */

class Version1Date20251222000000 extends SimpleMigrationStep
{

    /**
     * Database connection
     *
     * @var IDBConnection The database connection.
     */
    private IDBConnection $connection;

    /**
     * Constructor
     *
     * @param IDBConnection $connection The database connection.
     */
    public function __construct(IDBConnection $connection)
    {
        $this->connection = $connection;

    }//end __construct()

    /**
     * Execute data migration after schema changes
     *
     * This method fixes all schemas where the 'required' field is NULL by setting
     * it to an empty JSON array '[]'. This ensures schema validation works correctly
     * during object creation.
     *
     * @param IOutput                 $output        Migration output interface for messages.
     * @param Closure                 $schemaClosure Schema closure that returns ISchemaWrapper.
     * @param array<array-key, mixed> $options       Migration options.
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    {
        // @var ISchemaWrapper $schema
        $schema = $schemaClosure();

        // Check if the table exists before attempting migration.
        if ($schema->hasTable('openregister_schemas') === false) {
            $output->info(message: '   ℹ️  Table openregister_schemas does not exist, skipping migration');
            return;
        }

        $output->info(message: '📋 Fixing NULL required fields in schemas...');

        try {
            // Update all schemas where required is NULL to set it to an empty array.
            // This fixes a bug where schemas created without an explicit required field
            // were stored with NULL instead of [], causing validation errors.
            // phpcs:ignore Generic.Files.LineLength.MaxExceeded -- SQL query clarity.
            $sql = "UPDATE `*PREFIX*openregister_schemas` SET `required` = '[]' WHERE `required` IS NULL";

            $result = $this->connection->executeUpdate($sql);

            if ($result > 0) {
                $output->info(message: "   ✓ Fixed required field for {$result} schemas");
            } else {
                $output->info(message: '   ℹ️  No schemas needed fixing (all had valid required fields)');
            }

            $output->info(message: '✅ Migration completed successfully - all schemas now have valid required fields');
        } catch (\Exception $e) {
            $output->warning(message: '   ⚠️  Error during migration: '.$e->getMessage());
            $output->warning(message: '   ⚠️  This may cause validation errors during object creation');
        }//end try

    }//end postSchemaChange()
}//end class
