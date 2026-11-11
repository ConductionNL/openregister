<?php

/**
 * McpProtocolService behaviour tests.
 *
 * Session lifecycle details beyond the existing protocol tests: the exact
 * cache write on create, user-id stringification on validate, and removal
 * on destroy.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Mcp\Service\Mcp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Mcp\Service\Mcp;

use OCA\OpenRegister\Mcp\Service\Mcp\McpProtocolService;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Mcp\Service\Mcp\McpProtocolService
 */
class McpProtocolServiceBehaviourTest extends TestCase {

	private ICache&MockObject $cache;

	private ISecureRandom&MockObject $random;

	private LoggerInterface&MockObject $logger;

	private McpProtocolService $service;

	/**
	 * Build the service over a mocked distributed cache.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->cache = $this->createMock(ICache::class);
		$factory     = $this->createMock(ICacheFactory::class);
		$factory->expects($this->once())->method('createDistributed')
			->with('openregister_mcp_sessions')
			->willReturn($this->cache);
		$this->random  = $this->createMock(ISecureRandom::class);
		$this->logger  = $this->createMock(LoggerInterface::class);
		$this->service = new McpProtocolService($factory, $this->random, $this->logger);
	}//end setUp()

	/**
	 * initialize() stores a 32-char alphanumeric session for the user with a one-hour TTL.
	 *
	 * @return void
	 */
	public function testInitializeStoresSessionForUser(): void {
		$this->random->expects($this->once())->method('generate')
			->with(32, ISecureRandom::CHAR_ALPHANUMERIC)
			->willReturn('sess-1');
		$this->cache->expects($this->once())->method('set')->with('sess-1', 'alice', 3600);

		$response = $this->service->initialize([], 'alice');

		$this->assertSame('sess-1', $response['sessionId']);
		$this->assertSame('2025-03-26', $response['result']['protocolVersion']);
		$this->assertSame(['name' => 'OpenRegister', 'version' => '1.0.0'], $response['result']['serverInfo']);
	}//end testInitializeStoresSessionForUser()

	/**
	 * A cached non-string user id is returned as a string.
	 *
	 * @return void
	 */
	public function testValidateSessionStringifiesUserId(): void {
		$this->cache->method('get')->with('s')->willReturn(42);
		$this->logger->expects($this->never())->method('debug');

		$this->assertSame('42', $this->service->validateSession('s'));
	}//end testValidateSessionStringifiesUserId()

	/**
	 * A missing session is null and logged.
	 *
	 * @return void
	 */
	public function testValidateSessionMissingIsNull(): void {
		$this->cache->method('get')->willReturn(null);
		$this->logger->expects($this->once())->method('debug')
			->with('[MCP] Invalid or expired session', ['sessionId' => 'gone']);

		$this->assertNull($this->service->validateSession('gone'));
	}//end testValidateSessionMissingIsNull()

	/**
	 * destroySession() removes the session key and logs it.
	 *
	 * @return void
	 */
	public function testDestroySessionRemovesKey(): void {
		$this->cache->expects($this->once())->method('remove')->with('s-9');
		$this->logger->expects($this->once())->method('debug')
			->with('[MCP] Session destroyed', ['sessionId' => 's-9']);

		$this->service->destroySession('s-9');
	}//end testDestroySessionRemovesKey()
}//end class
