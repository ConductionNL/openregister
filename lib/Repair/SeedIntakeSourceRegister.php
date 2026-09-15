<?php

/**
 * SeedIntakeSourceRegister: materialises the intake-sources register — the
 * `intake-source` schema, so a watched folder, a mailbox or an inbound endpoint
 * is a row somebody can add, switch off and audit rather than a field in a
 * settings screen.
 *
 * OpenRegister does not self-import its own register JSON at boot (ADR-037).
 * This step decodes `lib/Settings/intake_source_register.json` and calls
 * `ConfigurationService::importFromApp(force: false)`, so the import is
 * idempotent: a re-run neither duplicates the schema nor overwrites an
 * administrator's edit. Never throws — a failure logs a warning and leaves the
 * instance otherwise healthy.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
 * @spec openspec/changes/objects-as-the-hinge-between-cases/specs/linked-entity-types/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Repair;

use OCA\OpenRegister\Service\ConfigurationService;
use OCP\App\IAppManager;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Imports the intake-sources register descriptor idempotently on upgrade/install.
 */
class SeedIntakeSourceRegister implements IRepairStep {

	/**
	 * App-relative path to the register descriptor imported by this step.
	 *
	 * @var string
	 */
	public const REGISTER_PATH = '/lib/Settings/intake_source_register.json';

	/**
	 * The register slug this step materialises.
	 *
	 * @var string
	 */
	public const REGISTER_SLUG = 'intake-sources';

	/**
	 * The schema slug an intake source is an object of.
	 *
	 * @var string
	 */
	public const SCHEMA_SLUG = 'intake-source';

	/**
	 * Descriptor version passed to the importer's version_compare gate.
	 *
	 * 🔴 BUMP THIS WHENEVER THE DESCRIPTOR CHANGES. The importer compares this
	 * against what the instance already has and does nothing when they match,
	 * so a descriptor edited without a bump lands on fresh installs only and is
	 * invisible on every instance that already ran the step.
	 *
	 * @var string
	 */
	private const REGISTER_VERSION = '1.0.0';

	/**
	 * Constructor.
	 *
	 * @param ConfigurationService $configurationService The OR configuration importer.
	 * @param IAppManager          $appManager           Resolves the openregister app path on disk.
	 * @param LoggerInterface      $logger               Logger for import diagnostics.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ConfigurationService $configurationService,
		private readonly IAppManager $appManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Get the name of this repair step.
	 *
	 * @return string The step name.
	 *
	 * @spec openspec/changes/objects-as-the-hinge-between-cases/specs/linked-entity-types/spec.md
	 */
	public function getName(): string {
		return 'Seed the intake-sources register (one object per watched folder, mailbox or endpoint)';
	}//end getName()

	/**
	 * Run the repair step, importing the intake-sources register descriptor.
	 *
	 * @param IOutput $output Output interface for status messages.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/objects-as-the-hinge-between-cases/specs/linked-entity-types/spec.md
	 */
	public function run(IOutput $output): void {
		try {
			$path = ($this->appManager->getAppPath('openregister') . self::REGISTER_PATH);
			if (is_file($path) === false) {
				$output->warning('Intake-sources register descriptor not found: ' . $path);
				return;
			}

			$data = json_decode((string)file_get_contents($path), true);
			if (is_array($data) === false) {
				$output->warning('Intake-sources register descriptor is not valid JSON: ' . $path);
				return;
			}

			// The importer takes the DECODED descriptor; force: false keeps the
			// import idempotent and leaves administrator edits in place.
			$this->configurationService->importFromApp(
				appId: 'openregister',
				data: $data,
				version: self::REGISTER_VERSION,
				force: false
			);

			$output->info('Intake-sources register imported (intake-source schema)');
		} catch (Throwable $e) {
			$this->logger->warning('[SeedIntakeSourceRegister] import failed: ' . $e->getMessage());
			$output->warning('Intake-sources register import skipped: ' . $e->getMessage());
		}//end try
	}//end run()
}//end class
