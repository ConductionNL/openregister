<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\SimpleMigrationStep;
use OCP\Migration\IOutput;

class Version1Date20250622212509 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // 1. Add 'groups' column to existing tables.
        $table = $schema->getTable('openregister_objects');
        if (!$table->hasColumn('groups')) {
            $table->addColumn('groups', Types::JSON, [
                'notnull' => false,
            ]);
        }
        if (!$table->hasColumn('name')) {
            $table->addColumn('name', Types::STRING, [
                'notnull' => false,
                'length'  => 255,
            ]);
        }
        if (!$table->hasColumn('description')) {
            $table->addColumn('description', Types::TEXT, [
                'notnull' => false,
            ]);
        }
        if ($table->hasColumn('text_representation')) {
            $table->dropColumn('text_representation');
        }
        
        $table = $schema->getTable('openregister_schemas');
        if (!$table->hasColumn('groups')) {
            $table->addColumn('groups', Types::JSON, [
                'notnull' => false,
            ]);
        }
        if (!$table->hasColumn('immutable')) {
            $table->addColumn('immutable', Types::BOOLEAN, [
                'notnull' => true,
                'default' => false,
            ]);
        }
        if (!$table->hasColumn('configuration')) {
            $table->addColumn('configuration', Types::JSON, [
                'notnull' => false,
            ]);
        }
        

        $table = $schema->getTable('openregister_registers');
        if (!$table->hasColumn('groups')) {
            $table->addColumn('groups', Types::JSON, [
                'notnull' => false,
            ]);
        }

        // 2. Create 'openregister_organisations' table.
        if (!$schema->hasTable('openregister_organisations')) {
            $table = $schema->createTable('openregister_organisations');
            $table->addColumn('id', Types::INTEGER, [
                'autoincrement' => true,
                'notnull' => true,
            ]);
            $table->addColumn('uuid', Types::STRING, [
                'notnull' => true,
                'length' => 255,
            ]);
            $table->addColumn('name', Types::STRING, [
                'notnull' => true,
                'length' => 255,
            ]);
            $table->addColumn('description', Types::TEXT, [
                'notnull' => false,
            ]);
            $table->addColumn('created', Types::DATETIME, [
                'notnull' => false,
            ]);
            $table->addColumn('updated', Types::DATETIME, [
                'notnull' => false,
            ]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['uuid'], 'openregister_organisations_uuid_index');
        }

        // 3. Create 'openregister_data_access_profiles' table.
        if (!$schema->hasTable('openregister_data_access_profiles')) {
            $table = $schema->createTable('openregister_data_access_profiles');
            $table->addColumn('id', Types::INTEGER, [
                'autoincrement' => true,
                'notnull' => true,
            ]);
            $table->addColumn('uuid', Types::STRING, [
                'notnull' => true,
                'length' => 255,
            ]);
            $table->addColumn('name', Types::STRING, [
                'notnull' => true,
                'length' => 255,
            ]);
            $table->addColumn('description', Types::TEXT, [
                'notnull' => false,
            ]);
            $table->addColumn('permissions', Types::JSON, [
                'notnull' => false,
            ]);
            $table->addColumn('created', Types::DATETIME, [
                'notnull' => false,
            ]);
            $table->addColumn('updated', Types::DATETIME, [
                'notnull' => false,
            ]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['uuid'], 'openregister_dap_uuid_index');
        }
        
        return $schema;
    }

    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    {
        // Implementation of postSchemaChange method.
    }
} 