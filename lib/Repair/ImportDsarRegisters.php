<?php

/**
 * OpenRegister Repair — import/upgrade the shipped DSAR registers.
 *
 * OpenRegister does NOT self-import its own `lib/Settings` register
 * descriptors (ADR-037: register config reaches an instance through an
 * explicit Repair step — the `ImportCredentialBrokerRegister` precedent).
 * Without this step the DSAR registers' declared behaviour — the
 * `escalationTier` calculation, the deadline reminder/escalation/breach
 * notification rules, the `breachedAt` stamp, the `dpiaFlagged` rule, and
 * the policy pack's `dpiaDetection` + `privacyOfficerGroup` fields
 * (dsar-escalation-and-dpia) — never reaches a live instance, leaving the
 * temporal sweep and the DPIA detection job with nothing to make live.
 *
 * The import is version-gated (`ConfigurationService::importFromApp` with
 * `force: false`), so existing installs upgrade their schema definitions in
 * place and re-runs are no-ops.
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
 * @spec openspec/changes/dsar-escalation-and-dpia/specs/dsar-deadline-escalation/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Repair;

use OCA\OpenRegister\Service\Configuration\DescriptorConfigIdsInterface;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Throwable;

/**
 * Import the DSAR case + policy-pack registers from lib/Settings.
 *
 * @psalm-suppress UnusedClass Instantiated by the NC repair framework (appinfo/info.xml).
 *
 * @spec openspec/changes/dsar-escalation-and-dpia/specs/dsar-deadline-escalation/spec.md
 *   (Requirement: Time-dependent calculated fields re-evaluate without object writes)
 */
class ImportDsarRegisters implements IRepairStep {

	/**
	 * App-relative descriptor paths mapped to their configuration identity
	 * (appId) + the descriptor version passed to the importer's
	 * version_compare gate (bump alongside the schema versions).
	 *
	 * Each descriptor gets its OWN configuration appId: `importFromApp`
	 * version-gates per configuration row, so two descriptors sharing one
	 * appId silently skip whichever imports second (verified live — the
	 * policy-pack file was version-gated away against the case register's
	 * higher version under a shared 'openregister' id).
	 *
	 * @var array<string, array{appId: string, version: string}>
	 */
	private const REGISTERS = [
		'/lib/Settings/data_subject_request_register.json' => [
			'appId' => DescriptorConfigIdsInterface::DSAR_CASES,
			'version' => '1.2.0',
		],
		'/lib/Settings/dsar_policy_pack_register.json' => [
			'appId' => DescriptorConfigIdsInterface::DSAR_POLICY_PACKS,
			'version' => '1.1.0',
		],
	];

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
	 * Get the name of this repair step.
	 *
	 * @return string The step name.
	 *
	 * @spec openspec/changes/dsar-escalation-and-dpia/specs/dsar-deadline-escalation/spec.md
	 *   (Requirement: Time-dependent calculated fields re-evaluate without object writes)
	 */
	public function getName(): string {
		return 'Import OpenRegister DSAR registers (data-subject requests + policy packs)';
	}//end getName()

	/**
	 * Run the repair step, importing both DSAR register descriptors.
	 *
	 * @param IOutput $output Output interface for status messages.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dsar-escalation-and-dpia/specs/dsar-deadline-escalation/spec.md
	 *   (Requirement: Time-dependent calculated fields re-evaluate without object writes)
	 */
	public function run(IOutput $output): void {
		foreach (array_keys(self::REGISTERS) as $relativePath) {
			$this->importOne(relativePath: $relativePath, output: $output);
		}
	}//end run()

	/**
	 * Import one DSAR register descriptor, or warn why it was skipped.
	 *
	 * @param string  $relativePath The descriptor's path under the openregister app.
	 * @param IOutput $output       Output interface for status messages.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dsar-escalation-and-dpia/specs/dsar-deadline-escalation/spec.md
	 */
	private function importOne(string $relativePath, IOutput $output): void {
		$descriptor = self::REGISTERS[$relativePath];

		try {
			$data = $this->readDescriptor(relativePath: $relativePath, output: $output);
			if ($data === null) {
				return;
			}

			$this->importData(data: $data, descriptor: $descriptor);

			$output->info('DSAR register imported: ' . basename($relativePath) . ' (v' . $descriptor['version'] . ')');
		} catch (Throwable $e) {
			$this->import->logger->warning('[ImportDsarRegisters] import failed for ' . $relativePath . ': ' . $e->getMessage());
			$output->warning('DSAR register import skipped for ' . basename($relativePath) . ': ' . $e->getMessage());
		}//end try
	}//end importOne()

	/**
	 * Read and decode one descriptor, or warn why it cannot be imported.
	 *
	 * Import the DECODED descriptor via importFromApp() — NOT
	 * importFromFilePath(), which rejects an absolute path (it
	 * expects a Nextcloud-root-relative one) and would fail
	 * closed here. importFromApp() takes the data directly.
	 *
	 * @param string  $relativePath The descriptor's path under the openregister app.
	 * @param IOutput $output       Output interface for status messages.
	 *
	 * @return array|null The decoded descriptor, or null after the warning.
	 *
	 * @spec openspec/changes/dsar-escalation-and-dpia/specs/dsar-deadline-escalation/spec.md
	 */
	private function readDescriptor(string $relativePath, IOutput $output): ?array {
		$path = $this->import->appManager->getAppPath('openregister') . $relativePath;
		if (is_file($path) === false) {
			$output->warning('DSAR register descriptor not found: ' . $path);
			return null;
		}

		$data = json_decode((string)file_get_contents($path), true);
		if (is_array($data) === false) {
			$output->warning('DSAR register descriptor is not valid JSON: ' . $path);
			return null;
		}

		return $data;
	}//end readDescriptor()

	/**
	 * Import a decoded descriptor for its config app id, idempotently (force: false).
	 *
	 * @param array                $data       The decoded descriptor.
	 * @param array<string, mixed> $descriptor The descriptor's app id and version.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dsar-escalation-and-dpia/specs/dsar-deadline-escalation/spec.md
	 */
	private function importData(array $data, array $descriptor): void {
		$this->import->configurationService->importFromApp(
			appId: $descriptor['appId'],
			data: $data,
			version: $descriptor['version'],
			force: false
		);
	}//end importData()
}//end class
