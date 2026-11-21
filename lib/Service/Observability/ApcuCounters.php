<?php

/**
 * Atomic APCu counters for the per-request metrics.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Observability
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Observability;

use APCUIterator;

/**
 * A thin, mockable wrapper over the APCu functions the request metrics use.
 *
 * Every method is a no-op answer (false, empty) when APCu is not loaded or
 * not enabled, so a deployment without APCu records no request metrics
 * instead of failing requests.
 *
 * @spec openspec/changes/production-observability
 */
class ApcuCounters {

	/**
	 * Whether APCu is loaded and enabled for this process.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/production-observability
	 */
	public function available(): bool {
		return function_exists('apcu_enabled') === true && apcu_enabled() === true;
	}//end available()

	/**
	 * Atomically add an amount to a counter, creating it when absent.
	 *
	 * @param string $key    The key.
	 * @param int    $amount The amount, at least 1.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/production-observability
	 */
	public function increment(string $key, int $amount): void {
		if ($amount < 1 || $this->available() === false) {
			return;
		}

		$success = false;
		apcu_inc($key, $amount, $success);
		if ($success === true) {
			return;
		}

		// Absent: create it. A concurrent request may have created it in between, then add to that.
		if (apcu_add($key, $amount) === false) {
			apcu_inc($key, $amount);
		}
	}//end increment()

	/**
	 * Atomically subtract an amount from a counter.
	 *
	 * @param string $key    The key.
	 * @param int    $amount The amount.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/production-observability
	 */
	public function subtract(string $key, int $amount): void {
		if ($amount < 1 || $this->available() === false) {
			return;
		}

		apcu_dec($key, $amount);
	}//end subtract()

	/**
	 * Store a marker only when it does not exist yet; true when this call stored it.
	 *
	 * @param string $key The key.
	 * @param int    $ttl The time to live in seconds.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/production-observability
	 */
	public function claim(string $key, int $ttl): bool {
		if ($this->available() === false) {
			return false;
		}

		return apcu_add($key, 1, $ttl) === true;
	}//end claim()

	/**
	 * Every positive integer counter whose key starts with the prefix.
	 *
	 * @param string $prefix The key prefix.
	 *
	 * @return array<string, int>
	 *
	 * @spec openspec/changes/production-observability
	 */
	public function entries(string $prefix): array {
		if ($this->available() === false || class_exists(APCUIterator::class) === false) {
			return [];
		}

		$entries = [];
		foreach (new APCUIterator('/^'.preg_quote($prefix, '/').'/', APC_ITER_KEY | APC_ITER_VALUE) as $entry) {
			$value = $entry['value'] ?? null;
			if (is_int($value) === true && $value > 0) {
				$entries[(string) $entry['key']] = $value;
			}
		}

		return $entries;
	}//end entries()
}//end class
