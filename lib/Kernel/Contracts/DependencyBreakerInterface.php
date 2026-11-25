<?php

/**
 * DependencyBreakerInterface: the per-dependency circuit breaker, for callers in every area.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

/**
 * Guards calls to a named dependency (an LLM, a webhook receiver, an external
 * source) with its circuit breaker.
 *
 * Load shedding (Platform) keeps the breakers; the areas that call out (Core,
 * Search, Integrations) reach them through this port, so none of them names a
 * Platform class.
 *
 * @spec openspec/changes/operate-load-shedding/specs/load-shedding/spec.md
 */
interface DependencyBreakerInterface {

	/**
	 * Refuse at once while the key's breaker is open.
	 *
	 * @param string $key The breaker key.
	 *
	 * @return void
	 *
	 * @throws DependencyRefusalInterface While the breaker is open.
	 *
	 * @spec openspec/changes/operate-load-shedding/specs/load-shedding/spec.md
	 */
	public function allow(string $key): void;

	/**
	 * Make one call through the key's breaker and count its outcome.
	 *
	 * @template T
	 *
	 * @param string          $key  The breaker key.
	 * @param callable(): T   $call Makes the call.
	 *
	 * @return T What the call returned.
	 *
	 * @throws DependencyRefusalInterface While the breaker is open.
	 * @throws \Throwable What the call threw.
	 *
	 * @spec openspec/changes/operate-load-shedding/specs/load-shedding/spec.md
	 */
	public function call(string $key, callable $call): mixed;

	/**
	 * Count one answered call.
	 *
	 * @param string $key The breaker key.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/operate-load-shedding/specs/load-shedding/spec.md
	 */
	public function recordSuccess(string $key): void;

	/**
	 * Count one failed call.
	 *
	 * @param string   $key        The breaker key.
	 * @param int|null $retryAfter The dependency's own Retry-After, in seconds.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/operate-load-shedding/specs/load-shedding/spec.md
	 */
	public function recordFailure(string $key, ?int $retryAfter = null): void;

	/**
	 * The key of the LLM breaker.
	 *
	 * @return string The key.
	 *
	 * @spec openspec/changes/operate-load-shedding/specs/load-shedding/spec.md
	 */
	public function llmKey(): string;

	/**
	 * The key of a webhook receiver's breaker.
	 *
	 * @param string $host The receiver's host.
	 *
	 * @return string The key.
	 *
	 * @spec openspec/changes/operate-load-shedding/specs/load-shedding/spec.md
	 */
	public function webhookKey(string $host): string;

	/**
	 * The key of an external source's breaker.
	 *
	 * @param string|int $sourceId The source's id.
	 *
	 * @return string The key.
	 *
	 * @spec openspec/changes/operate-load-shedding/specs/load-shedding/spec.md
	 */
	public function sourceKey(string|int $sourceId): string;
}//end interface
