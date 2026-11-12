<?php

/**
 * Golden responses of FileExtractionController: who is let through, who is
 * refused, and the exact status and body each gets.
 *
 * Pinned on the code before its response blocks were shared, so the refactor
 * cannot move an admin or file-access check behind the operation, change a
 * refusal into a success, or change one byte of a body.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use Exception;
use OCA\OpenRegister\Db\EntityRelationMapper;
use OCA\OpenRegister\Files\Controller\FileExtractionController;
use OCA\OpenRegister\Files\Db\Chunk;
use OCA\OpenRegister\Files\Db\ChunkMapper;
use OCA\OpenRegister\Files\Service\RiskLevelService;
use OCA\OpenRegister\Files\Service\TextExtractionService;
use OCA\OpenRegister\Search\Service\VectorizationService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\FileExtractionControllerFactory;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Files\Controller\FileExtractionController
 */
final class FileExtractionControllerResponsesGoldenTest extends TestCase {

	/**
	 * The body every refused maintenance call gets.
	 *
	 * @var array<string, mixed>
	 */
	private const ADMIN_REQUIRED = ['success' => false, 'error' => 'Admin privileges required'];

	/**
	 * The text extractor, so a case can make it succeed or throw.
	 *
	 * @var TextExtractionService
	 */
	private TextExtractionService $extractor;

	/**
	 * The vectorization service.
	 *
	 * @var VectorizationService
	 */
	private VectorizationService $vectorizer;

	/**
	 * The chunk mapper.
	 *
	 * @var ChunkMapper
	 */
	private ChunkMapper $chunks;

	/**
	 * Every maintenance endpoint, for every kind of caller, with the service succeeding.
	 *
	 * @return array<string, array{string, string, int, array<string, mixed>}>
	 */
	public static function maintenanceCalls(): array {
		$allowed = [
			'discover'       => [200, ['success' => true, 'message' => 'File discovery completed', 'data' => ['found' => 3]]],
			'extractAll'     => [200, ['success' => true, 'message' => 'Batch extraction completed', 'data' => ['processed' => 2]]],
			'retryFailed'    => [200, ['success' => true, 'message' => 'Retry completed', 'data' => ['retried' => 1]]],
			'stats'          => [200, ['success' => true, 'data' => ['total' => 9]]],
			'cleanup'        => [200, ['success' => true, 'message' => 'Cleanup completed', 'data' => ['deleted' => 0, 'reasons' => []]]],
			'vectorizeBatch' => [200, ['success' => true, 'data' => ['vectorized' => 4]]],
		];
		$cases   = [];
		foreach ($allowed as $method => [$status, $body]) {
			$cases[$method . ' as admin']     = [$method, 'admin', $status, $body];
			$cases[$method . ' as member']    = [$method, 'member', 403, self::ADMIN_REQUIRED];
			$cases[$method . ' as anonymous'] = [$method, 'anonymous', 403, self::ADMIN_REQUIRED];
		}

		$cases['fileTypes as admin']     = ['fileTypes', 'admin', 200, ['success' => true, 'data' => []]];
		$cases['fileTypes as member']    = ['fileTypes', 'member', 200, ['success' => true, 'data' => []]];
		$cases['fileTypes as anonymous'] = ['fileTypes', 'anonymous', 200, ['success' => true, 'data' => []]];
		return $cases;
	}//end maintenanceCalls()

	/**
	 * Every maintenance endpoint whose service can fail, as admin, with the service throwing.
	 *
	 * @return array<string, array{string, array<string, mixed>}>
	 */
	public static function failingCalls(): array {
		return [
			'discover'       => ['discover', ['success' => false, 'error' => 'File discovery failed', 'message' => 'boom']],
			'extractAll'     => ['extractAll', ['success' => false, 'error' => 'Batch extraction failed', 'message' => 'boom']],
			'retryFailed'    => ['retryFailed', ['success' => false, 'error' => 'Retry failed', 'message' => 'boom']],
			'stats'          => ['stats', ['success' => false, 'error' => 'Failed to retrieve statistics', 'message' => 'boom']],
			'vectorizeBatch' => ['vectorizeBatch', ['success' => false, 'error' => 'Vectorization failed', 'message' => 'boom']],
		];
	}//end failingCalls()

