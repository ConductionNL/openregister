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
use GuzzleHttp\Exception\BadResponseException;
use OCA\OpenRegister\Service\Configuration\GitHubHandler;
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
use RuntimeException;

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
     * Allowed reactions sort key set is enforced by GitHubHandler at fetch time; the
     * controller passes through whatever the client sent. Full server-side allowlist
     * is added in task 1.20 (follow-up).
     */

    /**
     * Distributed cache for the GET responses.
     *
     * @var ICache
     */
    private ICache $readCache;

    /**
     * Distributed cache for the per-user POST rate-limit window.
     *
     * @var ICache
     */
    private ICache $submitRateCache;

    /**
     * GitHubIssuesController constructor.
     *
     * @param string          $appName       Nextcloud app name (DI-injected)
     * @param IRequest        $request       Current HTTP request
     * @param GitHubHandler   $githubHandler Reused HTTP client / token / attribution logic
     * @param IUserSession    $userSession   Resolves the submitting NC user
     * @param ICacheFactory   $cacheFactory  Distributed cache factory
     * @param LoggerInterface $logger        Structured logger
     *
     * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-3
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly GitHubHandler $githubHandler,
        private readonly IUserSession $userSession,
        ICacheFactory $cacheFactory,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct(appName: $appName, request: $request);
        $this->readCache       = $cacheFactory->createDistributed('openregister_github_issues');
        $this->submitRateCache = $cacheFactory->createDistributed('openregister_feature_submission');
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

        if (preg_match('#^[A-Za-z0-9][A-Za-z0-9_.-]*/[A-Za-z0-9][A-Za-z0-9_.-]*$#', $repo) !== 1) {
            return new JSONResponse(['error' => 'repo_invalid_format'], Http::STATUS_BAD_REQUEST);
        }

        if ($perPage < 1 || $perPage > 100) {
            return new JSONResponse(['error' => 'per_page_out_of_range'], Http::STATUS_BAD_REQUEST);
        }

        [$owner, $repoName] = explode('/', $repo, 2);
        $labelArray         = $labels === '' ? null : array_values(array_filter(array_map('trim', explode(',', $labels))));

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

        $validationError = $this->validateSubmission(repo: $repo, title: $title, body: $body);
        if ($validationError !== null) {
            return $validationError;
        }

        $rateLimitResponse = $this->enforceSubmitRateLimit(uid: $uid);
        if ($rateLimitResponse !== null) {
            return $rateLimitResponse;
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

        // Mark the rate-limit slot consumed (stores submission time so we can compute Retry-After).
        $this->submitRateCache->set('openregister.feature_submission:'.$uid, time(), self::SUBMIT_RATE_LIMIT_TTL);

        return new JSONResponse($result, Http::STATUS_CREATED);
    }//end create()

    /**
     * Validate the submission body. Returns null on success, or a 400 JSONResponse on the first
     * validation failure. Extracted from `create()` so each method stays within PHPMD's
     * CyclomaticComplexity + NPathComplexity thresholds, and so tasks 1.14–1.22 can layer extra
     * guards (repo allowlist, specRef format, sort allowlist, labels validation, admin opt-out)
     * by adding more validator calls without inflating `create()`.
     *
     * @param string $repo  `<owner>/<repo>` slug from the request body
     * @param string $title Issue title from the request body
     * @param string $body  Issue body from the request body
     *
     * @return JSONResponse|null Null on success, 400 with structured error_code on failure.
     *
     * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-3
     */
    private function validateSubmission(string $repo, string $title, string $body): ?JSONResponse
    {
        if (preg_match('#^[A-Za-z0-9][A-Za-z0-9_.-]*/[A-Za-z0-9][A-Za-z0-9_.-]*$#', $repo) !== 1) {
            return new JSONResponse(['error' => 'repo_invalid_format'], Http::STATUS_BAD_REQUEST);
        }

        $titleLength = strlen($title);
        if ($titleLength < 3 || $titleLength > 200) {
            return new JSONResponse(['error' => 'title_invalid_length'], Http::STATUS_BAD_REQUEST);
        }

        if (strlen($body) < 10) {
            return new JSONResponse(['error' => 'body_invalid_length'], Http::STATUS_BAD_REQUEST);
        }

        return null;
    }//end validateSubmission()

    /**
     * Enforce the per-user 1/60s submission rate limit (task 1.6). Returns null when the user is
     * within their budget, or a 429 JSONResponse with `Retry-After` header when they are
     * currently rate-limited.
     *
     * @param string $uid Submitting user's UID.
     *
     * @return JSONResponse|null Null on success, 429 with structured error_code + retry_after.
     *
     * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-6
     */
    private function enforceSubmitRateLimit(string $uid): ?JSONResponse
    {
        $rateLimitKey = 'openregister.feature_submission:'.$uid;
        $existing     = $this->submitRateCache->get($rateLimitKey);
        if ($existing === null) {
            return null;
        }

        $retryAfter = max(1, self::SUBMIT_RATE_LIMIT_TTL - (time() - (int) $existing));
        $response   = new JSONResponse(
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
        if ($exception instanceof RuntimeException && $exception->getMessage() === 'github_pat_not_configured') {
            return $this->mapPatNotConfigured(isRead: $isRead);
        }

        if ($exception instanceof BadResponseException) {
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
     * @param BadResponseException $exception Guzzle exception carrying GitHub's response.
     *
     * @return JSONResponse|null 429 when rate-limit detected, null otherwise.
     *
     * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-9
     */
    private function mapGitHubRateLimit(BadResponseException $exception): ?JSONResponse
    {
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
