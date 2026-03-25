<?php

/**
 * OpenRegister Migration Version1Date20260325120000
 *
 * Creates the selection_lists and destruction_lists tables for the
 * archival and destruction workflow feature.
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
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Create selection_lists and destruction_lists tables for archival workflow.
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 */
class Version1Date20260325120000 extends SimpleMigrationStep
{
    /**
     * Change the database schema.
     *
     * @param IOutput                   $output        Output interface
     * @param Closure(): ISchemaWrapper $schemaClosure Schema closure
     * @param array<string, mixed>      $options       Migration options
     *
     * @return ISchemaWrapper|null
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        $this->createSelectionListsTable($schema, $output);
        $this->createDestructionListsTable($schema, $output);

        return $schema;
    }//end changeSchema()

    /**
     * Create the selection_lists table.
     *
     * @param ISchemaWrapper $schema The schema wrapper
     * @param IOutput        $output Migration output
     *
     * @return void
     */
    private function createSelectionListsTable(ISchemaWrapper $schema, IOutput $output): void
    {
        $tableName = 'openregister_selection_lists';

        if ($schema->hasTable($tableName) === true) {
            $output->info("Table {$tableName} already exists, skipping");
            return;
        }

        $table = $schema->createTable($tableName);

        $table->addColumn(
            'id',
            Types::BIGINT,
            [
                'autoincrement' => true,
                'notnull'       => true,
            ]
        );
        $table->addColumn(
            'uuid',
            Types::STRING,
            [
                'notnull' => true,
                'length'  => 36,
            ]
        );
        $table->addColumn(
            'category',
            Types::STRING,
            [
                'notnull' => true,
                'length'  => 255,
            ]
        );
        $table->addColumn(
            'retention_years',
            Types::INTEGER,
            [
                'notnull' => true,
                'default' => 0,
            ]
        );
        $table->addColumn(
            'action',
            Types::STRING,
            [
                'notnull' => true,
                'length'  => 50,
                'default' => 'vernietigen',
            ]
        );
        $table->addColumn(
            'description',
            Types::TEXT,
            [
                'notnull' => false,
                'default' => null,
            ]
        );
        $table->addColumn(
            'schema_overrides',
            Types::TEXT,
            [
                'notnull' => false,
                'default' => null,
                'comment' => 'JSON map of schema UUID to override retention years',
            ]
        );
        $table->addColumn(
            'organisation',
            Types::STRING,
            [
                'notnull' => false,
                'length'  => 255,
                'default' => null,
            ]
        );
        $table->addColumn(
            'created',
            Types::DATETIME,
            [
                'notnull' => false,
                'default' => null,
            ]
        );
        $table->addColumn(
            'updated',
            Types::DATETIME,
            [
                'notnull' => false,
                'default' => null,
            ]
        );

        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['uuid'], 'sl_uuid_idx');
        $table->addIndex(['category'], 'sl_category_idx');
        $table->addIndex(['organisation'], 'sl_organisation_idx');

        $output->info("Created table {$tableName}");
    }//end createSelectionListsTable()

    /**
     * Create the destruction_lists table.
     *
     * @param ISchemaWrapper $schema The schema wrapper
     * @param IOutput        $output Migration output
     *
     * @return void
     */
    private function createDestructionListsTable(ISchemaWrapper $schema, IOutput $output): void
    {
        $tableName = 'openregister_destruction_lists';

        if ($schema->hasTable($tableName) === true) {
            $output->info("Table {$tableName} already exists, skipping");
            return;
        }

        $table = $schema->createTable($tableName);

        $table->addColumn(
            'id',
            Types::BIGINT,
            [
                'autoincrement' => true,
                'notnull'       => true,
            ]
        );
        $table->addColumn(
            'uuid',
            Types::STRING,
            [
                'notnull' => true,
                'length'  => 36,
            ]
        );
        $table->addColumn(
            'name',
            Types::STRING,
            [
                'notnull' => true,
                'length'  => 255,
            ]
        );
        $table->addColumn(
            'status',
            Types::STRING,
            [
                'notnull' => true,
                'length'  => 50,
                'default' => 'pending_review',
            ]
        );
        $table->addColumn(
            'objects',
            Types::TEXT,
            [
                'notnull' => false,
                'default' => null,
                'comment' => 'JSON array of object UUIDs',
            ]
        );
        $table->addColumn(
            'approved_by',
            Types::STRING,
            [
                'notnull' => false,
                'length'  => 255,
                'default' => null,
            ]
        );
        $table->addColumn(
            'approved_at',
            Types::DATETIME,
            [
                'notnull' => false,
                'default' => null,
            ]
        );
        $table->addColumn(
            'notes',
            Types::TEXT,
            [
                'notnull' => false,
                'default' => null,
            ]
        );
        $table->addColumn(
            'organisation',
            Types::STRING,
            [
                'notnull' => false,
                'length'  => 255,
                'default' => null,
            ]
        );
        $table->addColumn(
            'created',
            Types::DATETIME,
            [
                'notnull' => false,
                'default' => null,
            ]
        );
        $table->addColumn(
            'updated',
            Types::DATETIME,
            [
                'notnull' => false,
                'default' => null,
            ]
        );

        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['uuid'], 'dl_uuid_idx');
        $table->addIndex(['status'], 'dl_status_idx');
        $table->addIndex(['organisation'], 'dl_organisation_idx');

        $output->info("Created table {$tableName}");
    }//end createDestructionListsTable()
}//end class
