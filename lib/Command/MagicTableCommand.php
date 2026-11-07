<?php

/**
 * The shape of a command that walks the magic tables.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Command
 * @package  OCA\OpenRegister\Command
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Command;

use OCA\OpenRegister\Db\Schema;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Resolves the selection, works table by table and reports the totals.
 *
 * @spec openspec/specs/auth-system/spec.md
 */
abstract class MagicTableCommand extends Command {

	/**
	 * Constructor.
	 *
	 * @param MagicTableSelection $selection Finds the tables to work on.
	 */
	public function __construct(private readonly MagicTableSelection $selection) {
		parent::__construct();
	}//end __construct()

	/**
	 * Add the options every magic table command shares.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/auth-system/spec.md
	 */
	protected function addSelectionOptions(): void {
		$this->addOption(name: 'dry-run', mode: InputOption::VALUE_NONE, description: 'Report counts without writing to the database')
			->addOption(name: 'register', mode: InputOption::VALUE_REQUIRED, description: 'Limit to a single register (slug, uuid or id)')
			->addOption(name: 'schema', mode: InputOption::VALUE_REQUIRED, description: 'Limit to a single schema (slug, uuid or id)');
	}//end addSelectionOptions()

	/**
	 * Walk the selected tables.
	 *
	 * @param InputInterface $input Console input.
	 * @param OutputInterface $output Console output stream.
	 *
	 * @return int Symfony command exit code.
	 *
	 * @spec openspec/specs/auth-system/spec.md
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$run = new MagicTableRun(input: $input, output: $output);
		if ($this->isReady(run: $run) === false) {
			return Command::FAILURE;
		}

		if ($this->select(run: $run) === false) {
			return Command::FAILURE;
		}

		if (count($run->registers) === 0 || count($run->schemas) === 0) {
			$output->writeln('<comment>No registers or schemas resolved — nothing to do.</comment>');
			return Command::SUCCESS;
		}

		return $this->walk(run: $run);
	}//end execute()

	/**
	 * Resolve the registers and schemas the options select.
	 *
	 * @param MagicTableRun $run The run
	 *
	 * @return bool False when a reference could not be resolved, which is reported
	 *
	 * @spec openspec/specs/auth-system/spec.md
	 */
	private function select(MagicTableRun $run): bool {
		try {
			$run->registers = $this->selection->registers(registerRef: $run->input->getOption('register'));
			$run->schemas = $this->selection->schemas(schemaRef: $run->input->getOption('schema'));
			return true;
		} catch (\Throwable $e) {
			$run->output->writeln('<error>' . $e->getMessage() . '</error>');
			return false;
		}
	}//end select()

	/**
	 * Work on every table of a schema that applies, then report.
	 *
	 * @param MagicTableRun $run The run
	 *
	 * @return int Symfony command exit code.
	 *
	 * @spec openspec/specs/auth-system/spec.md
	 */
	private function walk(MagicTableRun $run): int {
		$this->announce(run: $run);

		$schemas = array_filter($run->schemas, fn (Schema $schema): bool => $this->applies(schema: $schema, run: $run));
		foreach ($this->selection->tables(registers: $run->registers, schemas: $schemas) as $target) {
			$run->tables += 1;
			$this->workOn(target: $target, run: $run);
		}

		return $this->finish(run: $run);
	}//end walk()

	/**
	 * Whether the input lets the run start; a refusal explains itself.
	 *
	 * @param MagicTableRun $run The run
	 *
	 * @return bool Whether to go on
	 *
	 * @spec openspec/specs/auth-system/spec.md
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) A command without required input has nothing to check.
	 */
	protected function isReady(MagicTableRun $run): bool {
		return true;
	}//end isReady()

	/**
	 * Whether this command has work in the tables of this schema.
	 *
	 * @param Schema $schema The schema
	 * @param MagicTableRun $run The run
	 *
	 * @return bool Whether to work on them
	 *
	 * @spec openspec/specs/auth-system/spec.md
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) Most commands work on every table.
	 */
	protected function applies(Schema $schema, MagicTableRun $run): bool {
		return true;
	}//end applies()

	/**
	 * Say what this run is about to do.
	 *
	 * @param MagicTableRun $run The run
	 *
	 * @return void
	 *
	 * @spec openspec/specs/auth-system/spec.md
	 */
	abstract protected function announce(MagicTableRun $run): void;

	/**
	 * Work on one table, add to the counts and report the table.
	 *
	 * @param MagicTableTarget $target The table
	 * @param MagicTableRun $run The run
	 *
	 * @return void
	 *
	 * @spec openspec/specs/auth-system/spec.md
	 */
	abstract protected function workOn(MagicTableTarget $target, MagicTableRun $run): void;

	/**
	 * Report the totals.
	 *
	 * @param MagicTableRun $run The run
	 *
	 * @return int Symfony command exit code.
	 *
	 * @spec openspec/specs/auth-system/spec.md
	 */
	abstract protected function finish(MagicTableRun $run): int;
}//end class
