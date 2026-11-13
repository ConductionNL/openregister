<?php

/**
 * The search dictionary's two concept schemes are seeded empty, and never touch what an administrator wrote.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/search-over-history-and-an-administered-dictionary/tasks.md#3-the-dictionary
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Vocabulary;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Kernel\Contracts\VocabularyImporterInterface;
use OCA\OpenRegister\Repair\RegisterDescriptorImport;
use OCA\OpenRegister\Repair\SeedVocabularyRegister;
use OCA\OpenRegister\Service\ConfigurationService;
use OCA\OpenRegister\Service\Search\SearchDictionaryProvider;
use OCA\OpenRegister\Service\VocabularyImportService;
use OCA\OpenRegister\Service\VocabularyObjectStore;
use OCP\App\IAppManager;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Create when missing, leave alone when present, never through the deprecating import.
 *
 * @covers \OCA\OpenRegister\Service\VocabularyObjectStore
 * @covers \OCA\OpenRegister\Service\VocabularyImportService
 * @covers \OCA\OpenRegister\Repair\SeedVocabularyRegister
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Repair\RegisterDescriptorImport
 * @uses   \OCA\OpenRegister\Service\VocabularyCsvReader
 * @uses   \OCA\OpenRegister\Service\SkosNodeReader
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\SaveActor
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\SavePolicy
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\BulkBatchDefaults
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\BulkSaveOptions
 * @uses   \OCA\OpenRegister\Repair\AppRegisterDescriptor
 * @uses   \OCA\OpenRegister\Service\SystemOperationContext
 */
final class SearchDictionarySchemeSeedTest extends TestCase {

	/**
	 * The schemes the object service holds, by uri.
	 *
	 * @var array<string, ObjectEntity>
	 */
	private array $stored = [];

	/**
	 * Every object saved, as [schema, data, uuid].
	 *
	 * @var array<int, array>
	 */
	private array $saved = [];

	private function objects(): ObjectOperationsInterface {
		$objects = $this->createMock(ObjectOperationsInterface::class);
		$objects->method('findAll')->willReturnCallback(
			function (array $config = []): array {
				$uri = ($config['filters']['uri'] ?? null);
				if ($uri === null) {
					throw new \LogicException('only a uri lookup is expected: no concept page, no deprecation sweep');
				}

				return isset($this->stored[$uri]) === true ? [$this->stored[$uri]] : [];
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array|ObjectEntity $object, ObjectSaveOptions $options = new ObjectSaveOptions()): ObjectEntity {
				$this->saved[] = [$options->target->schema, $object, $options->uuid];
				$entity = new ObjectEntity();
				$entity->setUuid('new-'.count($this->saved));
				return $entity;
			}
		);

		return $objects;
	}

	private function existing(string $uri, string $uuid): void {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject(['uri' => $uri, 'title' => 'Renamed by an administrator']);
		$this->stored[$uri] = $entity;
	}

	public function testTheStoreCreatesOnlyWhenTheUriIsUnknown(): void {
		$store = new VocabularyObjectStore($this->objects(), 'conceptScheme');
		$this->existing('u:present', 'p-1');

		$this->assertSame(['status' => 'created', 'uuid' => 'new-1'], $store->createIfMissing(['uri' => 'u:new', 'title' => 'T']));
		$this->assertSame(['status' => 'present', 'uuid' => 'p-1'], $store->createIfMissing(['uri' => 'u:present', 'title' => 'Other']));
		$this->assertSame([['conceptScheme', ['uri' => 'u:new', 'title' => 'T'], null]], $this->saved, 'the present scheme is not saved again');
	}

	public function testEnsureSchemeCreatesAnEmptySchemeAndNeverSweepsConcepts(): void {
		$importer = new VocabularyImportService(objectService: $this->objects(), logger: $this->createMock(LoggerInterface::class));

		$created = $importer->ensureScheme(['uri' => 'u:syn', 'title' => 'Search synonyms', 'description' => 'Groups.']);
		$this->existing('u:stop', 's-1');
		$present = $importer->ensureScheme(['uri' => 'u:stop', 'title' => 'Search stopwords']);

		$this->assertSame(['scheme' => 'u:syn', 'status' => 'created'], $created);
		$this->assertSame(['scheme' => 'u:stop', 'status' => 'present'], $present);
		$this->assertSame(
			[['conceptScheme', ['uri' => 'u:syn', 'title' => 'Search synonyms', 'publisher' => 'OpenRegister', 'version' => '1.0', 'source' => 'u:syn', 'description' => 'Groups.'], null]],
			$this->saved
		);
	}

	public function testTheRepairStepSeedsBothSchemesAndContinuesPastAFailure(): void {
		$importer = $this->createMock(VocabularyImporterInterface::class);
		$asked = [];
		$importer->method('ensureScheme')->willReturnCallback(
			static function (array $scheme) use (&$asked): array {
				$asked[] = $scheme['uri'];
				if ($scheme['uri'] === SearchDictionaryProvider::SYNONYM_SCHEME) {
					throw new RuntimeException('register not imported yet');
				}

				return ['scheme' => $scheme['uri'], 'status' => 'created'];
			}
		);
		$importer->expects($this->exactly(2))->method('importJsonLd')->willReturn(['scheme' => 'tooi', 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'deprecated' => 0]);
		$output = $this->createMock(IOutput::class);
		$warnings = [];
		$infos = [];
		$output->method('warning')->willReturnCallback(static function (string $m) use (&$warnings): void { $warnings[] = $m; });
		$output->method('info')->willReturnCallback(static function (string $m) use (&$infos): void { $infos[] = $m; });
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getAppPath')->willReturn((string)realpath(__DIR__.'/../../../..'));

		(new SeedVocabularyRegister(
			descriptors: new RegisterDescriptorImport(configurationService: $this->createMock(ConfigurationService::class), appManager: $apps, logger: $this->createMock(LoggerInterface::class)),
			importService: $importer
		))->run($output);

		$this->assertSame([SearchDictionaryProvider::SYNONYM_SCHEME, SearchDictionaryProvider::STOPWORD_SCHEME], $asked);
		$this->assertContains('Search dictionary scheme skipped ('.SearchDictionaryProvider::SYNONYM_SCHEME.'): register not imported yet', $warnings);
		$this->assertContains('Created empty search dictionary scheme '.SearchDictionaryProvider::STOPWORD_SCHEME, $infos);
	}
}
