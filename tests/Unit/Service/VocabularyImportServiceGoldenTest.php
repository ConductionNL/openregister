<?php

/**
 * Golden test for VocabularyImportService.
 *
 * Runs JSON-LD and CSV imports against an in-memory object store and records
 * the report (or the exception), every findAll/find/saveObject call in order,
 * the logged import summary and the final store, per scenario, in
 * fixtures/vocabulary-import-golden.json. Set UPDATE_GOLDEN=1 to rewrite it.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\VocabularyImportService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Throwable;
use OCA\OpenRegister\Service\Object\ObjectSaveOptions;

/**
 * @covers \OCA\OpenRegister\Service\VocabularyImportService
 */
class VocabularyImportServiceGoldenTest extends TestCase {

	private const SCHEME = 'https://example.org/vocab/g';

	/**
	 * In-memory store: schema => uuid => data.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	private array $store = [];

	/**
	 * Ordered call log.
	 *
	 * @var array<int, mixed>
	 */
	private array $log = [];

	/**
	 * Next uuid number.
	 *
	 * @var int
	 */
	private int $sequence = 0;

	/**
	 * A rich JSON-LD graph exercising every literal / language-map / reference shape.
	 *
	 * @return array<string, mixed>
	 */
	private static function richGraph(): array {
		$skos = 'http://www.w3.org/2004/02/skos/core#';
		return [
			'@graph' => [
				'not a node',
				[
					'@id' => self::SCHEME,
					'@type' => $skos.'ConceptScheme',
					'http://purl.org/dc/terms/title' => [['@value' => ''], ['@value' => 'Golden scheme']],
					'dct:publisher' => '  Golden publisher ',
					'schema:version' => '2.5',
					'dct:description' => ['@value' => 'Described'],
				],
				['@id' => 'https://example.org/other-scheme', '@type' => 'skos:ConceptScheme'],
				[
					'@id' => self::SCHEME.'/a',
					'@type' => 'skos:Concept',
					'skos:prefLabel' => [
						['@value' => 'A nl', '@language' => 'nl'],
						['@value' => 'A en', '@language' => 'en'],
						['@value' => 'A untagged'],
						'   ',
					],
					'skos:altLabel' => ['nl' => 'Alt A', 'en' => '', 'de' => 5],
					'skos:definition' => ['', '  ', 'Def A'],
					'skos:notation' => ['@value' => 7],
					'skos:narrower' => [['@id' => self::SCHEME.'/b'], self::SCHEME.'/c', '', ['@id' => 'https://elsewhere/x'], 3],
					'skos:related' => self::SCHEME.'/c',
				],
				[
					'@id' => self::SCHEME.'/b',
					'@type' => $skos.'Concept',
					$skos.'prefLabel' => 'B only string',
					'skos:altLabel' => '',
					'skos:broader' => ['@id' => self::SCHEME.'/a'],
					'skos:definition' => 42,
				],
				[
					'@id' => self::SCHEME.'/c',
					'@type' => 'Concept',
					'skos:prefLabel' => ['@value' => 'C label'],
					'skos:altLabel' => [['@value' => 'C alt', '@language' => 'en'], 'C bare'],
					'skos:broader' => [self::SCHEME.'/a', self::SCHEME.'/b'],
					'skos:related' => '  ',
					'skos:notation' => '',
				],
				['@id' => '', '@type' => 'skos:Concept', 'skos:prefLabel' => 'skipped'],
				['@type' => 'skos:Concept', 'skos:prefLabel' => 'no id'],
				['@id' => self::SCHEME.'/other', '@type' => 'skos:Collection'],
			],
		];
	}//end richGraph()

