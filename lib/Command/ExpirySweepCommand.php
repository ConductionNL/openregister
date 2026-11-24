<?php

/**
 * ExpirySweepCommand: run the expired-object sweep, or show what it would do.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Command
 * @package  OCA\OpenRegister\Command
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/expired-object-sweep/specs/expired-object-sweep/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Command;

use DateTimeImmutable;
use OCA\OpenRegister\Service\Retention\ExpiredObjectSweeper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `occ openregister:expiry:sweep [--dry-run]`: per opted-in schema, how many
 * expired objects were (or would be) deleted, anonymised and kept for a hold.
 */
class ExpirySweepCommand extends Command {

	public const NAME = 'openregister:expiry:sweep';

	/**
	 * Constructor.
	 *
	 * @param ExpiredObjectSweeper $sweeper The sweep.
	 */
	public function __construct(
		private readonly ExpiredObjectSweeper $sweeper,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Name, description and options.
	 *
	 * @return void
	 */
	protected function configure(): void {
		$this->setName(name: self::NAME)
			->setDescription(description: 'Delete or anonymise the expired objects of schemas that opted in to expiry')
			->addOption(
				name: 'dry-run',
				shortcut: null,
				mode: InputOption::VALUE_NONE,
				description: 'Only count what the sweep would do; change nothing'
			);
	}//end configure()

	/**
	 * Run or preview the sweep and print the counts per schema.
	 *
	 * @param InputInterface  $input  The input.
	 * @param OutputInterface $output The output.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/expired-object-sweep/specs/expired-object-sweep/spec.md
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$dryRun  = ($input->getOption('dry-run') === true);
		$reports = $this->sweeper->sweep(now: new DateTimeImmutable(), dryRun: $dryRun);

		if ($reports === []) {
			$output->writeln('No schema opts in to expiry.');
			return 0;
		}

		foreach ($reports as $row) {
			$output->writeln(
				sprintf(
					'  %s / %s: %d deleted, %d anonymised, %d kept for a legal hold, %d failed, %d left for the next run',
					$row['register'],
					$row['schema'],
					$row['deleted'],
					$row['anonymised'],
					$row['held'],
					$row['failed'],
					$row['left']
				)
			);
		}

		if ($dryRun === true) {
			$output->writeln('Dry run: nothing was changed.');
			return 0;
		}

		$output->writeln('Sweep done.');

		return 0;
	}//end execute()
}//end class
