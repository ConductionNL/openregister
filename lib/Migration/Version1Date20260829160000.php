<?php

/**
 * Migration dropping the Dutch `oc_openregister_verwerkingsactiviteiten` columns in changeSchema.
 *
 * Version1Date20260818230000 renamed the table's 13 Dutch columns to English by adding the new
 * columns in `changeSchema()` and copying + dropping the old ones in `postSchemaChange()`.
 *
 * `postSchemaChange()` NEVER RUNS ON A FIRST-TIME INSTALL. `Installer::installApp()` calls
 * `$ms->migrate('latest', $previousVersion === '')`, and that second argument is `$schemaOnly` —
 * true whenever the app has no previously recorded version. `MigrationService::migrateSchemaOnly()`
 * invokes `changeSchema()` on every step and nothing else, then marks every step as EXECUTED. So on
 * a fresh install the English columns were added, the Dutch ones were never dropped, and the
 * migration was recorded as done and will never retry.
 *
 * `naam`, `doelbinding` and `rechtsgrond` are `notnull => true` (Version1Date20260430160000), while
 * every current write names only the English columns. Every insert therefore failed permanently:
 *
 *   ERROR: null value in column "naam" of relation "oc_openregister_verwerkingsactiviteiten"
 *          violates not-null constraint
 *
 * Measured on dossiq CI run 33255960731 — 238 failures in one run, taking its case-edit E2E with
 * them — and reproduced on a local install, whose table carries all 33 columns.
 *
 * The drop is done HERE, in `changeSchema()`, because that is the one hook both migration paths
 * run. Dropping a column through the schema object is honoured here (the diff is applied by
 * `migrateToSchema()`), unlike in `postSchemaChange()`, whose schema is never applied — the same
 * asymmetry Version1Date20260818230000 already documents for its own drop.
 *
 * Data-safe in every path. On an upgrade, 20260818230000's `postSchemaChange()` has already copied
 * the Dutch values across and dropped the columns, so this finds nothing to do. On a fresh install
 * there is nothing to copy: `$schemaOnly` is only ever true when the app had no previous version,
 * so no row can predate this.
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
 * @spec openspec/specs/avg-verwerkingsregister/spec.md#requirement-the-system-must-maintain-a-verwerkingsactiviteiten-register-as-an-openregister-schema
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Drops the 13 Dutch columns Version1Date20260818230000 could not drop on a fresh install.
 *
 * @spec openspec/specs/avg-verwerkingsregister/spec.md#requirement-the-system-must-maintain-a-verwerkingsactiviteiten-register-as-an-openregister-schema
 */
class Version1Date20260829160000 extends SimpleMigrationStep {

	/**
	 * The Dutch columns replaced by Version1Date20260818230000's English ones.
	 *
	 * Kept as its own list rather than referencing that migration's COLUMN_MAP: a migration is a
	 * record of what the schema did on a given date, and must not change meaning because a later
	 * edit changed a constant somewhere else.
	 *
	 * @var string[]
	 */
	private const OLD_DUTCH_COLUMNS = [
		'naam',
		'beschrijving',
		'doelbinding',
		'rechtsgrond',
		'categorieen_betrokkenen',
		'categorieen_persoonsgegevens',
		'bewaartermijn',
		'ontvangers',
		'doorgifte_buiten_eu',
		'technische_maatregelen',
		'organisatorische_maatregelen',
		'verwerkingsverantwoordelijke',
		'contactgegevens_fg',
	];

	/**
	 * Drop any Dutch column still present, so the table matches what the entity writes.
	 *
	 * @param IOutput  $output        Migration output sink.
	 * @param Closure  $schemaClosure Closure returning the ISchemaWrapper.
	 * @param array    $options       Migration options (unused).
	 *
	 * @return ISchemaWrapper|null The changed schema, or null when there was nothing to change.
	 *
	 * @spec openspec/specs/avg-verwerkingsregister/spec.md#requirement-the-system-must-maintain-a-verwerkingsactiviteiten-register-as-an-openregister-schema
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();

		if ($schema->hasTable('openregister_verwerkingsactiviteiten') === false) {
			return null;
		}

		$table = $schema->getTable('openregister_verwerkingsactiviteiten');
		$dropped = 0;

		foreach (self::OLD_DUTCH_COLUMNS as $columnName) {
			if ($table->hasColumn($columnName) === false) {
				continue;
			}

			$table->dropColumn($columnName);
			$dropped++;
		}

		if ($dropped === 0) {
			// The upgrade path already dropped them in 20260818230000's postSchemaChange.
			return null;
		}

		$output->info(
			sprintf(
				'Dropped %d Dutch column(s) from openregister_verwerkingsactiviteiten that '
				. 'postSchemaChange could not reach on a schema-only install.',
				$dropped
			)
		);

		return $schema;
	}//end changeSchema()
}//end class
