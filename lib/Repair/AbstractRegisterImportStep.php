<?php

/**
 * OpenRegister AbstractRegisterImportStep
 *
 * A repair step that imports one register descriptor shipped in lib/Settings.
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
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Repair;

use OCP\Migration\IRepairStep;
use Throwable;

/**
 * Imports the register descriptor at REGISTER_PATH, version-gated, on upgrade/install.
 *
 * The descriptor is decoded and passed to importFromApp(): importFromFilePath()
 * expects a Nextcloud-root-relative path and would reject the absolute one.
 * A missing or invalid descriptor and a failing import are reported, never thrown.
 */
abstract class AbstractRegisterImportStep implements IRepairStep {
	/**
	 * App-relative path to the register descriptor imported by the step.
	 *
	 * @var string
	 */
	protected const REGISTER_PATH = '';

	/**
	 * Descriptor version passed to the importer's version_compare gate.
	 *
	 * @var string
	 */
	protected const REGISTER_VERSION = '1.0.0';

	/**
	 * App id the import is recorded under, which is what the version gate keys on.
	 *
	 * @var string
	 */
	protected const CONFIG_APP_ID = 'openregister';

	/**
	 * Name of the register in the step's output lines.
	 *
	 * @var string
	 */
	protected const LABEL = '';

	/**
	 * Output line after a successful import.
	 *
	 * @var string
	 */
	protected const IMPORTED = '';

	/**
	 * Constructor.
	 *
	 * @param RegisterDescriptorImport $import The importer, the app path on disk and the log.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly RegisterDescriptorImport $import,
	) {
	}//end __construct()

	/**
	 * Run the repair step, importing the register descriptor.
	 *
	 * @param \OCP\Migration\IOutput $output Output interface for status messages.
	 *
	 * @return void
	 */
	public function run(\OCP\Migration\IOutput $output): void {
		try {
			$data = $this->readDescriptor(output: $output);
			if ($data !== null) {
				$this->import->configurationService->importFromApp(appId: static::CONFIG_APP_ID, data: $data, version: static::REGISTER_VERSION, force: false);
				$output->info(static::IMPORTED);
			}
		} catch (Throwable $e) {
			$step = substr((string)strrchr(static::class, '\\'), 1);
			$this->import->logger->warning('[' . $step . '] import failed: ' . $e->getMessage());
			$output->warning(static::LABEL . ' register import skipped: ' . $e->getMessage());
		}//end try
	}//end run()

	/**
	 * The decoded descriptor, or null after reporting why it cannot be read.
	 *
	 * @param \OCP\Migration\IOutput $output Output interface for status messages.
	 *
	 * @return array<mixed>|null
	 */
	private function readDescriptor(\OCP\Migration\IOutput $output): ?array {
		$path = $this->import->appManager->getAppPath('openregister') . static::REGISTER_PATH;
		if (is_file($path) === false) {
			$output->warning(static::LABEL . ' register descriptor not found: ' . $path);
			return null;
		}

		$data = json_decode((string)file_get_contents($path), true);
		if (is_array($data) === false) {
			$output->warning(static::LABEL . ' register descriptor is not valid JSON: ' . $path);
			return null;
		}

		return $data;
	}//end readDescriptor()
}//end class
