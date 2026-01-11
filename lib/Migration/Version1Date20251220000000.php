<?php

/**
 * OpenRegister Migration Version1Date20251220000000
 *
 * Migration to add configuration column to openregister_registers table for magic mapping.
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
 * Add configuration column to openregister_registers table for magic mapping
 *
 * This migration adds a configuration column to the registers table to support
 * per-register+schema magic mapping configuration. Magic mapping enables storing
 * objects in dedicated tables with schema properties mapped to columns for improved
 * indexing and query performance.
 *
 * @category  Migration
 * @package   OCA\OpenRegister\Migration
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 */

class Version1Date20251220000000 extends SimpleMigrationStep
{
    /**
     * Change database schema
     *
     * @param IOutput $output        Output interface for migration messages.
     * @param Closure $schemaClosure Schema closure that returns the current schema.
     * @param array   $options       Additional migration options.
     *
     * @return ISchemaWrapper|null The modified schema or null if no changes.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /*
         * @var ISchemaWrapper $schema
         */

        $schema = $schemaClosure();

        // Add configuration column to openregister_registers table if it exists.
        if ($schema->hasTable('openregister_registers') === true) {
            $table = $schema->getTable('openregister_registers');

            // Add configuration column if it doesn't exist.
            if ($table->hasColumn('configuration') === false) {
                $table->addColumn(
                    'configuration',
                    Types::TEXT,
                    [
                        'notnull' => false,
                        'default' => null,
                    ]
                );
                $output->info('Added configuration column to openregister_registers table for magic mapping support.');
            }
        }

        return $schema;
    }//end changeSchema()
}//end class
