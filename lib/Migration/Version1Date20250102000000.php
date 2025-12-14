<?php
/**
 * OpenRegister Organisation Groups Migration
 *
 * This migration adds the groups field to the Organisation table to support
 * organisation-specific Nextcloud groups for role-based access control.
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
 * Migration to add groups field to organisations
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 */
class Version1Date20250102000000 extends SimpleMigrationStep
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
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     *
     * @return void
     */
    public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    {
        // No pre-schema changes required.
    }//end preSchemaChange()


    /**
     * Apply schema changes to add roles field
     *
     * @param IOutput                   $output        Output interface
     * @param Closure(): ISchemaWrapper $schemaClosure Schema closure
     * @param array                     $options       Migration options
     *
     * @return ISchemaWrapper
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        // Get schema wrapper instance from closure.
        $schema = $schemaClosure();

        // Add groups field to organisations table.
        if ($schema->hasTable('openregister_organisations') === true) {
            $table = $schema->getTable('openregister_organisations');

            // Add groups field (JSON array of Nextcloud group IDs).
            if ($table->hasColumn('groups') === false) {
                $table->addColumn(
                        'groups',
                        Types::JSON,
                        [
                            'notnull' => false,
                            'default' => '[]',
                            'comment' => 'Array of Nextcloud group IDs that have access to this organisation',
                        ]
                        );
                $output->info(message: 'Added groups column to organisations table');
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
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    {
        // Initialize groups to empty array for existing organisations.
        $qb = $this->connection->getQueryBuilder();

        $qb->update('openregister_organisations')
            ->set('groups', $qb->createNamedParameter('[]'))
            ->where($qb->expr()->isNull('groups'));

        $affected = $qb->executeStatement();

        if ($affected > 0) {
            $output->info(message: "Initialized groups field for {$affected} existing organisations");
        }

    }//end postSchemaChange()


}//end class
