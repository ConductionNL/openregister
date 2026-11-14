<?php

/**
 * SeedFromDirectory: seed from the mounted directory on install and upgrade.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Repair
 * @package  OCA\OpenRegister\Repair
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

namespace OCA\OpenRegister\Repair;

use OCA\OpenRegister\Service\Configuration\SeedDirectoryImporter;
use OCA\OpenRegister\Service\Configuration\SeedReport;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Throwable;

/**
 * Imports the operator's seed directory on install and after every migration,
 * so a fresh container comes up with its data and nobody opens the admin.
 *
 * Imports whatever the digest: an upgrade may have changed how a file imports.
 * A failure never stops the upgrade; it is reported and the report is stored.
 *
 * @spec openspec/changes/seed-from-a-mounted-directory/specs/data-import-export/spec.md
 */
class SeedFromDirectory implements IRepairStep {

	/**
	 * Build the step.
	 *
	 * @param SeedDirectoryImporter $importer The seed import.
	 */
	public function __construct(
		private readonly SeedDirectoryImporter $importer,
	) {
	}//end __construct()

	/**
	 * The step's name.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/seed-from-a-mounted-directory/specs/data-import-export/spec.md#requirement-an-installation-is-seeded-from-an-operators-mounted-directory-without-the-admin-req-smd-001
	 */
	public function getName(): string {
		return 'Import the OpenRegister seed directory';
	}//end getName()

	/**
	 * Import the directory and say what happened.
	 *
	 * @param IOutput $output The repair output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/seed-from-a-mounted-directory/specs/data-import-export/spec.md#requirement-an-installation-is-seeded-from-an-operators-mounted-directory-without-the-admin-req-smd-001
	 */
	public function run(IOutput $output): void {
		try {
			$report = $this->importer->import();
		} catch (Throwable $e) {
			$output->warning('Seed directory import failed: ' . $e->getMessage());
			return;
		}

		if ($report === null) {
			$output->info('No seed directory configured (' . SeedDirectoryImporter::CONFIG_KEY . ')');
			return;
		}

		if ($report->status() === SeedReport::STATUS_SUCCESS) {
			$output->info($report->summary());
			return;
		}

		$output->warning($report->summary());
		foreach ($report->failures() as $failure) {
			$output->warning('  ' . $failure['reason']);
		}
	}//end run()
}//end class
