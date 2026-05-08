<?php

/**
 * Migration to create the workflow_executions table.
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
 * Creates the openregister_workflow_executions table for persisting hook execution history.
 */
class Version1Date20260325000001 extends SimpleMigrationStep
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
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /*
         * @var ISchemaWrapper $schema
         */

        $schema = $schemaClosure();

        if ($schema->hasTable('openregister_workflow_executions') === true) {
            return null;
        }

        $table = $schema->createTable('openregister_workflow_executions');

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
                'hook_id',
                Types::STRING,
                [
                    'notnull' => true,
                    'length'  => 255,
                ]
                );
        $table->addColumn(
                'event_type',
                Types::STRING,
                [
                    'notnull' => true,
                    'length'  => 50,
                ]
                );
        $table->addColumn(
                'object_uuid',
                Types::STRING,
                [
                    'notnull' => true,
                    'length'  => 36,
                ]
                );
        $table->addColumn(
                'schema_id',
                Types::BIGINT,
                [
                    'notnull' => false,
                ]
                );
        $table->addColumn(
                'register_id',
                Types::BIGINT,
                [
                    'notnull' => false,
                ]
                );
        $table->addColumn(
                'engine',
                Types::STRING,
                [
                    'notnull' => true,
                    'length'  => 50,
                ]
                );
        $table->addColumn(
                'workflow_id',
                Types::STRING,
                [
                    'notnull' => true,
                    'length'  => 255,
                ]
                );
        $table->addColumn(
                'mode',
                Types::STRING,
                [
                    'notnull' => true,
                    'length'  => 10,
                    'default' => 'sync',
                ]
                );
        $table->addColumn(
                'status',
                Types::STRING,
                [
                    'notnull' => true,
                    'length'  => 20,
                ]
                );
        $table->addColumn(
                'duration_ms',
                Types::INTEGER,
                [
                    'notnull' => true,
                    'default' => 0,
                ]
                );
        $table->addColumn(
                'errors',
                Types::TEXT,
                [
                    'notnull' => false,
                ]
                );
        $table->addColumn(
                'metadata',
                Types::TEXT,
                [
                    'notnull' => false,
                ]
                );
        $table->addColumn(
                'payload',
                Types::TEXT,
                [
                    'notnull' => false,
                ]
                );
        $table->addColumn(
                'executed_at',
                Types::DATETIME,
                [
                    'notnull' => true,
                ]
                );

        $table->setPrimaryKey(['id']);
        $table->addIndex(['object_uuid'], 'or_wfexec_obj_uuid');
        $table->addIndex(['schema_id'], 'or_wfexec_schema');
        $table->addIndex(['hook_id'], 'or_wfexec_hook');
        $table->addIndex(['status'], 'or_wfexec_status');
        $table->addIndex(['executed_at'], 'or_wfexec_exec_at');

        return $schema;
    }//end changeSchema()
}//end class
