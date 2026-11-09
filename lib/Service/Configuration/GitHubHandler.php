<?php

/**
 * OpenRegister GitHub Handler
 *
 * This file contains the GitHubHandler class for interacting with the GitHub API
 * to discover, fetch, and manage OpenRegister configurations stored in GitHub repositories.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\Configuration
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2025 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Configuration;

use Exception;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use InvalidArgumentException;
use OCA\OpenRegister\Kernel\Contracts\GitHubClientInterface;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Handler for GitHub API operations
 *
 * Provides methods for:
 * - Searching for OpenRegister configurations across GitHub
 * - Fetching file contents from repositories
 * - Listing branches
 * - Parsing and validating configuration files
 *
 * @package OCA\OpenRegister\Service\Configuration
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 */
class GitHubHandler implements GitHubClientInterface {
	/**
	 * GitHub API base URL
	 */
	private const API_BASE = 'https://api.github.com';

	/**
	 * Rate limit for code search (per minute)
	 */
	private const SEARCH_RATE_LIMIT = 30;

	/**
	 * The tag every GitHub log message starts with.
	 */
	private const LOG_TAG = '[GitHubHandler] ';

	/**
	 * User-facing messages for API failures whose status alone decides the message.
	 */
	private const STATUS_MESSAGES = [
		401 => 'GitHub API authentication failed. Please check your API token in Settings or remove it to use '
			.'unauthenticated access (60 requests/hour limit).',
		404 => 'Repository or resource not found on GitHub. Please check the repository exists and is public.',
		422 => 'Invalid search query. Please try different search terms.',
		500 => 'GitHub API is temporarily unavailable. Please try again in a few minutes.',
		503 => 'GitHub API is temporarily unavailable. Please try again in a few minutes.',
	];

	/**
	 * The exception message of a failed request, before the failure's own message, by action.
	 */
	private const FAILURE_PREFIXES = [
		'get branches' => 'Failed to fetch branches: ',
		'get file content' => 'Failed to fetch file: ',
		'list files' => 'Failed to list configuration files: ',
		'get repository info' => 'Failed to fetch repository info: ',
	];

	/**
	 * Cache instance for storing enriched configuration data
	 *
	 * @var ICache
	 */
	private ICache $cache;

	/**
	 * GitHubHandler constructor
	 *
	 * @param IClient $client HTTP client (built by the DI factory in
	 *                        Application.php via IClientService::newClient)
	 * @param IAppConfig $appConfig App-level configuration service
	 * @param IConfig $config Per-user configuration service
	 * @param ICacheFactory $cacheFactory Cache factory
	 * @param AttributionFormatter $attributionFormatter Server-PAT attribution helper
	 * @param LoggerInterface $logger Logger instance
	 */
	public function __construct(
		private readonly IClient $client,
		private readonly IAppConfig $appConfig,
		private readonly IConfig $config,
		ICacheFactory $cacheFactory,
		private readonly AttributionFormatter $attributionFormatter,
		private readonly LoggerInterface $logger,
	) {
		$this->cache = $cacheFactory->createDistributed('openregister_github_configs');
	}//end __construct()

	/**
	 * Get authentication headers for GitHub API
	 *
	 * @return array<string, string> GitHub API headers.
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-non-aggregated-facet-isolation
	 */
	private function getHeaders(): array {
		$headers = [
			'Accept' => 'application/vnd.github+json',
			'X-GitHub-Api-Version' => '2022-11-28',
		];

		$token = $this->appConfig->getValueString('openregister', 'github_api_token', '');
		if (empty($token) === false) {
			$headers['Authorization'] = 'Bearer '.$token;
		}

		$this->logTokenUse(hasToken: empty($token) === false);
		return $headers;
	}//end getHeaders()

	/**
	 * Log whether a request goes out with the configured token or unauthenticated.
	 *
	 * @param bool $hasToken Whether a token is configured.
	 *
	 * @return void
	 */
	private function logTokenUse(bool $hasToken): void {
		if ($hasToken === true) {
			$this->logger->debug(
				message: self::LOG_TAG.'Using GitHub API token for authentication',
				context: self::logContext(extra: ['has_token' => true])
			);
			return;
		}

		$message = 'No GitHub API token configured - unauthenticated access (60 requests/hour limit)';
		$this->logger->warning(message: self::LOG_TAG.$message, context: self::logContext());
	}//end logTokenUse()

	/**
	 * The log context every GitHub log line starts with, then the line's own keys.
	 *
	 * @param array<string, mixed> $extra The line's own keys.
	 *
	 * @return array<string, mixed>
	 */
	private static function logContext(array $extra = []): array {
		return ['file' => __FILE__, 'line' => __LINE__] + $extra;
	}//end logContext()

	/**
	 * GET a GitHub API URL with the API headers and decode the JSON body.
	 *
	 * @param string               $url     The URL.
	 * @param array<string, mixed> $options Request options; the headers are added last.
	 *
	 * @return mixed The decoded body.
	 *
	 * @throws GuzzleException If the request fails.
	 */
	private function getJson(string $url, array $options = []): mixed {
		$response = $this->client->request('GET', $url, $options + ['headers' => $this->getHeaders()]);
		return json_decode($response->getBody(), true);
	}//end getJson()

	/**
	 * Log a failed API request and turn it into an exception for the caller.
	 *
	 * @param GuzzleException   $exception The request failure.
	 * @param RepositoryApiCall $call      What failed and the request's log keys, logged after the error.
	 *
	 * @return Exception
	 */
	private function requestFailure(GuzzleException $exception, RepositoryApiCall $call): Exception {
		$this->logger->error(
			message: self::LOG_TAG."GitHub API {$call->action} failed",
			context: self::logContext(extra: ['error' => $exception->getMessage()] + $call->context)
		);
		return new Exception(self::FAILURE_PREFIXES[$call->action].$exception->getMessage());
	}//end requestFailure()

	/**
	 * The HTTP status of a failed request, or null when there was no response.
	 *
	 * @param GuzzleException $exception The request failure.
	 *
	 * @return int|null
	 */
	private static function statusOf(GuzzleException $exception): ?int {
		if ($exception instanceof RequestException && $exception->hasResponse() === true) {
			return $exception->getResponse()->getStatusCode();
		}

		return null;
	}//end statusOf()

	/**
	 * Search for OpenRegister configurations on GitHub
	 *
	 * Uses GitHub Code Search API to find JSON files containing x-openregister property
	 *
	 * @param string $search Search terms to filter results (optional)
	 * @param int $page Page number for pagination
	 * @param int $perPage Results per page (max 100)
	 *
	 * @return ((array|int|mixed|null|string)[][]|int|mixed)[]
	 *
	 * @throws \Exception If API request fails
	 *
	 * @since 0.2.10
	 *
	 * @psalm-return array{total_count: 0|mixed,
	 *     results: list<array{branch: string, config: array,
	 *     description: ''|mixed, name: string,
	 *     organization: array{avatar_url: ''|mixed, name: string,
	 *     type: 'User'|mixed, url: ''|mixed}, owner: string, path: string,
	 *     raw_url: non-empty-string, repo: string, repository: mixed,
	 *     sha: null|string, stars: 0|mixed, url: mixed}>,
	 *     page: int, per_page: int}
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-non-aggregated-facet-isolation
	 */
	public function searchConfigurations(string $search = '', int $page = 1, int $perPage = 30): array {
		return $this->searchPage(search: $search, page: new RepositorySearchPage(page: $page, perPage: $perPage));
	}//end searchConfigurations()

	/**
	 * One page of configuration files found by Code Search.
	 *
	 * @param string               $search The user's search terms, possibly empty.
	 * @param RepositorySearchPage $page   The page asked for.
	 *
	 * @return array{total_count: mixed, results: list<array<string, mixed>>, page: int, per_page: int}
	 *
	 * @throws Exception If the API request fails.
	 */
	private function searchPage(string $search, RepositorySearchPage $page): array {
		try {
			// One Code Search call per request: search directly for the
			// `x-openregister` property in JSON files, so the results are
			// actual configuration files.
			$this->logger->info(
				message: self::LOG_TAG.'Searching for OpenRegister configurations',
				context: self::logContext(extra: ['search_terms' => $search, 'page' => $page->page, 'per_page' => $page->perPage])
			);

			// GitHub max is 100.
			$query = ['q' => self::configurationSearchQuery(search: $search), 'page' => $page->page, 'per_page' => min($page->perPage, 100)];
			$data = $this->codeSearchPage(query: $query);
			$allResults = array_map(fn (array $item): array => $this->configurationSearchResult(item: $item), $data['items'] ?? []);
			$this->logSearchComplete(data: $data, returned: count($allResults));

			return ['total_count' => $data['total_count'] ?? 0, 'results' => $allResults, 'page' => $page->page, 'per_page' => $page->perPage];
		} catch (GuzzleException $e) {
			throw $this->searchFailure(exception: $e, search: $search);
		}//end try
	}//end searchPage()

