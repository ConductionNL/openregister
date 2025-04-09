<?php
/**
 * OpenRegister Configuration Migration
 *
 * This file contains the migration for creating the configurations table
 * in the OpenRegister application.
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author    Ruben Linde <ruben@nextcloud.com>
 * @copyright Copyright (c) 2024, Ruben Linde (https://github.com/rubenlinde)
 * @license   AGPL-3.0
 * @version   1.0.0
 * @link      https://github.com/cloud-py-api/openregister
 */

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Class Version000000Date20240315000000
 *
 * @package OCA\OpenRegister\Migration
 */
class Version000000Date20240315000000 extends SimpleMigrationStep {
    /**
     * Change the database schema
     *
     * @param IOutput        $output Output for the migration process
     * @param Closure       $schemaClosure The schema closure
     * @param array<string> $options Migration options
     *
     * @phpstan-return ISchemaWrapper|null
     * @psalm-return ISchemaWrapper|null
     * @return ISchemaWrapper|null The modified schema
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('openregister_configurations')) {
            $table = $schema->createTable('openregister_configurations');
            $table->addColumn('id', 'integer', [
                'autoincrement' => true,
                'notnull' => true,
            ]);
            $table->addColumn('title', 'string', [
                'notnull' => true,
                'length' => 255,
            ]);
            $table->addColumn('description', 'text', [
                'notnull' => false,
                'default' => '',
            ]);
            $table->addColumn('type', 'string', [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('data', 'json', [
                'notnull' => true,
                'default' => '{}',
            ]);
            $table->addColumn('owner', 'string', [
                'notnull' => false,
                'length' => 64,
            ]);
            $table->addColumn('created', 'datetime', [
                'notnull' => true,
            ]);
            $table->addColumn('updated', 'datetime', [
                'notnull' => true,
            ]);

            $table->setPrimaryKey(['id']);
            $table->addIndex(['type'], 'openregister_config_type_idx');
            $table->addIndex(['owner'], 'openregister_config_owner_idx');
            $table->addIndex(['created'], 'openregister_config_created_idx');
            $table->addIndex(['updated'], 'openregister_config_updated_idx');
        }

        return $schema;
    }
} 