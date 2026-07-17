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
 * @spec openspec/changes/expose-content-search-in-object-service/tasks.md#task-6
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
class ContentSearchHandlerTest extends TestCase
{
    private ChunkMapper&MockObject $chunkMapper;
    private FileMapper&MockObject $fileMapper;
    private MagicMapper&MockObject $objectMapper;
    private LoggerInterface&MockObject $logger;
    private ContentSearchHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->chunkMapper  = $this->createMock(ChunkMapper::class);
        $this->fileMapper   = $this->createMock(FileMapper::class);
        $this->objectMapper = $this->createMock(MagicMapper::class);
        $this->logger       = $this->createMock(LoggerInterface::class);

        $this->handler = new ContentSearchHandler(
            $this->chunkMapper,
            $this->fileMapper,
            $this->objectMapper,
            $this->logger
        );
    }//end setUp()

    /**
     * Build an ObjectEntity test double with id/register/schema set.
     */
    private function makeObject(int $id, string $register='1', string $schema='1'): ObjectEntity
    {
        $object = new ObjectEntity();
        $object->setId($id);
        $object->setRegister($register);
        $object->setSchema($schema);

        return $object;
    }//end makeObject()

    // =========================================================================
    // No-op paths (default-off byte-identity, ZKN-CONTENT-001)
    // =========================================================================

    public function testNoSearchTermReturnsResultsAndTotalUnchanged(): void
    {
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

    public function testZeroLimitSkipsChunkFanOut(): void
    {
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

    public function testEmptyChunkHitsReturnsResultsAndTotalUnchanged(): void
    {
        $this->chunkMapper->method('searchByKeyword')->willReturn([]);
        $this->objectMapper->expects($this->never())->method('find');

        $existing = [$this->makeObject(1)];
        $result   = $this->handler->augmentWithChunkMatches(
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

    public function testObjectSourceTypeResolvesDirectlyByEntityId(): void
    {
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

    public function testFileSourceTypeResolvesViaFileMapperJoin(): void
    {
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

    public function testFileChunkWithUnresolvableOwningObjectIsSkippedSilently(): void
    {
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
        $this->assertSame(3, $result['total']);
    }//end testFileChunkWithUnresolvableOwningObjectIsSkippedSilently()

    public function testResolveExceptionIsCaughtLoggedAndSkipped(): void
    {
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
        $this->assertSame(0, $result['total']);
    }//end testResolveExceptionIsCaughtLoggedAndSkipped()

    // =========================================================================
    // Dedup on object id (ZKN-CONTENT-002/-003)
    // =========================================================================

    public function testObjectAlreadyMatchedByMetadataArmIsNotDuplicated(): void
    {
        $existing = $this->makeObject(42);

        $this->chunkMapper->method('searchByKeyword')->willReturn(
            [
                ['entity_type' => 'object', 'entity_id' => '42', 'score' => 0.8, 'chunk_text' => 'x', 'chunk_index' => 0, 'metadata' => []],
            ]
        );
        $this->objectMapper->expects($this->never())->method('find');

        $result = $this->handler->augmentWithChunkMatches(
            query: ['_search' => 'quarterly report'],
            results: [$existing],
            total: 1,
            limit: 20
        );

        $this->assertCount(1, $result['results']);
        $this->assertSame(1, $result['total']);
    }//end testObjectAlreadyMatchedByMetadataArmIsNotDuplicated()

    // =========================================================================
    // Register / schema scope filtering
    // =========================================================================

    public function testObjectOutsideRequestedRegisterIsSkipped(): void
    {
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
        $this->assertSame(0, $result['total']);
    }//end testObjectOutsideRequestedRegisterIsSkipped()

    public function testObjectOutsideRequestedSchemasIsSkipped(): void
    {
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
        $this->assertSame(0, $result['total']);
    }//end testObjectOutsideRequestedSchemasIsSkipped()

    public function testUnscopedQueryMatchesAnyRegisterOrSchema(): void
    {
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

    public function testAppendedRowsAreClampedToRemainingRoomOnThePage(): void
    {
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
        $this->assertSame(1, $result['total']);
    }//end testAppendedRowsAreClampedToRemainingRoomOnThePage()

    // =========================================================================
    // Uses the unranked fallback (ZKN-CONTENT-001 MariaDB scenario)
    // =========================================================================

    public function testAlwaysRequestsUnrankedFallbackFromChunkMapper(): void
    {
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
}//end class
