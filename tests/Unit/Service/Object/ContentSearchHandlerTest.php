<?php

/**
 * ContentSearchHandlerTest
 *
 * Unit tests for the opt-in `_content_search` chunk fan-out handler.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/expose-content-search-in-object-service/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\ChunkMapper;
use OCA\OpenRegister\Db\FileMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Object\ContentSearchHandler;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Covers ZKN-CONTENT-001/-002/-003:
 * - default-off byte-identity (no chunk query when `_search`/limit absent).
 * - chunk hits resolved to owning objects (both source_type='object' and 'file').
 * - dedup on object id, register/schema scope filtering, silent-skip on failure.
 */
class ContentSearchHandlerTest extends TestCase {
	private ChunkMapper&MockObject $chunkMapper;
	private FileMapper&MockObject $fileMapper;
	private MagicMapper&MockObject $objectMapper;
	private LoggerInterface&MockObject $logger;
	private ContentSearchHandler $handler;

	protected function setUp(): void {
		parent::setUp();

		$this->chunkMapper = $this->createMock(ChunkMapper::class);
		$this->fileMapper = $this->createMock(FileMapper::class);
		$this->objectMapper = $this->createMock(MagicMapper::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->handler = new ContentSearchHandler(
			$this->chunkMapper,
			$this->fileMapper,
			$this->objectMapper,
			$this->logger
		);
	}//end setUp()

	/**
	 * Build an ObjectEntity test double with id/uuid/register/schema set.
	 *
	 * UUID is derived from the numeric id ("obj-uuid-<id>") so tests can dedup
	 * on UUID (production code keys dedup on getUuid() — see ContentSearchHandler).
	 */
	private function makeObject(int $id, string $register = '1', string $schema = '1'): ObjectEntity {
		$object = new ObjectEntity();
		$object->setId($id);
		$object->setUuid('obj-uuid-' . $id);
		$object->setRegister($register);
		$object->setSchema($schema);

		return $object;
	}//end makeObject()

	// =========================================================================
	// No-op paths (default-off byte-identity, ZKN-CONTENT-001)
	// =========================================================================

	public function testNoSearchTermReturnsResultsAndTotalUnchanged(): void {
		$this->chunkMapper->expects($this->never())->method('searchByKeyword');

		$result = $this->handler->augmentWithChunkMatches(
			query: [],
			results: [],
			total: 0,
			limit: 20
		);

		$this->assertSame([], $result['results']);
		$this->assertSame(0, $result['total']);
	}//end testNoSearchTermReturnsResultsAndTotalUnchanged()

	public function testZeroLimitSkipsChunkFanOut(): void {
		$this->chunkMapper->expects($this->never())->method('searchByKeyword');

		$result = $this->handler->augmentWithChunkMatches(
			query: ['_search' => 'quarterly report'],
			results: [],
			total: 0,
			limit: 0
		);

		$this->assertSame([], $result['results']);
		$this->assertSame(0, $result['total']);
	}//end testZeroLimitSkipsChunkFanOut()

	public function testEmptyChunkHitsReturnsResultsAndTotalUnchanged(): void {
		$this->chunkMapper->method('searchByKeyword')->willReturn([]);
		$this->objectMapper->expects($this->never())->method('find');

		$existing = [$this->makeObject(1)];
		$result = $this->handler->augmentWithChunkMatches(
			query: ['_search' => 'quarterly report'],
			results: $existing,
			total: 1,
			limit: 20
		);

		$this->assertSame($existing, $result['results']);
		$this->assertSame(1, $result['total']);
	}//end testEmptyChunkHitsReturnsResultsAndTotalUnchanged()

	// =========================================================================
	// Chunk-hit -> owning-object resolution (ZKN-CONTENT-002)
	// =========================================================================

	public function testObjectSourceTypeResolvesDirectlyByEntityId(): void {
		$this->chunkMapper->method('searchByKeyword')->willReturn(
			[
				['entity_type' => 'object', 'entity_id' => '42', 'score' => 0.8, 'chunk_text' => 'x', 'chunk_index' => 0, 'metadata' => []],
			]
		);

		$matchedObject = $this->makeObject(42);
		$this->objectMapper->expects($this->once())
			->method('find')
			->with(42)
			->willReturn($matchedObject);
		$this->fileMapper->expects($this->never())->method('findOwningObjectUuid');

		$result = $this->handler->augmentWithChunkMatches(
			query: ['_search' => 'quarterly report'],
			results: [],
			total: 0,
			limit: 20
		);

		$this->assertCount(1, $result['results']);
		$this->assertSame($matchedObject, $result['results'][0]);
		$this->assertSame(1, $result['total']);
	}//end testObjectSourceTypeResolvesDirectlyByEntityId()

	public function testFileSourceTypeResolvesViaFileMapperJoin(): void {
		$this->chunkMapper->method('searchByKeyword')->willReturn(
			[
				['entity_type' => 'file', 'entity_id' => '100', 'score' => 0.8, 'chunk_text' => 'x', 'chunk_index' => 0, 'metadata' => []],
			]
		);

		$this->fileMapper->expects($this->once())
			->method('findOwningObjectUuid')
			->with(100)
			->willReturn('obj-uuid-7');

		$matchedObject = $this->makeObject(7);
		$this->objectMapper->expects($this->once())
			->method('find')
			->with('obj-uuid-7')
			->willReturn($matchedObject);

		$result = $this->handler->augmentWithChunkMatches(
			query: ['_search' => 'quarterly report'],
			results: [],
			total: 0,
			limit: 20
		);

		$this->assertCount(1, $result['results']);
		$this->assertSame($matchedObject, $result['results'][0]);
	}//end testFileSourceTypeResolvesViaFileMapperJoin()

	public function testFileChunkWithUnresolvableOwningObjectIsSkippedSilently(): void {
		$this->chunkMapper->method('searchByKeyword')->willReturn(
			[
				['entity_type' => 'file', 'entity_id' => '999', 'score' => 0.8, 'chunk_text' => 'x', 'chunk_index' => 0, 'metadata' => []],
			]
		);

		$this->fileMapper->method('findOwningObjectUuid')->willReturn(null);
		$this->objectMapper->expects($this->never())->method('find');

		$result = $this->handler->augmentWithChunkMatches(
			query: ['_search' => 'quarterly report'],
			results: [],
			total: 3,
			limit: 20
		);

		$this->assertSame([], $result['results']);
		// The unresolvable owner is NOT counted. `total` is the number of
		// chunk owners this caller can actually see, so a chunk whose owning
		// object cannot be resolved contributes nothing to it.
		$this->assertSame(3, $result['total']);
	}//end testFileChunkWithUnresolvableOwningObjectIsSkippedSilently()

	public function testResolveExceptionIsCaughtLoggedAndSkipped(): void {
		$this->chunkMapper->method('searchByKeyword')->willReturn(
			[
				['entity_type' => 'object', 'entity_id' => '55', 'score' => 0.8, 'chunk_text' => 'x', 'chunk_index' => 0, 'metadata' => []],
			]
		);

		$this->objectMapper->method('find')->willThrowException(new DoesNotExistException('not found'));
		$this->logger->expects($this->once())->method('debug');

		$result = $this->handler->augmentWithChunkMatches(
			query: ['_search' => 'quarterly report'],
			results: [],
			total: 0,
			limit: 20
		);

		$this->assertSame([], $result['results']);
		// A hit whose resolve THREW is not a hit this caller can see, so it
		// is not counted either. Swallowing the exception must not leave the
		// row behind in the count.
		$this->assertSame(0, $result['total']);
	}//end testResolveExceptionIsCaughtLoggedAndSkipped()

	// =========================================================================
	// Dedup on object id (ZKN-CONTENT-002/-003)
	// =========================================================================

	/**
	 * Tell the overlap probe which of the resolved chunk owners the metadata
	 * arm matches too. The probe is the same query restricted to the candidate
	 * ids, so the mock answers with those objects.
	 *
	 * @param ObjectEntity[] $overlapping The owners the metadata arm also matches.
	 */
	private function metadataArmAlsoMatches(array $overlapping): void {
		$this->objectMapper->method('searchObjectsPaginated')->willReturn(
			['results' => $overlapping, 'total' => count($overlapping)]
		);
	}//end metadataArmAlsoMatches()

	/**
	 * The one plausible disclosure route of content search, closed.
	 *
	 * A chunk is a fragment of a FILE. The text in a file can hold values the
	 * reader is redacted out of on the object, so a chunk hit must be appended
	 * as the owning object and nothing else. If chunk text ever rode along on
	 * the row, the object would stay correctly filtered while the search
	 * result beside it leaked, which is exactly the shape a redaction bug
	 * takes: the guard works and the thing next to it does not.
	 *
	 * @return void
	 */
	public function testAnAppendedRowCarriesNoneOfTheChunksText(): void {
		$secret = 'BSN 000000000 en rekening NL00BANK0000000000';

		$this->chunkMapper->method('searchByKeyword')->willReturn(
			[
				[
					'entity_type' => 'object',
					'entity_id' => '42',
					'score' => 0.8,
					'chunk_text' => 'Bijlage bij de zaak: ' . $secret,
					'text_content' => 'Bijlage bij de zaak: ' . $secret,
					'chunk_index' => 0,
					'metadata' => ['filename' => 'bijlage.pdf'],
				],
			]
		);

		$this->objectMapper->method('find')->with(42)->willReturn($this->makeObject(42));

		$result = $this->handler->augmentWithChunkMatches(
			query: ['_search' => 'rekening'],
			results: [],
			total: 0,
			limit: 20
		);

		$this->assertCount(1, $result['results']);
		$row = $result['results'][0];
		$this->assertInstanceOf(ObjectEntity::class, $row);

		$serialised = json_encode($row->jsonSerialize());
		$this->assertStringNotContainsString(
			$secret,
			(string)$serialised,
			'file text must never ride along on the row a chunk hit produced'
		);
		$this->assertStringNotContainsString('bijlage.pdf', (string)$serialised);
	}//end testAnAppendedRowCarriesNoneOfTheChunksText()

	public function testObjectAlreadyMatchedByMetadataArmIsNotDuplicated(): void {
		$existing = $this->makeObject(42);

		$this->chunkMapper->method('searchByKeyword')->willReturn(
			[
				['entity_type' => 'object', 'entity_id' => '42', 'score' => 0.8, 'chunk_text' => 'x', 'chunk_index' => 0, 'metadata' => []],
			]
		);
		// The chunk resolves to the same object that the metadata arm already
		// returned, and the overlap probe confirms the metadata arm matches it.
		$this->objectMapper->method('find')->willReturn($existing);
		$this->metadataArmAlsoMatches([$existing]);

		$result = $this->handler->augmentWithChunkMatches(
			query: ['_search' => 'quarterly report'],
			results: [$existing],
			total: 1,
			limit: 20
		);

		$this->assertCount(1, $result['results']);
		// One object, counted once. It is already in the metadata arm's
		// total, so the chunk arm must not add it a second time — an object
		// that matches BOTH ways is still one result.
		$this->assertSame(1, $result['total']);
	}//end testObjectAlreadyMatchedByMetadataArmIsNotDuplicated()


	/**
	 * WOO-577: the overlap used to be computed against THIS PAGE's metadata
	 * rows. An owner the metadata arm serves on page 2 was therefore counted
	 * as chunk-only on page 1 (total too high by one) and dropped on page 2
	 * (total back down) — measured as total 4, 5, 4, 5 across pages on the
	 * NC 32 rig. The probe makes the overlap a property of the query, so a
	 * page that does not hold the row still leaves it out of the chunk arm.
	 */
	public function testAnOwnerTheMetadataArmMatchesOnAnotherPageIsNotCountedAsChunkOnly(): void {
		$sharedOwner = $this->makeObject(42);
		$chunkOnly = $this->makeObject(43);

		$this->chunkMapper->method('searchByKeyword')->willReturn(
			[
				['entity_type' => 'object', 'entity_id' => '42', 'score' => 0.9, 'chunk_text' => 'x', 'chunk_index' => 0, 'metadata' => []],
				['entity_type' => 'object', 'entity_id' => '43', 'score' => 0.8, 'chunk_text' => 'y', 'chunk_index' => 0, 'metadata' => []],
			]
		);
		$this->objectMapper->method('find')->willReturnCallback(
			fn (int $id): ObjectEntity => $this->makeObject($id)
		);
		$this->metadataArmAlsoMatches([$sharedOwner]);

		// Page 1 of the metadata arm holds a DIFFERENT row; 42 is on page 2.
		$pageOne = $this->handler->augmentWithChunkMatches(
			query: ['_search' => 'q'],
			results: [$this->makeObject(1)],
			total: 2,
			limit: 1,
			offset: 0
		);
		$this->assertSame(3, $pageOne['total'], 'metadata 2 + one chunk-only owner; the shared owner is not counted twice');
		$this->assertCount(1, $pageOne['results']);

		// Page 2 holds 42 itself. Same total, and 42 is not appended again.
		$pageTwo = $this->handler->augmentWithChunkMatches(
			query: ['_search' => 'q'],
			results: [$sharedOwner],
			total: 2,
			limit: 1,
			offset: 1
		);
		$this->assertSame(3, $pageTwo['total']);
		$this->assertSame(['obj-uuid-42'], array_map(static fn (ObjectEntity $o): string => $o->getUuid(), $pageTwo['results']));

		// Page 3: the metadata arm is exhausted, the chunk arm starts at 0 and
		// holds only the chunk-only owner.
		$pageThree = $this->handler->augmentWithChunkMatches(
			query: ['_search' => 'q'],
			results: [],
			total: 2,
			limit: 1,
			offset: 2
		);
		$this->assertSame(3, $pageThree['total']);
		$this->assertSame([$chunkOnly->getUuid()], array_map(static fn (ObjectEntity $o): string => $o->getUuid(), $pageThree['results']));
	}//end testAnOwnerTheMetadataArmMatchesOnAnotherPageIsNotCountedAsChunkOnly()


	/**
	 * WOO-577: without an offset into the chunk arm every page past the
	 * metadata rows re-served the same chunk-only rows, so a client walking
	 * `_page` never reached the end (page 4 onward returned the same object
	 * indefinitely on the rig). The chunk arm is the tail of one combined
	 * list: it starts where the metadata arm's `total` ends and is sliced by
	 * the remaining offset.
	 */
	public function testTheChunkArmIsPagedByTheOffsetPastTheMetadataArmAndEnds(): void {
		$this->chunkMapper->method('searchByKeyword')->willReturn(
			[
				['entity_type' => 'object', 'entity_id' => '101', 'score' => 0.9, 'chunk_text' => 'a', 'chunk_index' => 0, 'metadata' => []],
				['entity_type' => 'object', 'entity_id' => '102', 'score' => 0.8, 'chunk_text' => 'b', 'chunk_index' => 0, 'metadata' => []],
				['entity_type' => 'object', 'entity_id' => '103', 'score' => 0.7, 'chunk_text' => 'c', 'chunk_index' => 0, 'metadata' => []],
			]
		);
		$this->objectMapper->method('find')->willReturnCallback(
			fn (int $id): ObjectEntity => $this->makeObject($id)
		);

		$uuids = static fn (array $page): array => array_map(
			static fn (ObjectEntity $o): string => $o->getUuid(),
			$page['results']
		);

		// Metadata arm: 3 rows. _limit=2. Page 1 is metadata only.
		$page = fn (array $results, int $offset): array => $this->handler->augmentWithChunkMatches(
			query: ['_search' => 'q'],
			results: $results,
			total: 3,
			limit: 2,
			offset: $offset
		);

		$pageOne = $page([$this->makeObject(1), $this->makeObject(2)], 0);
		$this->assertSame([], array_slice($uuids($pageOne), 2), 'no room left on a full metadata page');
		$this->assertSame(6, $pageOne['total']);

		// Page 2: the last metadata row plus the FIRST chunk-only row.
		$pageTwo = $page([$this->makeObject(3)], 2);
		$this->assertSame(['obj-uuid-3', 'obj-uuid-101'], $uuids($pageTwo));
		$this->assertSame(6, $pageTwo['total']);

		// Page 3: offset 4 is one past the metadata arm (3), so the chunk arm
		// continues at its own position 1 — not at 0 again.
		$pageThree = $page([], 4);
		$this->assertSame(['obj-uuid-102', 'obj-uuid-103'], $uuids($pageThree));
		$this->assertSame(6, $pageThree['total']);

		// Page 4: past the end of both arms. Empty, and the total still holds.
		$pageFour = $page([], 6);
		$this->assertSame([], $uuids($pageFour));
		$this->assertSame(6, $pageFour['total']);
	}//end testTheChunkArmIsPagedByTheOffsetPastTheMetadataArmAndEnds()


	/**
	 * The probe must be the caller's own query — same term, same guards —
	 * restricted to the resolved candidates and stripped of paging, or its
	 * answer would depend on the page after all. It is aimed at the owners'
	 * own (register, schema) with `_ids`: that single-table path is the one
	 * on which an id restriction is honoured (the multi-schema UNION path
	 * accepts `ids`/`_ids` and applies neither — measured on the rig).
	 */
	public function testTheOverlapProbeIsTheSameQueryRestrictedToTheCandidatesWithoutPaging(): void {
		$this->chunkMapper->method('searchByKeyword')->willReturn(
			[
				['entity_type' => 'object', 'entity_id' => '7', 'score' => 0.9, 'chunk_text' => 'a', 'chunk_index' => 0, 'metadata' => []],
			]
		);
		$this->objectMapper->method('find')->willReturn($this->makeObject(7, '4', '9'));

		$this->objectMapper->expects($this->once())
			->method('searchObjectsPaginated')
			->with(
				$this->callback(
					static function (array $probe): bool {
						return ($probe['_search'] ?? null) === 'q'
							&& ($probe['_register'] ?? null) === 4
							&& ($probe['_schema'] ?? null) === 9
							&& ($probe['_ids'] ?? null) === ['obj-uuid-7']
							&& ($probe['_limit'] ?? null) === 1
							&& ($probe['_offset'] ?? null) === 0
							&& array_key_exists('_schemas', $probe) === false
							&& array_key_exists('_registers', $probe) === false
							&& array_key_exists('_page', $probe) === false
							&& array_key_exists('_content_search', $probe) === false
							&& array_key_exists('_facetable', $probe) === false;
					}
				),
				$this->anything(),
				'org-1',
				false,
				false
			)
			->willReturn(['results' => [], 'total' => 0]);

		$this->handler->augmentWithChunkMatches(
			query: ['_search' => 'q', '_registers' => [4], '_schemas' => [9, 10], '_page' => 3, '_limit' => 5, '_content_search' => true, '_facetable' => true],
			results: [],
			total: 10,
			limit: 5,
			_rbac: false,
			_multitenancy: false,
			offset: 10,
			activeOrgUuid: 'org-1'
		);
	}//end testTheOverlapProbeIsTheSameQueryRestrictedToTheCandidatesWithoutPaging()


	/**
	 * Owners from two tables mean two probes, each restricted to its own
	 * owners; the overlap is the union of what they report.
	 */
	public function testOneProbePerTableTheOwnersLiveIn(): void {
		$this->chunkMapper->method('searchByKeyword')->willReturn(
			[
				['entity_type' => 'object', 'entity_id' => '1', 'score' => 0.9, 'chunk_text' => 'a', 'chunk_index' => 0, 'metadata' => []],
				['entity_type' => 'object', 'entity_id' => '2', 'score' => 0.8, 'chunk_text' => 'b', 'chunk_index' => 0, 'metadata' => []],
				['entity_type' => 'object', 'entity_id' => '3', 'score' => 0.7, 'chunk_text' => 'c', 'chunk_index' => 0, 'metadata' => []],
			]
		);
		$this->objectMapper->method('find')->willReturnCallback(
			fn (int $id): ObjectEntity => $this->makeObject($id, '1', $id === 3 ? '2' : '1')
		);

		$probes = [];
		$this->objectMapper->method('searchObjectsPaginated')->willReturnCallback(
			function (array $searchQuery) use (&$probes): array {
				$probes[] = [$searchQuery['_schema'], $searchQuery['_ids']];
				// Schema 1's probe says owner 1 is a metadata match too; schema 2's says nothing.
				if ($searchQuery['_schema'] === 1) {
					return ['results' => [$this->makeObject(1)], 'total' => 1];
				}

				return ['results' => [], 'total' => 0];
			}
		);

		$result = $this->handler->augmentWithChunkMatches(
			query: ['_search' => 'q'],
			results: [],
			total: 5,
			limit: 10,
			offset: 5
		);

		$this->assertCount(2, $probes);
		$this->assertContains([1, ['obj-uuid-1', 'obj-uuid-2']], $probes);
		$this->assertContains([2, ['obj-uuid-3']], $probes);
		// Owner 1 is metadata-matched: not counted, not appended. 2 and 3 are chunk-only.
		$this->assertSame(7, $result['total']);
		$this->assertSame(['obj-uuid-2', 'obj-uuid-3'], array_map(static fn (ObjectEntity $o): string => $o->getUuid(), $result['results']));
	}//end testOneProbePerTableTheOwnersLiveIn()


	/**
	 * No candidates, no probe: the extra query is only paid when there is
	 * something to disambiguate.
	 */
	public function testNoProbeIsIssuedWithoutResolvedCandidates(): void {
		$this->chunkMapper->method('searchByKeyword')->willReturn([]);
		$this->objectMapper->expects($this->never())->method('searchObjectsPaginated');

		$this->handler->augmentWithChunkMatches(
			query: ['_search' => 'q'],
			results: [],
			total: 0,
			limit: 5
		);
	}//end testNoProbeIsIssuedWithoutResolvedCandidates()

	// =========================================================================
	// Register / schema scope filtering
	// =========================================================================

	public function testObjectOutsideRequestedRegisterIsSkipped(): void {
		$this->chunkMapper->method('searchByKeyword')->willReturn(
			[
				['entity_type' => 'object', 'entity_id' => '42', 'score' => 0.8, 'chunk_text' => 'x', 'chunk_index' => 0, 'metadata' => []],
			]
		);
		$this->objectMapper->method('find')->willReturn($this->makeObject(42, register: '9'));

		$result = $this->handler->augmentWithChunkMatches(
			query: ['_search' => 'quarterly report', '_register' => 5],
			results: [],
			total: 0,
			limit: 20
		);

		$this->assertSame([], $result['results']);
		// Out of the caller's register scope is out of the caller's count.
		$this->assertSame(0, $result['total']);
	}//end testObjectOutsideRequestedRegisterIsSkipped()

	public function testObjectOutsideRequestedSchemasIsSkipped(): void {
		$this->chunkMapper->method('searchByKeyword')->willReturn(
			[
				['entity_type' => 'object', 'entity_id' => '42', 'score' => 0.8, 'chunk_text' => 'x', 'chunk_index' => 0, 'metadata' => []],
			]
		);
		$this->objectMapper->method('find')->willReturn($this->makeObject(42, schema: '99'));

		$result = $this->handler->augmentWithChunkMatches(
			query: ['_search' => 'quarterly report', '_schemas' => [1, 2]],
			results: [],
			total: 0,
			limit: 20
		);

		$this->assertSame([], $result['results']);
		// Out of the caller's schema scope is out of the caller's count.
		$this->assertSame(0, $result['total']);
	}//end testObjectOutsideRequestedSchemasIsSkipped()

	public function testUnscopedQueryMatchesAnyRegisterOrSchema(): void {
		$this->chunkMapper->method('searchByKeyword')->willReturn(
			[
				['entity_type' => 'object', 'entity_id' => '42', 'score' => 0.8, 'chunk_text' => 'x', 'chunk_index' => 0, 'metadata' => []],
			]
		);
		$matched = $this->makeObject(42, register: '9', schema: '77');
		$this->objectMapper->method('find')->willReturn($matched);

		$result = $this->handler->augmentWithChunkMatches(
			query: ['_search' => 'quarterly report'],
			results: [],
			total: 0,
			limit: 20
		);

		$this->assertCount(1, $result['results']);
		$this->assertSame($matched, $result['results'][0]);
	}//end testUnscopedQueryMatchesAnyRegisterOrSchema()

	/**
	 * `_registers` (plural, array) and `_schema` (singular) are supported query
	 * shapes with no test until now. `resolveScope()` reads four keys per
	 * dimension — `@self.register`, `_register`, `register` and the plural
	 * `_registers` — and the two shapes exercised elsewhere in this file are
	 * `_register` and `_schemas`, i.e. one singular and one plural, never the
	 * other diagonal. A caller using the untested pair got a scope assembled by
	 * branches nothing had ever run.
	 *
	 * Asserted as a MATCH rather than a skip, so it fails if either branch stops
	 * contributing its id: a scope that silently resolved to empty would let this
	 * object through for the wrong reason and read identically.
	 */
	public function testPluralRegistersAndSingularSchemaBothNarrowTheScope(): void {
		$this->chunkMapper->method('searchByKeyword')->willReturn(
			[
				['entity_type' => 'object', 'entity_id' => '42', 'score' => 0.8, 'chunk_text' => 'x', 'chunk_index' => 0, 'metadata' => []],
			]
		);
		$matched = $this->makeObject(42, register: '7', schema: '3');
		$this->objectMapper->method('find')->willReturn($matched);

		$result = $this->handler->augmentWithChunkMatches(
			query: ['_search' => 'quarterly report', '_registers' => [7, 8], '_schema' => 3],
			results: [],
			total: 0,
			limit: 20
		);

		$this->assertCount(1, $result['results']);
		$this->assertSame($matched, $result['results'][0]);
		$this->assertSame(1, $result['total']);
	}//end testPluralRegistersAndSingularSchemaBothNarrowTheScope()

	/**
	 * The same pair, narrowing the other way: an object OUTSIDE the plural
	 * register list is skipped. Without this, the test above would pass even if
	 * `_registers` contributed nothing, because an empty scope matches everything.
	 */
	public function testObjectOutsideThePluralRegisterListIsSkipped(): void {
		$this->chunkMapper->method('searchByKeyword')->willReturn(
			[
				['entity_type' => 'object', 'entity_id' => '42', 'score' => 0.8, 'chunk_text' => 'x', 'chunk_index' => 0, 'metadata' => []],
			]
		);
		$this->objectMapper->method('find')->willReturn($this->makeObject(42, register: '99', schema: '3'));

		$result = $this->handler->augmentWithChunkMatches(
			query: ['_search' => 'quarterly report', '_registers' => [7, 8], '_schema' => 3],
			results: [],
			total: 0,
			limit: 20
		);

		$this->assertSame([], $result['results']);
		$this->assertSame(0, $result['total']);
	}//end testObjectOutsideThePluralRegisterListIsSkipped()

	// =========================================================================
	// Page-limit clamping
	// =========================================================================

	public function testAppendedRowsAreClampedToRemainingRoomOnThePage(): void {
		$this->chunkMapper->method('searchByKeyword')->willReturn(
			[
				['entity_type' => 'object', 'entity_id' => '42', 'score' => 0.8, 'chunk_text' => 'x', 'chunk_index' => 0, 'metadata' => []],
			]
		);
		// The hit RESOLVES even though this page has no room for it. That is
		// the change: `total` can only be truthful if visibility is actually
		// checked, and visibility cannot be known without resolving. The cost
		// is bounded by CHUNK_CANDIDATE_LIMIT, which this class already
		// documents as its worst case.
		$this->objectMapper->method('find')->willReturn($this->makeObject(42));

		// limit=1, already 1 metadata-match result -> zero room for chunk-only appends.
		$result = $this->handler->augmentWithChunkMatches(
			query: ['_search' => 'quarterly report'],
			results: [$this->makeObject(1)],
			total: 1,
			limit: 1
		);

		// The page is still clamped: nothing is appended beyond the room.
		$this->assertCount(1, $result['results']);
		// And the total still counts the resolvable owner, so page 2 of the
		// same query reports the same number. Stability now comes from
		// resolving the same candidate set every page, not from counting
		// candidates nobody can see.
		$this->assertSame(2, $result['total']);
	}//end testAppendedRowsAreClampedToRemainingRoomOnThePage()

	// =========================================================================
	// Uses the unranked fallback (ZKN-CONTENT-001 MariaDB scenario)
	// =========================================================================

	public function testAlwaysRequestsUnrankedFallbackFromChunkMapper(): void {
		$this->chunkMapper->expects($this->once())
			->method('searchByKeyword')
			->with('quarterly report', $this->anything(), $this->anything(), true)
			->willReturn([]);

		$this->handler->augmentWithChunkMatches(
			query: ['_search' => 'quarterly report'],
			results: [],
			total: 0,
			limit: 20
		);
	}//end testAlwaysRequestsUnrankedFallbackFromChunkMapper()

	// =========================================================================
	// Pagination-total stability (review discussion on drift)
	// =========================================================================

	/**
	 * Total reported on a page where room=0 (metadata fills the page) is the
	 * SAME as the total reported on a later page where room>0 (metadata
	 * drained, chunk-only rows appended). Without the stable-total fix, the
	 * two would disagree — page 1 = metaTotal, page 3 = metaTotal + appended.
	 *
	 * Both arms use the same chunk-hit set (mock returns the same list); the
	 * caller changes `results`/`limit` to simulate page-1-full vs page-3-open.
	 */
	public function testTotalIsStableAcrossPagesForSameQuery(): void {
		$chunkHits = [
			['entity_type' => 'object', 'entity_id' => '101', 'score' => 0.9, 'chunk_text' => 'a', 'chunk_index' => 0, 'metadata' => []],
			['entity_type' => 'object', 'entity_id' => '102', 'score' => 0.8, 'chunk_text' => 'b', 'chunk_index' => 0, 'metadata' => []],
			['entity_type' => 'object', 'entity_id' => '103', 'score' => 0.7, 'chunk_text' => 'c', 'chunk_index' => 0, 'metadata' => []],
		];
		$this->chunkMapper->method('searchByKeyword')->willReturn($chunkHits);
		$this->objectMapper->method('find')->willReturnCallback(
			fn (int $id): ObjectEntity => $this->makeObject($id)
		);

		// Page 1: metadata filled (10 rows, limit=10) → room=0, no chunks
		// appended, but total still includes the chunk-owner upper bound.
		$metaPage = array_map(fn (int $id): ObjectEntity => $this->makeObject($id), range(1, 10));
		$pageOne = $this->handler->augmentWithChunkMatches(
			query: ['_search' => 'q'],
			results: $metaPage,
			total: 50,
			limit: 10
		);
		$this->assertCount(10, $pageOne['results']);
		$this->assertSame(53, $pageOne['total']);

		// Page 3 (offset=40, limit=10): metadata returned 10 rows, chunks fill
		// remaining rows on this page — total stays 53, unchanged.
		$pageThree = $this->handler->augmentWithChunkMatches(
			query: ['_search' => 'q'],
			results: array_slice($metaPage, 0, 5),
			total: 50,
			limit: 10
		);
		$this->assertSame(53, $pageThree['total']);
	}//end testTotalIsStableAcrossPagesForSameQuery()

	// =========================================================================
	// Array scope and the per-request candidate memo (unified-search chunks)
	// =========================================================================

	/**
	 * The unified-search provider passes its searchable allow-list as
	 * `@self.schema`. `(int)` of a non-empty array is 1 in PHP, so that list
	 * used to scope every hit to schema id 1: the object in schema 3 was
	 * dropped and the object in schema 1 let through. Asserted both ways.
	 */
	public function testAnArraySchemaScopeOnSelfIsHonoured(): void {
		$this->chunkMapper->method('searchByKeyword')->willReturn(
			[
				['entity_type' => 'object', 'entity_id' => '42', 'score' => 0.8, 'chunk_text' => 'x', 'chunk_index' => 0, 'metadata' => []],
				['entity_type' => 'object', 'entity_id' => '43', 'score' => 0.7, 'chunk_text' => 'x', 'chunk_index' => 0, 'metadata' => []],
			]
		);
		$inScope = $this->makeObject(42, schema: '3');
		$this->objectMapper->method('find')->willReturnCallback(
			fn (int|string $identifier) => ((int)$identifier === 42) ? $inScope : $this->makeObject(43, schema: '1')
		);

		$result = $this->handler->augmentWithChunkMatches(
			query: ['_search' => 'dakkapel', '@self' => ['schema' => [2, 3]]],
			results: [],
			total: 0,
			limit: 20
		);

		$this->assertSame([$inScope], $result['results']);
		$this->assertSame(1, $result['total']);
	}//end testAnArraySchemaScopeOnSelfIsHonoured()

	/**
	 * One search over 1,272 schemas reaches this handler 26 times, once per
	 * schema chunk. The chunk-store query and the owner resolves are the
	 * same every time; only the scope differs. They must run once.
	 */
	public function testChunkCandidatesAreFetchedAndResolvedOnceAcrossTheChunksOfOneSearch(): void {
		$this->chunkMapper->expects($this->once())->method('searchByKeyword')->willReturn(
			[
				['entity_type' => 'object', 'entity_id' => '42', 'score' => 0.8, 'chunk_text' => 'x', 'chunk_index' => 0, 'metadata' => []],
			]
		);
		$owner = $this->makeObject(42, schema: '3');
		$this->objectMapper->expects($this->once())->method('find')->willReturn($owner);

		$totals = [];
		foreach ([[1, 2], [3, 4], [5, 6]] as $chunk) {
			$result = $this->handler->augmentWithChunkMatches(
				query: ['_search' => 'dakkapel', '@self' => ['schema' => $chunk]],
				results: [],
				total: 0,
				limit: 20
			);
			$totals[] = $result['total'];
		}

		$this->assertSame([0, 1, 0], $totals, 'the owner is appended by exactly the chunk that holds its schema');
	}//end testChunkCandidatesAreFetchedAndResolvedOnceAcrossTheChunksOfOneSearch()

	/**
	 * The memo is keyed on the term AND the guard flags: a different term is
	 * a different chunk query, and a relaxed guard resolves a different set.
	 */
	public function testTheCandidateMemoIsKeyedOnTermAndGuardFlags(): void {
		$this->chunkMapper->expects($this->exactly(3))->method('searchByKeyword')->willReturn([]);

		$this->handler->augmentWithChunkMatches(query: ['_search' => 'a'], results: [], total: 0, limit: 5);
		$this->handler->augmentWithChunkMatches(query: ['_search' => 'a'], results: [], total: 0, limit: 5);
		$this->handler->augmentWithChunkMatches(query: ['_search' => 'b'], results: [], total: 0, limit: 5);
		$this->handler->augmentWithChunkMatches(query: ['_search' => 'a'], results: [], total: 0, limit: 5, _rbac: false);
	}//end testTheCandidateMemoIsKeyedOnTermAndGuardFlags()
}//end class
