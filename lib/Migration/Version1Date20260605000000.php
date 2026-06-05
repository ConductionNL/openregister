<?php

/**
 * Migration to create openregister_file_links and add exif_metadata column.
 *
 * Creates the openregister_file_links table to store metadata for file-to-object
 * relationships, including cached EXIF data for the Photos integration.
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/integration-photos/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Creates openregister_file_links with exif_metadata JSON column.
 *
 * The file_links table acts as a metadata overlay on top of Nextcloud's
 * folder-based file storage. Photos (filtered images) and Files share
 * the same underlying storage; this table stores per-file metadata
 * (EXIF, GPS strip flag) keyed by (object_uuid, file_id).
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
class Version1Date20260605000000 extends SimpleMigrationStep
{
    /**
     * Change the database schema.
     *
     * @param IOutput                 $output        Migration output
     * @param Closure                 $schemaClosure Schema closure
     * @param array<array-key, mixed> $options       Migration options
     *
     * @return ISchemaWrapper|null Updated schema or null if no changes
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        $schema = $schemaClosure();

        if ($schema->hasTable('openregister_file_links') === true) {
            $output->info('Table openregister_file_links already exists, checking for missing columns');
            $table   = $schema->getTable('openregister_file_links');
            $changed = false;

            if ($table->hasColumn('exif_metadata') === false) {
                $table->addColumn(
                    'exif_metadata',
                    Types::TEXT,
                    [
                        'notnull' => false,
                        'default' => null,
                        'comment' => 'Cached EXIF metadata as JSON (lazy-extracted on first view)',
                    ]
                );
                $output->info('Added exif_metadata column to openregister_file_links');
                $changed = true;
            }

            if ($table->hasColumn('gps_stripped') === false) {
                $table->addColumn(
                    'gps_stripped',
                    Types::BOOLEAN,
                    [
                        'notnull' => false,
                        'default' => false,
                        'comment' => 'Whether GPS data was stripped from EXIF at link time',
                    ]
                );
                $output->info('Added gps_stripped column to openregister_file_links');
                $changed = true;
            }

            return $changed === true ? $schema : null;
        }//end if

        $table = $schema->createTable('openregister_file_links');

        $table->addColumn(
            'id',
            Types::BIGINT,
            [
                'autoincrement' => true,
                'notnull'       => true,
            ]
        );

        $table->addColumn(
            'object_uuid',
            Types::STRING,
            [
                'notnull' => true,
                'length'  => 36,
                'comment' => 'UUID of the OpenRegister object this file is linked to',
            ]
        );

        $table->addColumn(
            'file_id',
            Types::BIGINT,
            [
                'notnull' => true,
                'comment' => 'Nextcloud file ID from oc_filecache',
            ]
        );

        $table->addColumn(
            'exif_metadata',
            Types::TEXT,
            [
                'notnull' => false,
                'default' => null,
                'comment' => 'Cached EXIF metadata as JSON (lazy-extracted on first view)',
            ]
        );

        $table->addColumn(
            'gps_stripped',
            Types::BOOLEAN,
            [
                'notnull' => false,
                'default' => false,
                'comment' => 'Whether GPS data was stripped from EXIF at link time',
            ]
        );

        $table->addColumn(
            'created',
            Types::DATETIME,
            [
                'notnull' => true,
                'comment' => 'Timestamp when the link record was created',
            ]
        );

        $table->addColumn(
            'updated',
            Types::DATETIME,
            [
                'notnull' => false,
                'default' => null,
                'comment' => 'Timestamp when the link record was last updated',
            ]
        );

        $table->setPrimaryKey(['id']);
        $table->addIndex(['object_uuid'], 'or_file_links_obj_uuid');
        $table->addIndex(['file_id'], 'or_file_links_file_id');
        $table->addUniqueIndex(['object_uuid', 'file_id'], 'or_file_links_unique');

        $output->info('Created openregister_file_links table with exif_metadata column');

        return $schema;
    }//end changeSchema()
}//end class
