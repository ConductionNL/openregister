<?php

/**
 * Golden test for the rate-limit actions of SecuritySettingsController.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller\Settings
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Settings;

use Exception;
use OCA\OpenRegister\Controller\Settings\SecuritySettingsController;
use OCA\OpenRegister\Service\SecurityService;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins status, body, service calls and log messages of every branch.
 */
class SecuritySettingsControllerGoldenTest extends TestCase {
	private IRequest&MockObject $request;
	private SecurityService&MockObject $security;
	private LoggerInterface&MockObject $logger;
	private SecuritySettingsController $controller;

	/** @var list<string> */
	private array $cleared = [];

	/** @var list<array{string, string, array<string, mixed>}> */
	private array $logs = [];

	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->security = $this->createMock(SecurityService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->security->method('clearIpRateLimits')->willReturnCallback(function (string $ip): void {
			$this->cleared[] = 'ip:'.$ip;
		});
		$this->security->method('clearUserRateLimits')->willReturnCallback(function (string $user): void {
			$this->cleared[] = 'user:'.$user;
		});
		foreach (['info', 'error'] as $level) {
			$this->logger->method($level)->willReturnCallback(function (string $message, array $context = []) use ($level): void {
				unset($context['file'], $context['line']);
				$this->logs[] = [$level, $message, $context];
			});
		}

		$this->controller = new SecuritySettingsController('openregister', $this->request, $this->security, $this->logger);
	}//end setUp()

	/**
	 * @param array<string, mixed> $params Request parameters.
	 */
	private function params(array $params): void {
		$this->request->method('getParams')->willReturn($params);
	}//end params()

	public function testClearIpSucceeds(): void {
		$this->params(['ip' => '10.0.0.1']);
		$response = $this->controller->clearIpRateLimits();
		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['success' => true, 'message' => 'IP rate limits cleared successfully', 'ip_address' => '10.0.0.1'], $response->getData());
		$this->assertSame(['ip:10.0.0.1'], $this->cleared);
		$this->assertSame([['info', '[SecuritySettingsController] IP rate limits cleared by admin', ['ip_address' => '10.0.0.1']]], $this->logs);
	}//end testClearIpSucceeds()

	public function testClearIpRefusesEmptyIp(): void {
		$this->params(['ip' => '']);
		$response = $this->controller->clearIpRateLimits();
		$this->assertSame(400, $response->getStatus());
		$this->assertSame(['error' => 'IP address is required'], $response->getData());
		$this->assertSame([], $this->cleared);
		$this->assertSame([], $this->logs);
	}//end testClearIpRefusesEmptyIp()

	public function testClearIpReportsFailure(): void {
		$this->params(['ip' => '10.0.0.1']);
		$this->security = $this->createMock(SecurityService::class);
		$this->security->method('clearIpRateLimits')->willThrowException(new Exception('boom'));
		$controller = new SecuritySettingsController('openregister', $this->request, $this->security, $this->logger);
		$response = $controller->clearIpRateLimits();
		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['error' => 'boom'], $response->getData());
		$this->assertSame([['error', '[SecuritySettingsController] Failed to clear IP rate limits', ['error' => 'boom']]], $this->logs);
	}//end testClearIpReportsFailure()

	public function testClearUserSucceeds(): void {
		$this->params(['username' => 'alice']);
		$response = $this->controller->clearUserRateLimits();
		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['success' => true, 'message' => 'User rate limits cleared successfully', 'username' => 'alice'], $response->getData());
		$this->assertSame(['user:alice'], $this->cleared);
		$this->assertSame([['info', '[SecuritySettingsController] User rate limits cleared by admin', ['username' => 'alice']]], $this->logs);
	}//end testClearUserSucceeds()

	public function testClearUserRefusesMissingUsername(): void {
		$this->params([]);
		$response = $this->controller->clearUserRateLimits();
		$this->assertSame(400, $response->getStatus());
		$this->assertSame(['error' => 'Username is required'], $response->getData());
		$this->assertSame([], $this->cleared);
	}//end testClearUserRefusesMissingUsername()

	public function testClearUserReportsFailure(): void {
		$this->params(['username' => 'alice']);
		$this->security = $this->createMock(SecurityService::class);
		$this->security->method('clearUserRateLimits')->willThrowException(new Exception('nope'));
		$controller = new SecuritySettingsController('openregister', $this->request, $this->security, $this->logger);
		$response = $controller->clearUserRateLimits();
		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['error' => 'nope'], $response->getData());
		$this->assertSame([['error', '[SecuritySettingsController] Failed to clear user rate limits', ['error' => 'nope']]], $this->logs);
	}//end testClearUserReportsFailure()

	public function testClearAllClearsBoth(): void {
		$this->params(['ip' => '10.0.0.2', 'username' => 'bob']);
		$response = $this->controller->clearAllRateLimits();
		$this->assertSame(200, $response->getStatus());
		$this->assertSame(
			['success' => true, 'message' => 'Rate limits cleared successfully', 'cleared' => ['ip_address' => '10.0.0.2', 'username' => 'bob']],
			$response->getData()
		);
		$this->assertSame(['ip:10.0.0.2', 'user:bob'], $this->cleared);
		$this->assertSame([['info', '[SecuritySettingsController] Rate limits cleared by admin', ['ip_address' => '10.0.0.2', 'username' => 'bob']]], $this->logs);
	}//end testClearAllClearsBoth()

	public function testClearAllClearsOnlyTheUser(): void {
		$this->params(['ip' => '', 'username' => 'bob']);
		$response = $this->controller->clearAllRateLimits();
		$this->assertSame(['success' => true, 'message' => 'Rate limits cleared successfully', 'cleared' => ['username' => 'bob']], $response->getData());
		$this->assertSame(['user:bob'], $this->cleared);
	}//end testClearAllClearsOnlyTheUser()

	public function testClearAllClearsOnlyTheIp(): void {
		$this->params(['ip' => '10.0.0.3']);
		$response = $this->controller->clearAllRateLimits();
		$this->assertSame(['success' => true, 'message' => 'Rate limits cleared successfully', 'cleared' => ['ip_address' => '10.0.0.3']], $response->getData());
		$this->assertSame(['ip:10.0.0.3'], $this->cleared);
	}//end testClearAllClearsOnlyTheIp()

	public function testClearAllRefusesNeither(): void {
		$this->params(['ip' => null, 'username' => '']);
		$response = $this->controller->clearAllRateLimits();
		$this->assertSame(400, $response->getStatus());
		$this->assertSame(['error' => 'At least one of IP address or username is required'], $response->getData());
		$this->assertSame([], $this->cleared);
		$this->assertSame([], $this->logs);
	}//end testClearAllRefusesNeither()

	public function testClearAllReportsFailure(): void {
		$this->params(['ip' => '10.0.0.4', 'username' => 'carol']);
		$this->security = $this->createMock(SecurityService::class);
		$this->security->method('clearUserRateLimits')->willThrowException(new Exception('down'));
		$controller = new SecuritySettingsController('openregister', $this->request, $this->security, $this->logger);
		$response = $controller->clearAllRateLimits();
		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['error' => 'down'], $response->getData());
		$this->assertSame([['error', '[SecuritySettingsController] Failed to clear rate limits', ['error' => 'down']]], $this->logs);
	}//end testClearAllReportsFailure()
}//end class
