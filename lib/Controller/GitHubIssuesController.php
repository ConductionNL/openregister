<?php
/**
 * OpenRegister GitHub Issues Controller.
 *
 * Thin proxy over GitHub's issues API powering the Features & Roadmap menu component
 * shipped from `@conduction/nextcloud-vue`. Reads (GET) cache for 15 minutes and degrade
 * gracefully when the app-level PAT is unconfigured; submissions (POST) enforce CSRF +
 * per-user 60s rate limit and prefer the user's own PAT before falling back to the app
 * PAT with an attribution prefix.
 *
 * Security hardening (repo allowlist, display-name sanitization, audit logging, specRef
 * format validation, sort allowlist, labels validation, admin opt-out flag) is tracked
 * by tasks 1.14 – 1.22 and lands in a follow-up commit. This file implements the core
 * proxy shape only (tasks 1.3, 1.5, 1.6, 1.7, 1.8, 1.9).
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
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

namespace OCA\OpenRegister\Controller;

use Exception;
use OCA\OpenRegister\Service\Configuration\GitHubGuards;
use OCA\OpenRegister\Service\Configuration\GitHubHandler;
use OCA\OpenRegister\Service\Configuration\GitHubRequestValidator;
use OCA\OpenRegister\Service\Configuration\RateLimiterService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Class GitHubIssuesController.
 *
 * Exposes `GET /api/github/issues` (cached list) and `POST /api/github/issues` (submit)
 * backed by `GitHubHandler::listIssues` / `GitHubHandler::createIssue`.
 *
 * @package OCA\OpenRegister\Controller
 *
 * @psalm-suppress UnusedClass
 */
class GitHubIssuesController extends Controller
{
    /**
     * Cache TTL for GET responses, in seconds (15 minutes per design D9).
     */
    private const READ_CACHE_TTL = 900;

    /**
     * Per-user submission rate-limit window, in seconds (design D21).
     */
    private const SUBMIT_RATE_LIMIT_TTL = 60;

    /**
     * Distributed cache for the GET responses.
     *
     * @var ICache
     */
    private ICache $readCache;

