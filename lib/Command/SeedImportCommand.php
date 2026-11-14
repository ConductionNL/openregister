<?php

/**
 * SeedImportCommand: occ openregister:seed:import.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Command
 * @package  OCA\OpenRegister\Command
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/seed-from-a-mounted-directory/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Command;

use OCA\OpenRegister\Service\Configuration\SeedDirectoryImporter;
use OCA\OpenRegister\Service\Configuration\SeedReport;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Imports the seed directory now and prints the report, for operators who
 * script their provisioning. The exit code is non-zero for a partial or
 * failed run, so a script cannot mistake one for success.
 *
 * @spec openspec/changes/seed-from-a-mounted-directory/specs/data-import-export/spec.md
 */
class SeedImportCommand extends Command {

	/**
	 * The exit code of a partial run.
	 */
	public const EXIT_PARTIAL = 2;

	/**
	 * Build the command.
	 *
	 * @param SeedDirectoryImporter $importer The seed import.
	 */
	public function __construct(
		private readonly SeedDirectoryImporter $importer,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Name and describe the command.
	 *
	 * @return void
	 */
	protected function configure(): void {
		$this->setName(name: 'openregister:seed:import')
			->setDescription(
				description: 'Import every *.json file in the directory config.php names as '
					. SeedDirectoryImporter::CONFIG_KEY . ', and print the report'
			);
	}//end configure()

	/**
	 * Import and print the report.
	 *
	 * @param InputInterface  $input  The input.
	 * @param OutputInterface $output The output.
	 *
	 * @return int 0 on success, 2 on a partial run, 1 on a failed run or no directory.
	 *
	 * @spec openspec/changes/seed-from-a-mounted-directory/specs/data-import-export/spec.md#requirement-every-seed-run-reports-what-it-did-and-a-partial-run-says-it-is-partial-req-smd-002
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$report = $this->importer->import();
		if ($report === null) {
			$output->writeln('No seed directory is configured: set ' . SeedDirectoryImporter::CONFIG_KEY . ' in config.php.');
			return self::FAILURE;
		}

		$output->writeln($report->summary());
		foreach ($report->toArray()['files'] as $file) {
			$counts = $file['counts'];
			$output->writeln(
				sprintf(
					'  %s: %s (registers %d/%d/%d, schemas %d/%d/%d, objects %d/%d/%d imported/skipped/failed)',
					$file['file'],
					$file['status'],
					$counts['registers']['imported'],
					$counts['registers']['skipped'],
					$counts['registers']['failed'],
					$counts['schemas']['imported'],
					$counts['schemas']['skipped'],
					$counts['schemas']['failed'],
					$counts['objects']['imported'],
					$counts['objects']['skipped'],
					$counts['objects']['failed']
				)
			);
		}

		foreach ($report->failures() as $failure) {
			$output->writeln('  failed: ' . $failure['reason']);
		}

		return match ($report->status()) {
			SeedReport::STATUS_SUCCESS => self::SUCCESS,
			SeedReport::STATUS_PARTIAL => self::EXIT_PARTIAL,
			default => self::FAILURE,
		};
	}//end execute()
}//end class
