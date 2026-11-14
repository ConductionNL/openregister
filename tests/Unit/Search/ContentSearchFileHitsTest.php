<?php

/**
 * Unit tests for the file arm of the unified search provider.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Search
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/content-search-index/specs/unified-search-provider/spec.md#requirement-the-provider-searches-file-content-beside-object-data
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

namespace OCA\OpenRegister\Tests\Unit\Search;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaCatalog;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Files\Db\FileMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ChunkMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\KeywordChunkSearch;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Search\FileHitSearch;
use OCA\OpenRegister\Search\ObjectsProvider;
use OCA\OpenRegister\Search\ObjectsProviderOutput;
use OCA\OpenRegister\Search\SearchScopeCatalog;
use OCA\OpenRegister\Service\Search\ObjectSearchResultFormatterInterface;
use OCP\IL10N;
use OCP\IUser;
use OCP\Search\IFilter;
use OCP\Search\ISearchQuery;
use OCP\Search\SearchResultEntry;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * A word inside an attached file is found as a hit of kind `file` that links to its object.
 *
 * @covers \OCA\OpenRegister\Search\FileHitSearch
 * @covers \OCA\OpenRegister\Search\ObjectsProvider
 * @uses   \OCA\OpenRegister\Search\ObjectsProviderOutput
 * @uses   \OCA\OpenRegister\Search\SearchScopes
 * @uses   \OCA\OpenRegister\Search\SearchScopeCatalog
 * @uses   \OCA\OpenRegister\Db\SchemaCatalog
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Kernel\Contracts\ListQuery
 * @uses   \OCA\OpenRegister\Kernel\Contracts\KeywordChunkSearch
 */
final class ContentSearchFileHitsTest extends TestCase {

	/**
	 * The chunk searches the file arm asked for.
	 *
	 * @var list<KeywordChunkSearch>
	 */
	private array $chunkSearches = [];

	/**
	 * The object-arm queries the provider sent.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $objectQueries = [];

	/**
	 * The object with this uuid in this schema and register.
	 *
	 * @param string $uuid     The uuid.
	 * @param int    $schema   The schema id.
	 * @param int    $register The register id.
	 *
	 * @return ObjectEntity The object.
	 */
	private static function object(string $uuid, int $schema=5, int $register=1): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setSchema((string) $schema);
		$object->setRegister((string) $register);
		$object->setName('Aanslag '.$uuid);
		$object->setObject(['title' => 'Aanslag '.$uuid]);

