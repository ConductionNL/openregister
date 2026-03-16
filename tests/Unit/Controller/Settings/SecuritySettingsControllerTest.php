<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Settings;

use OCA\OpenRegister\Controller\Settings\SecuritySettingsController;
use OCA\OpenRegister\Service\SecurityService;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SecuritySettingsControllerTest extends TestCase
{
    private SecuritySettingsController $controller;
    private IRequest&MockObject $request;
    private SecurityService&MockObject $securityService;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request = $this->createMock(IRequest::class);
        $this->securityService = $this->createMock(SecurityService::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->controller = new SecuritySettingsController(
            'openregister',
            $this->request,
            $this->securityService,
            $this->logger
        );
    }

    public function testClearIpRateLimitsSuccess(): void
    {
        $this->request->method('getParams')->willReturn(['ip' => '192.168.1.1']);

        $result = $this->controller->clearIpRateLimits();

        $this->assertEquals(200, $result->getStatus());
        $this->assertTrue($result->getData()['success']);
        $this->assertEquals('192.168.1.1', $result->getData()['ip_address']);
    }

    public function testClearIpRateLimitsMissingIp(): void
    {
        $this->request->method('getParams')->willReturn([]);

        $result = $this->controller->clearIpRateLimits();

        $this->assertEquals(400, $result->getStatus());
        $this->assertArrayHasKey('error', $result->getData());
    }

    public function testClearIpRateLimitsException(): void
    {
        $this->request->method('getParams')->willReturn(['ip' => '192.168.1.1']);
        $this->securityService->method('clearIpRateLimits')
            ->willThrowException(new \Exception('Failed'));

        $result = $this->controller->clearIpRateLimits();

        $this->assertEquals(500, $result->getStatus());
    }

    public function testClearUserRateLimitsSuccess(): void
    {
        $this->request->method('getParams')->willReturn(['username' => 'testuser']);

        $result = $this->controller->clearUserRateLimits();

        $this->assertEquals(200, $result->getStatus());
        $this->assertTrue($result->getData()['success']);
        $this->assertEquals('testuser', $result->getData()['username']);
    }

    public function testClearUserRateLimitsMissingUsername(): void
    {
        $this->request->method('getParams')->willReturn([]);

        $result = $this->controller->clearUserRateLimits();

        $this->assertEquals(400, $result->getStatus());
    }

    public function testClearUserRateLimitsException(): void
    {
        $this->request->method('getParams')->willReturn(['username' => 'testuser']);
        $this->securityService->method('clearUserRateLimits')
            ->willThrowException(new \Exception('Failed'));

        $result = $this->controller->clearUserRateLimits();

        $this->assertEquals(500, $result->getStatus());
    }

    public function testClearAllRateLimitsSuccess(): void
    {
        $this->request->method('getParams')->willReturn([
            'ip' => '192.168.1.1',
            'username' => 'testuser',
        ]);

        $result = $this->controller->clearAllRateLimits();

        $this->assertEquals(200, $result->getStatus());
        $this->assertTrue($result->getData()['success']);
        $this->assertEquals('192.168.1.1', $result->getData()['cleared']['ip_address']);
        $this->assertEquals('testuser', $result->getData()['cleared']['username']);
    }

    public function testClearAllRateLimitsMissingBoth(): void
    {
        $this->request->method('getParams')->willReturn([]);

        $result = $this->controller->clearAllRateLimits();

        $this->assertEquals(400, $result->getStatus());
    }

    public function testClearAllRateLimitsIpOnly(): void
    {
        $this->request->method('getParams')->willReturn(['ip' => '10.0.0.1']);

        $result = $this->controller->clearAllRateLimits();

        $this->assertEquals(200, $result->getStatus());
        $this->assertArrayHasKey('ip_address', $result->getData()['cleared']);
        $this->assertArrayNotHasKey('username', $result->getData()['cleared']);
    }

    public function testClearAllRateLimitsException(): void
    {
        $this->request->method('getParams')->willReturn(['ip' => '10.0.0.1']);
        $this->securityService->method('clearIpRateLimits')
            ->willThrowException(new \Exception('Failed'));

        $result = $this->controller->clearAllRateLimits();

        $this->assertEquals(500, $result->getStatus());
    }

    public function testClearAllRateLimitsUsernameOnly(): void
    {
        $this->request->method('getParams')->willReturn(['username' => 'blockeduser']);

        $result = $this->controller->clearAllRateLimits();

        $this->assertEquals(200, $result->getStatus());
        $this->assertArrayHasKey('username', $result->getData()['cleared']);
        $this->assertArrayNotHasKey('ip_address', $result->getData()['cleared']);
    }
}
