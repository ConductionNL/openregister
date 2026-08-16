<?php

/**
 * OpenRegister relink-register-schemas command
 *
 * Reports — and on explicit request repairs — registers whose stored `schemas`
 * list has been lost, reconstructing it from the physical per-pair object tables.
 *
 * @category Command
 * @package  OCA\OpenRegister\Command
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Command;

use OCA\OpenRegister\Service\RegisterSchemaLinkageRepairService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Inspect and repair lost register→schema linkage.
 *
 * DRY RUN BY DEFAULT. `--write` is required to change anything.
 *
 * A register with an empty `schemas` list cannot resolve any schema slug once
 * register-scoped resolution refuses to fall back globally, so this command is the
 * remedy the refusal message points at. Repairing 17 registers as a side effect of
 * running it would be the same class of surprise the refusal exists to remove, so
 * the operator sees the full change first and then opts in.
 *
 * @spec openspec/specs/register-scoped-slug-resolution/spec.md
 */
class RelinkRegisterSchemasCommand extends Command {

	/**
	 * Wire the repair service.
	 *
	 * @param RegisterSchemaLinkageRepairService $repair The linkage repair service.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly RegisterSchemaLinkageRepairService $repair,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Define command name, description, and options.
	 *
	 * @return void
	 */
	protected function configure(): void {
		$this->setName(name: 'openregister:registers:relink-schemas')
			->setDescription('Rebuild a register\'s schemas list from its physical object tables (dry run by default)')
			->addOption(
				'write',
				null,
				InputOption::VALUE_NONE,
				'Apply the changes. Without this flag nothing is modified.'
			)
			->addOption(
				'register',
				null,
				InputOption::VALUE_REQUIRED,
				'Limit to a single register id.'
			);
	}//end configure()

	/**
	 * Run the inspection, and the repair when --write is given.
	 *
	 * @param InputInterface  $input  The console input.
	 * @param OutputInterface $output The console output.
	 *
	 * @return int The exit code.
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$write      = (bool)$input->getOption('write');
		$registerId = $input->getOption('register');
		if ($registerId !== null) {
			$registerId = (int)$registerId;
		}

		$report = $this->repair->inspect(registerId: $registerId);

		if ($report === []) {
			$output->writeln('<info>No register has recoverable schema linkage. Nothing to do.</info>');
			return Command::SUCCESS;
		}

		$output->writeln(
			sprintf('<comment>%d register(s) have recoverable schema linkage:</comment>', count($report))
		);
		$output->writeln('');

		foreach ($report as $entry) {
			$this->renderRegister(output: $output, entry: $entry);
		}

		if ($write === false) {
			$output->writeln('');
			$output->writeln('<comment>DRY RUN — nothing was changed. Re-run with --write to apply.</comment>');
			return Command::SUCCESS;
		}

		$changed = 0;
		foreach ($report as $entry) {
			$merged = $this->repair->apply(
				registerId: $entry['registerId'],
				schemaIds: array_keys($entry['recoverable'])
			);
			$output->writeln(
				sprintf(
					'  <info>repaired</info> register %d -> schemas [%s]',
					$entry['registerId'],
					implode(', ', $merged)
				)
			);
			$changed++;
		}

		$output->writeln('');
		$output->writeln(sprintf('<info>%d register(s) changed.</info>', $changed));

		return Command::SUCCESS;
	}//end execute()

	/**
	 * Render one register's findings.
	 *
	 * Row counts are printed per schema id because they separate strong evidence
	 * (a table holding rows) from weak (an empty table). Both are recovered; the
	 * operator can see which is which.
	 *
	 * @param OutputInterface $output The console output.
	 * @param array           $entry  One inspect() report entry.
	 *
	 * @return void
	 */
	private function renderRegister(OutputInterface $output, array $entry): void {
		$output->writeln(
			sprintf(
				'  register %d (%s) — currently [%s]',
				$entry['registerId'],
				(string)($entry['registerSlug'] ?? '?'),
				implode(', ', $entry['currentIds'])
			)
		);

		foreach ($entry['recoverable'] as $schemaId => $rowCount) {
			$evidence = 'table exists but is empty';
			if ($rowCount > 0) {
				$evidence = sprintf('%d row(s)', $rowCount);
			}

			if ($rowCount < 0) {
				$evidence = 'row count unavailable';
			}

			$output->writeln(sprintf('      + schema %d  (%s)', $schemaId, $evidence));
		}
	}//end renderRegister()
}//end class
