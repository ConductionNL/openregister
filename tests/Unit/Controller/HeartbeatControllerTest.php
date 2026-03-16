<?php

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenRegister\Controller\HeartbeatController;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for HeartbeatController
 *
 * @package Unit\Controller
 */
class HeartbeatControllerTest extends TestCase
{
    private HeartbeatController $controller;
    private IRequest&MockObject $request;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request = $this->createMock(IRequest::class);

        $this->controller = new HeartbeatController(
            'openregister',
            $this->request
        );
    }

    public function testConstructorCreatesInstance(): void
    {
        $controller = new HeartbeatController(
            'openregister',
            $this->request
        );

        $this->assertInstanceOf(HeartbeatController::class, $controller);
    }

    public function testControllerExtendsBaseController(): void
    {
        $this->assertInstanceOf(Controller::class, $this->controller);
    }

    public function testHeartbeatReturnsJsonResponse(): void
    {
        $result = $this->controller->heartbeat();

        $this->assertInstanceOf(JSONResponse::class, $result);
    }

    public function testHeartbeatReturnsAliveStatus(): void
    {
        $result = $this->controller->heartbeat();
        $data = $result->getData();

        $this->assertSame('alive', $data['status']);
    }

    public function testHeartbeatReturnsTimestamp(): void
    {
        $before = time();
        $result = $this->controller->heartbeat();
        $after = time();

        $data = $result->getData();

        $this->assertArrayHasKey('timestamp', $data);
        $this->assertIsInt($data['timestamp']);
        $this->assertGreaterThanOrEqual($before, $data['timestamp']);
        $this->assertLessThanOrEqual($after, $data['timestamp']);
    }

    public function testHeartbeatReturnsMessage(): void
    {
        $result = $this->controller->heartbeat();
        $data = $result->getData();

        $this->assertSame('Heartbeat successful - connection kept alive', $data['message']);
    }

    public function testHeartbeatReturnsStatus200(): void
    {
        $result = $this->controller->heartbeat();

        $this->assertSame(Http::STATUS_OK, $result->getStatus());
    }

    public function testHeartbeatResponseContainsExactlyThreeKeys(): void
    {
        $result = $this->controller->heartbeat();
        $data = $result->getData();

        $this->assertCount(3, $data);
        $this->assertArrayHasKey('status', $data);
        $this->assertArrayHasKey('timestamp', $data);
        $this->assertArrayHasKey('message', $data);
    }

    public function testHeartbeatTimestampIsPositiveInteger(): void
    {
        $result = $this->controller->heartbeat();
        $data = $result->getData();

        $this->assertIsInt($data['timestamp']);
        $this->assertGreaterThan(0, $data['timestamp']);
    }

    public function testMultipleHeartbeatCallsReturnConsistentStructure(): void
    {
        $result1 = $this->controller->heartbeat();
        $result2 = $this->controller->heartbeat();

        $data1 = $result1->getData();
        $data2 = $result2->getData();

        $this->assertSame($data1['status'], $data2['status']);
        $this->assertSame($data1['message'], $data2['message']);
        $this->assertGreaterThanOrEqual($data1['timestamp'], $data2['timestamp']);
    }

    public function testConstructorWithDifferentAppName(): void
    {
        $controller = new HeartbeatController(
            'otherapp',
            $this->request
        );

        $result = $controller->heartbeat();

        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertSame(Http::STATUS_OK, $result->getStatus());
    }
}
