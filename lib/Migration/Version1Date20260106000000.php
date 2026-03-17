<?php

/**
 * OpenRegister Migration Version1Date20260106000000
 *
 * Migration to create the openregister_mappings table for data transformation mappings.
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
 * Create mappings table for data transformation configurations
 *
 * This migration creates the openregister_mappings table which stores
 * mapping configurations for transforming data between different formats.
 * Mappings use Twig templating for dynamic value transformation and
 * support type casting, key unsetting, and pass-through modes.
 *
 * @category  Migration
 * @package   OCA\OpenRegister\Migration
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength) Table creation requires detailed column definitions
 * @SuppressWarnings(PHPMD.ElseExpression)        Else clause used for table existence check
 */
class Version1Date20260106000000 extends SimpleMigrationStep
{
    /**
     * Execute actions before schema changes
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
    }//end preSchemaChange()

    /**
     * Apply schema changes
     *
     * Creates the openregister_mappings table with all required columns
     * for storing mapping configurations.
     *
     * @param IOutput                   $output        Output interface for migration progress
     * @param Closure(): ISchemaWrapper $schemaClosure Schema closure function
     * @param array                     $options       Migration options
     *
     * @return ISchemaWrapper|null The modified schema wrapper
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /*
         * @var ISchemaWrapper $schema
         */

        $schema = $schemaClosure();

        if ($schema->hasTable('openregister_mappings') === false) {
            $output->info(message: '📋 Creating openregister_mappings table...');

            $table = $schema->createTable('openregister_mappings');

            // Primary key.
            $table->addColumn(
                'id',
                Types::BIGINT,
                [
                    'autoincrement' => true,
                    'notnull'       => true,
                ]
            );

            // UUID for external references.
            $table->addColumn(
                'uuid',
                Types::STRING,
                [
                    'notnull' => true,
                    'length'  => 255,
                ]
            );

            // External reference identifier.
            $table->addColumn(
                'reference',
                Types::STRING,
                [
                    'notnull' => false,
                    'length'  => 255,
                ]
            );

            // Semantic version.
            $table->addColumn(
                'version',
                Types::STRING,
                [
                    'notnull' => true,
                    'length'  => 255,
                    'default' => '0.0.1',
                ]
            );

            // Human-readable name.
            $table->addColumn(
                'name',
                Types::STRING,
                [
                    'notnull' => true,
                    'length'  => 255,
                ]
            );

            // Description of the mapping.
            $table->addColumn(
                'description',
                Types::TEXT,
                [
                    'notnull' => false,
                ]
            );

            // JSON mapping configuration (Twig templates).
            $table->addColumn(
                'mapping',
                Types::JSON,
                [
                    'notnull' => false,
                ]
            );

            // JSON array of keys to unset from output.
            $table->addColumn(
                'unset',
                Types::JSON,
                [
                    'notnull' => false,
                ]
            );

            // JSON object of type casting rules.
            $table->addColumn(
                'cast',
                Types::JSON,
                [
                    'notnull' => false,
                ]
            );

            // Pass-through mode flag.
            $table->addColumn(
                'pass_through',
                Types::BOOLEAN,
                [
                    'notnull' => true,
                    'default' => false,
                ]
            );

            // JSON array of configuration IDs.
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

            // Organisation UUID for multi-tenancy.
            $table->addColumn(
                'organisation',
                Types::STRING,
                [
                    'notnull' => false,
                    'length'  => 255,
                ]
            );

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

            // Set primary key.
            $table->setPrimaryKey(['id']);

            // Add indexes for common queries.
            $table->addIndex(['uuid'], 'openreg_mappings_uuid_idx');
            $table->addIndex(['name'], 'openreg_mappings_name_idx');
            $table->addIndex(['slug'], 'openreg_mappings_slug_idx');
            $table->addIndex(['organisation'], 'openreg_mappings_org_idx');

            $output->info(message: '   ✓ Table openregister_mappings created successfully');
        } else {
            $output->info(message: '   ℹ️  Table openregister_mappings already exists, skipping');
        }//end if

        return $schema;
    }//end changeSchema()

    /**
     * Performs actions after schema changes
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
        $output->info(message: '✅ Migration Version1Date20260106000000 completed - mappings table ready');
    }//end postSchemaChange()
}//end class
