<?php

/**
 * Migration to create the scheduled_workflows table.
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
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
 * Creates the openregister_scheduled_workflows table.
 */
class Version1Date20260325000002 extends SimpleMigrationStep
{
    /**
     * Change the database schema.
     *
     * @param IOutput                 $output        Output for the migration process
     * @param Closure                 $schemaClosure The schema closure
     * @param array<array-key, mixed> $options       Migration options
     *
     * @return ISchemaWrapper|null
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('openregister_scheduled_workflows') === true) {
            return null;
        }

        $table = $schema->createTable('openregister_scheduled_workflows');

        $table->addColumn('id', Types::BIGINT, [
            'autoincrement' => true,
            'notnull'       => true,
        ]);
        $table->addColumn('uuid', Types::STRING, [
            'notnull' => true,
            'length'  => 36,
        ]);
        $table->addColumn('name', Types::STRING, [
            'notnull' => true,
            'length'  => 255,
        ]);
        $table->addColumn('engine', Types::STRING, [
            'notnull' => true,
            'length'  => 50,
        ]);
        $table->addColumn('workflow_id', Types::STRING, [
            'notnull' => true,
            'length'  => 255,
        ]);
        $table->addColumn('register_id', Types::BIGINT, [
            'notnull' => false,
        ]);
        $table->addColumn('schema_id', Types::BIGINT, [
            'notnull' => false,
        ]);
        $table->addColumn('interval_sec', Types::INTEGER, [
            'notnull' => true,
            'default' => 86400,
        ]);
        $table->addColumn('enabled', Types::BOOLEAN, [
            'notnull' => true,
            'default' => true,
        ]);
        $table->addColumn('payload', Types::TEXT, [
            'notnull' => false,
        ]);
        $table->addColumn('last_run', Types::DATETIME, [
            'notnull' => false,
        ]);
        $table->addColumn('last_status', Types::STRING, [
            'notnull' => false,
            'length'  => 20,
        ]);
        $table->addColumn('created', Types::DATETIME, [
            'notnull' => true,
        ]);
        $table->addColumn('updated', Types::DATETIME, [
            'notnull' => true,
        ]);

        $table->setPrimaryKey(['id']);

        return $schema;
    }//end changeSchema()
}//end class
