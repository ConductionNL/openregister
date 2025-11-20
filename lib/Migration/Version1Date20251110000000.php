<?php

declare(strict_types=1);

/*
 * Organisation Hierarchy Migration
 *
 * This migration adds parent-child relationship support to organisations,
 * enabling hierarchical organisation structures where child organisations
 * can inherit access to parent organisation resources.
 *
 * Changes:
 * - openregister_organisations: ADD parent column (string UUID, nullable)
 * - openregister_organisations: ADD foreign key constraint to self (parent -> uuid)
 * - openregister_organisations: ADD index on parent column
 *
 * Use Cases:
 * - VNG (parent) → Gemeenten (children)
 * - Gemeente (parent) → Deelgemeenten (children)
 * - Multi-level hierarchies (max 10 levels)
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

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Migration to add organisation hierarchy support
 *
 * Adds parent column to enable parent-child relationships between organisations.
 * Children inherit access to parent resources (schemas, registers, etc.).
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 */
class Version1Date20251110000000 extends SimpleMigrationStep
{


    /**
     * Add parent column and constraints to organisations table
     *
     * @param IOutput $output        Migration output interface
     * @param Closure $schemaClosure Schema closure
     * @param array   $options       Migration options
     *
     * @return ISchemaWrapper|null Updated schema or null if no changes
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /*
         * @var ISchemaWrapper $schema
         */
        $schema  = $schemaClosure();
        $updated = false;

        $output->info('🏗️  Adding organisation hierarchy support...');

        // ============================================================.
        // Add parent column to openregister_organisations.
        // ============================================================.
        if ($schema->hasTable('openregister_organisations')) {
            $table = $schema->getTable('openregister_organisations');

            if (!$table->hasColumn('parent')) {
                $output->info('  📝 Adding organisations.parent column for hierarchy support');

                $table->addColumn(
                        'parent',
                        Types::STRING,
                        [
                            'notnull' => false,
                            'length'  => 255,
                            'default' => null,
                            'comment' => 'Parent organisation UUID for hierarchical relationships',
                        ]
                        );

                $output->info('    ✅ organisations.parent column added');
                $updated = true;
            } else {
                $output->info('  ℹ️  organisations.parent column already exists');
            }

            // Add index for fast parent lookups (used in recursive queries).
            if (!$table->hasIndex('parent_organisation_idx')) {
                $output->info('  📝 Adding index on parent column');

                $table->addIndex(['parent'], 'parent_organisation_idx');

                $output->info('    ✅ Index on parent column added');
                $updated = true;
            } else {
                $output->info('  ℹ️  Index on parent column already exists');
            }
        } else {
            $output->warning('  ⚠️  organisations table not found - skipping hierarchy migration');
        }//end if

        if ($updated === true) {
            $output->info('');
            $output->info('🎉 Organisation hierarchy support added successfully!');
            $output->info('');
            $output->info('📊 Summary:');
            $output->info('   • Parent column added to organisations table');
            $output->info('   • Index created for efficient parent lookups');
            $output->info('   • Foreign key constraint will be handled at application level');
            $output->info('');
            $output->info('✨ Features enabled:');
            $output->info('   • Parent-child organisation relationships');
            $output->info('   • Children inherit parent resource access');
            $output->info('   • Recursive parent chain lookups');
            $output->info('   • Support for multi-level hierarchies (max 10 levels)');
            $output->info('');
            $output->info('📖 Use Case Example:');
            $output->info('   VNG (root) → Amsterdam → Deelgemeente Noord');
            $output->info('   → Noord sees schemas from Amsterdam and VNG');
            $output->info('');
        } else {
            $output->info('');
            $output->info('ℹ️  No changes needed - organisation hierarchy already configured');
        }//end if

        return $updated === true ? $schema : null;

    }//end changeSchema()


    /**
     * Post-schema change operations
     *
     * Note: Foreign key constraints are intentionally NOT added at database level
     * because Nextcloud's database abstraction layer has limitations with
     * self-referencing foreign keys. The constraint is enforced at application
     * level in OrganisationMapper::validateParentAssignment().
     *
     * @param IOutput $output        Migration output interface
     * @param Closure $schemaClosure Schema closure
     * @param array   $options       Migration options
     *
     * @return void
     */
    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    {
        $output->info('');
        $output->info('ℹ️  Post-migration notes:');
        $output->info('   • Foreign key constraint enforced at application level');
        $output->info('   • Circular reference prevention: max depth 10 levels');
        $output->info('   • If parent organisation is deleted, parent field will be set to NULL');
        $output->info('   • All existing organisations have parent = NULL (no hierarchy)');
        $output->info('');
        $output->info('✅ Migration completed successfully');

    }//end postSchemaChange()


}//end class
