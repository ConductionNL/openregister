<?php

/**
 * Unit tests for {@see \OCA\OpenRegister\Controller\FlowLinksController}.
 *
 * Exercises the Tier-2 controller surface: HTTP status mapping
 * (200/201/400/403/404/409/501/503), admin-vs-non-admin gating
 * (mutating verbs return 403 for non-admins; GET stays open), and
 * graceful degradation when NC Flow is unavailable.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/integration-flow/tasks.md
 */

declare(strict_types=1);

namespace Unit\Controller;

use Exception;
use OCA\OpenRegister\Controller\FlowLinksController;
use OCA\OpenRegister\Db\FlowLink;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\FlowLinkService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * FlowLinksControllerTest.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class FlowLinksControllerTest extends TestCase {
	private IRequest&MockObject $request;
	private FlowLinkService&MockObject $service;
	private ObjectService&MockObject $objectService;
	private FlowLinksController $controller;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->service = $this->createMock(FlowLinkService::class);
		$this->objectService = $this->createMock(ObjectService::class);

		$this->controller = new FlowLinksController(
			'openregister',
			$this->request,
			$this->service,
			$this->objectService,
		);
	}

	private function mockObject(string $uuid = 'abc-123', int $registerId = 1, int $schemaId = 2): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setRegister($registerId);
		$object->setSchema($schemaId);
		$this->objectService->method('setSchema')->willReturnSelf();
		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setObject')->willReturnSelf();
		$this->objectService->method('getObject')->willReturn($object);
		return $object;
	}

	public function testIndexReturns501WhenFlowUnavailable(): void {
		$this->service->method('isFlowAvailable')->willReturn(false);

		$response = $this->controller->index('reg', 'sch', 'obj');

		$this->assertSame(501, $response->getStatus());
	}

	public function testIndexReturnsResults(): void {
		$this->service->method('isFlowAvailable')->willReturn(true);
		$this->service->method('isCurrentUserAdmin')->willReturn(true);
		$this->mockObject();
		$this->service->method('getLinkedOperations')->with('abc-123')->willReturn([
			['operationId' => 99, 'operationName' => 'Probe'],
		]);

		$response = $this->controller->index('reg', 'sch', 'obj');
		$data = $response->getData();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(1, $data['total']);
		$this->assertSame(99, $data['results'][0]['operationId']);
		$this->assertTrue($data['isAdmin']);
	}

	public function testIndexReturnsResultsForNonAdmin(): void {
		// Non-admins still see linked operations — read-only access.
		$this->service->method('isFlowAvailable')->willReturn(true);
		$this->service->method('isCurrentUserAdmin')->willReturn(false);
		$this->mockObject();
		$this->service->method('getLinkedOperations')->willReturn([]);

		$response = $this->controller->index('reg', 'sch', 'obj');
		$data = $response->getData();

		$this->assertSame(200, $response->getStatus());
		$this->assertFalse($data['isAdmin']);
	}

	public function testIndexReturns404WhenObjectMissing(): void {
		$this->service->method('isFlowAvailable')->willReturn(true);
		$this->objectService->method('getObject')->willReturn(null);

		$response = $this->controller->index('reg', 'sch', 'missing');

		$this->assertSame(404, $response->getStatus());
	}

	public function testLinkReturns400WhenOperationIdMissing(): void {
		$this->service->method('isFlowAvailable')->willReturn(true);
		$this->mockObject();
		$this->request->method('getParam')->willReturnMap([['operationId', 0, 0]]);

		$response = $this->controller->link('reg', 'sch', 'obj');

		$this->assertSame(400, $response->getStatus());
	}

	public function testLinkReturns201OnSuccess(): void {
		$this->service->method('isFlowAvailable')->willReturn(true);
		$this->mockObject();
		$this->request->method('getParam')->willReturn(99);

		$link = new FlowLink();
		$link->setObjectUuid('abc-123');
		$link->setOperationId(99);

		$this->service->method('linkOperation')
			->with('abc-123', 1, 2, 99)
			->willReturn($link);

		$response = $this->controller->link('reg', 'sch', 'obj');

		$this->assertSame(201, $response->getStatus());
		$this->assertSame(99, $response->getData()['operationId']);
	}

	public function testLinkReturns403ForNonAdmin(): void {
		$this->service->method('isFlowAvailable')->willReturn(true);
		$this->mockObject();
		$this->request->method('getParam')->willReturn(99);

		$this->service->method('linkOperation')
			->willThrowException(new Exception('Only administrators can link Flow operations', 403));

		$response = $this->controller->link('reg', 'sch', 'obj');

		$this->assertSame(403, $response->getStatus());
	}

	public function testLinkReturns409OnDuplicate(): void {
		$this->service->method('isFlowAvailable')->willReturn(true);
		$this->mockObject();
		$this->request->method('getParam')->willReturn(99);

		$this->service->method('linkOperation')
			->willThrowException(new Exception('Operation already linked to this object', 409));

		$response = $this->controller->link('reg', 'sch', 'obj');

		$this->assertSame(409, $response->getStatus());
	}

	public function testDestroyReturns200OnSuccess(): void {
		$this->service->method('isFlowAvailable')->willReturn(true);
		$this->mockObject();
		$this->service->expects($this->once())
			->method('unlinkOperation')
			->with('abc-123', 42);

		$response = $this->controller->destroy('reg', 'sch', 'obj', '42');

		$this->assertSame(200, $response->getStatus());
		$this->assertTrue($response->getData()['success']);
	}

	public function testDestroyReturns403ForNonAdmin(): void {
		$this->service->method('isFlowAvailable')->willReturn(true);
		$this->mockObject();
		$this->service->method('unlinkOperation')
			->willThrowException(new Exception('Only administrators can unlink Flow operations', 403));

		$response = $this->controller->destroy('reg', 'sch', 'obj', '42');

		$this->assertSame(403, $response->getStatus());
	}

	public function testDestroyReturns404WhenLinkMissing(): void {
		$this->service->method('isFlowAvailable')->willReturn(true);
		$this->mockObject();
		$this->service->method('unlinkOperation')
			->willThrowException(new Exception('Flow link not found', 404));

		$response = $this->controller->destroy('reg', 'sch', 'obj', '42');

		$this->assertSame(404, $response->getStatus());
	}

	public function testAvailableReturnsListForAdmin(): void {
		$this->service->method('isFlowAvailable')->willReturn(true);
		$this->service->method('isCurrentUserAdmin')->willReturn(true);
		$this->service->method('getAvailableOperations')->willReturn([
			['id' => 1, 'name' => 'Probe', 'class' => 'OCA\\WorkflowEngine\\Operation', 'entity' => 'OCA\\WorkflowEngine\\Entity\\File', 'operation' => '', 'events' => [], 'checks' => [], 'enabled' => true],
		]);

		$response = $this->controller->available();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(1, $response->getData()['total']);
	}

	public function testAvailableReturns403ForNonAdmin(): void {
		$this->service->method('isFlowAvailable')->willReturn(true);
		$this->service->method('isCurrentUserAdmin')->willReturn(false);

		$response = $this->controller->available();

		$this->assertSame(403, $response->getStatus());
		$this->assertSame([], $response->getData()['results']);
	}

	public function testAvailableReturns501WhenFlowUnavailable(): void {
		$this->service->method('isFlowAvailable')->willReturn(false);

		$response = $this->controller->available();

		$this->assertSame(501, $response->getStatus());
	}
}
