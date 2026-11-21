<?php

/**
 * A file over the extraction ceiling is found by its name, and its hit says it was not text-searched.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/large-file-handling/specs/file-actions/spec.md#requirement-text-extraction-has-a-stated-ceiling-and-a-file-above-it-stays-findable-req-lfh-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use OCA\OpenRegister\Files\Controller\FileSearchController;
use OCA\OpenRegister\Files\Controller\Support\FileSearchControllerServices;
use OCA\OpenRegister\Files\Db\Chunk;
use OCA\OpenRegister\Files\Db\ChunkMapper;
use OCA\OpenRegister\Files\Service\File\FileReadScope;
use OCA\OpenRegister\Files\Service\File\FileReadScopeRules;
use OCA\OpenRegister\Files\Service\File\FileReadScopeTree;
use OCA\OpenRegister\Files\Service\FileSidebarService;
use OCA\OpenRegister\Files\Service\TextExtraction\TextSearchedMarker;
use OCA\OpenRegister\Kernel\Contracts\ChunkMapperInterface;
use OCA\OpenRegister\Search\Service\Vectorization\HybridSearchRequest;
use OCA\OpenRegister\Search\Service\VectorizationService;
use OCP\DB\IResult;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Files\Controller\FileSearchController
 * @covers \OCA\OpenRegister\Files\Service\TextExtraction\TextSearchedMarker
 * @uses   \OCA\OpenRegister\Files\Controller\Support\FileSearchControllerServices
 * @uses   \OCA\OpenRegister\Files\Db\ChunkMapper
 * @uses   \OCA\OpenRegister\Files\Db\Chunk
 * @uses   \OCA\OpenRegister\Files\Service\File\FileReadScope
 * @uses   \OCA\OpenRegister\Files\Service\File\FileReadScopeRules
 * @uses   \OCA\OpenRegister\Files\Service\File\FileReadScopeTree
 * @uses   \OCA\OpenRegister\Kernel\Contracts\KeywordChunkSearch
 * @uses   \OCA\OpenRegister\Search\Service\Vectorization\HybridSearchRequest
 */
class FileSearchTextSearchedTest extends TestCase {

	/**
	 * The metadata chunk text the extraction writes for a 300 MB PDF above a 50 MB ceiling.
	 *
	 * @return string
	 */
	private static function skippedMetadata(): string {
		return (string) json_encode(
			[
				'source_type' => 'file', 'source_id' => 501, 'text_length' => 0,
				'file_metadata' => ['file_name' => 'raadsdossier-2024.pdf', 'file_size' => 314572800, 'mime_type' => 'application/pdf'],
				'extraction_status' => 'skipped-too-large', 'ceiling_bytes' => 52428800,
			],
			JSON_PRETTY_PRINT
		);
	}//end skippedMetadata()

