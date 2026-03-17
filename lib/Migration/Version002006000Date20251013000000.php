<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Migration to create oc_openregister_file_texts table
 * 
 * This table stores extracted text content from files for:
 * - Fast full-text search in SOLR
 * - Text chunking for AI/ML processing
 * - Avoiding repeated file parsing
 * - Supporting large file content (LONGTEXT)
 * 
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 * @author   OpenRegister Team
 * @license  AGPL-3.0-or-later
 */
class Version002006000Date20251013000000 extends SimpleMigrationStep
{
    /**
     * @param IOutput $output
     * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
     * @param array $options
     * @return null|ISchemaWrapper
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('openregister_file_texts')) {
            $table = $schema->createTable('openregister_file_texts');
            
            // Primary key
            $table->addColumn('id', 'bigint', [
                'autoincrement' => true,
                'notnull' => true,
                'length' => 20,
                'unsigned' => true,
            ]);
            
            // Nextcloud file reference
            $table->addColumn('file_id', 'bigint', [
                'notnull' => true,
                'length' => 20,
                'unsigned' => true,
                'comment' => 'Nextcloud file ID from oc_filecache',
            ]);
            
            // File metadata
            $table->addColumn('file_path', 'string', [
                'notnull' => true,
                'length' => 4000,
                'comment' => 'Full file path in Nextcloud',
            ]);
            
            $table->addColumn('file_name', 'string', [
                'notnull' => true,
                'length' => 255,
                'comment' => 'File name with extension',
            ]);
            
            $table->addColumn('mime_type', 'string', [
                'notnull' => true,
                'length' => 255,
                'comment' => 'MIME type (application/pdf, text/plain, etc.)',
            ]);
            
            $table->addColumn('file_size', 'bigint', [
                'notnull' => true,
                'length' => 20,
                'unsigned' => true,
                'comment' => 'File size in bytes',
            ]);
            
            $table->addColumn('file_checksum', 'string', [
                'notnull' => false,
                'length' => 64,
                'comment' => 'File checksum for change detection',
            ]);
            
            // Extracted text content
            $table->addColumn('text_content', 'text', [
                'notnull' => false,
                'length' => 16777215, // MEDIUMTEXT (16MB)
                'comment' => 'Extracted text content from file',
            ]);
            
            $table->addColumn('text_length', 'integer', [
                'notnull' => true,
                'default' => 0,
                'unsigned' => true,
                'comment' => 'Length of extracted text in characters',
            ]);
            
            // Extraction metadata
            $table->addColumn('extraction_method', 'string', [
                'notnull' => true,
                'length' => 50,
                'default' => 'text_extract',
                'comment' => 'Method used: text_extract, ocr, tika, api',
            ]);
            
            $table->addColumn('extraction_status', 'string', [
                'notnull' => true,
                'length' => 20,
                'default' => 'pending',
                'comment' => 'Status: pending, processing, completed, failed, skipped',
            ]);
            
            $table->addColumn('extraction_error', 'text', [
                'notnull' => false,
                'comment' => 'Error message if extraction failed',
            ]);
            
            // Processing flags
            $table->addColumn('chunked', 'boolean', [
                'notnull' => true,
                'default' => false,
                'comment' => 'Whether text has been chunked',
            ]);
            
            $table->addColumn('chunk_count', 'integer', [
                'notnull' => true,
                'default' => 0,
                'unsigned' => true,
                'comment' => 'Number of chunks created',
            ]);
            
            $table->addColumn('indexed_in_solr', 'boolean', [
                'notnull' => true,
                'default' => false,
                'comment' => 'Whether text has been indexed in SOLR',
            ]);
            
            $table->addColumn('vectorized', 'boolean', [
                'notnull' => true,
                'default' => false,
                'comment' => 'Whether text has been vectorized for semantic search',
            ]);
            
            // Timestamps
            $table->addColumn('created_at', 'datetime', [
                'notnull' => true,
                'comment' => 'When record was created',
            ]);
            
            $table->addColumn('updated_at', 'datetime', [
                'notnull' => true,
                'comment' => 'When record was last updated',
            ]);
            
            $table->addColumn('extracted_at', 'datetime', [
                'notnull' => false,
                'comment' => 'When text extraction completed',
            ]);
            
            // Set primary key
            $table->setPrimaryKey(['id']);
            
            // Create indexes for performance
            $table->addIndex(['file_id'], 'file_texts_file_id_idx');
            $table->addIndex(['extraction_status'], 'file_texts_status_idx');
            $table->addIndex(['mime_type'], 'file_texts_mime_idx');
            $table->addIndex(['indexed_in_solr'], 'file_texts_solr_idx');
            $table->addIndex(['vectorized'], 'file_texts_vector_idx');
            $table->addIndex(['created_at'], 'file_texts_created_idx');
            
            // Unique constraint on file_id
            $table->addUniqueIndex(['file_id'], 'file_texts_file_id_unique');
        }

        return $schema;
    }

    /**
     * @param IOutput $output
     * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
     * @param array $options
     */
    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    {
        $output->info('File texts table created successfully');
    }
}

