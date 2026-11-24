<?php

/**
 * UniqueUuidIndexCommand: reports duplicate uuids per magic table, and adds the unique uuid index where there are none.
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
 * @spec openspec/specs/magic-table-columns/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Command;

use OCA\OpenRegister\Repair\AddUniqueUuidIndex;
use OCA\OpenRegister\Service\Object\UniqueUuidIndex;
use Symfony\Component\Console\Command\Command;

/**
 * `occ openregister:unique-uuid-index [--dry-run]`: the repair step AddUniqueUuidIndex on demand.
 *
 * With --dry-run it only reports which tables lack the index and which
 * uuids they hold twice. It never deletes or changes a row.
 *
 * @spec openspec/specs/magic-table-columns/spec.md
 */
class UniqueUuidIndexCommand extends MagicTableCommand {

	/**
	 * Constructor.
	 *
	 * @param MagicTableSelection $selection The magic tables.
	 * @param UniqueUuidIndex     $index     Reads and adds the index.
	 */
	public function __construct(
		MagicTableSelection $selection,
		private readonly UniqueUuidIndex $index,
	) {
		parent::__construct(selection: $selection);
	}//end __construct()

	/**
	 * Name, description and options.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/magic-table-columns/spec.md
	 */
	protected function configure(): void {
		$this->setName(name: 'openregister:unique-uuid-index')
			->setDescription('Report duplicate uuids per object table, and add the unique uuid index to tables without duplicates.');
		$this->addSelectionOptions();
	}//end configure()

	/**
	 * Say what the run does.
	 *
	 * @param MagicTableRun $run The run.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/magic-table-columns/spec.md
	 */
	protected function announce(MagicTableRun $run): void {
		$run->output->writeln(sprintf('<info>Checking the unique uuid index of object tables%s</info>', $run->dryRunNote(words: ' (dry run)')));
	}//end announce()

	/**
	 * Report one table's duplicates, or add its index unless dry.
	 *
	 * @param MagicTableTarget $target The table.
	 * @param MagicTableRun    $run    The run.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/magic-table-columns/spec.md
	 */
	protected function workOn(MagicTableTarget $target, MagicTableRun $run): void {
		if ($this->index->isUnique(tableName: $target->tableName) === true) {
			return;
		}

		$run->scanned += 1;
		$duplicates = $this->index->duplicates(tableName: $target->tableName);
		if ($duplicates !== []) {
			$run->failed += 1;
			$run->output->writeln('  <comment>' . AddUniqueUuidIndex::duplicateReport(target: $target, duplicates: $duplicates) . '</comment>');
			return;
		}

		if ($run->isDryRun() === true) {
			$run->output->writeln('  ' . $target->label() . ': no duplicates, the index can be added');
			return;
		}

		$this->index->add(tableName: $target->tableName);
		$run->changed += 1;
		$run->output->writeln('  ' . $target->label() . ': added the unique uuid index');
	}//end workOn()

	/**
	 * Summarise the run.
	 *
	 * @param MagicTableRun $run The run.
	 *
	 * @return int The exit code.
	 *
	 * @spec openspec/specs/magic-table-columns/spec.md
	 */
	protected function finish(MagicTableRun $run): int {
		$run->output->writeln(
			sprintf(
				'<info>Done. Tables=%d without the index=%d with duplicates=%d index added=%d%s</info>',
				$run->tables,
				$run->scanned,
				$run->failed,
				$run->changed,
				$run->dryRunNote(words: ' (dry run, nothing written)')
			)
		);

		return Command::SUCCESS;
	}//end finish()
}//end class
