<?php

/**
 * What a record has ever been, in a shape a list query can join.
 *
 * Creates `openregister_state_history`: one row per interval a lifecycle
 * property of one object spent holding one value. The transitions are already
 * recorded in the audit trail; what was missing is a shape a filter can join.
 * ADR-009 forbids answering a list query by scanning the trail, so this is a
 * derived projection with its own indexes, rebuildable and prunable with the
 * trail it derives from.
 *
 * 🔑 `left_at IS NULL` MEANS "STILL IN THIS STATE". "Was ever in bezwaar"
 * matches an interval whether or not it has closed, so the open interval is
 * not a special case to remember; it is the same row with an open end. The
 * column is `left_at`, not `left`, because `LEFT` is a reserved word in every
 * database this app runs on and a column called `left` has to be quoted in
 * every statement forever.
 *
 * 🔑 THE PROPERTY IS THE ONE THE SCHEMA DECLARES. A row is only ever written
 * for the property named by `x-openregister-lifecycle.field`, never for a key
 * that happened to be in the payload. A projection that recorded whatever the
 * write attached would let a filter reach a value the schema never declared as
 * a state, and a reader could learn it from the result count alone.
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
 * @spec openspec/changes/search-over-history-and-an-administered-dictionary/specs/zoeken-filteren/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Create the lifecycle-state history projection.
 *
 * @spec openspec/changes/search-over-history-and-an-administered-dictionary/specs/zoeken-filteren/spec.md
 */
class Version1Date20260918210000 extends SimpleMigrationStep {

	/**
	 * The table this migration creates.
	 *
	 * @var string
	 */
	private const TABLE_STATE_HISTORY = 'openregister_state_history';

	/**
	 * Change the database schema.
	 *
	 * @param IOutput $output Output for the migration process.
	 * @param Closure $schemaClosure The schema closure.
	 * @param array<array-key, mixed> $options Migration options.
	 *
	 * @return ISchemaWrapper The changed schema.
	 *
	 * @spec openspec/changes/search-over-history-and-an-administered-dictionary/specs/zoeken-filteren/spec.md
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ISchemaWrapper {
		/*
		 * @var ISchemaWrapper $schema
		 */

		$schema = $schemaClosure();

		if ($schema->hasTable(tableName: self::TABLE_STATE_HISTORY) === false) {
			$table = $schema->createTable(self::TABLE_STATE_HISTORY);
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('object_uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
			$table->addColumn('register', Types::STRING, ['notnull' => false, 'length' => 255]);
			$table->addColumn('schema', Types::STRING, ['notnull' => false, 'length' => 255]);
			// The DECLARED lifecycle property, not a key off the payload.
			$table->addColumn('property', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('value', Types::STRING, ['notnull' => false, 'length' => 255]);
			$table->addColumn('entered_at', Types::DATETIME, ['notnull' => false]);
			// NULL means the object is in this state right now.
			$table->addColumn('left_at', Types::DATETIME, ['notnull' => false]);

			$table->setPrimaryKey(['id']);
			// Closing an object's open interval, and reading one object's line.
			$table->addIndex(['object_uuid', 'property', 'left_at'], 'idx_or_sthist_obj');
			// "Was ever in X": the predicate's own lookup, over every object.
			$table->addIndex(['property', 'value'], 'idx_or_sthist_value');
			// "Changed between": a range scan over the moments a state began.
			$table->addIndex(['property', 'entered_at'], 'idx_or_sthist_entered');

			$output->info('Created openregister_state_history table');
		}//end if

		return $schema;
	}//end changeSchema()
}//end class