	/**
	 * Each per-file endpoint for a reachable and an unreachable file, as a member.
	 *
	 * @return array<string, array{string, int, string, int, array<string, mixed>}>
	 */
	public static function fileCalls(): array {
		$hidden = ['success' => false, 'message' => 'File not found or access denied'];
		return [
			'show a reachable file'      => ['show', 42, 'ok', 200, ['success' => true, 'data' => [['id' => 7]]]],
			'show a file without chunks' => ['show', 42, 'empty', 404, ['success' => false, 'error' => 'File not found in extraction system', 'message' => 'No chunks found for file ID: 42']],
			'show when the read throws'  => ['show', 42, 'throws', 404, ['success' => false, 'error' => 'File not found in extraction system', 'message' => 'boom']],
			'show an unreachable file'   => ['show', 43, 'ok', 404, ['success' => false, 'error' => 'File not found in extraction system'] + $hidden],
			'extract a reachable file'   => ['extract', 42, 'ok', 200, ['success' => true, 'message' => 'File extraction completed']],
			'extract a vanished file'    => ['extract', 42, 'missing', 404, ['success' => false, 'error' => 'File not found in Nextcloud', 'message' => 'gone']],
			'extract when it throws'     => ['extract', 42, 'throws', 500, ['success' => false, 'error' => 'Extraction failed', 'message' => 'boom']],
			'extract an unreachable file' => ['extract', 43, 'ok', 404, ['success' => false, 'error' => 'File not found in Nextcloud'] + $hidden],
		];
	}//end fileCalls()

	/**
	 * A maintenance call gets exactly the pinned status and body, and a refused caller never reaches the service.
	 *
	 * @param string               $method The endpoint.
	 * @param string               $caller admin, member or anonymous.
	 * @param int                  $status The pinned status.
	 * @param array<string, mixed> $body   The pinned body.
	 *
	 * @return void
	 */
	#[DataProvider('maintenanceCalls')]
	public function testMaintenanceResponseIsPinned(string $method, string $caller, int $status, array $body): void {
		$controller = $this->controller(caller: $caller, outcome: 'ok', refused: $status === 403);

		$response = $controller->$method();

		$this->assertSame($status, $response->getStatus());
		$this->assertSame($body, $response->getData());
	}//end testMaintenanceResponseIsPinned()

	/**
	 * A failing service gives a 500 with the pinned body.
	 *
	 * @param string               $method The endpoint.
	 * @param array<string, mixed> $body   The pinned body.
	 *
	 * @return void
	 */
	#[DataProvider('failingCalls')]
	public function testFailureResponseIsPinned(string $method, array $body): void {
		$response = $this->controller(caller: 'admin', outcome: 'throws', refused: false)->$method();

		$this->assertSame(500, $response->getStatus());
		$this->assertSame($body, $response->getData());
	}//end testFailureResponseIsPinned()

	/**
	 * A per-file call gets the pinned status and body, and an unreachable file is never read or extracted.
	 *
	 * @param string               $method  show or extract.
	 * @param int                  $fileId  The file asked for; only 42 is reachable.
	 * @param string               $outcome ok, empty, missing or throws.
	 * @param int                  $status  The pinned status.
	 * @param array<string, mixed> $body    The pinned body.
	 *
	 * @return void
	 */
	#[DataProvider('fileCalls')]
	public function testFileResponseIsPinned(string $method, int $fileId, string $outcome, int $status, array $body): void {
		$controller = $this->controller(caller: 'member', outcome: $outcome, refused: $fileId !== 42);

		$response = $controller->$method($fileId);

		$this->assertSame($status, $response->getStatus());
		$this->assertSame($body, $response->getData());
	}//end testFileResponseIsPinned()

