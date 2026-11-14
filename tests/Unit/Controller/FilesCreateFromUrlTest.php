<?php

/**
 * FilesController::create() with `source.url`: fetched behind the guard, checked like an upload,
 * created with its provenance, refused with 400 or 422 and nothing written.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 * @author   Conduction <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 *
 * @spec openspec/changes/files-create-from-url/specs
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use DateTime;
use DateTimeZone;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Files\Controller\FilesController;
use OCA\OpenRegister\Files\Controller\Support\FileEditing;
use OCA\OpenRegister\Files\Controller\Support\FileGuards;
use OCA\OpenRegister\Files\Controller\Support\FilePlatform;
use OCA\OpenRegister\Files\Controller\Support\FilesControllerServices;
use OCA\OpenRegister\Files\Controller\Support\FileStore;
use OCA\OpenRegister\Files\Service\File\FileAuditHandler;
use OCA\OpenRegister\Files\Service\File\ObjectFileAccess;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\File\FileTarget;
use OCA\OpenRegister\Service\File\FileUpload;
use OCA\OpenRegister\Service\File\FileValidationHandler;
use OCA\OpenRegister\Service\File\UrlFileFetcher;
use OCA\OpenRegister\Service\File\UrlFileSource;
use OCA\OpenRegister\Service\File\UrlStreamOpener;
use OCA\OpenRegister\Service\FileServiceInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\FilesMetadata\IFilesMetadataManager;
use OCP\FilesMetadata\Model\IFilesMetadata;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The opener (HTTP), the file service, the object services, the metadata
 * manager and the audit handler are stubs; the fetcher, the address guard and
 * the upload checks are the real ones.
 *
 * @covers \OCA\OpenRegister\Files\Controller\FilesController
 * @uses   \OCA\OpenRegister\Files\Controller\Support\FileAccessCheck
 * @uses   \OCA\OpenRegister\Files\Controller\Support\FileActionRefusal
 * @uses   \OCA\OpenRegister\Files\Controller\Support\FilesControllerServices
 * @uses   \OCA\OpenRegister\Files\Controller\Support\FileStore
 * @uses   \OCA\OpenRegister\Files\Controller\Support\FilePlatform
 * @uses   \OCA\OpenRegister\Files\Controller\Support\FileGuards
 * @uses   \OCA\OpenRegister\Files\Controller\Support\FileEditing
 * @uses   \OCA\OpenRegister\Files\Controller\Support\FileObjectRoute
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\TypedFieldsJson
 * @uses   \OCA\OpenRegister\Exception\NotAuthorizedException
 * @uses   \OCA\OpenRegister\Kernel\Contracts\ObjectRef
 * @uses   \OCA\OpenRegister\Service\File\FileTarget
 * @uses   \OCA\OpenRegister\Service\File\FileUpload
 * @uses   \OCA\OpenRegister\Service\File\FetchedFile
 * @uses   \OCA\OpenRegister\Service\File\UrlFileSource
 * @uses   \OCA\OpenRegister\Service\File\UrlFileFetcher
 * @uses   \OCA\OpenRegister\Service\File\UrlFetchRefused
 * @uses   \OCA\OpenRegister\Service\File\FileValidationHandler
 * @uses   \OCA\OpenRegister\Service\File\ExecutableContentDetector
 * @uses   \OCA\OpenRegister\Service\ApiVersion\ApiCapabilitiesService
 * @uses   \OCA\OpenRegister\Service\SecurityService
 * @uses   \OCA\OpenRegister\Service\Security\SafeFetchUrl
 * @uses   \OCA\OpenRegister\Service\Security\OutboundAddressGuard
 */
class FilesCreateFromUrlTest extends TestCase {

	/**
	 * A public address, so the guard needs no name lookup.
	 */
	private const PUBLIC_URL = 'https://93.184.215.14/besluit.pdf';

	/**
	 * The opener.
	 *
	 * @var UrlStreamOpener&MockObject
	 */
	private UrlStreamOpener&MockObject $opener;

	/**
	 * The fileService.
	 *
	 * @var FileServiceInterface&MockObject
	 */
	private FileServiceInterface&MockObject $fileService;

	/**
	 * The access.
	 *
	 * @var ObjectFileAccess&MockObject
	 */
	private ObjectFileAccess&MockObject $access;

	/**
	 * The audit.
	 *
	 * @var FileAuditHandler&MockObject
	 */
	private FileAuditHandler&MockObject $audit;

	/**
	 * The metadataManager.
	 *
	 * @var IFilesMetadataManager&MockObject
	 */
	private IFilesMetadataManager&MockObject $metadataManager;

	/**
	 * The object.
	 *
	 * @var ObjectEntity
	 */
	private ObjectEntity $object;

