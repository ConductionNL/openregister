<?php

/**
 * Unit tests for {@see \OCA\OpenRegister\Controller\CollectiveLinksController}.
 *
 * Exercises the Tier-2 controller surface: HTTP status mapping
 * (200/201/400/404/409/501/503), payload routing (link existing vs.
 * create+link vs. unlink), and graceful degradation when NC Collectives
 * is unavailable.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/integration-collectives/tasks.md
 */

declare(strict_types=1);

namespace Unit\Controller;

use Exception;
use OCA\OpenRegister\Controller\CollectiveLinksController;
use OCA\OpenRegister\Db\CollectiveLink;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\CollectiveLinkService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * CollectiveLinksControllerTest.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class CollectiveLinksControllerTest extends TestCase {

	private IRequest&MockObject $request;

	private CollectiveLinkService&MockObject $service;

	private ObjectService&MockObject $objectService;

	private CollectiveLinksController $controller;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->service = $this->createMock(CollectiveLinkService::class);
		$this->objectService = $this->createMock(ObjectService::class);

		$this->controller = new CollectiveLinksController(
			'openregister',
			$this->request,
			$this->service,
			$this->objectService,
		);
	}//end setUp()

	private function mockObject(string $uuid = 'abc-123', int $registerId = 1, int $schemaId = 2): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setRegister($registerId);
		$object->setSchema($schemaId);
		// phpcs:disable CustomSn.Functions.NamedParameters
		$this->objectService->method('setSchema')->willReturnSelf();
		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setObject')->willReturnSelf();
		$this->objectService->method('getObject')->willReturn($object);
		// phpcs:enable CustomSn.Functions.NamedParameters
		return $object;
	}//end mockObject()

	public function testIndexReturns501WhenCollectivesUnavailable(): void {
		$this->service->method('isCollectivesAvailable')->willReturn(false);

		$response = $this->controller->index('reg', 'sch', 'obj');

		$this->assertSame(501, $response->getStatus());
	}//end testIndexReturns501WhenCollectivesUnavailable()

	public function testIndexReturnsLinkedPages(): void {
		$this->service->method('isCollectivesAvailable')->willReturn(true);
		$this->mockObject();
		$this->service->method('getLinkedPages')->with('abc-123')->willReturn([
			['pageId' => 42, 'pageTitle' => 'Runbook'],
		]);

		$response = $this->controller->index('reg', 'sch', 'obj');
		$data = $response->getData();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(1, $data['total']);
		$this->assertSame(42, $data['results'][0]['pageId']);
	}//end testIndexReturnsLinkedPages()

	public function testIndexReturns404WhenObjectMissing(): void {
		$this->service->method('isCollectivesAvailable')->willReturn(true);
		$this->objectService->method('getObject')->willReturn(null);

		$response = $this->controller->index('reg', 'sch', 'missing');

		$this->assertSame(404, $response->getStatus());
	}//end testIndexReturns404WhenObjectMissing()

	public function testLinkReturns501WhenCollectivesUnavailable(): void {
		$this->service->method('isCollectivesAvailable')->willReturn(false);

		$response = $this->controller->link('reg', 'sch', 'obj');

		$this->assertSame(501, $response->getStatus());
	}//end testLinkReturns501WhenCollectivesUnavailable()

	public function testLinkReturns400WhenPageIdMissing(): void {
		$this->service->method('isCollectivesAvailable')->willReturn(true);
		$this->mockObject();
		$this->request->method('getParam')->willReturnMap([['pageId', 0, 0]]);

		$response = $this->controller->link('reg', 'sch', 'obj');

		$this->assertSame(400, $response->getStatus());
	}//end testLinkReturns400WhenPageIdMissing()

	public function testLinkReturns201OnSuccess(): void {
		$this->service->method('isCollectivesAvailable')->willReturn(true);
		$this->mockObject();
		$this->request->method('getParam')->willReturn(42);

		$link = new CollectiveLink();
		$link->setObjectUuid('abc-123');
		$link->setPageId(42);
		$link->setPageTitle('Runbook');

		$this->service->method('linkPage')
			->with('abc-123', 1, 2, 42)
			->willReturn($link);

		$response = $this->controller->link('reg', 'sch', 'obj');

		$this->assertSame(201, $response->getStatus());
		$this->assertSame(42, $response->getData()['pageId']);
	}//end testLinkReturns201OnSuccess()

	public function testLinkReturns409OnDuplicate(): void {
		$this->service->method('isCollectivesAvailable')->willReturn(true);
		$this->mockObject();
		$this->request->method('getParam')->willReturn(42);

		$this->service->method('linkPage')
			->willThrowException(new Exception('Page already linked to this object', 409));

		$response = $this->controller->link('reg', 'sch', 'obj');

		$this->assertSame(409, $response->getStatus());
	}//end testLinkReturns409OnDuplicate()

	public function testCreateAndLinkReturns400WhenCollectiveIdMissing(): void {
		$this->service->method('isCollectivesAvailable')->willReturn(true);
		$this->mockObject();
		$this->request->method('getParam')->willReturnCallback(
			static function (string $key, $default = null) {
				return match ($key) {
					'collectiveId' => 0,
					'title' => 'Runbook',
					default => $default,
				};
			}
		);

		$response = $this->controller->createAndLink('reg', 'sch', 'obj');

		$this->assertSame(400, $response->getStatus());
	}//end testCreateAndLinkReturns400WhenCollectiveIdMissing()

	public function testCreateAndLinkReturns400WhenTitleMissing(): void {
		$this->service->method('isCollectivesAvailable')->willReturn(true);
		$this->mockObject();
		$this->request->method('getParam')->willReturnCallback(
			static function (string $key, $default = null) {
				return match ($key) {
					'collectiveId' => 5,
					'title' => '',
					default => $default,
				};
			}
		);

		$response = $this->controller->createAndLink('reg', 'sch', 'obj');

		$this->assertSame(400, $response->getStatus());
	}//end testCreateAndLinkReturns400WhenTitleMissing()

	public function testCreateAndLinkReturns201OnSuccess(): void {
		$this->service->method('isCollectivesAvailable')->willReturn(true);
		$this->mockObject();
		$this->request->method('getParam')->willReturnCallback(
			static function (string $key, $default = null) {
				return match ($key) {
					'collectiveId' => 5,
					'title' => 'Runbook',
					default => $default,
				};
			}
		);

		$link = new CollectiveLink();
		$link->setObjectUuid('abc-123');
		$link->setPageId(99);
		$link->setPageTitle('Runbook');
		$link->setCollectiveId(5);

		$this->service->expects($this->once())
			->method('createAndLinkPage')
			->with('abc-123', 1, 2, 5, 'Runbook')
			->willReturn($link);

		$response = $this->controller->createAndLink('reg', 'sch', 'obj');

		$this->assertSame(201, $response->getStatus());
		$this->assertSame(99, $response->getData()['pageId']);
	}//end testCreateAndLinkReturns201OnSuccess()

	public function testDestroyReturns200OnSuccess(): void {
		$this->service->method('isCollectivesAvailable')->willReturn(true);
		$this->mockObject();
		$this->service->expects($this->once())
			->method('unlinkPage')
			->with('abc-123', 42);

		$response = $this->controller->destroy('reg', 'sch', 'obj', '42');

		$this->assertSame(200, $response->getStatus());
		$this->assertTrue($response->getData()['success']);
	}//end testDestroyReturns200OnSuccess()

	public function testDestroyReturns404WhenLinkMissing(): void {
		$this->service->method('isCollectivesAvailable')->willReturn(true);
		$this->mockObject();
		$this->service->method('unlinkPage')
			->willThrowException(new Exception('Collective link not found', 404));

		$response = $this->controller->destroy('reg', 'sch', 'obj', '42');

		$this->assertSame(404, $response->getStatus());
	}//end testDestroyReturns404WhenLinkMissing()

	public function testAvailableReturns501WhenCollectivesUnavailable(): void {
		$this->service->method('isCollectivesAvailable')->willReturn(false);

		$response = $this->controller->available();

		$this->assertSame(501, $response->getStatus());
	}//end testAvailableReturns501WhenCollectivesUnavailable()

	public function testAvailableReturnsList(): void {
		$this->service->method('isCollectivesAvailable')->willReturn(true);
		$this->request->method('getParam')->willReturn(null);
		$this->service->method('getAvailablePages')->willReturn([
			[
				'pageId' => 1,
				'title' => 'Runbook',
				'collectiveName' => 'Ops',
			],
		]);

		$response = $this->controller->available();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(1, $response->getData()['total']);
	}//end testAvailableReturnsList()

	public function testCollectivesReturns501WhenCollectivesUnavailable(): void {
		$this->service->method('isCollectivesAvailable')->willReturn(false);

		$response = $this->controller->collectives();

		$this->assertSame(501, $response->getStatus());
	}//end testCollectivesReturns501WhenCollectivesUnavailable()

	public function testCollectivesReturnsList(): void {
		$this->service->method('isCollectivesAvailable')->willReturn(true);
		$this->service->method('getAvailableCollectives')->willReturn([
			['id' => 1, 'name' => 'Engineering'],
		]);

		$response = $this->controller->collectives();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(1, $response->getData()['total']);
	}//end testCollectivesReturnsList()
}//end class