	/**
	 * The controller, for a caller, with its services giving one outcome.
	 *
	 * @param string $caller  admin, member or anonymous.
	 * @param string $outcome ok, empty, missing or throws.
	 * @param bool   $refused Whether the call must not reach a service.
	 *
	 * @return FileExtractionController
	 */
	private function controller(string $caller, string $outcome, bool $refused): FileExtractionController {
		$this->extractor  = $this->createMock(TextExtractionService::class);
		$this->vectorizer = $this->createMock(VectorizationService::class);
		$this->chunks     = $this->createMock(ChunkMapper::class);
		if ($refused === true) {
			$this->expectNoServiceCall();
		} else {
			$this->stubServices(outcome: $outcome);
		}

		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn([]);
		$folder = $this->createMock(Folder::class);
		$folder->method('getById')->willReturnCallback(
			fn (int $id): array => $id === 42 ? [$this->createMock(Node::class)] : []
		);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturn($folder);

		return FileExtractionControllerFactory::create(
			'openregister',
			$request,
			$this->extractor,
			$this->vectorizer,
			$this->chunks,
			$this->createMock(EntityRelationMapper::class),
			$this->createMock(RiskLevelService::class),
			$root,
			$this->session(caller: $caller),
			$this->groupManager(caller: $caller)
		);
	}//end controller()

	/**
	 * A refused call reaches no service.
	 *
	 * @return void
	 */
	private function expectNoServiceCall(): void {
		foreach (['discoverUntrackedFiles', 'extractPendingFiles', 'retryFailedExtractions', 'getStats', 'extractFile'] as $method) {
			$this->extractor->expects($this->never())->method($method);
		}

		$this->vectorizer->expects($this->never())->method('vectorizeBatch');
		$this->chunks->expects($this->never())->method('findBySource');
	}//end expectNoServiceCall()

	/**
	 * Make every service succeed, come back empty, miss the file, or throw.
	 *
	 * @param string $outcome ok, empty, missing or throws.
	 *
	 * @return void
	 */
	private function stubServices(string $outcome): void {
		if ($outcome === 'throws') {
			$boom = new Exception('boom');
			foreach (['discoverUntrackedFiles', 'extractPendingFiles', 'retryFailedExtractions', 'getStats', 'extractFile'] as $method) {
				$this->extractor->method($method)->willThrowException($boom);
			}

			$this->vectorizer->method('vectorizeBatch')->willThrowException($boom);
			$this->chunks->method('findBySource')->willThrowException($boom);
			return;
		}

		$this->extractor->method('discoverUntrackedFiles')->willReturn(['found' => 3]);
		$this->extractor->method('extractPendingFiles')->willReturn(['processed' => 2]);
		$this->extractor->method('retryFailedExtractions')->willReturn(['retried' => 1]);
		$this->extractor->method('getStats')->willReturn(['total' => 9]);
		if ($outcome === 'missing') {
			$this->extractor->method('extractFile')->willThrowException(new NotFoundException('gone'));
		}

		$this->vectorizer->method('vectorizeBatch')->willReturn(['vectorized' => 4]);
		$chunk = $this->createMock(Chunk::class);
		$chunk->method('jsonSerialize')->willReturn(['id' => 7]);
		$this->chunks->method('findBySource')->willReturn($outcome === 'empty' ? [] : [$chunk]);
	}//end stubServices()

	/**
	 * The user session of a caller.
	 *
	 * @param string $caller admin, member or anonymous.
	 *
	 * @return IUserSession
	 */
	private function session(string $caller): IUserSession {
		$session = $this->createMock(IUserSession::class);
		$user    = null;
		if ($caller !== 'anonymous') {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($caller);
		}

		$session->method('getUser')->willReturn($user);
		return $session;
	}//end session()

	/**
	 * The group manager: only "admin" is an administrator.
	 *
	 * @param string $caller admin, member or anonymous.
	 *
	 * @return IGroupManager
	 */
	private function groupManager(string $caller): IGroupManager {
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn($caller === 'admin');
		return $groups;
	}//end groups()
}//end class
