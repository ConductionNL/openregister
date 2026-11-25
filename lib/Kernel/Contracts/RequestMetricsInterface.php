<?php
/**
 * RequestMetricsInterface: what callers outside the Integrations area record into the request metrics.
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
 * Records request, error and cache-lookup counts for the metrics endpoint.
 *
 * Implemented by \OCA\OpenRegister\Service\Observability\RequestMetricsRecorder
 * and bound in IntegrationWiring::BINDINGS, so the request middleware (Platform)
 * and the object cache (Core) do not name the Integrations class.
 *
 * @spec openspec/changes/production-observability
 */
interface RequestMetricsInterface {

	/**
	 * Count one finished request and its duration.
	 *
	 * @param string   $method     The HTTP method.
	 * @param string   $route      The route template.
	 * @param int      $status     The HTTP status.
	 * @param int|null $durationMs The request duration, null when unknown.
	 *
	 * @return void
	 */
	public function record(string $method, string $route, int $status, ?int $durationMs): void;

	/**
	 * Count one request that ended in an exception.
	 *
	 * @param string $type The error type (`database`, `validation`, `other`).
	 *
	 * @return void
	 */
	public function recordError(string $type): void;

	/**
	 * Count cache lookups and whether they hit.
	 *
	 * @param string $cache The cache (`object`, `name`).
	 * @param bool   $hit   Whether the lookups were answered from the cache.
	 * @param int    $count The number of lookups, at least 1.
	 *
	 * @return void
	 */
	public function recordCacheLookup(string $cache, bool $hit, int $count=1): void;

	/**
	 * Flush the in-memory counters when the flush interval has passed.
	 *
	 * @return int What a flush returned; 0 when no flush was due.
	 */
	public function flushIfDue(): int;
}//end interface
