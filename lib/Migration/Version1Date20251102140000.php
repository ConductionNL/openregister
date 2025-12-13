<?php

declare(strict_types=1);

/**
 * OpenRegister Views Table Migration
 *
 * This migration creates the 'openregister_views' table
 * to store saved search configurations (views).
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Migration to create views table
 *
 * This migration creates support for saved search views:
 * - Users can save complex search configurations
 * - Views can be made public to share with others
 * - Views can be set as default for a user
 * - Configuration includes registers, schemas, filters, and facets
 */
class Version1Date20251102140000 extends SimpleMigrationStep
{


    /**
     * Create views table
     *
     * @param IOutput $output        Migration output interface
     * @param Closure $schemaClosure Schema closure
     * @param array   $options       Migration options
     *
     * @return ISchemaWrapper|null Updated schema
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        $schema = $schemaClosure();

        $output->info(message: '🔧 Creating views table...');

        if ($schema->hasTable('openregister_views') === false) {
            $table = $schema->createTable('openregister_views');

            // Primary key.
            $table->addColumn(
                    'id',
                    Types::INTEGER,
                    [
                        'autoincrement' => true,
                        'notnull'       => true,
                        'unsigned'      => true,
                        'comment'       => 'Primary key',
                    ]
                    );

            // UUID for external references.
            $table->addColumn(
                    'uuid',
                    Types::STRING,
                    [
                        'notnull' => false,
                        'length'  => 255,
                        'comment' => 'Unique identifier for external references',
                    ]
                    );

            // View name.
            $table->addColumn(
                    'name',
                    Types::STRING,
                    [
                        'notnull' => true,
                        'length'  => 255,
                        'comment' => 'Name of the view',
                    ]
                    );

            // Description.
            $table->addColumn(
                    'description',
                    Types::TEXT,
                    [
                        'notnull' => false,
                        'comment' => 'Optional description of the view',
                    ]
                    );

            // Owner.
            $table->addColumn(
                    'owner',
                    Types::STRING,
                    [
                        'notnull' => true,
                        'length'  => 64,
                        'comment' => 'User ID of the view owner',
                    ]
                    );

            // Public flag.
            $table->addColumn(
                    'is_public',
                    Types::BOOLEAN,
                    [
                        'notnull' => true,
                        'default' => false,
                        'comment' => 'Whether the view is public and shareable',
                    ]
                    );

            // Default flag.
            $table->addColumn(
                    'is_default',
                    Types::BOOLEAN,
                    [
                        'notnull' => true,
                        'default' => false,
                        'comment' => 'Whether this is the user\'s default view',
                    ]
                    );

            // Query parameters as JSON.
            $table->addColumn(
                    'query',
                    Types::JSON,
                    [
                        'notnull' => true,
                        'comment' => 'Query parameters: registers, schemas, search terms, and facet filters',
                    ]
                    );

            // Favorited by users.
            $table->addColumn(
                    'favored_by',
                    Types::JSON,
                    [
                        'notnull' => false,
                        'default' => null,
                        'comment' => 'Array of user IDs who favorited this view',
                    ]
                    );

            // Timestamps.
            $table->addColumn(
                    'created',
                    Types::DATETIME,
                    [
                        'notnull' => true,
                        'comment' => 'Creation timestamp',
                    ]
                    );

            $table->addColumn(
                    'updated',
                    Types::DATETIME,
                    [
                        'notnull' => true,
                        'comment' => 'Last update timestamp',
                    ]
                    );

            // Set primary key.
            $table->setPrimaryKey(['id']);

            // Add indexes.
            $table->addIndex(['uuid'], 'views_uuid_index');
            $table->addIndex(['owner'], 'views_owner_index');
            $table->addIndex(['is_public'], 'views_public_index');
            $table->addIndex(['is_default'], 'views_default_index');
            $table->addIndex(['owner', 'is_default'], 'views_owner_default_index');

            $output->info(message: '✅ Created openregister_views table');
            $output->info('🎯 Views system now supports:');
            $output->info(message: '   • Saving reusable query filters');
            $output->info(message: '   • Multi-register and multi-schema constraints');
            $output->info(message: '   • Public and private views');
            $output->info(message: '   • Favorite views per user');
            $output->info(message: '   • Search terms, facets, and filters');
            $output->info('   • Future: Expose views as API endpoints');

            return $schema;
        } else {
            $output->info(message: 'ℹ️  Views table already exists, skipping...');
        }//end if

        return null;

    }//end changeSchema()


}//end class
