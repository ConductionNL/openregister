<?php

/**
 * AdoptOrganisationlessRows: reports the stored rows that carry no organisation.
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
 * @spec openspec/specs/saas-multi-tenant/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Repair;

use OCA\OpenRegister\Command\MagicTableSelection;
use OCA\OpenRegister\Command\MagicTableTarget;
use OCA\OpenRegister\Service\Organisation\OrganisationlessRowAdoption;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Repair step: report, per magic table, the rows without an organisation and the
 * organisation `occ openregister:adopt-organisationless-rows --apply` would give them.
 *
 * Writes nothing. Giving those rows an organisation changes who may read them,
 * so it is left to an administrator running the command.
 *
 * Never throws: one unreadable table must not abort an upgrade.
 *
 * @spec openspec/specs/saas-multi-tenant/spec.md
 */
class AdoptOrganisationlessRows implements IRepairStep {

	/**
	 * Constructor.
	 *
	 * @param MagicTableSelection         $selection The magic tables.
	 * @param OrganisationlessRowAdoption $adoption  Counts and writes the rows.
	 * @param LoggerInterface             $logger    Logger.
	 */
	public function __construct(
		private readonly MagicTableSelection $selection,
		private readonly OrganisationlessRowAdoption $adoption,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Human-readable step name surfaced in occ and the admin UI.
	 *
	 * @return string The step name.
	 *
	 * @spec openspec/specs/saas-multi-tenant/spec.md
	 */
	public function getName(): string {
		return 'Report stored objects without an organisation';
	}//end getName()

	/**
	 * Walk every magic table and report its org-less rows.
	 *
	 * @param IOutput $output Upgrade output.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/saas-multi-tenant/spec.md
	 */
	public function run(IOutput $output): void {
		try {
			$tables = $this->selection->tables(
				registers: $this->selection->registers(registerRef: null),
				schemas: $this->selection->schemas(schemaRef: null)
			);
		} catch (Throwable $e) {
			$output->warning('Could not list the object tables: ' . $e->getMessage());
			return;
		}

		$found = 0;
		foreach ($tables as $target) {
			try {
				$found += $this->reportTable(target: $target, output: $output);
			} catch (Throwable $e) {
				$this->logger->warning(
					message: '[AdoptOrganisationlessRows] Skipped a table',
					context: ['table' => $target->tableName, 'exception' => $e->getMessage()]
				);
				$output->warning($target->label() . ': skipped, ' . $e->getMessage());
			}
		}

		if ($found > 0) {
			$output->info(
				sprintf(
					'%d stored object(s) have no organisation; run occ openregister:adopt-organisationless-rows --apply to give them one.',
					$found
				)
			);
		}
	}//end run()

	/**
	 * Report one table's org-less rows and the organisation the command would give them.
	 *
	 * @param MagicTableTarget $target The table.
	 * @param IOutput          $output Upgrade output.
	 *
	 * @return int The rows without an organisation.
	 *
	 * @spec openspec/specs/saas-multi-tenant/spec.md
	 */
	private function reportTable(MagicTableTarget $target, IOutput $output): int {
		$count = $this->adoption->count(tableName: $target->tableName);
		if ($count === 0) {
			return 0;
		}

		$organisation = $this->adoption->organisationFor(target: $target);
		if ($organisation === null) {
			$output->warning(
				sprintf('%s: %d row(s) without an organisation, no register, schema or default organisation is set', $target->label(), $count)
			);
			return $count;
		}

		$output->info(sprintf('%s: %d row(s) without an organisation, the command would give them %s', $target->label(), $count, $organisation));

		return $count;
	}//end reportTable()
}//end class