	/**
	 * Log the end of a configuration search.
	 *
	 * @param array<string, mixed> $data     The Code Search response.
	 * @param int                  $returned The number of results in this page.
	 *
	 * @return void
	 */
	private function logSearchComplete(array $data, int $returned): void {
		$this->logger->info(
			message: self::LOG_TAG.'Search complete',
			context: self::logContext(
				extra: ['total_found' => $data['total_count'] ?? 0, 'returned_in_page' => $returned, 'api_calls_used' => 1]
			)
		);
	}//end logSearchComplete()

	/**
	 * The Code Search query for configuration files, narrowed by the user's terms.
	 *
	 * GitHub Code Search matches exact text, so the query looks for the
	 * `x-openregister` property name; terms such as `repo:owner/repo` are appended.
	 *
	 * @param string $search The user's search terms, possibly empty.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-non-aggregated-facet-isolation
	 */
	private static function configurationSearchQuery(string $search): string {
		$searchQuery = '"x-openregister" extension:json';
		if (empty($search) === false) {
			$searchQuery .= ' '.$search;
		}

		return $searchQuery;
	}//end configurationSearchQuery()

	/**
	 * Fetch one page of GitHub Code Search results, sorted by repository stars.
	 *
	 * @param array{q: string, page: int, per_page: int} $query The query, page and page size.
	 *
	 * @return array<string, mixed> The decoded response.
	 *
	 * @throws GuzzleException If the request fails.
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-non-aggregated-facet-isolation
	 */
	private function codeSearchPage(array $query): array {
		$this->logger->debug(
			message: self::LOG_TAG.'GitHub Code Search query',
			context: self::logContext(extra: ['query' => $query['q'], 'page' => $query['page'], 'per_page' => $query['per_page']])
		);

		$data = $this->getJson(url: self::API_BASE.'/search/code', options: ['query' => $query + ['sort' => 'stars', 'order' => 'desc']]);
		$context = self::logContext(extra: self::searchResponseSummary(data: $data));
		$this->logger->debug(message: self::LOG_TAG.'GitHub Code Search response', context: $context);

		return $data;
	}//end codeSearchPage()

	/**
	 * The log keys that summarize a Code Search response.
	 *
	 * @param array<string, mixed> $data The decoded response.
	 *
	 * @return array{total_count: mixed, items_count: int, incomplete_results: mixed}
	 */
	private static function searchResponseSummary(array $data): array {
		return [
			'total_count' => $data['total_count'] ?? 0,
			'items_count' => count($data['items'] ?? []),
			'incomplete_results' => $data['incomplete_results'] ?? false,
		];
	}//end searchResponseSummary()

	/**
	 * One search hit as a configuration result, with details read from the file.
	 *
	 * The details come from the cache or from raw.githubusercontent.com, which
	 * does not count against the API rate limit.
	 *
	 * @param array<string, mixed> $item The Code Search item.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-non-aggregated-facet-isolation
	 */
	private function configurationSearchResult(array $item): array {
		$repository = $item['repository'];
		$location = new GitHubFileLocation(
			owner: $repository['owner']['login'],
			repo: $repository['name'],
			path: $item['path'],
			branch: $repository['default_branch'] ?? 'main'
		);
		$fileSha = $item['sha'] ?? null;
		$configDetails = $this->getEnrichedConfigDetails(location: $location, fileSha: $fileSha);

		return self::searchResultFields(item: $item, location: $location) + ['config' => $configDetails];
	}//end configurationSearchResult()

	/**
	 * The fields of a search result that come from the Code Search item itself.
	 *
	 * @param array<string, mixed> $item     The Code Search item; its `sha` is the file's blob SHA, if given.
	 * @param GitHubFileLocation   $location The file the item points at.
	 *
	 * @return array<string, mixed>
	 */
	private static function searchResultFields(array $item, GitHubFileLocation $location): array {
		$repository = $item['repository'];
		$rawUrl = "https://raw.githubusercontent.com/{$location->owner}/{$location->repo}/{$location->branch}/{$location->path}";
		return [
			'repository' => $repository['full_name'], 'owner' => $location->owner, 'repo' => $location->repo, 'path' => $location->path,
			'url' => $item['html_url'],
			'stars' => $repository['stargazers_count'] ?? 0,
			'description' => $repository['description'] ?? '',
			'name' => basename($location->path, '.json'),
			'branch' => $location->branch,
			'raw_url' => $rawUrl,
			'sha' => $item['sha'] ?? null,
			'organization' => self::searchResultOrganization(owner: $repository['owner']),
		];
	}//end searchResultFields()

	/**
	 * The organization block of a search result.
	 *
	 * @param array<string, mixed> $owner The repository owner from the Code Search item.
	 *
	 * @return array{name: mixed, avatar_url: mixed, type: mixed, url: mixed}
	 */
	private static function searchResultOrganization(array $owner): array {
		return [
			'name' => $owner['login'],
			'avatar_url' => $owner['avatar_url'] ?? '',
			'type' => $owner['type'] ?? 'User',
			'url' => $owner['html_url'] ?? '',
		];
	}//end searchResultOrganization()

	/**
	 * Log a failed search and turn it into an exception with a user-facing message.
	 *
	 * @param GuzzleException $exception The request failure.
	 * @param string          $search    The user's search terms.
	 *
	 * @return Exception
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-non-aggregated-facet-isolation
	 */
	private function searchFailure(GuzzleException $exception, string $search): Exception {
		$errorMessage = $exception->getMessage();
		$statusCode = self::statusOf(exception: $exception);

		$this->logger->error(
			message: self::LOG_TAG.'GitHub API search failed',
			context: self::logContext(extra: ['error' => $errorMessage, 'status_code' => $statusCode, '_search' => $search])
		);

		return new Exception($this->getGitHubErrorMessage(statusCode: $statusCode, rawError: $errorMessage));
	}//end searchFailure()

	/**
	 * Get user-friendly error message based on GitHub API error
	 *
	 * The full raw error is never returned; it only decides between messages.
	 *
	 * @param int|null $statusCode HTTP status code
	 * @param string $rawError Raw error message
	 *
	 * @return string User-friendly error message
	 *
	 * @since 0.2.10
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-non-aggregated-facet-isolation
	 */
	private function getGitHubErrorMessage(?int $statusCode, string $rawError): string {
		$isRateLimit = stripos($rawError, 'rate limit') !== false;
		if ($statusCode === 403) {
			return $this->forbiddenMessage(isRateLimit: $isRateLimit);
		}

		$known = self::STATUS_MESSAGES[$statusCode ?? 0] ?? null;
		if ($known !== null) {
			return $known;
		}

		if ($isRateLimit === true) {
			return 'GitHub API rate limit exceeded. Please wait a few minutes or configure an API token in Settings.';
		}

		return 'GitHub API request failed. Please try again or check your API token configuration in Settings.';
	}//end getGitHubErrorMessage()

	/**
	 * The user-facing message for a 403: a rate limit, with or without a token, or a refusal.
	 *
	 * @param bool $isRateLimit Whether the raw error names a rate limit.
	 *
	 * @return string
	 */
	private function forbiddenMessage(bool $isRateLimit): string {
		if ($isRateLimit === false) {
			return 'Access forbidden. Please check your GitHub API token permissions in Settings.';
		}

		$token = $this->appConfig->getValueString('openregister', 'github_api_token', '');
		if (empty($token) === true) {
			return 'GitHub API rate limit exceeded (60 requests/hour for unauthenticated). Please configure a GitHub API '
				.'token in Settings to increase to 5,000 requests/hour (30/minute for Code Search).';
		}

		return 'GitHub Code Search API rate limit exceeded (30 requests per minute). Please wait a few minutes before '
			.'trying again. The discovery search makes multiple API calls to find configurations.';
	}//end forbiddenMessage()

