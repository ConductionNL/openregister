<?php

/**
 * Migration creating the `openregister_files` table.
 *
 * The table holds the OR-side metadata that wraps each Nextcloud
 * filecache row: description, category, labels (file-actions metadata
 * enrichment), locked_by/locked_at/lock_expires (file locking when
 * operators want DB-backed locks instead of cache-backed), and
 * download_count (audit + analytics counter).
 *
 * Foundational fix: `Version1Date20260325120000` was authored against
 * an `openregister_files` table that no migration had ever created;
 * the migration ran as a no-op ("Table openregister_files does not
 * exist, skipping migration"). This change creates the table so that
 * migration's add-column passes can run, and any future read/write
 * paths on description / category / labels / download_count have
 * something to persist against.
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/file-actions/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Creates the `openregister_files` table with the file-actions
 * metadata columns so existing add-column migrations + future read
 * paths have a real table to operate on.
 */
class Version1Date20260502130000 extends SimpleMigrationStep
{
    /**
     * Change the database schema.
     *
     * @param IOutput                 $output        Output for the migration process
     * @param Closure                 $schemaClosure The schema closure
     * @param array<array-key, mixed> $options       Migration options
     *
     * @return null|ISchemaWrapper
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /*
         * @var ISchemaWrapper $schema
         */
        $schema = $schemaClosure();

        if ($schema->hasTable(tableName: 'openregister_files') === true) {
            return null;
        }

        $table = $schema->createTable(tableName: 'openregister_files');

        $table->addColumn(
            name: 'id',
            typeName: Types::BIGINT,
            options: [
                'autoincrement' => true,
                'notnull'       => true,
            ]
        );

        $table->addColumn(
            name: 'file_id',
            typeName: Types::BIGINT,
            options: [
                'notnull' => true,
                'comment' => 'Nextcloud filecache.fileid this row wraps',
            ]
        );

        $table->addColumn(
            name: 'description',
            typeName: Types::TEXT,
            options: [
                'notnull' => false,
                'default' => null,
                'comment' => 'File description for metadata enrichment',
            ]
        );

        $table->addColumn(
            name: 'category',
            typeName: Types::STRING,
            options: [
                'notnull' => false,
                'length'  => 255,
                'default' => null,
                'comment' => 'File category for classification and filtering',
            ]
        );

        $table->addColumn(
            name: 'labels',
            typeName: Types::JSON,
            options: [
                'notnull' => false,
                'default' => null,
                'comment' => 'File labels (JSON array of strings) for tagging',
            ]
        );

        $table->addColumn(
            name: 'locked_by',
            typeName: Types::STRING,
            options: [
                'notnull' => false,
                'length'  => 64,
                'default' => null,
                'comment' => 'User ID who locked the file (DB-backed locks)',
            ]
        );

        $table->addColumn(
            name: 'locked_at',
            typeName: Types::DATETIME,
            options: [
                'notnull' => false,
                'default' => null,
                'comment' => 'Timestamp when the file lock was acquired',
            ]
        );

        $table->addColumn(
            name: 'lock_expires',
            typeName: Types::DATETIME,
            options: [
                'notnull' => false,
                'default' => null,
                'comment' => 'Timestamp when the file lock expires (TTL)',
            ]
        );

        $table->addColumn(
            name: 'download_count',
            typeName: Types::BIGINT,
            options: [
                'notnull' => true,
                'default' => 0,
                'comment' => 'Cached download count for audit and analytics',
            ]
        );

        $table->addColumn(
            name: 'created',
            typeName: Types::DATETIME,
            options: [
                'notnull' => true,
                'comment' => 'Row creation timestamp',
            ]
        );

        $table->addColumn(
            name: 'updated',
            typeName: Types::DATETIME,
            options: [
                'notnull' => false,
                'default' => null,
                'comment' => 'Row last-update timestamp',
            ]
        );

        $table->setPrimaryKey(columnNames: ['id']);
        $table->addUniqueIndex(columnNames: ['file_id'], indexName: 'idx_or_files_file_id');
        $table->addIndex(columnNames: ['category'], indexName: 'idx_or_files_category');
        $table->addIndex(columnNames: ['locked_by'], indexName: 'idx_or_files_locked_by');

        return $schema;

    }//end changeSchema()
}//end class
