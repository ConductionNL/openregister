<?php

/**
 * OpenRegister GitHub Guards.
 *
 * Policy guards + pipeline runner for the GitHub issues proxy endpoints. The class collects:
 *
 *   - `runGuards()` — sequential guard runner shared by index() and create()
 *   - `enforceFeatureFlag()` — admin opt-out (task 1.21)
 *   - `enforceRepoAllowlist()` — per-instance repo allowlist (task 1.14)
 *   - `enforceGetRateLimit()` — per-user GET cache-miss budget (task 1.19)
 *
 * Pure input validators (repo format, title/body length, specRef, sort, labels) live in
 * `GitHubRequestValidator` so each class stays under PHPMD's TooManyPublicMethods threshold
 * and so the two responsibilities (input validation vs. stateful policy) are clearly separated.
 *
 * Each public guard returns either `null` (continue) or a `JSONResponse` (short-circuit
 * with a structured error). The controller invokes them via the shared `runGuards` pipeline.
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

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IUserSession;

/**
 * Policy guards for the GitHub issues proxy endpoints.
 *
 * @package OCA\OpenRegister\Service\Configuration
 *
 * @psalm-suppress UnusedClass
 */
class GitHubGuards
{
    /**
     * Per-user GET cache-miss rate-limit window, in seconds (task 1.19).
     */
    private const GET_RATE_LIMIT_WINDOW = 300;

    /**
     * Per-user GET cache-miss budget within GET_RATE_LIMIT_WINDOW (task 1.19).
     */
    private const GET_RATE_LIMIT_MAX = 10;

    /**
     * GitHubGuards constructor.
     *
     * @param IAppConfig         $appConfig   App-level config (allowlist repo, opt-out flag).
     * @param IUserSession       $userSession Current user for the per-user GET counter.
     * @param RateLimiterService $rateLimiter Cache-backed rate limiter with fail-closed contract.
     */
    public function __construct(
        private readonly IAppConfig $appConfig,
        private readonly IUserSession $userSession,
        private readonly RateLimiterService $rateLimiter
    ) {
    }//end __construct()

    /**
     * Run a sequence of guard closures, short-circuiting on the first non-null response.
     *
     * @param array<callable(): ?JSONResponse> $guards Ordered guard closures.
     *
     * @return JSONResponse|null First failing response, or null when all guards pass.
     */
    public function runGuards(array $guards): ?JSONResponse
    {
        foreach ($guards as $guard) {
            $response = $guard();
            if ($response !== null) {
                return $response;
            }
        }

        return null;
    }//end runGuards()

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
    public function enforceFeatureFlag(): ?JSONResponse
    {
        $enabled = $this->appConfig->getValueBool('openregister', 'features_roadmap_enabled', true);
        if ($enabled === true) {
            return null;
        }

        return new JSONResponse(['error' => 'feature_disabled'], Http::STATUS_FORBIDDEN);
    }//end enforceFeatureFlag()

    /**
     * Enforce per-instance repo allowlist (task 1.14).
     *
     * Reads `openregister::github_repo` from IAppConfig:
     *   - Unset → graceful degradation: 200 + `hint: github_repo_not_configured` on GET,
     *     503 + `error: github_repo_not_configured` on POST.
     *   - Set + mismatch with caller-supplied `repo` → 403 `repo_not_allowed`.
     *   - Set + match → null (continue).
     *
     * @param string $repo   Caller-supplied slug (already format-validated).
     * @param bool   $isRead Whether the call is the GET path (alters the unset-config response).
     *
     * @return JSONResponse|null Null on match, structured 403/200/503 otherwise.
     *
     * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-14
     */
    public function enforceRepoAllowlist(string $repo, bool $isRead): ?JSONResponse
    {
        $allowed = $this->appConfig->getValueString('openregister', 'github_repo', '');
        if ($allowed === '') {
            if ($isRead === true) {
                return new JSONResponse(['items' => [], 'hint' => 'github_repo_not_configured']);
            }

            return new JSONResponse(['error' => 'github_repo_not_configured'], Http::STATUS_SERVICE_UNAVAILABLE);
        }

        if ($repo === $allowed) {
            return null;
        }

        return new JSONResponse(['error' => 'repo_not_allowed'], Http::STATUS_FORBIDDEN);
    }//end enforceRepoAllowlist()

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
    public function enforceGetRateLimit(string $cacheKey): ?JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return null;
        }

        if ($this->rateLimiter->isOperational() === false) {
            return new JSONResponse(['error' => 'rate_limiter_unavailable'], Http::STATUS_SERVICE_UNAVAILABLE);
        }

        $retryAfter = $this->rateLimiter->consumeDistinctKeyBudget(
            bucketKey: 'getmiss:'.$user->getUID(),
            distinctKey: $cacheKey,
            maxKeys: self::GET_RATE_LIMIT_MAX,
            windowSeconds: self::GET_RATE_LIMIT_WINDOW
        );
        if ($retryAfter === null) {
            return null;
        }

        $resp = new JSONResponse(
            ['error' => 'user_rate_limited', 'retry_after' => $retryAfter],
            Http::STATUS_TOO_MANY_REQUESTS
        );
        $resp->addHeader('Retry-After', (string) $retryAfter);
        return $resp;
    }//end enforceGetRateLimit()
}//end class
