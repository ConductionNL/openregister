<?php

/**
 * ContentSearchWideningTest
 *
 * The widening request, the metadata page it carries, and ContentSearchHandler::widen().
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
 * @spec openspec/specs/zoeken-filteren/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Files\Db\ChunkMapper;
use OCA\OpenRegister\Files\Db\FileMapper;
use OCA\OpenRegister\Service\Object\ContentSearchHandler;
use OCA\OpenRegister\Service\Object\ContentSearchWidening;
use OCA\OpenRegister\Service\Object\MetadataArmPage;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ContentSearchWideningTest extends TestCase {

	public function testTheSearchTermIsNullWhenAbsentBlankOrNotAString(): void {
		$page = new MetadataArmPage(results: [], total: 0, limit: 10);

		$this->assertNull((new ContentSearchWidening(query: [], page: $page))->searchTerm());
		$this->assertNull((new ContentSearchWidening(query: ['_search' => '  '], page: $page))->searchTerm());
		$this->assertNull((new ContentSearchWidening(query: ['_search' => ['a']], page: $page))->searchTerm());
		$this->assertSame('tax', (new ContentSearchWidening(query: ['_search' => 'tax'], page: $page))->searchTerm());
	}//end testTheSearchTermIsNullWhenAbsentBlankOrNotAString()

	public function testAnUnchangedPageIsItsRowsAndTotal(): void {
		$row  = new ObjectEntity();
		$page = new MetadataArmPage(results: [$row], total: 7, limit: 10, offset: 20);

		$this->assertSame(['results' => [$row], 'total' => 7], $page->unchanged());
		$this->assertSame(20, $page->offset);
		$this->assertSame(0, (new MetadataArmPage(results: [], total: 0, limit: 5))->offset);
	}//end testAnUnchangedPageIsItsRowsAndTotal()

	public function testWidenAppendsAChunkOnlyOwnerBehindTheMetadataRows(): void {
		$chunks = $this->createMock(ChunkMapper::class);
		$chunks->method('searchByKeyword')->willReturn([['entity_type' => 'object', 'entity_id' => '42']]);

		$owner = new ObjectEntity();
		$owner->setUuid('owner-42');
		$owner->setRegister('1');
		$owner->setSchema('1');

		$objects = $this->createMock(MagicMapper::class);
		$objects->expects($this->once())->method('find')->with(42, null, null, false, false, false)->willReturn($owner);
		$objects->method('searchObjectsPaginated')->willReturn(['results' => []]);

		$handler  = new ContentSearchHandler($chunks, $this->createMock(FileMapper::class), $objects, $this->createMock(LoggerInterface::class));
		$metadata = new ObjectEntity();
		$metadata->setUuid('meta-1');
		$widening = new ContentSearchWidening(query: ['_search' => 'tax'], page: new MetadataArmPage(results: [$metadata], total: 1, limit: 10));

		$result = $handler->widen($widening, false, false);

		$this->assertSame([$metadata, $owner], $result['results']);
		$this->assertSame(2, $result['total']);
	}//end testWidenAppendsAChunkOnlyOwnerBehindTheMetadataRows()

	public function testWidenLeavesACountOnlyPageAlone(): void {
		$chunks = $this->createMock(ChunkMapper::class);
		$chunks->expects($this->never())->method('searchByKeyword');

		$handler  = new ContentSearchHandler($chunks, $this->createMock(FileMapper::class), $this->createMock(MagicMapper::class), $this->createMock(LoggerInterface::class));
		$widening = new ContentSearchWidening(query: ['_search' => 'tax'], page: new MetadataArmPage(results: [], total: 3, limit: 0));

		$this->assertSame(['results' => [], 'total' => 3], $handler->widen($widening));
	}//end testWidenLeavesACountOnlyPageAlone()
}//end class
