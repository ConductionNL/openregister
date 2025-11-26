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

            // Indexes.
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['uuid'], 'openregister_webhooks_uuid');
            $table->addIndex(['organisation'], 'openregister_webhooks_org');
            $table->addIndex(['enabled'], 'openregister_webhooks_enabled');

            return $schema;
        }//end if

        return null;

    }//end changeSchema()


}//end class
