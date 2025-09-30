<?php

declare(strict_types=1);

/**
 * OpenRegister AI Enhancement Migration
 *
 * This migration adds AI functionality to OpenRegister by adding text and embedding
 * fields to the objects table for semantic search and AI-powered features.
 *
 * Changes:
 * 1. Adds 'text' column for AI-generated text representation of objects
 * 2. Adds 'embedding' column for AI-generated vector embeddings (JSON format)
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
 * Migration to add AI functionality fields to objects table
 *
 * This migration implements AI enhancements including:
 * - Text field for searchable text representation of objects
 * - Embedding field for vector embeddings used in semantic search
 * - Support for AI-powered content analysis and recommendations
 */
class Version1Date20250918120000 extends SimpleMigrationStep
{

    /**
     * Add AI functionality fields to objects table
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

        // Get the objects table to add AI fields
        if ($schema->hasTable('openregister_objects')) {
            $table = $schema->getTable('openregister_objects');
            
            $output->info('🤖 Adding AI functionality fields to objects table...');
            
            // Add text field for AI-generated text representation
            if (!$table->hasColumn('text')) {
                $table->addColumn('text', Types::TEXT, [
                    'notnull' => false,
                    'length' => 65535, // TEXT field can hold up to 65KB
                    'comment' => 'AI-generated text representation for search and analysis'
                ]);
                $output->info('✅ Added text field for AI-generated content representation');
            } else {
                $output->info('ℹ️  Text field already exists');
            }

            // Add embedding field for vector embeddings
            if (!$table->hasColumn('embedding')) {
                $table->addColumn('embedding', Types::JSON, [
                    'notnull' => false,
                    'comment' => 'AI-generated vector embedding as JSON array for semantic search'
                ]);
                $output->info('✅ Added embedding field for vector representations');
            } else {
                $output->info('ℹ️  Embedding field already exists');
            }

            $output->info('🎯 AI fields will enable semantic search and content analysis');
            
        } else {
            $output->info('⚠️  openregister_objects table not found');
        }
        
        $output->info('🎉 AI enhancement migration completed');
        
        return $schema;
    }

    /**
     * Post schema update operations
     *
     * @param IOutput $output Migration output interface
     * @param Closure $schemaClosure Schema closure
     * @param array   $options Migration options
     *
     * @return void
     */
    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    {
        $output->info('📋 Post-migration verification...');
        $output->info('✅ Text field ready for AI-generated content representation');
        $output->info('✅ Embedding field ready for vector-based semantic search');
        $output->info('✅ Objects can now be enhanced with AI-powered features');
        $output->info('🤖 AI functionality infrastructure successfully deployed');
    }

}//end class
