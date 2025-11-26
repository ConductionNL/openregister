<?php
declare(strict_types=1);
/*
 * Add authorization column to organisations and applications
 *
 * This migration adds the authorization column (JSON type) to both
 * openregister_organisations and openregister_applications tables
 * to support RBAC (Role-Based Access Control).
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @version   GIT: <git-id>
 * @link      https://www.openregister.nl
 */

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add authorization column for RBAC support
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 */
class Version1Date20251103130000 extends SimpleMigrationStep
{


    /**
     * Add authorization column to organisations and applications tables
     *
     * @param IOutput $output        The migration output handler
     * @param Closure $schemaClosure The closure to get the schema
     * @param array   $options       Migration options
     *
     * @return null|ISchemaWrapper The updated schema or null
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        $schema  = $schemaClosure();
        $updated = false;

        // Add authorization to organisations table.
        if ($schema->hasTable('openregister_organisations') === true) {
            $organisationsTable = $schema->getTable('openregister_organisations');

            if ($organisationsTable->hasColumn('authorization') === false) {
                $organisationsTable->addColumn(
                    'authorization',
                    Types::TEXT,
                    [
                        'notnull' => false,
                        'default' => null,
                    ]
                );
                $output->info(message: 'Added authorization column to openregister_organisations');
                $updated = true;
            }
        }//end if

        // Add authorization to applications table.
        if ($schema->hasTable('openregister_applications') === true) {
            $applicationsTable = $schema->getTable('openregister_applications');

            if ($applicationsTable->hasColumn('authorization') === false) {
                $applicationsTable->addColumn(
                    'authorization',
                    Types::TEXT,
                    [
                        'notnull' => false,
                        'default' => null,
                    ]
                );
                $output->info(message: 'Added authorization column to openregister_applications');
                $updated = true;
            }
        }//end if

        if ($updated === true) {
            return $schema;
        }

        return null;

    }//end changeSchema()


}//end class
