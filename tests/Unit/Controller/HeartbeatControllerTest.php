<?php

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenRegister\Controller\HeartbeatController;
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

        $this->assertSame(200, $result->getStatus());
    }
}
