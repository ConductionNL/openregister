<?php

/**
 * Tests for the abandoned-seal-lock recovery.
 *
 * ILockingProvider has no owner and no liveness check, and DBLockingProvider
 * only reaps expired rows from a separate cleanup job. So a process killed
 * inside its critical section keeps the lock for the rest of its TTL — measured
 * at 46 minutes on a live instance after an interrupted upgrade.
 *
 * That is worse than an outage because it is a SILENT one: every sweep in that
 * window returns 0, which is also the value meaning "nothing to seal". A dead
 * sweeper and an idle one are indistinguishable from outside while the backlog
 * grows, which is exactly how 49,123 unsealed rows accumulated unnoticed.
 *
 * The recovery therefore has to be conservative in one direction and decisive
 * in the other, and both directions are asserted here: never steal a lock a
 * live pass might still hold, always break one no live pass could.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/audit-hash-chain/spec.md
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenRegister\Service\AuditHashService;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Proves an abandoned seal lock is broken, and a live one is not.
 */
class AuditHashStaleLockTest extends TestCase {

	/**
	 * Mocked lock provider.
	 *
	 * @var ILockingProvider&MockObject
	 */
	private ILockingProvider&MockObject $locks;

	/**
	 * Mocked app config holding the acquisition timestamp.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * Service under test.
	 *
	 * @var AuditHashService
	 */
	private AuditHashService $service;

	/**
	 * Wire the service with a lock that is always already held.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->locks = $this->createMock(ILockingProvider::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->service = new AuditHashService(
			$this->createMock(IDBConnection::class),
			$this->locks,
			$this->createMock(LoggerInterface::class),
			$this->appConfig
		);

	}//end setUp()

	/**
	 * Call the private acquire path.
	 *
	 * @return bool Whether the lock was obtained.
	 */
	private function acquire(): bool {
		$method = new ReflectionMethod(AuditHashService::class, 'acquireSealLock');
		$method->setAccessible(true);

		return (bool)$method->invoke($this->service);
	}//end acquire()

	/**
	 * A lock held only moments ago is left alone.
	 *
	 * This is the direction that must not be got wrong. A live pass can hold
	 * the lock legitimately, and stealing it would put two writers into the
	 * chain at once — reintroducing the fan-out the lock exists to prevent.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/audit-hash-chain/spec.md
	 */
	public function testALockHeldRecentlyIsNotBroken(): void {
		$this->locks->method('acquireLock')->willThrowException(new LockedException('audit-seal'));
		$this->appConfig->method('getValueInt')->willReturn(time());

		// Never released: the holder may still be working.
		$this->locks->expects($this->never())->method('releaseLock');

		$this->assertFalse($this->acquire(), 'A recently taken lock must be respected');

	}//end testALockHeldRecentlyIsNotBroken()

	/**
	 * A lock with no recorded acquisition is left alone.
	 *
	 * A missing timestamp means the holder predates this bookkeeping — it fails
	 * safe rather than guessing, which is why the two locks left by an
	 * interrupted upgrade had to be cleared by hand.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/audit-hash-chain/spec.md
	 */
	public function testALockWithNoRecordedTimestampIsNotBroken(): void {
		$this->locks->method('acquireLock')->willThrowException(new LockedException('audit-seal'));
		$this->appConfig->method('getValueInt')->willReturn(0);

		$this->locks->expects($this->never())->method('releaseLock');

		$this->assertFalse($this->acquire());

	}//end testALockWithNoRecordedTimestampIsNotBroken()

	/**
	 * A lock held longer than any real pass could run is broken and taken.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/audit-hash-chain/spec.md
	 */
	public function testAnAbandonedLockIsBrokenAndAcquired(): void {
		$attempt = 0;
		$this->locks->method('acquireLock')
			->willReturnCallback(
				function () use (&$attempt): void {
					$attempt++;
					// The retry budget (SEAL_LOCK_ATTEMPTS, private — hence the
					// literal) is exhausted first; the acquisition that follows
					// the release is the one that succeeds.
					if ($attempt <= 3) {
						throw new LockedException('audit-seal');
					}
				}
			);

		// Two hours: far beyond anything a bounded pass can take.
		$this->appConfig->method('getValueInt')->willReturn((time() - 7200));

		$this->locks->expects($this->once())->method('releaseLock');

		$this->assertTrue($this->acquire(), 'An abandoned lock must be broken so sealing resumes');

	}//end testAnAbandonedLockIsBrokenAndAcquired()

	/**
	 * If the break itself fails, the caller is told so rather than assuming.
	 *
	 * Reporting success here would hand the sweep a lock it does not hold.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/audit-hash-chain/spec.md
	 */
	public function testAFailedBreakReportsFailure(): void {
		$this->locks->method('acquireLock')->willThrowException(new LockedException('audit-seal'));
		$this->locks->method('releaseLock')->willThrowException(new \RuntimeException('backend down'));
		$this->appConfig->method('getValueInt')->willReturn((time() - 7200));

		$this->assertFalse($this->acquire());

	}//end testAFailedBreakReportsFailure()
}//end class