    /**
     * GitHubIssuesController constructor.
     *
     * @param string                 $appName       Nextcloud app name (DI-injected)
     * @param IRequest               $request       Current HTTP request
     * @param GitHubHandler          $githubHandler Reused HTTP client / token / attribution logic
     * @param GitHubGuards           $guards        Policy guards (feature flag, repo allowlist, GET rate limit)
     * @param GitHubRequestValidator $validator     Pure-function input validators
     * @param RateLimiterService     $rateLimiter   Cache-backed rate limiter with fail-closed contract
     * @param IUserSession           $userSession   Resolves the submitting NC user
     * @param ICacheFactory          $cacheFactory  Distributed cache factory (read-response cache)
     * @param LoggerInterface        $logger        Structured logger
     *
     * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-3
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly GitHubHandler $githubHandler,
        private readonly GitHubGuards $guards,
        private readonly GitHubRequestValidator $validator,
        private readonly RateLimiterService $rateLimiter,
        private readonly IUserSession $userSession,
        ICacheFactory $cacheFactory,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct(appName: $appName, request: $request);
        $this->readCache = $cacheFactory->createDistributed('openregister_github_issues');
    }//end __construct()

    /**
     * List GitHub issues for a repository.
     *
     * `GET /index.php/apps/openregister/api/github/issues?repo=<owner/repo>&state=open&sort=reactions-%2B1&per_page=30&labels=enhancement,feature`
     *
     * NoCSRFRequired: pure read with no side effects.
     * NoAdminRequired: any authenticated NC user may browse the roadmap.
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-3
     * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-5
     * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-7
     * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-9
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(): JSONResponse
    {
        $repo    = (string) $this->request->getParam('repo', '');
        $state   = (string) $this->request->getParam('state', 'open');
        $sort    = (string) $this->request->getParam('sort', 'reactions-+1');
        $perPage = (int) $this->request->getParam('per_page', 30);
        $labels  = (string) $this->request->getParam('labels', '');

        $labelArray = $labels === '' ? null : array_values(array_filter(array_map('trim', explode(',', $labels))));

        $guardError = $this->guards->runGuards(
            $this->readGuardPipeline(repo: $repo, sort: $sort, perPage: $perPage, labels: $labelArray)
        );
        if ($guardError !== null) {
            return $guardError;
        }

        [$owner, $repoName] = explode('/', $repo, 2);

        $cacheKey = $this->buildReadCacheKey(
            repo: $repo,
            state: $state,
            sort: $sort,
            perPage: $perPage,
            labels: $labelArray
        );

        $cached = $this->readCache->get($cacheKey);
        if (is_array($cached) === true) {
            $resp = new JSONResponse($cached);
            $resp->addHeader('X-OpenRegister-GitHub-Cache', 'HIT');
            return $resp;
        }

        // Cache-miss rate-limit (task 1.19) — only enforce when we're about to hit GitHub.
        $missRateLimit = $this->guards->enforceGetRateLimit(cacheKey: $cacheKey);
        if ($missRateLimit !== null) {
            return $missRateLimit;
        }

        try {
            $result = $this->githubHandler->listIssues(
                owner: $owner,
                repo: $repoName,
                state: $state,
                sort: $sort,
                perPage: $perPage,
                labels: $labelArray
            );
        } catch (Exception $e) {
            return $this->mapHandlerException(exception: $e, isRead: true);
        }

        $this->readCache->set($cacheKey, $result, self::READ_CACHE_TTL);

        $resp = new JSONResponse($result);
        $resp->addHeader('X-OpenRegister-GitHub-Cache', 'MISS');
        return $resp;
    }//end index()

    /**
     * Submit a GitHub issue on behalf of the authenticated user.
     *
     * `POST /index.php/apps/openregister/api/github/issues` with JSON body
     * `{repo, title, body, specRef?}` and a Nextcloud CSRF token (CSRF middleware is
     * enforced — `#[NoCSRFRequired]` is intentionally NOT applied).
     *
     * Enforces per-user 60s rate limit and basic length validation; delegates the body
     * shape (attribution prefix, specRef suffix + label) to `GitHubHandler::createIssue`.
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-3
     * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-6
     * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-7
     * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-8
     * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-9
     */
    #[NoAdminRequired]
    public function create(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'unauthenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $uid = $user->getUID();

        $repo    = (string) $this->request->getParam('repo', '');
        $title   = (string) $this->request->getParam('title', '');
        $body    = (string) $this->request->getParam('body', '');
        $specRef = $this->request->getParam('specRef');
        $specRef = ($specRef === null || $specRef === '') ? null : (string) $specRef;

        $guardError = $this->guards->runGuards(
            $this->writeGuardPipeline(repo: $repo, title: $title, body: $body, specRef: $specRef, uid: $uid)
        );
        if ($guardError !== null) {
            return $guardError;
        }

        [$owner, $repoName] = explode('/', $repo, 2);

        try {
            $result = $this->githubHandler->createIssue(
                owner: $owner,
                repo: $repoName,
                title: $title,
                body: $body,
                specRef: $specRef,
                userId: $uid
            );
        } catch (Exception $e) {
            return $this->mapHandlerException(exception: $e, isRead: false);
        }

        // Mark the rate-limit slot consumed (records the submission time so Retry-After can be computed).
        $this->rateLimiter->markFixedWindow(
            bucketKey: 'feature_submission:'.$uid,
            windowSeconds: self::SUBMIT_RATE_LIMIT_TTL
        );

        return new JSONResponse($result, Http::STATUS_CREATED);
    }//end create()

    /**
     * Build the ordered guard pipeline for the GET (read) path. Extracted from `index()` so the
     * inline closures do not inflate that method's cyclomatic complexity past PHPMD's threshold.
     *
     * @param string             $repo    Caller-supplied `<owner>/<repo>` slug.
     * @param string             $sort    Caller-supplied sort key.
     * @param int                $perPage Caller-supplied page size.
     * @param array<string>|null $labels  Parsed label filter, or null when absent.
     *
     * @return array<callable(): ?JSONResponse> Ordered guard closures.
     *
     * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-5
     */
    private function readGuardPipeline(string $repo, string $sort, int $perPage, ?array $labels): array
    {
        return [
            fn () => $this->guards->enforceFeatureFlag(),
            fn () => $this->validator->validateRepoFormat(repo: $repo),
            fn () => $this->guards->enforceRepoAllowlist(repo: $repo, isRead: true),
            fn () => $this->validator->validatePerPage(perPage: $perPage),
            fn () => $this->validator->validateSort(sort: $sort),
            fn () => $this->validator->validateLabels(labels: $labels),
        ];
    }//end readGuardPipeline()

