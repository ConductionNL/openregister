<?php

/**
 * Migration to create the approval chain and approval step tables.
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
 * Creates the openregister_approval_chains and openregister_approval_steps tables.
 */
class Version1Date20260325000003 extends SimpleMigrationStep
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

        $changed = false;

        if ($schema->hasTable('openregister_approval_chains') === false) {
            $chainsTable = $schema->createTable('openregister_approval_chains');

            $chainsTable->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull'       => true,
            ]);
            $chainsTable->addColumn('uuid', Types::STRING, [
                'notnull' => true,
                'length'  => 36,
            ]);
            $chainsTable->addColumn('name', Types::STRING, [
                'notnull' => true,
                'length'  => 255,
            ]);
            $chainsTable->addColumn('schema_id', Types::BIGINT, [
                'notnull' => true,
            ]);
            $chainsTable->addColumn('status_field', Types::STRING, [
                'notnull' => true,
                'length'  => 255,
                'default' => 'status',
            ]);
            $chainsTable->addColumn('steps', Types::TEXT, [
                'notnull' => true,
            ]);
            $chainsTable->addColumn('enabled', Types::BOOLEAN, [
                'notnull' => true,
                'default' => true,
            ]);
            $chainsTable->addColumn('created', Types::DATETIME, [
                'notnull' => true,
            ]);
            $chainsTable->addColumn('updated', Types::DATETIME, [
                'notnull' => true,
            ]);

            $chainsTable->setPrimaryKey(['id']);
            $changed = true;
        }

        if ($schema->hasTable('openregister_approval_steps') === false) {
            $stepsTable = $schema->createTable('openregister_approval_steps');

            $stepsTable->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull'       => true,
            ]);
            $stepsTable->addColumn('uuid', Types::STRING, [
                'notnull' => true,
                'length'  => 36,
            ]);
            $stepsTable->addColumn('chain_id', Types::BIGINT, [
                'notnull' => true,
            ]);
            $stepsTable->addColumn('object_uuid', Types::STRING, [
                'notnull' => true,
                'length'  => 36,
            ]);
            $stepsTable->addColumn('step_order', Types::INTEGER, [
                'notnull' => true,
            ]);
            $stepsTable->addColumn('role', Types::STRING, [
                'notnull' => true,
                'length'  => 255,
            ]);
            $stepsTable->addColumn('status', Types::STRING, [
                'notnull' => true,
                'length'  => 20,
                'default' => 'pending',
            ]);
            $stepsTable->addColumn('decided_by', Types::STRING, [
                'notnull' => false,
                'length'  => 255,
            ]);
            $stepsTable->addColumn('comment', Types::TEXT, [
                'notnull' => false,
            ]);
            $stepsTable->addColumn('decided_at', Types::DATETIME, [
                'notnull' => false,
            ]);
            $stepsTable->addColumn('created', Types::DATETIME, [
                'notnull' => true,
            ]);

            $stepsTable->setPrimaryKey(['id']);
            $stepsTable->addIndex(['chain_id', 'object_uuid'], 'or_apstep_chain_obj');
            $stepsTable->addIndex(['status'], 'or_apstep_status');
            $stepsTable->addIndex(['role'], 'or_apstep_role');
            $changed = true;
        }

        if ($changed === false) {
            return null;
        }

        return $schema;
    }//end changeSchema()
}//end class