	/**
	 * Scenarios: [kind (json|csv), input, seed entries [schema, uuid, data], second input or null].
	 *
	 * @return array<string, array<int, mixed>>
	 */
	public static function scenarios(): array {
		$scheme = self::SCHEME;
		$csv = "uri , notation,prefLabel_nl,prefLabel_en,definition,broaderUri\n"
			."{$scheme}/x,X,Waarde X,Value X,Def X,\n"
			."\n"
			."{$scheme}/y,Y,Waarde Y,  ,Def Y,{$scheme}/x | {$scheme}/z\n"
			.",N,No uri,,,\n"
			."{$scheme}/z,Z,Waarde Z\n";
		$meta = ['uri' => $scheme, 'title' => 'CSV scheme', 'publisher' => 'Pub'];
		$seedConcepts = [];
		for ($i = 0; $i < 201; $i++) {
			$seedConcepts[] = ['concept', 'seed-'.$i, ['uri' => $scheme.'/old-'.$i, 'inScheme' => 'uuid-scheme', 'deprecated' => ($i === 5)]];
		}

		$seedConcepts[] = ['concept', 'seed-nouri', ['inScheme' => 'uuid-scheme']];
		$seedScheme = ['conceptScheme', 'uuid-scheme', ['uri' => $scheme, 'title' => 'Old title', 'extra' => 'kept']];
		$seedA = ['concept', 'uuid-a', ['uri' => $scheme.'/a', 'prefLabel' => ['nl' => 'A nl', 'en' => 'A en'], 'inScheme' => 'uuid-scheme', 'deprecated' => true, 'custom' => 1, 'broader' => ['zzz', '', 9], 'narrower' => 'notalist']];

		return [
			'rich graph fresh' => ['json', self::richGraph(), [], null],
			'rich graph reimported' => ['json', self::richGraph(), [], self::richGraph()],
			'rich graph over seeded store' => ['json', self::richGraph(), [$seedScheme, $seedA], null],
			'single top-level scheme node' => ['json', ['@id' => $scheme, '@type' => 'skos:ConceptScheme', 'owl:versionInfo' => ' ', 'dct:title' => ['@value' => 'T']], [], null],
			'scheme without version keys' => ['json', ['@graph' => [['@id' => $scheme, '@type' => 'ConceptScheme']]], [], null],
			'scheme unchanged on reimport' => ['json', ['@graph' => [['@id' => $scheme, '@type' => 'ConceptScheme', 'dct:title' => 'Same']]], [], ['@graph' => [['@id' => $scheme, '@type' => 'ConceptScheme', 'dct:title' => 'Same']]]],
			'deprecation sweep pages' => ['json', ['@graph' => [['@id' => $scheme, '@type' => 'ConceptScheme'], ['@id' => $scheme.'/old-3', '@type' => 'Concept', 'skos:prefLabel' => 'Three']]], array_merge([$seedScheme], $seedConcepts), null],
			'no graph' => ['json', ['foo' => 'bar'], [], null],
			'graph not array' => ['json', ['@graph' => 'x'], [], null],
			'no scheme node' => ['json', ['@graph' => [['@id' => $scheme.'/a', '@type' => 'Concept']]], [], null],
			'scheme without id' => ['json', ['@graph' => [['@type' => 'ConceptScheme']]], [], null],
			'scheme with numeric id' => ['json', ['@graph' => [['@id' => 5, '@type' => 'ConceptScheme']]], [], null],
			'concept without nl label' => ['json', ['@graph' => [['@id' => $scheme, '@type' => 'ConceptScheme'], ['@id' => $scheme.'/q', '@type' => 'Concept', 'skos:prefLabel' => ['@value' => 'Q', '@language' => 'en']]]], [], null],
			'concept with blank nl label' => ['json', ['@graph' => [['@id' => $scheme, '@type' => 'ConceptScheme'], ['@id' => $scheme.'/q', '@type' => 'Concept', 'skos:prefLabel' => ['nl' => ' ']]]], [], null],
			'csv value list' => ['csv', [$csv, $meta], [], null],
			'csv value list minimal meta' => ['csv', ["uri,prefLabel_nl\n{$scheme}/m,M\n", ['uri' => $scheme]], [], null],
			'csv value list reimported' => ['csv', [$csv, $meta], [], [$csv, $meta]],
			'csv missing scheme uri' => ['csv', [$csv, ['title' => 'x']], [], null],
			'csv blank scheme uri' => ['csv', [$csv, ['uri' => '  ']], [], null],
			'csv missing file' => ['csv', [null, $meta], [], null],
			'csv empty file' => ['csv', ['', $meta], [], null],
			'csv header only' => ['csv', ["uri,prefLabel_nl\n", $meta], [], null],
			'csv concept without label' => ['csv', ["uri,prefLabel_nl\n{$scheme}/n,\n", $meta], [], null],
		];
	}//end scenarios()

	/**
	 * @dataProvider scenarios
	 *
	 * @param string                $kind   json|csv.
	 * @param array<mixed>          $input  JSON-LD document or [csv content, scheme meta].
	 * @param array<int, mixed>     $seed   Store seed entries.
	 * @param array<mixed>|null     $second Optional second import input.
	 *
	 * @return void
	 */
	public function testImportMatchesGolden(string $kind, array $input, array $seed, ?array $second): void {
		$this->store = [];
		$this->log = [];
		$this->sequence = 0;
		foreach ($seed as [$schema, $uuid, $data]) {
			$this->store[$schema][$uuid] = $data;
		}

		$service = new VocabularyImportService($this->objectService(), $this->logger());

		$results = [$this->runImport($service, $kind, $input)];
		if ($second !== null) {
			$results[] = $this->runImport($service, $kind, $second);
		}

		$actual = ['results' => $results, 'log' => $this->log, 'store' => $this->store];
		$actual = json_decode(json_encode($actual, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION), true);

		$file = __DIR__.'/fixtures/vocabulary-import-golden.json';
		$name = $this->dataName();
		$all = is_file($file) === true ? json_decode(file_get_contents($file), true) : [];
		if (getenv('UPDATE_GOLDEN') === '1') {
			$all[$name] = $actual;
			ksort($all);
			file_put_contents($file, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)."\n");
		}