    /**
     * Build the ordered guard pipeline for the POST (write) path. Extracted from `create()` so the
     * inline closures do not inflate that method's cyclomatic / NPath complexity past PHPMD's
     * thresholds.
     *
     * @param string      $repo    Caller-supplied `<owner>/<repo>` slug.
     * @param string      $title   Caller-supplied issue title.
     * @param string      $body    Caller-supplied issue body.
     * @param string|null $specRef Caller-supplied capability slug, or null.
     * @param string      $uid     Submitting user's UID (for the per-user rate-limit bucket).
     *
     * @return array<callable(): ?JSONResponse> Ordered guard closures.
     *
     * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-6
     */
    private function writeGuardPipeline(string $repo, string $title, string $body, ?string $specRef, string $uid): array
    {
        return [
            fn () => $this->guards->enforceFeatureFlag(),
            fn () => $this->validator->validateRepoFormat(repo: $repo),
            fn () => $this->guards->enforceRepoAllowlist(repo: $repo, isRead: false),
            fn () => $this->validator->validateTitleLength(title: $title),
            fn () => $this->validator->validateBodyLength(body: $body),
            fn () => $this->validator->validateSpecRef(specRef: $specRef),
            fn () => $this->enforceRateLimiterOperational(),
            fn () => $this->enforceSubmitRateLimit(uid: $uid),
        ];
    }//end writeGuardPipeline()

    /**
     * Fail closed when no cache backend is available (task 1.18). On a cache-less instance the
     * rate limiters silently never fire, so both endpoints reject with HTTP 503
     * `rate_limiter_unavailable` rather than running unbounded.
     *
     * @return JSONResponse|null Null when a cache backend is available, 503 otherwise.
     *
     * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-18
     */
    private function enforceRateLimiterOperational(): ?JSONResponse
    {
        if ($this->rateLimiter->isOperational() === true) {
            return null;
        }

        return new JSONResponse(['error' => 'rate_limiter_unavailable'], Http::STATUS_SERVICE_UNAVAILABLE);
    }//end enforceRateLimiterOperational()

    /**
     * Enforce the per-user 1/60s submission rate limit (tasks 1.6 + 1.18). Returns null when the
     * user is within their budget, or a 429 JSONResponse with `Retry-After` header when they are
     * currently rate-limited.
     *
     * @param string $uid Submitting user's UID.
     *
     * @return JSONResponse|null Null on success, 429 with structured error_code + retry_after.
     *
     * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-6
     * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-18
     */
    private function enforceSubmitRateLimit(string $uid): ?JSONResponse
    {
        $retryAfter = $this->rateLimiter->checkFixedWindow(
            bucketKey: 'feature_submission:'.$uid,
            windowSeconds: self::SUBMIT_RATE_LIMIT_TTL
        );
        if ($retryAfter === null) {
            return null;
        }

        $response = new JSONResponse(
            ['error' => 'rate_limited', 'retry_after' => $retryAfter],
            Http::STATUS_TOO_MANY_REQUESTS
        );
        $response->addHeader('Retry-After', (string) $retryAfter);
        return $response;
    }//end enforceSubmitRateLimit()

    /**
     * Build the read cache key. Includes the (sorted) label list so different filter
     * combinations do not collide on the same cache slot. The cache namespace is already
     * scoped to `openregister_github_issues` by the factory.
     *
     * @param string             $repo    Validated `<owner>/<repo>` slug
     * @param string             $state   Issue state filter
     * @param string             $sort    Sort key
     * @param int                $perPage Page size
     * @param array<string>|null $labels  Optional label filter
     *
     * @return string Cache key (no PII, no token, no user identifier)
     *
     * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-5
     */
    private function buildReadCacheKey(string $repo, string $state, string $sort, int $perPage, ?array $labels): string
    {
        $key = 'list:'.$repo.':'.$state.':'.$sort.':'.$perPage;
        if (is_array($labels) === true && count($labels) > 0) {
            $sorted = $labels;
            sort($sorted);
            $key .= ':'.implode(',', $sorted);
        }

        return $key;
    }//end buildReadCacheKey()

