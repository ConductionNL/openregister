<?php

/**
 * OpenRegister GitHub Handler
 *
 * This file contains the GitHubHandler class for interacting with the GitHub API
 * to discover, fetch, and manage OpenRegister configurations stored in GitHub repositories.
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
use RuntimeException;
use OCP\Http\Client\IClient;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

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
class GitHubHandler
{
    /**
     * GitHub API base URL
     */
    private const API_BASE = 'https://api.github.com';

    /**
     * Rate limit for code search (per minute)
     */
    private const SEARCH_RATE_LIMIT = 30;

    /**
     * HTTP client for API requests
     *
     * @var IClient
     */
    private IClient $client;

    /**
     * Configuration service
     *
     * @var IAppConfig
     */
    private IAppConfig $appConfig;

    /**
     * Logger instance
     *
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * Cache instance for storing enriched configuration data
     *
     * @var ICache
     */
    private ICache $cache;

    /**
     * Configuration service for accessing app and user settings
     *
     * @var IConfig
     */
    private IConfig $config;

    /**
     * Attribution formatter for server-PAT-fallback issue submissions.
     *
     * @var AttributionFormatter
     */
    private AttributionFormatter $attributionFormatter;

    /**
     * GitHubHandler constructor
     *
     * @param IClient              $client               HTTP client (built by the DI factory in
     *                                                   Application.php via IClientService::newClient)
     * @param IAppConfig           $appConfig            App-level configuration service
     * @param IConfig              $config               Per-user configuration service
     * @param ICacheFactory        $cacheFactory         Cache factory
     * @param AttributionFormatter $attributionFormatter Server-PAT attribution helper
     * @param LoggerInterface      $logger               Logger instance
     */
    public function __construct(
        IClient $client,
        IAppConfig $appConfig,
        IConfig $config,
        ICacheFactory $cacheFactory,
        AttributionFormatter $attributionFormatter,
        LoggerInterface $logger
    ) {
        $this->client    = $client;
        $this->appConfig = $appConfig;
        $this->config    = $config;
        $this->cache     = $cacheFactory->createDistributed('openregister_github_configs');
        $this->attributionFormatter = $attributionFormatter;
        $this->logger = $logger;
    }//end __construct()

    /**
     * Get authentication headers for GitHub API
     *
     * @return array<string, string> GitHub API headers.
     *
     * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-29
     */
    private function getHeaders(): array
    {
        $headers = [
            'Accept'               => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => '2022-11-28',
        ];

        // Add authentication token if configured.
        $token = $this->appConfig->getValueString('openregister', 'github_api_token', '');
        if (empty($token) === false) {
            $headers['Authorization'] = 'Bearer '.$token;
            $this->logger->debug(
                message: '[GitHubHandler] Using GitHub API token for authentication',
                context: [
                    'file'         => __FILE__,
                    'line'         => __LINE__,
                    'token_length' => strlen($token),
                    'token_prefix' => substr($token, 0, 8).'...',
                ]
            );
        }

        if (empty($token) === true) {
            $this->logger->warning(
                message: '[GitHubHandler] No GitHub API token configured - unauthenticated access (60 requests/hour limit)',
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
        }

        return $headers;
    }//end getHeaders()

    /**
     * Search for OpenRegister configurations on GitHub
     *
     * Uses GitHub Code Search API to find JSON files containing x-openregister property
     *
     * @param string $search  Search terms to filter results (optional)
     * @param int    $page    Page number for pagination
     * @param int    $perPage Results per page (max 100)
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
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)  GitHub API search has many response conditions
     * @SuppressWarnings(PHPMD.NPathComplexity)       Search involves many conditional data extractions
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength) Full search implementation requires comprehensive handling
     *
     * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-29
     */
    public function searchConfigurations(string $search='', int $page=1, int $perPage=30): array
    {
        try {
            // Simplified single-phase search strategy to minimize API calls.
            // Search directly for x-openregister content in JSON files.
            // This targets actual config files immediately, avoiding multi-phase searches.
            $this->logger->info(
                message: '[GitHubHandler] Searching for OpenRegister configurations',
                context: [
                    'file'         => __FILE__,
                    'line'         => __LINE__,
                    'search_terms' => $search,
                    'page'         => $page,
                    'per_page'     => $perPage,
                ]
            );

            // Build search query targeting actual configuration files.
            // Search for "x-openregister" content in JSON files.
            // GitHub Code Search looks for exact text matches in file content.
            // We search for the exact property name that should appear in JSON files.
            $searchQuery = '"x-openregister" extension:json';
            if (empty($search) === false) {
                // If search term is provided, add it to the query.
                // GitHub Code Search supports searching in specific repos: repo:owner/repo.
                // Or we can add the search term as additional filter.
                $searchQuery .= ' '.$search;
            }

            $this->logger->debug(
                message: '[GitHubHandler] GitHub Code Search query',
                context: [
                    'file'     => __FILE__,
                    'line'     => __LINE__,
                    'query'    => $searchQuery,
                    'page'     => $page,
                    'per_page' => min($perPage, 100),
                ]
            );

            // Use GitHub pagination directly (max 100 per page, 1000 results total).
            // This uses only 1 Code Search API call per search request.
            $response = $this->client->request(
                'GET',
                self::API_BASE.'/search/code',
                [
                    'query'   => [
                        'q'        => $searchQuery,
                        'page'     => $page,
                        'per_page' => min($perPage, 100),
                // GitHub max is 100.
                        'sort'     => 'stars',
                // Sort by repository stars for quality.
                        'order'    => 'desc',
                    ],
                    'headers' => $this->getHeaders(),
                ]
            );

            $data = json_decode($response->getBody(), true);

            $this->logger->debug(
                message: '[GitHubHandler] GitHub Code Search response',
                context: [
                    'file'               => __FILE__,
                    'line'               => __LINE__,
                    'total_count'        => $data['total_count'] ?? 0,
                    'items_count'        => count($data['items'] ?? []),
                    'incomplete_results' => $data['incomplete_results'] ?? false,
                ]
            );
            $allResults = [];

            // Process and format results.
            foreach ($data['items'] ?? [] as $item) {
                $owner         = $item['repository']['owner']['login'];
                $repo          = $item['repository']['name'];
                $defaultBranch = $item['repository']['default_branch'] ?? 'main';
                $path          = $item['path'];
                $fileSha       = $item['sha'] ?? null;

                // Get enriched config details (from cache or by fetching from raw.githubusercontent.com).
                // This doesn't count against API rate limit.
                $configDetails = $this->getEnrichedConfigDetails(
                    owner: $owner,
                    repo: $repo,
                    path: $path,
                    branch: $defaultBranch,
                    fileSha: $fileSha
                );

                $allResults[] = [
                    'repository'   => $item['repository']['full_name'],
                    'owner'        => $owner,
                    'repo'         => $repo,
                    'path'         => $path,
                    'url'          => $item['html_url'],
                    'stars'        => $item['repository']['stargazers_count'] ?? 0,
                    'description'  => $item['repository']['description'] ?? '',
                    'name'         => basename($path, '.json'),
                    'branch'       => $defaultBranch,
                    'raw_url'      => "https://raw.githubusercontent.com/{$owner}/{$repo}/{$defaultBranch}/{$path}",
                    'sha'          => $fileSha,
                    // Repository owner/organization info.
                    'organization' => [
                        'name'       => $owner,
                        'avatar_url' => $item['repository']['owner']['avatar_url'] ?? '',
                        'type'       => $item['repository']['owner']['type'] ?? 'User',
                        'url'        => $item['repository']['owner']['html_url'] ?? '',
                    ],
                    // Config details - enriched from actual file content.
                    'config'       => $configDetails,
                ];
            }//end foreach

            $this->logger->info(
                message: '[GitHubHandler] Search complete',
                context: [
                    'file'             => __FILE__,
                    'line'             => __LINE__,
                    'total_found'      => $data['total_count'] ?? 0,
                    'returned_in_page' => count($allResults),
                    'api_calls_used'   => 1,
                // Only 1 Code Search API call.
                ]
            );

            return [
                'total_count' => $data['total_count'] ?? 0,
            // GitHub's total count.
                'results'     => $allResults,
                'page'        => $page,
                'per_page'    => $perPage,
            ];
        } catch (GuzzleException $e) {
            $errorMessage = $e->getMessage();
            $statusCode   = null;

            // Extract HTTP status code if available.
            if ($e instanceof RequestException && $e->hasResponse() === true) {
                $statusCode = $e->getResponse()->getStatusCode();
            }

            $this->logger->error(
                message: '[GitHubHandler] GitHub API search failed',
                context: [
                    'file'        => __FILE__,
                    'line'        => __LINE__,
                    'error'       => $errorMessage,
                    'status_code' => $statusCode,
                    '_search'     => $search ?? '',
                ]
            );

            // Provide user-friendly error messages based on status code.
            $userMessage = $this->getGitHubErrorMessage(statusCode: $statusCode, rawError: $errorMessage);
            throw new Exception($userMessage);
        }//end try
    }//end searchConfigurations()

    /**
     * Get user-friendly error message based on GitHub API error
     *
     * @param int|null $statusCode HTTP status code
     * @param string   $rawError   Raw error message
     *
     * @return string User-friendly error message
     *
     * @since 0.2.10
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Error handling requires multiple status code checks
     *
     * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-29
     */
    private function getGitHubErrorMessage(?int $statusCode, string $rawError): string
    {
        switch ($statusCode) {
            case 403:
                if (stripos($rawError, 'rate limit') !== false) {
                    $token = $this->appConfig->getValueString('openregister', 'github_api_token', '');
                    if (empty($token) === true) {
                        $message  = 'GitHub API rate limit exceeded (60 requests/hour for ';
                        $message .= 'unauthenticated). Please configure a GitHub API token in ';
                        $message .= 'Settings to increase to 5,000 requests/hour (30/minute for Code Search).';
                        return $message;
                    }

                    $message  = 'GitHub Code Search API rate limit exceeded (30 requests per ';
                    $message .= 'minute). Please wait a few minutes before trying again. The ';
                    $message .= 'discovery search makes multiple API calls to find configurations.';
                    return $message;
                }
                return 'Access forbidden. Please check your GitHub API token permissions in Settings.';

            case 401:
                $message  = 'GitHub API authentication failed. Please check your API token in ';
                $message .= 'Settings or remove it to use unauthenticated access (60 requests/hour limit).';
                return $message;

            case 404:
                return 'Repository or resource not found on GitHub. Please check the repository exists and is public.';

            case 422:
                return 'Invalid search query. Please try different search terms.';

            case 503:
            case 500:
                return 'GitHub API is temporarily unavailable. Please try again in a few minutes.';

            default:
                // Return a generic message but don't expose the full raw error.
                if (stripos($rawError, 'rate limit') !== false) {
                    $msg  = 'GitHub API rate limit exceeded. ';
                    $msg .= 'Please wait a few minutes or configure an API token in Settings.';
                    return $msg;
                }
                return 'GitHub API request failed. Please try again or check your API token configuration in Settings.';
        }//end switch
    }//end getGitHubErrorMessage()

    /**
     * Get enriched configuration details from cache or fetch if not cached
     *
     * Uses file SHA as cache key to automatically invalidate when file changes.
     * Falls back to fetching without caching if SHA is not available.
     *
     * @param string      $owner   Repository owner
     * @param string      $repo    Repository name
     * @param string      $path    File path
     * @param string      $branch  Branch name
     * @param string|null $fileSha File SHA for cache invalidation
     *
     * @return array Configuration details
     *
     * @since 0.2.11
     *
     * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-29
     */
    private function getEnrichedConfigDetails(
        string $owner,
        string $repo,
        string $path,
        string $branch,
        ?string $fileSha
    ): array {
        // Default fallback config.
        $fallbackConfig = [
            'title'       => basename($path, '.json'),
            'description' => '',
            'version'     => 'v.unknown',
            'app'         => null,
            'type'        => 'unknown',
        ];

        // If we have a SHA, use it as cache key.
        if ($fileSha !== null) {
            $cacheKey = "config_{$owner}_{$repo}_{$fileSha}";

            // Try to get from cache.
            $cached = $this->cache->get($cacheKey);
            if ($cached !== null) {
                $this->logger->debug(
                    message: '[GitHubHandler] Using cached config details',
                    context: [
                        'file'      => __FILE__,
                        'line'      => __LINE__,
                        'cache_key' => $cacheKey,
                    ]
                );
                return $cached;
            }
        }

        // Not in cache or no SHA available, fetch from GitHub.
        $enriched = $this->enrichConfigurationDetails(owner: $owner, repo: $repo, path: $path, branch: $branch);

        if ($enriched === null) {
            // Enrichment failed, return fallback.
            return $fallbackConfig;
        }

        // Cache the enriched data (if we have a SHA).
        if ($fileSha !== null) {
            $cacheKey = "config_{$owner}_{$repo}_{$fileSha}";
            // Cache for 7 days (file content won't change as long as SHA is the same).
            $this->cache->set($cacheKey, $enriched, 7 * 24 * 60 * 60);
            $this->logger->debug(
                message: '[GitHubHandler] Cached config details',
                context: [
                    'file'      => __FILE__,
                    'line'      => __LINE__,
                    'cache_key' => $cacheKey,
                ]
            );
        }

        return $enriched;
    }//end getEnrichedConfigDetails()

    /**
     * Enrich configuration metadata by fetching actual file contents
     *
     * Uses raw.githubusercontent.com (doesn't count against API rate limit!)
     *
     * @param string $owner  Repository owner
     * @param string $repo   Repository name
     * @param string $path   File path
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
     * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-29
     */
    public function enrichConfigurationDetails(string $owner, string $repo, string $path, string $branch='main'): array|null
    {
        try {
            // Use raw.githubusercontent.com - doesn't count against API rate limit.
            $rawUrl = "https://raw.githubusercontent.com/{$owner}/{$repo}/{$branch}/{$path}";

            $this->logger->debug(
                message: '[GitHubHandler] Enriching configuration details from raw URL',
                context: [
                    'file' => __FILE__,
                    'line' => __LINE__,
                    'url'  => $rawUrl,
                ]
            );

            $response = $this->client->request(
                'GET',
                $rawUrl,
                [
                    'headers' => [
                        'Accept' => 'application/json',
                    ],
                ]
            );

            $content = $response->getBody();
            $data    = json_decode($content, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->logger->warning(
                    message: '[GitHubHandler] Failed to parse configuration JSON',
                    context: [
                        'file'  => __FILE__,
                        'line'  => __LINE__,
                        'url'   => $rawUrl,
                        'error' => json_last_error_msg(),
                    ]
                );
                return null;
            }

            // Extract relevant metadata.
            return [
                'title'        => $data['info']['title'] ?? basename($path, '.json'),
                'description'  => $data['info']['description'] ?? '',
                'version'      => $data['info']['version'] ?? 'v.unknown',
                'app'          => $data['x-openregister']['app'] ?? null,
                'type'         => $data['x-openregister']['type'] ?? 'unknown',
                'openregister' => $data['x-openregister']['openregister'] ?? null,
            ];
        } catch (Exception $e) {
            $this->logger->warning(
                message: '[GitHubHandler] Failed to enrich configuration details',
                context: [
                    'file'  => __FILE__,
                    'line'  => __LINE__,
                    'owner' => $owner,
                    'repo'  => $repo,
                    'path'  => $path,
                    'error' => $e->getMessage(),
                ]
            );
            return null;
        }//end try
    }//end enrichConfigurationDetails()

    /**
     * Get list of branches for a repository
     *
     * @param string $owner Repository owner
     * @param string $repo  Repository name
     *
     * @return (false|mixed|null)[][] List of branches with name and commit info
     *
     * @throws \Exception If API request fails
     *
     * @since 0.2.10
     *
     * @psalm-return array<array{name: mixed, commit: mixed|null, protected: false|mixed}>
     *
     * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-29
     */
    public function getBranches(string $owner, string $repo): array
    {
        try {
            $this->logger->info(
                message: '[GitHubHandler] Fetching branches from GitHub',
                context: [
                    'file'  => __FILE__,
                    'line'  => __LINE__,
                    'owner' => $owner,
                    'repo'  => $repo,
                ]
            );

            $response = $this->client->request(
                'GET',
                self::API_BASE."/repos/{$owner}/{$repo}/branches",
                [
                    'headers' => $this->getHeaders(),
                ]
            );

            $branches = json_decode($response->getBody(), true);

            // Format branch data for return.
            return array_map(
                // Map branch data to standardized format.
                function (array $branch): array {
                    return [
                        'name'      => $branch['name'],
                        'commit'    => $branch['commit']['sha'] ?? null,
                        'protected' => $branch['protected'] ?? false,
                    ];
                },
                $branches
            );
        } catch (GuzzleException $e) {
            $this->logger->error(
                message: '[GitHubHandler] GitHub API get branches failed',
                context: [
                    'file'  => __FILE__,
                    'line'  => __LINE__,
                    'error' => $e->getMessage(),
                    'owner' => $owner,
                    'repo'  => $repo,
                ]
            );
            throw new Exception('Failed to fetch branches: '.$e->getMessage());
        }//end try
    }//end getBranches()

    /**
     * Get file content from a repository
     *
     * @param string $owner  Repository owner
     * @param string $repo   Repository name
     * @param string $path   File path in repository
     * @param string $branch Branch name (default: main)
     *
     * @return array Decoded JSON content
     * @throws \Exception If file cannot be fetched or parsed
     *
     * @since 0.2.10
     *
     * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-29
     */
    public function getFileContent(string $owner, string $repo, string $path, string $branch='main'): array
    {
        try {
            $this->logger->info(
                message: '[GitHubHandler] Fetching file from GitHub',
                context: [
                    'file'   => __FILE__,
                    'line'   => __LINE__,
                    'owner'  => $owner,
                    'repo'   => $repo,
                    'path'   => $path,
                    'branch' => $branch,
                ]
            );

            $response = $this->client->request(
                'GET',
                self::API_BASE."/repos/{$owner}/{$repo}/contents/{$path}",
                [
                    'query'   => ['ref' => $branch],
                    'headers' => $this->getHeaders(),
                ]
            );

            $data = json_decode($response->getBody(), true);

            // Decode base64 content.
            if (($data['content'] ?? null) !== null) {
                $content = base64_decode($data['content']);
                $json    = json_decode($content, true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new Exception('Invalid JSON in file: '.json_last_error_msg());
                }

                return $json;
            }

            throw new Exception('No content found in file');
        } catch (GuzzleException $e) {
            $this->logger->error(
                message: '[GitHubHandler] GitHub API get file content failed',
                context: [
                    'file'   => __FILE__,
                    'line'   => __LINE__,
                    'error'  => $e->getMessage(),
                    'owner'  => $owner,
                    'repo'   => $repo,
                    'path'   => $path,
                    'branch' => $branch,
                ]
            );
            throw new Exception('Failed to fetch file: '.$e->getMessage());
        }//end try
    }//end getFileContent()

    /**
     * List OpenRegister configuration files in a repository
     *
     * Searches for files matching naming conventions:
     * - openregister.json
     * - *.openregister.json
     * - Files containing x-openregister property
     *
     * @param string $owner  Repository owner
     * @param string $repo   Repository name
     * @param string $branch Branch name (default: main)
     * @param string $path   Directory path to search (default: root)
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
     * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-29
     */
    public function listConfigurationFiles(string $owner, string $repo, string $branch='main', string $path=''): array
    {
        try {
            $this->logger->info(
                message: '[GitHubHandler] Listing configuration files from GitHub',
                context: [
                    'file'   => __FILE__,
                    'line'   => __LINE__,
                    'owner'  => $owner,
                    'repo'   => $repo,
                    'branch' => $branch,
                    'path'   => $path,
                ]
            );

            // Search in the repository for configuration files.
            $searchQuery = "repo:{$owner}/{$repo} filename:openregister.json OR filename:*.openregister.json extension:json";

            $response = $this->client->request(
                'GET',
                self::API_BASE.'/search/code',
                [
                    'query'   => [
                        'q' => $searchQuery,
                    ],
                    'headers' => $this->getHeaders(),
                ]
            );

            $data = json_decode($response->getBody(), true);

            $files = [];
            foreach ($data['items'] ?? [] as $item) {
                $configData = $this->parseConfigurationFile(
                    owner: $owner,
                    repo: $repo,
                    path: $item['path'],
                    branch: $branch
                );

                if ($configData !== null) {
                    $info    = $configData['info'] ?? [];
                    $xReg    = $configData['x-openregister'] ?? [];
                    $files[] = [
                        'path'   => $item['path'],
                        'sha'    => $item['sha'] ?? null,
                        'url'    => $item['html_url'] ?? null,
                        'config' => [
                            'title'       => $info['title'] ?? $xReg['title'] ?? basename($item['path']),
                            'description' => $info['description'] ?? $xReg['description'] ?? '',
                            'version'     => $info['version'] ?? $xReg['version'] ?? '1.0.0',
                            'app'         => $xReg['app'] ?? null,
                            'type'        => $xReg['type'] ?? 'manual',
                        ],
                    ];
                }
            }//end foreach

            return $files;
        } catch (GuzzleException $e) {
            $this->logger->error(
                message: '[GitHubHandler] GitHub API list files failed',
                context: [
                    'file'   => __FILE__,
                    'line'   => __LINE__,
                    'error'  => $e->getMessage(),
                    'owner'  => $owner,
                    'repo'   => $repo,
                    'branch' => $branch,
                ]
            );
            throw new Exception('Failed to list configuration files: '.$e->getMessage());
        }//end try
    }//end listConfigurationFiles()

    /**
     * Parse and validate a configuration file
     *
     * @param string $owner  Repository owner
     * @param string $repo   Repository name
     * @param string $path   File path
     * @param string $branch Branch name (default: main)
     *
     * @return array|null Parsed configuration or null if invalid
     *
     * @since 0.2.10
     *
     * @psalm-return array{openapi: mixed, 'x-openregister': mixed,...}|null
     *
     * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-29
     */
    private function parseConfigurationFile(string $owner, string $repo, string $path, string $branch='main'): array|null
    {
        try {
            $content = $this->getFileContent(owner: $owner, repo: $repo, path: $path, branch: $branch);

            // Validate that it's a valid OpenRegister configuration.
            if (isset($content['openapi']) === false || isset($content['x-openregister']) === false) {
                $this->logger->debug(
                    message: '[GitHubHandler] File does not contain required OpenRegister structure',
                    context: [
                        'file' => __FILE__,
                        'line' => __LINE__,
                        'path' => $path,
                    ]
                );
                return null;
            }

            return $content;
        } catch (Exception $e) {
            $this->logger->debug(
                message: '[GitHubHandler] Failed to parse configuration file',
                context: [
                    'file'  => __FILE__,
                    'line'  => __LINE__,
                    'path'  => $path,
                    'error' => $e->getMessage(),
                ]
            );
            return null;
        }//end try
    }//end parseConfigurationFile()

    /**
     * Get repositories that the authenticated user has access to
     *
     * @param int $page    Page number (default: 1)
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
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Repository fetch has multiple auth and error conditions
     * @SuppressWarnings(PHPMD.NPathComplexity)      Auth check and error handling create multiple paths
     *
     * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-29
     */
    public function getRepositories(int $page=1, int $perPage=100): array
    {
        // Check if GitHub API token is configured.
        $token = $this->appConfig->getValueString('openregister', 'github_api_token', '');
        if (empty($token) === true) {
            $this->logger->info(
                message: '[GitHubHandler] GitHub API token not configured - returning empty repositories list',
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
            return [];
        }

        try {
            $this->logger->info(
                message: '[GitHubHandler] Fetching repositories from GitHub',
                context: [
                    'file'     => __FILE__,
                    'line'     => __LINE__,
                    'page'     => $page,
                    'per_page' => $perPage,
                ]
            );

            $response = $this->client->request(
                'GET',
                self::API_BASE.'/user/repos',
                [
                    'query'   => [
                        'type'      => 'all',
                // All, owner, member.
                        'sort'      => 'updated',
                        'direction' => 'desc',
                        'page'      => $page,
                        'per_page'  => min($perPage, 100),
                // GitHub max is 100.
                    ],
                    'headers' => $this->getHeaders(),
                ]
            );

            $repos = json_decode($response->getBody(), true);

            // Format repository data for return.
            return array_map(
                // Map repository data to standardized format.
                function (array $repo): array {
                    return [
                        'id'             => $repo['id'],
                        'name'           => $repo['name'],
                        'full_name'      => $repo['full_name'],
                        'owner'          => $repo['owner']['login'],
                        'owner_type'     => $repo['owner']['type'],
                    // User or Organization.
                        'private'        => $repo['private'],
                        'description'    => $repo['description'] ?? '',
                        'default_branch' => $repo['default_branch'] ?? 'main',
                        'url'            => $repo['html_url'],
                        'api_url'        => $repo['url'],
                    ];
                },
                $repos
            );
        } catch (GuzzleException $e) {
            $statusCode = null;
            if ($e instanceof RequestException && $e->hasResponse() === true) {
                $statusCode = $e->getResponse()->getStatusCode();
            }

            // If authentication failed (401) or token not configured, return empty array instead of error.
            if ($statusCode === 401 || empty($token) === true) {
                $this->logger->info(
                    message: '[GitHubHandler] GitHub API auth failed or not configured - returning empty list',
                    context: [
                        'file'        => __FILE__,
                        'line'        => __LINE__,
                        'status_code' => $statusCode,
                        'has_token'   => (empty($token) === false),
                    ]
                );
                return [];
            }

            $this->logger->error(
                message: '[GitHubHandler] GitHub API get repositories failed',
                context: [
                    'file'        => __FILE__,
                    'line'        => __LINE__,
                    'error'       => $e->getMessage(),
                    'status_code' => $statusCode,
                ]
            );
            throw new Exception('Failed to fetch repositories: '.$e->getMessage());
        }//end try
    }//end getRepositories()

    /**
     * Get repository information including default branch
     *
     * @param string $owner Repository owner
     * @param string $repo  Repository name
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
     * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-29
     */
    public function getRepositoryInfo(string $owner, string $repo): array
    {
        try {
            $response = $this->client->request(
                'GET',
                self::API_BASE."/repos/{$owner}/{$repo}",
                [
                    'headers' => $this->getHeaders(),
                ]
            );

            $repoData = json_decode($response->getBody(), true);

            return [
                'id'             => $repoData['id'] ?? null,
                'name'           => $repoData['name'] ?? $repo,
                'full_name'      => $repoData['full_name'] ?? "{$owner}/{$repo}",
                'owner'          => $repoData['owner']['login'] ?? $owner,
                'private'        => $repoData['private'] ?? false,
                'description'    => $repoData['description'] ?? '',
                'default_branch' => $repoData['default_branch'] ?? 'main',
                'url'            => $repoData['html_url'] ?? '',
            ];
        } catch (GuzzleException $e) {
            $this->logger->error(
                message: '[GitHubHandler] GitHub API get repository info failed',
                context: [
                    'file'  => __FILE__,
                    'line'  => __LINE__,
                    'error' => $e->getMessage(),
                    'owner' => $owner,
                    'repo'  => $repo,
                ]
            );
            throw new Exception('Failed to fetch repository info: '.$e->getMessage());
        }//end try
    }//end getRepositoryInfo()

    /**
     * Publish a configuration file to GitHub
     *
     * Creates or updates a file in the specified repository, branch, and path.
     * Uses the GitHub Contents API which requires the file SHA for updates.
     *
     * @param string      $owner         Repository owner
     * @param string      $repo          Repository name
     * @param string      $path          File path in repository (e.g., 'lib/Settings/config.json')
     * @param string      $branch        Branch name (default: main)
     * @param string      $content       File content (JSON string)
     * @param string      $commitMessage Commit message
     * @param string|null $fileSha       SHA of existing file (required for updates, null for new files)
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
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)  Complex error handling for GitHub API responses
     * @SuppressWarnings(PHPMD.NPathComplexity)       Publish involves multiple error and success paths
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength) Full publish handling requires comprehensive error logic
     *
     * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-29
     */
    public function publishConfiguration(
        string $owner,
        string $repo,
        string $path,
        string $branch,
        string $content,
        string $commitMessage,
        ?string $fileSha=null
    ): array {
        try {
            $this->logger->info(
                message: '[GitHubHandler] Publishing configuration to GitHub',
                context: [
                    'file'      => __FILE__,
                    'line'      => __LINE__,
                    'owner'     => $owner,
                    'repo'      => $repo,
                    'path'      => $path,
                    'branch'    => $branch,
                    'is_update' => $fileSha !== null,
                ]
            );

            // Base64 encode the content (GitHub API requires base64).
            $encodedContent = base64_encode($content);

            $payload = [
                'message' => $commitMessage,
                'content' => $encodedContent,
                'branch'  => $branch,
            ];

            // If updating existing file, include SHA.
            if ($fileSha !== null) {
                $payload['sha'] = $fileSha;
            }

            // URL encode the path for the GitHub API (path may contain slashes, spaces, etc.).
            // GitHub API expects path segments to be encoded, but slashes should remain as slashes.
            // So we encode each segment separately.
            $pathSegments        = explode('/', $path);
            $encodedPathSegments = array_map('rawurlencode', $pathSegments);
            $encodedPath         = implode('/', $encodedPathSegments);

            $apiUrl = self::API_BASE."/repos/{$owner}/{$repo}/contents/{$encodedPath}";

            $this->logger->debug(
                message: '[GitHubHandler] GitHub API publish request',
                context: [
                    'file'         => __FILE__,
                    'line'         => __LINE__,
                    'url'          => $apiUrl,
                    'path'         => $path,
                    'encoded_path' => $encodedPath,
                    'branch'       => $branch,
                ]
            );

            $response = $this->client->request(
                'PUT',
                $apiUrl,
                [
                    'headers' => $this->getHeaders(),
                    'json'    => $payload,
                ]
            );

            $result = json_decode($response->getBody(), true);

            $this->logger->info(
                message: '[GitHubHandler] Configuration published successfully',
                context: [
                    'file'       => __FILE__,
                    'line'       => __LINE__,
                    'commit_sha' => $result['commit']['sha'] ?? null,
                    'file_sha'   => $result['content']['sha'] ?? null,
                ]
            );

            return [
                'success'    => true,
                'commit_sha' => $result['commit']['sha'] ?? null,
                'file_sha'   => $result['content']['sha'] ?? null,
                'commit_url' => $result['commit']['html_url'] ?? null,
                'file_url'   => $result['content']['html_url'] ?? null,
            ];
        } catch (GuzzleException $e) {
            $errorMessage = $e->getMessage();
            $statusCode   = null;

            if ($e instanceof RequestException && $e->hasResponse() === true) {
                $statusCode   = $e->getResponse()->getStatusCode();
                $responseBody = $e->getResponse()->getBody()->getContents();
                $errorData    = json_decode($responseBody, true);

                if (($errorData['message'] ?? null) !== null) {
                    $errorMessage = $errorData['message'];

                    // Provide more context for common errors.
                    if ($statusCode === 404) {
                        $repoPath      = "{$owner}/{$repo}";
                        $errorMessage  = "Not Found - Repository '{$repoPath}', branch ";
                        $errorMessage .= "'{$branch}', or path '{$path}' may not exist or you may not have access";
                    } else if ($statusCode === 403) {
                        $repoPath      = "{$owner}/{$repo}";
                        $errorMessage  = "Forbidden - You may not have write access to repository ";
                        $errorMessage .= "'{$repoPath}' or the branch '{$branch}' is protected";
                    } else if ($statusCode === 422) {
                        $errorMessage  = "Validation Error - {$errorMessage}. Check that the branch ";
                        $errorMessage .= "'{$branch}' exists and the path '{$path}' is valid";
                    }
                }
            }//end if

            $this->logger->error(
                message: '[GitHubHandler] GitHub API publish failed',
                context: [
                    'file'        => __FILE__,
                    'line'        => __LINE__,
                    'error'       => $errorMessage,
                    'status_code' => $statusCode,
                    'owner'       => $owner,
                    'repo'        => $repo,
                    'path'        => $path,
                    'branch'      => $branch,
                ]
            );

            throw new Exception('Failed to publish configuration: '.$errorMessage);
        }//end try
    }//end publishConfiguration()

    /**
     * Get file SHA for a specific file (needed for updates)
     *
     * @param string $owner  Repository owner
     * @param string $repo   Repository name
     * @param string $path   File path in repository
     * @param string $branch Branch name (default: main)
     *
     * @return string|null File SHA or null if file doesn't exist
     * @throws \Exception If API request fails
     *
     * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-29
     */
    public function getFileSha(string $owner, string $repo, string $path, string $branch='main'): ?string
    {
        try {
            // URL encode the path for the GitHub API (path may contain slashes, spaces, etc.).
            $pathSegments        = explode('/', $path);
            $encodedPathSegments = array_map('rawurlencode', $pathSegments);
            $encodedPath         = implode('/', $encodedPathSegments);

            $response = $this->client->request(
                'GET',
                self::API_BASE."/repos/{$owner}/{$repo}/contents/{$encodedPath}",
                [
                    'query'   => [
                        'ref' => $branch,
                    ],
                    'headers' => $this->getHeaders(),
                ]
            );

            $fileInfo = json_decode($response->getBody(), true);
            return $fileInfo['sha'] ?? null;
        } catch (GuzzleException $e) {
            // File doesn't exist or other error.
            if ($e instanceof RequestException && $e->hasResponse() === true && $e->getResponse()->getStatusCode() === 404) {
                return null;
                // File doesn't exist, which is fine for new files.
            }

            $this->logger->error(
                message: '[GitHubHandler] GitHub API get file SHA failed',
                context: [
                    'file'  => __FILE__,
                    'line'  => __LINE__,
                    'error' => $e->getMessage(),
                    'owner' => $owner,
                    'repo'  => $repo,
                    'path'  => $path,
                ]
            );
            throw new Exception('Failed to get file SHA: '.$e->getMessage());
        }//end try
    }//end getFileSha()

    /**
     * Get user-specific GitHub token
     *
     * @param string $userId The user ID
     *
     * @return null|string The token or null if not set
     *
     * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-29
     */
    public function getUserToken(string $userId): string|null
    {
        $token = $this->config->getUserValue($userId, 'openregister', 'github_token', '');
        if ($token !== '') {
            return $token;
        }

        return null;
    }//end getUserToken()

    /**
     * Set user-specific GitHub token
     *
     * @param string|null $token  The token to set, or null to clear
     * @param string      $userId The user ID
     *
     * @return void
     *
     * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-29
     */
    public function setUserToken(?string $token, string $userId): void
    {
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
     * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-29
     */
    public function validateToken(?string $userId=null): bool
    {
        try {
            // Get user token if userId provided, otherwise use app-level token.
            $token = $this->appConfig->getValueString('openregister', 'github_api_token', '');
            if ($userId !== null) {
                $token = $this->getUserToken(userId: $userId);
            }

            if ($token === null || $token === '') {
                return false;
            }

            // Make a simple API request to validate the token.
            $headers = [
                'Accept'               => 'application/vnd.github+json',
                'X-GitHub-Api-Version' => '2022-11-28',
                'Authorization'        => 'Bearer '.$token,
            ];

            $response = $this->client->request(
                'GET',
                self::API_BASE.'/user',
                ['headers' => $headers]
            );

            return $response->getStatusCode() === 200;
        } catch (Exception $e) {
            $this->logger->error(
                message: '[GitHubHandler] GitHub token validation failed',
                context: [
                    'file'  => __FILE__,
                    'line'  => __LINE__,
                    'error' => $e->getMessage(),
                ]
            );
            return false;
        }//end try
    }//end validateToken()

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
     * @param string             $owner   GitHub repo owner (validated by caller)
     * @param string             $repo    GitHub repo name (validated by caller)
     * @param string             $state   Issue state filter (`open`, `closed`, or `all`)
     * @param string             $sort    Sort key (`reactions-+1` is the spec default)
     * @param int                $perPage Page size (1-100)
     * @param array<string>|null $labels  Optional label filter; null = no filter, 1+ = OR-merge
     *
     * @return array{items: array, hint?: string, github_status?: int, reset_at?: string}
     *
     * @throws GuzzleException When the underlying HTTP client errors in a way not covered by the
     *                        rate-limit / 4xx / 5xx mapping (e.g. DNS failure).
     *
     * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-1
     * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-10
     */
    public function listIssues(
        string $owner,
        string $repo,
        string $state='open',
        string $sort='reactions-+1',
        int $perPage=30,
        ?array $labels=null
    ): array {
        $token = $this->appConfig->getValueString('openregister', 'github_api_token', '');
        if ($token === '') {
            return ['items' => [], 'hint' => 'github_pat_not_configured'];
        }

        $effectiveLabels = is_array($labels) === true ? array_values(array_filter($labels, static fn($l) => $l !== '')) : [];
        $labelCount      = count($effectiveLabels);

        if ($labelCount >= 2) {
            // OR semantics: one request per label, merge deduped by issue number, re-sort, truncate.
            $merged = [];
            foreach ($effectiveLabels as $label) {
                $page = $this->fetchIssuesPage(
                    owner: $owner,
                    repo: $repo,
                    state: $state,
                    sort: $sort,
                    perPage: $perPage,
                    label: $label,
                    token: $token
                );
                foreach ($page as $item) {
                    $number = (int) ($item['number'] ?? 0);
                    if ($number > 0 && isset($merged[$number]) === false) {
                        $merged[$number] = $item;
                    }
                }
            }

            $items = array_values($merged);
            usort(
                $items,
                static function (array $a, array $b): int {
                    $aCount = (int) ($a['reactions']['total_count'] ?? 0);
                    $bCount = (int) ($b['reactions']['total_count'] ?? 0);
                    return $bCount <=> $aCount;
                }
            );
            $items = array_slice($items, 0, $perPage);
            return ['items' => $items];
        }//end if

        $singleLabel = $labelCount === 1 ? $effectiveLabels[0] : null;
        $items       = $this->fetchIssuesPage(
            owner: $owner,
            repo: $repo,
            state: $state,
            sort: $sort,
            perPage: $perPage,
            label: $singleLabel,
            token: $token
        );
        return ['items' => $items];
    }//end listIssues()

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
     * @param string      $owner   GitHub repo owner (validated by caller)
     * @param string      $repo    GitHub repo name (validated by caller)
     * @param string      $title   Issue title (3-200 chars, validated by caller)
     * @param string      $body    Issue body (>= 10 chars, validated by caller)
     * @param string|null $specRef Optional kebab-case capability slug
     * @param string      $userId  Nextcloud UID of the submitting user
     *
     * @return array{number: int, html_url: string, state: string, specRef?: string, used_server_pat: bool}
     *
     * @throws \RuntimeException     `github_pat_not_configured` when neither PAT is available
     * @throws GuzzleException       Network errors not covered by structured mapping
     *
     * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-2
     * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-8
     */
    public function createIssue(
        string $owner,
        string $repo,
        string $title,
        string $body,
        ?string $specRef,
        string $userId
    ): array {
        $userToken     = $this->getUserToken(userId: $userId) ?? '';
        $useServerPat  = $userToken === '';
        $token         = $userToken;
        $effectiveBody = $body;

        if ($useServerPat === true) {
            $token = $this->appConfig->getValueString('openregister', 'github_api_token', '');
            if ($token === '') {
                throw new RuntimeException('github_pat_not_configured');
            }

            $effectiveBody = $this->attributionFormatter->format($userId).$body;
        }

        // Append specRef suffix when provided (task 1.8). Slug-format validation happens in the controller (task 1.16).
        $labels = [];
        if ($specRef !== null && $specRef !== '') {
            $effectiveBody .= "\n\n---\nRelated capability: `".$specRef."`\n";
            $labels[]       = 'specRef:'.$specRef;
        }

        $payload = ['title' => $title, 'body' => $effectiveBody];
        if (empty($labels) === false) {
            $payload['labels'] = $labels;
        }

        $url      = self::API_BASE.'/repos/'.rawurlencode($owner).'/'.rawurlencode($repo).'/issues';
        $response = $this->client->request(
            'POST',
            $url,
            [
                'headers' => [
                    'Accept'               => 'application/vnd.github+json',
                    'X-GitHub-Api-Version' => '2022-11-28',
                    'Authorization'        => 'Bearer '.$token,
                    'Content-Type'         => 'application/json',
                ],
                'body'    => json_encode($payload, JSON_THROW_ON_ERROR),
            ]
        );

        $data   = json_decode((string) $response->getBody(), true);
        $result = [
            'number'          => (int) ($data['number'] ?? 0),
            'html_url'        => (string) ($data['html_url'] ?? ''),
            'state'           => (string) ($data['state'] ?? 'open'),
            'used_server_pat' => $useServerPat,
        ];
        if ($specRef !== null && $specRef !== '') {
            $result['specRef'] = $specRef;
        }

        return $result;
    }//end createIssue()

    /**
     * Issue a single GitHub `/issues` request and return the stripped, PR-filtered item list.
     *
     * Sensitive fields are stripped per task 1.10 — only `number`, `title`, `body`, `html_url`,
     * `user.login`, `user.avatar_url`, `reactions.{total_count, +1}`, `created_at`, `updated_at`,
     * and `labels[].{name,color}` survive. GitHub returns pull requests in the issues endpoint
     * with a `pull_request` field — those are filtered out.
     *
     * @param string      $owner   Validated owner
     * @param string      $repo    Validated repo
     * @param string      $state   Issue state
     * @param string      $sort    Sort key
     * @param int         $perPage Page size
     * @param string|null $label   Single label filter or null
     * @param string      $token   App-level PAT (verified non-empty by caller)
     *
     * @return array<int, array> Sanitized issue items
     *
     * @throws GuzzleException
     *
     * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-1
     * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-10
     */
    private function fetchIssuesPage(
        string $owner,
        string $repo,
        string $state,
        string $sort,
        int $perPage,
        ?string $label,
        string $token
    ): array {
        $query = [
            'state'     => $state,
            'sort'      => $sort,
            'direction' => 'desc',
            'per_page'  => $perPage,
        ];
        if ($label !== null && $label !== '') {
            $query['labels'] = $label;
        }

        $url      = self::API_BASE.'/repos/'.rawurlencode($owner).'/'.rawurlencode($repo).'/issues';
        $response = $this->client->request(
            'GET',
            $url,
            [
                'query'   => $query,
                'headers' => [
                    'Accept'               => 'application/vnd.github+json',
                    'X-GitHub-Api-Version' => '2022-11-28',
                    'Authorization'        => 'Bearer '.$token,
                ],
            ]
        );

        $raw   = json_decode((string) $response->getBody(), true);
        $items = [];
        if (is_array($raw) === true) {
            foreach ($raw as $issue) {
                if (is_array($issue) === false || isset($issue['pull_request']) === true) {
                    // Skip pull requests (GitHub returns them on the issues endpoint).
                    continue;
                }

                $items[] = $this->stripIssueFields(issue: $issue);
            }
        }

        return $items;
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
    private function stripIssueFields(array $issue): array
    {
        $labels = [];
        foreach (($issue['labels'] ?? []) as $label) {
            if (is_array($label) === true) {
                $labels[] = [
                    'name'  => (string) ($label['name'] ?? ''),
                    'color' => (string) ($label['color'] ?? ''),
                ];
            }
        }

        return [
            'number'     => (int) ($issue['number'] ?? 0),
            'title'      => (string) ($issue['title'] ?? ''),
            'body'       => (string) ($issue['body'] ?? ''),
            'html_url'   => (string) ($issue['html_url'] ?? ''),
            'user'       => [
                'login'      => (string) ($issue['user']['login'] ?? ''),
                'avatar_url' => (string) ($issue['user']['avatar_url'] ?? ''),
            ],
            'reactions'  => [
                'total_count' => (int) ($issue['reactions']['total_count'] ?? 0),
                '+1'          => (int) ($issue['reactions']['+1'] ?? 0),
            ],
            'created_at' => (string) ($issue['created_at'] ?? ''),
            'updated_at' => (string) ($issue['updated_at'] ?? ''),
            'labels'     => $labels,
        ];
    }//end stripIssueFields()
}//end class
