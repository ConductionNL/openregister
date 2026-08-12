<?php

/**
 * Unit tests for `FileTextController::addManualEntity`.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests\Unit\Controller
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author  Conduction Development Team <dev@conduction.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenRegister\Controller\FileTextController;
use OCA\OpenRegister\Db\EntityRelation;
use OCA\OpenRegister\Db\EntityRelationMapper;
use OCA\OpenRegister\Db\GdprEntity;
use OCA\OpenRegister\Exception\ManualEntityException;
use OCA\OpenRegister\Service\File\ManualEntityResult;
use OCA\OpenRegister\Service\File\ManualEntityService;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\TextExtractionService;
use OCP\AppFramework\Http;
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
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Targets each HTTP-mapping branch in `addManualEntity` +
 * `mapManualEntityException` + `formatManualEntityResponse`.
 *
 * Lives in its own sibling file (alongside `Coverage`, `Deep`) so the
 * core controller test stays focused on the legacy endpoints.
 */
class FileTextControllerManualEntityTest extends TestCase {

	/**
	 * SUT under test.
	 *
	 * @var FileTextController
	 */
	private FileTextController $controller;

	/**
	 * Request mock used for content-type + body parsing.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Logger mock used to assert PII-redacted request logging.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Orchestration service mock — the controller's delegate.
	 *
	 * @var ManualEntityService&MockObject
	 */
	private ManualEntityService&MockObject $manualEntityService;

	/**
	 * Session-user lookup mock.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * Boot the SUT with all dependencies mocked.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(originalClassName: IRequest::class);
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);
		$this->manualEntityService = $this->createMock(originalClassName: ManualEntityService::class);
		$this->userSession = $this->createMock(originalClassName: IUserSession::class);

		$rootFolder = $this->createMock(originalClassName: IRootFolder::class);
		$groupManager = $this->createMock(originalClassName: IGroupManager::class);
		$userFolder = $this->createMock(originalClassName: Folder::class);
		$userFolder->method('getById')->willReturn([$this->createMock(originalClassName: Node::class)]);
		$rootFolder->method('getUserFolder')->willReturn($userFolder);
		$groupManager->method('isAdmin')->willReturn(true);

		$this->controller = new FileTextController(
			'openregister',
			$this->request,
			$this->createMock(originalClassName: TextExtractionService::class),
			$this->createMock(originalClassName: FileService::class),
			$this->createMock(originalClassName: EntityRelationMapper::class),
			$this->logger,
			$this->createMock(originalClassName: IAppConfig::class),
			$this->manualEntityService,
			$this->userSession,
			$rootFolder,
			$groupManager
		);

	}//end setUp()

	/**
	 * Configure the request mock to report a JSON content-type and
	 * return the given parsed body.
	 *
	 * @param array<string,mixed> $body Parsed body the controller will read.
	 * @param string $contentType Override the Content-Type header (default JSON).
	 *
	 * @return void
	 */
	private function setupRequest(array $body, string $contentType = 'application/json'): void {
		$this->request->method('getHeader')
			->with('Content-Type')
			->willReturn(value: $contentType);

		$this->request->method('getParams')->willReturn(value: $body);

	}//end setupRequest()

	/**
	 * Wire an authenticated user into the session mock.
	 *
	 * @return IUser&MockObject
	 */
	private function setupAuthenticatedUser(): IUser&MockObject {
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn(value: 'op1');
		$this->userSession->method('getUser')->willReturn(value: $user);

		return $user;
	}//end setupAuthenticatedUser()

	/**
	 * Non-JSON content-type → 415.
	 *
	 * @return void
	 */
	public function testWrongContentTypeReturns415(): void {
		$this->request->method('getHeader')->willReturn(value: 'text/plain');

		$this->manualEntityService->expects($this->never())->method('addManualEntity');

		$response = $this->controller->addManualEntity(fileId: 42);

		$this->assertSame(expected: Http::STATUS_UNSUPPORTED_MEDIA_TYPE, actual: $response->getStatus());
		$this->assertSame(expected: 'unsupported_media_type', actual: $response->getData()['error']);

	}//end testWrongContentTypeReturns415()

