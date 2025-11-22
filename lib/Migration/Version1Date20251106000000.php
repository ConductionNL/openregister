<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
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
     * @param  IOutput $output
     * @param  Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
     * @param  array   $options
     *
     * @return null|ISchemaWrapper
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /*
         * @var ISchemaWrapper $schema
         */
        $schema  = $schemaClosure();
        $updated = false;

        // Fix openregister_agents table.
        if ($schema->hasTable('openregister_agents')) {
            $table = $schema->getTable('openregister_agents');
            if ($table->hasColumn('organisation')) {
                $column = $table->getColumn('organisation');

                // Change from BIGINT to VARCHAR(36) for UUID.
                $column->setType(\Doctrine\DBAL\Types\Type::getType(Types::STRING));
                $column->setLength(36);
                $column->setNotnull(false);
                $column->setDefault(null);
                $column->setComment('Organisation UUID for multi-tenancy');

                $output->info(message: ('✅ Updated openregister_agents.organisation to VARCHAR(36)');
                $updated = true;
            }
        }

        // Fix openregister_applications table.
        if ($schema->hasTable('openregister_applications')) {
            $table = $schema->getTable('openregister_applications');
            if ($table->hasColumn('organisation')) {
                $column = $table->getColumn('organisation');

                // Change from BIGINT to VARCHAR(36) for UUID.
                $column->setType(\Doctrine\DBAL\Types\Type::getType(Types::STRING));
                $column->setLength(36);
                $column->setNotnull(false);
                $column->setDefault(null);
                $column->setComment('Organisation UUID for multi-tenancy');

                $output->info(message: ('✅ Updated openregister_applications.organisation to VARCHAR(36)');
                $updated = true;
            }
        }

        // Fix openregister_views table (if it has BIGINT).
        if ($schema->hasTable('openregister_views')) {
            $table = $schema->getTable('openregister_views');
            if ($table->hasColumn('organisation')) {
                $column = $table->getColumn('organisation');

                // Ensure it's VARCHAR(36) for UUID.
                if ($column->getLength() !== 36) {
                    $column->setType(\Doctrine\DBAL\Types\Type::getType(Types::STRING));
                    $column->setLength(36);
                    $column->setNotnull(false);
                    $column->setDefault(null);
                    $column->setComment('Organisation UUID for multi-tenancy');

                    $output->info(message: ('✅ Updated openregister_views.organisation to VARCHAR(36)');
                    $updated = true;
                }
            }
        }

        // Fix openregister_sources table (if it has BIGINT).
        if ($schema->hasTable('openregister_sources')) {
            $table = $schema->getTable('openregister_sources');
            if ($table->hasColumn('organisation')) {
                $column = $table->getColumn('organisation');

                // Ensure it's VARCHAR(36) for UUID.
                if ($column->getLength() !== 36) {
                    $column->setType(\Doctrine\DBAL\Types\Type::getType(Types::STRING));
                    $column->setLength(36);
                    $column->setNotnull(false);
                    $column->setDefault(null);
                    $column->setComment('Organisation UUID for multi-tenancy');

                    $output->info(message: ('✅ Updated openregister_sources.organisation to VARCHAR(36)');
                    $updated = true;
                }
            }
        }

        // Fix openregister_configurations table (add organisation column if missing).
        if ($schema->hasTable('openregister_configurations')) {
            $table = $schema->getTable('openregister_configurations');

            if (!$table->hasColumn('organisation')) {
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

                $output->info(message: ('✅ Added openregister_configurations.organisation column (VARCHAR(36))');
                $updated = true;
            } else {
                // Ensure existing column is VARCHAR(36) for UUID.
                $column = $table->getColumn('organisation');
                $column->setType(\Doctrine\DBAL\Types\Type::getType(Types::STRING));
                $column->setLength(36);
                $column->setNotnull(false);
                $column->setDefault(null);
                $column->setComment('Organisation UUID for multi-tenancy');

                $output->info(message: ('✅ Updated openregister_configurations.organisation to VARCHAR(36)');
                $updated = true;
            }//end if
        }//end if

        return $updated ? $schema : null;

    }//end changeSchema()


}//end class
