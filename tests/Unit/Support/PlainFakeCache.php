<?php

/**
 * OpenRegister PlainFakeCache
 *
 * In-memory ICache with no atomic increment, standing in for a backend that
 * implements ICache but not IMemcache, so the read-modify-write fallback in
 * FacetCacheVersion is exercised by a test rather than assumed.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Support;

use OCP\ICache;

/**
 * Array-backed ICache without IMemcache's atomic operations.
 */
class PlainFakeCache implements ICache {
	/**
	 * Stored values by key.
	 *
	 * @var array<string, mixed>
	 */
	private array $store = [];

	/**
	 * TTL passed to the most recent set() call.
	 *
	 * @var int
	 */
	public int $lastTtl = 0;

	/**
	 * Read one key.
	 *
	 * @param string $key Cache key.
	 *
	 * @return mixed Stored value or null.
	 */
	public function get($key) {
		return ($this->store[$key] ?? null);
	}//end get()

	/**
	 * Write one key.
	 *
	 * @param string $key   Cache key.
	 * @param mixed  $value Value to store.
	 * @param int    $ttl   Requested time to live.
	 *
	 * @return bool Always true.
	 */
	public function set($key, $value, $ttl = 0) {
		$this->store[$key] = $value;
		$this->lastTtl = (int)$ttl;
		return true;
	}//end set()

	/**
	 * Whether a key is present.
	 *
	 * @param string $key Cache key.
	 *
	 * @return bool True when present.
	 */
	public function hasKey($key) {
		return array_key_exists($key, $this->store);
	}//end hasKey()

	/**
	 * Drop one key.
	 *
	 * @param string $key Cache key.
	 *
	 * @return bool Always true.
	 */
	public function remove($key) {
		unset($this->store[$key]);
		return true;
	}//end remove()

	/**
	 * Drop every key.
	 *
	 * @param string $prefix Key prefix, unused.
	 *
	 * @return bool Always true.
	 */
	public function clear($prefix = '') {
		$this->store = [];
		return true;
	}//end clear()

	/**
	 * Whether this backend can be used.
	 *
	 * @return bool Always true.
	 */
	public static function isAvailable(): bool {
		return true;
	}//end isAvailable()
}//end class
