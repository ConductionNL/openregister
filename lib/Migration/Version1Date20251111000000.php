<?php

declare(strict_types=1);

/**
 * Vector Embedding Model Tracking Migration
 *
 * This migration adds embedding_model column to track which model was used
 * to generate each vector. This is critical for detecting when embedding models
 * change and vectors need to be regenerated.
 *
 * Changes:
 * - openregister_vectors: ADD embedding_model column (string, nullable)
 * - openregister_vectors: ADD index on embedding_model for filtering
 *
 * Use Cases:
 * - Detect when embedding model has changed
 * - Warn users that vectors need regeneration
 * - Filter vectors by model for selective deletion
 * - Track model usage and migration status
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author   Conduction Development Team <dev@conduction.nl>
 * @copyright 2025 Conduction B.V.
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
 * Migration to add embedding model tracking to vectors
 *
 * When embedding models change, all existing vectors become invalid
 * because they were created with different model weights/dimensions.
 * This migration adds tracking to detect and manage model changes.
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 */
class Version1Date20251111000000 extends SimpleMigrationStep
{

    /**
     * Add embedding_model column to vectors table
     *
     * @param IOutput $output Migration output interface
     * @param Closure $schemaClosure Schema closure
     * @param array   $options Migration options
     *
     * @return ISchemaWrapper|null Updated schema or null if no changes
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        $updated = false;

        $output->info('🏗️  Adding embedding model tracking to vectors...');

        // ============================================================
        // Add embedding_model to openregister_vectors
        // ============================================================
        if ($schema->hasTable('openregister_vectors')) {
            $table = $schema->getTable('openregister_vectors');
            
            if (!$table->hasColumn('embedding_model')) {
                $output->info('  📝 Adding vectors.embedding_model column');
                
                $table->addColumn('embedding_model', Types::STRING, [
                    'notnull' => false,
                    'length'  => 255,
                    'default' => null,
                    'comment' => 'Embedding model used to generate this vector (e.g., text-embedding-ada-002, nomic-embed-text)',
                ]);
                
                $output->info('    ✅ vectors.embedding_model column added');
                $updated = true;
            } else {
                $output->info('  ℹ️  vectors.embedding_model column already exists');
            }
            
            // Add index for filtering by model
            if (!$table->hasIndex('embedding_model_idx')) {
                $output->info('  📝 Adding index on embedding_model column');
                
                $table->addIndex(['embedding_model'], 'embedding_model_idx');
                
                $output->info('    ✅ Index on embedding_model column added');
                $updated = true;
            } else {
                $output->info('  ℹ️  Index on embedding_model column already exists');
            }
        } else {
            $output->warning('  ⚠️  vectors table not found - skipping model tracking migration');
        }

        if ($updated) {
            $output->info('');
            $output->info('🎉 Embedding model tracking added successfully!');
            $output->info('');
            $output->info('📊 Summary:');
            $output->info('   • embedding_model column added to vectors table');
            $output->info('   • Index created for efficient model filtering');
            $output->info('');
            $output->info('✨ Features enabled:');
            $output->info('   • Track which model generated each vector');
            $output->info('   • Detect when embedding model changes');
            $output->info('   • Warn users to regenerate vectors after model change');
            $output->info('   • Selectively delete vectors by model');
            $output->info('');
            $output->info('⚠️  IMPORTANT:');
            $output->info('   • Existing vectors have NULL embedding_model');
            $output->info('   • New vectors will track their model automatically');
            $output->info('   • If you change embedding models, DELETE ALL VECTORS and re-vectorize');
            $output->info('');
        } else {
            $output->info('');
            $output->info('ℹ️  No changes needed - embedding model tracking already configured');
        }

        return $updated === true ? $schema : null;

    }//end changeSchema()


    /**
     * Post-schema change operations
     *
     * @param IOutput $output Migration output interface
     * @param Closure $schemaClosure Schema closure
     * @param array   $options Migration options
     *
     * @return void
     */
    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    {
        $output->info('');
        $output->info('📖 Migration Notes:');
        $output->info('   • All new vectors will automatically track their embedding model');
        $output->info('   • Existing vectors (NULL model) are assumed to use current config');
        $output->info('   • System will warn if model changes and vectors exist');
        $output->info('   • Use "Clear All Embeddings" action to remove vectors after model change');
        $output->info('');
        $output->info('✅ Migration completed successfully');

    }//end postSchemaChange()


}//end class





