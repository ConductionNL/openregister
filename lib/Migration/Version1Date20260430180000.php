<?php

/**
 * Migration adding `register_id`, `schema_id`, `object_uuid` columns to
 * `oc_openregister_entity_relations`.
 *
 * The pre-existing relation table only stored `object_id` (a plain
 * bigint pointing at a magic-table row). Magic-table sequences are
 * scoped per-table (each register/schema combo has its own table),
 * so the same `object_id` can collide across tables — DSAR composition
 * (`DsarService::findObjectsForSubject` / `eraseObjectsForSubject`)
 * couldn't reliably resolve the int back to a single concrete object.
 *
 * Adding `register_id` + `schema_id` + `object_uuid` makes the lookup
 * deterministic. The new columns are nullable so existing rows
 * (predating this migration) keep loading; new rows written by
 * `EntityRecognitionHandler` will populate them.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author  Conduction Development Team <dev@conduction.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\Types;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds disambiguating columns to entity_relations.
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
class Version1Date20260430180000 extends SimpleMigrationStep
{
    /**
     * Add register_id + schema_id + object_uuid + indexes when missing.
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

        if ($schema->hasTable(tableName: 'openregister_entity_relations') === false) {
            return $schema;
        }

        $table = $schema->getTable(tableName: 'openregister_entity_relations');

        if ($table->hasColumn(name: 'register_id') === false) {
            $table->addColumn(
                name: 'register_id',
                typeName: Types::STRING,
                options: ['notnull' => false, 'length' => 64]
            );
        }

        if ($table->hasColumn(name: 'schema_id') === false) {
            $table->addColumn(
                name: 'schema_id',
                typeName: Types::STRING,
                options: ['notnull' => false, 'length' => 64]
            );
        }

        if ($table->hasColumn(name: 'object_uuid') === false) {
            $table->addColumn(
                name: 'object_uuid',
                typeName: Types::STRING,
                options: ['notnull' => false, 'length' => 64]
            );
        }

        if ($table->hasIndex(name: 'idx_relations_register_schema') === false) {
            $table->addIndex(
                columnNames: ['register_id', 'schema_id'],
                indexName: 'idx_relations_register_schema'
            );
        }

        if ($table->hasIndex(name: 'idx_relations_object_uuid') === false) {
            $table->addIndex(
                columnNames: ['object_uuid'],
                indexName: 'idx_relations_object_uuid'
            );
        }

        return $schema;

    }//end changeSchema()
}//end class
