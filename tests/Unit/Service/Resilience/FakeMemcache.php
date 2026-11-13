<?php

/**
 * An in-memory IMemcache for the circuit-breaker tests: atomic inc/add,
 * get/set/remove, and a switch that makes every operation throw.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Resilience
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Resilience;

use OCP\IMemcache;
use RuntimeException;

/**
 * A minimal in-memory memcache double.
 */
class FakeMemcache implements IMemcache {

	/**
	 * The stored values.
	 *
	 * @var array<string, mixed>
	 */
	public array $store = [];

	/**
	 * Whether every operation throws.
	 *
	 * @var bool
	 */
	public bool $broken = false;

	/**
	 * Throw when broken.
	 *
	 * @return void
	 */
	private function guard(): void {
		if ($this->broken === true) {
			throw new RuntimeException('cache down');
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $key The key.
	 *
	 * @return mixed The value, or null.
	 */
	public function get($key): mixed {
		$this->guard();
		return ($this->store[$key] ?? null);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $key   The key.
	 * @param mixed  $value The value.
	 * @param int    $ttl   The ttl.
	 *
	 * @return bool True.
	 */
	public function set($key, $value, $ttl = 0): bool {
		$this->guard();
		$this->store[$key] = $value;
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $key The key.
	 *
	 * @return bool Whether it was set.
	 */
	public function hasKey($key): bool {
		$this->guard();
		return array_key_exists($key, $this->store);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $key The key.
	 *
	 * @return bool True.
	 */
	public function remove($key): bool {
		$this->guard();
		unset($this->store[$key]);
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string|null $prefix The prefix.
	 *
	 * @return bool True.
	 */
	public function clear($prefix = ''): bool {
		$this->guard();
		$this->store = [];
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $key   The key.
	 * @param mixed  $value The value.
	 * @param int    $ttl   The ttl.
	 *
	 * @return bool True when the key was absent and is now set.
	 */
	public function add($key, $value, $ttl = 0): bool {
		$this->guard();
		if (array_key_exists($key, $this->store) === true) {
			return false;
		}

		$this->store[$key] = $value;
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $key  The key.
	 * @param int    $step The step.
	 *
	 * @return int The new value.
	 */
	public function inc($key, $step = 1): int {
		$this->guard();
		$this->store[$key] = (((int) ($this->store[$key] ?? 0)) + $step);
		return $this->store[$key];
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $key  The key.
	 * @param int    $step The step.
	 *
	 * @return int The new value.
	 */
	public function dec($key, $step = 1): int {
		$this->guard();
		$this->store[$key] = (((int) ($this->store[$key] ?? 0)) - $step);
		return $this->store[$key];
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $key The key.
	 * @param mixed  $old The expected value.
	 * @param mixed  $new The new value.
	 *
	 * @return bool Whether it was replaced.
	 */
	public function cas($key, $old, $new): bool {
		$this->guard();
		if (($this->store[$key] ?? null) === $old) {
			$this->store[$key] = $new;
			return true;
		}

		return false;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $key The key.
	 * @param mixed  $old The expected value.
	 *
	 * @return bool Whether it was removed.
	 */
	public function cad($key, $old): bool {
		$this->guard();
		if (($this->store[$key] ?? null) === $old) {
			unset($this->store[$key]);
			return true;
		}

		return false;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $key The key.
	 * @param mixed  $old The expected value.
	 *
	 * @return bool Whether it was removed.
	 */
	/**
	 * {@inheritDoc}
	 *
	 * @return bool True.
	 */
	public static function isAvailable(): bool {
		return true;
	}

	public function ncad(string $key, mixed $old): bool {
		$this->guard();
		if (array_key_exists($key, $this->store) === true && $this->store[$key] !== $old) {
			unset($this->store[$key]);
			return true;
		}

		return false;
	}
}//end class