	/**
	 * Get enriched configuration details from cache or fetch if not cached
	 *
	 * Uses file SHA as cache key to automatically invalidate when file changes.
	 * Falls back to fetching without caching if SHA is not available.
	 *
	 * @param GitHubFileLocation $location The configuration file
	 * @param string|null        $fileSha  File SHA for cache invalidation
	 *
	 * @return array Configuration details
	 *
	 * @since 0.2.11
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-non-aggregated-facet-isolation
	 */
	private function getEnrichedConfigDetails(GitHubFileLocation $location, ?string $fileSha): array {
		$cacheKey = self::configCacheKey(location: $location, fileSha: $fileSha);
		$cached = $this->cachedConfigDetails(cacheKey: $cacheKey);
		if ($cached !== null) {
			return $cached;
		}

		$enriched = $this->enrich(location: $location);
		if ($enriched === null) {
			return ['title' => basename($location->path, '.json'), 'description' => '', 'version' => 'v.unknown', 'app' => null, 'type' => 'unknown'];
		}

		$this->cacheConfigDetails(cacheKey: $cacheKey, details: $enriched);
		return $enriched;
	}//end getEnrichedConfigDetails()

	/**
	 * The cache key of a file's enriched details, or null when the file has no SHA.
	 *
	 * @param GitHubFileLocation $location The file.
	 * @param string|null        $fileSha  The file's blob SHA.
	 *
	 * @return string|null
	 */
	private static function configCacheKey(GitHubFileLocation $location, ?string $fileSha): ?string {
		if ($fileSha === null) {
			return null;
		}

		return "config_{$location->owner}_{$location->repo}_{$fileSha}";
	}//end configCacheKey()

	/**
	 * Configuration details cached under a key, or null.
	 *
	 * @param string|null $cacheKey The key, or null when the file has no SHA (never cached).
	 *
	 * @return mixed The cached details, or null when there are none.
	 */
	private function cachedConfigDetails(?string $cacheKey): mixed {
		if ($cacheKey === null) {
			return null;
		}

		$cached = $this->cache->get($cacheKey);
		if ($cached !== null) {
			$this->logger->debug(message: self::LOG_TAG.'Using cached config details', context: self::logContext(extra: ['cache_key' => $cacheKey]));
		}

		return $cached;
	}//end cachedConfigDetails()

	/**
	 * Cache enriched details for 7 days; the file cannot change while its SHA is the same.
	 *
	 * @param string|null          $cacheKey The key, or null when the file has no SHA (not cached).
	 * @param array<string, mixed> $details  The details.
	 *
	 * @return void
	 */
	private function cacheConfigDetails(?string $cacheKey, array $details): void {
		if ($cacheKey === null) {
			return;
		}

		$this->cache->set($cacheKey, $details, 7 * 24 * 60 * 60);
		$this->logger->debug(message: self::LOG_TAG.'Cached config details', context: self::logContext(extra: ['cache_key' => $cacheKey]));
	}//end cacheConfigDetails()

	/**
	 * Enrich configuration metadata by fetching actual file contents
	 *
	 * Uses raw.githubusercontent.com (doesn't count against API rate limit!)
	 *
	 * @param string $owner Repository owner
	 * @param string $repo Repository name
	 * @param string $path File path
	 * @param string $branch Branch name
	 *
	 * @return (mixed|null|string)[]|null Configuration details from file, or null if failed
	 *
	 * @since 0.2.10
	 *
	 * @psalm-return array{
	 *     title: mixed|string,
	 *     description: ''|mixed,
	 *     version: 'v.unknown'|mixed,
	 *     app: mixed|null,
	 *     type: 'unknown'|mixed,
	 *     openregister: mixed|null
	 * }|null
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-non-aggregated-facet-isolation
	 */
	public function enrichConfigurationDetails(string $owner, string $repo, string $path, string $branch = 'main'): ?array {
		return $this->enrich(location: new GitHubFileLocation(owner: $owner, repo: $repo, path: $path, branch: $branch));
	}//end enrichConfigurationDetails()

	/**
	 * The metadata a configuration file describes itself with, or null when it cannot be read.
	 *
	 * @param GitHubFileLocation $location The configuration file.
	 *
	 * @return array<string, mixed>|null
	 */
	private function enrich(GitHubFileLocation $location): ?array {
		try {
			$data = $this->getRawConfiguration(location: $location);
			if ($data === null) {
				return null;
			}

			return self::configurationMetadata(data: $data, path: $location->path);
		} catch (Exception $e) {
			$where = ['owner' => $location->owner, 'repo' => $location->repo, 'path' => $location->path, 'error' => $e->getMessage()];
			$this->logger->warning(message: self::LOG_TAG.'Failed to enrich configuration details', context: self::logContext(extra: $where));
			return null;
		}//end try
	}//end enrich()

	/**
	 * The metadata a configuration document describes itself with.
	 *
	 * @param array<string, mixed> $data The decoded document.
	 * @param string               $path The file path, for the default title.
	 *
	 * @return array<string, mixed>
	 */
	private static function configurationMetadata(array $data, string $path): array {
		return [
			'title' => $data['info']['title'] ?? basename($path, '.json'),
			'description' => $data['info']['description'] ?? '',
			'version' => $data['info']['version'] ?? 'v.unknown',
			'app' => $data['x-openregister']['app'] ?? null,
			'type' => $data['x-openregister']['type'] ?? 'unknown',
			'openregister' => $data['x-openregister']['openregister'] ?? null,
		];
	}//end configurationMetadata()

	/**
	 * Fetch and decode a JSON file from raw.githubusercontent.com.
	 *
	 * The raw host does not count against the API rate limit.
	 *
	 * @param GitHubFileLocation $location The file.
	 *
	 * @return array|null The decoded document (empty for a JSON scalar), or null when it is not valid JSON.
	 *
	 * @throws Exception When a segment is unsafe or the request fails.
	 */
	private function getRawConfiguration(GitHubFileLocation $location): ?array {
		$rawUrl = $location->rawUrl();
		$this->logger->debug(message: self::LOG_TAG.'Enriching configuration details from raw URL', context: self::logContext(extra: ['url' => $rawUrl]));

		$response = $this->client->request('GET', $rawUrl, ['headers' => ['Accept' => 'application/json'], 'allow_redirects' => false]);

		$data = json_decode($response->getBody(), true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			$context = self::logContext(extra: ['url' => $rawUrl, 'error' => json_last_error_msg()]);
			$this->logger->warning(message: self::LOG_TAG.'Failed to parse configuration JSON', context: $context);
			return null;
		}

		if (is_array($data) === false) {
			return [];
		}

		return $data;
	}//end getRawConfiguration()

	/**
	 * Get list of branches for a repository
	 *
	 * @param string $owner Repository owner
	 * @param string $repo Repository name
	 *
	 * @return (false|mixed|null)[][] List of branches with name and commit info
	 *
	 * @throws \Exception If API request fails
	 *
	 * @since 0.2.10
	 *
	 * @psalm-return array<array{name: mixed, commit: mixed|null, protected: false|mixed}>
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-non-aggregated-facet-isolation
	 */
	public function getBranches(string $owner, string $repo): array {
		$where = ['owner' => $owner, 'repo' => $repo];
		try {
			$this->logger->info(message: self::LOG_TAG.'Fetching branches from GitHub', context: self::logContext(extra: $where));
			$branches = $this->getJson(url: self::API_BASE."/repos/{$owner}/{$repo}/branches");

			return array_map(self::branchSummary(...), $branches);
		} catch (GuzzleException $e) {
			throw $this->requestFailure(exception: $e, call: new RepositoryApiCall(action: 'get branches', context: $where));
		}
	}//end getBranches()

	/**
	 * One branch in the standardized format.
	 *
	 * @param array<string, mixed> $branch The branch from the API.
	 *
	 * @return array{name: mixed, commit: mixed, protected: mixed}
	 */
	private static function branchSummary(array $branch): array {
		return [
			'name' => $branch['name'],
			'commit' => $branch['commit']['sha'] ?? null,
			'protected' => $branch['protected'] ?? false,
		];
	}//end branchSummary()

	/**
	 * Get file content from a repository
	 *
	 * @param string $owner Repository owner
	 * @param string $repo Repository name
	 * @param string $path File path in repository
	 * @param string $branch Branch name (default: main)
	 *
	 * @return array Decoded JSON content
	 * @throws \Exception If file cannot be fetched or parsed
	 *
	 * @since 0.2.10
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-non-aggregated-facet-isolation
	 */
	public function getFileContent(string $owner, string $repo, string $path, string $branch = 'main'): array {
		return $this->fileContent(location: new GitHubFileLocation(owner: $owner, repo: $repo, path: $path, branch: $branch));
	}//end getFileContent()

