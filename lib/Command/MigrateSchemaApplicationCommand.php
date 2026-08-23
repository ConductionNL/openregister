<?php

/**
 * OpenRegister migrate-application command
 *
 * Console entry point for SchemaApplicationMigrator: re-points registers and
 * schemas at a new owning application id when a fleet app's `<id>` changes
 * (procest -> dossiq, nldesign -> thematiq, and the rest of the 2026 rename
 * programme).
 *
 * The rule itself lives in the service so that this command and an app's own
 * repair step run the SAME code — see SchemaApplicationMigrator for why the
 * migration is needed at all.
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

use OCA\OpenRegister\Service\SchemaApplicationMigrator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Move registers and schemas from one owning application id to another.
 */
class MigrateSchemaApplicationCommand extends Command {
	/**
	 * Constructor.
	 *
	 * @param SchemaApplicationMigrator $migrator The migration service.
	 */
	public function __construct(private readonly SchemaApplicationMigrator $migrator) {
		parent::__construct();

	}//end __construct()


	/**
	 * Configure the command.
	 *
	 * @return void
	 */
	protected function configure(): void {
		$this->setName(name: 'openregister:migrate-application')
			->setDescription('Re-point registers and schemas from one owning app id to another (app rename)')
			->addArgument('from', InputArgument::REQUIRED, 'The current application id, e.g. procest')
			->addArgument('to', InputArgument::REQUIRED, 'The new application id, e.g. dossiq')
			->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would change without writing');

	}//end configure()


	/**
	 * Execute the command.
	 *
	 * @param InputInterface  $input  The console input.
	 * @param OutputInterface $output The console output.
	 *
	 * @return int 0 on success, 1 on refusal.
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$from = (string)$input->getArgument('from');
		$to   = (string)$input->getArgument('to');

		if ($from === '' || $to === '' || $from === $to) {
			$output->writeln('<error>`from` and `to` must both be given and must differ.</error>');
			return 1;
		}

		$schemas   = $this->migrator->countFor(table: 'openregister_schemas', application: $from);
		$registers = $this->migrator->countFor(table: 'openregister_registers', application: $from);

		$output->writeln(
			sprintf('Application "%s" currently owns %d schema(s) and %d register(s).', $from, $schemas, $registers)
		);

		if (($schemas + $registers) === 0) {
			// Nothing under the old id is the expected state on a re-run, and
			// also the state when the rename was never applied here at all.
			// Those are different answers to the same number, so say which.
			$already = $this->migrator->countFor(table: 'openregister_schemas', application: $to);
			$output->writeln(
				sprintf('<info>Nothing owned by "%s". "%s" owns %d schema(s).</info>', $from, $to, $already)
			);
			return 0;
		}

		if ((bool)$input->getOption('dry-run') === true) {
			return $this->dryRun(output: $output, from: $from, to: $to, schemas: $schemas, registers: $registers);
		}

		$result = $this->migrator->migrate(from: $from, to: $to);

		if ($result['ok'] === false) {
			$this->reportRefusal(output: $output, collisions: $result['collisions'], wouldRefuse: false);
			return 1;
		}

		$output->writeln(
			sprintf(
				'<info>Moved %d schema(s) and %d register(s) from "%s" to "%s".</info>',
				$result['schemas'],
				$result['registers'],
				$from,
				$to
			)
		);
		$output->writeln('Objects are unaffected: they reference their schema by id, which does not change here.');

		return 0;

	}//end execute()


	/**
	 * Report what a real run would do, including whether it would refuse.
	 *
	 * The collision check runs here too, deliberately. A dry run that reports
	 * what it "would move" without checking whether the real run would refuse
	 * is worse than no dry run, because it reads as a green light.
	 *
	 * @param OutputInterface $output    The console output.
	 * @param string          $from      The current application id.
	 * @param string          $to        The new application id.
	 * @param int             $schemas   Schemas owned by `from`.
	 * @param int             $registers Registers owned by `from`.
	 *
	 * @return int 0 when the real run would proceed, 1 when it would refuse.
	 */
	private function dryRun(OutputInterface $output, string $from, string $to, int $schemas, int $registers): int {
		$collisions = $this->migrator->collidingSlugs(from: $from, to: $to);
		if (empty($collisions) === false) {
			$this->reportRefusal(output: $output, collisions: $collisions, wouldRefuse: true);
			return 1;
		}

		$output->writeln(
			sprintf(
				'<comment>Dry run: would move %d schema(s) and %d register(s) from "%s" to "%s".</comment>',
				$schemas,
				$registers,
				$from,
				$to
			)
		);

		return 0;

	}//end dryRun()


	/**
	 * Print the refusal and the slugs that caused it.
	 *
	 * @param OutputInterface $output      The console output.
	 * @param string[]        $collisions  The colliding slugs.
	 * @param bool            $wouldRefuse True when reporting a dry run.
	 *
	 * @return void
	 */
	private function reportRefusal(OutputInterface $output, array $collisions, bool $wouldRefuse): void {
		$lead = 'Refusing:';
		if ($wouldRefuse === true) {
			$lead = 'Would refuse:';
		}

		$output->writeln('<error>' . $lead . ' these slugs already exist under the target application id.</error>');
		foreach ($collisions as $slug) {
			$output->writeln('  - ' . $slug);
		}

		$output->writeln(
			'An import has already forked these schemas. Resolve the duplicates first (see openregister:schemas:dedup).'
		);

	}//end reportRefusal()


}//end class
