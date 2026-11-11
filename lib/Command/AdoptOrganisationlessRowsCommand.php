<?php

/**
 * AdoptOrganisationlessRowsCommand: reports or gives the stored rows without an organisation one.
 *
 * @category Command
 * @package  OCA\OpenRegister\Command
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

namespace OCA\OpenRegister\Command;

use OCA\OpenRegister\Service\Organisation\OrganisationlessRowAdoption;
use Symfony\Component\Console\Command\Command;

/**
 * `occ openregister:adopt-organisationless-rows [--dry-run]`: the repair step
 * AdoptOrganisationlessRows on demand, with a report of what it would write.
 *
 * @spec openspec/specs/saas-multi-tenant/spec.md
 */
class AdoptOrganisationlessRowsCommand extends MagicTableCommand {

	/**
	 * Tables none of register, schema or default organisation covers.
	 *
	 * @var integer
	 */
	private int $unassigned = 0;

	/**
	 * Constructor.
	 *
	 * @param MagicTableSelection         $selection The magic tables.
	 * @param OrganisationlessRowAdoption $adoption  Counts and writes the rows.
	 */
	public function __construct(
		MagicTableSelection $selection,
		private readonly OrganisationlessRowAdoption $adoption,
	) {
		parent::__construct(selection: $selection);
	}//end __construct()

	/**
	 * Name, description and options.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/saas-multi-tenant/spec.md
	 */
	protected function configure(): void {
		$this->setName(name: 'openregister:adopt-organisationless-rows')
			->setDescription(
				'Give stored objects without an organisation the organisation of their register, schema or instance.'
			);
		$this->addSelectionOptions();
	}//end configure()

	/**
	 * Say what the run does.
	 *
	 * @param MagicTableRun $run The run.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/saas-multi-tenant/spec.md
	 */
	protected function announce(MagicTableRun $run): void {
		$run->output->writeln(
			sprintf('<info>Giving stored objects without an organisation one%s</info>', $run->dryRunNote(words: ' (dry run)'))
		);
	}//end announce()

	/**
	 * Count, and unless dry, adopt one table's org-less rows.
	 *
	 * @param MagicTableTarget $target The table.
	 * @param MagicTableRun    $run    The run.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/saas-multi-tenant/spec.md
	 */
	protected function workOn(MagicTableTarget $target, MagicTableRun $run): void {
		$scanned = $this->adoption->count(tableName: $target->tableName);
		if ($scanned === 0) {
			return;
		}

		$organisation = $this->adoption->organisationFor(target: $target);
		$updated = 0;
		if ($organisation !== null && $run->isDryRun() === false) {
			$updated = $this->adoption->adopt(tableName: $target->tableName, organisation: $organisation);
		}

		if ($organisation === null) {
			$this->unassigned += 1;
		}

		$run->scanned += $scanned;
		$run->changed += $updated;
		$run->output->writeln(
			sprintf('  %s: without organisation=%d organisation=%s updated=%d', $target->label(), $scanned, ($organisation ?? 'none'), $updated)
		);
	}//end workOn()

	/**
	 * Summarise the run.
	 *
	 * @param MagicTableRun $run The run.
	 *
	 * @return int The exit code.
	 *
	 * @spec openspec/specs/saas-multi-tenant/spec.md
	 */
	protected function finish(MagicTableRun $run): int {
		$run->output->writeln(
			sprintf(
				'<info>Done. Tables=%d without organisation=%d updated=%d tables without any organisation to give=%d%s</info>',
				$run->tables,
				$run->scanned,
				$run->changed,
				$this->unassigned,
				$run->dryRunNote(words: ' (dry run, nothing written)')
			)
		);

		return Command::SUCCESS;
	}//end finish()
}//end class
