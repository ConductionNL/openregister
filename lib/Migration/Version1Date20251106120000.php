<?php

/**
 * Multi-Tenancy Organisation UUID Migration
 *
 * This migration ensures all entities have proper organisation UUID columns
 * for multi-tenancy support. It updates existing int columns to string UUID
 * and adds organisation columns where missing.
 *
 * Updated tables:
 * - openregister_configurations: organisation int → string UUID
 * - openregister_agents: organisation int → string UUID
 * - openregister_applications: organisation int → string UUID
 * - openregister_view: ADD organisation string UUID column
 * - openregister_sources: ADD organisation string UUID column
 * - openregister_registers: ADD organisation string UUID column
 * - openregister_schemas: organisation already string UUID (verify only)
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
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
use Doctrine\DBAL\Types\Type;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Migration to implement multi-tenancy organisation UUID support
 *
 * Changes organisation columns from int to string UUID and adds
 * organisation columns to tables that don't have them yet.
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 */

class Version1Date20251106120000 extends SimpleMigrationStep
{
    /**
     * Update organisation columns for multi-tenancy
     *
     * @param IOutput $output        Migration output interface
     * @param Closure $schemaClosure Schema closure
     * @param array   $options       Migration options
     *
     * @return ISchemaWrapper|null Updated schema
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     * @SuppressWarnings(PHPMD.StaticAccess)          Type::getType is standard Doctrine DBAL pattern
     * @SuppressWarnings(PHPMD.NPathComplexity)       Database migration requires checking many columns
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength) Database migration requires many column definitions
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /*
         * @var ISchemaWrapper $schema
         */

        $schema  = $schemaClosure();
        $updated = false;

        $output->info(message: '🏢 Updating organisation columns for multi-tenancy support...');

        // ============================================================.
        // Update openregister_configurations: int → string UUID.
        // ============================================================.
        if ($schema->hasTable('openregister_configurations') === true) {
            $table = $schema->getTable('openregister_configurations');

            if ($table->hasColumn('organisation') === true) {
                $column = $table->getColumn('organisation');
                // Check if it's currently an integer.
                if ($column->getType()->getName() === Types::INTEGER) {
                    $output->info('  📝 Updating configurations.organisation: int → string UUID');

                    // Change column type to string UUID.
                    $column->setType(Type::getType(Types::STRING));
                    $column->setLength(36);
                    $column->setNotnull(false);
                    $column->setDefault(null);
                    $column->setComment('Organisation UUID for multi-tenancy');

                    $output->info(message: '    ✅ configurations.organisation updated');
                    $updated = true;
                }
            }
        }//end if

        // ============================================================.
        // Update openregister_agents: int → string UUID.
        // ============================================================.
        if ($schema->hasTable('openregister_agents') === true) {
            $table = $schema->getTable('openregister_agents');

            if ($table->hasColumn('organisation') === true) {
                $column = $table->getColumn('organisation');
                // Check if it's currently an integer.
                if ($column->getType()->getName() === Types::INTEGER) {
                    $output->info('  📝 Updating agents.organisation: int → string UUID');

                    // Change column type to string UUID.
                    $column->setType(Type::getType(Types::STRING));
                    $column->setLength(255);
                    $column->setNotnull(false);
                    $column->setDefault(null);
                    $column->setComment('Organisation UUID for multi-tenancy');

                    $output->info(message: '    ✅ agents.organisation updated');
                    $updated = true;
                }
            }
        }//end if

        // ============================================================.
        // Update openregister_applications: int → string UUID.
        // ============================================================.
        if ($schema->hasTable('openregister_applications') === true) {
            $table = $schema->getTable('openregister_applications');

            if ($table->hasColumn('organisation') === true) {
                $column = $table->getColumn('organisation');
                // Check if it's currently an integer.
                if ($column->getType()->getName() === Types::INTEGER) {
                    $output->info('  📝 Updating applications.organisation: int → string UUID');

                    // Change column type to string UUID.
                    $column->setType(Type::getType(Types::STRING));
                    $column->setLength(255);
                    $column->setNotnull(false);
                    $column->setDefault(null);
                    $column->setComment('Organisation UUID for multi-tenancy');

                    $output->info(message: '    ✅ applications.organisation updated');
                    $updated = true;
                }
            }
        }//end if

