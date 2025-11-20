<?php

declare(strict_types=1);

/*
 * OpenRegister Schema Composition Migration
 *
 * This migration adds 'allOf', 'oneOf', and 'anyOf' columns to the schemas table
 * to support proper JSON Schema composition patterns conforming to the specification.
 * This replaces the single 'extend' pattern with standards-compliant schema composition.
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

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Migration to add allOf, oneOf, anyOf columns to schemas table
 *
 * Adds support for JSON Schema composition patterns:
 * - allOf: Instance must validate against ALL schemas (multiple inheritance)
 * - oneOf: Instance must validate against EXACTLY ONE schema
 * - anyOf: Instance must validate against AT LEAST ONE schema
 *
 * This follows the Liskov Substitution Principle where extended schemas
 * can only add constraints, not relax them. Metadata (title, description, order)
 * can be overridden without affecting validation.
 */
class Version1Date20251114120000 extends SimpleMigrationStep
{


    /**
     * Add allOf, oneOf, anyOf columns to schemas table
     *
     * @param IOutput $output        Migration output interface
     * @param Closure $schemaClosure Schema closure
     * @param array   $options       Migration options
     *
     * @return ISchemaWrapper|null Updated schema
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /*
         *
         *
         * @var ISchemaWrapper $schema
         */
        $schema = $schemaClosure();

        $output->info('🔧 Adding JSON Schema composition support...');

        // Add allOf, oneOf, anyOf fields to schemas table
        if ($schema->hasTable('openregister_schemas')) {
            $table = $schema->getTable('openregister_schemas');

            // Add allOf field (array of schema identifiers - must validate against ALL)
            if (!$table->hasColumn('all_of')) {
                $table->addColumn(
                        'all_of',
                        Types::TEXT,
                        [
                            'notnull' => false,
                            'default' => null,
                            'comment' => 'JSON array of schema IDs/UUIDs/slugs - instance must validate against ALL',
                        ]
                        );

                $output->info('   ✓ Added all_of column to schemas table');
            } else {
                $output->info('   ⚠️  all_of column already exists');
            }

            // Add oneOf field (array of schema identifiers - must validate against EXACTLY ONE)
            if (!$table->hasColumn('one_of')) {
                $table->addColumn(
                        'one_of',
                        Types::TEXT,
                        [
                            'notnull' => false,
                            'default' => null,
                            'comment' => 'JSON array of schema IDs/UUIDs/slugs - instance must validate against EXACTLY ONE',
                        ]
                        );

                $output->info('   ✓ Added one_of column to schemas table');
            } else {
                $output->info('   ⚠️  one_of column already exists');
            }

            // Add anyOf field (array of schema identifiers - must validate against AT LEAST ONE)
            if (!$table->hasColumn('any_of')) {
                $table->addColumn(
                        'any_of',
                        Types::TEXT,
                        [
                            'notnull' => false,
                            'default' => null,
                            'comment' => 'JSON array of schema IDs/UUIDs/slugs - instance must validate against AT LEAST ONE',
                        ]
                        );

                $output->info('   ✓ Added any_of column to schemas table');
            } else {
                $output->info('   ⚠️  any_of column already exists');
            }

            $output->info('✅ JSON Schema composition support added successfully');
            $output->info('🎯 Features enabled:');
            $output->info('   • allOf: Multiple inheritance/composition (validate against ALL)');
            $output->info('   • oneOf: Mutually exclusive options (validate against EXACTLY ONE)');
            $output->info('   • anyOf: Flexible composition (validate against AT LEAST ONE)');
            $output->info('   • Liskov Substitution Principle enforcement');
            $output->info('   • Metadata override support (title, description, order)');
            $output->info('📚 See: https://json-schema.org/understanding-json-schema/reference/combining');
        } else {
            $output->info('⚠️  Schemas table does not exist!');
        }//end if

        return $schema;

    }//end changeSchema()


}//end class