		return $object;
	}//end object()

	/**
	 * The provider over these chunk hits, file owners and readable objects.
	 *
	 * @param list<array<string, mixed>>  $chunks      The chunk hits the chunk table answers.
	 * @param array<int, string>          $owners      File id => owning object uuid.
	 * @param array<string, ObjectEntity> $readable    Uuid => object the caller may read.
	 * @param list<ObjectEntity>          $objectHits  What the object arm answers.
	 * @param bool                        $withFileArm Whether the file arm is wired.
	 *
	 * @return ObjectsProvider The provider.
	 */
	private function provider(array $chunks, array $owners, array $readable, array $objectHits=[], bool $withFileArm=true): ObjectsProvider {
		$objectService = $this->createMock(ObjectOperationsInterface::class);
		$objectService->method('searchObjectsPaginated')->willReturnCallback(
			function (array $query) use ($objectHits): array {
				$this->objectQueries[] = $query;
				return ['results' => $objectHits, 'total' => count($objectHits)];
			}
		);

		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('findNonSearchableIds')->willReturn([]);
		$schemas->method('findSearchableIds')->willReturn([5, 6]);
		$schemas->method('findAll')->willReturn([]);
		$catalog = new SchemaCatalog($this->createMock(RegisterMapper::class), $schemas, $this->createMock(MagicMapperInterface::class));

		$formatter = $this->createMock(ObjectSearchResultFormatterInterface::class);
		$formatter->method('format')->willReturnCallback(
			static fn (array|ObjectEntity $result): SearchResultEntry => new SearchResultEntry(
				'',
				(string) $result->getName(),
				'subline',
				'/apps/dossiq/zaken/'.$result->getUuid(),
				'icon-object'
			)
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$logger = new NullLogger();

		$chunkMapper = $this->createMock(ChunkMapperInterface::class);
		$chunkMapper->method('searchByKeyword')->willReturnCallback(
			function (KeywordChunkSearch $search) use ($chunks): array {
				$this->chunkSearches[] = $search;
				return $chunks;
			}
		);
		$files = $this->createMock(FileMapperInterface::class);
		$files->method('findOwningObjectUuid')->willReturnCallback(static fn (int $fileId): ?string => ($owners[$fileId] ?? null));
		$files->method('getFile')->willReturnCallback(static fn (int $fileId): array => ['fileid' => $fileId, 'name' => 'aanslag-'.$fileId.'.txt']);
		$objects = $this->createMock(MagicMapperInterface::class);
		$objects->method('find')->willReturnCallback(
			static function (string|int $identifier) use ($readable): ObjectEntity {
				if (isset($readable[$identifier]) === false) {
					throw new RuntimeException('not readable');
				}

				return $readable[$identifier];
			}
		);

		$fileArm = null;
		if ($withFileArm === true) {
			$fileArm = new FileHitSearch($chunkMapper, $files, $objects, $logger);
		}

		return new ObjectsProvider(
			$objectService,
			$catalog,
			new ObjectsProviderOutput($l10n, $formatter, $logger),
			new SearchScopeCatalog(catalog: $catalog, logger: $logger),
			$fileArm
		);
	}//end provider()

	/**
	 * A query with these filters.
	 *
	 * @param array<string, string> $filters The filters.
	 * @param mixed                 $cursor  The paging cursor.
	 *
	 * @return ISearchQuery The query.
	 */
	private function query(array $filters, mixed $cursor=null): ISearchQuery {
		$query = $this->createMock(ISearchQuery::class);
		$query->method('getFilter')->willReturnCallback(
			function (string $name) use ($filters): ?IFilter {
				if (array_key_exists($name, $filters) === false) {
					return null;
				}

				$filter = $this->createMock(IFilter::class);
				$filter->method('get')->willReturn($filters[$name]);
				return $filter;
			}
		);
		$query->method('getLimit')->willReturn(25);
		$query->method('getCursor')->willReturn($cursor);

		return $query;
	}//end query()

	/**
	 * The serialised entries of a search.
	 *
	 * @param ObjectsProvider       $provider The provider.
	 * @param array<string, string> $filters  The filters.
	 * @param mixed                 $cursor   The paging cursor.
	 *
	 * @return list<array<string, mixed>> The entries.
	 */
	private function entries(ObjectsProvider $provider, array $filters, mixed $cursor=null): array {
		$result = $provider->search($this->createMock(IUser::class), $this->query(filters: $filters, cursor: $cursor))->jsonSerialize();

		return array_map(static fn (SearchResultEntry $entry): array => $entry->jsonSerialize(), $result['entries']);
	}//end entries()

	/**
	 * The chunk hit of a file.
	 *
	 * @param int    $fileId The file id.
	 * @param string $text   The chunk text.
	 *
	 * @return array<string, mixed> The hit.
	 */
	private static function chunk(int $fileId, string $text): array {
		return ['entity_type' => 'file', 'entity_id' => (string) $fileId, 'score' => 1.0, 'chunk_text' => $text, 'chunk_index' => 0, 'metadata' => []];
	}//end chunk()

	public function testAWordOnlyInsideAnAttachedFileIsOneFileHitLinkingToTheObject(): void {
		$text     = 'De '.str_repeat('aanslag ', 12).'voor de waterschapsheffing van dit jaar is verzonden.';
		$provider = $this->provider(
			chunks: [self::chunk(fileId: 70, text: $text), self::chunk(fileId: 70, text: 'tweede chunk waterschapsheffing')],
			owners: [70 => 'zaak-1'],
			readable: ['zaak-1' => self::object(uuid: 'zaak-1')]
		);

		$entries = $this->entries(provider: $provider, filters: ['term' => 'waterschapsheffing']);

		$this->assertCount(1, $entries);
		$this->assertSame('aanslag-70.txt', $entries[0]['title']);
		$this->assertSame('/apps/dossiq/zaken/zaak-1', $entries[0]['resourceUrl']);
		$this->assertStringContainsString('waterschapsheffing van dit jaar', $entries[0]['subline']);
		$this->assertStringEndsWith('Aanslag zaak-1', $entries[0]['subline']);
		$this->assertSame(
			['kind' => 'file', 'backend' => 'database', 'fileId' => '70', 'fileName' => 'aanslag-70.txt'],
			self::sorted($entries[0]['attributes'], ['kind', 'backend', 'fileId', 'fileName'])
		);

		// The object arm no longer widens to file text: the file arm answers it, once.
		$this->assertArrayNotHasKey('_content_search', $this->objectQueries[0]);
		$this->assertCount(1, $this->chunkSearches);
		$this->assertSame(25, $this->chunkSearches[0]->limit);
		$this->assertSame('file', $this->chunkSearches[0]->sourceType());
		$this->assertTrue($this->chunkSearches[0]->allowUnrankedFallback);
	}//end testAWordOnlyInsideAnAttachedFileIsOneFileHitLinkingToTheObject()

	public function testAnObjectHitIsMarkedAsAnObjectBesideTheFileHit(): void {
		$provider = $this->provider(
			chunks: [self::chunk(fileId: 70, text: 'dijkgraaf')],
			owners: [70 => 'zaak-1'],
			readable: ['zaak-1' => self::object(uuid: 'zaak-1')],
			objectHits: [self::object(uuid: 'zaak-2')]
		);

		$entries = $this->entries(provider: $provider, filters: ['term' => 'dijkgraaf']);

		$this->assertSame(['object', 'file'], array_map(static fn (array $entry): string => $entry['attributes']['kind'], $entries));
		$this->assertSame(['database', 'database'], array_map(static fn (array $entry): string => $entry['attributes']['backend'], $entries));
	}//end testAnObjectHitIsMarkedAsAnObjectBesideTheFileHit()

	public function testAFileOfAnUnreadableOrUnsearchableObjectIsNotAHit(): void {
		$provider = $this->provider(
			chunks: [self::chunk(fileId: 70, text: 'geheim'), self::chunk(fileId: 71, text: 'geheim'), self::chunk(fileId: 72, text: 'geheim')],
			owners: [70 => 'zaak-1', 71 => 'zaak-9'],
			readable: ['zaak-9' => self::object(uuid: 'zaak-9', schema: 8)]
		);

		$this->assertSame([], $this->entries(provider: $provider, filters: ['term' => 'geheim']));
	}//end testAFileOfAnUnreadableOrUnsearchableObjectIsNotAHit()

	public function testTheFilesScopeKeepsOnlyFileHitsAndAPlaceScopeNarrowsThem(): void {
		$provider = $this->provider(
			chunks: [self::chunk(fileId: 70, text: 'heffing'), self::chunk(fileId: 71, text: 'heffing')],
			owners: [70 => 'zaak-1', 71 => 'zaak-2'],
			readable: ['zaak-1' => self::object(uuid: 'zaak-1', schema: 5), 'zaak-2' => self::object(uuid: 'zaak-2', schema: 6, register: 2)],
			objectHits: [self::object(uuid: 'zaak-3')]
		);

		$onlyFiles = $this->entries(provider: $provider, filters: ['term' => 'heffing', 'scopes' => 'files']);
		$this->assertSame(['aanslag-70.txt', 'aanslag-71.txt'], array_column($onlyFiles, 'title'));
		$this->assertSame([], $this->objectQueries);

		$inRegister = $this->entries(provider: $provider, filters: ['term' => 'heffing', 'scopes' => 'files', 'register' => '2']);
		$this->assertSame(['aanslag-71.txt'], array_column($inRegister, 'title'));
	}//end testTheFilesScopeKeepsOnlyFileHitsAndAPlaceScopeNarrowsThem()

	public function testFileHitsRideOnTheFirstPageOnly(): void {
		$provider = $this->provider(
			chunks: [self::chunk(fileId: 70, text: 'heffing')],
			owners: [70 => 'zaak-1'],
			readable: ['zaak-1' => self::object(uuid: 'zaak-1')]
		);

		$this->assertSame([], $this->entries(provider: $provider, filters: ['term' => 'heffing'], cursor: 25));
		$this->assertSame([], $this->chunkSearches);
		$this->assertSame([], $this->entries(provider: $provider, filters: ['since' => '2026-01-01']));
		$this->assertSame([], $this->chunkSearches);
	}//end testFileHitsRideOnTheFirstPageOnly()

	public function testWithoutTheFileArmTheOlderWideningStays(): void {
		$provider = $this->provider(chunks: [], owners: [], readable: [], objectHits: [self::object(uuid: 'zaak-1')], withFileArm: false);

		$entries = $this->entries(provider: $provider, filters: ['term' => 'heffing']);

		$this->assertTrue($this->objectQueries[0]['_content_search']);
		$this->assertArrayNotHasKey('kind', $entries[0]['attributes']);
	}//end testWithoutTheFileArmTheOlderWideningStays()

	public function testTheExcerptIsCutAroundTheTerm(): void {
		$long = str_repeat('a ', 50).'dijkgraaf'.str_repeat(' b', 50);

		$expected = '…'.trim(str_repeat('a ', 30)).' dijkgraaf '.trim(str_repeat('b ', 30)).'…';
		$this->assertSame($expected, FileHitSearch::excerpt(text: $long, term: 'Dijkgraaf'));
		$this->assertSame('kort stuk', FileHitSearch::excerpt(text: "kort\n stuk", term: 'ontbreekt'));
		$this->assertSame(str_repeat('x', 120).'…', FileHitSearch::excerpt(text: str_repeat('x', 200), term: 'y'));
	}//end testTheExcerptIsCutAroundTheTerm()

	/**
	 * The named keys of an attribute map, in that order.
	 *
	 * @param array<string, string> $attributes The attributes.
	 * @param list<string>          $keys       The keys.
	 *
	 * @return array<string, string|null> The values.
	 */
	private static function sorted(array $attributes, array $keys): array {
		$picked = [];
		foreach ($keys as $key) {
			$picked[$key] = ($attributes[$key] ?? null);
		}

		return $picked;
	}//end sorted()
}//end class
