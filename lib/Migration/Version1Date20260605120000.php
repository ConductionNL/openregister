<?php

/**
 * Migration to create openregister_file_links table with exif_metadata column.
 *
 * Creates the shared file link table used by both Files and Photos integrations.
 * The Photos integration filters this table by MIME type at query time and
 * stores EXIF data in the exif_metadata JSON column (lazy-extracted on first view).
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/integration-photos/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use Doctrine\DBAL\Types\Types;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Creates openregister_file_links with exif_metadata column.
 *
 * If the table already exists (created by a prior migration), only adds the
 * exif_metadata column when missing.
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
class Version1Date20260605120000 extends SimpleMigrationStep
{
    /**
     * Apply schema changes.
     *
     * @param IOutput                 $output        Migration output.
     * @param Closure                 $schemaClosure Schema closure.
     * @param array<array-key, mixed> $options       Options.
     *
     * @return ISchemaWrapper|null
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        $schema  = $schemaClosure();
        $changed = false;

        if ($schema->hasTable('openregister_file_links') === false) {
            $table = $schema->createTable('openregister_file_links');

            $table->addColumn(
                name: 'id',
                typeName: Types::BIGINT,
                options: ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]
            );
            $table->addColumn(
                name: 'object_uuid',
                typeName: Types::STRING,
                options: ['notnull' => true, 'length' => 255]
            );
            $table->addColumn(
                name: 'file_id',
                typeName: Types::BIGINT,
                options: ['notnull' => true, 'unsigned' => true]
            );
            $table->addColumn(
                name: 'file_name',
                typeName: Types::STRING,
                options: ['notnull' => true, 'length' => 255]
            );
            $table->addColumn(
                name: 'mime_type',
                typeName: Types::STRING,
                options: ['notnull' => true, 'length' => 255, 'default' => '']
            );
            $table->addColumn(
                name: 'file_size',
                typeName: Types::BIGINT,
                options: ['notnull' => false, 'default' => 0]
            );
            $table->addColumn(
                name: 'linked_by',
                typeName: Types::STRING,
                options: ['notnull' => true, 'length' => 64]
            );
            $table->addColumn(
                name: 'linked_at',
                typeName: Types::DATETIME_IMMUTABLE,
                options: ['notnull' => true]
            );
            $table->addColumn(
                name: 'exif_metadata',
                typeName: Types::TEXT,
                options: ['notnull' => false, 'default' => null]
            );

            $table->setPrimaryKey(['id']);
            $table->addIndex(['object_uuid'], 'or_file_links_obj_uuid_idx');
            $table->addIndex(['file_id'], 'or_file_links_file_id_idx');
            $table->addIndex(['mime_type'], 'or_file_links_mime_idx');

            $output->info('Created openregister_file_links table.');
            $changed = true;
        } else {
            $table = $schema->getTable('openregister_file_links');

            if ($table->hasColumn('exif_metadata') === false) {
                $table->addColumn(
                    name: 'exif_metadata',
                    typeName: Types::TEXT,
                    options: ['notnull' => false, 'default' => null]
                );
                $output->info('Added exif_metadata column to openregister_file_links.');
                $changed = true;
            }
        }//end if

        if ($changed === false) {
            return null;
        }

        return $schema;
    }//end changeSchema()
}//end class
