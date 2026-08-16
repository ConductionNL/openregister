<?php

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\WorkflowEngineController;
use OCA\OpenRegister\Service\WorkflowEngineRegistry;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class WorkflowEngineControllerTest extends TestCase {
	/**
	 * Controller under test.
	 *
	 * @var WorkflowEngineController
	 */
	private WorkflowEngineController $controller;

	/**
	 * HTTP request mock.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Engine registry mock.
	 *
	 * @var WorkflowEngineRegistry&MockObject
	 */
	private WorkflowEngineRegistry&MockObject $registry;

	/**
	 * Logger mock.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->registry = $this->createMock(WorkflowEngineRegistry::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		// Write endpoints are admin-gated; simulate an authenticated admin.
		$adminUser = $this->createMock(\OCP\IUser::class);
		$adminUser->method('getUID')->willReturn('admin');
		$userSession = $this->createMock(\OCP\IUserSession::class);
		$userSession->method('getUser')->willReturn($adminUser);
		$groupManager = $this->createMock(\OCP\IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn(true);

		$this->controller = new WorkflowEngineController(
			'openregister',
			$this->request,
			$this->registry,
			$this->logger,
			$this->createMock(IL10N::class),
			$userSession,
			$groupManager
		);
	}

	private function createEngineEntity(): \OCA\OpenRegister\Db\WorkflowEngine {
		$engine = new \OCA\OpenRegister\Db\WorkflowEngine();
		$ref = new \ReflectionClass($engine);
		$prop = $ref->getProperty('id');
		$prop->setAccessible(true);
		$prop->setValue($engine, 1);
		$engine->setName('Test Engine');
		$engine->setEngineType('n8n');
		$engine->setBaseUrl('http://localhost:5678');
		return $engine;
	}

	public function testIndexSuccess(): void {
		$engine = $this->createEngineEntity();
		$this->registry->method('getEngines')->willReturn([$engine]);

		$result = $this->controller->index();

		$this->assertEquals(200, $result->getStatus());
		$data = $result->getData();
		$this->assertCount(1, $data);
		$this->assertEquals('Test Engine', $data[0]['name']);
	}

	public function testShowSuccess(): void {
		$engine = $this->createEngineEntity();
		$this->registry->method('getEngine')->with(1)->willReturn($engine);

		$result = $this->controller->show(1);

		$this->assertEquals(200, $result->getStatus());
		$this->assertEquals('Test Engine', $result->getData()['name']);
	}

	public function testShowNotFound(): void {
		$this->registry->method('getEngine')
			->willThrowException(new DoesNotExistException('not found'));

		$result = $this->controller->show(999);

		$this->assertEquals(404, $result->getStatus());
	}

	private function makeNonAdminController(): WorkflowEngineController {
		$bob = $this->createMock(\OCP\IUser::class);
		$bob->method('getUID')->willReturn('bob');
		$session = $this->createMock(\OCP\IUserSession::class);
		$session->method('getUser')->willReturn($bob);
		$groupManager = $this->createMock(\OCP\IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn(false);

		return new WorkflowEngineController(
			'openregister',
			$this->request,
			$this->registry,
			$this->logger,
			$this->createMock(IL10N::class),
			$session,
			$groupManager
		);
	}

	public function testIndexRejectsNonAdmin(): void {
		$controller = $this->makeNonAdminController();
		// Engine registry (internal baseUrl/healthStatus) is never read.
		$this->registry->expects($this->never())->method('getEngines');

		$result = $controller->index();

		$this->assertEquals(403, $result->getStatus());
	}

	public function testShowRejectsNonAdmin(): void {
		$controller = $this->makeNonAdminController();
		$this->registry->expects($this->never())->method('getEngine');

		$result = $controller->show(1);

		$this->assertEquals(403, $result->getStatus());
	}

	public function testCreateInvalidType(): void {
		$result = $this->controller->create(
			'Test',
			'invalid_type',
			'http://localhost:5678'
		);

		$this->assertEquals(400, $result->getStatus());
		$this->assertStringContainsString('Invalid engine type', $result->getData()['error']);
	}

	public function testCreateSuccess(): void {
		$engine = $this->createEngineEntity();
		$this->registry->method('createEngine')->willReturn($engine);
		$this->registry->method('getEngine')->willReturn($engine);

		$result = $this->controller->create(
			'Test Engine',
			'n8n',
			'http://localhost:5678'
		);

		$this->assertEquals(201, $result->getStatus());
	}

	public function testUpdateSuccess(): void {
		$engine = $this->createEngineEntity();
		$this->request->method('getParams')->willReturn(['name' => 'Updated']);
		$this->registry->method('updateEngine')->willReturn($engine);

		$result = $this->controller->update(1);

		$this->assertEquals(200, $result->getStatus());
	}

	public function testUpdateNotFound(): void {
		$this->request->method('getParams')->willReturn(['name' => 'Updated']);
		$this->registry->method('updateEngine')
			->willThrowException(new DoesNotExistException('not found'));

		$result = $this->controller->update(999);

		$this->assertEquals(404, $result->getStatus());
	}

	public function testDestroySuccess(): void {
		$engine = $this->createEngineEntity();
		$this->registry->method('deleteEngine')->willReturn($engine);

		$result = $this->controller->destroy(1);

		$this->assertEquals(200, $result->getStatus());
	}

	public function testDestroyNotFound(): void {
		$this->registry->method('deleteEngine')
			->willThrowException(new DoesNotExistException('not found'));

		$result = $this->controller->destroy(999);

		$this->assertEquals(404, $result->getStatus());
	}

	public function testHealthSuccess(): void {
		$healthResult = ['status' => 'healthy'];
		$this->registry->method('healthCheck')->willReturn($healthResult);

		$result = $this->controller->health(1);

		$this->assertEquals(200, $result->getStatus());
		$this->assertEquals($healthResult, $result->getData());
	}

	public function testHealthNotFound(): void {
		$this->registry->method('healthCheck')
			->willThrowException(new DoesNotExistException('not found'));

		$result = $this->controller->health(999);

		$this->assertEquals(404, $result->getStatus());
	}

	public function testHealthException(): void {
		$this->registry->method('healthCheck')
			->willThrowException(new \Exception('Connection failed'));

		$result = $this->controller->health(1);

		$this->assertEquals(500, $result->getStatus());
	}

	public function testAvailable(): void {
		$engines = [['type' => 'n8n', 'name' => 'n8n']];
		$this->registry->method('discoverEngines')->willReturn($engines);

		$result = $this->controller->available();

		$this->assertEquals(200, $result->getStatus());
		$this->assertEquals($engines, $result->getData());
	}

	/**
	 * Stub the request params from a simple map.
	 *
	 * @param array<string,mixed> $params The params the request should answer.
	 *
	 * @return void
	 */
	private function stubParams(array $params): void {
		$this->request->method('getParam')->willReturnCallback(
			static function (string $key, mixed $default = null) use ($params): mixed {
				return ($params[$key] ?? $default);
			}
		);
	}

	public function testTestHookRequiresAWorkflowId(): void {
		$this->stubParams([]);
		$this->registry->expects($this->never())->method('resolveAdapterById');

		$result = $this->controller->testHook(3);

		$this->assertEquals(400, $result->getStatus());
		$this->assertSame('workflowId is required', $result->getData()['error']);
	}

	public function testTestHookExecutesTheWorkflowAndFlagsTheResponseAsADryRun(): void {
		$this->stubParams(['workflowId' => 'wf-1', 'sampleData' => ['title' => 'x'], 'timeout' => 12]);

		$adapter = $this->createMock(\OCA\OpenRegister\WorkflowEngine\WorkflowEngineInterface::class);
		$adapter->expects($this->once())
			->method('executeWorkflow')
			->with('wf-1', ['title' => 'x'], 12)
			->willReturn(
				new \OCA\OpenRegister\WorkflowEngine\WorkflowResult(
					\OCA\OpenRegister\WorkflowEngine\WorkflowResult::STATUS_APPROVED,
					['title' => 'x']
				)
			);

		$this->registry->expects($this->once())
			->method('resolveAdapterById')
			->with(3)
			->willReturn($adapter);

		$result = $this->controller->testHook(3);

		$this->assertEquals(200, $result->getStatus());
		$data = $result->getData();
		$this->assertTrue($data['dryRun']);
		$this->assertSame('approved', $data['status']);
	}

	public function testTestHookDecodesAJsonEncodedSampleDataPayload(): void {
		$this->stubParams(['workflowId' => 'wf-1', 'sampleData' => '{"title":"x"}']);

		$adapter = $this->createMock(\OCA\OpenRegister\WorkflowEngine\WorkflowEngineInterface::class);
		$adapter->expects($this->once())
			->method('executeWorkflow')
			->with('wf-1', ['title' => 'x'], 30)
			->willReturn(
				new \OCA\OpenRegister\WorkflowEngine\WorkflowResult(
					\OCA\OpenRegister\WorkflowEngine\WorkflowResult::STATUS_MODIFIED,
					['title' => 'y']
				)
			);

		$this->registry->method('resolveAdapterById')->willReturn($adapter);

		$result = $this->controller->testHook(3);

		$this->assertEquals(200, $result->getStatus());
		$this->assertSame('modified', $result->getData()['status']);
	}

	public function testTestHookReturns404ForAnUnknownEngine(): void {
		$this->stubParams(['workflowId' => 'wf-1']);
		$this->registry->method('resolveAdapterById')
			->willThrowException(new DoesNotExistException('no such engine'));

		$result = $this->controller->testHook(99);

		$this->assertEquals(404, $result->getStatus());
	}

	public function testTestHookMapsAConnectivityFailureTo502(): void {
		$this->stubParams(['workflowId' => 'wf-1']);

		$adapter = $this->createMock(\OCA\OpenRegister\WorkflowEngine\WorkflowEngineInterface::class);
		$adapter->method('executeWorkflow')
			->willThrowException(new \RuntimeException('Connection refused by engine host'));

		$this->registry->method('resolveAdapterById')->willReturn($adapter);

		$result = $this->controller->testHook(3);

		$this->assertEquals(502, $result->getStatus());
		$this->assertTrue($result->getData()['dryRun']);
		$this->assertSame('error', $result->getData()['status']);
	}

	public function testTestHookMapsAWorkflowFailureTo422(): void {
		$this->stubParams(['workflowId' => 'wf-1']);

		$adapter = $this->createMock(\OCA\OpenRegister\WorkflowEngine\WorkflowEngineInterface::class);
		$adapter->method('executeWorkflow')
			->willThrowException(new \RuntimeException('Workflow wf-1 has no active trigger'));

		$this->registry->method('resolveAdapterById')->willReturn($adapter);

		$result = $this->controller->testHook(3);

		$this->assertEquals(422, $result->getStatus());
		$this->assertSame(
			'Workflow wf-1 has no active trigger',
			$result->getData()['errors'][0]['message']
		);
	}
}
