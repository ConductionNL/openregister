<?php
/**
 * OpenRegister Organisation Active Field Migration
 *
 * This migration adds the active field to the Organisation table to support
 * enabling/disabling organisations.
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use OCP\IDBConnection;

/**
 * Migration to add active field to organisations
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 */
class Version1Date20250102000001 extends SimpleMigrationStep
{

    /**
     * Database connection
     *
     * @var IDBConnection
     */
    private IDBConnection $connection;


    /**
     * Constructor
     *
     * @param IDBConnection $connection Database connection
     */
    public function __construct(IDBConnection $connection)
    {
        $this->connection = $connection;

    }//end __construct()


    /**
     * Pre-schema change operations
     *
     * @param IOutput                   $output        Output interface
     * @param Closure(): ISchemaWrapper $schemaClosure Schema closure
     * @param array                     $options       Migration options
     *
     * @return void
     */
    public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    {
        // No pre-schema changes required.
    }//end preSchemaChange()


    /**
     * Apply schema changes to add active field
     *
     * @param IOutput                   $output        Output interface
     * @param Closure(): ISchemaWrapper $schemaClosure Schema closure
     * @param array                     $options       Migration options
     *
     * @return null|ISchemaWrapper
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        // Get schema wrapper instance from closure.
        $schema = $schemaClosure();

        // Add active field to organisations table.
        if ($schema->hasTable('openregister_organisations') === true) {
            $table = $schema->getTable('openregister_organisations');

            // Add active field (boolean).
            if ($table->hasColumn('active') === false) {
                $table->addColumn(
                        'active',
                        Types::BOOLEAN,
                        [
                            'notnull' => true,
                            'default' => true,
                            'comment' => 'Whether the organisation is active',
                        ]
                        );
                $output->info(message: 'Added active column to organisations table');
            }
        }

        return $schema;

    }//end changeSchema()


    /**
     * Post-schema change operations
     *
     * @param IOutput                   $output        Output interface
     * @param Closure(): ISchemaWrapper $schemaClosure Schema closure
     * @param array                     $options       Migration options
     *
     * @return void
     */
    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    {
        // All organisations should be active by default (already set by column default).
        $output->info(message: 'All existing organisations are now active by default');

    }//end postSchemaChange()


}//end class
