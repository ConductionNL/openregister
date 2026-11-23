<?php

/**
 * NarrowMagicJsonColumns: on MariaDB, move the JSON columns of existing magic
 * tables off LONGTEXT, before this upgrade's migrations read the schema.
 *
 * @category Repair
 * @package  OCA\OpenRegister\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/magic-table-columns/spec.md#requirement-a-json-column-does-not-slow-every-schema-read-of-the-instance-req-mtc-003
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Repair;

use OCA\OpenRegister\Db\MagicMapper\MagicJsonColumnNarrower;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Repair step: narrow the LONGTEXT JSON columns of existing magic tables.
 *
 * PRE-migration, deliberately. Nextcloud reads the whole database schema three
 * times for every migration step, and on MariaDB that read rescans every table
 * once per `longtext` column: about four minutes a read on an instance with a
 * thousand magic tables (#10612). Run after the migrations, this step would
 * speed up the next upgrade and leave this one as slow as it was.
 *
 * It needs nothing a migration creates: only the catalogue and the magic
 * tables. A table is rebuilt to narrow it, so this step takes the small ones
 * and stops after a fixed time; `occ openregister:tables:narrow-json` takes
 * the rest. It never fails the upgrade: an untouched table is only slower.
 */
class NarrowMagicJsonColumns implements IRepairStep {

	/**
	 * The most data a table may hold for this step to rebuild it during an upgrade.
	 */
	public const MAX_TABLE_BYTES = 268435456;

	/**
	 * How long this step keeps starting on new tables, in seconds.
	 */
	public const BUDGET_SECONDS = 300.0;

	/**
	 * Constructor.
	 *
	 * @param MagicJsonColumnNarrower $narrower Finds and narrows the columns.
	 * @param LoggerInterface         $logger   Logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly MagicJsonColumnNarrower $narrower,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Human-readable step name surfaced in occ and the admin UI.
	 *
	 * @return string The step name.
	 *
	 * @spec openspec/specs/magic-table-columns/spec.md#requirement-a-json-column-does-not-slow-every-schema-read-of-the-instance-req-mtc-003
	 */
	public function getName(): string {
		return 'Store the JSON columns of existing object tables so they no longer slow down database upgrades';
	}//end getName()

	/**
	 * Narrow the JSON columns of the magic tables small enough to rebuild now.
	 *
	 * @param IOutput $output Migration output handle.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/magic-table-columns/spec.md#requirement-a-json-column-does-not-slow-every-schema-read-of-the-instance-req-mtc-003
	 */
	public function run(IOutput $output): void {
		try {
			if ($this->narrower->applies() === false) {
				return;
			}

			$result = $this->narrower->narrowPending(maxTableBytes: self::MAX_TABLE_BYTES, budgetSeconds: self::BUDGET_SECONDS);
		} catch (\Throwable $e) {
			$this->logger->warning('[NarrowMagicJsonColumns] skipped: ' . $e->getMessage());
			$output->warning('Could not check the JSON columns of the object tables: ' . $e->getMessage());
			return;
		}

		$this->report(output: $output, result: $result);
	}//end run()

	/**
	 * Report what was narrowed, what failed and what is left; nothing when there was nothing to do.
	 *
	 * @param IOutput                                                                           $output The output.
	 * @param array{narrowed: list<string>, failed: array<string, string>, left: list<string>} $result The outcome.
	 *
	 * @return void
	 */
	private function report(IOutput $output, array $result): void {
		foreach ($result['failed'] as $table => $reason) {
			$output->warning(sprintf('%s: its JSON columns were left as they are: %s', $table, $reason));
		}

		if ($result['narrowed'] !== [] || $result['failed'] !== []) {
			$output->info(
				sprintf(
					'Narrowed the JSON columns of %d object table(s); %d could not be.',
					count($result['narrowed']),
					count($result['failed'])
				)
			);
		}

		if ($result['left'] !== []) {
			$output->warning(
				sprintf(
					'%d large object table(s) still hold JSON in LONGTEXT columns, which slows every database upgrade.'
					. ' Run "occ openregister:tables:narrow-json" to rebuild them.',
					count($result['left'])
				)
			);
		}
	}//end report()
}//end class
