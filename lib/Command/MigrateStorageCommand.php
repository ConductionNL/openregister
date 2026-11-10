<?php

/**
 * OpenRegister Migrate Storage Command
 *
 * OCC command for migrating objects between blob storage and magic tables.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Command
 * @package   OCA\OpenRegister\Command
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Command;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\MigrationServiceInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * OCC command for migrating objects between blob storage and magic tables.
 *
 * Usage:
 *   php occ openregister:migrate-storage to-magic 1 5
 *   php occ openregister:migrate-storage to-blob 1 5 --dry-run
 *   php occ openregister:migrate-storage to-magic 1 5 --status
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
class MigrateStorageCommand extends Command {
	/**
	 * The required arguments, in order: name => description.
	 */
	private const ARGUMENTS = [
		'direction' => 'Migration direction: to-magic or to-blob',
		'register' => 'Register ID or slug',
		'schema' => 'Schema ID or slug',
	];

	/**
	 * The command's help text.
	 */
	private const HELP = '<info>Migrate objects between blob storage and magic tables</info>

<comment>Directions:</comment>
  <info>to-magic</info>   Move objects from blob storage to a magic table
  <info>to-blob</info>    Move objects from a magic table to blob storage

<comment>Examples:</comment>
  <info>php occ openregister:migrate-storage to-magic 1 5</info>
    Migrate register 1, schema 5 objects from blob to magic table

  <info>php occ openregister:migrate-storage to-blob 1 5 --dry-run</info>
    Preview migration from magic table to blob storage

  <info>php occ openregister:migrate-storage to-magic 1 5 --status</info>
    Show current storage status for register 1, schema 5

  <info>php occ openregister:migrate-storage to-magic 1 5 -b 50</info>
    Migrate in batches of 50
