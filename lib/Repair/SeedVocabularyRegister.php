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

use OCA\OpenRegister\Service\Search\SearchDictionaryProvider;
use OCA\OpenRegister\Service\Configuration\DescriptorConfigIdsInterface;
use OCA\OpenRegister\Service\SystemOperationContext;
use OCA\OpenRegister\Kernel\Contracts\VocabularyImporterInterface;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
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
	private const REGISTER_VERSION = '1.1.0';

	/**
	 * Configuration identity for the descriptor (its own appId so the
	 * importFromApp version gate is independent of the other system registers).
	 *
	 * @var string
	 */
	private const CONFIG_APP_ID = DescriptorConfigIdsInterface::VOCABULARY;

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
	 * The search dictionary's concept schemes, seeded empty (see SearchDictionaryProvider).
	 *
	 * @var array<int, array{uri: string, title: string, description: string}>
	 */
	private const DICTIONARY_SCHEMES = [
		[
			'uri'         => SearchDictionaryProvider::SYNONYM_SCHEME,
			'title'       => 'Search synonyms',
			'description' => 'Synonym groups for search: a concept\'s prefLabel and its altLabel entries find each other.',
		],
		[
			'uri'         => SearchDictionaryProvider::STOPWORD_SCHEME,
			'title'       => 'Search stopwords',
			'description' => 'Words search drops: a concept\'s prefLabel is the word to drop.',
		],
	];

	/**
	 * Constructor.
	 *
	 * @param RegisterDescriptorImport $descriptors Importer, app path and log for the descriptor.
	 * @param VocabularyImporterInterface $importService The SKOS/JSON-LD import service (concept data).
	 *
	 * @return void
	 */
	public function __construct(
		private readonly RegisterDescriptorImport $descriptors,
		private readonly VocabularyImporterInterface $importService,
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
		$this->seedDictionarySchemes(output: $output);
	}//end run()

	/**
	 * Make sure the search dictionary's two concept schemes exist, empty, so an
	 * administrator has somewhere to write synonym groups and stopwords.
	 *
	 * Created only when missing and never through the importer: an import of an
	 * empty scheme deprecates every concept it does not list, which on every
	 * upgrade would undo the administrator's dictionary. No synonym or stopword
	 * is seeded; which words mean the same is each organisation's own decision.
	 *
	 * @param IOutput $output Output interface for status messages.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/search-over-history-and-an-administered-dictionary/tasks.md#3-the-dictionary
	 */
	private function seedDictionarySchemes(IOutput $output): void {
		foreach (self::DICTIONARY_SCHEMES as $scheme) {
			try {
				$result = $this->importService->ensureScheme(schemeMeta: $scheme);
			} catch (Throwable $e) {
				$this->descriptors->logger->warning('[SeedVocabularyRegister] dictionary scheme not seeded (' . $scheme['uri'] . '): ' . $e->getMessage());
				$output->warning('Search dictionary scheme skipped (' . $scheme['uri'] . '): ' . $e->getMessage());
				continue;
			}

			if (($result['status'] ?? '') === 'created') {
				$output->info('Created empty search dictionary scheme ' . $result['scheme']);
			}
		}
	}//end seedDictionarySchemes()

	/**
	 * Import the `vocabulary_register.json` descriptor (conceptScheme + concept schemas).
	 *
	 * @param IOutput $output Output interface for status messages.
	 *
	 * @return void
	 */
	private function importRegisterDescriptor(IOutput $output): void {
		try {
			$descriptor = new AppRegisterDescriptor(descriptors: $this->descriptors, label: 'Vocabulary');
			$data = $descriptor->read(relativePath: self::REGISTER_PATH, output: $output);
			if ($data === null) {
				return;
			}

			$this->importDescriptor(data: $data);

			$output->info('Vocabulary register imported (conceptScheme, concept)');
		} catch (Throwable $e) {
			$this->descriptors->logger->warning('[SeedVocabularyRegister] register import failed: ' . $e->getMessage());
			$output->warning('Vocabulary register import skipped: ' . $e->getMessage());
		}//end try
	}//end importRegisterDescriptor()

	/**
	 * Import the decoded vocabulary register descriptor idempotently (force: false).
	 *
	 * @param array $data The decoded descriptor.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/skos-concept-registers/specs/skos-concept-registers/spec.md#skos-003
	 */
	private function importDescriptor(array $data): void {
		$this->descriptors->configurationService->importFromApp(
			appId: self::CONFIG_APP_ID,
			data: $data,
			version: self::REGISTER_VERSION,
			force: false
		);
	}//end importDescriptor()

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
		$appPath = $this->descriptors->appManager->getAppPath('openregister');

		foreach (self::FIXTURE_PATHS as $relativePath) {
			$path = $appPath . $relativePath;

			try {
				$this->seedFixture(path: $path, output: $output);
			} catch (Throwable $e) {
				$this->descriptors->logger->warning('[SeedVocabularyRegister] fixture seed failed for ' . $path . ': ' . $e->getMessage());
				$output->warning('TOOI vocabulary fixture seed skipped (' . $relativePath . '): ' . $e->getMessage());
			}//end try
		}//end foreach
	}//end seedTooiFixtures()

	/**
	 * Seed one TOOI fixture, or warn why it cannot be read.
	 *
	 * @param string  $path   The fixture's absolute path.
	 * @param IOutput $output Output interface for status messages.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/skos-concept-registers/specs/skos-concept-registers/spec.md#skos-003
	 */
	private function seedFixture(string $path, IOutput $output): void {
		if (is_file($path) === false) {
			$output->warning('TOOI vocabulary fixture not found: ' . $path);
			return;
		}

		$jsonLd = json_decode((string)file_get_contents($path), true);
		if (is_array($jsonLd) === false) {
			$output->warning('TOOI vocabulary fixture is not valid JSON: ' . $path);
			return;
		}

		// NOSEMGREP: php.lang.security.exec-use.exec-use -- SystemOperationContext is a scope flag, no process is started.
		$report = SystemOperationContext::run(
			fn (): array => $this->importService->importJsonLd(jsonLd: $jsonLd)
		);

		$output->info(self::seededLine(report: $report));
	}//end seedFixture()

	/**
	 * The line for a seeded fixture: its scheme and the importer's counts.
	 *
	 * @param array<string, mixed> $report The importer's report.
	 *
	 * @return string The line.
	 *
	 * @spec openspec/changes/skos-concept-registers/specs/skos-concept-registers/spec.md#skos-003
	 */
	private static function seededLine(array $report): string {
		return sprintf(
			'Seeded vocabulary scheme %s (created=%d, updated=%d, unchanged=%d, deprecated=%d)',
			$report['scheme'],
			$report['created'],
			$report['updated'],
			$report['unchanged'],
			$report['deprecated']
		);
	}//end seededLine()
}//end class
