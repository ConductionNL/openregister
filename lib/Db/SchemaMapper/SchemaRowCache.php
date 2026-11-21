<?php

/**
 * OpenRegister SchemaRowCache
 *
 * A cross-request cache of stored schema rows, keyed on the row's id and its `updated` stamp.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\SchemaMapper
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/object-write-sub-500ms/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\SchemaMapper;

use OCP\ICache;
use OCP\ICacheFactory;
use Throwable;

/**
 * A cross-request cache of stored schema rows, keyed on the row's id and its `updated` stamp.
 *
 * A cold worker otherwise re-reads every schema row it resolves, `properties`
 * blob included. With this cache the identifier lookup reads only the narrow
 * columns that pick and identify the row (`id`, `updated` and the columns the
 * ambiguity warning names); the full row comes from the host-local cache when
 * a row with the same id AND the same `updated` stamp was stored before.
 *
 * What it caches is the stored row, never a composed schema: composition pulls
 * in parent schemas whose stamps are not part of this key, so it still runs per
 * request on top of the cached row.
 *
 * Staleness bounds: a row whose stamp changed is a different key; a mutation
 * through the mapper drops every entry of that id ({@see forget()}); entries
 * expire after {@see TTL} seconds, which bounds a write that bypassed the
 * mapper within the same second as the cached stamp. A row without an
 * `updated` stamp is never cached. Without a local memory cache on the host
 * the cache reports itself unavailable and the mapper reads full rows as before.
 *
 * @spec openspec/changes/object-write-sub-500ms/tasks.md
 */
final class SchemaRowCache {

	/**
	 * Seconds an entry lives.
	 *
	 * @var integer
	 */
	public const TTL = 300;

	/**
	 * The columns the narrow identifier probe selects.
	 *
	 * The id and stamp form the key; uuid, slug, title and application are what
	 * the ambiguity warning reports about the candidates.
	 *
	 * @var array<int, string>
	 */
	public const PROBE_COLUMNS = ['id', 'uuid', 'slug', 'title', 'application', 'updated'];

	/**
	 * The cache, or null when the host has no local memory cache.
	 *
	 * @var ICache|null
	 */
	private readonly ?ICache $cache;

	/**
	 * Build the cache over the host-local memory cache, when there is one.
	 *
	 * @param ICacheFactory|null $cacheFactory The cache factory; null disables the cache.
	 *
	 * @return void
	 */
	public function __construct(?ICacheFactory $cacheFactory) {
		$this->cache = self::localCache(cacheFactory: $cacheFactory);
	}//end __construct()

	/**
	 * Whether rows are cached at all.
	 *
	 * @return bool
	 */
	public function isAvailable(): bool {
		return $this->cache !== null;
	}//end isAvailable()

	/**
	 * The stored row with this id and stamp, if it was cached.
	 *
	 * @param array<string, mixed> $probe A probe row: at least `id` and `updated`.
	 *
	 * @return array<string, mixed>|null The full row, or null on a miss.
	 */
	public function get(array $probe): ?array {
		$key = self::key(probe: $probe);
		if ($this->cache === null || $key === null) {
			return null;
		}

		$row = $this->cache->get($key);
		if (is_array($row) === false) {
			return null;
		}

		// Only a row with the very id and stamp the probe saw answers it.
		if (self::key(probe: $row) !== $key) {
			return null;
		}

		return $row;
	}//end get()

	/**
	 * Remember a stored row under its id and stamp.
	 *
	 * @param array<string, mixed> $row The full stored row.
	 *
	 * @return void
	 */
	public function put(array $row): void {
		$key = self::key(probe: $row);
		if ($this->cache === null || $key === null) {
			return;
		}

		$this->cache->set($key, $row, self::TTL);
	}//end put()

	/**
	 * Drop every cached row of a schema, whatever its stamp.
	 *
	 * @param int $schemaId The schema id.
	 *
	 * @return void
	 */
	public function forget(int $schemaId): void {
		if ($this->cache === null) {
			return;
		}

		$this->cache->clear(self::idPrefix(schemaId: $schemaId));
	}//end forget()

	/**
	 * The cache key of a row, or null when it cannot be keyed.
	 *
	 * @param array<string, mixed> $probe The row.
	 *
	 * @return string|null
	 */
	public static function key(array $probe): ?string {
		$id      = ($probe['id'] ?? null);
		$updated = ($probe['updated'] ?? null);
		if (is_numeric($id) === false || is_scalar($updated) === false || (string)$updated === '') {
			return null;
		}

		return self::idPrefix(schemaId: (int)$id) . (string)$updated;
	}//end key()

	/**
	 * The key prefix every entry of one schema shares.
	 *
	 * The trailing separator keeps schema 1 from matching schema 12.
	 *
	 * @param int $schemaId The schema id.
	 *
	 * @return string
	 */
	private static function idPrefix(int $schemaId): string {
		return 'row:' . $schemaId . ':';
	}//end idPrefix()

	/**
	 * The host-local cache, or null when the host has none or it cannot be built.
	 *
	 * @param ICacheFactory|null $cacheFactory The factory.
	 *
	 * @return ICache|null
	 */
	private static function localCache(?ICacheFactory $cacheFactory): ?ICache {
		if ($cacheFactory === null) {
			return null;
		}

		try {
			if ($cacheFactory->isLocalCacheAvailable() === false) {
				return null;
			}

			return $cacheFactory->createLocal('openregister_schema_rows');
		} catch (Throwable) {
			// A cache that cannot be built only costs speed: read full rows as before.
			return null;
		}
	}//end localCache()
}//end class