	/**
	 * The decoded JSON content of a file, fetched through the Contents API.
	 *
	 * @param GitHubFileLocation $location The file.
	 *
	 * @return array The decoded document.
	 *
	 * @throws Exception If the file cannot be fetched or parsed.
	 */
	private function fileContent(GitHubFileLocation $location): array {
		$where = ['owner' => $location->owner, 'repo' => $location->repo, 'path' => $location->path, 'branch' => $location->branch];
		try {
			$this->logger->info(message: self::LOG_TAG.'Fetching file from GitHub', context: self::logContext(extra: $where));
			$url = self::API_BASE."/repos/{$location->owner}/{$location->repo}/contents/{$location->path}";
			$data = $this->getJson(url: $url, options: ['query' => ['ref' => $location->branch]]);

			return self::decodedContent(data: $data);
		} catch (GuzzleException $e) {
			throw $this->requestFailure(exception: $e, call: new RepositoryApiCall(action: 'get file content', context: $where));
		}
	}//end fileContent()

	/**
	 * The JSON document inside a Contents API response.
	 *
	 * @param mixed $data The decoded Contents API response.
	 *
	 * @return mixed The decoded document.
	 *
	 * @throws Exception When the response has no content or the content is not JSON.
	 */
	private static function decodedContent(mixed $data): mixed {
		if (($data['content'] ?? null) === null) {
			throw new Exception('No content found in file');
		}

		$json = json_decode(base64_decode($data['content']), true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			throw new Exception('Invalid JSON in file: '.json_last_error_msg());
		}

		return $json;
	}//end decodedContent()

	/**
	 * List OpenRegister configuration files in a repository
	 *
	 * Searches for files matching naming conventions:
	 * - openregister.json
	 * - *.openregister.json
	 * - Files containing x-openregister property
	 *
	 * @param string $owner Repository owner
	 * @param string $repo Repository name
	 * @param string $branch Branch name (default: main)
	 * @param string $path Directory path to search (default: root)
	 *
	 * @return ((mixed|null|string)[]|mixed|null)[][]
	 *
	 * @throws \Exception If API request fails
	 *
	 * @since 0.2.10
	 *
	 * @psalm-return list<array{config: array{app: mixed|null,
	 *     description: ''|mixed, title: mixed|string, type: 'manual'|mixed,
	 *     version: '1.0.0'|mixed}, path: mixed, sha: mixed|null,
	 *     url: mixed|null}>
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-non-aggregated-facet-isolation
	 */
	public function listConfigurationFiles(string $owner, string $repo, string $branch = 'main', string $path = ''): array {
		return $this->configurationFiles(directory: new GitHubFileLocation(owner: $owner, repo: $repo, path: $path, branch: $branch));
	}//end listConfigurationFiles()

	/**
	 * The configuration files Code Search finds in a repository branch.
	 *
	 * @param GitHubFileLocation $directory The repository, branch and directory to search.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @throws Exception If the API request fails.
	 */
	private function configurationFiles(GitHubFileLocation $directory): array {
		$where = ['owner' => $directory->owner, 'repo' => $directory->repo, 'branch' => $directory->branch];
		try {
			$this->logger->info(
				message: self::LOG_TAG.'Listing configuration files from GitHub',
				context: self::logContext(extra: $where + ['path' => $directory->path])
			);
			$searchQuery = "repo:{$directory->repoPath()} filename:openregister.json OR filename:*.openregister.json extension:json";
			$data = $this->getJson(url: self::API_BASE.'/search/code', options: ['query' => ['q' => $searchQuery]]);

			return $this->configurationFileEntries(items: $data['items'] ?? [], directory: $directory);
		} catch (GuzzleException $e) {
			throw $this->requestFailure(exception: $e, call: new RepositoryApiCall(action: 'list files', context: $where));
		}
	}//end configurationFiles()

	/**
	 * The search hits that are valid configuration files, as file entries.
	 *
	 * @param array<int, array<string, mixed>> $items     The Code Search items.
	 * @param GitHubFileLocation               $directory The repository and branch.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function configurationFileEntries(array $items, GitHubFileLocation $directory): array {
		$files = [];
		foreach ($items as $item) {
			$configData = $this->parseConfigurationFile(
				location: new GitHubFileLocation(owner: $directory->owner, repo: $directory->repo, path: $item['path'], branch: $directory->branch)
			);
			if ($configData !== null) {
				$files[] = self::configurationFileEntry(item: $item, configData: $configData);
			}
		}

		return $files;
	}//end configurationFileEntries()

	/**
	 * One configuration file entry: where it is and what it says about itself.
	 *
	 * @param array<string, mixed> $item       The Code Search item.
	 * @param array<string, mixed> $configData The parsed configuration.
	 *
	 * @return array<string, mixed>
	 */
	private static function configurationFileEntry(array $item, array $configData): array {
		$info = $configData['info'] ?? [];
		$xReg = $configData['x-openregister'] ?? [];
		return [
			'path' => $item['path'], 'sha' => $item['sha'] ?? null, 'url' => $item['html_url'] ?? null,
			'config' => [
				'title' => $info['title'] ?? $xReg['title'] ?? basename($item['path']),
				'description' => $info['description'] ?? $xReg['description'] ?? '',
				'version' => $info['version'] ?? $xReg['version'] ?? '1.0.0',
				'app' => $xReg['app'] ?? null,
				'type' => $xReg['type'] ?? 'manual',
			],
		];
	}//end configurationFileEntry()

	/**
	 * Parse and validate a configuration file
	 *
	 * @param GitHubFileLocation $location The file.
	 *
	 * @return array|null Parsed configuration or null if invalid
	 *
	 * @since 0.2.10
	 *
	 * @psalm-return array{openapi: mixed, 'x-openregister': mixed,...}|null
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-non-aggregated-facet-isolation
	 */
	private function parseConfigurationFile(GitHubFileLocation $location): ?array {
		$path = $location->path;
		try {
			$content = $this->fileContent(location: $location);
		} catch (Exception $e) {
			$context = self::logContext(extra: ['path' => $path, 'error' => $e->getMessage()]);
			$this->logger->debug(message: self::LOG_TAG.'Failed to parse configuration file', context: $context);
			return null;
		}

		// Validate that it's a valid OpenRegister configuration.
		if (isset($content['openapi']) === false || isset($content['x-openregister']) === false) {
			$message = 'File does not contain required OpenRegister structure';
			$this->logger->debug(message: self::LOG_TAG.$message, context: self::logContext(extra: ['path' => $path]));
			return null;
		}

		return $content;
	}//end parseConfigurationFile()

	/**
	 * Get repositories that the authenticated user has access to
	 *
	 * @param int $page Page number (default: 1)
	 * @param int $perPage Items per page (default: 100, max: 100)
	 *
	 * @return (mixed|string)[][] List of repositories with name, full_name, owner, etc.
	 *
	 * @throws \Exception If API request fails
	 *
	 * @psalm-return array<
	 *     array{
	 *         id: mixed,
	 *         name: mixed,
	 *         full_name: mixed,
	 *         owner: mixed,
	 *         owner_type: mixed,
	 *         private: mixed,
	 *         description: ''|mixed,
	 *         default_branch: 'main'|mixed,
	 *         url: mixed,
	 *         api_url: mixed
	 *     }
	 * >
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-non-aggregated-facet-isolation
	 */
	public function getRepositories(int $page = 1, int $perPage = 100): array {
		$token = $this->appConfig->getValueString('openregister', 'github_api_token', '');
		if (empty($token) === true) {
			$message = 'GitHub API token not configured - returning empty repositories list';
			$this->logger->info(message: self::LOG_TAG.$message, context: self::logContext());
			return [];
		}

		try {
			return $this->fetchRepositories(page: $page, perPage: $perPage);
		} catch (GuzzleException $e) {
			return $this->repositoriesFailure(exception: $e);
		}
	}//end getRepositories()

	/**
	 * Fetch one page of the user's repositories.
	 *
	 * @param int $page    Page number.
	 * @param int $perPage Items per page; GitHub caps it at 100.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @throws GuzzleException If the request fails.
	 */
	private function fetchRepositories(int $page, int $perPage): array {
		$context = self::logContext(extra: ['page' => $page, 'per_page' => $perPage]);
		$this->logger->info(message: self::LOG_TAG.'Fetching repositories from GitHub', context: $context);
		// Type all, owner or member; GitHub's page size max is 100.
		$query = ['type' => 'all', 'sort' => 'updated', 'direction' => 'desc', 'page' => $page, 'per_page' => min($perPage, 100)];
		$repos = $this->getJson(url: self::API_BASE.'/user/repos', options: ['query' => $query]);

		return array_map(self::repositorySummary(...), $repos);
	}//end fetchRepositories()

