<?php

/**
 * Migration to convert organisation columns from BIGINT to STRING (UUID)
 *
 * Fixes the organisation column in all tables to use UUID strings instead of integer IDs
 * for proper multi-tenancy support.
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
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Migration to convert organisation columns from BIGINT to STRING (UUID)
 *
 * Fixes the organisation column in all tables to use UUID strings instead of integer IDs
 * for proper multi-tenancy support.
 */
class Version1Date20251106000000 extends SimpleMigrationStep
{
    /**
     * Apply schema changes.
     *
     * @param IOutput                   $output        Output interface.
     * @param Closure(): ISchemaWrapper $schemaClosure Schema closure.
     * @param array                     $options       Migration options.
     *
     * @return null|ISchemaWrapper The modified schema.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     * @SuppressWarnings(PHPMD.StaticAccess)          Type::getType is standard Doctrine DBAL pattern
     * @SuppressWarnings(PHPMD.NPathComplexity)       Database migration requires checking many columns
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /*
         * @var ISchemaWrapper $schema
         */

        $schema  = $schemaClosure();
        $updated = false;

        // Fix openregister_agents table.
        if ($schema->hasTable('openregister_agents') === true) {
            $table = $schema->getTable('openregister_agents');
            if ($table->hasColumn('organisation') === true) {
                $column = $table->getColumn('organisation');

                // Change from BIGINT to VARCHAR(36) for UUID.
                $column->setType(Type::getType(Types::STRING));
                $column->setLength(36);
                $column->setNotnull(false);
                $column->setDefault(null);
                $column->setComment('Organisation UUID for multi-tenancy');

                $output->info(message: '✅ Updated openregister_agents.organisation to VARCHAR(36)');
                $updated = true;
            }
        }

        // Fix openregister_applications table.
        if ($schema->hasTable('openregister_applications') === true) {
            $table = $schema->getTable('openregister_applications');
            if ($table->hasColumn('organisation') === true) {
                $column = $table->getColumn('organisation');

                // Change from BIGINT to VARCHAR(36) for UUID.
                $column->setType(Type::getType(Types::STRING));
                $column->setLength(36);
                $column->setNotnull(false);
                $column->setDefault(null);
                $column->setComment('Organisation UUID for multi-tenancy');

                $output->info(message: '✅ Updated openregister_applications.organisation to VARCHAR(36)');
                $updated = true;
            }
        }

        // Fix openregister_views table (if it has BIGINT).
        if ($schema->hasTable('openregister_views') === true) {
            $table = $schema->getTable('openregister_views');
            if ($table->hasColumn('organisation') === true) {
                $column = $table->getColumn('organisation');

                // Ensure it's VARCHAR(36) for UUID.
                if ($column->getLength() !== 36) {
                    $column->setType(Type::getType(Types::STRING));
                    $column->setLength(36);
                    $column->setNotnull(false);
                    $column->setDefault(null);
                    $column->setComment('Organisation UUID for multi-tenancy');

                    $output->info(message: '✅ Updated openregister_views.organisation to VARCHAR(36)');
                    $updated = true;
                }
            }
        }

        // Fix openregister_sources table (if it has BIGINT).
        if ($schema->hasTable('openregister_sources') === true) {
            $table = $schema->getTable('openregister_sources');
            if ($table->hasColumn('organisation') === true) {
                $column = $table->getColumn('organisation');

                // Ensure it's VARCHAR(36) for UUID.
                if ($column->getLength() !== 36) {
                    $column->setType(Type::getType(Types::STRING));
                    $column->setLength(36);
                    $column->setNotnull(false);
                    $column->setDefault(null);
                    $column->setComment('Organisation UUID for multi-tenancy');

                    $output->info(message: '✅ Updated openregister_sources.organisation to VARCHAR(36)');
                    $updated = true;
                }
            }
        }

        // Fix openregister_configurations table (add organisation column if missing).
        if ($schema->hasTable('openregister_configurations') === true) {
            $table = $schema->getTable('openregister_configurations');

            if ($table->hasColumn('organisation') === false) {
                // Add organisation column if it doesn't exist.
                $table->addColumn(
                    'organisation',
                    Types::STRING,
                    [
                        'notnull' => false,
                        'length'  => 36,
                        'default' => null,
                        'comment' => 'Organisation UUID for multi-tenancy',
                    ]
                );

                $output->info(message: '✅ Added openregister_configurations.organisation column (VARCHAR(36))');
                $updated = true;
            }

            if ($table->hasColumn('organisation') === true) {
                // Ensure existing column is VARCHAR(36) for UUID.
                $column = $table->getColumn('organisation');
                $column->setType(\Doctrine\DBAL\Types\Type::getType(Types::STRING));
                $column->setLength(36);
                $column->setNotnull(false);
                $column->setDefault(null);
                $column->setComment('Organisation UUID for multi-tenancy');

                $output->info(message: '✅ Updated openregister_configurations.organisation to VARCHAR(36)');
                $updated = true;
            }//end if
        }//end if

        if ($updated === false) {
            return null;
        }

        return $schema;
    }//end changeSchema()
}//end class