';

	/**
	 * Constructor for MigrateStorageCommand.
	 *
	 * @param MigrationServiceInterface $migrationService Migration service instance.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		private readonly MigrationServiceInterface $migrationService,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Configure the command.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	protected function configure(): void {
		$this->setName(name: 'openregister:migrate-storage')
			->setDescription('Migrate objects between blob storage and magic tables')
			->setHelp(self::HELP);
		$this->configureArguments();
		$this->addOption(
			name: 'batch-size',
			shortcut: 'b',
			mode: InputOption::VALUE_REQUIRED,
			description: 'Batch size for migration',
			default: '100'
		);
		$this->configureFlags();
	}//end configure()

	/**
	 * Declare the three required arguments, in order.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function configureArguments(): void {
		foreach (self::ARGUMENTS as $name => $description) {
			$this->addArgument(name: $name, mode: InputArgument::REQUIRED, description: $description);
		}
	}//end configureArguments()

	/**
	 * Declare the dry-run and status switches.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function configureFlags(): void {
		$this->addOption(
			name: 'dry-run',
			mode: InputOption::VALUE_NONE,
			description: 'Preview migration without making changes'
		);
		$this->addOption(
			name: 'status',
			shortcut: 's',
			mode: InputOption::VALUE_NONE,
			description: 'Show storage status only'
		);
	}//end configureFlags()

	/**
	 * Execute the migration command.
	 *
	 * @param InputInterface $input Input interface.
	 * @param OutputInterface $output Output interface.
	 *
	 * @return int Command exit code.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$run = new MigrateStorageRun(input: $input, output: $output);
		$resolved = $this->resolveTarget(run: $run);
		if ($resolved === null || $this->writeStatus(output: $output, resolved: $resolved) === false) {
			return self::FAILURE;
		}

		if ($input->getOption('status') === true) {
			return self::SUCCESS;
		}

		$this->writePlan(run: $run);

		return $this->migrateAndReport(run: $run, resolved: $resolved);
	}//end execute()

	/**
	 * Validate the direction, then resolve the register and schema.
	 *
	 * @param MigrateStorageRun $run The console input and output.
	 *
	 * @return array{register: Register, schema: Schema}|null The resolved pair, or null after reporting the error.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function resolveTarget(MigrateStorageRun $run): ?array {
		if ($this->directionIsValid(run: $run) === false) {
			return null;
		}

		try {
			return $this->migrationService->resolveRegisterAndSchema(
				registerId: $run->input->getArgument('register'),
				schemaId: $run->input->getArgument('schema')
			);
		} catch (\Exception $e) {
			$run->output->writeln('<error>Failed to resolve register/schema: ' . $e->getMessage() . '</error>');
			return null;
		}
	}//end resolveTarget()

	/**
	 * Whether the direction is one the command knows; reports it when not.
	 *
	 * @param MigrateStorageRun $run The console input and output.
	 *
	 * @return bool True for `to-magic` or `to-blob`.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function directionIsValid(MigrateStorageRun $run): bool {
		$direction = $run->input->getArgument('direction');
		if (in_array($direction, ['to-magic', 'to-blob'], true) === false) {
			$run->output->writeln('<error>Invalid direction: ' . $direction . '. Use "to-magic" or "to-blob".</error>');
			return false;
		}

		return true;
	}//end directionIsValid()

	/**
	 * Write the header and the storage status of the register and schema.
	 *
	 * @param OutputInterface                            $output   Output interface.
	 * @param array{register: Register, schema: Schema} $resolved The resolved register and schema.
	 *
	 * @return bool False when the storage status could not be read.
	 */
	private function writeStatus(OutputInterface $output, array $resolved): bool {
		$this->writeHeader(output: $output, resolved: $resolved);

		try {
			$status = $this->migrationService->getStorageStatus(register: $resolved['register'], schema: $resolved['schema']);
		} catch (\Exception $e) {
			$output->writeln('<error>Failed to get storage status: ' . $e->getMessage() . '</error>');
			return false;
		}

		$this->writeStorageStatus(output: $output, status: $status);
		return true;
	}//end writeStatus()

	/**
	 * Write the command header naming the register and schema.
	 *
	 * @param OutputInterface                            $output   Output interface.
	 * @param array{register: Register, schema: Schema} $resolved The resolved register and schema.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function writeHeader(OutputInterface $output, array $resolved): void {
		$register = $resolved['register'];
		$schema = $resolved['schema'];

		$output->writeln('');
		$output->writeln('<info>OpenRegister Storage Migration</info>');
		$output->writeln('================================');
		$output->writeln('  Register: <comment>' . $register->getName() . '</comment> (ID: ' . $register->getId() . ')');
		$output->writeln('  Schema:   <comment>' . $schema->getName() . '</comment> (ID: ' . $schema->getId() . ')');
		$output->writeln('');
	}//end writeHeader()

	/**
	 * Write the object counts in blob storage and in the magic table.
	 *
	 * @param OutputInterface      $output Output interface.
	 * @param array<string, mixed> $status The storage status.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function writeStorageStatus(OutputInterface $output, array $status): void {
		$output->writeln('<info>Storage Status:</info>');
		$output->writeln('  Blob storage:  <comment>' . $status['blobStorage']['count'] . ' objects</comment>');
		$magicInfo = 'does not exist';
		if ($status['magicTable']['exists'] === true) {
			$magicInfo = $status['magicTable']['count'] . ' objects';
		}

		$output->writeln('  Magic table:   <comment>' . $magicInfo . '</comment>');
		$output->writeln('');
	}//end writeStatus()

	/**
	 * Write the direction, dry-run flag and batch size of the migration about to run.
	 *
	 * @param MigrateStorageRun $run The console input and output.
	 *
	 * @return void
	 */
	private function writePlan(MigrateStorageRun $run): void {
		$directionLabel = 'magic table -> blob';
		if ($run->input->getArgument('direction') === 'to-magic') {
			$directionLabel = 'blob -> magic table';
		}

		$run->output->writeln('<info>Migrating: ' . $directionLabel . '</info>');

		if ($run->input->getOption('dry-run') === true) {
			$run->output->writeln('<comment>DRY RUN - no changes will be made</comment>');
		}

		$run->output->writeln('  Batch size: <comment>' . (int)$run->input->getOption('batch-size') . '</comment>');
		$run->output->writeln('');
	}//end writePlan()

	/**
	 * Run the migration and write its report, or report its failure.
	 *
	 * @param MigrateStorageRun                          $run      The console input and output.
	 * @param array{register: Register, schema: Schema} $resolved The resolved register and schema.
	 *
	 * @return int The exit code.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function migrateAndReport(MigrateStorageRun $run, array $resolved): int {
		try {
			$report = $this->migrate(input: $run->input, resolved: $resolved);
		} catch (\Exception $e) {
			$run->output->writeln('<error>Migration failed: ' . $e->getMessage() . '</error>');
			return self::FAILURE;
		}

		return $this->writeReport(output: $run->output, report: $report);
	}//end migrateAndReport()

	/**
	 * Run the migration.
	 *
	 * The blob migration always runs; `to-magic` then runs the magic-table
	 * migration, and its report is the one returned.
	 *
	 * @param InputInterface                            $input    Input interface.
	 * @param array{register: Register, schema: Schema} $resolved The resolved register and schema.
	 *
	 * @return array{total: int, migrated: int, skipped: int, failed: int, errors: array} The migration report.
	 *
	 * @throws \Exception When the migration fails.
	 */
	private function migrate(InputInterface $input, array $resolved): array {
		$batchSize = (int)$input->getOption('batch-size');
		$dryRun = $input->getOption('dry-run');

		$report = $this->migrationService->migrateToBlobStorage(
			register: $resolved['register'],
			schema: $resolved['schema'],
			batchSize: $batchSize,
			dryRun: $dryRun
		);
		if ($input->getArgument('direction') === 'to-magic') {
			$report = $this->migrationService->migrateToMagicTable(
				register: $resolved['register'],
				schema: $resolved['schema'],
				batchSize: $batchSize,
				dryRun: $dryRun
			);
		}

		return $report;
	}//end migrate()

	/**
	 * Write the migration report and pick the exit code.
	 *
	 * @param OutputInterface                                                             $output Output interface.
	 * @param array{total: int, migrated: int, skipped: int, failed: int, errors: array} $report The migration report.
	 *
	 * @return int FAILURE when any object failed, SUCCESS otherwise.
	 */
	private function writeReport(OutputInterface $output, array $report): int {
		$output->writeln('<info>Migration Report:</info>');
		$output->writeln('  Total source objects: <comment>' . $report['total'] . '</comment>');
		$output->writeln('  Migrated:            <comment>' . $report['migrated'] . '</comment>');
		$output->writeln('  Skipped (duplicate): <comment>' . $report['skipped'] . '</comment>');
		$output->writeln('  Failed:              <comment>' . $report['failed'] . '</comment>');

		$this->writeErrors(output: $output, errors: $report['errors']);
		$output->writeln('');

		if ($report['failed'] > 0) {
			$output->writeln('<error>Migration completed with errors.</error>');
			return self::FAILURE;
		}

		$output->writeln('<info>Migration completed successfully.</info>');
		return self::SUCCESS;
	}//end writeReport()

	/**
	 * Write the per-object errors, when there are any.
	 *
	 * @param OutputInterface                                    $output Output interface.
	 * @param array<int, array{uuid: string, message: string}> $errors The errors.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function writeErrors(OutputInterface $output, array $errors): void {
		if (count($errors) === 0) {
			return;
		}

		$output->writeln('');
		$output->writeln('<error>Errors:</error>');
		foreach ($errors as $error) {
			$output->writeln('  - UUID ' . $error['uuid'] . ': ' . $error['message']);
		}
	}//end writeErrors()
}//end class