	/**
	 * One repository of the user's list.
	 *
	 * @param array<string, mixed> $repo The repository from the API.
	 *
	 * @return array<string, mixed>
	 */
	private static function repositorySummary(array $repo): array {
		return [
			'id' => $repo['id'],
			'name' => $repo['name'],
			'full_name' => $repo['full_name'],
			'owner' => $repo['owner']['login'],
			// User or Organization.
			'owner_type' => $repo['owner']['type'],
			'private' => $repo['private'],
			'description' => $repo['description'] ?? '',
			'default_branch' => $repo['default_branch'] ?? 'main',
			'url' => $repo['html_url'],
			'api_url' => $repo['url'],
		];
	}//end repositorySummary()

	/**
	 * Handle a failed repository listing.
	 *
	 * A 401 means the configured token is not accepted: logged, and the list is empty
	 * instead of an error. Anything else is logged and rethrown.
	 *
	 * @param GuzzleException $exception The client failure.
	 *
	 * @return array An empty list for a 401.
	 *
	 * @throws Exception For every other failure.
	 */
	private function repositoriesFailure(GuzzleException $exception): array {
		$statusCode = self::statusOf(exception: $exception);
		if ($statusCode === 401) {
			$this->logger->info(
				message: self::LOG_TAG.'GitHub API auth failed or not configured - returning empty list',
				context: self::logContext(extra: ['status_code' => $statusCode, 'has_token' => true])
			);
			return [];
		}

		$this->logger->error(
			message: self::LOG_TAG.'GitHub API get repositories failed',
			context: self::logContext(extra: ['error' => $exception->getMessage(), 'status_code' => $statusCode])
		);
		throw new Exception('Failed to fetch repositories: '.$exception->getMessage());
	}//end repositoriesFailure()

	/**
	 * Get repository information including default branch
	 *
	 * @param string $owner Repository owner
	 * @param string $repo Repository name
	 *
	 * @return (false|mixed|null|string)[] Repository info with default_branch
	 *
	 * @throws \Exception If API request fails
	 *
	 * @since 0.2.10
	 *
	 * @psalm-return array{
	 *     id: mixed|null,
	 *     name: mixed|string,
	 *     full_name: mixed|string,
	 *     owner: mixed|string,
	 *     private: false|mixed,
	 *     description: ''|mixed,
	 *     default_branch: 'main'|mixed,
	 *     url: ''|mixed
	 * }
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-non-aggregated-facet-isolation
	 */
	public function getRepositoryInfo(string $owner, string $repo): array {
		try {
			$repoData = $this->getJson(url: self::API_BASE."/repos/{$owner}/{$repo}");
		} catch (GuzzleException $e) {
			$where = ['owner' => $owner, 'repo' => $repo];
			throw $this->requestFailure(exception: $e, call: new RepositoryApiCall(action: 'get repository info', context: $where));
		}

		return [
			'id' => $repoData['id'] ?? null, 'name' => $repoData['name'] ?? $repo, 'full_name' => $repoData['full_name'] ?? "{$owner}/{$repo}",
			'owner' => $repoData['owner']['login'] ?? $owner, 'private' => $repoData['private'] ?? false,
			'description' => $repoData['description'] ?? '',
			'default_branch' => $repoData['default_branch'] ?? 'main',
			'url' => $repoData['html_url'] ?? '',
		];
	}//end getRepositoryInfo()

	/**
	 * Publish a configuration file to GitHub
	 *
	 * Creates or updates a file in the specified repository, branch, and path.
	 * Uses the GitHub Contents API which requires the file SHA for updates.
	 *
	 * @param string $owner Repository owner
	 * @param string $repo Repository name
	 * @param string $path File path in repository (e.g., 'lib/Settings/config.json')
	 * @param string $branch Branch name (default: main)
	 * @param string $content File content (JSON string)
	 * @param string $commitMessage Commit message
	 * @param string|null $fileSha SHA of existing file (required for updates, null for new files)
	 *
	 * @return (mixed|null|true)[] Response with commit SHA, file SHA, and commit info
	 *
	 * @throws \Exception If publish fails
	 *
	 * @psalm-return array{
	 *     success: true, commit_sha: mixed|null, file_sha: mixed|null,
	 *     commit_url: mixed|null, file_url: mixed|null
	 * }
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-non-aggregated-facet-isolation
	 */
	public function publishConfiguration(
		string $owner,
		string $repo,
		string $path,
		string $branch,
		string $content,
		string $commitMessage,
		?string $fileSha = null,
	): array {
		$payload = ['message' => $commitMessage, 'content' => base64_encode($content), 'branch' => $branch] + self::shaField(fileSha: $fileSha);
		return $this->publish(location: new GitHubFileLocation(owner: $owner, repo: $repo, path: $path, branch: $branch), payload: $payload);
	}//end publishConfiguration()

	/**
	 * The `sha` field of a Contents API body: present only when the publish updates a file.
	 *
	 * @param string|null $fileSha SHA of the existing file, or null for a new file.
	 *
	 * @return array<string, string>
	 */
	private static function shaField(?string $fileSha): array {
		if ($fileSha === null) {
			return [];
		}

		return ['sha' => $fileSha];
	}//end shaField()

	/**
	 * Publish a file and describe the commit, or turn the failure into a readable exception.
	 *
	 * @param GitHubFileLocation    $location The file.
	 * @param array<string, string> $payload  The Contents API body.
	 *
	 * @return array{success: true, commit_sha: mixed, file_sha: mixed, commit_url: mixed, file_url: mixed}
	 *
	 * @throws Exception If the publish fails.
	 */
	private function publish(GitHubFileLocation $location, array $payload): array {
		try {
			$context = ['owner' => $location->owner, 'repo' => $location->repo, 'path' => $location->path, 'branch' => $location->branch];
			$context['is_update'] = isset($payload['sha']);
			$this->logger->info(message: '[GitHubHandler] Publishing configuration to GitHub', context: ['file' => __FILE__, 'line' => __LINE__] + $context);

			return $this->published(result: $this->putContents(location: $location, payload: $payload));
		} catch (GuzzleException $e) {
			throw $this->publishFailure(exception: $e, location: $location);
		}
	}//end publish()

	/**
	 * Log a successful publish and describe the commit and the file.
	 *
	 * @param mixed $result The decoded Contents API response.
	 *
	 * @return array{success: true, commit_sha: mixed, file_sha: mixed, commit_url: mixed, file_url: mixed}
	 */
	private function published(mixed $result): array {
		$commitSha = $result['commit']['sha'] ?? null;
		$fileSha = $result['content']['sha'] ?? null;
		$context = ['file' => __FILE__, 'line' => __LINE__, 'commit_sha' => $commitSha, 'file_sha' => $fileSha];
		$this->logger->info(message: '[GitHubHandler] Configuration published successfully', context: $context);

		return [
			'success' => true,
			'commit_sha' => $commitSha,
			'file_sha' => $fileSha,
			'commit_url' => $result['commit']['html_url'] ?? null,
			'file_url' => $result['content']['html_url'] ?? null,
		];
	}//end published()

	/**
	 * Create or update a file through the GitHub Contents API.
	 *
	 * @param GitHubFileLocation   $location The file.
	 * @param array<string, mixed> $payload  The request body.
	 *
	 * @return mixed The decoded response.
	 *
	 * @throws GuzzleException If the request fails.
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-non-aggregated-facet-isolation
	 */
	private function putContents(GitHubFileLocation $location, array $payload): mixed {
		$encodedPath = $location->encodedPath();
		$apiUrl = self::API_BASE."/repos/{$location->owner}/{$location->repo}/contents/{$encodedPath}";

		$context = ['url' => $apiUrl, 'path' => $location->path, 'encoded_path' => $encodedPath, 'branch' => $location->branch];
		$this->logger->debug(message: '[GitHubHandler] GitHub API publish request', context: ['file' => __FILE__, 'line' => __LINE__] + $context);

		$response = $this->client->request('PUT', $apiUrl, ['headers' => $this->getHeaders(), 'json' => $payload]);
		return json_decode($response->getBody(), true);
	}//end putContents()