	/**
	 * No session user → 401.
	 *
	 * @return void
	 */
	public function testUnauthenticatedReturns401(): void {
		$this->request->method('getHeader')->willReturn(value: 'application/json');
		$this->userSession->method('getUser')->willReturn(value: null);

		$response = $this->controller->addManualEntity(fileId: 42);

		$this->assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $response->getStatus());
		$this->assertSame(expected: 'unauthenticated', actual: $response->getData()['error']);

	}//end testUnauthenticatedReturns401()

	/**
	 * Missing `value` → 400 with `invalid_request` + field hint.
	 *
	 * @return void
	 */
	public function testMissingValueReturns400(): void {
		$this->setupAuthenticatedUser();
		$this->setupRequest(body: ['type' => 'PERSON']);

		$response = $this->controller->addManualEntity(fileId: 42);

		$this->assertSame(expected: Http::STATUS_BAD_REQUEST, actual: $response->getStatus());
		$this->assertSame(expected: 'invalid_request', actual: $response->getData()['error']);
		$this->assertSame(expected: 'value', actual: $response->getData()['field']);

	}//end testMissingValueReturns400()

	/**
	 * Missing `type` → 400 with `invalid_request` + field hint.
	 *
	 * @return void
	 */
	public function testMissingTypeReturns400(): void {
		$this->setupAuthenticatedUser();
		$this->setupRequest(body: ['value' => 'Jan Jansen']);

		$response = $this->controller->addManualEntity(fileId: 42);

		$this->assertSame(expected: Http::STATUS_BAD_REQUEST, actual: $response->getStatus());
		$this->assertSame(expected: 'type', actual: $response->getData()['field']);

	}//end testMissingTypeReturns400()

	/**
	 * ADR-005: the request log MUST NOT contain the operator-supplied
	 * `value`. Confirm via spy on the logger.
	 *
	 * @return void
	 */
	public function testRequestLogRedactsValue(): void {
		$this->setupAuthenticatedUser();
		$this->setupRequest(body: ['value' => 'SECRETIDENTITY', 'type' => 'PERSON']);

		$this->logger->expects($this->atLeastOnce())
			->method('info')
			->willReturnCallback(
				callback: function (string $message, array $context): void {
					// The `value` itself must not appear in the message or context.
					$this->assertStringNotContainsString(needle: 'SECRETIDENTITY', haystack: $message);
					foreach ($context as $v) {
						$this->assertNotSame(expected: 'SECRETIDENTITY', actual: $v);
					}

					if (array_key_exists(key: 'valueLength', array: $context) === true) {
						$this->assertSame(expected: 14, actual: $context['valueLength']);
					}
				}
			);

		// Have the service throw any reason so the call short-circuits
		// without needing to fabricate a full result.
		$this->manualEntityService->method('addManualEntity')->willThrowException(
			exception: new ManualEntityException(
				reason: ManualEntityException::REASON_FILE_NOT_EXTRACTED,
				message: 'nothing here'
			)
		);

		$this->controller->addManualEntity(fileId: 42);

	}//end testRequestLogRedactsValue()

	/**
	 * Service throws `REASON_FORBIDDEN` → 403.
	 *
	 * @return void
	 */
	public function testForbiddenMapsTo403(): void {
		$this->setupAuthenticatedUser();
		$this->setupRequest(body: ['value' => 'Jan', 'type' => 'PERSON']);

		$this->manualEntityService->method('addManualEntity')->willThrowException(
			exception: new ManualEntityException(
				reason: ManualEntityException::REASON_FORBIDDEN,
				message: 'write access to file required'
			)
		);

		$response = $this->controller->addManualEntity(fileId: 42);

		$this->assertSame(expected: Http::STATUS_FORBIDDEN, actual: $response->getStatus());
		$this->assertSame(expected: 'forbidden', actual: $response->getData()['error']);

	}//end testForbiddenMapsTo403()

	/**
	 * REASON_FILE_NOT_EXTRACTED → 422.
	 *
	 * @return void
	 */
	public function testFileNotExtractedMapsTo422(): void {
		$this->setupAuthenticatedUser();
		$this->setupRequest(body: ['value' => 'Jan', 'type' => 'PERSON']);

		$this->manualEntityService->method('addManualEntity')->willThrowException(
			exception: new ManualEntityException(
				reason: ManualEntityException::REASON_FILE_NOT_EXTRACTED,
				message: 'no chunks'
			)
		);

		$response = $this->controller->addManualEntity(fileId: 42);

		$this->assertSame(expected: Http::STATUS_UNPROCESSABLE_ENTITY, actual: $response->getStatus());

	}//end testFileNotExtractedMapsTo422()

	/**
	 * REASON_REGEX_COMPILE_FAILURE → 400 (catches both compile errors
	 * and value-too-long upstream).
	 *
	 * @return void
	 */
	public function testRegexCompileFailureMapsTo400(): void {
		$this->setupAuthenticatedUser();
		$this->setupRequest(body: ['value' => str_repeat(string: 'A', times: 300), 'type' => 'PERSON']);

		$this->manualEntityService->method('addManualEntity')->willThrowException(
			exception: new ManualEntityException(
				reason: ManualEntityException::REASON_REGEX_COMPILE_FAILURE,
				message: 'too long'
			)
		);

		$response = $this->controller->addManualEntity(fileId: 42);

		$this->assertSame(expected: Http::STATUS_BAD_REQUEST, actual: $response->getStatus());

	}//end testRegexCompileFailureMapsTo400()

	/**
	 * Unknown `Throwable` from the service → 500 with a generic
	 * `internal_error` body (never leaks the raw message).
	 *
	 * @return void
	 */
	public function testUnexpectedThrowableMapsTo500(): void {
		$this->setupAuthenticatedUser();
		$this->setupRequest(body: ['value' => 'Jan', 'type' => 'PERSON']);

		$this->manualEntityService->method('addManualEntity')->willThrowException(
			exception: new RuntimeException(message: 'unexpected database explosion')
		);

		$response = $this->controller->addManualEntity(fileId: 42);

		$this->assertSame(expected: Http::STATUS_INTERNAL_SERVER_ERROR, actual: $response->getStatus());
		$this->assertSame(expected: 'internal_error', actual: $response->getData()['error']);
		// The raw exception message MUST NOT leak in the response.
		$body = json_encode($response->getData());
		$this->assertStringNotContainsString(needle: 'unexpected database explosion', haystack: (string)$body);

	}//end testUnexpectedThrowableMapsTo500()

	/**
	 * Happy path: service returns a result with one match → 201 with
	 * the documented response shape.
	 *
	 * @return void
	 */
	public function testSuccessReturns201WithBody(): void {
		$this->setupAuthenticatedUser();
		$this->setupRequest(body: ['value' => 'Jan Jansen', 'type' => 'PERSON']);

		$entity = new GdprEntity();
		$entity->setId(7);
		$entity->setUuid('uuid-7');
		$entity->setValue('Jan Jansen');
		$entity->setType('PERSON');

		$relation = new EntityRelation();
		$relation->setId(200);
		$relation->setChunkId(10);
		$relation->setPositionStart(13);
		$relation->setPositionEnd(23);
		$relation->setContext('... Jan Jansen ...');

		$this->manualEntityService->method('addManualEntity')->willReturn(
			value: new ManualEntityResult(
				entity: $entity,
				entityWasNew: true,
				relations: [$relation],
				matchCount: 1,
				matchesSkipped: 0
			)
		);

		$response = $this->controller->addManualEntity(fileId: 42);

		$this->assertSame(expected: Http::STATUS_CREATED, actual: $response->getStatus());
		$data = $response->getData();
		$this->assertSame(expected: 7, actual: $data['entity']['id']);
		$this->assertSame(expected: 'uuid-7', actual: $data['entity']['uuid']);
		$this->assertFalse(condition: $data['entity']['reused']);
		$this->assertCount(expectedCount: 1, haystack: $data['relations']);
		$this->assertSame(expected: 200, actual: $data['relations'][0]['id']);
		$this->assertSame(expected: 1, actual: $data['matchCount']);
		$this->assertSame(expected: 0, actual: $data['matchesSkipped']);
		// No `uuid` on relation rows (table has no uuid column).
		$this->assertArrayNotHasKey(key: 'uuid', array: $data['relations'][0]);

	}//end testSuccessReturns201WithBody()

	/**
	 * Zero-match path: service returns matchCount=0 → 200 + `message`
	 * field plus the entity payload.
	 *
	 * @return void
	 */
	public function testZeroMatchReturns200WithMessage(): void {
		$this->setupAuthenticatedUser();
		$this->setupRequest(body: ['value' => 'NotInFile', 'type' => 'PERSON']);

		$entity = new GdprEntity();
		$entity->setId(9);
		$entity->setUuid('uuid-9');
		$entity->setValue('NotInFile');
		$entity->setType('PERSON');

		$this->manualEntityService->method('addManualEntity')->willReturn(
			value: new ManualEntityResult(
				entity: $entity,
				entityWasNew: false,
				relations: [],
				matchCount: 0,
				matchesSkipped: 0
			)
		);

		$response = $this->controller->addManualEntity(fileId: 42);

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$data = $response->getData();
		$this->assertSame(expected: 0, actual: $data['matchCount']);
		$this->assertTrue(condition: $data['entity']['reused']);
		$this->assertArrayHasKey(key: 'message', array: $data);
		$this->assertStringContainsString(needle: 'not found', haystack: $data['message']);

	}//end testZeroMatchReturns200WithMessage()
}//end class
