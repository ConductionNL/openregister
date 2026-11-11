<?php

/**
 * OpenRegister FacetCacheVersion
 *
 * Per-scope mutation counter that makes a cached facet response unreachable
 * as soon as an object write lands in the scope the facet was derived from.
 *
 * The facet response cache in {@see FacetHandler} keys on the query, the facet
 * config, the user and the organisation, and holds for an hour. Nothing in that
 * key moves when an object is written, so the `facets` block beside a live
 * `results` array could be an hour old (openregister#3560). A bucket list is a
 * navigation control, so it may not lag: a folder pane offered a category whose
 * rows were gone and hid the one just created.
 *
 * This counter is the freshness token folded into that key. It reuses the shape
 * already proven by {@see \OCA\OpenRegister\Search\Service\Aggregation\AggregationCache}
 * (scope-cache-invalidation): a write bumps a per-scope integer, every key for
 * that scope then carries the new integer, and the superseded entries become
 * unreachable without wiping any other scope.
 *
 * It does NOT share AggregationCache's counter. That counter's safety rests on
 * outliving the data it guards, and it is set with a TTL of one hour against a
 * 60 second data TTL. The facet response TTL is itself an hour, so the same
 * counter could expire back to zero while a superseded facet entry was still
 * live, and a stale bucket list would become reachable again. This counter
 * therefore lives in its own namespace and is written so it cannot expire before
 * the data it invalidates.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/faceting-configuration/spec.md#requirement-an-object-write-must-invalidate-the-facet-response-derived-from-it
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IMemcache;
use Psr\Log\LoggerInterface;

/**
 * Mutation counter for the scopes a facet response can be derived from.
 *
 * Four scopes are tracked per write, from most to least specific:
 *
 *   - the (register, schema) pair the object belongs to;
 *   - the schema alone, for a query that names schemas but no register;
 *   - the register alone, for a query that names a register but no schema;
 *   - a global counter, for a query that names neither.
 *
 * A reader picks the tightest scope its query actually names, so a write to one
 * schema leaves every other schema's cached facets hitting. A query that names
 * nothing falls back to the global counter and is therefore invalidated by any
 * write anywhere, which is the only answer that cannot be wrong for a facet
 * computed across the whole instance.
 */
class FacetCacheVersion implements FacetCacheVersionInterface {
	/**
	 * Cache namespace holding the counters.
	 *
	 * Deliberately NOT `openregister_facets`: the admin action at
	 * `DELETE /api/settings/cache?type=facet` clears that namespace, and a
	 * counter that resets alongside the data it guards is harmless only by
	 * coincidence. Keeping them apart makes the ordering irrelevant.
	 *
	 * @var string
	 */
	public const CACHE_NAMESPACE = 'openregister_facet_versions';

	/**
	 * TTL for a counter written through the get/set fallback, in seconds (30 days).
	 *
	 * The invariant is that a counter outlives every facet entry it invalidates.
	 * Facet entries hold for FacetHandler::FACET_CACHE_TTL (3600s), so 30 days
	 * leaves three orders of magnitude of headroom. Backends implementing
	 * IMemcache take the inc() path instead and never expire at all.
	 *
	 * @var int
	 */
	public const VERSION_TTL = 2592000;

	/**
	 * Ceiling on the (register, schema) pairs a single token may enumerate.
	 *
	 * Past this a query is treated as unscoped and reads the global counter.
	 * A query naming dozens of schemas is a cross-collection search whose facet
	 * is invalidated by almost any write anyway, so widening it costs nothing
	 * and keeps the token from growing with the query.
	 *
	 * @var int
	 */
	private const MAX_ENUMERATED_PAIRS = 32;

	/**
	 * Key of one counter, from its register part and schema part.
	 *
	 * @var string
	 */
	private const KEY_FORMAT = 'facetver:r%s:s%s';

	/**
	 * Distributed cache holding the counters, null when no backend is available.
	 *
	 * @var ICache|null
	 */
	private ?ICache $cache = null;

	/**
	 * Scope keys already bumped during this request.
	 *
	 * A bump makes every entry written BEFORE it unreachable, so a second bump of
	 * the same key with no read in between changes nothing. Skipping it turns a
	 * thousand-object import into four increments instead of four thousand.
	 *
	 * Any read clears this, because a read may have written a fresh entry that a
	 * later write in the same request must still invalidate.
	 *
	 * @var array<string, true>
	 */
	private array $bumpedThisRequest = [];

