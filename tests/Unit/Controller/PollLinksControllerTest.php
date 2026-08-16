<?php

/**
 * Unit tests for {@see \OCA\OpenRegister\Controller\PollLinksController}.
 *
 * Exercises the Tier-2 controller surface: HTTP status mapping
 * (200/201/400/404/409/501/503), payload routing (link existing vs.
 * create new), and graceful degradation when Polls is unavailable.
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
 * @spec openspec/changes/integration-polls/tasks.md
 */

declare(strict_types=1);

namespace Unit\Controller;

use Exception;
use OCA\OpenRegister\Controller\PollLinksController;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\PollLink;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\PollLinkService;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * PollLinksControllerTest.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class PollLinksControllerTest extends TestCase {
	private IRequest&MockObject $request;
	private PollLinkService&MockObject $service;
	private ObjectService&MockObject $objectService;
	private PollLinksController $controller;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->service = $this->createMock(PollLinkService::class);
		$this->objectService = $this->createMock(ObjectService::class);

		$this->controller = new PollLinksController(
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

	public function testIndexReturns501WhenPollsUnavailable(): void {
		$this->service->method('isPollsAvailable')->willReturn(false);

		$response = $this->controller->index('reg', 'sch', 'obj');

		$this->assertSame(501, $response->getStatus());
	}

	public function testIndexReturnsResults(): void {
		$this->service->method('isPollsAvailable')->willReturn(true);
		$this->mockObject();
		$this->service->method('getLinkedPolls')->with('abc-123')->willReturn([
			['pollId' => 99, 'pollTitle' => 'Test'],
		]);

		$response = $this->controller->index('reg', 'sch', 'obj');
		$data = $response->getData();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(1, $data['total']);
		$this->assertSame(99, $data['results'][0]['pollId']);
	}

	public function testIndexReturns404WhenObjectMissing(): void {
		$this->service->method('isPollsAvailable')->willReturn(true);
		$this->objectService->method('getObject')->willReturn(null);

		$response = $this->controller->index('reg', 'sch', 'missing');

		$this->assertSame(404, $response->getStatus());
	}

	public function testLinkReturns400WhenPollIdMissing(): void {
		$this->service->method('isPollsAvailable')->willReturn(true);
		$this->mockObject();
		$this->request->method('getParam')->willReturnMap([['pollId', 0, 0]]);

		$response = $this->controller->link('reg', 'sch', 'obj');

		$this->assertSame(400, $response->getStatus());
	}

	public function testLinkReturns201OnSuccess(): void {
		$this->service->method('isPollsAvailable')->willReturn(true);
		$this->mockObject();
		$this->request->method('getParam')->willReturn(99);

		$link = new PollLink();
		$link->setObjectUuid('abc-123');
		$link->setPollId(99);

		$this->service->method('linkPoll')
			->with('abc-123', 1, 2, 99)
			->willReturn($link);

		$response = $this->controller->link('reg', 'sch', 'obj');

		$this->assertSame(201, $response->getStatus());
		$this->assertSame(99, $response->getData()['pollId']);
	}

	public function testLinkReturns409OnDuplicate(): void {
		$this->service->method('isPollsAvailable')->willReturn(true);
		$this->mockObject();
		$this->request->method('getParam')->willReturn(99);

		$this->service->method('linkPoll')
			->willThrowException(new Exception('Poll already linked to this object', 409));

		$response = $this->controller->link('reg', 'sch', 'obj');

		$this->assertSame(409, $response->getStatus());
	}

	public function testCreateNewReturns400WhenTitleMissing(): void {
		$this->service->method('isPollsAvailable')->willReturn(true);
		$this->mockObject();
		$this->request->method('getParam')->willReturnCallback(
			static function (string $key, $default = null) {
				return match ($key) {
					'title' => '',
					'options' => ['A'],
					default => $default,
				};
			}
		);

		$response = $this->controller->createNew('reg', 'sch', 'obj');

		$this->assertSame(400, $response->getStatus());
	}

	public function testCreateNewReturns201OnSuccess(): void {
		$this->service->method('isPollsAvailable')->willReturn(true);
		$this->mockObject();
		$this->request->method('getParam')->willReturnCallback(
			static function (string $key, $default = null) {
				return match ($key) {
					'title' => 'Lunch',
					'description' => 'Pick a day',
					'type' => 'textPoll',
					'options' => ['Mon', 'Tue'],
					'deadline' => null,
					default => $default,
				};
			}
		);

		$link = new PollLink();
		$link->setObjectUuid('abc-123');
		$link->setPollId(456);
		$link->setPollTitle('Lunch');

		$this->service->expects($this->once())
			->method('createAndLinkPoll')
			->willReturn($link);

		$response = $this->controller->createNew('reg', 'sch', 'obj');

		$this->assertSame(201, $response->getStatus());
		$this->assertSame(456, $response->getData()['pollId']);
	}

	public function testDestroyReturns200OnSuccess(): void {
		$this->service->method('isPollsAvailable')->willReturn(true);
		$this->mockObject();
		$this->service->expects($this->once())
			->method('unlinkPoll')
			->with('abc-123', 42);

		$response = $this->controller->destroy('reg', 'sch', 'obj', '42');

		$this->assertSame(200, $response->getStatus());
		$this->assertTrue($response->getData()['success']);
	}

	public function testDestroyReturns404WhenLinkMissing(): void {
		$this->service->method('isPollsAvailable')->willReturn(true);
		$this->mockObject();
		$this->service->method('unlinkPoll')
			->willThrowException(new Exception('Poll link not found', 404));

		$response = $this->controller->destroy('reg', 'sch', 'obj', '42');

		$this->assertSame(404, $response->getStatus());
	}

	public function testAvailableReturnsList(): void {
		$this->service->method('isPollsAvailable')->willReturn(true);
		$this->service->method('getAvailablePolls')->willReturn([
			['id' => 1, 'title' => 'Lunch', 'type' => 'textPoll', 'deadline' => null, 'closed' => false, 'voterCount' => 0, 'optionCount' => 2],
		]);

		$response = $this->controller->available();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(1, $response->getData()['total']);
	}

	public function testAvailableReturns501WhenPollsUnavailable(): void {
		$this->service->method('isPollsAvailable')->willReturn(false);

		$response = $this->controller->available();

		$this->assertSame(501, $response->getStatus());
	}
}
