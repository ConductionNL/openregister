<?php

/**
 * Golden access outcomes of the per-file endpoints of FileTextController and
 * FileSidebarController.
 *
 * Pinned on the code before the "can the caller open this file" check moved
 * into one shared class. For every way the check can answer (the object read
 * scope allows or denies; without a scope, an anonymous caller, a reachable and
 * an unreachable file) it pins who is let through to the operation and who gets
 * the exact 404 refusal without reaching it.
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

use OCA\OpenRegister\Controller\Support\ControllerSession;
use OCA\OpenRegister\Db\EntityRelationMapper;
use OCA\OpenRegister\Files\Controller\FileSidebarController;
use OCA\OpenRegister\Files\Service\File\FileReadScope;
use OCA\OpenRegister\Files\Service\File\ManualEntityService;
use OCA\OpenRegister\Files\Service\FileSidebarService;
use OCA\OpenRegister\Files\Service\TextExtractionService;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\FileTextControllerFactory;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\OpenRegister\Files\Controller\FileTextController
 * @covers \OCA\OpenRegister\Files\Controller\FileSidebarController
 */
final class FileAccessGuardGoldenTest extends TestCase {

	/**
	 * The file the caller can open; every other id is out of reach.
	 *
	 * @var int
	 */
	private const REACHABLE = 42;

	/**
	 * Endpoint => [the mock that does the work, the method it calls, the pinned refusal body].
	 *
	 * @var array<string, array{string, string, array<string, mixed>}>
	 */
	private const ENDPOINTS = [
		'getFileText'         => ['extractor', 'getExtractedText', ['success' => false, 'message' => 'File not found or access denied', 'file_id' => 43]],
		'deleteFileText'      => ['none', '', ['success' => false, 'message' => 'File not found or access denied']],
		'anonymizeFile'       => ['files', 'getFileById', ['success' => false, 'message' => 'File not found or access denied']],
		'getObjectsForFile'   => ['sidebar', 'getObjectsForFile', ['success' => false, 'error' => 'File not found or access denied']],
		'getExtractionStatus' => ['sidebar', 'getExtractionStatus', ['success' => false, 'error' => 'File not found or access denied']],
	];

	/**
	 * The mocks a case can expect calls on.
	 *
	 * @var array<string, MockObject>
	 */
	private array $mocks = [];

	/**
	 * Every endpoint under every answer of the access check.
	 *
	 * @return array<string, array{string, string, bool}>
	 */
	public static function cases(): array {
		$ways  = [
			'the read scope allows'          => ['scope-allows', true],
			'the read scope denies'          => ['scope-denies', false],
			'no scope, anonymous'            => ['anonymous', false],
			'no scope, a reachable file'     => ['reachable', true],
			'no scope, an unreachable file'  => ['unreachable', false],
		];
		$cases = [];
		foreach (array_keys(self::ENDPOINTS) as $endpoint) {
			foreach ($ways as $label => [$way, $allowed]) {
				$cases[$endpoint . ', ' . $label] = [$endpoint, $way, $allowed];
			}
		}

		return $cases;
	}//end cases()

	/**
	 * An allowed caller reaches the operation; a refused one gets the pinned 404 and reaches nothing.
	 *
	 * @param string $endpoint The endpoint.
	 * @param string $way      How the access check is set up.
	 * @param bool   $allowed  Whether the caller is let through.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testAccessOutcomeIsPinned(string $endpoint, string $way, bool $allowed): void {
		[$mock, $method, $refusal] = self::ENDPOINTS[$endpoint];
		$fileId     = self::REACHABLE;
		$controller = $this->controller(endpoint: $endpoint, way: $way);
		if ($way === 'unreachable') {
			$fileId = 43;
		}

		if (array_key_exists('file_id', $refusal) === true) {
			$refusal['file_id'] = $fileId;
		}

		if ($mock !== 'none') {
			$this->mocks[$mock]->expects($allowed === true ? $this->once() : $this->never())->method($method);
		}

		$response = $controller->$endpoint($fileId);

		if ($allowed === false) {
			$this->assertSame(404, $response->getStatus());
			$this->assertSame($refusal, $response->getData());
			return;
		}

		$this->assertNotSame($refusal, $response->getData());
		if ($endpoint === 'deleteFileText') {
			$this->assertSame(501, $response->getStatus());
			$this->assertSame(['success' => false, 'message' => 'Chunk deletion not yet implemented. Use chunk-based endpoints.'], $response->getData());
		}
	}//end testAccessOutcomeIsPinned()

	/**
	 * The controller that serves an endpoint, with the access check set up one way.
	 *
	 * @param string $endpoint The endpoint.
	 * @param string $way      How the access check is set up.
	 *
	 * @return FileSidebarController|\OCA\OpenRegister\Files\Controller\FileTextController
	 */
	private function controller(string $endpoint, string $way): object {
		$session = $this->session(way: $way);
		$root    = $this->rootFolder();
		$scope   = $this->readScope(way: $way);
		$request = $this->createMock(IRequest::class);

		$this->mocks = [
			'extractor' => $this->createMock(TextExtractionService::class),
			'files'     => $this->createMock(FileService::class),
			'sidebar'   => $this->createMock(FileSidebarService::class),
		];
		if (in_array($endpoint, ['getObjectsForFile', 'getExtractionStatus'], true) === true) {
			return new FileSidebarController(
				'openregister',
				$request,
				$this->mocks['sidebar'],
				new ControllerSession(userSession: $session, groupManager: $this->createMock(IGroupManager::class), logger: new NullLogger()),
				$root,
				$scope
			);
		}

		return FileTextControllerFactory::create(
			'openregister',
			$request,
			$this->mocks['extractor'],
			$this->mocks['files'],
			$this->createMock(EntityRelationMapper::class),
			new NullLogger(),
			$this->createMock(ManualEntityService::class),
			$session,
			$root,
			$this->createMock(IGroupManager::class),
			$scope
		);
	}//end controller()

	/**
	 * The object read scope: absent, allowing every file, or denying every file.
	 *
	 * @param string $way How the access check is set up.
	 *
	 * @return FileReadScope|null
	 */
	private function readScope(string $way): ?FileReadScope {
		if (in_array($way, ['scope-allows', 'scope-denies'], true) === false) {
			return null;
		}

		$scope = $this->createMock(FileReadScope::class);
		$scope->method('mayReadFile')->willReturnCallback(
			fn (int $fileId): bool => $way === 'scope-allows'
		);
		return $scope;
	}//end readScope()

	/**
	 * The user session: nobody for "anonymous", a member otherwise.
	 *
	 * @param string $way How the access check is set up.
	 *
	 * @return IUserSession
	 */
	private function session(string $way): IUserSession {
		$user = null;
		if ($way !== 'anonymous') {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn('member');
		}

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		return $session;
	}//end session()

	/**
	 * A root folder whose user folder holds only the reachable file.
	 *
	 * @return IRootFolder
	 */
	private function rootFolder(): IRootFolder {
		$folder = $this->createMock(Folder::class);
		$folder->method('getById')->willReturnCallback(
			fn (int $id): array => $id === self::REACHABLE ? [$this->createMock(Node::class)] : []
		);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturn($folder);
		return $root;
	}//end rootFolder()
}//end class