	/**
	 * Wire the counter store.
	 *
	 * @param ICacheFactory   $cacheFactory Factory used to create the distributed cache.
	 * @param LoggerInterface $logger       Logger for backend-unavailable warnings.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-an-object-write-must-invalidate-the-facet-response-derived-from-it
	 */
	public function __construct(
		ICacheFactory $cacheFactory,
		private readonly LoggerInterface $logger,
	) {
		try {
			$this->cache = $cacheFactory->createDistributed(self::CACHE_NAMESPACE);
		} catch (\Throwable $e) {
			$this->logger->warning(
				sprintf('[FacetCacheVersion] cache backend unavailable: %s', $e->getMessage())
			);
			$this->cache = null;
		}
	}//end __construct()

	/**
	 * Record that an object was written in a (register, schema) scope.
	 *
	 * Bumps every scope a facet response could have been derived from, so the
	 * next read of any of them computes fresh. Cheap by construction: four
	 * counter increments against the memcache, no database work, and no
	 * enumeration of the keys being invalidated.
	 *
	 * @param string|null $register Register id the written object belongs to.
	 * @param string|null $schema   Schema id the written object belongs to.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-an-object-write-must-invalidate-the-facet-response-derived-from-it
	 */
	public function bump(?string $register, ?string $schema): void {
		if ($this->cache === null) {
			return;
		}

		foreach ($this->scopeKeysToBump(register: $register, schema: $schema) as $key) {
			$this->incrementOnce(key: $key);
		}
	}//end bump()

	/**
	 * Current counter for one scope.
	 *
	 * A scope that has never been written to reads 0, so a cold instance keys
	 * exactly as it did before this counter existed.
	 *
	 * @param string|null $register Register id, or null for a scope that does not name one.
	 * @param string|null $schema   Schema id, or null for a scope that does not name one.
	 *
	 * @return int The current counter value.
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-an-object-write-must-invalidate-the-facet-response-derived-from-it
	 */
	public function version(?string $register, ?string $schema): int {
		// A read may cache a response keyed on what it reads here, so a later
		// write in this same request has to bump again even if it already did.
		$this->bumpedThisRequest = [];

		if ($this->cache === null) {
			return 0;
		}

		return $this->readCounter(key: $this->scopeKey(register: $register, schema: $schema));
	}//end version()

	/**
	 * Freshness token for every scope a facet query can be answered from.
	 *
	 * The token is folded into the facet cache key. Two reads agree only while
	 * no write has landed in any scope the query covers.
	 *
	 * @param array<int, string> $registers Register ids named by the query, empty when it names none.
	 * @param array<int, string> $schemas   Schema ids named by the query, empty when it names none.
	 *
	 * @return string Opaque token, stable for as long as the covered scopes are unwritten.
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-an-object-write-must-invalidate-the-facet-response-derived-from-it
	 */
	public function tokenForScope(array $registers, array $schemas): string {
		$parts = [];

		foreach ($this->readScopes(registers: $registers, schemas: $schemas) as $scope) {
			$parts[] = $scope[0] . '/' . $scope[1] . '=' . $this->version(register: $scope[0], schema: $scope[1]);
		}

		return implode(',', $parts);
	}//end tokenForScope()

	/**
	 * Resolve the scopes a reader must consult for a query.
	 *
	 * @param array<int, string> $registers Register ids named by the query.
	 * @param array<int, string> $schemas   Schema ids named by the query.
	 *
	 * @return array<int, array{0: string|null, 1: string|null}> Scope pairs to read.
	 */
	private function readScopes(array $registers, array $schemas): array {
		$registers = $this->namedIds(ids: $registers);
		$schemas = $this->namedIds(ids: $schemas);

		if (empty($schemas) === true || empty($registers) === true) {
			return $this->oneSidedScopes(registers: $registers, schemas: $schemas);
		}

		if ((count($registers) * count($schemas)) > self::MAX_ENUMERATED_PAIRS) {
			return [[null, null]];
		}

		return $this->pairScopes(registers: $registers, schemas: $schemas);
	}//end readScopes()

	/**
	 * Every (register, schema) pair of a query that names both.
	 *
	 * @param array<int, string> $registers Register ids.
	 * @param array<int, string> $schemas   Schema ids.
	 *
	 * @return array<int, array{0: string, 1: string}> Scope pairs to read.
	 */
	private function pairScopes(array $registers, array $schemas): array {
		$scopes = [];
		foreach ($registers as $register) {
			foreach ($schemas as $schema) {
				$scopes[] = [$register, $schema];
			}
		}

		return $scopes;
	}//end pairScopes()

	/**
	 * The distinct, non-empty ids a query names, as a list.
	 *
	 * @param array<int, string> $ids Ids as the query named them.
	 *
	 * @return array<int, string> The ids that name something.
	 */
	private function namedIds(array $ids): array {
		return array_values(array_unique(array_filter($ids, static fn ($value) => $value !== '')));
	}//end namedIds()

