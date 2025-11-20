<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Create table for performance and usage metrics
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 * @author   Conduction <info@conduction.nl>
 * @license  EUPL-1.2 https://opensource.org/licenses/EUPL-1.2
 * @link     https://www.conduction.nl
 */
class Version002005000Date20251013000000 extends SimpleMigrationStep
{


    /**
     * @param IOutput $output
     * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
     * @param array   $options
     *
     * @return null|ISchemaWrapper
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /*
         *
         *
         * @var ISchemaWrapper $schema
         */
        $schema = $schemaClosure();

        // Create openregister_metrics table for tracking operational metrics.
        if (!$schema->hasTable('openregister_metrics')) {
            $table = $schema->createTable('openregister_metrics');

            // Primary key.
            $table->addColumn(
            'id',
            'bigint',
            [
                'autoincrement' => true,
                'notnull'       => true,
                'length'        => 20,
            ]
            );

            // Metric type (e.g., 'file_processed', 'embedding_generated', 'search_executed').
            $table->addColumn(
            'metric_type',
            'string',
            [
                'notnull' => true,
                'length'  => 64,
            ]
            );

            // Entity type (e.g., 'file', 'object', 'search').
            $table->addColumn(
            'entity_type',
            'string',
            [
                'notnull' => false,
                'length'  => 32,
                'default' => null,
            ]
            );

            // Entity ID (if applicable).
            $table->addColumn(
            'entity_id',
            'string',
            [
                'notnull' => false,
                'length'  => 64,
                'default' => null,
            ]
            );

            // User who triggered the action.
            $table->addColumn(
            'user_id',
            'string',
            [
                'notnull' => false,
                'length'  => 64,
                'default' => null,
            ]
            );

            // Success or failure.
            $table->addColumn(
            'status',
            'string',
            [
                'notnull' => true,
                'length'  => 20,
                'default' => 'success',
            ]
            );

            // Duration in milliseconds.
            $table->addColumn(
            'duration_ms',
            'integer',
            [
                'notnull' => false,
                'default' => null,
            ]
            );

            // Additional metadata (JSON).
            $table->addColumn(
            'metadata',
            'text',
            [
                'notnull' => false,
                'default' => null,
            ]
            );

            // Error message (if failed).
            $table->addColumn(
            'error_message',
            'text',
            [
                'notnull' => false,
                'default' => null,
            ]
            );

            // Timestamp.
            $table->addColumn(
            'created_at',
            'bigint',
            [
                'notnull' => true,
            ]
            );

            // Set primary key.
            $table->setPrimaryKey(['id']);

            // Add indexes for common queries.
            $table->addIndex(['metric_type'], 'idx_metrics_type');
            $table->addIndex(['entity_type'], 'idx_metrics_entity_type');
            $table->addIndex(['status'], 'idx_metrics_status');
            $table->addIndex(['created_at'], 'idx_metrics_created');
            $table->addIndex(['metric_type', 'created_at'], 'idx_metrics_type_created');
            $table->addIndex(['entity_type', 'created_at'], 'idx_metrics_entity_created');
        }//end if

        return $schema;

    }//end changeSchema()


    /**
     * Rollback migration
     *
     * @param IOutput $output
     * @param Closure $schemaClosure
     * @param array   $options
     *
     * @return null|ISchemaWrapper
     */
    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        return null;

    }//end postSchemaChange()


}//end class
