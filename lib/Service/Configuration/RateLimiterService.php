<?php

/**
 * OpenRegister Rate Limiter Service.
 *
 * Cache-backed rate limiter for the GitHub issues proxy endpoints (task 1.18). Wraps
 * `ICacheFactory` — which already selects the best available distributed backend (APCu →
 * Redis → Memcached → no-op Null) — and adds the missing piece: a fail-closed contract
 * when no usable backend is configured. Without that contract, `createDistributed()` on a
 * cache-less instance returns the Null backend and silently never rate-limits anything;
 * callers gate on `isOperational()` first and return HTTP 503 `rate_limiter_unavailable`
 * when it returns false.
 *
 * Two limiter shapes:
 *   - `consumeFixedWindow()` — "1 action per `$windowSeconds`" (used by the POST submission
 *     limiter, task 1.6).
 *   - `consumeFromBudget()` — "at most `$maxKeys` distinct sub-keys per rolling
 *     `$windowSeconds`" (used by the GET cache-miss limiter, task 1.19).
 *
 * Both return `null` when within budget and an `int` (whole seconds until the window
 * resets) when the budget is exhausted.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Configuration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Configuration;

use OCP\ICache;
use OCP\ICacheFactory;

/**
 * Cache-backed rate limiter with a fail-closed contract.
 *
 * @package OCA\OpenRegister\Service\Configuration
 *
 * @psalm-suppress UnusedClass
 */
class RateLimiterService implements RateLimiterServiceInterface {
	/**
	 * Distributed cache namespace shared by all rate-limit buckets in this service.
	 */
	private const CACHE_PREFIX = 'openregister_rate_limiter';

	/**
	 * Resolved cache instance. May be a no-op Null backend when no cache is configured —
	 * callers MUST check `isOperational()` before relying on rate-limit behaviour.
	 *
	 * @var ICache
	 */
	private ICache $cache;

	/**
	 * RateLimiterService constructor.
	 *
	 * @param ICacheFactory $cacheFactory Cache factory — used both to create the distributed
	 *                                    cache and to introspect backend availability.
	 */
	public function __construct(
		private readonly ICacheFactory $cacheFactory,
	) {
		$this->cache = $cacheFactory->createDistributed(self::CACHE_PREFIX);
	}//end __construct()

	/**
	 * Whether a usable cache backend is available. False on a cache-less instance, in which
	 * case `createDistributed()` would have returned the no-op Null backend and any
	 * rate-limit check would silently pass — callers fail closed (HTTP 503
	 * `rate_limiter_unavailable`) instead.
	 *
	 * @return bool True when APCu and/or a distributed cache is configured.
	 *
	 * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-18
	 */
	public function isOperational(): bool {
		if ($this->cacheFactory->isAvailable() === true) {
			return true;
		}

		if ($this->cacheFactory->isLocalCacheAvailable() === true) {
			return true;
		}

		return false;
	}//end isOperational()

	/**
	 * Fixed-window limiter: at most one action per `$windowSeconds` for the given `$bucketKey`.
	 *
	 * Call this BEFORE performing the action. Returns null when the action is permitted; on
	 * permit, the caller MUST call `markFixedWindow()` after the action succeeds so the slot is
	 * consumed. Returns the remaining-window seconds when the slot is still occupied.
	 *
	 * @param string $bucketKey Caller-namespaced bucket key (e.g. `feature_submission:<uid>`).
	 * @param int $windowSeconds Minimum gap between actions.
	 *
	 * @return int|null Null when permitted, retry-after seconds (≥ 1) when rate-limited.
	 *
	 * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-6
	 * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-18
	 */
	public function checkFixedWindow(string $bucketKey, int $windowSeconds): ?int {
		$occupiedAt = $this->cache->get($bucketKey);
		if ($occupiedAt === null) {
			return null;
		}

		return max(1, $windowSeconds - (time() - (int)$occupiedAt));
	}//end checkFixedWindow()

	/**
	 * Consume the fixed-window slot for `$bucketKey` (call after `checkFixedWindow()` returned
	 * null and the action succeeded).
	 *
	 * @param string $bucketKey Caller-namespaced bucket key.
	 * @param int $windowSeconds TTL for the slot.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-6
	 */
	public function markFixedWindow(string $bucketKey, int $windowSeconds): void {
		$this->cache->set($bucketKey, time(), $windowSeconds);
	}//end markFixedWindow()

	/**
	 * Distinct-key-budget limiter: at most `maxKeys` distinct `$distinctKey` values seen for
	 * the budget's `bucketKey` within a rolling `windowSeconds`. Used by the GET cache-miss
	 * limiter — each distinct cache-key tuple counts once, repeats are free, and the window
	 * rolls over once it expires.
	 *
	 * The caller does NOT need a separate "mark" step — this method records the key as part of
	 * the same call when the budget is not yet exhausted.
	 *
	 * @param DistinctKeyBudget $budget      The bucket, its maximum and its window.
	 * @param string            $distinctKey The value being counted (e.g. the read cache key).
	 *
	 * @return int|null Null when within budget, retry-after seconds (≥ 1) when exhausted.
	 *
	 * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-19
	 * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-18
	 */
	public function consumeFromBudget(DistinctKeyBudget $budget, string $distinctKey): ?int {
		[$bucket, $age] = $this->liveBucket(bucket: $this->cache->get($budget->bucketKey), windowSeconds: $budget->windowSeconds);

		$keys = (array)($bucket['keys'] ?? []);
		if (in_array($distinctKey, $keys, true) === false) {
			$keys[] = $distinctKey;
		}

		if (count($keys) > $budget->maxKeys) {
			return max(1, $budget->windowSeconds - $age);
		}

		$bucket['keys'] = $keys;
		$this->cache->set($budget->bucketKey, $bucket, $budget->windowSeconds);
		return null;
	}//end consumeFromBudget()

	/**
	 * The stored bucket and its age, or a fresh bucket of age 0 when none is
	 * stored or the stored one is at least one window old.
	 *
	 * @param mixed $bucket        What the cache holds for the bucket key.
	 * @param int   $windowSeconds Rolling window length.
	 *
	 * @return array{0: array<string, mixed>, 1: int} The bucket and its age in seconds.
	 */
	private function liveBucket(mixed $bucket, int $windowSeconds): array {
		if (is_array($bucket) === false) {
			$bucket = ['t' => time(), 'keys' => []];
		}

		$age = time() - (int)($bucket['t'] ?? 0);
		if ($age >= $windowSeconds) {
			return [['t' => time(), 'keys' => []], 0];
		}

		return [$bucket, $age];
	}//end liveBucket()
}//end class
