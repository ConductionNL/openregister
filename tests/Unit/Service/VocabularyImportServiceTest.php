<?php

/**
 * Unit tests for {@see \OCA\OpenRegister\Service\VocabularyImportService}
 * (skos-concept-registers, SKOS-002).
 *
 * Backs {@see VocabularyImportService} with an in-memory ObjectService fake
 * (a PHPUnit mock whose findAll/find/saveObject callbacks operate on a plain
 * array keyed by schema+uuid) so the real upsert/idempotency/deprecation/
 * relation logic runs end-to-end without a database.
 *
 * @category Test
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
 *
 * @spec openspec/changes/skos-concept-registers/specs/skos-concept-registers/spec.md#skos-002
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\VocabularyImportService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @coversDefaultClass \OCA\OpenRegister\Service\VocabularyImportService
 */
class VocabularyImportServiceTest extends TestCase {

	/**
	 * In-memory fake store: schema => uuid => ObjectEntity.
	 *
	 * @var array<string, array<string, ObjectEntity>>
	 */
	private array $store = [];

	/**
	 * uuid sequence counter for the fake store.
	 *
	 * @var int
	 */
	private int $sequence = 0;

	/**
	 * Every config array handed to ObjectService::findAll(), in call order.
	 *
	 * Recorded so the tests can assert what was ASKED, not only what came back.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $findAllCalls = [];

	/**
	 * @var ObjectService&MockObject
	 */
	private ObjectService $objectService;

	private VocabularyImportService $importer;

	private string $fixturesDir;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = [];
		$this->sequence = 0;
		$this->findAllCalls = [];
		$this->fixturesDir = __DIR__ . '/../../Fixtures/vocabulary';

		$this->objectService = $this->createMock(ObjectService::class);

		$this->objectService->method('findAll')->willReturnCallback(
			function (array $config): array {
				return $this->fakeFindAll($config);
			}
		);

		$this->objectService->method('find')->willReturnCallback(
			function ($id, $_extend = [], $files = false, $register = null, $schema = null, ...$rest) {
				return $this->fakeFind((string)$id, (string)$schema);
			}
		);

		$this->objectService->method('saveObject')->willReturnCallback(
			function ($object, $extend = [], $register = null, $schema = null, $uuid = null, ...$rest) {
				return $this->fakeSaveObject($object, (string)$schema, $uuid);
			}
		);

		$logger = $this->createMock(LoggerInterface::class);

