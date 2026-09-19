<?php

/**
 * The response cache in front of facet computation, and what keeps it honest.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
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
 * @spec openspec/specs/faceting-configuration/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCP\ICacheFactory;
use OCP\IMemcache;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Keys, reads and writes the facet response cache.
 *
 * 🔴 THE KEY CARRIES THE CALLER AND THE FRESHNESS TOKEN, and both halves are
 * load-bearing. Without the caller, one person's facet buckets are served to
 * another and RBAC is bypassed by a cache hit. Without the freshness token
 * the only invalidation is the TTL, a schema change or an admin cache flush,
 * which is how a folder pane came to offer a category nobody had
 * (openregister#3560).
 *
 * Its own class so those two are decided in one place rather than beside the
 * computation they are meant to be safe for. A missing cache backend is not
 * an error here: every read answers null and every write is dropped, so the
 * facets are simply computed again.
 *
 * @spec openspec/specs/faceting-configuration/spec.md
 */
class FacetResponseCache {

	/**
	 * Cache TTL for facet responses (1 hour).
	 *
	 * This TTL is the CEILING on staleness, not the invalidation. A cached entry
	 * is unreachable as soon as an object write bumps the freshness token folded
	 * into its key (see FacetCacheVersion). It used to be the only invalidation
	 * besides a schema change and an admin cache flush, which is how a folder pane
	 * came to offer a category nobody had (openregister#3560).
	 *
	 * @var int
	 */
	private const FACET_CACHE_TTL = 3600;

	/**
	 * Cache TTL for collection-wide facets (1 hour).
	 *
	 * Collection-wide facets change even less frequently.
	 *
	 * @var int
	 */
	private const COLLECTION_FACET_TTL = 3600;

	/**
	 * Distributed cache for facet responses.
	 *
	 * @var IMemcache|null
	 */
	private ?IMemcache $facetCache = null;

	/**
	 * Constructor.
	 *
	 * @param ICacheFactory     $cacheFactory      Builds the distributed, then the local, cache.
	 * @param IUserSession      $userSession       The caller, whose identity is part of the key.
	 * @param FacetCacheVersion $facetCacheVersion Per-scope freshness counter folded into the key.
	 * @param LoggerInterface   $logger            Hits, writes and an unavailable backend.
	 */
	public function __construct(
		private readonly ICacheFactory $cacheFactory,
		private readonly IUserSession $userSession,
		private readonly FacetCacheVersion $facetCacheVersion,
		private readonly LoggerInterface $logger,
	) {
		try {
			$this->facetCache = $this->cacheFactory->createDistributed('openregister_facets');
		} catch (\Exception $e) {
			// Fallback to local cache if distributed cache unavailable.
			try {
				$this->facetCache = $this->cacheFactory->createLocal('openregister_facets');
			} catch (\Exception $e) {
				// No caching available - cache operations are skipped.
				$this->facetCache = null;
				$this->logger->warning(
					message: '[FacetResponseCache] Facet caching unavailable',
					context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
				);
			}
		}
	}//end __construct()

	/**
	 * Generate cache key for facet responses.
	 *
	 * @param array $facetQuery Query for faceting (without pagination).
	 * @param array $facetConfig Facet configuration.
	 *
	 * @return string Cache key.
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md
	 */
	public function keyFor(array $facetQuery, array $facetConfig): string {
		// **RBAC COMPLIANCE**: Include user context for role-based access control.
		$user = $this->userSession->getUser();
		$userId = 'anonymous';
		if ($user !== null) {
			$userId = $user->getUID();
		}

		// Get organization context if available.
		$orgId = null;
		if (($facetQuery['@self']['organisation'] ?? null) !== null) {
			$orgId = $facetQuery['@self']['organisation'];
		}

		// Create RBAC-aware cache key.
		$cacheData = [
			'facets' => $facetConfig,
			'filters' => array_diff_key($facetQuery, ['_facets' => true]),
			'user' => $userId,
			'org' => $orgId,
			'version' => '2.0',
			// Increment to invalidate when RBAC logic changes.
			// **FRESHNESS**: an object write bumps the counter for its (register,
			// schema) scope, which changes this token, which changes the key. So a
			// facet computed before the write is unreachable after it, and the
			// bucket list beside a live `results` array can no longer be an hour
			// old (openregister#3560). Without this the only invalidation was the
			// TTL, a schema change, or an admin cache flush.
			'freshness' => $this->facetFreshnessToken(facetQuery: $facetQuery),
		];

		return 'facet_rbac_' . md5(json_encode($cacheData));
	}//end keyFor()

