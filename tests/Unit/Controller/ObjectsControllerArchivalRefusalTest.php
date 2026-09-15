<?php

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenRegister\Controller\ObjectsController;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\ArchivalImmutableException;
use OCA\OpenRegister\Service\ExportService;
use OCA\OpenRegister\Service\ImportService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\WebhookService;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
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
 * A refused delete on an archival schema answers the contract it published.
 *
 * `ArchivalImmutableException` carries `toResponseBody()` precisely so a client
 * can branch on `error`, name the `schema` and show the `hint`. DeletedController
 * and BulkController both catch it; ObjectsController did not, so on the endpoint
 * clients actually call the refusal fell through to the generic handler and
 * arrived as one flattened string. A client following the spec and testing
 * `body.error === 'SCHEMA_ARCHIVAL_IMMUTABLE'` matched nothing.
 *
 * @package Unit\Controller
 */
class ObjectsControllerArchivalRefusalTest extends TestCase {

	private ObjectsController $controller;
	private ObjectService&MockObject $objectService;

	/**
	 * Build the controller with the collaborators destroy() actually reaches.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($this->createMock(IUser::class));
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturn(['users']);

		$this->objectService = $this->createMock(ObjectService::class);

		$this->controller = new ObjectsController(
			'openregister',
			$this->createMock(IRequest::class),
			$this->createMock(IAppConfig::class),
			$this->createMock(IAppManager::class),
			$this->createMock(ContainerInterface::class),
			$this->createMock(RegisterMapper::class),
			$this->createMock(SchemaMapper::class),
			$this->createMock(AuditTrailMapper::class),
			$this->objectService,
			$userSession,
			$groupManager,
			$this->createMock(ExportService::class),
			$this->createMock(ImportService::class),
			$this->createMock(WebhookService::class),
			$this->createMock(LoggerInterface::class)
		);
	}

	/**
	 * The refusal answers 403 with the whole structured body, not a string.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/archival-annotation-vocabulary/spec.md
	 */
	public function testARefusedArchivalDeleteAnswersItsStructuredBody(): void {
		$this->objectService->method('deleteObject')->willThrowException(
			new ArchivalImmutableException(schemaIdentifier: 'call_log', operation: 'delete')
		);

		$response = $this->controller->destroy(
			id: 'abc',
			register: 'logs',
			schema: 'call_log',
			objectService: $this->objectService
		);
		$body = $response->getData();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertIsArray($body, 'the body must be the structured refusal, not a flattened string');
		$this->assertSame('SCHEMA_ARCHIVAL_IMMUTABLE', ($body['error'] ?? null));
		$this->assertSame('call_log', ($body['schema'] ?? null));
		$this->assertSame('delete', ($body['operation'] ?? null));
		$this->assertArrayHasKey('hint', $body, 'the hint tells the caller how to proceed lawfully');
		$this->assertArrayHasKey('message', $body);
	}
}//end class
