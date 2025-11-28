<?php
/**
 * OpenRegister Migration Version1Date20251128120000
 *
 * Migration to create endpoints and endpoint_logs tables.
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Create endpoints and endpoint_logs tables
 *
 * @category  Migration
 * @package   OCA\OpenRegister\Migration
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 */
class Version1Date20251128120000 extends SimpleMigrationStep
{


    /**
     * Change database schema
     *
     * @param IOutput $output        Output interface
     * @param Closure $schemaClosure Schema closure
     * @param array   $options       Options
     *
     * @return ISchemaWrapper|null
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /*
         * @var ISchemaWrapper $schema
         */
        $schema = $schemaClosure();

        // Create endpoints table.
        if ($schema->hasTable('openregister_endpoints') === false) {
            $output->info('🔗 Creating endpoints table...');

            $table = $schema->createTable('openregister_endpoints');

            // Primary key.
            $table->addColumn(
                'id',
                Types::BIGINT,
                [
                    'autoincrement' => true,
                    'notnull'       => true,
                    'unsigned'      => true,
                ]
            );
            $table->setPrimaryKey(['id']);

            // UUID for external reference.
            $table->addColumn(
                'uuid',
                Types::STRING,
                [
                    'notnull' => true,
                    'length'  => 255,
                ]
            );
            $table->addUniqueIndex(['uuid'], 'endpoints_uuid_index');

            // Basic information.
            $table->addColumn(
                'name',
                Types::STRING,
                [
                    'notnull' => true,
                    'length'  => 255,
                ]
            );

            $table->addColumn(
                'description',
                Types::TEXT,
                [
                    'notnull' => false,
                ]
            );

            $table->addColumn(
                'reference',
                Types::STRING,
                [
                    'notnull' => false,
                    'length'  => 255,
                ]
            );

            $table->addColumn(
                'version',
                Types::STRING,
                [
                    'notnull' => false,
                    'length'  => 20,
                    'default' => '0.0.0',
                ]
            );

            // Endpoint configuration.
            $table->addColumn(
                'endpoint',
                Types::STRING,
                [
                    'notnull' => true,
                    'length'  => 1024,
                ]
            );

            $table->addColumn(
                'endpoint_array',
                Types::JSON,
                [
                    'notnull' => false,
                ]
            );

            $table->addColumn(
                'endpoint_regex',
                Types::STRING,
                [
                    'notnull' => false,
                    'length'  => 1024,
                ]
            );

            $table->addColumn(
                'method',
                Types::STRING,
                [
                    'notnull' => false,
                    'length'  => 10,
                    'default' => 'GET',
                ]
            );

            // Target configuration.
            $table->addColumn(
                'target_type',
                Types::STRING,
                [
                    'notnull' => false,
                    'length'  => 50,
                ]
            );
            $table->addIndex(['target_type'], 'endpoints_target_type_index');

            $table->addColumn(
                'target_id',
                Types::STRING,
                [
                    'notnull' => false,
                    'length'  => 255,
                ]
            );
            $table->addIndex(['target_id'], 'endpoints_target_id_index');

            // Transformation and rules.
            $table->addColumn(
                'conditions',
                Types::JSON,
                [
                    'notnull' => false,
                ]
            );

            $table->addColumn(
                'input_mapping',
                Types::STRING,
                [
                    'notnull' => false,
                    'length'  => 255,
                ]
            );

            $table->addColumn(
                'output_mapping',
                Types::STRING,
                [
                    'notnull' => false,
                    'length'  => 255,
                ]
            );

            $table->addColumn(
                'rules',
                Types::JSON,
                [
                    'notnull' => false,
                ]
            );

            $table->addColumn(
                'configurations',
                Types::JSON,
                [
                    'notnull' => false,
                ]
            );

            // URL-friendly slug.
            $table->addColumn(
                'slug',
                Types::STRING,
                [
                    'notnull' => false,
                    'length'  => 255,
                ]
            );
            $table->addIndex(['slug'], 'endpoints_slug_index');

            // Access control.
            $table->addColumn(
                'groups',
                Types::JSON,
                [
                    'notnull' => false,
                ]
            );

            // Multi-tenancy.
            $table->addColumn(
                'organisation',
                Types::STRING,
                [
                    'notnull' => false,
                    'length'  => 255,
                ]
            );
            $table->addIndex(['organisation'], 'endpoints_organisation_index');

            // Timestamps.
            $table->addColumn(
                'created',
                Types::DATETIME,
                [
                    'notnull' => true,
                    'default' => 'CURRENT_TIMESTAMP',
                ]
            );

            $table->addColumn(
                'updated',
                Types::DATETIME,
                [
                    'notnull' => true,
                    'default' => 'CURRENT_TIMESTAMP',
                ]
            );

            $output->info('✅ Created endpoints table');
        } else {
            $output->info('ℹ️  Endpoints table already exists');
        }//end if