	/**
	 * Freshness token for the scopes this facet query reads from.
	 *
	 * The scope is taken from the query itself, which already carries numeric
	 * register and schema ids by the time faceting runs (the numeric-ID contract
	 * on ObjectService::searchObjects; ObjectsController resolves the slugs in the
	 * URL before building the query). Those are the same ids ObjectEntity stores,
	 * so the counter a write bumps is the counter this read consults. Deriving the
	 * scope from the query costs no database work, which matters because the whole
	 * point of the cache is to avoid the aggregation underneath it.
	 *
	 * @param array $facetQuery Query for faceting (without pagination).
	 *
	 * @psalm-param   array<string, mixed> $facetQuery
	 * @phpstan-param array<string, mixed> $facetQuery
	 *
	 * @return string Token that changes when any covered scope is written to.
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-an-object-write-must-invalidate-the-facet-response-derived-from-it
	 */
	private function facetFreshnessToken(array $facetQuery): string {
		$registers = $this->scopeIdsFromQuery(
			values: [
				($facetQuery['@self']['registers'] ?? null),
				($facetQuery['@self']['register'] ?? null),
				($facetQuery['_registers'] ?? null),
			]
		);

		$schemas = $this->scopeIdsFromQuery(
			values: [
				($facetQuery['@self']['schemas'] ?? null),
				($facetQuery['@self']['schema'] ?? null),
				($facetQuery['_schemas'] ?? null),
			]
		);

		return $this->facetCacheVersion->tokenForScope(registers: $registers, schemas: $schemas);
	}//end facetFreshnessToken()

	/**
	 * Flatten the register/schema positions of a query into a list of id strings.
	 *
	 * Each position may be absent, a scalar id, or a list of ids. Anything that is
	 * not a scalar is dropped rather than guessed: an unrecognised shape widens the
	 * scope to the global counter, which over-invalidates but never under-invalidates.
	 *
	 * @param array $values Candidate values from the query, most specific first.
	 *
	 * @psalm-param   array<int, mixed> $values
	 * @phpstan-param array<int, mixed> $values
	 *
	 * @return array<int, string> Distinct id strings, possibly empty.
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-an-object-write-must-invalidate-the-facet-response-derived-from-it
	 */
	private function scopeIdsFromQuery(array $values): array {
		$ids = [];

		foreach ($values as $value) {
			if ($value === null) {
				continue;
			}

			$candidates = [$value];
			if (is_array($value) === true) {
				$candidates = $value;
			}

			foreach ($candidates as $candidate) {
				if (is_int($candidate) === true || is_string($candidate) === true) {
					$candidate = (string)$candidate;
					if ($candidate !== '') {
						$ids[] = $candidate;
					}
				}
			}
		}

		return array_values(array_unique($ids));
	}//end scopeIdsFromQuery()

	/**
	 * Get cached facet response.
	 *
	 * @param string $cacheKey Cache key to lookup.
	 *
	 * @return array|null Cached response or null if not found.
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md
	 */
	public function get(string $cacheKey): ?array {
		if ($this->facetCache === null) {
			return null;
		}

		try {
			$cached = $this->facetCache->get($cacheKey);
			if ($cached !== null) {
				$this->logger->debug(
					message: '[FacetResponseCache] Facet response cache hit',
					context: ['file' => __FILE__, 'line' => __LINE__, 'cacheKey' => $cacheKey]
				);
				// Add cache metadata.
				$cached['performance_metadata']['cache_hit'] = true;
				return $cached;
			}
		} catch (\Exception $e) {
			// Cache get failed, continue without cache.
		}

		return null;
	}//end get()

	/**
	 * Cache facet response for future requests.
	 *
	 * @param string $cacheKey Cache key.
	 * @param array $result Facet result to cache.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md
	 */
	public function put(string $cacheKey, array $result): void {
		if ($this->facetCache === null) {
			return;
		}

		try {
			// Use different TTL based on strategy.
			$fallbackUsed = $result['performance_metadata']['fallback_used'] ?? false;
			$ttl = self::FACET_CACHE_TTL;
			if ($fallbackUsed === true) {
				$ttl = self::COLLECTION_FACET_TTL;
			}

			$this->facetCache->set($cacheKey, $result, $ttl);

			$this->logger->debug(
				message: '[FacetResponseCache] Facet response cached',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'cacheKey' => $cacheKey,
					'ttl' => $ttl,
					'strategy' => $result['performance_metadata']['strategy'] ?? 'unknown',
				]
			);
		} catch (\Exception $e) {
			// Cache set failed, continue without caching.
		}//end try
	}//end put()

}//end class
