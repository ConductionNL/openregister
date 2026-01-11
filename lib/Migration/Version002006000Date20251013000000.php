<?php

/**
 * OpenRegister File Texts Table Migration
 *
 * This migration creates the oc_openregister_file_texts table to store
 * extracted text content from files for fast full-text search in SOLR,
 * text chunking for AI/ML processing, and avoiding repeated file parsing.
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version002006000Date20251013000000 extends SimpleMigrationStep
{
    /**
     * Create file texts table for storing extracted text content.
     *
     * @param IOutput $output        Output interface for logging
     * @param Closure $schemaClosure Schema retrieval closure
     * @param array   $options       Migration options
     *
     * @return null|ISchemaWrapper Modified schema or null
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /*
         * @var ISchemaWrapper $schema
         */

        $schema = $schemaClosure();

        if ($schema->hasTable('openregister_file_texts') === false) {
            $table = $schema->createTable('openregister_file_texts');

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

            // Nextcloud file reference.
            $table->addColumn(
                'file_id',
                'bigint',
                [
                    'notnull'  => true,
                    'length'   => 20,
                    'unsigned' => true,
                    'comment'  => 'Nextcloud file ID from oc_filecache',
                ]
            );

            // File metadata.
            $table->addColumn(
                'file_path',
                'string',
                [
                    'notnull' => true,
                    'length'  => 4000,
                    'comment' => 'Full file path in Nextcloud',
                ]
            );

            $table->addColumn(
                'file_name',
                'string',
                [
                    'notnull' => true,
                    'length'  => 255,
                    'comment' => 'File name with extension',
                ]
            );

            $table->addColumn(
                'mime_type',
                'string',
                [
                    'notnull' => true,
                    'length'  => 255,
                    'comment' => 'MIME type (application/pdf, text/plain, etc.)',
                ]
            );

            $table->addColumn(
                'file_size',
                'bigint',
                [
                    'notnull'  => true,
                    'length'   => 20,
                    'unsigned' => true,
                    'comment'  => 'File size in bytes',
                ]
            );

            $table->addColumn(
                'file_checksum',
                'string',
                [
                    'notnull' => false,
                    'length'  => 64,
                    'comment' => 'File checksum for change detection',
                ]
            );

            // Extracted text content.
            $table->addColumn(
                'text_content',
                'text',
                [
                    'notnull' => false,
                    'length'  => 16777215,
                // MEDIUMTEXT (16MB).
                    'comment' => 'Extracted text content from file',
                ]
            );

            $table->addColumn(
                'text_length',
                'integer',
                [
                    'notnull'  => true,
                    'default'  => 0,
                    'unsigned' => true,
                    'comment'  => 'Length of extracted text in characters',
                ]
            );

            // Extraction metadata.
            $table->addColumn(
                'extraction_method',
                'string',
                [
                    'notnull' => true,
                    'length'  => 50,
                    'default' => 'text_extract',
                    'comment' => 'Method used: text_extract, ocr, tika, api',
                ]
            );

            $table->addColumn(
                'extraction_status',
                'string',
                [
                    'notnull' => true,
                    'length'  => 20,
                    'default' => 'pending',
                    'comment' => 'Status: pending, processing, completed, failed, skipped',
                ]
            );

            $table->addColumn(
                'extraction_error',
                'text',
                [
                    'notnull' => false,
                    'comment' => 'Error message if extraction failed',
                ]
            );

            // Processing flags.
            $table->addColumn(
                'chunked',
                'boolean',
                [
                    'notnull' => true,
                    'default' => false,
                    'comment' => 'Whether text has been chunked',
                ]
            );

            $table->addColumn(
                'chunk_count',
                'integer',
                [
                    'notnull'  => true,
                    'default'  => 0,
                    'unsigned' => true,
                    'comment'  => 'Number of chunks created',
                ]
            );

            $table->addColumn(
                'indexed_in_solr',
                'boolean',
                [
                    'notnull' => true,
                    'default' => false,
                    'comment' => 'Whether text has been indexed in SOLR',
                ]
            );

            $table->addColumn(
                'vectorized',
                'boolean',
                [
                    'notnull' => true,
                    'default' => false,
                    'comment' => 'Whether text has been vectorized for semantic search',
                ]
            );

            // Timestamps.
            $table->addColumn(
                'created_at',
                'datetime',
                [
                    'notnull' => true,
                    'comment' => 'When record was created',
                ]
            );

            $table->addColumn(
                'updated_at',
                'datetime',
                [
                    'notnull' => true,
                    'comment' => 'When record was last updated',
                ]
            );

            $table->addColumn(
                'extracted_at',
                'datetime',
                [
                    'notnull' => false,
                    'comment' => 'When text extraction completed',
                ]
            );

            // Set primary key.
            $table->setPrimaryKey(['id']);

            // Create indexes for performance.
            $table->addIndex(['file_id'], 'file_texts_file_id_idx');
            $table->addIndex(['extraction_status'], 'file_texts_status_idx');
            $table->addIndex(['mime_type'], 'file_texts_mime_idx');
            $table->addIndex(['indexed_in_solr'], 'file_texts_solr_idx');
            $table->addIndex(['vectorized'], 'file_texts_vector_idx');
            $table->addIndex(['created_at'], 'file_texts_created_idx');

            // Unique constraint on file_id.
            $table->addUniqueIndex(['file_id'], 'file_texts_file_id_unique');
        }//end if

        return $schema;
    }//end changeSchema()

    /**
     * Post-schema change hook for logging.
     *
     * @param IOutput $output        Output interface for logging
     * @param Closure $schemaClosure Schema retrieval closure
     * @param array   $options       Migration options
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    {
        $output->info(message: 'File texts table created successfully');
    }//end postSchemaChange()
}//end class
