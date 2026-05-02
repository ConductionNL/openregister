<?php

/**
 * Migration creating `oc_openregister_notification_subscriptions`.
 *
 * Per-user subscription scoping: a row binds (user_id, register_id?,
 * schema_id?) so the dispatcher can short-circuit recipient resolution
 * to users who have explicitly opted into the register or schema.
 *
 * Either register_id or schema_id (or both) MUST be set; nullable
 * columns let the same table model "subscribe to a whole register"
 * AND "subscribe to a single schema across registers".
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/notificatie-engine/tasks.md "Users MUST be able to manage their notification preferences"
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1Date20260502200000 extends SimpleMigrationStep
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

        if ($schema->hasTable(tableName: 'openregister_notification_subscriptions') === true) {
            return null;
        }

        $table = $schema->createTable(tableName: 'openregister_notification_subscriptions');

        $table->addColumn(
            name: 'id',
            typeName: Types::BIGINT,
            options: [
                'autoincrement' => true,
                'notnull'       => true,
            ]
        );

        $table->addColumn(
            name: 'user_id',
            typeName: Types::STRING,
            options: [
                'notnull' => true,
                'length'  => 64,
                'comment' => 'NC user UID who subscribed',
            ]
        );

        $table->addColumn(
            name: 'register_id',
            typeName: Types::BIGINT,
            options: [
                'notnull' => false,
                'default' => null,
                'comment' => 'Register being subscribed to (null = schema-only subscription)',
            ]
        );

        $table->addColumn(
            name: 'schema_id',
            typeName: Types::BIGINT,
            options: [
                'notnull' => false,
                'default' => null,
                'comment' => 'Schema being subscribed to (null = register-wide subscription)',
            ]
        );

        $table->addColumn(
            name: 'created',
            typeName: Types::DATETIME,
            options: [
                'notnull' => true,
                'comment' => 'Subscription timestamp',
            ]
        );

        $table->setPrimaryKey(columnNames: ['id']);
        $table->addUniqueIndex(
            columnNames: ['user_id', 'register_id', 'schema_id'],
            indexName: 'idx_or_subs_uid_reg_sch'
        );
        $table->addIndex(columnNames: ['user_id'], indexName: 'idx_or_subs_user_id');
        $table->addIndex(columnNames: ['register_id', 'schema_id'], indexName: 'idx_or_subs_reg_sch');

        return $schema;

    }//end changeSchema()
}//end class
