<?php

/**
 * Database migration to add `tmlo` column to the openregister_objects table.
 *
 * Adds a JSON column for storing TMLO (Toepassingsprofiel Metadatastandaard
 * Lokale Overheden) archival metadata on objects.
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
<<<<<<< HEAD
 * @author  Conduction Development Team <dev@conduction.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
=======
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
>>>>>>> origin/development
 *
 * @link https://OpenRegister.app
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds the `tmlo` JSON column to the openregister_objects table.
 *
 * This column stores TMLO-compliant archival metadata including:
 * classificatie, archiefnominatie, archiefactiedatum, archiefstatus,
 * bewaarTermijn, and vernietigingsCategorie.
 *
 * @package OCA\OpenRegister\Migration
 */
class Version1Date20260325000000 extends SimpleMigrationStep
{
    /**
     * Change the database schema.
     *
     * @param IOutput $output        Migration output
     * @param Closure $schemaClosure Schema closure
     * @param array   $options       Migration options
     *
     * @return ISchemaWrapper|null The updated schema or null if no changes
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        // Get the schema wrapper from the closure.
        $schema = $schemaClosure();

        $tableName = 'openregister_objects';

        if ($schema->hasTable($tableName) === false) {
            $output->info("Table {$tableName} does not exist, skipping migration");
            return null;
        }

        $table = $schema->getTable($tableName);

        if ($table->hasColumn('tmlo') === true) {
            $output->info("Column 'tmlo' already exists in {$tableName}, skipping");
            return null;
        }

        $table->addColumn(
            'tmlo',
            Types::TEXT,
            [
                'notnull' => false,
                'default' => null,
            ]
        );

        $output->info("Added 'tmlo' column to {$tableName}");

        return $schema;
    }//end changeSchema()
}//end class
