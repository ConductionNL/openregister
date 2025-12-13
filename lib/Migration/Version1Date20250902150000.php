<?php

declare(strict_types=1);

/**
 * OpenRegister Migration - Facets Column
 *
 * Migration to add facets column to openregister_schemas table.
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
 * @link https://www.OpenRegister.app
 */

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Migration to add facets column to openregister_schemas table
 *
 * **PERFORMANCE OPTIMIZATION**: This migration adds a facets column to store
 * pre-computed facetable field configurations, eliminating the need for runtime
 * schema analysis when _facetable=true is requested.
 *
 * Benefits:
 * - Eliminates ~15ms runtime analysis per _facetable=true request
 * - Provides consistent facet configurations based on schema properties
 * - Enables faster facet discovery and configuration
 * - Reduces database queries during faceting operations
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
 * @link https://www.OpenRegister.app
 */
class Version1Date20250902150000 extends SimpleMigrationStep
{


    /**
     * Add facets column to schemas table for performance optimization
     *
     * @param IOutput $output        Migration output interface
     * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
     * @param array   $options       Migration options
     *
     * @return null|ISchemaWrapper Updated schema or null
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        $schema = $schemaClosure();

        if ($schema->hasTable('openregister_schemas') === false) {
            return null;
        }

        $table = $schema->getTable('openregister_schemas');

        // Add facets column for pre-computed facet configurations.
        if ($table->hasColumn('facets') === false) {
            $table->addColumn(
                'facets',
                Types::JSON,
                [
                    'notnull' => false,
                    'default' => null,
                    'comment' => 'Pre-computed facetable field configurations for performance optimization',
                ]
            );
            $output->info(message: 'Added facets column to openregister_schemas table for facet caching');
        }

        return $schema;

    }//end changeSchema()


    /**
     * Post-schema changes to regenerate facets for existing schemas
     *
     * @param IOutput $output        Migration output interface
     * @param Closure $schemaClosure Schema closure
     * @param array   $options       Migration options
     *
     * @return void
     */
    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    {
        // Note: We'll regenerate facets via an OCC command rather than in migration.
        // to avoid dependency injection issues during migration.
        $output->info('Facets column added. Run `occ openregister:regenerate-facets` to populate facet data for existing schemas.');

    }//end postSchemaChange()


}//end class
