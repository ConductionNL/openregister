<?php

/**
 * File search returns only the chunks of files the caller may read (openregister#4097).
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

use OCA\OpenRegister\Controller\FileSearchController;
use OCA\OpenRegister\Db\ChunkMapper;
use OCA\OpenRegister\Service\File\FileReadScope;
use OCA\OpenRegister\Service\VectorizationService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A signed-in user cannot read the text of a file they cannot open through search.
 *
 * Before openregister#4097 both endpoints returned every matching chunk, text
 * included, with no check that the caller may read the file. The scope here is
 * the REAL FileReadScope over a doubled file tree in which user B can open
 * file 101 and not file 202.
 */
class FileSearchReadScopeTest extends TestCase {

	private FileSearchController $controller;

	private VectorizationService&MockObject $vectorService;

	private ChunkMapper&MockObject $chunkMapper;

	private IRequest&MockObject $request;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => ($key === 'query' ? 'begroting' : $default)
		);
		$this->vectorService = $this->createMock(VectorizationService::class);
		$this->chunkMapper = $this->createMock(ChunkMapper::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('user-b');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getFirstNodeById')->willReturnCallback(
			fn (int $id) => ($id === 101 ? $this->createMock(File::class) : null)
		);
		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->with('user-b')->willReturn($userFolder);

		$this->controller = new FileSearchController(
			'openregister',
			$this->request,
			$this->vectorService,
			$this->chunkMapper,
			new NullLogger(),
			new FileReadScope($rootFolder, $session, new NullLogger())
		);
	}//end setUp()

	/**
	 * One chunk of a file B can open, one of a file B cannot, one of an object.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function hits(): array {
		return [
			['entity_type' => 'file', 'entity_id' => '101', 'chunk_text' => 'readable begroting'],
			['entity_type' => 'file', 'entity_id' => '202', 'chunk_text' => 'secret begroting of user A'],
			['entity_type' => 'object', 'entity_id' => 'obj-1', 'chunk_text' => 'object begroting'],
		];
	}//end hits()

	/**
	 * Semantic search leaves out the chunks of a file the caller cannot open.
	 *
	 * @return void
	 */
	public function testSemanticSearchLeavesOutUnreadableFiles(): void {
		$this->vectorService->method('semanticSearch')->willReturn($this->hits());

		$data = $this->controller->semanticSearch()->getData();

		$this->assertSame(1, $data['total']);
		$this->assertSame(['101'], array_column($data['results'], 'entity_id'));
		$this->assertStringNotContainsString('secret', (string)json_encode($data));
	}//end testSemanticSearchLeavesOutUnreadableFiles()

	/**
	 * Hybrid search scopes the keyword arm before fusion, and the fused results after.
	 *
	 * @return void
	 */
	public function testHybridSearchLeavesOutUnreadableFiles(): void {
		$this->chunkMapper->method('searchByKeyword')->willReturn($this->hits());

		$fusedInput = null;
		$this->vectorService->method('hybridSearch')->willReturnCallback(
			function (string $query, array $keywordResults = [], int $limit = 20, array $weights = []) use (&$fusedInput): array {
				$fusedInput = $keywordResults;
				return ['results' => $this->hits(), 'total' => 3];
			}
		);

		$data = $this->controller->hybridSearch()->getData();

		$this->assertSame(['101'], array_column($fusedInput ?? [], 'entity_id'), 'Only readable keyword hits may be fused.');
		$this->assertSame(['101'], array_column($data['results'], 'entity_id'));
		$this->assertSame(1, $data['total']);
		$this->assertStringNotContainsString('secret', (string)json_encode($data));
	}//end testHybridSearchLeavesOutUnreadableFiles()
}//end class
