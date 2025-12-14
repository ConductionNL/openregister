<?php

declare(strict_types=1);

/*
 * OpenRegister Schema and Register Publication Fields Migration
 *
 * This migration adds published and depublished columns to schemas and registers tables
 * to support publication-based multi-tenancy bypass functionality.
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2025 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Migration to add publication fields to schemas and registers tables
 *
 * Adds support for:
 * - Publication timestamps for schemas and registers
 * - Depublication timestamps for schemas and registers
 * - Publication-based multi-tenancy bypass (published entities can bypass org restrictions)
 *
 * This enables schemas and registers to be published and made accessible across
 * organization boundaries, similar to how objects already support this feature.
 */
class Version1Date20251202000000 extends SimpleMigrationStep
{


    /**
     * Add publication fields to schemas and registers tables
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

        $output->info('🔧 Adding publication fields to schemas and registers tables...');

        // Add columns to schemas table.
        if ($schema->hasTable('openregister_schemas') === true) {
            $schemasTable = $schema->getTable('openregister_schemas');

            // Add published field (datetime) - publication timestamp.
            if ($schemasTable->hasColumn('published') === false) {
                $schemasTable->addColumn(
                        'published',
                        Types::DATETIME,
                        [
                            'notnull' => false,
                            'default' => null,
                            'comment' => 'Publication timestamp. When set, schema becomes publicly accessible regardless of organisation restrictions if published bypass is enabled.',
                        ]
                        );

                $output->info('   ✓ Added published column to schemas table');
            } else {
                $output->info('   ⚠️  published column already exists in schemas table');
            }

            // Add depublished field (datetime) - depublication timestamp.
            if ($schemasTable->hasColumn('depublished') === false) {
                $schemasTable->addColumn(
                        'depublished',
                        Types::DATETIME,
                        [
                            'notnull' => false,
                            'default' => null,
                            'comment' => 'Depublication timestamp. When set, schema becomes inaccessible after this date/time.',
                        ]
                        );

                $output->info('   ✓ Added depublished column to schemas table');
            } else {
                $output->info('   ⚠️  depublished column already exists in schemas table');
            }
        } else {
            $output->info('⚠️  Schemas table does not exist!');
        }//end if

        // Add columns to registers table.
        if ($schema->hasTable('openregister_registers') === true) {
            $registersTable = $schema->getTable('openregister_registers');

            // Add published field (datetime) - publication timestamp.
            if ($registersTable->hasColumn('published') === false) {
                $registersTable->addColumn(
                        'published',
                        Types::DATETIME,
                        [
                            'notnull' => false,
                            'default' => null,
                            'comment' => 'Publication timestamp. When set, register becomes publicly accessible regardless of organisation restrictions if published bypass is enabled.',
                        ]
                        );

                $output->info('   ✓ Added published column to registers table');
            } else {
                $output->info('   ⚠️  published column already exists in registers table');
            }

            // Add depublished field (datetime) - depublication timestamp.
            if ($registersTable->hasColumn('depublished') === false) {
                $registersTable->addColumn(
                        'depublished',
                        Types::DATETIME,
                        [
                            'notnull' => false,
                            'default' => null,
                            'comment' => 'Depublication timestamp. When set, register becomes inaccessible after this date/time.',
                        ]
                        );

                $output->info('   ✓ Added depublished column to registers table');
            } else {
                $output->info('   ⚠️  depublished column already exists in registers table');
            }
        } else {
            $output->info('⚠️  Registers table does not exist!');
        }//end if

        $output->info('✅ Publication fields added successfully');
        $output->info('🎯 Features enabled:');
        $output->info('   • Publication timestamps for schemas and registers');
        $output->info('   • Depublication timestamps for schemas and registers');
        $output->info('   • Publication-based multi-tenancy bypass support');
        $output->info('   • Consistent publication handling across objects, schemas, and registers');

        return $schema;

    }//end changeSchema()


}//end class