        // ============================================================.
        // Add openregister_view.organisation column (table name is singular).
        // ============================================================.
        if ($schema->hasTable('openregister_view') === true) {
            $table = $schema->getTable('openregister_view');

            if ($table->hasColumn('organisation') === false) {
                $output->info(message: '  📝 Adding view.organisation column');

                $table->addColumn(
                    'organisation',
                    Types::STRING,
                    [
                        'notnull' => false,
                        'length'  => 255,
                        'default' => null,
                        'comment' => 'Organisation UUID for multi-tenancy',
                    ]
                );

                // Add index for faster filtering.
                $table->addIndex(['organisation'], 'view_organisation_idx');

                $output->info(message: '    ✅ view.organisation added');
                $updated = true;
            }
        }//end if

        // ============================================================.
        // Add openregister_sources.organisation column.
        // ============================================================.
        if ($schema->hasTable('openregister_sources') === true) {
            $table = $schema->getTable('openregister_sources');

            if ($table->hasColumn('organisation') === false) {
                $output->info(message: '  📝 Adding sources.organisation column');

                $table->addColumn(
                    'organisation',
                    Types::STRING,
                    [
                        'notnull' => false,
                        'length'  => 255,
                        'default' => null,
                        'comment' => 'Organisation UUID for multi-tenancy',
                    ]
                );

                // Add index for faster filtering.
                $table->addIndex(['organisation'], 'sources_organisation_idx');

                $output->info(message: '    ✅ sources.organisation added');
                $updated = true;
            }
        }//end if

        // ============================================================.
        // Add openregister_registers.organisation column.
        // ============================================================.
        if ($schema->hasTable('openregister_registers') === true) {
            $table = $schema->getTable('openregister_registers');

            if ($table->hasColumn('organisation') === false) {
                $output->info(message: '  📝 Adding registers.organisation column');

                $table->addColumn(
                    'organisation',
                    Types::STRING,
                    [
                        'notnull' => false,
                        'length'  => 255,
                        'default' => null,
                        'comment' => 'Organisation UUID for multi-tenancy',
                    ]
                );

                // Add index for faster filtering.
                $table->addIndex(['organisation'], 'registers_organisation_idx');

                $output->info(message: '    ✅ registers.organisation added');
                $updated = true;
            }
        }//end if

        // ============================================================.
        // Verify openregister_schemas.organisation (should already be string).
        // ============================================================.
        if ($schema->hasTable('openregister_schemas') === true) {
            $table = $schema->getTable('openregister_schemas');

            if ($table->hasColumn('organisation') === true) {
                $column = $table->getColumn('organisation');
                if ($column->getType()->getName() === Types::STRING) {
                    $output->info(message: '  ✅ schemas.organisation already string UUID (no change needed)');
                }

                if ($column->getType()->getName() !== Types::STRING) {
                    // If somehow it's not a string, fix it.
                    $output->info(message: '  📝 Updating schemas.organisation to string UUID');

                    $column->setType(Type::getType(Types::STRING));
                    $column->setLength(255);
                    $column->setNotnull(false);
                    $column->setDefault(null);
                    $column->setComment('Organisation UUID for multi-tenancy');

                    $output->info(message: '    ✅ schemas.organisation updated');
                    $updated = true;
                }
            }
        }//end if

        if ($updated === false) {
            $output->info(message: '');
            $output->info(message: 'ℹ️  No changes needed - all organisation columns already configured correctly');
            return null;
        }

        $output->info(message: '');
        $output->info(message: '🎉 Multi-tenancy organisation columns updated successfully!');
        $output->info('📊 Summary:');
        $output->info('   • Configurations: organisation updated to string UUID');
        $output->info('   • Agents: organisation updated to string UUID');
        $output->info('   • Applications: organisation updated to string UUID');
        $output->info('   • View: organisation column added (string UUID)');
        $output->info('   • Sources: organisation column added (string UUID)');
        $output->info('   • Registers: organisation column added (string UUID)');
        $output->info('   • Schemas: organisation verified as string UUID');
        $output->info(message: '');
        $output->info(message: '✅ All entities now support multi-tenancy with organisation UUIDs');

        return $schema;
    }//end changeSchema()
}//end class