	/**
	 * Log a failed publish and turn it into an exception with a readable message.
	 *
	 * @param GuzzleException    $exception The request failure.
	 * @param GitHubFileLocation $location  The file that was being published.
	 *
	 * @return Exception
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-non-aggregated-facet-isolation
	 */
	private function publishFailure(GuzzleException $exception, GitHubFileLocation $location): Exception {
		$statusCode = null;
		$errorMessage = $exception->getMessage();
		if ($exception instanceof RequestException && $exception->hasResponse() === true) {
			$statusCode = $exception->getResponse()->getStatusCode();
			$errorMessage = $this->publishErrorMessage(exception: $exception, location: $location) ?? $errorMessage;
		}

		$context = ['error' => $errorMessage, 'status_code' => $statusCode, 'owner' => $location->owner, 'repo' => $location->repo];
		$context += ['path' => $location->path, 'branch' => $location->branch];
		$this->logger->error(message: '[GitHubHandler] GitHub API publish failed', context: ['file' => __FILE__, 'line' => __LINE__] + $context);

		return new Exception('Failed to publish configuration: '.$errorMessage);
	}//end publishFailure()

	/**
	 * GitHub's own error message, with context for the common failures, or null when it sent none.
	 *
	 * @param RequestException   $exception The failure, with a response.
	 * @param GitHubFileLocation $location  The file that was being published.
	 *
	 * @return mixed
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-non-aggregated-facet-isolation
	 */
	private function publishErrorMessage(RequestException $exception, GitHubFileLocation $location): mixed {
		$response = $exception->getResponse();
		$errorData = json_decode($response->getBody()->getContents(), true);
		$message = ($errorData['message'] ?? null);
		if ($message === null) {
			return null;
		}

		if ($response->getStatusCode() === 422) {
			return self::validationMessage(message: $message, location: $location);
		}

		return self::accessMessage(statusCode: $response->getStatusCode(), location: $location) ?? $message;
	}//end publishErrorMessage()

	/**
	 * The message for a publish refused with a 404 or 403, or null for any other status.
	 *
	 * @param int                $statusCode The HTTP status.
	 * @param GitHubFileLocation $location   The file that was being published.
	 *
	 * @return string|null
	 */
	private static function accessMessage(int $statusCode, GitHubFileLocation $location): ?string {
		$repoPath = $location->repoPath();
		return match ($statusCode) {
			404 => "Not Found - Repository '{$repoPath}', branch '{$location->branch}', "
				."or path '{$location->path}' may not exist or you may not have access",
			403 => 'Forbidden - You may not have write access to repository '
				."'{$repoPath}' or the branch '{$location->branch}' is protected",
			default => null,
		};
	}//end accessMessage()

	/**
	 * GitHub's message for a publish refused with a 422, with the branch and path to check.
	 *
	 * @param mixed              $message  GitHub's own message.
	 * @param GitHubFileLocation $location The file that was being published.
	 *
	 * @return string
	 */
	private static function validationMessage(mixed $message, GitHubFileLocation $location): string {
		return "Validation Error - {$message}. Check that the branch "
			."'{$location->branch}' exists and the path '{$location->path}' is valid";
	}//end validationMessage()

	/**
	 * Get file SHA for a specific file (needed for updates)
	 *
	 * @param string $owner Repository owner
	 * @param string $repo Repository name
	 * @param string $path File path in repository
	 * @param string $branch Branch name (default: main)
	 *
	 * @return string|null File SHA or null if file doesn't exist
	 * @throws \Exception If API request fails
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-non-aggregated-facet-isolation
	 */
	public function getFileSha(string $owner, string $repo, string $path, string $branch = 'main'): ?string {
		return $this->fileSha(location: new GitHubFileLocation(owner: $owner, repo: $repo, path: $path, branch: $branch));
	}//end getFileSha()

	/**
	 * The blob SHA of a file, or null when the file does not exist yet.
	 *
	 * @param GitHubFileLocation $location The file.
	 *
	 * @return string|null
	 *
	 * @throws Exception For every failure but a 404.
	 */
	private function fileSha(GitHubFileLocation $location): ?string {
		try {
			// The path is URL-encoded per segment (it may contain slashes, spaces, etc.).
			$url = self::API_BASE."/repos/{$location->owner}/{$location->repo}/contents/{$location->encodedPath()}";
			$response = $this->client->request('GET', $url, ['query' => ['ref' => $location->branch], 'headers' => $this->getHeaders()]);

			$fileInfo = json_decode($response->getBody(), true);
			return $fileInfo['sha'] ?? null;
		} catch (GuzzleException $e) {
			// A file that does not exist yet is fine: it is a new file.
			$this->requireMissingFile(exception: $e, location: $location);
			return null;
		}
	}//end fileSha()

	/**
	 * Accept a 404 (the file does not exist yet); log and throw every other failure.
	 *
	 * @param GuzzleException    $exception The request failure.
	 * @param GitHubFileLocation $location  The file.
	 *
	 * @return void
	 *
	 * @throws Exception For every failure but a 404.
	 */
	private function requireMissingFile(GuzzleException $exception, GitHubFileLocation $location): void {
		if ($exception instanceof RequestException && $exception->hasResponse() === true && $exception->getResponse()->getStatusCode() === 404) {
			return;
		}

		$context = ['error' => $exception->getMessage(), 'owner' => $location->owner, 'repo' => $location->repo, 'path' => $location->path];
		$this->logger->error(message: '[GitHubHandler] GitHub API get file SHA failed', context: ['file' => __FILE__, 'line' => __LINE__] + $context);
		throw new Exception('Failed to get file SHA: '.$exception->getMessage());
	}//end requireMissingFile()

	/**
	 * Get user-specific GitHub token
	 *
	 * @param string $userId The user ID
	 *
	 * @return null|string The token or null if not set
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-non-aggregated-facet-isolation
	 */
	public function getUserToken(string $userId): ?string {
		$token = $this->config->getUserValue($userId, 'openregister', 'github_token', '');
		if ($token !== '') {
			return $token;
		}

		return null;
	}//end getUserToken()

	/**
	 * Set user-specific GitHub token
	 *
	 * @param string|null $token The token to set, or null to clear
	 * @param string $userId The user ID
	 *
	 * @return void
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-non-aggregated-facet-isolation
	 */
	public function setUserToken(?string $token, string $userId): void {
		if ($token === null) {
			$this->config->deleteUserValue($userId, 'openregister', 'github_token');
			return;
		}

		$this->config->setUserValue($userId, 'openregister', 'github_token', $token);
	}//end setUserToken()

