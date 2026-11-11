<?php

/**
 * AdoptOrganisationlessRows: gives the stored rows that carry no organisation one.
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
 * Repair step: give every magic-table row without an organisation the register's,
 * else the schema's, else the default organisation.
 *
 * Once it has run, a row without an organisation is one written since, and
 * the rule that only an administrator reads such rows holds for the data as
 * well as for the code. `occ openregister:adopt-organisationless-rows --dry-run`
 * reports what this step would write.
 *
 * Idempotent, and never throws: one unreadable table must not abort an upgrade.
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
		return 'Give stored objects without an organisation the organisation of their register, schema or instance';
	}//end getName()

	/**
	 * Walk every magic table and give its org-less rows an organisation.
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

		$adopted = 0;
		foreach ($tables as $target) {
			try {
				$adopted += $this->adoptTable(target: $target, output: $output);
			} catch (Throwable $e) {
				$this->logger->warning(
					message: '[AdoptOrganisationlessRows] Skipped a table',
					context: ['table' => $target->tableName, 'exception' => $e->getMessage()]
				);
				$output->warning($target->label() . ': skipped, ' . $e->getMessage());
			}
		}

		$output->info(sprintf('Gave %d stored object(s) an organisation.', $adopted));
	}//end run()

	/**
	 * Give one table's org-less rows its organisation.
	 *
	 * @param MagicTableTarget $target The table.
	 * @param IOutput          $output Upgrade output.
	 *
	 * @return int The rows written.
	 *
	 * @spec openspec/specs/saas-multi-tenant/spec.md
	 */
	private function adoptTable(MagicTableTarget $target, IOutput $output): int {
		if ($this->adoption->count(tableName: $target->tableName) === 0) {
			return 0;
		}

		$organisation = $this->adoption->organisationFor(target: $target);
		if ($organisation === null) {
			$output->warning($target->label() . ': rows without an organisation stay, no register, schema or default organisation is set');
			return 0;
		}

		$written = $this->adoption->adopt(tableName: $target->tableName, organisation: $organisation);
		$output->info(sprintf('%s: gave %d row(s) organisation %s', $target->label(), $written, $organisation));

		return $written;
	}//end adoptTable()
}//end class
