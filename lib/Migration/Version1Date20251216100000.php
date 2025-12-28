<?php

/**
 * OpenRegister Migration Version1Date20250126000000
 *
 * Migration to create webhook_logs table for webhook delivery logging.
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
 * Create webhook_logs table for webhook delivery logging
 */
class Version1Date20251216100000 extends SimpleMigrationStep
{
    /**
     * Change schema to create webhook_logs table
     *
     * @param IOutput $output        Migration output
     * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
     * @param array   $options       Migration options
     *
     * @return null|ISchemaWrapper
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        // @var ISchemaWrapper $schema
        $schema = $schemaClosure();

        if ($schema->hasTable('openregister_webhook_logs') === false) {
            $table = $schema->createTable('openregister_webhook_logs');

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

            // Webhook ID reference.
            $table->addColumn(
                'webhook_id',
                Types::BIGINT,
                [
                        'notnull'  => true,
                        'unsigned' => true,
                    ]
            );

            // Event class name.
            $table->addColumn(
                'event_class',
                Types::STRING,
                [
                        'notnull' => true,
                        'length'  => 255,
                    ]
            );

            // Payload data (JSON).
            $table->addColumn(
                'payload',
                Types::TEXT,
                [
                        'notnull' => false,
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

            // HTTP method.
            $table->addColumn(
                'method',
                Types::STRING,
                [
                        'notnull' => true,
                        'length'  => 10,
                        'default' => 'POST',
                    ]
            );

            // Success status.
            $table->addColumn(
                'success',
                Types::BOOLEAN,
                [
                        'notnull' => true,
                        'default' => false,
                    ]
            );

            // HTTP status code.
            $table->addColumn(
                'status_code',
                Types::INTEGER,
                [
                        'notnull' => false,
                    ]
            );

            // Request body (stored for debugging failures).
            $table->addColumn(
                'request_body',
                Types::TEXT,
                [
                        'notnull' => false,
                    ]
            );

            // Response body.
            $table->addColumn(
                'response_body',
                Types::TEXT,
                [
                        'notnull' => false,
                    ]
            );

            // Error message.
            $table->addColumn(
                'error_message',
                Types::TEXT,
                [
                        'notnull' => false,
                    ]
            );

            // Attempt number.
            $table->addColumn(
                'attempt',
                Types::INTEGER,
                [
                        'notnull' => true,
                        'default' => 1,
                    ]
            );

            // Next retry timestamp.
            $table->addColumn(
                'next_retry_at',
                Types::DATETIME,
                [
                        'notnull' => false,
                    ]
            );

            // Created timestamp.
            $table->addColumn(
                'created',
                Types::DATETIME,
                [
                        'notnull' => true,
                    ]
            );

            // Indexes.
            $table->setPrimaryKey(['id']);
            $table->addIndex(['webhook_id'], 'openregister_webhook_logs_webhook_id');
            $table->addIndex(['success'], 'openregister_webhook_logs_success');
            $table->addIndex(['next_retry_at'], 'openregister_webhook_logs_next_retry');
            $table->addIndex(['created'], 'openregister_webhook_logs_created');

            // Foreign key constraint.
            $table->addForeignKeyConstraint(
                foreignTable: 'openregister_webhooks',
                localColumnNames: ['webhook_id'],
                foreignColumnNames: ['id'],
                options: [
                        'onDelete' => 'CASCADE',
                    ]
            );

            return $schema;
        }//end if

        return null;
    }//end changeSchema()
}//end class
