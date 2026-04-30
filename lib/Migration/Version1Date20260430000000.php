<?php

/**
 * Migration to create the openregister_realtime_events append-only event
 * log used by the realtime-updates spec.
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use Doctrine\DBAL\Types\Types;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Creates `openregister_realtime_events`, the append-only event log
 * for realtime change notifications. Each row is a CloudEvent-shaped
 * record of one register-object change; clients poll with `?since={id}`
 * to receive every event newer than their last seen id.
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
class Version1Date20260430000000 extends SimpleMigrationStep
{

    /**
     * @param IOutput        $output     Migration output
     * @param Closure        $schemaClosure Schema closure
     * @param array<string, mixed> $options Migration options
     *
     * @return null|ISchemaWrapper
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('openregister_realtime_events') === true) {
            return $schema;
        }

        $table = $schema->createTable('openregister_realtime_events');

        $table->addColumn('id', Types::BIGINT, [
            'autoincrement' => true,
            'notnull'       => true,
        ]);
        $table->setPrimaryKey(['id']);

        // CloudEvent-style envelope fields.
        $table->addColumn('event_type', Types::STRING, [
            'notnull' => true,
            'length'  => 64,
        ]);
        $table->addColumn('source', Types::STRING, [
            'notnull' => true,
            'length'  => 255,
        ]);
        $table->addColumn('subject', Types::STRING, [
            'notnull' => false,
            'length'  => 255,
        ]);

        // OpenRegister identity (denormalised for filtering).
        $table->addColumn('register_id', Types::STRING, [
            'notnull' => false,
            'length'  => 255,
        ]);
        $table->addColumn('schema_id', Types::STRING, [
            'notnull' => false,
            'length'  => 255,
        ]);
        $table->addColumn('object_uuid', Types::STRING, [
            'notnull' => false,
            'length'  => 64,
        ]);
        $table->addColumn('actor_uid', Types::STRING, [
            'notnull' => false,
            'length'  => 64,
        ]);
        $table->addColumn('organisation', Types::STRING, [
            'notnull' => false,
            'length'  => 64,
        ]);

        // Full CloudEvent payload as JSON.
        $table->addColumn('payload', Types::TEXT, [
            'notnull' => true,
        ]);

        $table->addColumn('created', Types::DATETIME_MUTABLE, [
            'notnull' => true,
        ]);

        // Indices for the cursor-based polling query (`WHERE id > :since`)
        // and per-object / per-schema filters.
        $table->addIndex(['register_id', 'schema_id', 'id'], 'idx_realtime_register_schema_id');
        $table->addIndex(['object_uuid', 'id'], 'idx_realtime_object_id');
        $table->addIndex(['organisation', 'id'], 'idx_realtime_org_id');

        return $schema;
    }//end changeSchema()

}//end class
