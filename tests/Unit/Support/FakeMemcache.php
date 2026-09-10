<?php

/**
 * OpenRegister FakeMemcache
 *
 * In-memory IMemcache backed by a plain array, so a test can observe exactly
 * what a cache holds and what survives an invalidation, instead of stubbing
 * return values call by call as createMock() would require.
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

use OCP\IMemcache;

/**
 * Array-backed IMemcache for unit tests.
 *
 * TTLs are recorded but never enforced: a unit test that needed a TTL to
 * elapse would be a timing test, and the freshness this cache is used to
 * assert is precisely the property that must not depend on one.
 */
class FakeMemcache implements IMemcache {
	/**
	 * Stored values by key.
	 *
	 * @var array<string, mixed>
	 */
	private array $store = [];

	/**
	 * TTL last written per key, for assertions about expiry headroom.
	 *
	 * @var array<string, int>
	 */
	private array $ttls = [];

	/**
	 * Number of get() calls, for assertions about read cost.
	 *
	 * @var int
	 */
	public int $reads = 0;

	/**
	 * Number of set()/inc() calls, for assertions about write cost.
	 *
	 * @var int
	 */
	public int $writes = 0;

	/**
	 * Read one key.
	 *
	 * @param string $key Cache key.
	 *
	 * @return mixed Stored value or null.
	 */
	public function get($key) {
		++$this->reads;
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
		++$this->writes;
		$this->store[$key] = $value;
		$this->ttls[$key] = $ttl;
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
		unset($this->store[$key], $this->ttls[$key]);
		return true;
	}//end remove()

	/**
	 * Drop every key carrying a prefix.
	 *
	 * @param string $prefix Key prefix, empty for everything.
	 *
	 * @return bool Always true.
	 */
	public function clear($prefix = '') {
		if ($prefix === '') {
			$this->store = [];
			$this->ttls = [];
			return true;
		}

		foreach (array_keys($this->store) as $key) {
			if (str_starts_with((string)$key, $prefix) === true) {
				unset($this->store[$key], $this->ttls[$key]);
			}
		}

		return true;
	}//end clear()

	/**
	 * Write a key only when it is absent.
	 *
	 * @param string $key   Cache key.
	 * @param mixed  $value Value to store.
	 * @param int    $ttl   Requested time to live.
	 *
	 * @return bool True when the key was written.
	 */
	public function add($key, $value, $ttl = 0) {
		if (array_key_exists($key, $this->store) === true) {
			return false;
		}

		return $this->set($key, $value, $ttl);
	}//end add()

	/**
	 * Increase a stored number, creating it when absent.
	 *
	 * Matches the Redis backend, whose incrBy() creates the key at the step
	 * value rather than failing, and whose created key carries no expiry.
	 *
	 * @param string $key  Cache key.
	 * @param int    $step Amount to add.
	 *
	 * @return int|false The new value.
	 */
	public function inc($key, $step = 1) {
		++$this->writes;
		$current = 0;
		if (is_numeric($this->store[$key] ?? null) === true) {
			$current = (int)$this->store[$key];
		}

		$this->store[$key] = ($current + $step);
		return $this->store[$key];
	}//end inc()

	/**
	 * Decrease a stored number.
	 *
	 * @param string $key  Cache key.
	 * @param int    $step Amount to subtract.
	 *
	 * @return int|false The new value, or false when the key is absent.
	 */
	public function dec($key, $step = 1) {
		if (is_numeric($this->store[$key] ?? null) === false) {
			return false;
		}

		$this->store[$key] = ((int)$this->store[$key] - $step);
		return $this->store[$key];
	}//end dec()

	/**
	 * Compare and set.
	 *
	 * @param string $key Cache key.
	 * @param mixed  $old Expected current value.
	 * @param mixed  $new Replacement value.
	 *
	 * @return bool True when replaced.
	 */
	public function cas($key, $old, $new) {
		if (($this->store[$key] ?? null) !== $old) {
			return false;
		}

		return $this->set($key, $new);
	}//end cas()

	/**
	 * Compare and delete.
	 *
	 * @param string $key Cache key.
	 * @param mixed  $old Expected current value.
	 *
	 * @return bool True when deleted.
	 */
	public function cad($key, $old) {
		if (($this->store[$key] ?? null) !== $old) {
			return false;
		}

		return $this->remove($key);
	}//end cad()

	/**
	 * Delete when the stored value differs from the given one.
	 *
	 * @param string $key Cache key.
	 * @param mixed  $old Value that must NOT be present.
	 *
	 * @return bool True when deleted.
	 */
	public function ncad(string $key, mixed $old): bool {
		if (array_key_exists($key, $this->store) === false) {
			return false;
		}

		if ($this->store[$key] === $old) {
			return false;
		}

		return $this->remove($key);
	}//end ncad()

	/**
	 * TTL last written for a key.
	 *
	 * @param string $key Cache key.
	 *
	 * @return int|null Recorded TTL, or null when the key was never set().
	 */
	public function ttlFor(string $key): ?int {
		return ($this->ttls[$key] ?? null);
	}//end ttlFor()

	/**
	 * Every key currently held.
	 *
	 * @return array<int, string> Cache keys.
	 */
	public function keys(): array {
		return array_map('strval', array_keys($this->store));
	}//end keys()

	/**
	 * Whether this backend can be used.
	 *
	 * @return bool Always true.
	 */
	public static function isAvailable(): bool {
		return true;
	}//end isAvailable()
}//end class
