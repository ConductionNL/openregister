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

use OCA\OpenRegister\Service\SchemaApplicationMigratorInterface;
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
	 * @param SchemaApplicationMigratorInterface $migrator The migration service.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function __construct(private readonly SchemaApplicationMigratorInterface $migrator) {
		parent::__construct();

	}//end __construct()


	/**
	 * Configure the command.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/data-import-export/spec.md
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
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$from = (string)$input->getArgument('from');
		$to   = (string)$input->getArgument('to');

		if ($from === '' || $to === '' || $from === $to) {
			$output->writeln('<error>`from` and `to` must both be given and must differ.</error>');
			return 1;
		}

		if ($this->reportOwned(input: $input, output: $output) === 0) {
			return $this->reportNothingOwned(input: $input, output: $output);
		}

		if ((bool)$input->getOption('dry-run') === true) {
			return $this->dryRun(input: $input, output: $output);
		}

		return $this->migrate(input: $input, output: $output);

	}//end execute()


	/**
	 * Print how many schemas and registers the current application id owns.
	 *
	 * @param InputInterface  $input  The console input naming the current application id.
	 * @param OutputInterface $output The console output.
	 *
	 * @return int The schemas and registers owned, together.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function reportOwned(InputInterface $input, OutputInterface $output): int {
		$from      = (string)$input->getArgument('from');
		$schemas   = $this->migrator->countFor(table: 'openregister_schemas', application: $from);
		$registers = $this->migrator->countFor(table: 'openregister_registers', application: $from);

		$output->writeln(
			sprintf('Application "%s" currently owns %d schema(s) and %d register(s).', $from, $schemas, $registers)
		);

		return ($schemas + $registers);

	}//end reportOwned()


	/**
	 * Say how many schemas the new id owns when the old id owns nothing.
	 *
	 * Nothing under the old id is the expected state on a re-run, and
	 * also the state when the rename was never applied here at all.
	 * Those are different answers to the same number, so say which.
	 *
	 * @param InputInterface  $input  The console input naming the current and the new application id.
	 * @param OutputInterface $output The console output.
	 *
	 * @return int Always 0.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function reportNothingOwned(InputInterface $input, OutputInterface $output): int {
		$from    = (string)$input->getArgument('from');
		$to      = (string)$input->getArgument('to');
		$already = $this->migrator->countFor(table: 'openregister_schemas', application: $to);
		$output->writeln(
			sprintf('<info>Nothing owned by "%s". "%s" owns %d schema(s).</info>', $from, $to, $already)
		);
		return 0;

	}//end reportNothingOwned()


	/**
	 * Move the schemas and registers, or print the refusal and its colliding slugs.
	 *
	 * @param InputInterface  $input  The console input naming the current and the new application id.
	 * @param OutputInterface $output The console output.
	 *
	 * @return int 0 when moved, 1 when refused.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function migrate(InputInterface $input, OutputInterface $output): int {
		$rename = ApplicationIdRename::fromInput(input: $input);
		$result = $this->migrator->migrate(from: $rename->from, to: $rename->to);

		if ($result['ok'] === false) {
			$output->writeln(self::refusalLines(lead: 'Refusing:', collisions: $result['collisions']));
			return 1;
		}

		$output->writeln(
			'<info>Moved ' . $rename->describeMove(schemas: $result['schemas'], registers: $result['registers']) . '.</info>'
		);
		$output->writeln('Objects are unaffected: they reference their schema by id, which does not change here.');

		return 0;

	}//end migrate()


	/**
	 * Report what a real run would do, including whether it would refuse.
	 *
	 * The collision check runs here too, deliberately. A dry run that reports
	 * what it "would move" without checking whether the real run would refuse
	 * is worse than no dry run, because it reads as a green light.
	 *
	 * The counts it reports are read from the migrator here, so the dry run
	 * reports exactly what it checked.
	 *
	 * @param InputInterface  $input  The console input naming the current and the new application id.
	 * @param OutputInterface $output The console output.
	 *
	 * @return int 0 when the real run would proceed, 1 when it would refuse.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function dryRun(InputInterface $input, OutputInterface $output): int {
		$rename = ApplicationIdRename::fromInput(input: $input);

		$collisions = $this->migrator->collidingSlugs(from: $rename->from, to: $rename->to);
		if (empty($collisions) === false) {
			$output->writeln(self::refusalLines(lead: 'Would refuse:', collisions: $collisions));
			return 1;
		}

		$move = $rename->describeMove(
			schemas: $this->migrator->countFor(table: 'openregister_schemas', application: $rename->from),
			registers: $this->migrator->countFor(table: 'openregister_registers', application: $rename->from)
		);
		$output->writeln('<comment>Dry run: would move ' . $move . '.</comment>');

		return 0;

	}//end dryRun()


	/**
	 * The refusal and the slugs that caused it.
	 *
	 * @param string   $lead       `Refusing:`, or `Would refuse:` on a dry run.
	 * @param string[] $collisions The colliding slugs.
	 *
	 * @return array<int, string> The lines to print.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private static function refusalLines(string $lead, array $collisions): array {
		$lines = ['<error>' . $lead . ' these slugs already exist under the target application id.</error>'];
		foreach ($collisions as $slug) {
			$lines[] = '  - ' . $slug;
		}

		$lines[] = 'An import has already forked these schemas. Resolve the duplicates first (see openregister:schemas:dedup).';

		return $lines;

	}//end refusalLines()


}//end class
