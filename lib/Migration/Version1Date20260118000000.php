<?php

/**
 * OpenRegister Migration Version1Date20260118000000
 *
 * Migration to add the 'active' column to the openregister_organisations table.
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
 * Add active column to organisations table
 *
 * This migration adds an 'active' boolean column to the openregister_organisations
 * table to track whether an organisation is active or inactive. This is used by
 * the Software Catalog to manage organisation status and user access.
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
 */
class Version1Date20260118000000 extends SimpleMigrationStep
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
     * Adds the 'active' column to the openregister_organisations table.
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

        if ($schema->hasTable('openregister_organisations') === true) {
            $table = $schema->getTable('openregister_organisations');

            // Check if active column already exists
            if ($table->hasColumn('active') === false) {
                $output->info(message: '📋 Adding active column to openregister_organisations table...');

                $table->addColumn(
                    'active',
                    Types::BOOLEAN,
                    [
                        'notnull' => true,
                        'default' => true,
                        'comment' => 'Whether the organisation is active'
                    ]
                );

                $output->info(message: '   ✓ Column active added successfully');
            } else {
                $output->info(message: '   ℹ️  Column active already exists, skipping');
            }//end if
        } else {
            $output->info(message: '   ⚠️  Table openregister_organisations does not exist, skipping');
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
        $output->info(message: '✅ Migration Version1Date20260118000000 completed - active column ready');
    }//end postSchemaChange()
}//end class
