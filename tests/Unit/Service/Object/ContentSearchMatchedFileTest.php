<?php

/**
 * ContentSearchHandler: a row found through a file carries the file's name, and nothing of its text.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/tables-bulk-jobs-and-file-search/design.md
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Files\Db\ChunkMapper;
use OCA\OpenRegister\Files\Db\FileMapper;
use OCA\OpenRegister\Service\Object\ContentSearchHandler;
use OCA\OpenRegister\Service\Object\ContentSearchWidening;
use OCA\OpenRegister\Service\Object\MetadataArmPage;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ContentSearchMatchedFileTest extends TestCase {

	private const SECRET = 'BSN 000000000 en rekening NL00BANK0000000000';

	/**
	 * The chunk store.
	 *
	 * @var ChunkMapper&MockObject
	 */
	private ChunkMapper&MockObject $chunkMapper;

	/**
	 * The file mapper.
	 *
	 * @var FileMapper&MockObject
	 */
	private FileMapper&MockObject $fileMapper;

	/**
	 * The object mapper.
	 *
	 * @var MagicMapper&MockObject
	 */
	private MagicMapper&MockObject $objectMapper;

	/**
	 * The handler under test.
	 *
	 * @var ContentSearchHandler
	 */
	private ContentSearchHandler $handler;

	protected function setUp(): void {
		parent::setUp();
		$this->chunkMapper = $this->createMock(ChunkMapper::class);
		$this->fileMapper = $this->createMock(FileMapper::class);
		$this->objectMapper = $this->createMock(MagicMapper::class);
		$this->handler = new ContentSearchHandler($this->chunkMapper, $this->fileMapper, $this->objectMapper, $this->createMock(LoggerInterface::class));
	}

	private function object(int $id): ObjectEntity {
		$object = new ObjectEntity();
		$object->setId($id);
		$object->setUuid('obj-uuid-'.$id);
		$object->setRegister('1');
		$object->setSchema('1');
		return $object;
	}

	private function hit(string $type, string $id): array {
		return [
			'entity_type' => $type,
			'entity_id' => $id,
			'score' => 0.8,
			'chunk_text' => 'Bijlage bij de zaak: '.self::SECRET,
			'text_content' => 'Bijlage bij de zaak: '.self::SECRET,
			'chunk_index' => 0,
			'metadata' => ['filename' => 'bijlage.pdf'],
		];
	}

	private function search(array $metadataRows=[]): array {
		return $this->handler->widen(
			widening: new ContentSearchWidening(
				query: ['_search' => 'rekening'],
				page: new MetadataArmPage(results: $metadataRows, total: count($metadataRows), limit: 20)
			)
		)['results'];
	}

	private function self(ObjectEntity $row): array {
		return ($row->jsonSerialize()['@self'] ?? []);
	}

	public function testARowFoundThroughAFileCarriesTheFileNameAndNoText(): void {
		$this->chunkMapper->method('searchByKeyword')->willReturn([$this->hit('file', '100')]);
		$this->fileMapper->method('findOwningObjectUuid')->with(100)->willReturn('obj-uuid-7');
		$this->fileMapper->expects($this->once())->method('getFile')->with(100)->willReturn(['fileid' => 100, 'name' => 'besluit-2026.txt']);
		$this->objectMapper->method('find')->willReturn($this->object(7));

		$rows = $this->search();

		$this->assertCount(1, $rows);
		$this->assertSame('besluit-2026.txt', $this->self($rows[0])['matchedFile']);
		$this->assertStringNotContainsString(self::SECRET, (string)json_encode($rows[0]->jsonSerialize()));
	}

	public function testARowFoundThroughItsOwnTextHasNoMatchedFile(): void {
		$this->chunkMapper->method('searchByKeyword')->willReturn([$this->hit('object', '42')]);
		$this->fileMapper->expects($this->never())->method('getFile');
		$this->objectMapper->method('find')->willReturn($this->object(42));

		$rows = $this->search();

		$this->assertArrayNotHasKey('matchedFile', $this->self($rows[0]));
	}

	public function testAMetadataMatchIsNotMarkedEvenWhenAFileAlsoMatched(): void {
		$this->chunkMapper->method('searchByKeyword')->willReturn([$this->hit('file', '100')]);
		$this->fileMapper->method('findOwningObjectUuid')->willReturn('obj-uuid-7');
		$this->fileMapper->expects($this->never())->method('getFile');
		$this->objectMapper->method('find')->willReturn($this->object(7));
		$this->objectMapper->method('searchObjects')->willReturn([$this->object(7)]);

		$metadataRow = $this->object(7);
		$rows = $this->search(metadataRows: [$metadataRow]);

		$this->assertCount(1, $rows);
		$this->assertArrayNotHasKey('matchedFile', $this->self($rows[0]));
	}

	public function testAFileWhoseNameCannotBeReadLeavesTheRowWithoutOne(): void {
		$this->chunkMapper->method('searchByKeyword')->willReturn([$this->hit('file', '100')]);
		$this->fileMapper->method('findOwningObjectUuid')->willReturn('obj-uuid-7');
		$this->fileMapper->method('getFile')->willThrowException(new \RuntimeException('filecache gone'));
		$this->objectMapper->method('find')->willReturn($this->object(7));

		$rows = $this->search();

		$this->assertCount(1, $rows);
		$this->assertArrayNotHasKey('matchedFile', $this->self($rows[0]));
	}
}
