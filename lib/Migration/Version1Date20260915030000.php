<?php

/**
 * Relation rows that no $ref property can hold.
 *
 * A typed link between two objects normally lives in a `$ref` property, and
 * that stays true. Four kinds of link have nowhere to live there, and each one
 * currently ends up in a description field where the graph, the reverse view
 * and the export cannot see it:
 *
 *  - the provenance of a SPLIT: a new object created from one entry of another
 *    carries a typed relation to the source object and to the entry it came
 *    from. Zammad splits a ticket and keeps no provenance column; copying the
 *    act without copying the omission costs one row and answers "why does this
 *    zaak exist" for the rest of its life.
 *  - what a child INHERITED from its parent at creation, and when. Recorded
 *    once, at creation, so that a later change to the parent is a decision
 *    somebody makes again rather than a reclassification nobody authorised.
 *  - an EXTERNAL address, with a title and a type. A URL pasted into a
 *    description is invisible to all three surfaces; as a relation row it is
 *    in all three, and costs no new concept.
 *  - a reference written in PROSE, resolved by the timeline's pattern
 *    resolution, which records the row on both sides and withdraws it with the
 *    text.
 *
 * Idempotent: the table is created only when absent.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Create the object relation row table.
 *
 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
 */
class Version1Date20260915030000 extends SimpleMigrationStep {
	/**
	 * Change the database schema.
	 *
	 * @param IOutput $output Output for the migration process.
	 * @param Closure $schemaClosure The schema closure.
	 * @param array<array-key, mixed> $options Migration options.
	 *
	 * @return ISchemaWrapper|null The changed schema.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/*
		 * @var ISchemaWrapper $schema
		 */

		$schema = $schemaClosure();

		if ($schema->hasTable('openregister_object_relations') === true) {
			return $schema;
		}

		$table = $schema->createTable('openregister_object_relations');
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
		$table->addColumn('uuid', Types::STRING, ['notnull' => false, 'length' => 36]);
		// The object the row hangs off. Every read starts here.
		$table->addColumn('source_uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
		$table->addColumn('source_register', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
		$table->addColumn('source_schema', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
		// Exactly one of target_uuid and target_url is set: `object` rows name
		// an object, `external` rows name an address outside the product.
		$table->addColumn('target_uuid', Types::STRING, ['notnull' => false, 'length' => 36]);
		$table->addColumn('target_register', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
		$table->addColumn('target_schema', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
		$table->addColumn('target_url', Types::TEXT, ['notnull' => false]);
		$table->addColumn('target_title', Types::STRING, ['notnull' => false, 'length' => 512]);
		$table->addColumn('kind', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'object']);
		// The vocabulary key, when the row names one. Labels are never stored:
		// they are resolved from the schema at read, so renaming "blocks" to
		// "blokkeert" does not need a backfill over every row that used it.
		$table->addColumn('relation_type', Types::STRING, ['notnull' => false, 'length' => 128]);
		$table->addColumn('label', Types::STRING, ['notnull' => false, 'length' => 255]);
		$table->addColumn('inverse_label', Types::STRING, ['notnull' => false, 'length' => 255]);
		$table->addColumn('symmetric', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
		// How the row came to exist: split, derive, prose, external, manual.
		$table->addColumn('origin', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'manual']);
		// The entry of the source object this row came out of, for a split.
		$table->addColumn('source_entry', Types::STRING, ['notnull' => false, 'length' => 128]);
		// What the child took from the parent at creation, and what the values
		// were. Kept as written so a later parent change is visibly a second
		// decision rather than a silent reclassification.
		$table->addColumn('inherited', Types::TEXT, ['notnull' => false]);
		// The text anchor a prose reference came from, so withdrawing the text
		// withdraws exactly the rows it wrote and no others.
		$table->addColumn('anchor', Types::STRING, ['notnull' => false, 'length' => 255]);
		$table->addColumn('created_by', Types::STRING, ['notnull' => false, 'length' => 64]);
		$table->addColumn('created', Types::DATETIME, ['notnull' => false]);
		$table->addColumn('updated', Types::DATETIME, ['notnull' => false]);

		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['uuid'], 'idx_or_objrel_uuid');
		// The two traversal directions the graph read walks, one index each.
		$table->addIndex(['source_uuid'], 'idx_or_objrel_source');
		$table->addIndex(['target_uuid'], 'idx_or_objrel_target');
		$table->addIndex(['source_uuid', 'kind'], 'idx_or_objrel_kind');
		$table->addIndex(['origin', 'anchor'], 'idx_or_objrel_anchor');

		$output->info('Created openregister_object_relations table');

		return $schema;
	}//end changeSchema()
}//end class