	/**
	 * The scopes of a query that names registers or schemas, but not both.
	 *
	 * A query naming registers only cannot know the schemas inside them, so it
	 * reads each register's own counter. A query naming nothing has only the
	 * global counter that can be right.
	 *
	 * @param array<int, string> $registers Register ids, empty when the query names schemas.
	 * @param array<int, string> $schemas   Schema ids, empty when the query names registers.
	 *
	 * @return array<int, array{0: string|null, 1: string|null}> Scope pairs to read.
	 */
	private function oneSidedScopes(array $registers, array $schemas): array {
		if (empty($registers) === false) {
			return array_map(static fn ($register) => [$register, null], $registers);
		}

		if (empty($schemas) === false) {
			return array_map(static fn ($schema) => [null, $schema], $schemas);
		}

		return [[null, null]];
	}//end oneSidedScopes()

	/**
	 * The scope keys a single object write must bump.
	 *
	 * @param string|null $register Register id of the written object.
	 * @param string|null $schema   Schema id of the written object.
	 *
	 * @return array<int, string> Cache keys, global first and the exact pair last.
	 */
	private function scopeKeysToBump(?string $register, ?string $schema): array {
		$keys = [];
		foreach (array_unique(['*', $this->scopePart(id: $register)]) as $registerPart) {
			foreach (array_unique(['*', $this->scopePart(id: $schema)]) as $schemaPart) {
				$keys[] = sprintf(self::KEY_FORMAT, $registerPart, $schemaPart);
			}
		}

		return $keys;
	}//end scopeKeysToBump()

	/**
	 * Cache key for one scope.
	 *
	 * @param string|null $register Register id, or null.
	 * @param string|null $schema   Schema id, or null.
	 *
	 * @return string Cache key.
	 */
	private function scopeKey(?string $register, ?string $schema): string {
		return sprintf(self::KEY_FORMAT, $this->scopePart(id: $register), $this->scopePart(id: $schema));
	}//end scopeKey()

	/**
	 * What an id contributes to a cache key.
	 *
	 * @param string|null $id Register or schema id, or null.
	 *
	 * @return string The id, or the wildcard when it names nothing.
	 */
	private function scopePart(?string $id): string {
		if ($id === null || $id === '') {
			return '*';
		}

		return $id;
	}//end scopePart()

	/**
	 * Read one counter, answering 0 when the backend fails.
	 *
	 * @param string $key Cache key to read.
	 *
	 * @return int The counter value.
	 */
	private function readCounter(string $key): int {
		try {
			return $this->numberIn(stored: $this->cache->get($key));
		} catch (\Throwable $e) {
			return 0;
		}
	}//end readCounter()

	/**
	 * The counter a stored value stands for.
	 *
	 * @param mixed $stored What the backend holds for a key.
	 *
	 * @return int The number, or 0 when it holds none.
	 */
	private function numberIn(mixed $stored): int {
		if (is_numeric($stored) === true) {
			return (int)$stored;
		}

		return 0;
	}//end numberIn()

	/**
	 * Increase one counter, unless this request already did and read nothing since.
	 *
	 * @param string $key Cache key to increase.
	 *
	 * @return void
	 */
	private function incrementOnce(string $key): void {
		if (isset($this->bumpedThisRequest[$key]) === true) {
			return;
		}

		$this->increment(key: $key);
		$this->bumpedThisRequest[$key] = true;
	}//end incrementOnce()

	/**
	 * Increase one counter by one.
	 *
	 * Prefers IMemcache::inc(), which is atomic, so two concurrent writes cannot
	 * lose a bump between them. Falls back to read-modify-write with an explicit
	 * long TTL for a backend that offers no atomic increment. A lost bump under
	 * the fallback still changes the counter, so a write is still invalidating;
	 * only the ordering of two simultaneous writes is unobservable.
	 *
	 * @param string $key Cache key to increase.
	 *
	 * @return void
	 */
	private function increment(string $key): void {
		try {
			if ($this->incrementedAtomically(key: $key) === false) {
				$next = ($this->numberIn(stored: $this->cache->get($key)) + 1);
				$this->cache->set($key, $next, self::VERSION_TTL);
			}
		} catch (\Throwable $e) {
			// A counter that cannot be written leaves the TTL as the only
			// bound, which is where this started. Say so rather than pass.
			$this->logger->warning(
				sprintf('[FacetCacheVersion] could not bump %s: %s', $key, $e->getMessage())
			);
		}//end try
	}//end increment()

	/**
	 * Increase one counter through the backend's atomic increment.
	 *
	 * @param string $key Cache key to increase.
	 *
	 * @return bool False when the backend has none or it refused.
	 */
	private function incrementedAtomically(string $key): bool {
		return $this->cache instanceof IMemcache && $this->cache->inc($key) !== false;
	}//end incrementedAtomically()
}//end class
