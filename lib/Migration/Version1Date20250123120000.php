<?php
/**
 * OpenRegister Add Active Organisation Flag Migration
 *
 * This migration adds the active column to the organisations table
 * to allow enabling/disabling organisations.
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
use OCP\IDBConnection;

/**
 * Migration to add active column to organisations table
 */
class Version1Date20250123120000 extends SimpleMigrationStep
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
     *
     * @return void
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
     * Apply schema changes for active column
     *
     * @param IOutput                   $output        Output interface
     * @param Closure(): ISchemaWrapper $schemaClosure Schema closure
     * @param array                     $options       Migration options
     *
     * @return null|ISchemaWrapper
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        // Get schema from closure.
        $schema = $schemaClosure();

        // Add active field to organisations table.
        if ($schema->hasTable('openregister_organisations') === true) {
            $table = $schema->getTable('openregister_organisations');

            // Add active field (boolean flag for active organisation).
            if ($table->hasColumn('active') === false) {
                $table->addColumn(
                    'active',
                    Types::BOOLEAN,
                    [
                        'notnull' => false,
                        'default' => true,
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
        // No post-schema changes required.

    }//end postSchemaChange()


}//end class
