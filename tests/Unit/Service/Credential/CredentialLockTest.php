<?php

/**
 * CredentialLockTest — the mutual exclusion around one credential's rotation.
 *
 * Two properties matter and both are tested here rather than assumed. First, a
 * second caller must NOT get the lock while a first holds it, which is what makes
 * one refresh happen instead of two. Second, an install with no distributed cache
 * must be told that its lock is advisory: a lock that silently is not one is worse
 * than no lock, because it makes a claim nobody can substantiate.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Credential
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/credential-oauth2-token-set/specs/credential-oauth2-token-set/spec.md#requirement-a-refresh-runs-under-a-per-credential-lock-and-rotates-atomically
 */

declare(strict_types=1);

namespace Unit\Service\Credential;

use OCA\OpenRegister\Service\Credential\CredentialLock;
use OCP\ICacheFactory;
use OCP\IMemcache;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Service\Credential\CredentialLock
 */
class CredentialLockTest extends TestCase {
	public function testASecondCallerIsRefusedWhileTheFirstHoldsTheLock(): void {
		$cache = $this->fakeMemcache();
		$lock = new CredentialLock(cacheFactory: $this->factoryFor(cache: $cache), logger: $this->createMock(LoggerInterface::class));

		$this->assertTrue($lock->acquire('credential-uuid'));
		$this->assertFalse($lock->acquire('credential-uuid'), 'a held lock must exclude a second caller');
	}

	public function testReleasingLetsTheNextCallerIn(): void {
		$cache = $this->fakeMemcache();
		$lock = new CredentialLock(cacheFactory: $this->factoryFor(cache: $cache), logger: $this->createMock(LoggerInterface::class));

		$lock->acquire('credential-uuid');
		$lock->release('credential-uuid');

		$this->assertTrue($lock->acquire('credential-uuid'));
	}

	public function testTwoCredentialsDoNotBlockEachOther(): void {
		$cache = $this->fakeMemcache();
		$lock = new CredentialLock(cacheFactory: $this->factoryFor(cache: $cache), logger: $this->createMock(LoggerInterface::class));

		$this->assertTrue($lock->acquire('credential-one'));
		$this->assertTrue($lock->acquire('credential-two'));
	}

	public function testWithoutADistributedCacheTheLockIsAdvisoryAndSaysSo(): void {
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('isAvailable')->willReturn(false);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')
			->with($this->stringContains('ADVISORY ONLY'));

		$lock = new CredentialLock(cacheFactory: $factory, logger: $logger);

		$this->assertFalse($lock->isEffective(), 'the degradation must be reportable, not silent');
		$this->assertTrue($lock->acquire('credential-uuid'), 'an advisory lock still lets work proceed');
	}

	public function testTheLockReportsItselfEffectiveWithADistributedCache(): void {
		$lock = new CredentialLock(
			cacheFactory: $this->factoryFor(cache: $this->fakeMemcache()),
			logger: $this->createMock(LoggerInterface::class)
		);

		$this->assertTrue($lock->isEffective());
	}

	/**
	 * A cache factory answering with the given memcache.
	 *
	 * @param IMemcache $cache The cache to hand out.
	 *
	 * @return ICacheFactory The factory.
	 */
	private function factoryFor(IMemcache $cache): ICacheFactory {
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('isAvailable')->willReturn(true);
		$factory->method('createDistributed')->willReturn($cache);

		return $factory;
	}

	/**
	 * An in-memory IMemcache whose add() is genuinely compare-and-set.
	 *
	 * A mock returning canned values could not tell "the lock excludes" from "the
	 * test told it to say so"; this fake actually holds state, so the exclusion is
	 * observed rather than stipulated.
	 *
	 * @return IMemcache The fake cache.
	 */
	private function fakeMemcache(): IMemcache {
		return new class implements IMemcache {
			/** @var array<string, mixed> */
			private array $entries = [];

			public function get($key) {
				return ($this->entries[$key] ?? null);
			}

			public function set($key, $value, $ttl = 0) {
				$this->entries[$key] = $value;
				return true;
			}

			public function hasKey($key) {
				return array_key_exists($key, $this->entries);
			}

			public function remove($key) {
				unset($this->entries[$key]);
				return true;
			}

			public function clear($prefix = '') {
				$this->entries = [];
				return true;
			}

			public function add($key, $value, $ttl = 0) {
				if (array_key_exists($key, $this->entries) === true) {
					return false;
				}

				$this->entries[$key] = $value;
				return true;
			}

			public function inc($key, $step = 1) {
				$this->entries[$key] = ((int)($this->entries[$key] ?? 0) + $step);
				return $this->entries[$key];
			}

			public function dec($key, $step = 1) {
				return $this->inc($key, -$step);
			}

			public function cas($key, $old, $new) {
				if (($this->entries[$key] ?? null) !== $old) {
					return false;
				}

				$this->entries[$key] = $new;
				return true;
			}

			public function cad($key, $old) {
				if (($this->entries[$key] ?? null) !== $old) {
					return false;
				}

				unset($this->entries[$key]);
				return true;
			}

			public function ncad(string $key, $old): bool {
				if (($this->entries[$key] ?? null) === $old) {
					return false;
				}

				unset($this->entries[$key]);
				return true;
			}

			public static function isAvailable(): bool {
				return true;
			}
		};
	}
}