	/**
	 * The controller over the real ChunkMapper keyword search on PostgreSQL and
	 * the real read scope, in which the caller may open files 501 and 502.
	 *
	 * @param array<int, array<string, mixed>> $rows The chunk rows the keyword query answers.
	 *
	 * @return FileSearchController
	 */
	private function controller(array $rows): FileSearchController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => ($key === 'query' ? 'raadsdossier' : $default)
		);

		$result = $this->createMock(IResult::class);
		$result->method('fetchAll')->willReturn($rows);
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());
		$db->method('executeQuery')->willReturn($result);

		// The fusion leg hands the keyword arm back as it is: no vector hits.
		$vectorService = $this->createMock(VectorizationService::class);
		$vectorService->method('hybridSearch')->willReturnCallback(
			static fn (HybridSearchRequest $request): array => ['results' => $request->keywordResults]
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('officer');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getFirstNodeById')->willReturnCallback(
			fn (int $id) => (in_array($id, [501, 502], true) ? $this->createMock(File::class) : null)
		);
		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->willReturn($userFolder);

		return new FileSearchController(
			request: $request,
			services: new FileSearchControllerServices(
				vectorService: $vectorService,
				chunkMapper: new ChunkMapper(db: $db),
				readScope: new FileReadScope(
					tree: new FileReadScopeTree(rootFolder: $rootFolder, userSession: $session),
					rules: new FileReadScopeRules(),
					logger: new NullLogger()
				),
				logger: new NullLogger()
			)
		);
	}//end controller()

	/**
	 * The 300 MB scan is found by its name through its metadata chunk and
	 * marked `textSearched: false`; a read file's text hit is marked true.
	 *
	 * @return void
	 */
	public function testAFileOverTheCeilingIsFoundByNameAndMarked(): void {
		$controller = $this->controller(
			rows: [
				['id' => 1, 'source_type' => 'file', 'source_id' => 501, 'text_content' => self::skippedMetadata(), 'chunk_index' => -1, 'score' => 0.9],
				['id' => 2, 'source_type' => 'file', 'source_id' => 502, 'text_content' => 'het raadsdossier van 2023', 'chunk_index' => 0, 'score' => 0.5],
			]
		);

		$data = $controller->hybridSearch()->getData();

		$this->assertTrue($data['success']);
		$this->assertSame(
			[['501', false], ['502', true]],
			array_map(static fn (array $hit): array => [$hit['entity_id'], $hit['textSearched']], $data['results'])
		);
	}//end testAFileOverTheCeilingIsFoundByNameAndMarked()

	/**
	 * A hit that does not carry its chunk is looked up once per file.
	 *
	 * @return void
	 */
	public function testAHitWithoutItsChunkIsLookedUpOncePerFile(): void {
		$metadata = new Chunk();
		$metadata->setChunkIndex(-1);
		$metadata->setTextContent(self::skippedMetadata());
		$chunks = $this->createMock(ChunkMapperInterface::class);
		$chunks->expects($this->exactly(2))->method('findBySource')->willReturnCallback(
			static fn (string $type, int $id): array => ($id === 501 ? [$metadata] : [])
		);

		$marked = (new TextSearchedMarker(chunkMapper: $chunks))->mark(
			results: [
				['entity_type' => 'file', 'entity_id' => '501', 'similarity' => 0.8],
				['entity_type' => 'file', 'entity_id' => 501, 'similarity' => 0.7],
				['entity_type' => 'file', 'entity_id' => '777'],
				['entity_type' => 'object', 'entity_id' => 'obj-1'],
			]
		);

		$this->assertSame([false, false, true], array_column(array_slice($marked, 0, 3), 'textSearched'));
		$this->assertArrayNotHasKey('textSearched', $marked[3]);
	}//end testAHitWithoutItsChunkIsLookedUpOncePerFile()

	/**
	 * A file whose chunks cannot be read is not reported as skipped.
	 *
	 * @return void
	 */
	public function testAnUnreadableChunkStoreIsNotReportedAsSkipped(): void {
		$chunks = $this->createMock(ChunkMapperInterface::class);
		$chunks->method('findBySource')->willThrowException(new RuntimeException('down'));

		$marked = (new TextSearchedMarker(chunkMapper: $chunks))->mark(results: [['entity_type' => 'file', 'entity_id' => '9']]);

		$this->assertTrue($marked[0]['textSearched']);
	}//end testAnUnreadableChunkStoreIsNotReportedAsSkipped()

	/**
	 * The skipped ceiling is read from the metadata chunk only, and only when it says skipped.
	 *
	 * @return void
	 */
	public function testTheSkippedCeilingComesFromTheMetadataChunk(): void {
		$metadata = new Chunk();
		$metadata->setChunkIndex(-1);
		$metadata->setTextContent(self::skippedMetadata());
		$read = new Chunk();
		$read->setChunkIndex(-1);
		$read->setTextContent((string) json_encode(['source_type' => 'file', 'text_length' => 900]));

		$this->assertSame(52428800, TextSearchedMarker::skippedCeiling(chunks: [$metadata]));
		$this->assertNull(TextSearchedMarker::skippedCeiling(chunks: [$read]));
		$this->assertNull(TextSearchedMarker::skippedCeiling(chunks: []));
	}//end testTheSkippedCeilingComesFromTheMetadataChunk()
}//end class
