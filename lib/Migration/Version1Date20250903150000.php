<?php

/**
 * OpenRegister Authorization Exception Migration
 *
 * This migration creates the authorization exceptions table for handling
 * authorization inclusions and exclusions in the OpenRegister application.
 *
 * @category Database
 * @package  OCA\OpenRegister\Migration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Migration to create authorization exceptions table
 *
 * This migration creates the openregister_authorization_exceptions table
 * for managing authorization inclusions and exclusions that override
 * the standard RBAC system.
 *
 * @category Database
 * @package  OCA\OpenRegister\Migration
 *
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version  GIT: <git_id>
 * @link     https://www.OpenRegister.app
 */
class Version1Date20250903150000 extends SimpleMigrationStep
{

    /**
     * Perform the migration
     *
     * @param IOutput         $output The output interface for logging
     * @param Closure         $schemaClosure Closure that returns the current schema
     * @param array           $options Migration options
     * @phpstan-param array<string, mixed> $options
     * @psalm-param array<string, mixed> $options
     *
     * @return ISchemaWrapper|null The new schema or null if no changes
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // Skip if table already exists
        if ($schema->hasTable('openregister_authorization_exceptions') === true) {
            return null;
        }

        // Create the authorization exceptions table
        $table = $schema->createTable('openregister_authorization_exceptions');

        // Primary key
        $table->addColumn('id', Types::BIGINT, [
            'autoincrement' => true,
            'notnull' => true,
            'unsigned' => true,
        ]);

        // Unique identifier for the authorization exception
        $table->addColumn('uuid', Types::STRING, [
            'notnull' => true,
            'length' => 36,
            'comment' => 'Unique identifier for the authorization exception',
        ]);

        // Type of exception: inclusion or exclusion
        $table->addColumn('type', Types::STRING, [
            'notnull' => true,
            'length' => 20,
            'comment' => 'Type of exception: inclusion or exclusion',
        ]);

        // Subject type: user or group
        $table->addColumn('subject_type', Types::STRING, [
            'notnull' => true,
            'length' => 10,
            'comment' => 'Subject type: user or group',
        ]);

        // Subject ID: the actual user ID or group ID
        $table->addColumn('subject_id', Types::STRING, [
            'notnull' => true,
            'length' => 64,
            'comment' => 'The user ID or group ID this exception applies to',
        ]);

        // Schema UUID this exception applies to (nullable for global exceptions)
        $table->addColumn('schema_uuid', Types::STRING, [
            'notnull' => false,
            'length' => 36,
            'comment' => 'Schema UUID this exception applies to (nullable for global)',
        ]);

        // Register UUID this exception applies to (nullable)
        $table->addColumn('register_uuid', Types::STRING, [
            'notnull' => false,
            'length' => 36,
            'comment' => 'Register UUID this exception applies to (nullable)',
        ]);

        // Organization UUID this exception applies to (nullable)
        $table->addColumn('organization_uuid', Types::STRING, [
            'notnull' => false,
            'length' => 36,
            'comment' => 'Organization UUID this exception applies to (nullable)',
        ]);

        // CRUD action this exception applies to
        $table->addColumn('action', Types::STRING, [
            'notnull' => true,
            'length' => 10,
            'comment' => 'CRUD action: create, read, update, or delete',
        ]);

        // Priority for exception resolution (higher = more important)
        $table->addColumn('priority', Types::INTEGER, [
            'notnull' => true,
            'default' => 0,
            'comment' => 'Priority for exception resolution (higher = more important)',
        ]);

        // Whether the exception is active
        $table->addColumn('active', Types::BOOLEAN, [
            'notnull' => true,
            'default' => true,
            'comment' => 'Whether the exception is active',
        ]);

        // Human readable description of the exception
        $table->addColumn('description', Types::TEXT, [
            'notnull' => false,
            'comment' => 'Human readable description of the exception',
        ]);

        // User who created the exception
        $table->addColumn('created_by', Types::STRING, [
            'notnull' => true,
            'length' => 64,
            'comment' => 'User who created the exception',
        ]);

        // Timestamps
        $table->addColumn('created_at', Types::DATETIME, [
            'notnull' => true,
            'comment' => 'Creation timestamp',
        ]);

        $table->addColumn('updated_at', Types::DATETIME, [
            'notnull' => true,
            'comment' => 'Last update timestamp',
        ]);

        // Set primary key
        $table->setPrimaryKey(['id']);

        // Create indexes for performance
        $table->addUniqueIndex(['uuid'], 'openregister_auth_exceptions_uuid');
        $table->addIndex(['type'], 'openregister_auth_exceptions_type');
        $table->addIndex(['subject_type', 'subject_id'], 'openregister_auth_exceptions_subject');
        $table->addIndex(['schema_uuid'], 'openregister_auth_exceptions_schema');
        $table->addIndex(['register_uuid'], 'openregister_auth_exceptions_register');
        $table->addIndex(['organization_uuid'], 'openregister_auth_exceptions_org');
        $table->addIndex(['action'], 'openregister_auth_exceptions_action');
        $table->addIndex(['active'], 'openregister_auth_exceptions_active');
        $table->addIndex(['priority'], 'openregister_auth_exceptions_priority');

        // Composite indexes for common queries
        $table->addIndex(['subject_type', 'subject_id', 'action', 'active'], 'openregister_auth_exceptions_lookup');
        $table->addIndex(['schema_uuid', 'action', 'active'], 'openregister_auth_exceptions_schema_lookup');

        return $schema;

    }//end changeSchema()


}//end class

