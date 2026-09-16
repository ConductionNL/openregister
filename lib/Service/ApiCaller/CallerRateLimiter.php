<?php

/**
 * Bounds one caller to its administered ceiling, and says so when it refuses.
 *
 * A gemeente hands a token to a leverancier and has no way to bound it. When
 * that leverancier's cron job goes into a loop at three in the morning, the
 * first anyone knows is the database.
 *
 * 🔑 THE REFUSAL NAMES THE LIMIT AND THE RESET TIME. A 429 that says only
 * "too many requests" tells an integrator to retry, which is the one thing
 * guaranteed to make it worse, and gives them nothing to size their client
 * against. The answer carries the ceiling, what is left, and the second the
 * window rolls, in the body and in `RateLimit-*` and `Retry-After` headers.
 *
 * 🔑 A FIXED WINDOW, AND THE DOCSTRING SAYS SO. A caller can spend its whole
 * ceiling in the last second of one window and again in the first second of
 * the next, so the true worst case over a rolling minute is twice the number
 * an administrator wrote down. A sliding window would fix that and costs a
 * sorted set per caller in a cache this app cannot assume is Redis. The
 * ceiling exists to stop a runaway loop, which a fixed window does, and not to
 * meter billing, which it would do badly.
 *
 * 🔴 THE CLOCK IS INJECTED. A limiter tested against `time()` can only be
 * tested by sleeping, so in practice it is tested by not testing the part that
 * matters: what happens as the window rolls.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ApiCaller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/api-as-a-versioned-surface/specs/api-surface-governance/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ApiCaller;

use OCP\ICacheFactory;
use OCP\IMemcache;
use Throwable;

/**
 * Counts a caller's calls inside a fixed window and refuses over the ceiling.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ApiCaller
 */
class CallerRateLimiter {

	/**
	 * Cache key prefix for the per-caller counters.
	 *
	 * @var string
	 */
	private const CACHE_PREFIX = 'openregister_api_caller_';

	/**
	 * The distributed counter store, or null when this instance has none.
	 *
	 * 🔴 IMemcache, NOT ICache. `ICacheFactory::createDistributed()` is declared
	 * to return `ICache`, which has no `inc()`: only `IMemcache` carries the
	 * atomic increment. A backend that is merely an `ICache` would fatal on the
	 * first call here, and the only alternative — read, add one, write — loses a
	 * count whenever two calls from the same caller overlap, which for a
	 * runaway integration is every call. So the cast is checked and the limiter
	 * simply does not apply when the store cannot count atomically.
	 *
	 * @var IMemcache|null
	 */
	private readonly ?IMemcache $cache;

	/**
	 * Constructor.
	 *
	 * @param ICacheFactory $cacheFactory Provides the distributed counter store.
	 * @param CallerPolicy $policy Resolves the administered ceiling.
	 *
	 * @return void
	 */
	public function __construct(
		ICacheFactory $cacheFactory,
		private readonly CallerPolicy $policy,
	) {
		$this->cache = $this->resolveCache(cacheFactory: $cacheFactory);

	}//end __construct()

	/**
	 * Count one call and say whether it is allowed.
	 *
	 * Fails OPEN on every internal error, and on an instance whose distributed
	 * cache cannot count atomically. A limiter that refuses traffic because its
	 * counter store is briefly unreachable converts somebody else's outage into
	 * this gemeente's outage, and the thing it is protecting against is a
	 * runaway loop, not an attacker.
	 *
	 * @param string $principal The caller, or the empty string for anonymous.
	 * @param int $now The current unix time, injected so the window can be tested.
	 *
	 * @return array{allowed: bool, limit: int, remaining: int, resetAt: int}|null
	 *         The outcome, or null when no ceiling is administered for this caller.
	 *
	 * @spec openspec/changes/api-as-a-versioned-surface/specs/api-surface-governance/spec.md#requirement-every-api-call-records-its-caller-and-a-caller-carries-a-limit-and-an-address-binding-req-avs-003
	 */
	public function consume(string $principal, int $now): ?array {
		$ceiling = $this->policy->limitFor(principal: $principal);
		if ($ceiling === null || $this->cache === null) {
			return null;
		}

		$window = $ceiling['windowSeconds'];
		$limit = $ceiling['limit'];

		$windowStart = ($now - ($now % $window));
		$resetAt = ($windowStart + $window);
		$key = $this->keyFor(principal: $principal, windowStart: $windowStart);

		try {
			$used = $this->cache->inc($key);
			if (is_int($used) === false) {
				return null;
			}

			// The counter has to expire, or a caller's first window would bound
			// it forever. Set the lifetime once, on the call that created it.
			if ($used === 1) {
				$this->cache->set($key, 1, ($window + 1));
			}
		} catch (Throwable) {
			return null;
		}

		return [
			'allowed' => ($used <= $limit),
			'limit' => $limit,
			'remaining' => max(0, ($limit - $used)),
			'resetAt' => $resetAt,
		];

	}//end consume()

	/**
	 * The cache key for one caller's window.
	 *
	 * The principal is hashed rather than interpolated: a uid can carry a
	 * character the cache backend treats as a separator, and a caller whose
	 * name collides with another caller's key is a limit applied to the wrong
	 * person.
	 *
	 * @param string $principal The caller.
	 * @param int $windowStart The unix time the window began.
	 *
	 * @return string The cache key.
	 */
	private function keyFor(string $principal, int $windowStart): string {
		$name = trim($principal);
		if ($name === '') {
			$name = CallerPolicy::ANONYMOUS;
		}

		return self::CACHE_PREFIX . hash('sha256', $name) . '_' . $windowStart;

	}//end keyFor()

	/**
	 * The distributed counter store, when this instance has one that can count.
	 *
	 * @param ICacheFactory $cacheFactory The factory.
	 *
	 * @return IMemcache|null The store, or null.
	 */
	private function resolveCache(ICacheFactory $cacheFactory): ?IMemcache {
		try {
			if ($cacheFactory->isAvailable() === false) {
				return null;
			}

			$cache = $cacheFactory->createDistributed('openregister_api_callers');
			if (($cache instanceof IMemcache) === false) {
				return null;
			}

			return $cache;
		} catch (Throwable) {
			return null;
		}

	}//end resolveCache()
}//end class
