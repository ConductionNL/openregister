<?php

/**
 * The upload-in-parts endpoints ask the object rule first, and a session is its opener's alone.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/large-file-handling/specs/file-actions/spec.md#requirement-a-large-file-uploads-in-parts-with-stated-boundaries-req-lfh-002
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use DateTime;
use DateTimeImmutable;
use OCA\OpenRegister\Controller\ChunkedUploadController;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Db\UploadSession;
use OCA\OpenRegister\Db\UploadSessionMapper;
use OCA\OpenRegister\Exception\ObjectStateWriteException;
use OCA\OpenRegister\Files\Controller\Support\ChunkedUploadControllerServices;
use OCA\OpenRegister\Files\Controller\Support\RawRequestBody;
use OCA\OpenRegister\Files\Service\File\ObjectFileAccess;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\File\ChunkedUploadService;
use OCA\OpenRegister\Service\FileReaderInterface;
use OCA\OpenRegister\Service\FileWriterInterface;
use OCA\OpenRegister\Service\Object\FileWriteGuardInterface;
use OCA\OpenRegister\Service\Object\PermissionAsk;
use OCA\OpenRegister\Service\Object\PermissionHandlerInterface;
use OCA\OpenRegister\Service\Settings\FileSettingsHandler;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\AppData\IAppDataFactory;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\OpenRegister\Controller\ChunkedUploadController
 * @uses   \OCA\OpenRegister\Files\Controller\Support\ChunkedUploadControllerServices
 * @uses   \OCA\OpenRegister\Files\Controller\Support\FileActionRefusal
 * @uses   \OCA\OpenRegister\Files\Service\File\ObjectFileAccess
 * @uses   \OCA\OpenRegister\Files\Service\File\FileVerbCheck
 * @uses   \OCA\OpenRegister\Service\File\ChunkedUploadService
 * @uses   \OCA\OpenRegister\Service\Settings\FileSettingsHandler
 * @uses   \OCA\OpenRegister\Db\UploadSession
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Exception\ObjectFileAccessDeniedException
 * @uses   \OCA\OpenRegister\Kernel\Contracts\ObjectRef
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions
 * @uses   \OCA\OpenRegister\Service\WriteCause
 */
class ChunkedUploadControllerTest extends TestCase {

	/**
	 * Whether the update rule lets the caller change the object.
	 *
	 * @var bool
	 */
	private bool $mayUpdate = true;

	/**
	 * Whether the caller may read the object at all.
	 *
	 * @var bool
	 */
	private bool $mayRead = true;

	/**
	 * The signed-in user.
	 *
	 * @var string
	 */
	private string $user = 'user-a';

	/**
	 * The sessions table.
	 *
	 * @var UploadSessionMapper&MockObject
	 */
	private UploadSessionMapper&MockObject $sessions;

	/**
	 * The app data factory; never asked when nothing is stored.
	 *
	 * @var IAppDataFactory&MockObject
	 */
	private IAppDataFactory&MockObject $appData;

	/**
	 * The write guard.
	 *
	 * @var FileWriteGuardInterface&MockObject
	 */
	private FileWriteGuardInterface&MockObject $writeGuard;

	protected function setUp(): void {
		parent::setUp();
		$this->sessions   = $this->createMock(UploadSessionMapper::class);
		$this->appData    = $this->createMock(IAppDataFactory::class);
		$this->writeGuard = $this->createMock(FileWriteGuardInterface::class);
	}//end setUp()

