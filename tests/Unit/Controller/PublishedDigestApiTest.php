<?php

/**
 * The digest of a published file on its listings and its download (REQ-PFD-002).
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

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Files\Controller\FilesController;
use OCA\OpenRegister\Files\Db\FileMapper;
use OCA\OpenRegister\Files\Service\File\ObjectFileAccess;
use OCA\OpenRegister\Service\File\FileFormattingHandler;
use OCA\OpenRegister\Service\File\FileSession;
use OCA\OpenRegister\Service\File\PublishedDigestReader;
use OCA\OpenRegister\Service\File\PublishedDownloadGuard;
use OCA\OpenRegister\Service\File\PublishedFileChangedException;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\FilesControllerFactory;
use OCP\AppFramework\Http\StreamResponse;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\Lock\ILockManager;
use OCP\Files\Node;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A published file carries its seal where it is listed, and its download is checked against it.
 */
#[CoversClass(FileFormattingHandler::class)]
#[CoversClass(FilesController::class)]
#[UsesClass(FileSession::class)]
#[UsesClass(PublishedFileChangedException::class)]
class PublishedDigestApiTest extends TestCase {

	private const BLOCK = [
		'sha256' => 'abc123',
		'size' => 22,
		'publishedAt' => '2025-11-24T10:00:00+00:00',
		'sealedBy' => ['uuid' => 'audit-1', 'hash' => 'h-1'],
	];

	/**
	 * A listing names the seal of each published file and null for the others; `published` keeps its date.
	 *
	 * @return void
	 */
	public function testListingsCarryTheDigest(): void {
		$reader = $this->createMock(PublishedDigestReader::class);
		$reader->method('publishedOf')->willReturnMap([[7, self::BLOCK], [8, null]]);

		$listing = $this->formatter(reader: $reader)->formatFiles([$this->node(7), $this->node(8)]);

		self::assertSame(self::BLOCK, $listing['results'][0]['publishedDigest']);
		self::assertNull($listing['results'][1]['publishedDigest']);
		self::assertArrayHasKey('published', $listing['results'][0], 'The publication date key is kept beside it.');
		self::assertNull($listing['results'][0]['published']);
	}//end testListingsCarryTheDigest()

	/**
	 * A `publishedDigest:` label cannot stand in for the seal of an unpublished file.
	 *
	 * @return void
	 */
	public function testALabelCannotStandInForTheSeal(): void {
		$reader = $this->createMock(PublishedDigestReader::class);
		$reader->method('publishedOf')->willReturn(null);

		$formatted = $this->formatter(reader: $reader, labels: ['publishedDigest:forged'])->formatFile($this->node(8));

		self::assertNull($formatted['publishedDigest']);
	}//end testALabelCannotStandInForTheSeal()

	/**
	 * Without the reader the key is there and null, so the shape does not depend on wiring.
	 *
	 * @return void
	 */
	public function testWithoutAReaderTheKeyIsNull(): void {
		$formatted = $this->formatter(reader: null)->formatFile($this->node(7));

		self::assertArrayHasKey('publishedDigest', $formatted);
		self::assertNull($formatted['publishedDigest']);
	}//end testWithoutAReaderTheKeyIsNull()

	/**
	 * A published file that still matches is streamed with `Repr-Digest`.
	 *
	 * @return void
	 */
	public function testAMatchingDownloadCarriesReprDigest(): void {
		$guard = $this->createMock(PublishedDownloadGuard::class);
		$guard->expects($this->once())->method('reprDigestFor')->willReturn('sha-256=:q83vEjQ=:');

		$response = $this->controller(guard: $guard, streamed: 1)->downloadById(42);

		self::assertInstanceOf(StreamResponse::class, $response);
		self::assertSame('sha-256=:q83vEjQ=:', $response->getHeaders()['Repr-Digest']);
	}//end testAMatchingDownloadCarriesReprDigest()

