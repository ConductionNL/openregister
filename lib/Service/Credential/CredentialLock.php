<?php

/**
 * CredentialLock — a short-lived mutual exclusion around one credential's rotation.
 *
 * A token refresh reads a token set, exchanges it at the provider, and writes the
 * result back. Two callers doing that at once is not merely wasteful: a provider
 * that rotates refresh tokens on every use invalidates the loser's refresh token,
 * so the second write can store a set the provider has already retired. This lock
 * makes exactly one caller per credential perform the exchange, and makes the
 * others re-read instead.
 *
 * The primitive is `IMemcache::add()` on the distributed cache, which is atomic
 * across processes, with a TTL so a crashed holder releases the credential by
 * itself rather than wedging it.
 *
 * WHEN THERE IS NO DISTRIBUTED CACHE THERE IS NO LOCK, AND THIS CLASS SAYS SO.
 * `ICacheFactory::createDistributed()` falls back to a local, per-process cache
 * that cannot exclude anything, and a lock that silently is not one is worse than
 * no lock at all — "we take a lock" has to be a claim someone can substantiate,
 * the same reason ADR-064 requires the credential-store resolver to log which leaf
 * served. {@see acquire()} therefore logs a warning on every degraded acquire and
 * reports the degradation through {@see isEffective()}. The consequence is bounded:
 * the custody write is a single atomic `put`, so the worst case is a duplicated
 * exchange rather than a torn token set.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Credential
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Credential;

use OCP\ICacheFactory;
use OCP\IMemcache;
use Psr\Log\LoggerInterface;

/**
 * Per-credential advisory lock backed by the distributed cache.
 */
class CredentialLock {
	/**
	 * Cache namespace for credential rotation locks.
	 *
	 * @var string
	 */
	private const CACHE_PREFIX = 'openregister_credential_lock';

	/**
	 * How long a holder may keep the lock before it expires by itself, in seconds.
	 *
	 * Comfortably longer than a token exchange (a few seconds at worst) and short
	 * enough that a crashed process does not block the credential for a whole
	 * refresh cycle.
	 *
	 * @var integer
	 */
	public const DEFAULT_TTL_SECONDS = 30;

	/**
	 * How long a waiter watches for the holder to finish, in milliseconds.
	 *
	 * @var integer
	 */
	private const WAIT_BUDGET_MS = 5000;

	/**
	 * How long a waiter sleeps between polls, in microseconds.
	 *
	 * @var integer
	 */
	private const WAIT_POLL_US = 100000;

	/**
	 * Constructor.
	 *
	 * @param ICacheFactory $cacheFactory Supplies the distributed cache the lock lives in.
	 * @param LoggerInterface $logger Reports a degraded, non-excluding lock.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ICacheFactory $cacheFactory,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether this lock can actually exclude a second process.
	 *
	 * @return boolean True when a distributed cache offering an atomic add is available.
	 *
	 * @spec openspec/changes/credential-oauth2-token-set/specs/credential-oauth2-token-set/spec.md#requirement-a-refresh-runs-under-a-per-credential-lock-and-rotates-atomically
	 */
	public function isEffective(): bool {
		return ($this->cacheFactory->isAvailable() === true && $this->memcache() !== null);
	}//end isEffective()

	/**
	 * Try to take the lock for one credential.
	 *
	 * @param string $credentialId The credential UUID the lock is keyed by.
	 * @param integer $ttlSeconds How long the lock survives without an explicit release.
	 *
	 * @return boolean True when this caller now holds the lock.
	 *
	 * @spec openspec/changes/credential-oauth2-token-set/specs/credential-oauth2-token-set/spec.md#requirement-a-refresh-runs-under-a-per-credential-lock-and-rotates-atomically
	 */
	public function acquire(string $credentialId, int $ttlSeconds = self::DEFAULT_TTL_SECONDS): bool {
		$cache = $this->memcache();
		if ($cache === null) {
			$this->logger->warning(
				'[CredentialLock] no distributed cache with an atomic add is available, so the refresh lock is'
				. ' ADVISORY ONLY and a concurrent refresh of the same credential is possible. Configure'
				. ' memcache.distributed to make this lock effective.'
			);

			return true;
		}

		return $cache->add($credentialId, 1, $ttlSeconds);
	}//end acquire()

	/**
	 * Release the lock (idempotent).
	 *
	 * @param string $credentialId The credential UUID the lock is keyed by.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/credential-oauth2-token-set/specs/credential-oauth2-token-set/spec.md#requirement-a-refresh-runs-under-a-per-credential-lock-and-rotates-atomically
	 */
	public function release(string $credentialId): void {
		$this->memcache()?->remove($credentialId);
	}//end release()

	/**
	 * Wait, briefly, for whoever holds the lock to finish.
	 *
	 * The caller re-reads the stored token set afterwards rather than refreshing:
	 * after a successful rotation by the holder, that re-read finds a token outside
	 * the margin and no second exchange happens. Returning after the budget expires
	 * is deliberate — the caller then decides, with the token set in front of it,
	 * whether there is still work to do.
	 *
	 * @param string $credentialId The credential UUID the lock is keyed by.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/credential-oauth2-token-set/specs/credential-oauth2-token-set/spec.md#requirement-a-refresh-runs-under-a-per-credential-lock-and-rotates-atomically
	 */
	public function waitForRelease(string $credentialId): void {
		$cache = $this->memcache();
		if ($cache === null) {
			return;
		}

		$deadline = (microtime(true) + (self::WAIT_BUDGET_MS / 1000));
		while (microtime(true) < $deadline) {
			if ($cache->hasKey($credentialId) === false) {
				return;
			}

			usleep(self::WAIT_POLL_US);
		}
	}//end waitForRelease()

	/**
	 * Resolve the distributed cache when it offers an atomic add, else null.
	 *
	 * @return IMemcache|null The cache, or null when no atomic primitive is available.
	 *
	 * @spec exclude private cache accessor; its behaviour is asserted through acquire() and isEffective()
	 */
	private function memcache(): ?IMemcache {
		if ($this->cacheFactory->isAvailable() === false) {
			return null;
		}

		$cache = $this->cacheFactory->createDistributed(self::CACHE_PREFIX);
		if ($cache instanceof IMemcache) {
			return $cache;
		}

		return null;
	}//end memcache()
}//end class
