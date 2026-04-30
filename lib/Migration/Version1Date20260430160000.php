<?php

/**
 * Migration adding the `oc_openregister_verwerkingsactiviteiten` table.
 *
 * Implements the dedicated catalog table for the AVG (GDPR Art 30)
 * Verwerkingsregister. Each row describes a single processing
 * activity: legal basis, purpose, data subject categories, data
 * categories, retention rule, recipients, third-country transfers,
 * technical/organisational measures, controller and DPO contact.
 *
 * The audit trail's existing `processing_activity_id` column points
 * at this table's `uuid` as a soft FK; combined with hash-chained
 * tamper evidence on the audit rows themselves, this gives operators
 * the full GDPR Art 30 verantwoordingsdocument from a single query.
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
use Doctrine\DBAL\Types\Types;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds `oc_openregister_verwerkingsactiviteiten` per AVG Art 30 §1.
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
class Version1Date20260430160000 extends SimpleMigrationStep
{
    /**
     * Add the verwerkingsactiviteiten table when missing.
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

        if ($schema->hasTable(tableName: 'openregister_verwerkingsactiviteiten') === true) {
            return $schema;
        }

        $table = $schema->createTable(tableName: 'openregister_verwerkingsactiviteiten');

        $table->addColumn(
            name: 'id',
            typeName: Types::BIGINT,
            options: [
                'autoincrement' => true,
                'notnull'       => true,
                'unsigned'      => true,
            ]
        );

        $table->addColumn(
            name: 'uuid',
            typeName: Types::STRING,
            options: ['notnull' => true, 'length' => 36]
        );

        $table->addColumn(
            name: 'code',
            typeName: Types::STRING,
            options: ['notnull' => false, 'length' => 64]
        );

        $table->addColumn(
            name: 'naam',
            typeName: Types::STRING,
            options: ['notnull' => true, 'length' => 255]
        );

        $table->addColumn(
            name: 'beschrijving',
            typeName: Types::TEXT,
            options: ['notnull' => false]
        );

        $table->addColumn(
            name: 'doelbinding',
            typeName: Types::TEXT,
            options: ['notnull' => true]
        );

        $table->addColumn(
            name: 'rechtsgrond',
            typeName: Types::STRING,
            options: ['notnull' => true, 'length' => 64]
        );

        $table->addColumn(
            name: 'categorieen_betrokkenen',
            typeName: Types::JSON,
            options: ['notnull' => false]
        );

        $table->addColumn(
            name: 'categorieen_persoonsgegevens',
            typeName: Types::JSON,
            options: ['notnull' => false]
        );

        $table->addColumn(
            name: 'bewaartermijn',
            typeName: Types::STRING,
            options: ['notnull' => false, 'length' => 64]
        );

        $table->addColumn(
            name: 'ontvangers',
            typeName: Types::JSON,
            options: ['notnull' => false]
        );

        $table->addColumn(
            name: 'doorgifte_buiten_eu',
            typeName: Types::JSON,
            options: ['notnull' => false]
        );

        $table->addColumn(
            name: 'technische_maatregelen',
            typeName: Types::TEXT,
            options: ['notnull' => false]
        );

        $table->addColumn(
            name: 'organisatorische_maatregelen',
            typeName: Types::TEXT,
            options: ['notnull' => false]
        );

        $table->addColumn(
            name: 'verwerkingsverantwoordelijke',
            typeName: Types::JSON,
            options: ['notnull' => false]
        );

        $table->addColumn(
            name: 'contactgegevens_fg',
            typeName: Types::JSON,
            options: ['notnull' => false]
        );

        $table->addColumn(
            name: 'organisation_id',
            typeName: Types::STRING,
            options: ['notnull' => false, 'length' => 64]
        );

        $table->addColumn(
            name: 'status',
            typeName: Types::STRING,
            options: ['notnull' => true, 'length' => 32, 'default' => 'concept']
        );

        $table->addColumn(
            name: 'created',
            typeName: Types::DATETIME_MUTABLE,
            options: ['notnull' => true]
        );

        $table->addColumn(
            name: 'updated',
            typeName: Types::DATETIME_MUTABLE,
            options: ['notnull' => true]
        );

        $table->setPrimaryKey(columnNames: ['id']);
        $table->addUniqueIndex(columnNames: ['uuid'], indexName: 'idx_vrw_uuid');
        $table->addIndex(columnNames: ['code'], indexName: 'idx_vrw_code');
        $table->addIndex(columnNames: ['organisation_id'], indexName: 'idx_vrw_organisation');
        $table->addIndex(columnNames: ['status'], indexName: 'idx_vrw_status');

        return $schema;

    }//end changeSchema()
}//end class