    /**
     * Map a handler-layer exception to the proxy's structured HTTP response.
     *
     * - `github_pat_not_configured` → 503 on POST, 200 + hint on GET (the GET path
     *   actually returns the hint from `listIssues` directly without throwing; this
     *   handles the POST submission path).
     * - GitHub 403 with `X-RateLimit-Remaining: 0` or 429 → 429 with `github_rate_limited`
     *   and ISO-8601 `reset_at` derived from `X-RateLimit-Reset`.
     * - Anything else → 502 with `github_unavailable`. Real error is logged server-side.
     *
     * @param Exception $exception Handler-layer exception
     * @param bool      $isRead    True on GET path (changes the PAT-missing mapping)
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-7
     * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-9
     */
    private function mapHandlerException(Exception $exception, bool $isRead): JSONResponse
    {
        if (is_a($exception, 'RuntimeException') === true && $exception->getMessage() === 'github_pat_not_configured') {
            return $this->mapPatNotConfigured(isRead: $isRead);
        }

        if (is_a($exception, 'GuzzleHttp\\Exception\\BadResponseException') === true) {
            $rateLimitResponse = $this->mapGitHubRateLimit(exception: $exception);
            if ($rateLimitResponse !== null) {
                return $rateLimitResponse;
            }
        }

        $this->logger->error(
            message: '[GitHubIssuesController] GitHub proxy error',
            context: ['file' => __FILE__, 'line' => __LINE__, 'error_class' => $exception::class]
        );

        return new JSONResponse(['error' => 'github_unavailable'], Http::STATUS_BAD_GATEWAY);
    }//end mapHandlerException()

    /**
     * Translate the handler's PAT-missing signal into either a graceful 200 (GET — items: [] +
     * hint) or a 503 (POST — structured error_code). Separated so `mapHandlerException` stays
     * within PHPMD's CyclomaticComplexity threshold.
     *
     * @param bool $isRead Whether the original request was a GET (read) or POST (write).
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-7
     */
    private function mapPatNotConfigured(bool $isRead): JSONResponse
    {
        if ($isRead === true) {
            return new JSONResponse(['items' => [], 'hint' => 'github_pat_not_configured']);
        }

        return new JSONResponse(['error' => 'github_pat_not_configured'], Http::STATUS_SERVICE_UNAVAILABLE);
    }//end mapPatNotConfigured()

    /**
     * Translate a GitHub-side rate-limit response into a structured 429 for the client. Returns
     * null when the upstream response is NOT a rate-limit signal so the caller can fall through
     * to the generic 502 path.
     *
     * @param Exception $exception Guzzle BadResponseException carrying GitHub's response (the
     *                             instanceof check is the caller's responsibility — we use
     *                             the looser Exception type here to avoid importing
     *                             BadResponseException directly and inflating coupling).
     *
     * @return JSONResponse|null 429 when rate-limit detected, null otherwise.
     *
     * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-9
     */
    private function mapGitHubRateLimit(Exception $exception): ?JSONResponse
    {
        // Caller (mapHandlerException) has already confirmed via is_a() that this is a
        // BadResponseException, so the dynamic getResponse() call is safe.
        if (method_exists($exception, 'getResponse') === false) {
            return null;
        }

        $response = $exception->getResponse();
        if ($response === null) {
            return null;
        }

        $status    = $response->getStatusCode();
        $remaining = $response->getHeaderLine('X-RateLimit-Remaining');
        $reset     = $response->getHeaderLine('X-RateLimit-Reset');

        if ($status !== 429 && ($status !== 403 || $remaining !== '0')) {
            return null;
        }

        $body = ['error' => 'github_rate_limited'];
        if ($reset !== '') {
            $body['reset_at'] = gmdate('c', (int) $reset);
        }

        $jsonResp = new JSONResponse($body, Http::STATUS_TOO_MANY_REQUESTS);
        if ($reset !== '') {
            $jsonResp->addHeader('Retry-After', (string) max(1, (int) $reset - time()));
        }

        return $jsonResp;
    }//end mapGitHubRateLimit()
}//end class
