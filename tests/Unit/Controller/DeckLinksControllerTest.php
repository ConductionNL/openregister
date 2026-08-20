<?php

/**
 * Unit tests for {@see \OCA\OpenRegister\Controller\DeckLinksController}.
 *
 * Exercises the Tier-2 controller surface: HTTP status mapping
 * (200/201/400/404/409/501/503), payload routing
 * (link existing vs. create new), and graceful degradation when Deck
 * is unavailable.
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
 * @spec openspec/changes/integration-deck/tasks.md
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace Unit\Controller;

use Exception;
use OCA\OpenRegister\Controller\DeckLinksController;
use OCA\OpenRegister\Db\DeckLink;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\DeckLinkService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\SettingsService;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * DeckLinksControllerTest.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class DeckLinksControllerTest extends TestCase {

	/**
	 * HTTP request mock.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * DeckLinkService mock.
	 *
	 * @var DeckLinkService&MockObject
	 */
	private DeckLinkService&MockObject $service;

	/**
	 * ObjectService mock.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService&MockObject $objectService;

	/**
	 * SettingsService mock.
	 *
	 * @var SettingsService&MockObject
	 */
	private SettingsService&MockObject $settingsService;

	/**
	 * Controller under test.
	 *
	 * @var DeckLinksController
	 */
	private DeckLinksController $controller;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->service = $this->createMock(DeckLinkService::class);
		$this->objectService = $this->createMock(ObjectService::class);
		$this->settingsService = $this->createMock(SettingsService::class);

		$this->controller = new DeckLinksController(
			'openregister',
			$this->request,
			$this->service,
			$this->objectService,
			$this->settingsService,
		);
	}//end setUp()

	private function mockObject(string $uuid = 'abc-123', int $registerId = 1, int $schemaId = 2): ObjectEntity {
		// ObjectEntity uses NC's __call magic; instantiate concretely so
		// setters/getters resolve via the Entity base class rather than
		// tripping PHPUnit's "method does not exist" guard.
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setRegister($registerId);
		$object->setSchema($schemaId);
		$this->objectService->method('setSchema')->willReturnSelf();
		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setObject')->willReturnSelf();
		$this->objectService->method('getObject')->willReturn($object);
		return $object;
	}//end mockObject()

	public function testIndexReturns501WhenDeckUnavailable(): void {
		$this->service->method('isDeckAvailable')->willReturn(false);

		$response = $this->controller->index('reg', 'sch', 'obj');

		$this->assertSame(501, $response->getStatus());
	}//end testIndexReturns501WhenDeckUnavailable()

	public function testIndexReturnsResults(): void {
		$this->service->method('isDeckAvailable')->willReturn(true);
		$this->mockObject();
		$this->service->method('getLinkedCards')->with('abc-123')->willReturn(
			[
				['cardId' => 99, 'cardTitle' => 'Test'],
			]
		);

		$response = $this->controller->index('reg', 'sch', 'obj');
		$data = $response->getData();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(1, $data['total']);
		$this->assertSame(99, $data['results'][0]['cardId']);
	}//end testIndexReturnsResults()

	public function testIndexReturns404WhenObjectMissing(): void {
		$this->service->method('isDeckAvailable')->willReturn(true);
		$this->objectService->method('getObject')->willReturn(null);

		$response = $this->controller->index('reg', 'sch', 'missing');

		$this->assertSame(404, $response->getStatus());
	}//end testIndexReturns404WhenObjectMissing()

	public function testLinkReturns400WhenCardIdMissing(): void {
		$this->service->method('isDeckAvailable')->willReturn(true);
		$this->mockObject();
		$this->request->method('getParam')->willReturnMap([['cardId', 0, 0]]);

		$response = $this->controller->link('reg', 'sch', 'obj');

		$this->assertSame(400, $response->getStatus());
	}//end testLinkReturns400WhenCardIdMissing()

	public function testLinkReturns201OnSuccess(): void {
		$this->service->method('isDeckAvailable')->willReturn(true);
		$this->mockObject();
		$this->request->method('getParam')->willReturn(99);

		$link = new DeckLink();
		$link->setObjectUuid('abc-123');
		$link->setCardId(99);

		$this->service->method('linkCard')
			->with('abc-123', 1, 2, 99)
			->willReturn($link);

		$response = $this->controller->link('reg', 'sch', 'obj');

		$this->assertSame(201, $response->getStatus());
		$this->assertSame(99, $response->getData()['cardId']);
	}//end testLinkReturns201OnSuccess()

	public function testLinkReturns409OnDuplicate(): void {
		$this->service->method('isDeckAvailable')->willReturn(true);
		$this->mockObject();
		$this->request->method('getParam')->willReturn(99);

		$this->service->method('linkCard')
			->willThrowException(new Exception('Card already linked to this object', 409));

		$response = $this->controller->link('reg', 'sch', 'obj');

		$this->assertSame(409, $response->getStatus());
	}//end testLinkReturns409OnDuplicate()

	public function testCreateNewReturns400WhenFieldsMissing(): void {
		$this->service->method('isDeckAvailable')->willReturn(true);
		$this->mockObject();
		$this->request->method('getParam')->willReturn(0);

		$response = $this->controller->createNew('reg', 'sch', 'obj');

		$this->assertSame(400, $response->getStatus());
	}//end testCreateNewReturns400WhenFieldsMissing()

	public function testCreateNewReturns201OnSuccess(): void {
		$this->service->method('isDeckAvailable')->willReturn(true);
		$this->mockObject();
		$this->request->method('getParam')->willReturnCallback(
			static function (string $key, $default = null) {
				return match ($key) {
					'boardId' => 10,
					'stackId' => 20,
					'title' => 'New card',
					'description' => 'Body',
					'duedate' => '2026-06-01T12:00:00+00:00',
					default => $default,
				};
			}
		);

		$link = new DeckLink();
		$link->setObjectUuid('abc-123');
		$link->setCardId(123);
		$link->setCardTitle('New card');

		$this->service->expects($this->once())
			->method('createAndLinkCard')
			->with(
				'abc-123',
				1,
				2,
				10,
				20,
				'New card',
				'Body',
				'2026-06-01T12:00:00+00:00'
			)
			->willReturn($link);

		$response = $this->controller->createNew('reg', 'sch', 'obj');

		$this->assertSame(201, $response->getStatus());
		$this->assertSame(123, $response->getData()['cardId']);
	}//end testCreateNewReturns201OnSuccess()

	public function testDestroyReturns200OnSuccess(): void {
		$this->service->method('isDeckAvailable')->willReturn(true);
		$this->mockObject();
		$this->service->expects($this->once())
			->method('unlinkCard')
			->with('abc-123', 42);

		$response = $this->controller->destroy('reg', 'sch', 'obj', '42');

		$this->assertSame(200, $response->getStatus());
		$this->assertTrue($response->getData()['success']);
	}//end testDestroyReturns200OnSuccess()

	public function testDestroyReturns404WhenLinkMissing(): void {
		$this->service->method('isDeckAvailable')->willReturn(true);
		$this->mockObject();
		$this->service->method('unlinkCard')
			->willThrowException(new Exception('Deck link not found', 404));

		$response = $this->controller->destroy('reg', 'sch', 'obj', '42');

		$this->assertSame(404, $response->getStatus());
	}//end testDestroyReturns404WhenLinkMissing()

	public function testBoardsReturnsList(): void {
		$this->service->method('isDeckAvailable')->willReturn(true);
		$this->service->method('getAvailableBoards')->willReturn(
			[
				['id' => 1, 'title' => 'Sprint'],
				['id' => 2, 'title' => 'Backlog'],
			]
		);

		$response = $this->controller->boards();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(2, $response->getData()['total']);
	}//end testBoardsReturnsList()

	public function testBoardsReturns501WhenDeckUnavailable(): void {
		$this->service->method('isDeckAvailable')->willReturn(false);

		$response = $this->controller->boards();

		$this->assertSame(501, $response->getStatus());
	}//end testBoardsReturns501WhenDeckUnavailable()

	public function testStacksReturnsList(): void {
		$this->service->method('isDeckAvailable')->willReturn(true);
		$this->service->method('getStacksForBoard')->with(7)->willReturn(
			[
				['id' => 11, 'title' => 'To Do', 'boardId' => 7],
			]
		);

		$response = $this->controller->stacks('7');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(1, $response->getData()['total']);
		$this->assertSame(11, $response->getData()['results'][0]['id']);
	}//end testStacksReturnsList()

	public function testGetDefaultReturnsTheStoredStickyDefault(): void {
		$this->settingsService->expects($this->once())
			->method('getDeckDefault')
			->with('zaak')
			->willReturn(['boardId' => 7, 'stackId' => 11]);

		$response = $this->controller->getDefault('zaak');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(7, $response->getData()['boardId']);
		$this->assertSame(11, $response->getData()['stackId']);
	}//end testGetDefaultReturnsTheStoredStickyDefault()

	public function testGetDefaultReturnsANullPairWhenNothingIsStored(): void {
		$this->settingsService->method('getDeckDefault')->willReturn(null);

		$response = $this->controller->getDefault('zaak');

		$this->assertSame(200, $response->getStatus());
		$this->assertNull($response->getData()['boardId']);
		$this->assertNull($response->getData()['stackId']);
	}//end testGetDefaultReturnsANullPairWhenNothingIsStored()

	public function testSetDefaultPersistsTheBoardAndStackAndEchoesThem(): void {
		$this->request->method('getParam')->willReturnCallback(
			static function (string $key, mixed $default = null): mixed {
				return (['boardId' => 7, 'stackId' => 11][$key] ?? $default);
			}
		);

		$this->settingsService->expects($this->once())
			->method('setDeckDefault')
			->with('zaak', 7, 11);

		$response = $this->controller->setDefault('zaak');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(7, $response->getData()['boardId']);
		$this->assertSame(11, $response->getData()['stackId']);
	}//end testSetDefaultPersistsTheBoardAndStackAndEchoesThem()

	public function testSetDefaultRejectsAMissingStackWith400(): void {
		$this->request->method('getParam')->willReturnCallback(
			static function (string $key, mixed $default = null): mixed {
				return (['boardId' => 7][$key] ?? $default);
			}
		);

		$this->settingsService->expects($this->never())->method('setDeckDefault');

		$response = $this->controller->setDefault('zaak');

		$this->assertSame(400, $response->getStatus());
		$this->assertSame('boardId and stackId are required', $response->getData()['error']);
	}//end testSetDefaultRejectsAMissingStackWith400()
}//end class
