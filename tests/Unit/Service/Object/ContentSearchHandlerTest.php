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
		// Total is metaTotal + distinct-chunk-owners upper bound (1 chunk hit
		// even though it turned out to be unresolvable). See "stable total
		// across pages" fix — over-count is intentional and accepted.
		$this->assertSame(4, $result['total']);
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
		// Total is upper-bound: chunk-owner set included the doomed id
		// before the resolve threw. See "stable total across pages" fix.
		$this->assertSame(1, $result['total']);
	}//end testResolveExceptionIsCaughtLoggedAndSkipped()

	// =========================================================================
	// Dedup on object id (ZKN-CONTENT-002/-003)
	// =========================================================================

	public function testObjectAlreadyMatchedByMetadataArmIsNotDuplicated(): void {
		$existing = $this->makeObject(42);

		$this->chunkMapper->method('searchByKeyword')->willReturn(
			[
				['entity_type' => 'object', 'entity_id' => '42', 'score' => 0.8, 'chunk_text' => 'x', 'chunk_index' => 0, 'metadata' => []],
			]
		);
		// The chunk resolves to the same object that the metadata arm already
		// returned. objectMapper->find() is called once (dedup happens after
		// resolve — seenUuids is keyed by getUuid() which is not derivable from
		// the numeric chunk source_id without loading the object).
		$this->objectMapper->method('find')->willReturn($existing);

		$result = $this->handler->augmentWithChunkMatches(
			query: ['_search' => 'quarterly report'],
			results: [$existing],
			total: 1,
			limit: 20
		);

		$this->assertCount(1, $result['results']);
		// Total is upper-bound: chunk-owner set had 1 hit even though it
		// deduped against the metadata arm. Over-count is intentional per
		// "stable total across pages" fix — pagination stays consistent.
		$this->assertSame(2, $result['total']);
	}//end testObjectAlreadyMatchedByMetadataArmIsNotDuplicated()

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
		// Total is upper-bound: the out-of-scope chunk is counted before the
		// scope filter runs. Over-count accepted per "stable total" fix.
		$this->assertSame(1, $result['total']);
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
		// Total is upper-bound: the out-of-scope chunk is counted before the
		// scope filter runs. Over-count accepted per "stable total" fix.
		$this->assertSame(1, $result['total']);
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

	// =========================================================================
	// Page-limit clamping
	// =========================================================================

	public function testAppendedRowsAreClampedToRemainingRoomOnThePage(): void {
		$this->chunkMapper->method('searchByKeyword')->willReturn(
			[
				['entity_type' => 'object', 'entity_id' => '42', 'score' => 0.8, 'chunk_text' => 'x', 'chunk_index' => 0, 'metadata' => []],
			]
		);
		$this->objectMapper->expects($this->never())->method('find');

		// limit=1, already 1 metadata-match result -> zero room for chunk-only appends.
		$result = $this->handler->augmentWithChunkMatches(
			query: ['_search' => 'quarterly report'],
			results: [$this->makeObject(1)],
			total: 1,
			limit: 1
		);

		$this->assertCount(1, $result['results']);
		// Total is upper-bound = metaTotal + distinct chunk-owner count
		// (1 chunk hit, room=0 so nothing appended). This is the whole point
		// of the "stable total" fix: page 2 of the same query would then
		// append this chunk, and the total stays 2 across both pages.
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
}//end class
