<?php
/**
 * Database migration creating the federated-shares table.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * `openregister_federated_shares` backs cross-instance OpenRegister federation
 * (OCM): one row per outgoing/incoming share of a register, schema, object or
 * query with an organisation on another Nextcloud instance. The scoped
 * `share_token` authorises the federation serving endpoint; `remote_instance_url`
 * is the peer a FederatedObjectSourceProvider proxies live reads against.
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author    Conduction Development Team <info@conduction.nl>
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
 * Creates the `openregister_federated_shares` table (idempotent).
 *
 * @package OCA\OpenRegister\Migration
 */
class Version1Date20260710000000 extends SimpleMigrationStep
{
    /**
     * Change the database schema.
     *
     * @param IOutput $output        Migration output.
     * @param Closure $schemaClosure Schema closure returning an ISchemaWrapper.
     * @param array   $options       Migration options.
     *
     * @return ISchemaWrapper|null The updated schema, or null if no changes.
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        $schema = $schemaClosure();

        if ($schema->hasTable('openregister_federated_shares') === true) {
            return null;
        }

        $table = $schema->createTable('openregister_federated_shares');

        $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
        $table->addColumn('uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
        $table->addColumn('direction', Types::STRING, ['notnull' => true, 'length' => 16]);
        $table->addColumn('remote_instance_url', Types::STRING, ['notnull' => false, 'length' => 1024, 'default' => null]);
        $table->addColumn('remote_provider_id', Types::STRING, ['notnull' => false, 'length' => 255, 'default' => null]);
        $table->addColumn('share_token', Types::STRING, ['notnull' => true, 'length' => 128]);
        $table->addColumn('scope', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'schema']);
        $table->addColumn('register', Types::STRING, ['notnull' => false, 'length' => 255, 'default' => null]);
        $table->addColumn('schema', Types::STRING, ['notnull' => false, 'length' => 255, 'default' => null]);
        $table->addColumn('object_uri', Types::STRING, ['notnull' => false, 'length' => 1024, 'default' => null]);
        $table->addColumn('query_filter', Types::TEXT, ['notnull' => false, 'default' => null]);
        $table->addColumn('permissions', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'read']);
        $table->addColumn('organisation', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]);
        $table->addColumn('shared_with', Types::STRING, ['notnull' => false, 'length' => 512, 'default' => null]);
        $table->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'pending']);
        $table->addColumn('created', Types::DATETIME, ['notnull' => true]);
        $table->addColumn('updated', Types::DATETIME, ['notnull' => true]);

        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['share_token'], 'or_fedshare_token');
        $table->addIndex(['organisation', 'direction'], 'or_fedshare_org_dir');
        $table->addIndex(['register', 'schema'], 'or_fedshare_reg_sch');

        $output->info('   ✓ Created openregister_federated_shares table');

        return $schema;

    }//end changeSchema()
}//end class
