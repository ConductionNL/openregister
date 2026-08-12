<?php

/**
 * OpenRegister Repair — import the vocabulary register + seed the bundled
 * TOOI Woo value lists (skos-concept-registers).
 *
 * OpenRegister does not self-import its own `lib/Settings` register
 * descriptors (ADR-037; the `ImportCredentialBrokerRegister` /
 * `ImportTrustConfigurationRegister` precedent). This step first imports the
 * `vocabulary` register (`conceptScheme` + `concept` schemas), then seeds the
 * Woo-critical TOOI value lists — the 17 informatiecategorieën and the DiWoo
 * documenthandelingen — through {@see VocabularyImportService}'s idempotent
 * URI-keyed upsert, so a fresh instance can serve DiWoo/DCAT vocabulary
 * lookups without network access. Both steps are version-gated / diff-checked
 * and safe to re-run.
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
 * @spec openspec/changes/skos-concept-registers/specs/skos-concept-registers/spec.md#skos-003
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Repair;

use OCA\OpenRegister\Service\ConfigurationService;
use OCA\OpenRegister\Service\SystemOperationContext;
use OCA\OpenRegister\Service\VocabularyImportService;
use OCP\App\IAppManager;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Import the vocabulary register and seed the bundled TOOI fixtures.
 *
 * @psalm-suppress UnusedClass Instantiated by the NC repair framework (appinfo/info.xml).
 *
 * @SuppressWarnings(PHPMD.StaticAccess) SystemOperationContext::run() is a static
 *   trusted-scope holder (see the class docblock) — the repair step's fixture seed
 *   must run inside it because repair steps execute without a user session.
 *
 * @spec openspec/changes/skos-concept-registers/specs/skos-concept-registers/spec.md#skos-003
 */
class SeedVocabularyRegister implements IRepairStep {

	/**
	 * App-relative descriptor path.
	 *
	 * @var string
	 */
	private const REGISTER_PATH = '/lib/Settings/vocabulary_register.json';

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
	private const CONFIG_APP_ID = 'openregister.vocabulary';

	/**
	 * App-relative paths of the bundled TOOI SKOS/JSON-LD seed fixtures.
	 *
	 * @var array<int, string>
	 */
	private const FIXTURE_PATHS = [
		'/lib/Resources/Vocabulary/tooi-informatiecategorieen.jsonld',
		'/lib/Resources/Vocabulary/tooi-documenthandelingen.jsonld',
	];

	/**
	 * Constructor.
	 *
	 * @param ConfigurationService $configurationService The OR configuration importer (register/schema).
	 * @param VocabularyImportService $importService The SKOS/JSON-LD import service (concept data).
	 * @param IAppManager $appManager Resolves the openregister app path on disk.
	 * @param LoggerInterface $logger Logger for seed diagnostics.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ConfigurationService $configurationService,
		private readonly VocabularyImportService $importService,
		private readonly IAppManager $appManager,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Get the name of this repair step.
	 *
	 * @return string The step name.
	 *
	 * @spec openspec/changes/skos-concept-registers/specs/skos-concept-registers/spec.md#skos-003
	 */
	public function getName(): string {
		return 'Import OpenRegister vocabulary register and seed bundled TOOI Woo value lists';
	}//end getName()

	/**
	 * Run the repair step: import the register descriptor, then seed the
	 * bundled TOOI fixtures through the idempotent importer.
	 *
	 * @param IOutput $output Output interface for status messages.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/skos-concept-registers/specs/skos-concept-registers/spec.md#skos-003
	 */
	public function run(IOutput $output): void {
		$this->importRegisterDescriptor(output: $output);
		$this->seedTooiFixtures(output: $output);
	}//end run()

	/**
	 * Import the `vocabulary_register.json` descriptor (conceptScheme + concept schemas).
	 *
	 * @param IOutput $output Output interface for status messages.
	 *
	 * @return void
	 */
	private function importRegisterDescriptor(IOutput $output): void {
		try {
			$path = $this->appManager->getAppPath('openregister') . self::REGISTER_PATH;
			if (is_file($path) === false) {
				$output->warning('Vocabulary register descriptor not found: ' . $path);
				return;
			}

			$data = json_decode((string)file_get_contents($path), true);
			if (is_array($data) === false) {
				$output->warning('Vocabulary register descriptor is not valid JSON: ' . $path);
				return;
			}

			$this->configurationService->importFromApp(
				appId: self::CONFIG_APP_ID,
				data: $data,
				version: self::REGISTER_VERSION,
				force: false
			);

			$output->info('Vocabulary register imported (conceptScheme, concept)');
		} catch (Throwable $e) {
			$this->logger->warning('[SeedVocabularyRegister] register import failed: ' . $e->getMessage());
			$output->warning('Vocabulary register import skipped: ' . $e->getMessage());
		}//end try
	}//end importRegisterDescriptor()

	/**
	 * Seed the bundled TOOI SKOS/JSON-LD fixtures through the idempotent importer.
	 *
	 * Runs inside {@see SystemOperationContext} because repair steps execute
	 * without a user session (app boot / webcron), and the vocabulary schemas'
	 * writes are admin-gated by default — without the trusted scope every seed
	 * write would be denied as anonymous.
	 *
	 * @param IOutput $output Output interface for status messages.
	 *
	 * @return void
	 */
	private function seedTooiFixtures(IOutput $output): void {
		$appPath = $this->appManager->getAppPath('openregister');

		foreach (self::FIXTURE_PATHS as $relativePath) {
			$path = $appPath . $relativePath;

			try {
				if (is_file($path) === false) {
					$output->warning('TOOI vocabulary fixture not found: ' . $path);
					continue;
				}

				$jsonLd = json_decode((string)file_get_contents($path), true);
				if (is_array($jsonLd) === false) {
					$output->warning('TOOI vocabulary fixture is not valid JSON: ' . $path);
					continue;
				}

				$report = SystemOperationContext::run(
					fn (): array => $this->importService->importJsonLd(jsonLd: $jsonLd)
				);

				$output->info(
					sprintf(
						'Seeded vocabulary scheme %s (created=%d, updated=%d, unchanged=%d, deprecated=%d)',
						$report['scheme'],
						$report['created'],
						$report['updated'],
						$report['unchanged'],
						$report['deprecated']
					)
				);
			} catch (Throwable $e) {
				$this->logger->warning('[SeedVocabularyRegister] fixture seed failed for ' . $path . ': ' . $e->getMessage());
				$output->warning('TOOI vocabulary fixture seed skipped (' . $relativePath . '): ' . $e->getMessage());
			}//end try
		}//end foreach
	}//end seedTooiFixtures()
}//end class