		$this->importer = new VocabularyImportService(objectService: $this->objectService, logger: $logger);
	}//end setUp()

	/**
	 * A fresh import creates the scheme + both concepts, with broader/narrower
	 * maintained in both directions from a source that only asserts `broader`.
	 *
	 * @return void
	 */
	public function testImportJsonLdCreatesSchemeAndConceptsWithBidirectionalRelations(): void {
		$report = $this->importFixture('sample-scheme.jsonld');

		$this->assertSame('https://example.org/vocab/sample-scheme', $report['scheme']);
		$this->assertSame(2, $report['created']);
		$this->assertSame(0, $report['updated']);
		$this->assertSame(0, $report['unchanged']);
		$this->assertSame(0, $report['deprecated']);

		$concepts = $this->conceptsByUri();
		$a = $concepts['https://example.org/vocab/sample-scheme/a'];
		$b = $concepts['https://example.org/vocab/sample-scheme/b'];

		$this->assertSame('Concept A', $a->getObject()['prefLabel']['nl']);
		$this->assertSame('Concept A (en)', $a->getObject()['prefLabel']['en']);
		$this->assertSame([$b->getUuid()], $a->getObject()['narrower'], 'A must list B as narrower (derived from B.broader=A)');
		$this->assertSame([$a->getUuid()], $b->getObject()['broader']);
		$this->assertFalse($a->getObject()['deprecated']);
	}//end testImportJsonLdCreatesSchemeAndConceptsWithBidirectionalRelations()

	/**
	 * Re-importing an identical source is a full no-op (SKOS-002 scenario 1).
	 *
	 * @return void
	 */
	public function testReimportOfIdenticalSourceIsNoOp(): void {
		$this->importFixture('sample-scheme.jsonld');
		$countBefore = count($this->store[VocabularyImportService::SCHEMA_CONCEPT] ?? []);

		$report = $this->importFixture('sample-scheme.jsonld');

		$this->assertSame(0, $report['created']);
		$this->assertSame(0, $report['updated']);
		$this->assertSame(2, $report['unchanged']);
		$this->assertSame(0, $report['deprecated']);
		$this->assertSame($countBefore, count($this->store[VocabularyImportService::SCHEMA_CONCEPT] ?? []));
	}//end testReimportOfIdenticalSourceIsNoOp()

	/**
	 * A changed label updates the existing concept in place (SKOS-002).
	 *
	 * A removed concept (B, absent from the updated fixture) is deprecated,
	 * not deleted, and remains retrievable (SKOS-002 scenario 2).
	 *
	 * @return void
	 */
	public function testLabelChangeUpdatesInPlaceAndRemovedConceptIsDeprecatedNotDeleted(): void {
		$this->importFixture('sample-scheme.jsonld');
		$countBefore = count($this->store[VocabularyImportService::SCHEMA_CONCEPT] ?? []);

		$report = $this->importFixture('sample-scheme-updated.jsonld');

		$this->assertSame(0, $report['created']);
		$this->assertSame(1, $report['updated'], 'Concept A label change must update in place');
		$this->assertSame(0, $report['unchanged']);
		$this->assertSame(1, $report['deprecated'], 'Concept B, absent from the re-imported source, must be deprecated');

		// Never deleted — count stays the same.
		$this->assertSame($countBefore, count($this->store[VocabularyImportService::SCHEMA_CONCEPT] ?? []));

		$concepts = $this->conceptsByUri();
		$a = $concepts['https://example.org/vocab/sample-scheme/a'];
		$b = $concepts['https://example.org/vocab/sample-scheme/b'];

		$this->assertSame('Concept A (renamed)', $a->getObject()['prefLabel']['nl']);
		$this->assertTrue($b->getObject()['deprecated'], 'B must be retrievable with a deprecated marker set');
		$this->assertSame('https://example.org/vocab/sample-scheme/b', $b->getObject()['uri'], 'B still resolves by uri');
	}//end testLabelChangeUpdatesInPlaceAndRemovedConceptIsDeprecatedNotDeleted()

	/**
	 * A CSV value-list import creates the scheme + concepts and resolves the
	 * `broaderUri` column into the same bidirectional relation shape as JSON-LD.
	 *
	 * @return void
	 */
	public function testImportCsvValueListCreatesSchemeAndConceptsWithBroaderRelation(): void {
		$report = $this->importer->importCsvValueList(
			csvPath: $this->fixturesDir . '/sample-values.csv',
			schemeMeta: [
				'uri' => 'https://example.org/vocab/csv-scheme',
				'title' => 'CSV Test Scheme',
				'publisher' => 'OpenRegister Test Fixtures',
				'version' => '1.0',
				'source' => 'https://example.org/vocab/csv-scheme/source',
			]
		);

		$this->assertSame('https://example.org/vocab/csv-scheme', $report['scheme']);
		$this->assertSame(2, $report['created']);
		$this->assertSame(0, $report['updated']);
		$this->assertSame(0, $report['unchanged']);

		$concepts = $this->conceptsByUri();
		$x = $concepts['https://example.org/vocab/csv-scheme/x'];
		$y = $concepts['https://example.org/vocab/csv-scheme/y'];

		$this->assertSame('Waarde X', $x->getObject()['prefLabel']['nl']);
		$this->assertSame([$y->getUuid()], $x->getObject()['narrower']);
		$this->assertSame([$x->getUuid()], $y->getObject()['broader']);
	}//end testImportCsvValueListCreatesSchemeAndConceptsWithBroaderRelation()

	/**
	 * EVERY read must name its scope where ObjectService actually looks.
	 *
	 * `prepareFindAllConfig()` reads `$config['filters']['register']` and
	 * `$config['filters']['schema']`, and no other key. A pair at the TOP level
	 * of the config is therefore read by nothing: `setRegister()`/`setSchema()`
	 * are never called and `findAll()` hands the handler
	 * `$this->currentRegister` / `$this->currentSchema` — leftover shared state
	 * from whatever ran last on the same service instance.
	 *
	 * This importer used the top-level shape at both of its read sites. It was
	 * the second of the three outliers openregister#3408 found while diagnosing
	 * the flow migration, and was reported there rather than changed because it
	 * was not in that defect's path and needed its own test. This is that test.
	 *
	 * It asserts the ARGUMENTS, not the result: the fake used to answer from
	 * `$config['schema']`, so it replied correctly to a question the real
	 * service is never asked, and every assertion about the returned objects
	 * passed with the scope wired to nothing.
	 *
	 * @return void
	 */
	public function testEveryReadNamesItsScopeWhereObjectServiceReadsIt(): void {
		$this->importFixture('sample-scheme.jsonld');
		$this->importFixture('sample-scheme-updated.jsonld');

		// A recording that stayed empty would satisfy every loop below, so the
		// count is asserted first: this import performs identity lookups and a
		// deprecation sweep, so there is no version of it that reads nothing.
		$this->assertGreaterThan(
			0,
			count($this->findAllCalls),
			'No findAll() call was recorded, so the assertions below prove nothing.'
		);

		foreach ($this->findAllCalls as $index => $config) {
			$this->assertArrayNotHasKey(
				'register',
				$config,
				"findAll() call #$index puts `register` at the top level, where nothing reads it."
			);
			$this->assertArrayNotHasKey(
				'schema',
				$config,
				"findAll() call #$index puts `schema` at the top level, where nothing reads it."
			);
			$this->assertSame(
				VocabularyImportService::REGISTER,
				($config['filters']['register'] ?? null),
				"findAll() call #$index does not scope its register under `filters`."
			);
			$this->assertContains(
				($config['filters']['schema'] ?? null),
				[VocabularyImportService::SCHEMA_SCHEME, VocabularyImportService::SCHEMA_CONCEPT],
				"findAll() call #$index does not scope its schema under `filters`."
			);
		}
	}//end testEveryReadNamesItsScopeWhereObjectServiceReadsIt()

	/**
	 * The deprecation sweep's FIRST page must be scoped, not just the later ones.
	 *
	 * `deprecateMissing()` pages through a scheme's concepts and writes a
	 * `deprecated` flag as it goes. Its `saveObject()` names register and schema,
	 * so under the old shape the write anchored the shared service on exactly the
	 * pair the read wanted — and every page AFTER the first inherited a correct
	 * context by accident. Only page one read whatever an unrelated caller had
	 * left behind.
	 *
	 * That is what made the defect invisible: the loop appeared to work. This
	 * pins the first read of the sweep specifically.
	 *
	 * @return void
	 */
	public function testTheDeprecationSweepScopesItsFirstPageAndNotOnlyThePagesAfterAWrite(): void {
		$this->importFixture('sample-scheme.jsonld');

		// The re-import is the run that deprecates the concept absent from the
		// updated source, so its sweep is the one to inspect.
		$this->findAllCalls = [];
		$report = $this->importFixture('sample-scheme-updated.jsonld');
		$this->assertSame(1, $report['deprecated'], 'Fixture must still exercise the deprecation sweep.');

		$conceptReads = array_values(
			array_filter(
				$this->findAllCalls,
				static fn (array $config): bool => (($config['filters']['schema'] ?? null)
					=== VocabularyImportService::SCHEMA_CONCEPT)
					&& isset($config['filters']['inScheme']) === true
			)
		);

		$this->assertNotEmpty($conceptReads, 'The deprecation sweep performed no scoped concept read.');
		$this->assertSame(
			VocabularyImportService::REGISTER,
			($conceptReads[0]['filters']['register'] ?? null),
			'The sweep\'s FIRST page must carry its own scope; it cannot borrow one from a later write.'
		);
		$this->assertSame(0, ($conceptReads[0]['offset'] ?? 0), 'The first recorded sweep read must be page one.');
	}//end testTheDeprecationSweepScopesItsFirstPageAndNotOnlyThePagesAfterAWrite()

	// -------------------------------------------------------------------
	// Fixture + fake-store helpers.
	// -------------------------------------------------------------------

	/**
	 * @param string $filename Fixture filename under tests/Fixtures/vocabulary.
	 *
	 * @return array{scheme: string, created: int, updated: int, unchanged: int, deprecated: int}
	 */
	private function importFixture(string $filename): array {
		$path = $this->fixturesDir . '/' . $filename;
		$this->assertFileExists($path);

		$jsonLd = json_decode((string)file_get_contents($path), true);
		$this->assertIsArray($jsonLd);

		return $this->importer->importJsonLd(jsonLd: $jsonLd);
	}//end importFixture()

	/**
	 * @return array<string, ObjectEntity> uri => ObjectEntity for every stored concept.
	 */
	private function conceptsByUri(): array {
		$out = [];
		foreach (($this->store[VocabularyImportService::SCHEMA_CONCEPT] ?? []) as $entity) {
			$out[$entity->getObject()['uri']] = $entity;
		}

		return $out;
	}//end conceptsByUri()

	/**
	 * @param array<string, mixed> $config findAll() config array.
	 *
	 * @return array<int, ObjectEntity>
	 */
	private function fakeFindAll(array $config): array {
		// A FAKE THAT ANSWERS ANY QUESTION CANNOT REPORT A WRONG ONE.
		//
		// This used to read `$config['schema']` — a key `ObjectService` never
		// looks at. `prepareFindAllConfig()` reads `$config['filters']['register']`
		// and `$config['filters']['schema']` and nothing else, so the fake was
		// answering a question the real service is not asked, and the service's
		// answer came from whatever register/schema the previous CALLER had left
		// on it. Every test here passed either way, which is why the defect
		// survived (openregister#3408).
		//
		// The fake now reads the scope from exactly where the service reads it,
		// and refuses the read outright when the scope is absent — so a caller
		// that goes back to the top-level shape fails here rather than being
		// quietly served.
		$this->findAllCalls[] = $config;

		$register = ($config['filters']['register'] ?? null);
		$schema = ($config['filters']['schema'] ?? null);
		if ($register === null || $schema === null) {
			throw new \RuntimeException(
				'findAll() was called without filters.register / filters.schema; '
				.'ObjectService reads the scope from nowhere else, so this read '
				.'would have run on whatever context the last write left behind.'
			);
		}

		$schema = (string)$schema;
		// `register` and `schema` are reserved query context, not object fields.
		// MagicSearchHandler::getReservedParams() strips them before filtering;
		// leaving them in here would match every stored row against a field that
		// does not exist and return nothing.
		$filters = ($config['filters'] ?? []);
		unset($filters['register'], $filters['schema']);
		$limit = ($config['limit'] ?? null);
		$offset = ($config['offset'] ?? 0);

		$matches = [];
		foreach (($this->store[$schema] ?? []) as $entity) {
			$data = $entity->getObject();
			$ok = true;
			foreach ($filters as $key => $value) {
				if (($data[$key] ?? null) !== $value) {
					$ok = false;
					break;
				}
			}

			if ($ok === true) {
				$matches[] = $entity;
			}
		}

		if ($offset > 0) {
			$matches = array_slice($matches, (int)$offset);
		}

		if ($limit !== null) {
			$matches = array_slice($matches, 0, (int)$limit);
		}

		return $matches;
	}//end fakeFindAll()

	/**
	 * @param string $uuid Object uuid.
	 * @param string $schema Schema slug.
	 *
	 * @return ObjectEntity|null
	 */
	private function fakeFind(string $uuid, string $schema): ?ObjectEntity {
		return ($this->store[$schema][$uuid] ?? null);
	}//end fakeFind()

	/**
	 * @param array<string, mixed>|ObjectEntity $object The object payload.
	 * @param string $schema Schema slug.
	 * @param string|null $uuid Existing uuid, or null to create.
	 *
	 * @return ObjectEntity
	 */
	private function fakeSaveObject($object, string $schema, ?string $uuid): ObjectEntity {
		$data = (is_array($object) === true ? $object : $object->getObject());

		if ($uuid === null) {
			$uuid = 'uuid-' . (++$this->sequence);
		}

		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($data);

		$this->store[$schema][$uuid] = $entity;

		return $entity;
	}//end fakeSaveObject()
}//end class
