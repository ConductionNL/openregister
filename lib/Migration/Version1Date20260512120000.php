<?php

/**
 * Migration adding `bases` (JSON, nullable) and `skip_anonymization`
 * (boolean, default false) columns to `oc_openregister_entity_relations`.
 *
 * These two columns hold the operator's *decision metadata* per
 * detected entity occurrence:
 *
 *  - `bases`: optional array of UUIDs referencing `base` schema
 *    objects in a consumer app's register (DocuDesk's `dossier`
 *    register is the first consumer). Records the legal grondslag
 *    under which the entity is being redacted (Woo Art. 5).
 *    OpenRegister stores the array verbatim; vocabulary validation
 *    is the consumer app's responsibility.
 *
 *  - `skip_anonymization`: boolean flag set by the operator to
 *    exclude this specific occurrence from the anonymise pass.
 *    `EntityRelationMapper::markAsAnonymized` skips rows where
 *    this flag is true; the anonymise flow does not redact them
 *    and they retain `anonymized = false`.
 *
 * Both columns are written via a single audited path:
 * `EntityRelationMapper::updateDecisionMetadata` (and its HTTP
 * surface `PATCH /api/entity-relations/{id}`), per the
 * `entity-relation-grondslagen` change.
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
 *
 * @spec openspec/changes/entity-relation-grondslagen/tasks.md "1.1 Add a new migration class"
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds the `bases` and `skip_anonymization` decision-metadata columns
 * to the entity_relations table. Both columns are nullable-or-defaulted
 * so existing rows pick up sensible defaults without backfill.
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
class Version1Date20260512120000 extends SimpleMigrationStep
{
    /**
     * Add the two decision-metadata columns when missing.
     *
     * @param IOutput                 $output        Migration output sink.
     * @param Closure                 $schemaClosure Closure returning the ISchemaWrapper.
     * @param array<array-key, mixed> $options       Migration options (unused).
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

        if ($table->hasColumn(name: 'bases') === false) {
            $table->addColumn(
                name: 'bases',
                typeName: Types::JSON,
                options: ['notnull' => false]
            );
        }

        if ($table->hasColumn(name: 'skip_anonymization') === false) {
            $table->addColumn(
                name: 'skip_anonymization',
                typeName: Types::BOOLEAN,
                options: [
                    'notnull' => true,
                    'default' => false,
                ]
            );
        }

        return $schema;

    }//end changeSchema()
}//end class
