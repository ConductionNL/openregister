<?php

/**
 * Every audit entry names the cause of the write.
 *
 * Adds `cause` and `cause_run` to `openregister_audit_trails`. `cause` is one
 * of a closed vocabulary — person, scheduled, import, migration, rule, cascade
 * — derived on the server and never read from a request. `cause_run` names the
 * run, when the cause is one, so the eight hundred entries of a single load are
 * reachable as a set rather than as eight hundred unrelated rows.
 *
 * 🔑 COLUMNS AND NOT `changed`. `changed` is JSON and cannot be indexed, and
 * the whole point of the cause is the FILTER: "show me everything that load
 * touched" over a table with millions of rows. A JSON predicate there is a
 * full scan.
 *
 * 🔑 THE INDEX IS THE PAIR, IN THAT ORDER. Every question asked of this is
 * either "which entries had this cause" or "which entries belonged to this
 * run", and the second is always asked within the first.
 *
 * Nullable with no back-fill: an entry written before this change has no
 * recorded cause, and `person` would be a guess. The read treats null as
 * unknown rather than as a person.
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
 * @spec openspec/changes/runs-recorded-and-causes-named/specs/enhanced-audit-trail/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add the cause and its run to the audit trail.
 *
 * @spec openspec/changes/runs-recorded-and-causes-named/specs/enhanced-audit-trail/spec.md
 */
class Version1Date20260918171500 extends SimpleMigrationStep {

	/**
	 * The audit table.
	 *
	 * @var string
	 */
	private const TABLE_AUDIT = 'openregister_audit_trails';

	/**
	 * Change the database schema.
	 *
	 * @param IOutput $output Output for the migration process.
	 * @param Closure $schemaClosure The schema closure.
	 * @param array<array-key, mixed> $options Migration options.
	 *
	 * @return ISchemaWrapper The schema, changed or not.
	 *
	 * @spec openspec/changes/runs-recorded-and-causes-named/specs/enhanced-audit-trail/spec.md
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ISchemaWrapper {
		/*
		 * @var ISchemaWrapper $schema
		 */

		$schema = $schemaClosure();

		// 🔑 THE SCHEMA COMES BACK EVEN WHEN THERE IS NOTHING TO DO. Returning
		// null drops the shared snapshot and makes the NEXT migration
		// re-introspect the whole database; `SchemaReuseHygieneTest` refuses
		// it for exactly that reason.
		if ($schema->hasTable(tableName: self::TABLE_AUDIT) === false) {
			return $schema;
		}

		$table = $schema->getTable(tableName: self::TABLE_AUDIT);

		if ($table->hasColumn('cause') === false) {
			// 32, because the vocabulary is six words and the longest is nine
			// characters. A wider column would invite somebody to put a
			// sentence in it, which is the open string this replaces.
			$table->addColumn('cause', Types::STRING, ['notnull' => false, 'length' => 32]);
		}

		if ($table->hasColumn('cause_run') === false) {
			$table->addColumn('cause_run', Types::STRING, ['notnull' => false, 'length' => 64]);
		}

		if ($table->hasIndex('or_audit_cause') === false) {
			$table->addIndex(['cause', 'cause_run'], 'or_audit_cause');
		}

		return $schema;
	}//end changeSchema()
}//end class