	protected function setUp(): void {
		$this->opener = $this->createMock(UrlStreamOpener::class);
		$this->fileService = $this->createMock(FileServiceInterface::class);
		$this->access = $this->createMock(ObjectFileAccess::class);
		$this->audit = $this->createMock(FileAuditHandler::class);
		$this->metadataManager = $this->createMock(IFilesMetadataManager::class);
		$this->object = new ObjectEntity();
		$this->object->setId(11);
		$this->object->setUuid('obj-uuid');
	}

	/**
	 * @param array<string, mixed> $params The request body.
	 *
	 * @return FilesController The controller.
	 */
	private function controller(array $params): FilesController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);

		$objects = $this->createMock(ObjectOperationsInterface::class);
		$objects->method('getObject')->willReturn($this->object);

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($this->createMock(IUser::class));

		$clock = $this->createMock(ITimeFactory::class);
		$clock->method('getDateTime')->willReturn(new DateTime('2026-10-05T09:30:00', new DateTimeZone('UTC')));

		$source = new UrlFileSource(
			new UrlFileFetcher($this->opener),
			new FileValidationHandler($this->createMock(LoggerInterface::class)),
			$this->metadataManager,
			$clock,
			$this->createMock(LoggerInterface::class),
		);

		return new FilesController(
			$request,
			new FilesControllerServices(
				store: new FileStore(
					fileService: $this->fileService,
					objectService: $objects,
					rootFolder: $this->createMock(IRootFolder::class),
					urlFileSource: $source,
				),
				platform: new FilePlatform(
					userManager: $this->createMock(IUserManager::class),
					eventDispatcher: $this->createMock(IEventDispatcher::class),
					userSession: $session,
				),
				guards: new FileGuards(objectFileAccess: $this->access, fileAuditHandler: $this->audit),
				editing: new FileEditing(),
			)
		);
	}

	private function serve(int $status, string $body): void {
		$stream = fopen('php://memory', 'w+b');
		fwrite($stream, $body);
		rewind($stream);
		$this->opener->expects($this->once())->method('open')->with(self::PUBLIC_URL)->willReturn(['stream' => $stream, 'status' => $status]);
	}

	private function mayChange(): void {
		$this->access->method('changeable')->willReturn($this->object);
		$this->access->method('readable')->willReturn($this->object);
	}

	public function testAFileIsCreatedFromAUrl(): void {
		$this->mayChange();
		$this->serve(200, 'aGVsbG8=');
		$created = $this->createMock(File::class);
		$created->method('getId')->willReturn(77);
		$written = null;
		$this->fileService->expects($this->once())->method('addFile')->willReturnCallback(
			function (FileTarget $target, FileUpload $upload) use (&$written, $created): File {
				$this->assertSame($this->object, $target->object);
				$this->assertSame('besluit.pdf', $upload->fileName);
				$this->assertTrue($upload->share);
				$this->assertSame(['woo'], $upload->tags);
				// A stream, so bytes that read as base64 are stored as they came.
				$this->assertIsResource($upload->content);
				$written = stream_get_contents($upload->content);
				return $created;
			}
		);
		$this->fileService->method('formatFile')->with($created)->willReturn(['id' => 77, 'title' => 'besluit.pdf']);
		$metadata = $this->createMock(IFilesMetadata::class);
		$this->metadataManager->expects($this->once())->method('getMetadata')->with(77, true)->willReturn($metadata);
		$this->metadataManager->expects($this->once())->method('saveMetadata')->with($metadata);
		$provenance = [
			'sourceUrl' => self::PUBLIC_URL,
			'fetchedAt' => '2026-10-05T09:30:00+00:00',
			'sha256'    => hash('sha256', 'aGVsbG8='),
		];
		$this->audit->expects($this->once())->method('logFileAction')->with($this->object, 77, 'file.fetched', $provenance);

		$params = ['name' => 'besluit.pdf', 'source' => ['url' => self::PUBLIC_URL], 'share' => true, 'tags' => ['woo']];
		$response = $this->controller($params)->create('reg', 'sch', 'obj');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['id' => 77, 'title' => 'besluit.pdf', 'metadata' => $provenance], $response->getData());
		$this->assertSame('aGVsbG8=', $written);
	}

	public function testContentAndSourceTogetherAreRefused(): void {
		$this->mayChange();
		$this->opener->expects($this->never())->method('open');
		$this->fileService->expects($this->never())->method('addFile');

		$response = $this->controller(['name' => 'a.pdf', 'content' => 'x', 'source' => ['url' => self::PUBLIC_URL]])->create('reg', 'sch', 'obj');

		$this->assertSame(400, $response->getStatus());
		$this->assertSame(['error' => 'Send either content or source, not both'], $response->getData());
	}

	public function testNeitherContentNorSourceIsRefused(): void {
		$this->mayChange();
		$this->fileService->expects($this->never())->method('addFile');

		$response = $this->controller(['name' => 'a.pdf'])->create('reg', 'sch', 'obj');

		$this->assertSame(400, $response->getStatus());
		$this->assertSame(['error' => 'File content is required'], $response->getData());
	}

	public function testASourceWithoutAUrlIsRefused(): void {
		$this->mayChange();
		$this->opener->expects($this->never())->method('open');
		$this->fileService->expects($this->never())->method('addFile');

		foreach ([['url' => ''], 'https://example.org/x.pdf', ['url' => ['nested']]] as $source) {
			$response = $this->controller(['name' => 'a.pdf', 'source' => $source])->create('reg', 'sch', 'obj');

			$this->assertSame(400, $response->getStatus());
			$this->assertSame(['error' => 'File source must hold a url'], $response->getData());
		}
	}

	public function testAFailedFetchCreatesNoFile(): void {
		$this->mayChange();
		$this->serve(404, 'not here');
		$this->fileService->expects($this->never())->method('addFile');
		$this->audit->expects($this->never())->method('logFileAction');

		$response = $this->controller(['name' => 'a.pdf', 'source' => ['url' => self::PUBLIC_URL]])->create('reg', 'sch', 'obj');

		$this->assertSame(422, $response->getStatus());
		$this->assertSame(['error' => 'The URL answered with status 404.', 'reason' => 'status'], $response->getData());
	}

	public function testAPrivateAddressIsRefused(): void {
		$this->mayChange();
		$this->opener->expects($this->never())->method('open');
		$this->fileService->expects($this->never())->method('addFile');

		$response = $this->controller(['name' => 'a.pdf', 'source' => ['url' => 'http://169.254.169.254/latest/meta-data']])->create('reg', 'sch', 'obj');

		$this->assertSame(422, $response->getStatus());
		$this->assertSame('refused-address', $response->getData()['reason']);
	}

	public function testARedirectIsNotFollowed(): void {
		$this->mayChange();
		$this->serve(302, '');
		$this->fileService->expects($this->never())->method('addFile');

		$response = $this->controller(['name' => 'a.pdf', 'source' => ['url' => self::PUBLIC_URL]])->create('reg', 'sch', 'obj');

		$this->assertSame(422, $response->getStatus());
		$this->assertSame(['error' => 'The URL answered with status 302.', 'reason' => 'status'], $response->getData());
	}

	public function testTheCeilingStopsTheReadNotTheWrite(): void {
		$this->mayChange();
		$this->serve(200, str_repeat('x', UrlFileSource::ceilingBytes() + 1));
		$this->fileService->expects($this->never())->method('addFile');

		$response = $this->controller(['name' => 'a.pdf', 'source' => ['url' => self::PUBLIC_URL]])->create('reg', 'sch', 'obj');

		$this->assertSame(422, $response->getStatus());
		$this->assertSame('too-large', $response->getData()['reason']);
	}

	public function testFetchedExecutableBytesAreRefusedLikeAnUpload(): void {
		$this->mayChange();
		$this->serve(200, "\x7fELF\x02\x01\x01" . str_repeat("\x00", 32));
		$this->fileService->expects($this->never())->method('addFile');

		$response = $this->controller(['name' => 'a.pdf', 'source' => ['url' => self::PUBLIC_URL]])->create('reg', 'sch', 'obj');

		$this->assertSame(400, $response->getStatus());
		$this->assertMatchesRegularExpression('/executable/i', $response->getData()['error']);
	}

	public function testObjectAccessIsCheckedFirst(): void {
		$this->access->method('changeable')->willThrowException(new NotAuthorizedException('no'));
		$this->access->method('readable')->willThrowException(new NotAuthorizedException('no'));
		$this->opener->expects($this->never())->method('open');
		$this->fileService->expects($this->never())->method('addFile');

		$response = $this->controller(['name' => 'a.pdf', 'source' => ['url' => self::PUBLIC_URL]])->create('reg', 'sch', 'obj');

		$this->assertSame(403, $response->getStatus());
	}

	public function testPlainContentIsStillWrittenAsContent(): void {
		$this->mayChange();
		$this->opener->expects($this->never())->method('open');
		$created = $this->createMock(File::class);
		$this->fileService->expects($this->once())->method('addFile')->with(
			$this->anything(),
			$this->callback(static fn (FileUpload $upload): bool => $upload->content === 'https://example.org/x.pdf')
		)->willReturn($created);
		$this->fileService->method('formatFile')->willReturn(['id' => 5]);

		$response = $this->controller(['name' => 'a.txt', 'content' => 'https://example.org/x.pdf'])->create('reg', 'sch', 'obj');

		$this->assertSame(['id' => 5], $response->getData());
	}
}
