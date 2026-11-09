<?php

/**
 * Waiting on another refresh's credential lock.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Credential
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Credential;

use OCA\OpenRegister\Service\Credential\CredentialLock;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IMemcache;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A waiter returns as soon as the lock is gone; a cache without atomic add is no lock at all.
 *
 * @covers \OCA\OpenRegister\Service\Credential\CredentialLock
 */
final class CredentialLockWaitTest extends TestCase {

	private ICacheFactory&MockObject $factory;

	private LoggerInterface&MockObject $logger;

	/**
	 * Fresh collaborators per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->factory = $this->createMock(ICacheFactory::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * The lock under test.
	 *
	 * @return CredentialLock The lock.
	 */
	private function lock(): CredentialLock {
		return new CredentialLock(cacheFactory: $this->factory, logger: $this->logger);
	}//end lock()

	/**
	 * A waiter polls until the holder's key is gone, then returns.
	 *
	 * @return void
	 */
	public function testAWaiterReturnsOnceTheKeyIsGone(): void {
		$cache = $this->createMock(IMemcache::class);
		$cache->expects($this->exactly(2))->method('hasKey')->with('cred-1')->willReturnOnConsecutiveCalls(true, false);
		$this->factory->method('isAvailable')->willReturn(true);
		$this->factory->method('createDistributed')->with('openregister_credential_lock')->willReturn($cache);

		$this->lock()->waitForRelease(credentialId: 'cred-1');
	}//end testAWaiterReturnsOnceTheKeyIsGone()

	/**
	 * A distributed cache without atomic add is not used: the lock is advisory, not effective.
	 *
	 * @return void
	 */
	public function testACacheWithoutAtomicAddIsNoLock(): void {
		$this->factory->method('isAvailable')->willReturn(true);
		$this->factory->method('createDistributed')->willReturn($this->createMock(ICache::class));
		$this->logger->expects($this->once())->method('warning')->with($this->stringContains('ADVISORY ONLY'));

		$lock = $this->lock();

		$this->assertFalse($lock->isEffective());
		$this->assertTrue($lock->acquire(credentialId: 'cred-1'));
	}//end testACacheWithoutAtomicAddIsNoLock()

	/**
	 * Without any cache a waiter returns at once and a release is a no-op.
	 *
	 * @return void
	 */
	public function testWithoutACacheWaitingAndReleasingDoNothing(): void {
		$this->factory->method('isAvailable')->willReturn(false);
		$this->factory->expects($this->never())->method('createDistributed');

		$lock = $this->lock();
		$lock->waitForRelease(credentialId: 'cred-1');
		$lock->release(credentialId: 'cred-1');

		$this->assertFalse($lock->isEffective());
	}//end testWithoutACacheWaitingAndReleasingDoNothing()

	/**
	 * Acquire passes the TTL to the atomic add, and release removes the key.
	 *
	 * @return void
	 */
	public function testAcquireUsesTheTtlAndReleaseRemovesTheKey(): void {
		$cache = $this->createMock(IMemcache::class);
		$cache->expects($this->once())->method('add')->with('cred-2', 1, 12)->willReturn(false);
		$cache->expects($this->once())->method('remove')->with('cred-2');
		$this->factory->method('isAvailable')->willReturn(true);
		$this->factory->method('createDistributed')->willReturn($cache);

		$lock = $this->lock();

		$this->assertFalse($lock->acquire(credentialId: 'cred-2', ttlSeconds: 12));
		$lock->release(credentialId: 'cred-2');
	}//end testAcquireUsesTheTtlAndReleaseRemovesTheKey()
}//end class