	/**
	 * The controller over the real object rule and the real upload service.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 *
	 * @return ChunkedUploadController
	 */
	private function controller(array $params = []): ChunkedUploadController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);

		$object = new ObjectEntity();
		$object->setUuid('5f0c0a1e-0000-4000-8000-000000000001');
		$object->setSchema('7');
		$objects = $this->createMock(ObjectOperationsInterface::class);
		$objects->method('find')->willReturnCallback(fn () => ($this->mayRead === true ? $object : null));
		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->method('find')->willReturn(new Schema());
		$permissions = $this->createMock(PermissionHandlerInterface::class);
		$permissions->method('hasPermission')->willReturnCallback(
			fn (PermissionAsk $ask): bool => ($ask->action === 'update' && $this->mayUpdate === true)
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturnCallback(fn (): string => $this->user);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$clock = $this->createMock(ITimeFactory::class);
		$clock->method('now')->willReturn(new DateTimeImmutable('2026-11-14T12:00:00+00:00'));

		$body = $this->createMock(RawRequestBody::class);
		$body->method('read')->willReturn('aaaaaaaaaa');

		return new ChunkedUploadController(
			request: $request,
			services: new ChunkedUploadControllerServices(
				uploads: new ChunkedUploadService(
					sessions: $this->sessions,
					appDataFactory: $this->appData,
					settings: new FileSettingsHandler(appConfig: $this->createMock(IAppConfig::class)),
					files: $this->createMock(FileWriterInterface::class),
					clock: $clock,
					logger: new NullLogger()
				),
				access: new ObjectFileAccess(objectService: $objects, permissionHandler: $permissions, schemaMapper: $schemas),
				writeGuard: $this->writeGuard,
				userSession: $session,
				fileReader: $this->createMock(FileReaderInterface::class),
				body: $body,
				logger: new NullLogger()
			)
		);
	}//end controller()

	/**
	 * Opening needs update on the object: a reader gets 403, a stranger 404,
	 * and neither opens a session; an updater gets the plan.
	 *
	 * @return void
	 */
	public function testOpeningNeedsUpdateRightsOnTheObject(): void {
		$declared = ['name' => 'scan.pdf', 'size' => 25, 'sha256' => str_repeat('a', 64)];
		$this->sessions->expects($this->once())->method('insert')->willReturnArgument(0);

		$this->mayUpdate = false;
		$reader = $this->controller(params: $declared)->open(register: 'woo', schema: 'dossier', id: '5f0c0a1e-0000-4000-8000-000000000001');
		$this->assertSame([403, ['error' => 'You do not have access to this object']], [$reader->getStatus(), $reader->getData()]);

		$this->mayRead = false;
		$stranger = $this->controller(params: $declared)->open(register: 'woo', schema: 'dossier', id: '5f0c0a1e-0000-4000-8000-000000000001');
		$this->assertSame([404, ['error' => 'Object not found']], [$stranger->getStatus(), $stranger->getData()]);

		$this->mayRead = true;
		$this->mayUpdate = true;
		$updater = $this->controller(params: $declared)->open(register: 'woo', schema: 'dossier', id: '5f0c0a1e-0000-4000-8000-000000000001');
		$this->assertSame(201, $updater->getStatus());
		$this->assertSame([10485760, 1, [['n' => 1, 'start' => 0, 'end' => 24]]], [$updater->getData()['partSize'], $updater->getData()['partCount'], $updater->getData()['parts']]);
	}//end testOpeningNeedsUpdateRightsOnTheObject()

	/**
	 * User B sending a part, reading or completing user A's session gets 404, and nothing is stored.
	 *
	 * @return void
	 */
	public function testAnotherUserGets404OnTheSession(): void {
		$session = new UploadSession();
		$session->setUploadId('0f6c2b6e-1d2a-4c3b-9e8f-7a6b5c4d3e2f');
		$session->setUserId('user-a');
		$session->setObjectUuid('5f0c0a1e-0000-4000-8000-000000000001');
		$session->setSize(25);
		$session->setPartSize(10);
		$session->setExpiresAt(new DateTime('2026-11-15T12:00:00+00:00'));
		$this->sessions->method('findByUploadId')->willReturn($session);
		$this->sessions->expects($this->never())->method('update');
		$this->sessions->expects($this->never())->method('delete');
		$this->appData->expects($this->never())->method('get');
		$this->user = 'user-b';
		$controller = $this->controller();

		$responses = [
			$controller->part(register: 'woo', schema: 'dossier', id: '5f0c0a1e-0000-4000-8000-000000000001', uploadId: '0f6c2b6e-1d2a-4c3b-9e8f-7a6b5c4d3e2f', n: 1),
			$controller->status(register: 'woo', schema: 'dossier', id: '5f0c0a1e-0000-4000-8000-000000000001', uploadId: '0f6c2b6e-1d2a-4c3b-9e8f-7a6b5c4d3e2f'),
			$controller->complete(register: 'woo', schema: 'dossier', id: '5f0c0a1e-0000-4000-8000-000000000001', uploadId: '0f6c2b6e-1d2a-4c3b-9e8f-7a6b5c4d3e2f'),
		];

		foreach ($responses as $response) {
			$this->assertSame([404, ['error' => 'Upload session not found']], [$response->getStatus(), $response->getData()]);
		}

		$this->assertSame([], $session->receivedParts());
	}//end testAnotherUserGets404OnTheSession()

	/**
	 * The opener reads their session's status; an incomplete one cannot complete (409).
	 *
	 * @return void
	 */
	public function testTheOpenerReadsStatusAndCannotCompleteEarly(): void {
		$session = new UploadSession();
		$session->setUploadId('0f6c2b6e-1d2a-4c3b-9e8f-7a6b5c4d3e2f');
		$session->setUserId('user-a');
		$session->setObjectUuid('5f0c0a1e-0000-4000-8000-000000000001');
		$session->setSize(25);
		$session->setPartSize(10);
		$session->setReceived('[2]');
		$session->setExpiresAt(new DateTime('2026-11-15T12:00:00+00:00'));
		$this->sessions->method('findByUploadId')->willReturn($session);
		$controller = $this->controller();

		$status = $controller->status(register: 'woo', schema: 'dossier', id: '5f0c0a1e-0000-4000-8000-000000000001', uploadId: '0f6c2b6e-1d2a-4c3b-9e8f-7a6b5c4d3e2f');
		$this->assertSame([200, [2], [1, 3], false], [$status->getStatus(), $status->getData()['received'], $status->getData()['missing'], $status->getData()['complete']]);

		$complete = $controller->complete(register: 'woo', schema: 'dossier', id: '5f0c0a1e-0000-4000-8000-000000000001', uploadId: '0f6c2b6e-1d2a-4c3b-9e8f-7a6b5c4d3e2f');
		$this->assertSame([409, ['error' => 'The upload is not complete; missing parts: 1, 3']], [$complete->getStatus(), $complete->getData()]);
	}//end testTheOpenerReadsStatusAndCannotCompleteEarly()

	/**
	 * A frozen or archived object refuses an upload as it refuses any file write.
	 *
	 * @return void
	 */
	public function testAFrozenObjectRefusesTheUpload(): void {
		$this->sessions->expects($this->never())->method('insert');
		$refusal = $this->createMock(ObjectStateWriteException::class);
		$refusal->method('toResponseBody')->willReturn(['error' => 'frozen']);
		$this->writeGuard->method('assertWritable')->willThrowException($refusal);

		$response = $this->controller(params: ['name' => 'a', 'size' => 1, 'sha256' => str_repeat('a', 64)])
			->open(register: 'woo', schema: 'dossier', id: '5f0c0a1e-0000-4000-8000-000000000001');

		$this->assertSame([409, ['error' => 'frozen']], [$response->getStatus(), $response->getData()]);
	}//end testAFrozenObjectRefusesTheUpload()
}//end class
