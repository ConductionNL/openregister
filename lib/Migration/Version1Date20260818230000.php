<?php

/**
 * Migration renaming the `oc_openregister_verwerkingsactiviteiten` table's Dutch columns to English.
 *
 * Part of the `verwerkingsregister-i18n` change: adds the 13 English-named replacement columns,
 * copies existing row data across (remapping the `rechtsgrond` legal-basis values to their GDPR
 * Art. 6(1)(a)-(f) English equivalents), then drops the old Dutch-named columns. Data-preserving —
 * no row is deleted, and any legacy `rechtsgrond` value outside the known 6 is left in place on a
 * best-effort column (`legal_basis`) rather than silently discarded, with a warning logged.
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
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Renames `oc_openregister_verwerkingsactiviteiten`'s 13 Dutch columns to English.
 */
class Version1Date20260818230000 extends SimpleMigrationStep {

	/**
	 * Old (Dutch) column name => new (English) column name, in the shape `IDBConnection` needs.
	 *
	 * @var array<string, string>
	 */
	private const COLUMN_MAP = [
		'naam' => 'name',
		'beschrijving' => 'description',
		'doelbinding' => 'purpose',
		'rechtsgrond' => 'legal_basis',
		'categorieen_betrokkenen' => 'data_subject_categories',
		'categorieen_persoonsgegevens' => 'personal_data_categories',
		'bewaartermijn' => 'retention_period',
		'ontvangers' => 'recipients',
		'doorgifte_buiten_eu' => 'international_transfers',
		'technische_maatregelen' => 'technical_measures',
		'organisatorische_maatregelen' => 'organisational_measures',
		'verwerkingsverantwoordelijke' => 'controller',
		'contactgegevens_fg' => 'dpo_contact_details',
	];

	/**
	 * `rechtsgrond` (Dutch) value => `legal_basis` (English, GDPR Art. 6(1)(a)-(f)) value.
	 *
	 * @var array<string, string>
	 */
	private const LEGAL_BASIS_VALUE_MAP = [
		'toestemming' => 'consent',
		'overeenkomst' => 'contract',
		'wettelijke_verplichting' => 'legal_obligation',
		'vitaal_belang' => 'vital_interests',
		'publieke_taak' => 'public_task',
		'gerechtvaardigd_belang' => 'legitimate_interest',
	];

	/**
	 * Constructor.
	 *
	 * @param IOutput $output unused, kept for parity with sibling migrations.
	 * @param IDBConnection $connection Database connection used for the data copy in postSchemaChange.
	 */
	public function __construct(
		private readonly IDBConnection $connection,
	) {
	}//end __construct()

	/**
	 * Add the 13 new English-named columns (nullable — populated in postSchemaChange).
	 *
	 * @param IOutput $output Migration output sink.
	 * @param Closure $schemaClosure Closure returning the ISchemaWrapper.
	 * @param array $options Migration options (unused).
	 *
	 * @return ISchemaWrapper|null
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();

		if ($schema->hasTable(tableName: 'openregister_verwerkingsactiviteiten') === false) {
			return null;
		}

		$table = $schema->getTable(tableName: 'openregister_verwerkingsactiviteiten');

		// Type/length must mirror each old column's definition (Version1Date20260430160000).
		$newColumnTypes = [
			'name' => [Types::STRING, ['notnull' => false, 'length' => 255]],
			'description' => [Types::TEXT, ['notnull' => false]],
			'purpose' => [Types::TEXT, ['notnull' => false]],
			'legal_basis' => [Types::STRING, ['notnull' => false, 'length' => 64]],
			'data_subject_categories' => [Types::JSON, ['notnull' => false]],
			'personal_data_categories' => [Types::JSON, ['notnull' => false]],
			'retention_period' => [Types::STRING, ['notnull' => false, 'length' => 64]],
			'recipients' => [Types::JSON, ['notnull' => false]],
			'international_transfers' => [Types::JSON, ['notnull' => false]],
			'technical_measures' => [Types::TEXT, ['notnull' => false]],
			'organisational_measures' => [Types::TEXT, ['notnull' => false]],
			'controller' => [Types::JSON, ['notnull' => false]],
			'dpo_contact_details' => [Types::JSON, ['notnull' => false]],
		];

		foreach ($newColumnTypes as $columnName => [$typeName, $columnOptions]) {
			if ($table->hasColumn(columnName: $columnName) === false) {
				$table->addColumn(name: $columnName, typeName: $typeName, options: $columnOptions);
			}
		}

		return $schema;
	}//end changeSchema()

	/**
	 * Copy data from the old Dutch columns into the new English ones (remapping `legal_basis`
	 * values), then drop the old columns. Idempotent: only acts on columns that still exist.
	 *
	 * @param IOutput $output Migration output sink.
	 * @param Closure $schemaClosure Closure returning the ISchemaWrapper.
	 * @param array $options Migration options (unused).
	 *
	 * @return void
	 */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$schema = $schemaClosure();

		if ($schema->hasTable(tableName: 'openregister_verwerkingsactiviteiten') === false) {
			return;
		}

		$table = $schema->getTable(tableName: 'openregister_verwerkingsactiviteiten');

		foreach (self::COLUMN_MAP as $oldColumn => $newColumn) {
			if ($table->hasColumn(columnName: $oldColumn) === false
				|| $table->hasColumn(columnName: $newColumn) === false
			) {
				continue;
			}

			if ($oldColumn === 'rechtsgrond') {
				// Value remap (Dutch legal-basis term -> GDPR Art. 6(1)(a)-(f) English term),
				// defensive for all 6 possible values. A legacy value outside the known 6 is
				// copied across UNCHANGED rather than dropped, and logged for operator follow-up.
				foreach (self::LEGAL_BASIS_VALUE_MAP as $oldValue => $newValue) {
					$this->connection->executeStatement(
						'UPDATE `*PREFIX*openregister_verwerkingsactiviteiten` SET `legal_basis` = ? WHERE `rechtsgrond` = ?',
						[$newValue, $oldValue]
					);
				}

				$unmappedCount = $this->connection->executeQuery(
					'SELECT COUNT(*) AS c FROM `*PREFIX*openregister_verwerkingsactiviteiten` WHERE `legal_basis` IS NULL AND `rechtsgrond` IS NOT NULL'
				)->fetchOne();

				if ((int)$unmappedCount > 0) {
					$output->warning(
						sprintf(
							'%d row(s) in openregister_verwerkingsactiviteiten had a `rechtsgrond` value outside the known 6 (toestemming/overeenkomst/wettelijke_verplichting/vitaal_belang/publieke_taak/gerechtvaardigd_belang). Copying it across unchanged into `legal_basis` rather than dropping it.',
							(int)$unmappedCount
						)
					);
					$this->connection->executeStatement(
						'UPDATE `*PREFIX*openregister_verwerkingsactiviteiten` SET `legal_basis` = `rechtsgrond` WHERE `legal_basis` IS NULL AND `rechtsgrond` IS NOT NULL'
					);
				}

				continue;
			}//end if

			$this->connection->executeStatement(
				sprintf(
					'UPDATE `*PREFIX*openregister_verwerkingsactiviteiten` SET `%s` = `%s`',
					$newColumn,
					$oldColumn
				)
			);
		}//end foreach

		$schema = $schemaClosure();
		$table = $schema->getTable(tableName: 'openregister_verwerkingsactiviteiten');
		foreach (array_keys(self::COLUMN_MAP) as $oldColumn) {
			if ($table->hasColumn(columnName: $oldColumn) === true) {
				$table->dropColumn(name: $oldColumn);
			}
		}
	}//end postSchemaChange()
}//end class
