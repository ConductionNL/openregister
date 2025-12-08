<?php
/**
 * OpenRegister Migration Version1Date20251120210000
 *
 * Migration to create webhooks table for webhook integration.
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
 * Create webhooks table for webhook integration
 */
class Version1Date20251120210000 extends SimpleMigrationStep
{


    /**
     * Change schema for webhooks table creation
     *
     * @param IOutput $output        Migration output
     * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
     * @param array   $options       Migration options
     *
     * @return null|ISchemaWrapper
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        // @var ISchemaWrapper $schema
        $schema = $schemaClosure();

        if ($schema->hasTable('openregister_webhooks') === false) {
            $table = $schema->createTable('openregister_webhooks');

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
            // Set primary key immediately to ensure it's available for foreign key references.
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

            // Webhook name/description.
            $table->addColumn(
                    'name',
                    Types::STRING,
                    [
                        'notnull' => true,
                        'length'  => 255,
                    ]
                    );

            // Target URL.
            $table->addColumn(
                    'url',
                    Types::STRING,
                    [
                        'notnull' => true,
                        'length'  => 1024,
                    ]
                    );

            // HTTP method (POST, PUT, GET, etc.).
            $table->addColumn(
                    'method',
                    Types::STRING,
                    [
                        'notnull' => true,
                        'length'  => 10,
                        'default' => 'POST',
                    ]
                    );

            // Events to listen to (JSON array).
            $table->addColumn(
                    'events',
                    Types::TEXT,
                    [
                        'notnull' => true,
                    ]
                    );

            // Custom headers (JSON object).
            $table->addColumn(
                    'headers',
                    Types::TEXT,
                    [
                        'notnull' => false,
                    ]
                    );

            // Secret for signing payloads.
            $table->addColumn(
                    'secret',
                    Types::STRING,
                    [
                        'notnull' => false,
                        'length'  => 255,
                    ]
                    );

            // Is webhook enabled.
            $table->addColumn(
                    'enabled',
                    Types::BOOLEAN,
                    [
                        'notnull' => true,
                        'default' => true,
                    ]
                    );

            // Organisation (multi-tenancy).
            $table->addColumn(
                    'organisation',
                    Types::STRING,
                    [
                        'notnull' => false,
                        'length'  => 255,
                    ]
                    );

            // Event filters (JSON object).
            $table->addColumn(
                    'filters',
                    Types::TEXT,
                    [
                        'notnull' => false,
                    ]
                    );

            // Delivery configuration.
            $table->addColumn(
                    'retry_policy',
                    Types::STRING,
                    [
                        'notnull' => true,
                        'length'  => 50,
                        'default' => 'exponential',
                    ]
                    );

            $table->addColumn(
                    'max_retries',
                    Types::INTEGER,
                    [
                        'notnull' => true,
                        'default' => 3,
                    ]
                    );

            $table->addColumn(
                    'timeout',
                    Types::INTEGER,
                    [
                        'notnull' => true,
                        'default' => 30,
                    ]
                    );

            // Statistics.
            $table->addColumn(
                    'last_triggered_at',
                    Types::DATETIME,
                    [
                        'notnull' => false,
                    ]
                    );

            $table->addColumn(
                    'last_success_at',
                    Types::DATETIME,
                    [
                        'notnull' => false,
                    ]
                    );

            $table->addColumn(
                    'last_failure_at',
                    Types::DATETIME,
                    [
                        'notnull' => false,
                    ]
                    );

            $table->addColumn(
                    'total_deliveries',
                    Types::INTEGER,
                    [
                        'notnull' => true,
                        'default' => 0,
                    ]
                    );

            $table->addColumn(
                    'successful_deliveries',
                    Types::INTEGER,
                    [
                        'notnull' => true,
                        'default' => 0,
                    ]
                    );

            $table->addColumn(
                    'failed_deliveries',
                    Types::INTEGER,
                    [
                        'notnull' => true,
                        'default' => 0,
                    ]
                    );

            // Timestamps.
            $table->addColumn(
                    'created',
                    Types::DATETIME,
                    [
                        'notnull' => true,
                    ]
                    );

            $table->addColumn(
                    'updated',
                    Types::DATETIME,
                    [
                        'notnull' => true,
                    ]
                    );

            // Configuration (JSON object for additional webhook configuration).
            $table->addColumn(
                    'configuration',
                    Types::TEXT,
                    [
                        'notnull' => false,
                        'comment' => 'Additional webhook configuration (JSON object)',
                    ]
                    );

            // Indexes (primary key already set above).
            $table->addUniqueIndex(['uuid'], 'openregister_webhooks_uuid');
            $table->addIndex(['organisation'], 'openregister_webhooks_org');
            $table->addIndex(['enabled'], 'openregister_webhooks_enabled');

            $output->info('✅ Created webhooks table');
        } else {
            $output->info('ℹ️  Webhooks table already exists');
        }//end if

        // Create webhook_logs table if it doesn't exist.
        if ($schema->hasTable('openregister_webhook_logs') === false) {
            $output->info('📝 Creating webhook_logs table...');

            $logsTable = $schema->createTable('openregister_webhook_logs');

            // Primary key.
            $logsTable->addColumn(
                    'id',
                    Types::BIGINT,
                    [
                        'autoincrement' => true,
                        'notnull'       => true,
                        'unsigned'      => true,
                    ]
                    );
            $logsTable->setPrimaryKey(['id']);

            // Reference to webhook (using 'webhook' instead of 'webhook_id' to prevent Doctrine auto-foreign-key).
            $logsTable->addColumn(
                    'webhook',
                    Types::BIGINT,
                    [
                        'notnull' => true,
                        'unsigned' => true,
                        'comment' => 'References openregister_webhooks.id',
                    ]
                    );
            $logsTable->addIndex(['webhook'], 'webhook_logs_webhook_idx');

            // Event information.
            $logsTable->addColumn(
                    'event_class',
                    Types::STRING,
                    [
                        'notnull' => true,
                        'length'  => 255,
                    ]
                    );

            // Payload data (JSON).
            $logsTable->addColumn(
                    'payload',
                    Types::TEXT,
                    [
                        'notnull' => false,
                    ]
                    );

            // Target URL and method.
            $logsTable->addColumn(
                    'url',
                    Types::STRING,
                    [
                        'notnull' => true,
                        'length'  => 1024,
                    ]
                    );

            $logsTable->addColumn(
                    'method',
                    Types::STRING,
                    [
                        'notnull' => true,
                        'length'  => 10,
                        'default' => 'POST',
                    ]
                    );

            // Delivery status.
            $logsTable->addColumn(
                    'success',
                    Types::BOOLEAN,
                    [
                        'notnull' => true,
                        'default' => false,
                    ]
                    );

            $logsTable->addColumn(
                    'status_code',
                    Types::INTEGER,
                    [
                        'notnull' => false,
                    ]
                    );

            // Request and response bodies (stored only on failure for debugging).
            $logsTable->addColumn(
                    'request_body',
                    Types::TEXT,
                    [
                        'notnull' => false,
                    ]
                    );

            $logsTable->addColumn(
                    'response_body',
                    Types::TEXT,
                    [
                        'notnull' => false,
                    ]
                    );

            // Error information.
            $logsTable->addColumn(
                    'error_message',
                    Types::TEXT,
                    [
                        'notnull' => false,
                    ]
                    );

            // Retry information.
            $logsTable->addColumn(
                    'attempt',
                    Types::INTEGER,
                    [
                        'notnull' => true,
                        'default' => 1,
                    ]
                    );

            $logsTable->addColumn(
                    'next_retry_at',
                    Types::DATETIME,
                    [
                        'notnull' => false,
                    ]
                    );
            $logsTable->addIndex(['next_retry_at'], 'webhook_logs_next_retry_at_idx');

            // Timestamp.
            $logsTable->addColumn(
                    'created',
                    Types::DATETIME,
                    [
                        'notnull' => true,
                    ]
                    );
            $logsTable->addIndex(['created'], 'webhook_logs_created_idx');

            $output->info('✅ Created webhook_logs table');
        } else {
            $output->info('ℹ️  Webhook_logs table already exists');
        }//end if

        // NOTE: No foreign key constraint added due to Nextcloud/Doctrine prefix handling issues.
        // Referential integrity is maintained by the application code instead.
        // When deleting a webhook, the application will also delete associated logs.

        return $schema;

    }//end changeSchema()


}//end class
