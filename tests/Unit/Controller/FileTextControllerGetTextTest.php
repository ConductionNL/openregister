<?php

/**
 * GET /api/files/{fileId}/text returns the extracted text (openregister#4106).
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\FileTextController;
use OCA\OpenRegister\Db\Chunk;
use OCA\OpenRegister\Db\ChunkMapper;
use OCA\OpenRegister\Db\EntityRelationMapper;
use OCA\OpenRegister\Service\File\ManualEntityService;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\TextExtractionService;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The route was a stub answering 404 for every file while the MCP discovery
 * advertised it as returning the text. The text is read back from the file's
 * chunks, behind the same per-user file access check as extraction.
 */
class FileTextControllerGetTextTest extends TestCase {

	private ChunkMapper&MockObject $chunkMapper;

	/**
	 * The controller, with the caller able to open the files in $reachable.
	 *
	 * @param int[] $reachable File ids the caller's user folder resolves.
	 *
	 * @return FileTextController
	 */
	private function controller(array $reachable): FileTextController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$folder = $this->createMock(Folder::class);
		$folder->method('getById')->willReturnCallback(
			fn (int $id): array => in_array($id, $reachable, true) === true ? [$this->createMock(Node::class)] : []
		);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturn($folder);

		return new FileTextController(
			'openregister',
			$this->createMock(IRequest::class),
			$this->createMock(TextExtractionService::class),
			$this->createMock(FileService::class),
			$this->createMock(EntityRelationMapper::class),
			new NullLogger(),
			$this->createMock(IAppConfig::class),
			$this->createMock(ManualEntityService::class),
			$session,
			$root,
			$this->createMock(IGroupManager::class),
			$this->chunkMapper
		);
	}//end controller()

	/**
	 * A stored chunk.
	 *
	 * @param int    $index The chunk index.
	 * @param string $text  Its text.
	 * @param int    $start Its start offset.
	 *
	 * @return Chunk
	 */
	private function chunk(int $index, string $text, int $start): Chunk {
		$chunk = new Chunk();
		$chunk->setChunkIndex($index);
		$chunk->setTextContent($text);
		$chunk->setStartOffset($start);
		$chunk->setEndOffset($start + strlen($text));

		return $chunk;
	}//end chunk()

	protected function setUp(): void {
		parent::setUp();
		$this->chunkMapper = $this->createMock(ChunkMapper::class);
	}//end setUp()

	/**
	 * The text comes back whole: overlap removed, the metadata chunk left out.
	 *
	 * @return void
	 */
	public function testTheExtractedTextIsReturned(): void {
		$this->chunkMapper->method('findBySource')->with('file', 12)->willReturn(
			[
				$this->chunk(-1, '{"mimeType": "application/pdf"}', 0),
				$this->chunk(0, 'The quick brown ', 0),
				$this->chunk(1, 'brown fox jumps.', 10),
			]
		);

		$response = $this->controller(reachable: [12])->getFileText(12);

		$this->assertSame(200, $response->getStatus());
		$this->assertSame('The quick brown fox jumps.', $response->getData()['text']);
	}//end testTheExtractedTextIsReturned()

	/**
	 * A file the caller cannot open is not read, and answers as not found.
	 *
	 * @return void
	 */
	public function testAFileTheCallerCannotOpenIsNotRead(): void {
		$this->chunkMapper->expects($this->never())->method('findBySource');

		$response = $this->controller(reachable: [])->getFileText(12);

		$this->assertSame(404, $response->getStatus());
	}//end testAFileTheCallerCannotOpenIsNotRead()

	/**
	 * A file with no extracted text answers 404 and says so.
	 *
	 * @return void
	 */
	public function testAFileWithoutTextAnswers404(): void {
		$this->chunkMapper->method('findBySource')->willReturn([]);

		$response = $this->controller(reachable: [12])->getFileText(12);

		$this->assertSame(404, $response->getStatus());
		$this->assertStringContainsString('No extracted text', $response->getData()['message']);
	}//end testAFileWithoutTextAnswers404()
}//end class
