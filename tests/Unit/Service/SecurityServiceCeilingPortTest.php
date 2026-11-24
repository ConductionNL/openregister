<?php

/**
 * SecurityService reads the inbound-authentication ceiling through the Core port.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Kernel\Contracts\Core\AuthRateLimitCeilingInterface;
use OCA\OpenRegister\Service\Hardening\HardeningPolicy;
use OCA\OpenRegister\Service\SecurityService;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Service\SecurityService
 */
final class SecurityServiceCeilingPortTest extends TestCase {

	/**
	 * The ceiling SecurityService publishes is the one the port answers.
	 *
	 * @return void
	 */
	public function testTheCeilingComesFromThePort(): void {
		$ceiling = ['attemptsPerIdentity' => 3, 'attemptsPerAddress' => 7, 'windowSeconds' => 60, 'lockoutSeconds' => 120];
		$port = $this->createMock(originalClassName: AuthRateLimitCeilingInterface::class);
		$port->expects($this->once())->method('authRateLimit')->willReturn($ceiling);

		$cacheFactory = $this->createMock(originalClassName: ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($this->createMock(originalClassName: ICache::class));

		$service = new SecurityService($cacheFactory, $this->createMock(originalClassName: LoggerInterface::class), $port);

		$this->assertSame(expected: $ceiling, actual: $service->describeAuthRateLimit());
	}//end testTheCeilingComesFromThePort()

	/**
	 * The hardening policy is the class that answers the port.
	 *
	 * @return void
	 */
	public function testTheHardeningPolicyImplementsThePort(): void {
		$this->assertTrue(condition: is_subclass_of(HardeningPolicy::class, AuthRateLimitCeilingInterface::class));
	}//end testTheHardeningPolicyImplementsThePort()
}//end class