		$this->assertArrayHasKey($name, $all);
		$this->assertSame($all[$name], $actual);
	}//end testImportMatchesGolden()

	/**
	 * Run one import and capture its report or exception.
	 *
	 * @param VocabularyImportService $service The importer.
	 * @param string                  $kind    json|csv.
	 * @param array<mixed>            $input   The import input.
	 *
	 * @return array<string, mixed>
	 */
	private function runImport(VocabularyImportService $service, string $kind, array $input): array {
		try {
			if ($kind === 'json') {
				return ['report' => $service->importJsonLd($input)];
			}

			return ['report' => $service->importCsvValueList($this->csvPath($input[0]), $input[1])];
		} catch (Throwable $e) {
			return ['exception' => get_class($e), 'message' => str_replace(sys_get_temp_dir(), '<tmp>', $e->getMessage())];
		}
	}//end runImport()

	/**
	 * Write CSV content to a temp file (null yields a path that does not exist).
	 *
	 * @param string|null $content The CSV content.
	 *
	 * @return string
	 */
	private function csvPath(?string $content): string {
		$path = sys_get_temp_dir().'/vocabulary-golden.csv';
		if (is_file($path) === true) {
			unlink($path);
		}

		if ($content !== null) {
			file_put_contents($path, $content);
		}

		return $path;
	}//end csvPath()

	/**
	 * Logger recording info() messages without file/line context.
	 *
	 * @return LoggerInterface
	 */
	private function logger(): LoggerInterface {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('info')->willReturnCallback(
			function ($message, array $context = []): void {
				unset($context['file'], $context['line']);
				$this->log[] = ['info', $message, $context];
			}
		);
		return $logger;
	}//end logger()

	/**
	 * Object service fake over the in-memory store.
	 *
	 * @return ObjectOperationsInterface
	 */
	private function objectService(): ObjectOperationsInterface {
		$objects = $this->createMock(ObjectOperationsInterface::class);
		$objects->method('findAll')->willReturnCallback(
			function (array $config = []): array {
				$this->log[] = ['findAll', $config];
				$filters = $config['filters'];
				$schema = $filters['schema'];
				unset($filters['register'], $filters['schema']);
				$matches = [];
				foreach (($this->store[$schema] ?? []) as $uuid => $data) {
					if ($this->sameValues($data, $filters) === true) {
						$matches[] = $this->entity($uuid, $data);
					}
				}

				return array_slice($matches, (int)($config['offset'] ?? 0), $config['limit'] ?? null);
			}
		);
		$objects->method('find')->willReturnCallback(
			function ($id, $extend = [], $files = false, $register = null, $schema = null) {
				$this->log[] = ['find', $id, $register, $schema];
				$data = ($this->store[$schema][$id] ?? null);
				return $data === null ? null : $this->entity((string)$id, $data);
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array|ObjectEntity $object, ObjectSaveOptions $options = new ObjectSaveOptions(), bool $_rbac = true, bool $_multitenancy = true) {
				$register = $options->target->register;
				$schema = $options->target->schema;
				$uuid = $options->uuid;
				$this->log[] = ['saveObject', $object, $register, $schema, $uuid];
				$uuid = ($uuid ?? 'uuid-'.(++$this->sequence));
				$this->store[$schema][$uuid] = $object;
				return $this->entity($uuid, $object);
			}
		);
		return $objects;
	}//end objectService()

	/**
	 * Strict per-key equality of filters against data.
	 *
	 * @param array<string, mixed> $data    Stored data.
	 * @param array<string, mixed> $filters Filters.
	 *
	 * @return bool
	 */
	private function sameValues(array $data, array $filters): bool {
		foreach ($filters as $key => $value) {
			if (($data[$key] ?? null) !== $value) {
				return false;
			}
		}

		return true;
	}//end sameValues()

	/**
	 * Build an entity.
	 *
	 * @param string               $uuid The uuid.
	 * @param array<string, mixed> $data The data.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $uuid, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($data);
		return $entity;
	}//end entity()
}//end class
