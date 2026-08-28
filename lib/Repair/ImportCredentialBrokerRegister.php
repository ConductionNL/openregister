<?php

/**
 * ImportCredentialBrokerRegister — materialises the credential-broker register.
 *
 * OpenRegister does not self-import its own register JSON at boot (ADR-037). This
 * repair step imports `lib/Settings/credential_broker_register.json` via
 * `ConfigurationService::importFromFilePath()` so the `credential` schema and its
 * two secret-less example objects land idempotently on `occ upgrade` (matched by
 * slug, `version_compare`-gated) — mirroring the register-import-via-Repair
 * convention of {@see SeedAppVirtualSchemas}. The provider catalogue is NOT imported
 * here: it is the runtime-immutable `lib/Settings/credential-providers.json` file
 * read directly by the broker (design.md D2). Never throws — a failure logs a warning
 * and leaves the instance otherwise healthy.
 *
 * @category Repair
 * @package  OCA\OpenRegister\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/credential-broker/spec.md
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
 * Imports the credential-broker register descriptor idempotently on upgrade/install.
 */
class ImportCredentialBrokerRegister implements IRepairStep {
	/**
	 * App-relative path to the register descriptor imported by this step.
	 *
	 * @var string
	 */
	private const REGISTER_PATH = '/lib/Settings/credential_broker_register.json';

	/**
	 * Descriptor version passed to the importer's version_compare gate.
	 *
	 * @var string
	 */
	private const REGISTER_VERSION = '1.0.0';

	/**
	 * Constructor.
	 *
	 * @param ConfigurationService $configurationService The OR configuration importer.
	 * @param IAppManager $appManager Resolves the openregister app path on disk.
	 * @param LoggerInterface $logger Logger for import diagnostics.
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
	 * @spec openspec/specs/credential-broker/spec.md
	 */
	public function getName(): string {
		return 'Import OpenRegister credential-broker register (credential schema + example objects)';
	}//end getName()

	/**
	 * Run the repair step, importing the credential-broker register descriptor.
	 *
	 * @param IOutput $output Output interface for status messages.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/credential-broker/spec.md
	 */
	public function run(IOutput $output): void {
		try {
			$path = $this->appManager->getAppPath('openregister') . self::REGISTER_PATH;
			if (is_file($path) === false) {
				$output->warning('Credential-broker register descriptor not found: ' . $path);
				return;
			}

			// Import the DECODED descriptor via importFromApp() — NOT importFromFilePath(),
			// which rejects an absolute path (it expects a Nextcloud-root-relative one) and
			// would fail closed here. importFromApp() takes the data directly.
			$data = json_decode((string)file_get_contents($path), true);
			if (is_array($data) === false) {
				$output->warning('Credential-broker register descriptor is not valid JSON: ' . $path);
				return;
			}

			$this->configurationService->importFromApp(
				appId: 'openregister',
				data: $data,
				version: self::REGISTER_VERSION,
				force: false
			);

			$output->info('Credential-broker register imported (credential schema + example objects)');
		} catch (Throwable $e) {
			$this->logger->warning('[ImportCredentialBrokerRegister] import failed: ' . $e->getMessage());
			$output->warning('Credential-broker register import skipped: ' . $e->getMessage());
		}//end try
	}//end run()
}//end class
