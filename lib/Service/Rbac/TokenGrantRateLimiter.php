<?php

/**
 * Counts a granted token's calls against the rate limit on its grant.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md#requirement-a-token-carries-its-own-rate-limit-and-outbound-destinations-are-allowlisted-req-sat-005
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use OCP\ICacheFactory;
use OCP\IMemcache;
use Throwable;

/**
 * A fixed one-minute window per token, counted in the distributed cache.
 *
 * The limit on a grant is a number of calls per minute. The counter lives in
 * the shared cache so every web node counts the same token together, which
 * is what lets one runaway integration be stopped instead of being allowed
 * once per node. Without a distributed memcache it counts nothing and refuses
 * nothing: a limit the platform cannot count is reported as not enforced, not
 * turned into a refusal of every call.
 *
 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md#requirement-a-token-carries-its-own-rate-limit-and-outbound-destinations-are-allowlisted-req-sat-005
 */
class TokenGrantRateLimiter {

	/**
	 * The window the grant's limit is counted over, in seconds.
	 *
	 * @var int
	 */
	public const WINDOW_SECONDS = 60;

	/**
	 * Cache key prefix.
	 *
	 * @var string
	 */
	private const CACHE_PREFIX = 'openregister_token_grant_';

	/**
	 * The shared counter store, or null when none is available.
	 *
	 * @var IMemcache|null
	 */
	private readonly ?IMemcache $cache;

	/**
	 * Constructor.
	 *
	 * @param ICacheFactory $cacheFactory Where the distributed counter lives.
	 */
	public function __construct(ICacheFactory $cacheFactory) {
		$this->cache = $this->resolveCache(cacheFactory: $cacheFactory);
	}//end __construct()

	/**
	 * Count one call by the token and say whether it is within the limit.
	 *
	 * @param TokenGrant $grant The grant bound to the call.
	 * @param int        $now   The current unix time.
	 *
	 * @return array{allowed: bool, limit: int, remaining: int, resetAt: int}|null Null when the grant
	 *     carries no limit or no id, or the counter cannot be kept.
	 *
	 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md#scenario-one-runaway-integration-does-not-take-the-balie-down
	 */
	public function consume(TokenGrant $grant, int $now): ?array {
		$limit = $grant->rateLimit;
		if ($limit === null || $limit < 1 || $grant->tokenId === '' || $this->cache === null) {
			return null;
		}

		$windowStart = ($now - ($now % self::WINDOW_SECONDS));
		$used        = $this->countCall(key: self::CACHE_PREFIX . hash('sha256', $grant->tokenId) . '_' . $windowStart);
		if ($used === null) {
			return null;
		}

		return [
			'allowed'   => ($used <= $limit),
			'limit'     => $limit,
			'remaining' => max(0, ($limit - $used)),
			'resetAt'   => ($windowStart + self::WINDOW_SECONDS),
		];
	}//end consume()

	/**
	 * Increment a window's counter, giving it a lifetime on its first call.
	 *
	 * @param string $key The window's cache key.
	 *
	 * @return int|null The calls counted so far, or null when the cache failed.
	 */
	private function countCall(string $key): ?int {
		try {
			$used = $this->cache?->inc($key);
			if (is_int($used) === false) {
				return null;
			}

			if ($used === 1) {
				$this->cache?->set($key, 1, (self::WINDOW_SECONDS + 1));
			}
		} catch (Throwable) {
			return null;
		}

		return $used;
	}//end countCall()

	/**
	 * The distributed memcache, or null when the instance has none.
	 *
	 * @param ICacheFactory $cacheFactory The cache factory.
	 *
	 * @return IMemcache|null
	 */
	private function resolveCache(ICacheFactory $cacheFactory): ?IMemcache {
		try {
			if ($cacheFactory->isAvailable() === false) {
				return null;
			}

			$cache = $cacheFactory->createDistributed('openregister_token_grants');
			if (($cache instanceof IMemcache) === false) {
				return null;
			}

			return $cache;
		} catch (Throwable) {
			return null;
		}
	}//end resolveCache()
}//end class
