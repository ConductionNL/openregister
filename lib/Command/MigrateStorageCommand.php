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
use OCA\OpenRegister\Service\MigrationService;
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
 */
class MigrateStorageCommand extends Command {
	/**
	 * Constructor for MigrateStorageCommand.
	 *
	 * @param MigrationService $migrationService Migration service instance.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		private readonly MigrationService $migrationService,
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
			->addArgument(
				name: 'direction',
				mode: InputArgument::REQUIRED,
				description: 'Migration direction: to-magic or to-blob'
			)
			->addArgument(
				name: 'register',
				mode: InputArgument::REQUIRED,
				description: 'Register ID or slug'
			)
			->addArgument(
				name: 'schema',
				mode: InputArgument::REQUIRED,
				description: 'Schema ID or slug'
			)
			->addOption(
				name: 'batch-size',
				shortcut: 'b',
				mode: InputOption::VALUE_REQUIRED,
				description: 'Batch size for migration',
				default: '100'
			)
			->addOption(
				name: 'dry-run',
				mode: InputOption::VALUE_NONE,
				description: 'Preview migration without making changes'
			)
			->addOption(
				name: 'status',
				shortcut: 's',
				mode: InputOption::VALUE_NONE,
				description: 'Show storage status only'
			)
			->setHelp(
				'<info>Migrate objects between blob storage and magic tables</info>

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
'
			);
	}//end configure()

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
		$direction = $input->getArgument('direction');

		// Validate direction.
		if (in_array($direction, ['to-magic', 'to-blob'], true) === false) {
			$output->writeln('<error>Invalid direction: ' . $direction . '. Use "to-magic" or "to-blob".</error>');
			return self::FAILURE;
		}

		try {
			$resolved = $this->migrationService->resolveRegisterAndSchema(
				registerId: $input->getArgument('register'),
				schemaId: $input->getArgument('schema')
			);
		} catch (\Exception $e) {
			$output->writeln('<error>Failed to resolve register/schema: ' . $e->getMessage() . '</error>');
			return self::FAILURE;
		}

		if ($this->writeStatus(output: $output, resolved: $resolved) === false) {
			return self::FAILURE;
		}

		if ($input->getOption('status') === true) {
			return self::SUCCESS;
		}

		$this->writePlan(input: $input, output: $output);

		try {
			$report = $this->migrate(input: $input, resolved: $resolved);
		} catch (\Exception $e) {
			$output->writeln('<error>Migration failed: ' . $e->getMessage() . '</error>');
			return self::FAILURE;
		}

		return $this->writeReport(output: $output, report: $report);
	}//end execute()

	/**
	 * Write the header and the storage status of the register and schema.
	 *
	 * @param OutputInterface                            $output   Output interface.
	 * @param array{register: Register, schema: Schema} $resolved The resolved register and schema.
	 *
	 * @return bool False when the storage status could not be read.
	 */
	private function writeStatus(OutputInterface $output, array $resolved): bool {
		$register = $resolved['register'];
		$schema = $resolved['schema'];

		$output->writeln('');
		$output->writeln('<info>OpenRegister Storage Migration</info>');
		$output->writeln('================================');
		$output->writeln('  Register: <comment>' . $register->getName() . '</comment> (ID: ' . $register->getId() . ')');
		$output->writeln('  Schema:   <comment>' . $schema->getName() . '</comment> (ID: ' . $schema->getId() . ')');
		$output->writeln('');

		try {
			$status = $this->migrationService->getStorageStatus(register: $register, schema: $schema);
		} catch (\Exception $e) {
			$output->writeln('<error>Failed to get storage status: ' . $e->getMessage() . '</error>');
			return false;
		}

		$output->writeln('<info>Storage Status:</info>');
		$output->writeln('  Blob storage:  <comment>' . $status['blobStorage']['count'] . ' objects</comment>');
		$magicInfo = 'does not exist';
		if ($status['magicTable']['exists'] === true) {
			$magicInfo = $status['magicTable']['count'] . ' objects';
		}

		$output->writeln('  Magic table:   <comment>' . $magicInfo . '</comment>');
		$output->writeln('');
		return true;
	}//end writeStatus()

	/**
	 * Write the direction, dry-run flag and batch size of the migration about to run.
	 *
	 * @param InputInterface  $input  Input interface.
	 * @param OutputInterface $output Output interface.
	 *
	 * @return void
	 */
	private function writePlan(InputInterface $input, OutputInterface $output): void {
		$directionLabel = 'magic table -> blob';
		if ($input->getArgument('direction') === 'to-magic') {
			$directionLabel = 'blob -> magic table';
		}

		$output->writeln('<info>Migrating: ' . $directionLabel . '</info>');

		if ($input->getOption('dry-run') === true) {
			$output->writeln('<comment>DRY RUN - no changes will be made</comment>');
		}

		$output->writeln('  Batch size: <comment>' . (int)$input->getOption('batch-size') . '</comment>');
		$output->writeln('');
	}//end writePlan()

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

		if (count($report['errors']) > 0) {
			$output->writeln('');
			$output->writeln('<error>Errors:</error>');
			foreach ($report['errors'] as $error) {
				$output->writeln('  - UUID ' . $error['uuid'] . ': ' . $error['message']);
			}
		}

		$output->writeln('');

		if ($report['failed'] > 0) {
			$output->writeln('<error>Migration completed with errors.</error>');
			return self::FAILURE;
		}

		$output->writeln('<info>Migration completed successfully.</info>');
		return self::SUCCESS;
	}//end writeReport()
}//end class
