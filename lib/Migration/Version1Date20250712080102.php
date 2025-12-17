<?php
/**
 * OpenRegister Migration - Create Search Trails Table
 *
 * This migration creates the search_trails table for logging search operations,
 * including search terms, query parameters, results, and user/request information.
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
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
 * Migration to create the search_trails table for search analytics
 */
class Version1Date20250712080102 extends SimpleMigrationStep
{
    /**
     * Pre-schema change operations
     *
     * @param IOutput                   $output        Output interface for migration progress
     * @param Closure(): ISchemaWrapper $schemaClosure Schema closure function
     * @param array                     $options       Migration options
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    {
        // No pre-schema changes required.
    }//end preSchemaChange()

    /**
     * Create the search_trails table
     *
     * @param IOutput                   $output        Output interface for migration progress
     * @param Closure(): ISchemaWrapper $schemaClosure Schema closure function
     * @param array                     $options       Migration options
     *
     * @return ISchemaWrapper
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        $schema = $schemaClosure();

        if ($schema->hasTable('openregister_search_trails') === false) {
            $table = $schema->createTable('openregister_search_trails');

            // Primary key.
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);

            // Unique identifier.
            $table->addColumn('uuid', Types::STRING, ['notnull' => true, 'length' => 255]);

            // Search information.
            $table->addColumn('search_term', Types::STRING, ['notnull' => false, 'length' => 1000]);
            $table->addColumn('query_parameters', Types::JSON, ['notnull' => false]);
            $table->addColumn('filters', Types::JSON, ['notnull' => false]);
            $table->addColumn('sort_parameters', Types::JSON, ['notnull' => false]);

            // Result information.
            $table->addColumn('result_count', Types::INTEGER, ['notnull' => false]);
            $table->addColumn('total_results', Types::INTEGER, ['notnull' => false]);

            // Context information.
            $table->addColumn('register', Types::INTEGER, ['notnull' => false]);
            $table->addColumn('schema', Types::INTEGER, ['notnull' => false]);
            $table->addColumn('register_uuid', Types::STRING, ['notnull' => false, 'length' => 255]);
            $table->addColumn('schema_uuid', Types::STRING, ['notnull' => false, 'length' => 255]);

            // User information.
            $table->addColumn('user', Types::STRING, ['notnull' => false, 'length' => 255]);
            $table->addColumn('user_name', Types::STRING, ['notnull' => false, 'length' => 255]);
            $table->addColumn('session', Types::STRING, ['notnull' => false, 'length' => 255]);

            // Request information.
            $table->addColumn('ip_address', Types::STRING, ['notnull' => false, 'length' => 45]);
            $table->addColumn('user_agent', Types::TEXT, ['notnull' => false]);
            $table->addColumn('request_uri', Types::TEXT, ['notnull' => false]);
            $table->addColumn('http_method', Types::STRING, ['notnull' => false, 'length' => 10]);

            // Performance information.
            $table->addColumn('response_time', Types::INTEGER, ['notnull' => false]);

            // Pagination information.
            $table->addColumn('page', Types::INTEGER, ['notnull' => false]);
            $table->addColumn('limit', Types::INTEGER, ['notnull' => false]);
            $table->addColumn('offset', Types::INTEGER, ['notnull' => false]);

            // Feature flags.
            $table->addColumn('facets_requested', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
            $table->addColumn('facetable_requested', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
            $table->addColumn('published_only', Types::BOOLEAN, ['notnull' => false, 'default' => false]);

            // Execution type.
            $table->addColumn('execution_type', Types::STRING, ['notnull' => false, 'length' => 10]);

            // Privacy/compliance fields.
            $table->addColumn('organisation_id', Types::STRING, ['notnull' => false, 'length' => 255]);
            $table->addColumn('organisation_id_type', Types::STRING, ['notnull' => false, 'length' => 64]);

            // Timestamps.
            $table->addColumn('created', Types::DATETIME, ['notnull' => true, 'default' => 'CURRENT_TIMESTAMP']);
            $table->addColumn('expires', Types::DATETIME, ['notnull' => false]);

            // Set primary key.
            $table->setPrimaryKey(['id']);

            // Add indexes for performance.
            $table->addIndex(['uuid'], 'search_trails_uuid_index');
            $table->addIndex(['search_term'], 'search_trails_search_term_index');
            $table->addIndex(['register'], 'search_trails_register_index');
            $table->addIndex(['schema'], 'search_trails_schema_index');
            $table->addIndex(['user'], 'search_trails_user_index');
            $table->addIndex(['ip_address'], 'search_trails_ip_address_index');
            $table->addIndex(['created'], 'search_trails_created_index');
            $table->addIndex(['expires'], 'search_trails_expires_index');
            $table->addIndex(['execution_type'], 'search_trails_execution_type_index');

            // Composite indexes for common query patterns.
            $table->addIndex(['register', 'schema'], 'search_trails_register_schema_index');
            $table->addIndex(['created', 'register'], 'search_trails_created_register_index');
            $table->addIndex(['user', 'created'], 'search_trails_user_created_index');
        }//end if

        return $schema;

    }//end changeSchema()

    /**
     * Post-schema change operations
     *
     * @param IOutput                   $output        Output interface for migration progress
     * @param Closure(): ISchemaWrapper $schemaClosure Schema closure function
     * @param array                     $options       Migration options
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    {
        // No post-schema changes required.
    }//end postSchemaChange()
}//end class
