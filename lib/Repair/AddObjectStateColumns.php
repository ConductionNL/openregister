<?php

/**
 * AddObjectStateColumns — retrofit the `_archived` and `_frozen` metadata
 * columns onto every magic table that predates them.
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
 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Repair;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Repair step: give every existing magic table its archive and freeze columns.
 *
 * ⚠️ THIS STEP IS NOT OPTIONAL POLISH. The default object list now carries
 * `_archived IS NULL`, and so do the count and the facet queries built from
 * the same choke point. A table that never gained the column answers that
 * WHERE clause with an SQL error, which reaches the caller as an HTTP 500 on
 * the plain list of a register — not as a missing feature.
 *
 * `MagicMapper::getMetadataColumns()` is where the two columns are declared,
 * and `addMissingColumns()` is what puts a declared-but-absent column on an
 * existing table. What neither of them is, is a thing that runs on its own:
 * `searchObjectsInRegisterSchemaTable()` checks only that the table EXISTS and
 * creates it when it does not. An already-materialised table is never
 * re-synced by a read. So the sync has to be asked for, and an upgrade is when
 * to ask.
 *
 * Idempotent: `ensureTableForRegisterSchema()` adds only what is missing, so a
 * second run over a reconciled instance does nothing. Never throws — a schema
 * whose table cannot be reconciled is logged and the sweep continues, because
 * one unreachable table must not abort an app upgrade.
 */
class AddObjectStateColumns implements IRepairStep {

	/**
	 * Constructor.
	 *
	 * @param RegisterMapper $registerMapper Register and schema lookups.
	 * @param MagicMapper $magicMapper Magic-table sync.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly RegisterMapper $registerMapper,
		private readonly MagicMapper $magicMapper,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Human-readable step name surfaced in occ and the admin UI.
	 *
	 * @return string The step name.
	 *
	 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md
	 */
	public function getName(): string {
		return 'Add the archive and freeze columns to existing object tables';
	}//end getName()

	/**
	 * Reconcile every register and schema pair.
	 *
	 * @param IOutput $output Migration output handle.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md
	 */
	public function run(IOutput $output): void {
		$reconciled = 0;
		$failed = 0;

		try {
			$registers = $this->registerMapper->findAll(_rbac: false, _multitenancy: false);
		} catch (\Throwable $e) {
			// No registers to walk is the normal state of a fresh install, and
			// a mapper that cannot answer during an upgrade is not something
			// this step can fix. Either way it must not stop the upgrade.
			$this->logger->info(
				'[AddObjectStateColumns] Could not list registers, skipping: ' . $e->getMessage()
			);
			return;
		}

		foreach ($registers as $register) {
			if ($register instanceof Register === false) {
				continue;
			}

			$reconciledForRegister = $this->reconcileRegister(register: $register, failed: $failed);
			$reconciled += $reconciledForRegister;
		}

		$output->info(
			sprintf(
				'Reconciled %d object table(s) for the archive and freeze columns, %d could not be read.',
				$reconciled,
				$failed
			)
		);
	}//end run()

	/**
	 * Reconcile every schema table of one register.
	 *
	 * @param Register $register The register to walk.
	 * @param int $failed Running count of tables that could not be reconciled, by reference.
	 *
	 * @return int The number of tables reconciled.
	 */
	private function reconcileRegister(Register $register, int &$failed): int {
		try {
			$schemas = $this->registerMapper->getSchemasByRegisterId(
				registerId: (int)$register->getId(),
				_rbac: false,
				_multitenancy: false
			);
		} catch (\Throwable $e) {
			$failed++;
			$this->logger->warning(
				'[AddObjectStateColumns] Could not list schemas for register '
				. (string)$register->getId() . ': ' . $e->getMessage()
			);
			return 0;
		}

		$reconciled = 0;
		foreach ($schemas as $schema) {
			if ($schema instanceof Schema === false) {
				continue;
			}

			try {
				$this->magicMapper->ensureTableForRegisterSchema(
					register: $register,
					schema: $schema
				);
				$reconciled++;
			} catch (\Throwable $e) {
				$failed++;
				$this->logger->warning(
					'[AddObjectStateColumns] Could not reconcile register '
					. (string)$register->getId() . ' schema ' . (string)$schema->getId()
					. ': ' . $e->getMessage()
				);
			}//end try
		}//end foreach

		return $reconciled;
	}//end reconcileRegister()
}//end class
