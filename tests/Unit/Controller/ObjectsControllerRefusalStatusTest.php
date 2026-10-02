<?php

declare(strict_types=1);

/**
 * ObjectsController::patch() optimistic-concurrency (409) unit tests.
 *
 * `patch()` is a read-merge-write operation: two concurrent PATCHes can
 * silently clobber each other's untouched fields. A caller may pass the
 * `updated` timestamp it read as `_expectedUpdated` (If-Match semantics).
 * If the stored object's `updated` no longer matches, the write is rejected
 * with HTTP 409 instead of overwriting the newer version. Callers that omit
 * `_expectedUpdated` keep the previous (last-write-wins) behaviour.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

namespace Unit\Controller;

use OCA\OpenRegister\Controller\ObjectsController;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\ExportService;
use OCA\OpenRegister\Service\ImportService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\WebhookService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for the `_expectedUpdated` optimistic-concurrency guard on
 * ObjectsController::patch().
 */
class ObjectsControllerRefusalStatusTest extends TestCase {
	private ObjectsController $controller;
	private IRequest&MockObject $request;
	private IAppConfig&MockObject $config;
	private IAppManager&MockObject $appManager;
	private ContainerInterface&MockObject $container;
	private RegisterMapper&MockObject $registerMapper;
	private SchemaMapper&MockObject $schemaMapper;
	private AuditTrailMapper&MockObject $auditTrailMapper;
	private ObjectService&MockObject $objectService;
	private IUserSession&MockObject $userSession;
	private IGroupManager&MockObject $groupManager;
	private ExportService&MockObject $exportService;
	private ImportService&MockObject $importService;
	private WebhookService&MockObject $webhookService;
	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->config = $this->createMock(IAppConfig::class);
		$this->appManager = $this->createMock(IAppManager::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->auditTrailMapper = $this->createMock(AuditTrailMapper::class);
		$this->objectService = $this->createMock(ObjectService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->exportService = $this->createMock(ExportService::class);
		$this->importService = $this->createMock(ImportService::class);
		$this->webhookService = $this->createMock(WebhookService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		// resolveRegisterSchemaIds() reuses the entities resolved by
		// ObjectService::setRegister()/setSchema(); the ObjectService mock
		// returns null from the entity getters by default, so entities stay
		// null and the numeric ids passed in tests ('1' / '2') are used as-is.
		$this->controller = new ObjectsController(
			'openregister',
			$this->request,
			$this->config,
			$this->appManager,
			$this->container,
			$this->registerMapper,
			$this->schemaMapper,
			$this->auditTrailMapper,
			$this->objectService,
			$this->userSession,
			$this->groupManager,
			$this->exportService,
			$this->importService,
			$this->webhookService,
			$this->logger
		);
	}//end setUp()

	private function setupAdminUser(): void {
		$user = $this->createMock(IUser::class);
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('getUserGroupIds')->willReturn(['admin']);
	}//end setupAdminUser()

	/**
	 * Arrange an existing object and a request body, as a caller the rules refuse.
	 *
	 * @return void
	 */
	private function arrangeRefusedCaller(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('pq-viewer');
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('getUserGroupIds')->willReturn([]);

		$existing = new ObjectEntity();
		$existing->setUuid('uuid-123');
		$existing->setRegister('1');
		$existing->setSchema('2');
		$existing->setObject(['title' => 'Project', 'members' => ['pq-owner']]);
		$existing->setUpdated(new \DateTime('2026-10-02T10:00:00+00:00'));

		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setSchema')->willReturnSelf();
		$this->objectService->method('getRegister')->willReturn(1);
		$this->objectService->method('getSchema')->willReturn(2);
		$this->objectService->method('findSilent')->willReturn($existing);

		$this->request->method('getParams')->willReturn(['members' => ['pq-owner', 'pq-viewer']]);
		$this->request->method('getHeader')->willReturn('application/json');
		$this->request->method('getParam')->willReturnCallback(static fn (string $key, $default = null) => $default);
	}//end arrangeRefusedCaller()

	/**
	 * The refusal PermissionHandler raises when the rules deny the update.
	 *
	 * @return NotAuthorizedException
	 */
	private function refusal(): NotAuthorizedException {
		return new NotAuthorizedException(
			message: "User 'pq-viewer' does not have permission to 'update' objects in schema 'Project'"
		);
	}//end refusal()

	/**
	 * A PATCH the rules refuse answers 403 with the reason, not a 500 (planninq live pass P4).
	 *
	 * @return void
	 */
	public function testARefusedPatchAnswers403(): void {
		$this->arrangeRefusedCaller();
		$this->objectService->method('saveObject')->willThrowException($this->refusal());

		$result = $this->controller->patch(register: '1', schema: '2', id: 'uuid-123', objectService: $this->objectService);

		$this->assertSame(403, $result->getStatus());
		$this->assertStringContainsString("permission to 'update'", (string) ($result->getData()['error'] ?? ''));
	}//end testARefusedPatchAnswers403()

	/**
	 * The multipart PATCH (POST with files) answers the same 403.
	 *
	 * @return void
	 */
	public function testARefusedPostPatchAnswers403(): void {
		$this->arrangeRefusedCaller();
		$this->objectService->method('saveObject')->willThrowException($this->refusal());

		$result = $this->controller->postPatch(register: '1', schema: '2', id: 'uuid-123', objectService: $this->objectService);

		$this->assertSame(403, $result->getStatus());
	}//end testARefusedPostPatchAnswers403()

	/**
	 * Control: a refused PUT already answers 403.
	 *
	 * @return void
	 */
	public function testARefusedPutAnswers403(): void {
		$this->arrangeRefusedCaller();
		$this->objectService->method('saveObject')->willThrowException($this->refusal());

		$result = $this->controller->update(register: '1', schema: '2', id: 'uuid-123', objectService: $this->objectService);

		$this->assertSame(403, $result->getStatus());
	}//end testARefusedPutAnswers403()

	/**
	 * Control: a refused DELETE already answers 403.
	 *
	 * @return void
	 */
	public function testARefusedDeleteAnswers403(): void {
		$this->arrangeRefusedCaller();
		$this->objectService->method('deleteObject')->willThrowException($this->refusal());

		$result = $this->controller->destroy(id: 'uuid-123', register: '1', schema: '2', objectService: $this->objectService);

		$this->assertSame(403, $result->getStatus());
	}//end testARefusedDeleteAnswers403()
}//end class
