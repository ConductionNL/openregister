<?php

/**
 * Database migration that creates the openregister_file_locks table.
 *
 * Part of the file-actions change (openspec/changes/file-actions): file
 * locking (FileLockHandler) needs to persist lock state so a lock survives
 * past the PHP request/worker that created it. `openregister_files` -- the
 * table the file-actions design doc originally proposed adding lock columns
 * to -- was deprecated and dropped in Version1Date20250430083916 and is
 * never recreated, so Version1Date20260325120000's `locked_by`/`locked_at`/
 * `lock_expires` columns are added to a table that no longer exists and are
 * never actually created. This migration gives file locks a real home in a
 * small dedicated table instead.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Creates the `openregister_file_locks` table used by FileLockHandler.
 *
 * @package OCA\OpenRegister\Migration
 *
 * @spec openspec/changes/file-actions/specs/file-actions/spec.md#File-Locking
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
class Version1Date20260814120000 extends SimpleMigrationStep
{
    /**
     * Change the database schema.
     *
     * @param IOutput $output        Migration output
     * @param Closure $schemaClosure Schema closure
     * @param array   $options       Migration options
     *
     * @return ISchemaWrapper|null The updated schema or null if no changes
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        $schema    = $schemaClosure();
        $tableName = 'openregister_file_locks';

        if ($schema->hasTable($tableName) === true) {
            $output->info("Table {$tableName} already exists, skipping");
            return null;
        }

        $table = $schema->createTable($tableName);
        $table->addColumn(
            'id',
            Types::INTEGER,
            [
                'autoincrement' => true,
                'notnull'       => true,
            ]
        );
        $table->addColumn(
            'file_id',
            Types::INTEGER,
            [
                'notnull' => true,
                'comment' => 'Nextcloud filecache fileid the lock applies to',
            ]
        );
        $table->addColumn(
            'locked_by',
            Types::STRING,
            [
                'notnull' => true,
                'length'  => 64,
                'comment' => 'User ID who holds the lock',
            ]
        );
        $table->addColumn(
            'locked_at',
            Types::DATETIME,
            [
                'notnull' => true,
                'comment' => 'Timestamp when the lock was acquired',
            ]
        );
        $table->addColumn(
            'lock_expires',
            Types::DATETIME,
            [
                'notnull' => true,
                'comment' => 'Timestamp when the lock expires (TTL)',
            ]
        );

        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['file_id'], 'or_file_locks_file_id_idx');

        $output->info("Created {$tableName} table");

        return $schema;
    }//end changeSchema()
}//end class
