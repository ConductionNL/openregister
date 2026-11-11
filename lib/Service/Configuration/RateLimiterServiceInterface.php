<?php

/**
 * The fixed-window rate limit the GitHub-issues endpoints check.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Configuration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Configuration;

/**
 * The fixed-window rate limit the GitHub-issues endpoints check.
 *
 * Bound to RateLimiterService in ContractBindings. Every method is described on RateLimiterService.
 */
interface RateLimiterServiceInterface {

	/**
	 * Whether a usable cache backend is available. False on a cache-less instance, in which
	 * case `createDistributed()` would have returned the no-op Null backend and any
	 * rate-limit check would silently pass — callers fail closed (HTTP 503
	 * `rate_limiter_unavailable`) instead.
	 *
	 * @return bool True when APCu and/or a distributed cache is configured.
	 *
	 * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-18
	 */
	public function isOperational(): bool;

	/**
	 * Fixed-window limiter: at most one action per `$windowSeconds` for the given `$bucketKey`.
	 *
	 * Call this BEFORE performing the action. Returns null when the action is permitted; on
	 * permit, the caller MUST call `markFixedWindow()` after the action succeeds so the slot is
	 * consumed. Returns the remaining-window seconds when the slot is still occupied.
	 *
	 * @param string $bucketKey Caller-namespaced bucket key (e.g. `feature_submission:<uid>`).
	 * @param int $windowSeconds Minimum gap between actions.
	 *
	 * @return int|null Null when permitted, retry-after seconds (≥ 1) when rate-limited.
	 *
	 * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-6
	 * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-18
	 */
	public function checkFixedWindow(string $bucketKey, int $windowSeconds): ?int;

	/**
	 * Consume the fixed-window slot for `$bucketKey` (call after `checkFixedWindow()` returned
	 * null and the action succeeded).
	 *
	 * @param string $bucketKey Caller-namespaced bucket key.
	 * @param int $windowSeconds TTL for the slot.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-6
	 */
	public function markFixedWindow(string $bucketKey, int $windowSeconds): void;
}//end interface
