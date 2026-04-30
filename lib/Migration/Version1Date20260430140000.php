<?php

/**
 * Migration adding the `type` column to `openregister_registers`.
 *
 * Lets the import pipeline persist `x-openregister.type` (e.g. "mock",
 * "production", "demo") as first-class register metadata so consuming
 * apps can filter mock/demo data out of production deployments via the
 * `GET /api/registers?filters[type]=mock` query.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use Doctrine\DBAL\Types\Types;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds a nullable `type` column + index on `openregister_registers`.
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
class Version1Date20260430140000 extends SimpleMigrationStep
{
    /**
     * Add the column + index when missing.
     *
     * @param IOutput $output        Migration output sink.
     * @param Closure $schemaClosure Closure returning the ISchemaWrapper.
     * @param array   $options       Migration options (unused).
     *
     * @return ISchemaWrapper|null
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /*
         * @var ISchemaWrapper $schema
         */

        $schema = $schemaClosure();

        if ($schema->hasTable(tableName: 'openregister_registers') === false) {
            return $schema;
        }

        $table = $schema->getTable(tableName: 'openregister_registers');

        if ($table->hasColumn(name: 'type') === false) {
            $table->addColumn(
                name: 'type',
                typeName: Types::STRING,
                options: [
                    'notnull' => false,
                    'length'  => 32,
                ],
            );
        }

        if ($table->hasIndex(name: 'idx_registers_type') === false) {
            $table->addIndex(columnNames: ['type'], indexName: 'idx_registers_type');
        }

        return $schema;

    }//end changeSchema()
}//end class
