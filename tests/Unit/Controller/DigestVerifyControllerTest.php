<?php

/**
 * `GET /api/files/{fileId}/digest/verify` (REQ-PFD-002).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/published-file-digest/specs/file-actions/spec.md#requirement-a-published-file-is-checked-against-its-digest-when-it-is-read-req-pfd-002
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Files\Controller\FilesController;
use OCA\OpenRegister\Files\Db\FileMapper;
use OCA\OpenRegister\Files\Service\File\ObjectFileAccess;
use OCA\OpenRegister\Service\File\PublishedDigestReader;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\FilesControllerFactory;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The verify endpoint for a citizen, a colleague and a stranger.
 */
#[CoversClass(FilesController::class)]
class DigestVerifyControllerTest extends TestCase {

	/**
	 * What the reader answers.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $answer = null;

	/**
	 * A citizen verifying a published file gets identical with the seal.
	 *
	 * @return void
	 */
	public function testIdenticalIsReported(): void {
		$this->answer = ['recorded' => 'abc', 'current' => 'abc', 'verdict' => 'identical', 'auditRow' => ['uuid' => 'a-1', 'hash' => 'h', 'previousHash' => 'p']];

		$response = $this->controller(signedIn: false, published: true)->verifyDigest(42);

		self::assertSame(200, $response->getStatus());
		self::assertSame($this->answer, $response->getData());
	}//end testIdenticalIsReported()

	/**
	 * A colleague who may read the object gets changed when the bytes were replaced.
	 *
	 * @return void
	 */
	public function testChangedIsReported(): void {
		$this->answer = ['recorded' => 'abc', 'current' => 'def', 'verdict' => 'changed', 'auditRow' => ['uuid' => 'a-1', 'hash' => 'h', 'previousHash' => 'p']];

		$response = $this->controller(signedIn: true, published: false, mayRead: true)->verifyDigest(42);

		self::assertSame(200, $response->getStatus());
		self::assertSame('changed', $response->getData()['verdict']);
	}//end testChangedIsReported()

	/**
	 * An anonymous caller cannot verify a file that is not public, and the reader is never asked.
	 *
	 * @return void
	 */
	public function testAPrivateFileIsNotVerifiableAnonymously(): void {
		$response = $this->controller(signedIn: false, published: false)->verifyDigest(42);

		self::assertSame(403, $response->getStatus());
	}//end testAPrivateFileIsNotVerifiableAnonymously()

	/**
	 * A signed-in caller the object's rule refuses gets the same 404 as a missing file.
	 *
	 * @return void
	 */
	public function testACallerWhoMayNotReadTheObjectGets404(): void {
		$this->answer = ['recorded' => 'abc', 'current' => 'abc', 'verdict' => 'identical', 'auditRow' => ['uuid' => 'a-1', 'hash' => 'h', 'previousHash' => 'p']];

		$response = $this->controller(signedIn: true, published: false, mayRead: false)->verifyDigest(42);

		self::assertSame(404, $response->getStatus());
		self::assertSame(['error' => 'File not found'], $response->getData());
	}//end testACallerWhoMayNotReadTheObjectGets404()

	/**
	 * A readable file that is not published has nothing to verify.
	 *
	 * @return void
	 */
	public function testAnUnpublishedFileIs404(): void {
		$this->answer = null;

		$response = $this->controller(signedIn: true, published: false, mayRead: true)->verifyDigest(42);

		self::assertSame(404, $response->getStatus());
		self::assertSame(['error' => 'This file is not published'], $response->getData());
	}//end testAnUnpublishedFileIs404()

	/**
	 * The route is reachable without a session; the method decides who may verify.
	 *
	 * @return void
	 */
	public function testTheRouteIsAPublicPage(): void {
		self::assertCount(1, (new ReflectionMethod(FilesController::class, 'verifyDigest'))->getAttributes(PublicPage::class));
		self::assertStringContainsString(
			"'files#verifyDigest', 'url' => '/api/files/{fileId}/digest/verify'",
			(string)file_get_contents(__DIR__.'/../../../appinfo/routes.php')
		);
	}//end testTheRouteIsAPublicPage()

	/**
	 * The controller over one managed file 42 of an object.
	 *
	 * @param bool $signedIn  Whether a user is signed in.
	 * @param bool $published Whether the file is publicly shared.
	 * @param bool $mayRead   Whether the object's read rule admits the signed-in user.
	 *
	 * @return FilesController The controller.
	 */
	private function controller(bool $signedIn, bool $published, bool $mayRead = false): FilesController {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(42);

		$fileService = $this->createMock(FileService::class);
		$fileService->method('getFileById')->willReturn($file);
		$fileService->method('isManagedFile')->willReturn(true);
		$fileService->method('findObjectForFile')->willReturn(new ObjectEntity());

		$fileMapper = $this->createMock(FileMapper::class);
		$fileMapper->method('isFilePublished')->with(42)->willReturn($published);

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($signedIn === true ? $this->createMock(IUser::class) : null);

		$access = $this->createMock(ObjectFileAccess::class);
		$access->method('mayRead')->willReturn($mayRead);

		$reader = $this->createMock(PublishedDigestReader::class);
		if (($signedIn === false && $published === false) || ($signedIn === true && $mayRead === false)) {
			$reader->expects($this->never())->method('verify');
		}

		$reader->method('verify')->willReturnCallback(fn (): ?array => $this->answer);

		return FilesControllerFactory::create(
			request: $this->createMock(IRequest::class),
			fileService: $fileService,
			objectService: $this->createMock(ObjectService::class),
			rootFolder: $this->createMock(IRootFolder::class),
			userManager: $this->createMock(IUserManager::class),
			eventDispatcher: $this->createMock(IEventDispatcher::class),
			fileMapper: $fileMapper,
			userSession: $session,
			objectFileAccess: $access,
			publishedDigests: $reader
		);
	}//end controller()
}//end class
