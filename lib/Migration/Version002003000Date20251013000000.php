<?php

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Create oc_openregister_vectors table for storing vector embeddings
 *
 * This migration adds support for semantic search by storing vector embeddings
 * for both objects and file chunks. Vectors enable similarity search and
 * LLM integration via RAG (Retrieval Augmented Generation).
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 */
class Version002003000Date20251013000000 extends SimpleMigrationStep
{
    /**
     * Database schema changes
     *
     * @param IOutput $output        Output interface for logging
     * @param Closure $schemaClosure Schema retrieval closure
     * @param array   $options       Migration options
     *
     * @return ISchemaWrapper|null Modified schema or null
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        $schema = $schemaClosure();

        // Check if table already exists.
        if ($schema->hasTable('openregister_vectors') === false) {
            $table = $schema->createTable('openregister_vectors');

            // Primary key.
            $table->addColumn(
                'id',
                'bigint',
                [
                    'autoincrement' => true,
                    'notnull'       => true,
                    'length'        => 20,
                    'unsigned'      => true,
                ]
            );

            // Entity information.
            $table->addColumn(
                'entity_type',
                'string',
                [
                    'notnull' => true,
                    'length'  => 50,
                    'comment' => 'Type of entity: object or file',
                ]
            );

            $table->addColumn(
                'entity_id',
                'string',
                [
                    'notnull' => true,
                    'length'  => 255,
                    'comment' => 'UUID of the object or file',
                ]
            );

            // Chunk information (for files).
            $table->addColumn(
                'chunk_index',
                'integer',
                [
                    'notnull' => true,
                    'default' => 0,
                    'comment' => '0 for objects, N for file chunks',
                ]
            );

            $table->addColumn(
                'total_chunks',
                'integer',
                [
                    'notnull' => true,
                    'default' => 1,
                    'comment' => '1 for objects, N for files',
                ]
            );

            $table->addColumn(
                'chunk_text',
                'text',
                [
                    'notnull' => false,
                    'comment' => 'The text that was embedded (for reference and debugging)',
                ]
            );

            // Vector data.
            $table->addColumn(
                'embedding',
                'blob',
                [
                    'notnull' => true,
                    'comment' => 'Binary vector data (serialized array or binary format)',
                ]
            );

            $table->addColumn(
                'embedding_model',
                'string',
                [
                    'notnull' => true,
                    'length'  => 100,
                    'comment' => 'Model used to generate embeddings (e.g., text-embedding-ada-002)',
                ]
            );

            $table->addColumn(
                'embedding_dimensions',
                'integer',
                [
                    'notnull' => true,
                    'comment' => 'Number of dimensions in the vector (e.g., 1536 for OpenAI ada-002)',
                ]
            );

            // Metadata.
            $table->addColumn(
                'metadata',
                'text',
                [
                    'notnull' => false,
                    'comment' => 'Additional metadata as JSON',
                ]
            );

            // Timestamps.
            $table->addColumn(
                'created_at',
                'datetime',
                [
                    'notnull' => true,
                    'default' => 'CURRENT_TIMESTAMP',
                ]
            );

            $table->addColumn(
                'updated_at',
                'datetime',
                [
                    'notnull' => true,
                    'default' => 'CURRENT_TIMESTAMP',
                ]
            );

            // Set primary key.
            $table->setPrimaryKey(['id']);

            // Indexes for performance.
            $table->addIndex(['entity_type', 'entity_id'], 'openreg_vec_entity_idx');
            $table->addIndex(['entity_id', 'chunk_index'], 'openreg_vec_chunk_idx');
            $table->addIndex(['embedding_model'], 'openreg_vec_model_idx');
            $table->addIndex(['created_at'], 'openreg_vec_created_idx');

            $output->info(message: 'Created table openregister_vectors for vector embeddings');

            return $schema;
        }//end if

        $output->info(message: 'Table openregister_vectors already exists');
        return null;
    }//end changeSchema()
}//end class