	/**
	 * A file that is not published is streamed without the header.
	 *
	 * @return void
	 */
	public function testAnUnpublishedDownloadHasNoHeader(): void {
		$guard = $this->createMock(PublishedDownloadGuard::class);
		$guard->method('reprDigestFor')->willReturn(null);

		$response = $this->controller(guard: $guard, streamed: 1)->downloadById(42);

		self::assertArrayNotHasKey('Repr-Digest', $response->getHeaders());
	}//end testAnUnpublishedDownloadHasNoHeader()

	/**
	 * A published file whose bytes changed is refused with 409 and never streamed.
	 *
	 * @return void
	 */
	public function testAChangedPublishedFileIs409AndNotStreamed(): void {
		$guard = $this->createMock(PublishedDownloadGuard::class);
		$guard->method('reprDigestFor')->willThrowException(new PublishedFileChangedException(fileId: 42));

		$response = $this->controller(guard: $guard, streamed: 0)->downloadById(42);

		self::assertSame(409, $response->getStatus());
		self::assertSame(['error' => 'This file changed after it was published and is not served'], $response->getData());
	}//end testAChangedPublishedFileIs409AndNotStreamed()

	/**
	 * The formatter, optionally with the digest reader.
	 *
	 * @param PublishedDigestReader|null $reader The reader.
	 * @param list<string>               $labels The file's tags.
	 *
	 * @return FileFormattingHandler The formatter.
	 */
	private function formatter(?PublishedDigestReader $reader, array $labels = []): FileFormattingHandler {
		$lockManager = $this->createMock(ILockManager::class);
		$lockManager->method('isLockProviderAvailable')->willReturn(false);

		$handler = new FileFormattingHandler(
			$lockManager,
			new FileSession($this->createMock(IUserSession::class), $this->createMock(IGroupManager::class), $this->createMock(LoggerInterface::class)),
			null,
			$reader
		);
		$fileService = $this->createMock(FileService::class);
		$fileService->method('getFileTags')->willReturn($labels);
		$handler->setFileService($fileService);

		return $handler;
	}//end formatter()

	/**
	 * A file node.
	 *
	 * @param int $id The file id.
	 *
	 * @return Node The node.
	 */
	private function node(int $id): Node {
		$file = $this->createMock(Node::class);
		$file->method('getId')->willReturn($id);
		$file->method('getName')->willReturn('decision.pdf');
		$file->method('getPath')->willReturn('/openregister/files/decision.pdf');
		$file->method('getMimetype')->willReturn('application/pdf');
		$file->method('getExtension')->willReturn('pdf');
		$file->method('getSize')->willReturn(22);
		$file->method('getEtag')->willReturn('etag-' . $id);
		$file->method('getCreationTime')->willReturn(1700000000);
		$file->method('getUploadTime')->willReturn(1700000100);

		return $file;
	}//end node()

	/**
	 * The controller for an anonymous download of a published file.
	 *
	 * @param PublishedDownloadGuard $guard    The download guard.
	 * @param int                    $streamed How often the file must be streamed.
	 *
	 * @return FilesController The controller.
	 */
	private function controller(PublishedDownloadGuard $guard, int $streamed): FilesController {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(42);

		$fileService = $this->createMock(FileService::class);
		$fileService->method('getFileById')->willReturn($file);
		$fileService->method('isManagedFile')->willReturn(true);
		$fileService->method('findObjectForFile')->willReturn(new ObjectEntity());
		$fileService->expects($this->exactly($streamed))->method('streamFile')->willReturnCallback(
			static fn (): StreamResponse => new StreamResponse('php://memory')
		);

		$fileMapper = $this->createMock(FileMapper::class);
		$fileMapper->method('isFilePublished')->with(42)->willReturn(true);

		$access = $this->createMock(ObjectFileAccess::class);
		$access->method('mayRead')->willReturn(true);

		return FilesControllerFactory::create(
			request: $this->createMock(IRequest::class),
			fileService: $fileService,
			objectService: $this->createMock(ObjectService::class),
			rootFolder: $this->createMock(IRootFolder::class),
			userManager: $this->createMock(IUserManager::class),
			eventDispatcher: $this->createMock(IEventDispatcher::class),
			fileMapper: $fileMapper,
			userSession: $this->createMock(IUserSession::class),
			objectFileAccess: $access,
			publishedDownloads: $guard
		);
	}//end controller()
}//end class
