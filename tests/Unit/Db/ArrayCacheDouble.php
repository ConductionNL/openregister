<?php

/**
 * An array-backed ICache for tests that need a cache which really stores.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Db
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/object-write-sub-500ms/tasks.md
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- ICache's own contract documents these methods.

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCP\ICache;

/**
 * An array-backed ICache that records the TTL of every write.
 */
final class ArrayCacheDouble implements ICache {
	/**
	 * Stored values by key.
	 *
	 * @var array<string, mixed>
	 */
	public array $values = [];

	/**
	 * TTL of each write, by key.
	 *
	 * @var array<string, int>
	 */
	public array $ttls = [];

	/**
	 * Prefixes passed to clear(), in order.
	 *
	 * @var array<int, string>
	 */
	public array $cleared = [];

	public function get(string $key) {
		return $this->values[$key] ?? null;
	}

	public function set(string $key, $value, int $ttl = 0) {
		$this->values[$key] = $value;
		$this->ttls[$key]   = $ttl;
		return true;
	}

	public function hasKey(string $key) {
		return array_key_exists($key, $this->values);
	}

	public function remove(string $key) {
		unset($this->values[$key]);
		return true;
	}

	public function clear(string $prefix = '') {
		$this->cleared[] = $prefix;
		foreach (array_keys($this->values) as $key) {
			if (str_starts_with($key, $prefix) === true) {
				unset($this->values[$key]);
			}
		}

		return true;
	}

	public static function isAvailable(): bool {
		return true;
	}
}//end class
