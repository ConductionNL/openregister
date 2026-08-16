<?php

/**
 * OpenRegister Repair — import the e-Depot transfer system register.
 *
 * OpenRegister does not self-import its own `lib/Settings` register
 * descriptors (ADR-037; the `ImportCredentialBrokerRegister` /
 * `ImportDsarRegisters` precedent). This step imports/upgrades the
 * `edepot-transfers` register (the `edepotTransfer` transfer-list schema +
 * the immutable `edepotTransferProof` schema) so the durable transfer +
 * proof-of-transfer records (archival-transfer-hardening, OR-AD-2/OR-AD-3)
 * have a home. Version-gated (`force: false`); re-runs are no-ops.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Repair
 * @package  OCA\OpenRegister\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/archival-transfer-hardening/specs/edepot-proof-of-transfer/spec.md
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
 * Import the e-Depot transfer + proof-of-transfer register from lib/Settings.
 *
 * @psalm-suppress UnusedClass Instantiated by the NC repair framework (appinfo/info.xml).
 *
 * @spec openspec/changes/archival-transfer-hardening/specs/edepot-proof-of-transfer/spec.md
 *   (Requirement: Durable transfer-list objects served over the API)
 */
class ImportEdepotTransferRegister implements IRepairStep {

	/**
	 * App-relative descriptor path.
	 *
	 * @var string
	 */
	private const REGISTER_PATH = '/lib/Settings/edepot_transfer_register.json';

	/**
	 * Descriptor version passed to the importer's version_compare gate.
	 *
	 * @var string
	 */
	private const REGISTER_VERSION = '1.0.0';

	/**
	 * Configuration identity for the descriptor (its own appId so the
	 * importFromApp version gate is independent of the other system registers).
	 *
	 * @var string
	 */
	private const CONFIG_APP_ID = 'openregister.edepot-transfers';

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
	 * @spec openspec/changes/archival-transfer-hardening/specs/edepot-proof-of-transfer/spec.md
	 *   (Requirement: Durable transfer-list objects served over the API)
	 */
	public function getName(): string {
		return 'Import OpenRegister e-Depot transfer register (transfer lists + proof-of-transfer records)';
	}//end getName()

	/**
	 * Run the repair step, importing the e-Depot transfer register descriptor.
	 *
	 * @param IOutput $output Output interface for status messages.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archival-transfer-hardening/specs/edepot-proof-of-transfer/spec.md
	 *   (Requirement: Durable transfer-list objects served over the API)
	 */
	public function run(IOutput $output): void {
		try {
			$path = $this->appManager->getAppPath('openregister') . self::REGISTER_PATH;
			if (is_file($path) === false) {
				$output->warning('e-Depot transfer register descriptor not found: ' . $path);
				return;
			}

			$data = json_decode((string)file_get_contents($path), true);
			if (is_array($data) === false) {
				$output->warning('e-Depot transfer register descriptor is not valid JSON: ' . $path);
				return;
			}

			$this->configurationService->importFromApp(
				appId: self::CONFIG_APP_ID,
				data: $data,
				version: self::REGISTER_VERSION,
				force: false
			);

			$output->info('e-Depot transfer register imported (edepotTransfer + edepotTransferProof)');
		} catch (Throwable $e) {
			$this->logger->warning('[ImportEdepotTransferRegister] import failed: ' . $e->getMessage());
			$output->warning('e-Depot transfer register import skipped: ' . $e->getMessage());
		}//end try
	}//end run()
}//end class
