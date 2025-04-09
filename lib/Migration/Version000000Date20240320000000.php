<?php
/**
 * OpenRegister Migration
 *
 * This file contains the migration for creating the configurations table.
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

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Migration class for creating the configurations table
 *
 * @package OCA\OpenRegister\Migration
 */
class Version000000Date20240320000000 extends SimpleMigrationStep {
    /**
     * Change the database schema
     *
     * @param IOutput        $output Output for the migration process
     * @param Closure       $schemaClosure The schema closure
     * @param array<string> $options Migration options
     *
     * @return null|ISchemaWrapper The schema wrapper
     *
     * @psalm-suppress MoreSpecificImplementedParamType
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('openregister_configurations')) {
            $table = $schema->createTable('openregister_configurations');
            $table->addColumn('id', Types::INTEGER, [
                'autoincrement' => true,
                'notnull' => true,
            ]);
            $table->addColumn('title', Types::STRING, [
                'notnull' => true,
                'length' => 255,
            ]);
            $table->addColumn('description', Types::TEXT, [
                'notnull' => false,
                'default' => null,
            ]);
            $table->addColumn('type', Types::STRING, [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('data', Types::JSON, [
                'notnull' => false,
                'default' => null,
            ]);
            $table->addColumn('owner', Types::STRING, [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('created', Types::DATETIME, [
                'notnull' => true,
            ]);
            $table->addColumn('updated', Types::DATETIME, [
                'notnull' => true,
            ]);

            $table->setPrimaryKey(['id']);
            $table->addIndex(['type'], 'openregister_config_type_idx');
            $table->addIndex(['owner'], 'openregister_config_owner_idx');
            $table->addIndex(['created'], 'openregister_config_created_idx');
        }

        return $schema;
    }
} 