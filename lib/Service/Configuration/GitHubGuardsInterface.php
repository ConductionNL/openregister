<?php

/**
 * The policy guards the GitHub-issues endpoints run before they call GitHub.
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

use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;

/**
 * The policy guards the GitHub-issues endpoints run before they call GitHub.
 *
 * Bound to GitHubGuards in ContractBindings. Every method is described on GitHubGuards.
 */
interface GitHubGuardsInterface {

	/**
	 * Run a sequence of guard closures, short-circuiting on the first non-null response.
	 *
	 * @param array<callable(): ?JSONResponse> $guards Ordered guard closures.
	 *
	 * @return JSONResponse|null First failing response, or null when all guards pass.
	 *
	 * @spec exclude Generic guard-runner combinator — loops guard closures and short-circuits on the first
	 *              non-null response; carries no policy itself (the individual guards are separately annotated).
	 */
	public function runGuards(array $guards): ?JSONResponse;

	/**
	 * Enforce admin opt-out flag `openregister::features_roadmap_enabled` (task 1.21).
	 *
	 * When `false`, both endpoints SHALL return HTTP 403 `feature_disabled`. Default is `true`,
	 * so unconfigured instances inherit the feature.
	 *
	 * @return JSONResponse|null Null on enabled, 403 `feature_disabled` on disabled.
	 *
	 * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-21
	 */
	public function enforceFeatureFlag(): ?JSONResponse;

	/**
	 * Enforce per-instance owner allowlist (task 1.14, generalised).
	 *
	 * Reads `openregister::github_allowed_owners` from IAppConfig as a
	 * comma-separated list of GitHub organisation / user names. The caller's
	 * `repo` is `<owner>/<name>`; only the owner segment is matched against
	 * the allowlist (case-insensitive). This lets one OR instance proxy
	 * issues for many sibling repos under the same orgs without per-repo
	 * configuration.
	 *
	 *   - Unset / empty after trimming → graceful degradation: 200 +
	 *     `hint: github_repo_not_configured` on GET, 503 +
	 *     `error: github_repo_not_configured` on POST.
	 *   - Owner not in list → 403 `repo_not_allowed`.
	 *   - Owner in list → null (continue).
	 *
	 * Default value: `ConductionNL,nextcloud` so fresh installs proxy the
	 * Conduction-fleet and core Nextcloud repos out of the box.
	 *
	 * Supersedes the old single-repo `openregister::github_repo` config key
	 * which used strict `owner/repo` equality (PR #1726).
	 *
	 * @param string $repo Caller-supplied slug `<owner>/<name>` (already format-validated).
	 * @param bool $isRead Whether the call is the GET path (alters the unset-config response).
	 *
	 * @return JSONResponse|null Null on match, structured 403/200/503 otherwise.
	 *
	 * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-14
	 */
	public function enforceRepoAllowlist(string $repo, bool $isRead): ?JSONResponse;

	/**
	 * Enforce per-user GET cache-miss rate limit (tasks 1.19 + 1.18). Counts distinct cache-key
	 * tuples within a rolling GET_RATE_LIMIT_WINDOW via the shared RateLimiterService. Anonymous
	 * callers are not counted. When no cache backend is available the limiter fails closed with
	 * HTTP 503 `rate_limiter_unavailable`.
	 *
	 * @param string $cacheKey The exact read cache key the caller is about to miss against.
	 *
	 * @return JSONResponse|null Null when within budget, 503 `rate_limiter_unavailable` when no
	 *                           cache backend exists, 429 `user_rate_limited` when exhausted.
	 *
	 * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-19
	 * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-18
	 */
	public function enforceGetRateLimit(string $cacheKey): ?JSONResponse;
}//end interface
