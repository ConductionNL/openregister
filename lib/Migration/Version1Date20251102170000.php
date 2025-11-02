<?php

declare(strict_types=1);

/**
 * OpenRegister Schema Extension Migration
 *
 * This migration adds the 'extend' column to the schemas table to support
 * schema inheritance/extension functionality.
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author   Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version  GIT: <git_id>
 *
 * @link     https://www.OpenRegister.nl
 */

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Migration to add extend column to schemas table
 *
 * Adds support for schema inheritance by allowing schemas to extend other schemas.
 * The extend column stores the ID, UUID, or slug of the parent schema.
 */
class Version1Date20251102170000 extends SimpleMigrationStep
{

    /**
     * Add extend column to schemas table
     *
     * @param IOutput $output Migration output interface
     * @param Closure $schemaClosure Schema closure
     * @param array   $options Migration options
     *
     * @return ISchemaWrapper|null Updated schema
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        $output->info('🔧 Adding schema extension support...');

        // Add extend field to schemas table
        if ($schema->hasTable('openregister_schemas')) {
            $table = $schema->getTable('openregister_schemas');
            
            // Add extend field (stores parent schema identifier)
            if (!$table->hasColumn('extend')) {
                $table->addColumn('extend', Types::STRING, [
                    'notnull' => false,
                    'length' => 255,
                    'default' => null,
                    'comment' => 'ID, UUID, or slug of the parent schema that this schema extends'
                ]);
                
                // Add index for faster lookups of child schemas
                $table->addIndex(['extend'], 'schemas_extend_index');
                
                $output->info('   ✓ Added extend column to schemas table');
                $output->info('   ✓ Added index for extend lookups');
                $output->info('✅ Schema extension support added successfully');
                $output->info('🎯 Features enabled:');
                $output->info('   • Schema inheritance/extension');
                $output->info('   • Delta storage (only differences stored)');
                $output->info('   • Automatic property merging on retrieval');
                $output->info('   • Multi-level inheritance support');
            } else {
                $output->info('⚠️  Extend column already exists in schemas table');
            }
        } else {
            $output->info('⚠️  Schemas table does not exist!');
        }

        return $schema;

    }//end changeSchema()


}//end class