        // Create endpoint_logs table.
        if ($schema->hasTable('openregister_endpoint_logs') === false) {
            $output->info('📝 Creating endpoint_logs table...');

            $table = $schema->createTable('openregister_endpoint_logs');

            // Primary key.
            $table->addColumn(
                'id',
                Types::BIGINT,
                [
                    'autoincrement' => true,
                    'notnull'       => true,
                    'unsigned'      => true,
                ]
            );
            $table->setPrimaryKey(['id']);

            // UUID for external reference.
            $table->addColumn(
                'uuid',
                Types::STRING,
                [
                    'notnull' => true,
                    'length'  => 255,
                ]
            );
            $table->addUniqueIndex(['uuid'], 'endpoint_logs_uuid_index');

            // Status information.
            $table->addColumn(
                'status_code',
                Types::INTEGER,
                [
                    'notnull' => false,
                ]
            );

            $table->addColumn(
                'status_message',
                Types::TEXT,
                [
                    'notnull' => false,
                ]
            );

            // Request and response data.
            $table->addColumn(
                'request',
                Types::JSON,
                [
                    'notnull' => false,
                ]
            );

            $table->addColumn(
                'response',
                Types::JSON,
                [
                    'notnull' => false,
                ]
            );

            // Reference to endpoint.
            $table->addColumn(
                'endpoint_id',
                Types::INTEGER,
                [
                    'notnull' => false,
                ]
            );
            $table->addIndex(['endpoint_id'], 'endpoint_logs_endpoint_id_index');

            // User and session information.
            $table->addColumn(
                'user_id',
                Types::STRING,
                [
                    'notnull' => false,
                    'length'  => 64,
                ]
            );

            $table->addColumn(
                'session_id',
                Types::STRING,
                [
                    'notnull' => false,
                    'length'  => 255,
                ]
            );

            // Expiry and timestamps.
            $table->addColumn(
                'expires',
                Types::DATETIME,
                [
                    'notnull' => false,
                ]
            );
            $table->addIndex(['expires'], 'endpoint_logs_expires_index');

            $table->addColumn(
                'created',
                Types::DATETIME,
                [
                    'notnull' => true,
                    'default' => 'CURRENT_TIMESTAMP',
                ]
            );
            $table->addIndex(['created'], 'endpoint_logs_created_index');

            // Size for storage management.
            $table->addColumn(
                'size',
                Types::INTEGER,
                [
                    'notnull' => true,
                    'default' => 4096,
                ]
            );

            $output->info('✅ Created endpoint_logs table');
        } else {
            $output->info('ℹ️  Endpoint_logs table already exists');
        }//end if

        return $schema;

    }//end changeSchema()


    /**
     * Post-schema change hook
     *
     * @param IOutput $output        Output handler
     * @param Closure $schemaClosure Schema closure
     * @param array   $options       Options
     *
     * @return void
     */
    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    {
        $output->info('✅ Endpoint management system migration complete');
        $output->info('   Endpoints can now be created to expose views, agents, webhooks, registers, and schemas');

    }//end postSchemaChange()


}//end class