	/**
	 * Validate GitHub token by making a test API request
	 *
	 * @param string|null $userId The user ID (optional, uses current user if not provided)
	 *
	 * @return bool True if token is valid, false otherwise
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-non-aggregated-facet-isolation
	 */
	public function validateToken(?string $userId = null): bool {
		try {
			$token = $this->validationToken(userId: $userId);
			if ($token === null || $token === '') {
				return false;
			}

			// A simple API request tells whether the token is accepted.
			$response = $this->client->request('GET', self::API_BASE.'/user', ['headers' => self::bearerHeaders(token: $token)]);
			return $response->getStatusCode() === 200;
		} catch (Exception $e) {
			$context = ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()];
			$this->logger->error(message: '[GitHubHandler] GitHub token validation failed', context: $context);
			return false;
		}
	}//end validateToken()

	/**
	 * The token to validate: the user's token when a user is named, else the app-level token.
	 *
	 * @param string|null $userId The user ID, or null for the app-level token.
	 *
	 * @return string|null
	 */
	private function validationToken(?string $userId): ?string {
		$token = $this->appConfig->getValueString('openregister', 'github_api_token', '');
		if ($userId !== null) {
			$token = $this->getUserToken(userId: $userId);
		}

		return $token;
	}//end validationToken()

	/**
	 * The API headers with a bearer token.
	 *
	 * @param string $token The token.
	 *
	 * @return array<string, string>
	 */
	private static function bearerHeaders(string $token): array {
		return [
			'Accept' => 'application/vnd.github+json',
			'X-GitHub-Api-Version' => '2022-11-28',
			'Authorization' => 'Bearer '.$token,
		];
	}//end bearerHeaders()

	/**
	 * List GitHub Issues for a repository, with sensitive fields stripped and pull requests excluded.
	 *
	 * When `$labels` contains two or more entries, the method issues one GitHub request per label and
	 * merges the results deduplicated by issue `number` — OR semantics (see D23 in design.md). When
	 * `$labels` has exactly one entry, a single request with `labels=<name>` is issued. When `null`,
	 * no label filter is applied.
	 *
	 * Reads the app-level PAT from `IAppConfig::openregister::github_api_token`. Returns an empty
	 * `items` array with a `hint: github_pat_not_configured` marker when the PAT is missing (the
	 * controller turns this into a clean HTTP 200 graceful-degradation response, task 1.7).
	 *
	 * @param string $owner GitHub repo owner (validated by caller)
	 * @param string $repo GitHub repo name (validated by caller)
	 * @param string $state Issue state filter (`open`, `closed`, or `all`)
	 * @param string $sort Sort key (`reactions-+1` is the spec default)
	 * @param int $perPage Page size (1-100)
	 * @param array<string>|null $labels Optional label filter; null = no filter, 1+ = OR-merge
	 *
	 * @return array{items: array, hint?: string, github_status?: int, reset_at?: string}
	 *
	 * @throws GuzzleException When the underlying HTTP client errors in a way not covered by the
	 *                         rate-limit / 4xx / 5xx mapping (e.g. DNS failure).
	 *
	 * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-1
	 * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-10
	 */
	public function listIssues(
		string $owner, string $repo, string $state='open', string $sort='reactions-+1', int $perPage=30, ?array $labels=null
	): array {
		$query = (new GitHubIssueQuery(owner: $owner, repo: $repo))->filteredBy(state: $state, sort: $sort)->pagedBy(perPage: $perPage);
		return $this->listIssuesFor(query: $query->withLabels(labels: $labels));
	}//end listIssues()

	/**
	 * List the GitHub Issues a listing names, as listIssues() does.
	 *
	 * @param GitHubIssueQuery $query The repository, filters, page size and labels.
	 *
	 * @return array{items: array, hint?: string, github_status?: int, reset_at?: string}
	 *
	 * @throws GuzzleException When the HTTP client errors outside the structured mapping.
	 *
	 * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-1
	 */
	public function listIssuesFor(GitHubIssueQuery $query): array {
		// PAT is optional for reads — GitHub's public Issues API serves anonymous
		// requests at a lower rate limit (60/hr unauth vs 5000/hr authenticated).
		// Supersedes the original D23 (add-features-roadmap-menu) decision to gate
		// reads on a configured PAT.
		$token = $this->appConfig->getValueString('openregister', 'github_api_token', '');
		return $this->issuesFor(query: $query->withToken(token: $token));
	}//end listIssuesFor()

	/**
	 * The issues of a listing: one request, or one per label merged (OR semantics) for two or more labels.
	 *
	 * @param GitHubIssueQuery $query The listing.
	 *
	 * @return array{items: array}
	 *
	 * @throws GuzzleException
	 */
	private function issuesFor(GitHubIssueQuery $query): array {
		$labels = $query->labels();
		if (count($labels) < 2) {
			return ['items' => $this->fetchIssuesPage(query: $query, label: ($labels[0] ?? null))];
		}

		$pages = [];
		foreach ($labels as $label) {
			$pages[] = $this->fetchIssuesPage(query: $query, label: $label);
		}

		return ['items' => $query->merge(pages: $pages)];
	}//end issuesFor()

	/**
	 * Create a GitHub Issue on the named repository.
	 *
	 * Authorship policy: prefer the user's per-user PAT (`IConfig::openregister::github_token`); when
	 * absent or empty, fall back to the app-level PAT (`IAppConfig::openregister::github_api_token`)
	 * with an attribution prefix prepended to the body so the human submitter is preserved.
	 *
	 * When `$specRef` is non-empty, the body sent to GitHub is suffixed with
	 * `\n\n---\nRelated capability: \`<specRef>\`\n` AND the created issue carries a
	 * `specRef:<slug>` label. The label is created on-demand by GitHub if absent.
	 *
	 * Returns the structured response shape `{number, html_url, state, specRef?, used_server_pat}`.
	 *
	 * @param string $owner GitHub repo owner (validated by caller)
	 * @param string $repo GitHub repo name (validated by caller)
	 * @param string $title Issue title (3-200 chars, validated by caller)
	 * @param string $body Issue body (>= 10 chars, validated by caller)
	 * @param string|null $specRef Optional kebab-case capability slug
	 * @param string $userId Nextcloud UID of the submitting user
	 *
	 * @return array{number: int, html_url: string, state: string, specRef?: string, used_server_pat: bool}
	 *
	 * @throws \RuntimeException `github_pat_not_configured` when neither PAT is available
	 * @throws GuzzleException Network errors not covered by structured mapping
	 *
	 * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-2
	 * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-8
	 */
	public function createIssue(string $owner, string $repo, string $title, string $body, ?string $specRef, string $userId): array {
		$submission = new GitHubIssueSubmission(owner: $owner, repo: $repo, userId: $userId, specRef: $specRef);
		return $this->fileIssue(submission: $submission, text: new GitHubIssueText(title: $title, body: $body));
	}//end createIssue()

	/**
	 * Create a GitHub Issue for one submission, as createIssue() does.
	 *
	 * @param GitHubIssueSubmission $submission Where the issue goes, who submits it and its capability.
	 * @param GitHubIssueText       $text       The validated title and body.
	 *
	 * @return array{number: int, html_url: string, state: string, specRef?: string, used_server_pat: bool}
	 *
	 * @throws \RuntimeException `github_pat_not_configured` when neither PAT is available
	 * @throws GuzzleException Network errors not covered by structured mapping
	 *
	 * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-2
	 */
	public function fileIssue(GitHubIssueSubmission $submission, GitHubIssueText $text): array {
		$delivery = $this->resolveCreateIssueDelivery(submission: $submission);
		$effectiveBody = $this->buildIssueBody(delivery: $delivery, body: $text->body);
		$payload = self::withSpecRefLabel(payload: ['title' => $text->title, 'body' => $effectiveBody], specRef: $submission->specRef);

		return $this->dispatchCreateIssue(delivery: $delivery, payload: $payload);
	}//end fileIssue()

	/**
	 * The structured response shape of a created issue.
	 *
	 * @param array<string, mixed> $data     The decoded GitHub response.
	 * @param GitHubIssueDelivery  $delivery The submission and its credential.
	 *
	 * @return array{number: int, html_url: string, state: string, specRef?: string, used_server_pat: bool}
	 */
	private static function createdIssue(array $data, GitHubIssueDelivery $delivery): array {
		$result = [
			'number' => (int)($data['number'] ?? 0),
			'html_url' => (string)($data['html_url'] ?? ''),
			'state' => (string)($data['state'] ?? 'open'),
			'used_server_pat' => $delivery->useServerPat,
		];
		$specRef = $delivery->submission->specRef;
		if ($specRef !== null && $specRef !== '') {
			$result['specRef'] = $specRef;
		}

		return $result;
	}//end createdIssue()

	/**
	 * Resolve the PAT to use for a `createIssue` call: prefer the user's per-user token, fall
	 * back to the app-level PAT. The delivery says which one was taken, so the caller can branch
	 * on it for attribution + audit logging.
	 *
	 * @param GitHubIssueSubmission $submission Where the issue goes and who submits it.
	 *
	 * @return GitHubIssueDelivery The submission with a non-empty token and its server-PAT flag.
	 *
	 * @throws \RuntimeException `github_pat_not_configured` when neither PAT is available.
	 *
	 * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-2
	 */
	private function resolveCreateIssueDelivery(GitHubIssueSubmission $submission): GitHubIssueDelivery {
		$userToken = $this->getUserToken(userId: $submission->userId) ?? '';
		if ($userToken !== '') {
			return new GitHubIssueDelivery(submission: $submission, token: $userToken, useServerPat: false);
		}

		$serverToken = $this->appConfig->getValueString('openregister', 'github_api_token', '');
		if ($serverToken === '') {
			throw new RuntimeException('github_pat_not_configured');
		}

		return new GitHubIssueDelivery(submission: $submission, token: $serverToken, useServerPat: true);
	}//end resolveCreateIssueDelivery()

	/**
	 * The body sent to GitHub: attributed when the server PAT carries it,
	 * suffixed with the related capability when one is named.
	 *
	 * @param GitHubIssueDelivery $delivery The submission and its credential.
	 * @param string              $body     The body as the user wrote it.
	 *
	 * @return string The effective body.
	 */
	private function buildIssueBody(GitHubIssueDelivery $delivery, string $body): string {
		$effectiveBody = $body;
		if ($delivery->useServerPat === true) {
			$effectiveBody = $this->attributionFormatter->format(userId: $delivery->submission->userId) . $body;
		}

		$specRef = $delivery->submission->specRef;
		if ($specRef !== null && $specRef !== '') {
			$effectiveBody .= "\n\n---\nRelated capability: `" . $specRef . "`\n";
		}

		return $effectiveBody;
	}//end buildIssueBody()

	/**
	 * The issue payload, carrying the `specRef:<slug>` label when a capability is named.
	 *
	 * @param array{title: string, body: string} $payload The title and effective body.
	 * @param string|null                        $specRef The capability slug, or null.
	 *
	 * @return array<string, mixed> The payload.
	 */
	private static function withSpecRefLabel(array $payload, ?string $specRef): array {
		if ($specRef !== null && $specRef !== '') {
			$payload['labels'] = ['specRef:' . $specRef];
		}

		return $payload;
	}//end withSpecRefLabel()

	/**
	 * Send the issue, auditing a server-PAT submission whichever way it ends.
	 *
	 * @param GitHubIssueDelivery  $delivery The submission and its credential.
	 * @param array<string, mixed> $payload  The issue payload.
	 *
	 * @return array{number: int, html_url: string, state: string, specRef?: string, used_server_pat: bool}
	 *
	 * @throws Exception The request failure, after it was audited.
	 */
	private function dispatchCreateIssue(GitHubIssueDelivery $delivery, array $payload): array {
		$submission = $delivery->submission;
		try {
			$headers = self::bearerHeaders(token: $delivery->token) + ['Content-Type' => 'application/json'];
			$options = ['headers' => $headers, 'body' => json_encode($payload, JSON_THROW_ON_ERROR)];
			$response = $this->client->request('POST', $submission->issuesUrl(apiBase: self::API_BASE), $options);
		} catch (Exception $exception) {
			$failure = ['error_code' => $exception::class, 'github_status' => $this->extractGitHubStatus(exception: $exception)];
			$this->auditServerPatFailure(delivery: $delivery, failure: $failure);
			throw $exception;
		}

		$data = self::decodedIssue(response: $response);
		$this->auditServerPatSuccess(delivery: $delivery, issueNumber: (int)($data['number'] ?? 0));
		return self::createdIssue(data: $data, delivery: $delivery);
	}//end dispatchCreateIssue()

	/**
	 * The decoded Issues API response, or an empty array when it is not a JSON object.
	 *
	 * @param IResponse $response The response.
	 *
	 * @return array<string, mixed>
	 */
	private static function decodedIssue(IResponse $response): array {
		$data = json_decode((string)$response->getBody(), true);
		if (is_array($data) === false) {
			return [];
		}

		return $data;
	}//end decodedIssue()

	/**
	 * Audit a server-PAT submission that succeeded; a user-PAT one is not audited here.
	 *
	 * @param GitHubIssueDelivery $delivery    The submission and its credential.
	 * @param int                 $issueNumber The created issue's number.
	 *
	 * @return void
	 */
	private function auditServerPatSuccess(GitHubIssueDelivery $delivery, int $issueNumber): void {
		if ($delivery->useServerPat === false) {
			return;
		}

		$this->logger->info(
			message: '[GitHubHandler] Server-PAT submission succeeded',
			context: $this->serverPatContext(submission: $delivery->submission, issueNumber: $issueNumber)
		);
	}//end auditServerPatSuccess()

	/**
	 * Audit a server-PAT submission that failed; a user-PAT one is not audited here.
	 *
	 * @param GitHubIssueDelivery  $delivery The submission and its credential.
	 * @param array<string, mixed> $failure  `error_code` and `github_status`.
	 *
	 * @return void
	 */
	private function auditServerPatFailure(GitHubIssueDelivery $delivery, array $failure): void {
		if ($delivery->useServerPat === false) {
			return;
		}

		$this->logger->warning(
			message: '[GitHubHandler] Server-PAT submission failed',
			context: $this->serverPatContext(submission: $delivery->submission, issueNumber: 0) + $failure
		);
	}//end auditServerPatFailure()

	/**
	 * The audit context of a server-PAT submission.
	 *
	 * @param GitHubIssueSubmission $submission  Where the issue went and who submitted it.
	 * @param int                   $issueNumber The issue number, 0 when none was created.
	 *
	 * @return array<string, mixed> The context.
	 */
	private function serverPatContext(GitHubIssueSubmission $submission, int $issueNumber): array {
		$context = ['user_id' => $submission->userId, 'repo' => $submission->repoSlug(), 'issue_number' => $issueNumber];

		return $context + ['specref' => $submission->specRef ?? '', 'timestamp' => gmdate('c')];
	}//end serverPatContext()

	/**
	 * Extract GitHub's HTTP status from a Guzzle exception when available. Returns 0 when the
	 * exception is not a BadResponseException or carries no response.
	 *
	 * @param Exception $exception Exception raised by the client.
	 *
	 * @return int HTTP status, or 0 when unavailable.
	 */
	private function extractGitHubStatus(Exception $exception): int {
		// Use is_a() instead of importing BadResponseException — keeps the class's
		// coupling-between-objects count under the PHPMD threshold. The runtime check is
		// identical to instanceof.
		if (is_a($exception, 'GuzzleHttp\\Exception\\BadResponseException') === false) {
			return 0;
		}

		// The class is confirmed by the is_a() check above, so getResponse() is
		// safe — the method_exists() bail-out that used to sit here could never
		// be reached.

		// BadResponseException always carries a response (it is required by its constructor),
		// so getResponse() is non-null here.
		return $exception->getResponse()->getStatusCode();
	}//end extractGitHubStatus()

	/**
	 * Issue a single GitHub `/issues` request and return the stripped, PR-filtered item list.
	 *
	 * Sensitive fields are stripped per task 1.10 — only `number`, `title`, `body`, `html_url`,
	 * `user.login`, `user.avatar_url`, `reactions.{total_count, +1}`, `created_at`, `updated_at`,
	 * and `labels[].{name,color}` survive. GitHub returns pull requests in the issues endpoint
	 * with a `pull_request` field — those are filtered out.
	 *
	 * @param GitHubIssueQuery $query The listing
	 * @param string|null $label Single label filter or null
	 *
	 * @return array<int, array> Sanitized issue items
	 *
	 * @throws GuzzleException
	 *
	 * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-1
	 * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-10
	 */
	private function fetchIssuesPage(GitHubIssueQuery $query, ?string $label): array {
		$response = $this->client->request('GET', $query->url(apiBase: self::API_BASE), $query->requestOptions(label: $label));

		$raw = json_decode((string)$response->getBody(), true);
		if (is_array($raw) === false) {
			return [];
		}

		// Pull requests come back on the issues endpoint too; they are skipped.
		$issues = array_filter($raw, static fn (mixed $issue): bool => is_array($issue) === true && isset($issue['pull_request']) === false);
		return array_values(array_map(fn (array $issue): array => $this->stripIssueFields(issue: $issue), $issues));
	}//end fetchIssuesPage()

	/**
	 * Strip GitHub issue payload to the allowlisted fields per task 1.10.
	 *
	 * @param array $issue Raw issue payload from the GitHub API
	 *
	 * @return array Sanitized issue
	 *
	 * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-10
	 */
	private function stripIssueFields(array $issue): array {
		return [
			'number' => (int)($issue['number'] ?? 0),
			'title' => (string)($issue['title'] ?? ''),
			'body' => (string)($issue['body'] ?? ''),
			'html_url' => (string)($issue['html_url'] ?? ''),
			'user' => ['login' => (string)($issue['user']['login'] ?? ''), 'avatar_url' => (string)($issue['user']['avatar_url'] ?? '')],
			'reactions' => ['total_count' => (int)($issue['reactions']['total_count'] ?? 0), '+1' => (int)($issue['reactions']['+1'] ?? 0)],
			'created_at' => (string)($issue['created_at'] ?? ''),
			'updated_at' => (string)($issue['updated_at'] ?? ''),
			'labels' => self::issueLabels(labels: ($issue['labels'] ?? [])),
		];
	}//end stripIssueFields()

	/**
	 * An issue's labels, reduced to their name and color; anything that is not a label is dropped.
	 *
	 * @param iterable $labels The raw labels.
	 *
	 * @return array<int, array{name: string, color: string}>
	 */
	private static function issueLabels(iterable $labels): array {
		$stripped = [];
		foreach ($labels as $label) {
			if (is_array($label) === true) {
				$stripped[] = ['name' => (string)($label['name'] ?? ''), 'color' => (string)($label['color'] ?? '')];
			}
		}

		return $stripped;
	}//end issueLabels()
}//end class
