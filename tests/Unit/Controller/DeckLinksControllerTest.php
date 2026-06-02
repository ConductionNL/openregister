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

namespace Unit\Controller;

use Exception;
use OCA\OpenRegister\Controller\DeckLinksController;
use OCA\OpenRegister\Db\DeckLink;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\DeckLinkService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * DeckLinksControllerTest.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class DeckLinksControllerTest extends TestCase
{
    private IRequest&MockObject $request;
    private DeckLinkService&MockObject $service;
    private ObjectService&MockObject $objectService;
    private DeckLinksController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request       = $this->createMock(IRequest::class);
        $this->service       = $this->createMock(DeckLinkService::class);
        $this->objectService = $this->createMock(ObjectService::class);

        $this->controller = new DeckLinksController(
            'openregister',
            $this->request,
            $this->service,
            $this->objectService,
        );
    }

    private function mockObject(string $uuid = 'abc-123', int $registerId = 1, int $schemaId = 2): ObjectEntity
    {
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
    }

    public function testIndexReturns501WhenDeckUnavailable(): void
    {
        $this->service->method('isDeckAvailable')->willReturn(false);

        $response = $this->controller->index('reg', 'sch', 'obj');

        $this->assertSame(501, $response->getStatus());
    }

    public function testIndexReturnsResults(): void
    {
        $this->service->method('isDeckAvailable')->willReturn(true);
        $this->mockObject();
        $this->service->method('getLinkedCards')->with('abc-123')->willReturn([
            ['cardId' => 99, 'cardTitle' => 'Test'],
        ]);

        $response = $this->controller->index('reg', 'sch', 'obj');
        $data     = $response->getData();

        $this->assertSame(200, $response->getStatus());
        $this->assertSame(1, $data['total']);
        $this->assertSame(99, $data['results'][0]['cardId']);
    }

    public function testIndexReturns404WhenObjectMissing(): void
    {
        $this->service->method('isDeckAvailable')->willReturn(true);
        $this->objectService->method('getObject')->willReturn(null);

        $response = $this->controller->index('reg', 'sch', 'missing');

        $this->assertSame(404, $response->getStatus());
    }

    public function testLinkReturns400WhenCardIdMissing(): void
    {
        $this->service->method('isDeckAvailable')->willReturn(true);
        $this->mockObject();
        $this->request->method('getParam')->willReturnMap([['cardId', 0, 0]]);

        $response = $this->controller->link('reg', 'sch', 'obj');

        $this->assertSame(400, $response->getStatus());
    }

    public function testLinkReturns201OnSuccess(): void
    {
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
    }

    public function testLinkReturns409OnDuplicate(): void
    {
        $this->service->method('isDeckAvailable')->willReturn(true);
        $this->mockObject();
        $this->request->method('getParam')->willReturn(99);

        $this->service->method('linkCard')
            ->willThrowException(new Exception('Card already linked to this object', 409));

        $response = $this->controller->link('reg', 'sch', 'obj');

        $this->assertSame(409, $response->getStatus());
    }

    public function testCreateNewReturns400WhenFieldsMissing(): void
    {
        $this->service->method('isDeckAvailable')->willReturn(true);
        $this->mockObject();
        $this->request->method('getParam')->willReturn(0);

        $response = $this->controller->createNew('reg', 'sch', 'obj');

        $this->assertSame(400, $response->getStatus());
    }

    public function testCreateNewReturns201OnSuccess(): void
    {
        $this->service->method('isDeckAvailable')->willReturn(true);
        $this->mockObject();
        $this->request->method('getParam')->willReturnCallback(
            static function (string $key, $default = null) {
                return match ($key) {
                    'boardId'     => 10,
                    'stackId'     => 20,
                    'title'       => 'New card',
                    'description' => 'Body',
                    'duedate'     => '2026-06-01T12:00:00+00:00',
                    default       => $default,
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
    }

    public function testDestroyReturns200OnSuccess(): void
    {
        $this->service->method('isDeckAvailable')->willReturn(true);
        $this->mockObject();
        $this->service->expects($this->once())
            ->method('unlinkCard')
            ->with('abc-123', 42);

        $response = $this->controller->destroy('reg', 'sch', 'obj', '42');

        $this->assertSame(200, $response->getStatus());
        $this->assertTrue($response->getData()['success']);
    }

    public function testDestroyReturns404WhenLinkMissing(): void
    {
        $this->service->method('isDeckAvailable')->willReturn(true);
        $this->mockObject();
        $this->service->method('unlinkCard')
            ->willThrowException(new Exception('Deck link not found', 404));

        $response = $this->controller->destroy('reg', 'sch', 'obj', '42');

        $this->assertSame(404, $response->getStatus());
    }

    public function testBoardsReturnsList(): void
    {
        $this->service->method('isDeckAvailable')->willReturn(true);
        $this->service->method('getAvailableBoards')->willReturn([
            ['id' => 1, 'title' => 'Sprint'],
            ['id' => 2, 'title' => 'Backlog'],
        ]);

        $response = $this->controller->boards();

        $this->assertSame(200, $response->getStatus());
        $this->assertSame(2, $response->getData()['total']);
    }

    public function testBoardsReturns501WhenDeckUnavailable(): void
    {
        $this->service->method('isDeckAvailable')->willReturn(false);

        $response = $this->controller->boards();

        $this->assertSame(501, $response->getStatus());
    }

    public function testStacksReturnsList(): void
    {
        $this->service->method('isDeckAvailable')->willReturn(true);
        $this->service->method('getStacksForBoard')->with(7)->willReturn([
            ['id' => 11, 'title' => 'To Do', 'boardId' => 7],
        ]);

        $response = $this->controller->stacks('7');

        $this->assertSame(200, $response->getStatus());
        $this->assertSame(1, $response->getData()['total']);
        $this->assertSame(11, $response->getData()['results'][0]['id']);
    }
}
