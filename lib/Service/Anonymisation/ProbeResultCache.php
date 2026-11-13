<?php

/**
 * OpenRegister Anonymisation Probe Result Cache
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\Anonymisation
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Anonymisation;

use OCP\IAppConfig;
use OCP\ICache;

/**
 * The cached probe result of each backend, kept for the configured TTL.
 *
 * Without a cache backend every read misses and every write is dropped, so a
 * probe simply runs each time.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Anonymisation
 *
 * @spec openspec/changes/anonymiser-backend-selection/specs/anonymiser-backend-selection/spec.md#requirement-probe-results-must-be-cached-with-a-60-second-default-ttl
 */
final class ProbeResultCache {

	/**
	 * IAppConfig key holding the probe-cache TTL in seconds.
	 */
	private const TTL_CONFIG_KEY = 'anonymisation.probe_cache_ttl';

	/**
	 * Default probe-cache TTL in seconds.
	 */
	private const TTL_DEFAULT = 60;

	/**
	 * Minimum allowed probe-cache TTL in seconds.
	 */
	private const TTL_MIN = 10;

	/**
	 * Maximum allowed probe-cache TTL in seconds.
	 */
	private const TTL_MAX = 600;

	/**
	 * Constructor.
	 *
	 * @param ICache|null $cache     The distributed cache, or null when none is available.
	 * @param IAppConfig  $appConfig Holds the TTL.
	 *
	 * @spec openspec/changes/anonymiser-backend-selection/specs/anonymiser-backend-selection/spec.md#requirement-probe-results-must-be-cached-with-a-60-second-default-ttl
	 */
	public function __construct(
		private readonly ?ICache $cache,
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * The probe-cache TTL in seconds, clamped to 10-600.
	 *
	 * @return int The TTL.
	 *
	 * @spec openspec/changes/anonymiser-backend-selection/specs/anonymiser-backend-selection/spec.md#requirement-probe-results-must-be-cached-with-a-60-second-default-ttl
	 */
	public function ttl(): int {
		$ttl = $this->appConfig->getValueInt('openregister', self::TTL_CONFIG_KEY, self::TTL_DEFAULT);

		return max(self::TTL_MIN, min(self::TTL_MAX, $ttl));
	}//end ttl()

	/**
	 * The cached probe result of a method, or null on a miss, without a cache or for an unreadable entry.
	 *
	 * @param string $method One of BackendState::METHODS.
	 *
	 * @return ProbeResult|null The cached result.
	 *
	 * @spec openspec/changes/anonymiser-backend-selection/specs/anonymiser-backend-selection/spec.md#requirement-probe-results-must-be-cached-with-a-60-second-default-ttl
	 */
	public function read(string $method): ?ProbeResult {
		$data = $this->entry(method: $method);
		if ($data === null) {
			return null;
		}

		return new ProbeResult(
			reachable: (bool)($data['reachable'] ?? false),
			latencyMs: self::nullableInt(value: ($data['latencyMs'] ?? null)),
			error: self::nullableString(value: ($data['error'] ?? null)),
			probedAt: (string)($data['probedAt'] ?? gmdate('c')),
		);
	}//end read()

	/**
	 * Keep a probe result for the configured TTL; nothing without a cache.
	 *
	 * @param string      $method One of BackendState::METHODS.
	 * @param ProbeResult $result The result.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/anonymiser-backend-selection/specs/anonymiser-backend-selection/spec.md#requirement-probe-results-must-be-cached-with-a-60-second-default-ttl
	 */
	public function write(string $method, ProbeResult $result): void {
		if ($this->cache === null) {
			return;
		}

		$this->cache->set($method, json_encode($result->jsonSerialize()), $this->ttl());
	}//end write()

	/**
	 * The decoded cache entry of a method, or null when there is no cache, no entry or no readable one.
	 *
	 * @param string $method One of BackendState::METHODS.
	 *
	 * @return array<string, mixed>|null The decoded entry.
	 */
	private function entry(string $method): ?array {
		if ($this->cache === null) {
			return null;
		}

		$blob = $this->cache->get($method);
		if (is_string($blob) === false) {
			return null;
		}

		$data = json_decode($blob, true);
		if (is_array($data) === false) {
			return null;
		}

		return $data;
	}//end entry()

	/**
	 * A cached value as an int, keeping null.
	 *
	 * @param mixed $value The cached value.
	 *
	 * @return int|null The int, or null.
	 */
	private static function nullableInt(mixed $value): ?int {
		if ($value === null) {
			return null;
		}

		return (int)$value;
	}//end nullableInt()

	/**
	 * A cached value as a string, keeping null.
	 *
	 * @param mixed $value The cached value.
	 *
	 * @return string|null The string, or null.
	 */
	private static function nullableString(mixed $value): ?string {
		if ($value === null) {
			return null;
		}

		return (string)$value;
	}//end nullableString()
}//end class
