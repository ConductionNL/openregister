<?php

declare(strict_types=1);

/**
 * OpenRegister GitHub Service
 *
 * This file contains the GitHubService class for interacting with the GitHub API
 * to discover, fetch, and manage OpenRegister configurations stored in GitHub repositories.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author   Conduction Development Team <info@conduction.nl>
 * @copyright 2025 Conduction B.V.
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version  GIT: <git_id>
 *
 * @link     https://www.OpenRegister.nl
 */

namespace OCA\OpenRegister\Service;

use OCP\Http\Client\IClient;
use GuzzleHttp\Exception\GuzzleException;
use OCP\IConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;

/**
 * Service for GitHub API operations
 *
 * Provides methods for:
 * - Searching for OpenRegister configurations across GitHub
 * - Fetching file contents from repositories
 * - Listing branches
 * - Parsing and validating configuration files
 *
 * @package OCA\OpenRegister\Service
 */
class GitHubService
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
     * @var IConfig
     */
    private IConfig $config;

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
     * GitHubService constructor
     *
     * @param IClient         $client       HTTP client
     * @param IConfig         $config       Configuration service
     * @param ICacheFactory   $cacheFactory Cache factory for creating cache instances
     * @param LoggerInterface $logger       Logger instance
     */
    public function __construct(
        IClient $client,
        IConfig $config,
        ICacheFactory $cacheFactory,
        LoggerInterface $logger
    ) {
        $this->client = $client;
        $this->config = $config;
        $this->cache = $cacheFactory->createDistributed('openregister_github_configs');
        $this->logger = $logger;
    }

    /**
     * Get authentication headers for GitHub API
     *
     * @return array Headers array
     */
    private function getHeaders(): array
    {
        $headers = [
            'Accept' => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => '2022-11-28',
        ];

        // Add authentication token if configured
        $token = $this->config->getAppValue('openregister', 'github_api_token', '');
        if (!empty($token)) {
            $headers['Authorization'] = 'Bearer ' . $token;
            $this->logger->debug('Using GitHub API token for authentication', [
                'token_length' => strlen($token),
                'token_prefix' => substr($token, 0, 8) . '...',
            ]);
        } else {
            $this->logger->warning('No GitHub API token configured - using unauthenticated access (60 requests/hour limit)');
        }

        return $headers;
    }

    /**
     * Search for OpenRegister configurations on GitHub
     *
     * Uses GitHub Code Search API to find JSON files containing x-openregister property
     *
     * @param string $search  Search terms to filter results (optional)
     * @param int    $page   Page number for pagination
     * @param int    $perPage Results per page (max 100)
     *
     * @return array Search results with repository info and file details
     * @throws \Exception If API request fails
     *
     * @since 0.2.10
     */
    public function searchConfigurations(string $search = '', int $page = 1, int $perPage = 30): array
    {
        try {
            // Simplified single-phase search strategy to minimize API calls
            // Search directly for x-openregister content in JSON files
            // This targets actual config files immediately, avoiding multi-phase searches
            
            $this->logger->info('Searching for OpenRegister configurations', [
                'search_terms' => $search,
                'page' => $page,
                'per_page' => $perPage,
            ]);
            
            // Build search query targeting actual configuration files
            // Search for "x-openregister" content in JSON files only
            $searchQuery = 'x-openregister extension:json';
            if (!empty($search)) {
                $searchQuery .= ' ' . $search;
            }
            
            // Use GitHub pagination directly (max 100 per page, 1000 results total)
            // This uses only 1 Code Search API call per search request
            $response = $this->client->request('GET', self::API_BASE . '/search/code', [
                'query' => [
                    'q'        => $searchQuery,
                    'page'     => $page,
                    'per_page' => min($perPage, 100), // GitHub max is 100
                    'sort'     => 'stars', // Sort by repository stars for quality
                    'order'    => 'desc',
                ],
                'headers' => $this->getHeaders(),
            ]);
            
            $data = json_decode($response->getBody(), true);
            $allResults = [];
            
            // Process and format results
            foreach ($data['items'] ?? [] as $item) {
                $owner = $item['repository']['owner']['login'];
                $repo = $item['repository']['name'];
                $defaultBranch = $item['repository']['default_branch'] ?? 'main';
                $path = $item['path'];
                $fileSha = $item['sha'] ?? null;
                
                // Get enriched config details (from cache or by fetching from raw.githubusercontent.com)
                // This doesn't count against API rate limit
                $configDetails = $this->getEnrichedConfigDetails($owner, $repo, $path, $defaultBranch, $fileSha);
                
                $allResults[] = [
                    'repository'  => $item['repository']['full_name'],
                    'owner'       => $owner,
                    'repo'        => $repo,
                    'path'        => $path,
                    'url'         => $item['html_url'],
                    'stars'       => $item['repository']['stargazers_count'] ?? 0,
                    'description' => $item['repository']['description'] ?? '',
                    'name'        => basename($path, '.json'),
                    'branch'      => $defaultBranch,
                    'raw_url'     => "https://raw.githubusercontent.com/{$owner}/{$repo}/{$defaultBranch}/{$path}",
                    'sha'         => $fileSha,
                    // Repository owner/organization info
                    'organization' => [
                        'name'        => $owner,
                        'avatar_url'  => $item['repository']['owner']['avatar_url'] ?? '',
                        'type'        => $item['repository']['owner']['type'] ?? 'User',
                        'url'         => $item['repository']['owner']['html_url'] ?? '',
                    ],
                    // Config details - enriched from actual file content
                    'config'      => $configDetails,
                ];
            }
            
            $this->logger->info('Search complete', [
                'total_found' => $data['total_count'] ?? 0,
                'returned_in_page' => count($allResults),
                'api_calls_used' => 1, // Only 1 Code Search API call
            ]);

            return [
                'total_count' => $data['total_count'] ?? 0, // GitHub's total count
                'results'     => $allResults,
                'page'        => $page,
                'per_page'    => $perPage,
            ];
        } catch (GuzzleException $e) {
            $errorMessage = $e->getMessage();
            $statusCode = null;
            
            // Extract HTTP status code if available
            if ($e->hasResponse()) {
                $statusCode = $e->getResponse()->getStatusCode();
            }
            
            $this->logger->error('GitHub API search failed', [
                'error' => $errorMessage,
                'status_code' => $statusCode,
                '_search' => $search ?? '',
            ]);
            
            // Provide user-friendly error messages based on status code
            $userMessage = $this->getGitHubErrorMessage($statusCode, $errorMessage);
            throw new \Exception($userMessage);
        }
    }

    /**
     * Get user-friendly error message based on GitHub API error
     *
     * @param int|null $statusCode HTTP status code
     * @param string   $rawError   Raw error message
     *
     * @return string User-friendly error message
     *
     * @since 0.2.10
     */
    private function getGitHubErrorMessage(?int $statusCode, string $rawError): string
    {
        switch ($statusCode) {
            case 403:
                if (stripos($rawError, 'rate limit') !== false) {
                    $token = $this->config->getAppValue('openregister', 'github_api_token', '');
                    if (empty($token)) {
                        return 'GitHub API rate limit exceeded (60 requests/hour for unauthenticated). Please configure a GitHub API token in Settings to increase to 5,000 requests/hour (30/minute for Code Search).';
                    } else {
                        return 'GitHub Code Search API rate limit exceeded (30 requests per minute). Please wait a few minutes before trying again. The discovery search makes multiple API calls to find configurations.';
                    }
                }
                return 'Access forbidden. Please check your GitHub API token permissions in Settings.';
                
            case 401:
                return 'GitHub API authentication failed. Please check your API token in Settings or remove it to use unauthenticated access (60 requests/hour limit).';
                
            case 404:
                return 'Repository or resource not found on GitHub. Please check the repository exists and is public.';
                
            case 422:
                return 'Invalid search query. Please try different search terms.';
                
            case 503:
            case 500:
                return 'GitHub API is temporarily unavailable. Please try again in a few minutes.';
                
            default:
                // Return a generic message but don't expose the full raw error
                if (stripos($rawError, 'rate limit') !== false) {
                    return 'GitHub API rate limit exceeded. Please wait a few minutes or configure an API token in Settings.';
                }
                return 'GitHub API request failed. Please try again or check your API token configuration in Settings.';
        }
    }

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
     */
    private function getEnrichedConfigDetails(string $owner, string $repo, string $path, string $branch, ?string $fileSha): array
    {
        // Default fallback config
        $fallbackConfig = [
            'title'       => basename($path, '.json'),
            'description' => '',
            'version'     => 'v.unknown',
            'app'         => null,
            'type'        => 'unknown',
        ];

        // If we have a SHA, use it as cache key
        if ($fileSha !== null) {
            $cacheKey = "config_{$owner}_{$repo}_{$fileSha}";
            
            // Try to get from cache
            $cached = $this->cache->get($cacheKey);
            if ($cached !== null) {
                $this->logger->debug('Using cached config details', ['cache_key' => $cacheKey]);
                return $cached;
            }
        }

        // Not in cache or no SHA available, fetch from GitHub
        $enriched = $this->enrichConfigurationDetails($owner, $repo, $path, $branch);
        
        if ($enriched === null) {
            // Enrichment failed, return fallback
            return $fallbackConfig;
        }

        // Cache the enriched data (if we have a SHA)
        if ($fileSha !== null) {
            $cacheKey = "config_{$owner}_{$repo}_{$fileSha}";
            // Cache for 7 days (file content won't change as long as SHA is the same)
            $this->cache->set($cacheKey, $enriched, 7 * 24 * 60 * 60);
            $this->logger->debug('Cached config details', ['cache_key' => $cacheKey]);
        }

        return $enriched;
    }

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
     * @return array|null Configuration details from file, or null if failed
     *
     * @since 0.2.10
     */
    public function enrichConfigurationDetails(string $owner, string $repo, string $path, string $branch = 'main'): ?array
    {
        try {
            // Use raw.githubusercontent.com - doesn't count against API rate limit
            $rawUrl = "https://raw.githubusercontent.com/{$owner}/{$repo}/{$branch}/{$path}";
            
            $this->logger->debug('Enriching configuration details from raw URL', [
                'url' => $rawUrl,
            ]);
            
            $response = $this->client->request('GET', $rawUrl, [
                'headers' => [
                    'Accept' => 'application/json',
                ],
            ]);
            
            $content = $response->getBody();
            $data = json_decode($content, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->logger->warning('Failed to parse configuration JSON', [
                    'url' => $rawUrl,
                    'error' => json_last_error_msg(),
                ]);
                return null;
            }
            
            // Extract relevant metadata
            return [
                'title'       => $data['info']['title'] ?? basename($path, '.json'),
                'description' => $data['info']['description'] ?? '',
                'version'     => $data['info']['version'] ?? 'v.unknown',
                'app'         => $data['x-openregister']['app'] ?? null,
                'type'        => $data['x-openregister']['type'] ?? 'unknown',
                'openregister' => $data['x-openregister']['openregister'] ?? null,
            ];
        } catch (\Exception $e) {
            $this->logger->warning('Failed to enrich configuration details', [
                'owner' => $owner,
                'repo' => $repo,
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Get list of branches for a repository
     *
     * @param string $owner Repository owner
     * @param string $repo  Repository name
     *
     * @return array List of branches with name and commit info
     * @throws \Exception If API request fails
     *
     * @since 0.2.10
     */
    public function getBranches(string $owner, string $repo): array
    {
        try {
            $this->logger->info('Fetching branches from GitHub', [
                'owner' => $owner,
                'repo'  => $repo,
            ]);

            $response = $this->client->request('GET', self::API_BASE . "/repos/{$owner}/{$repo}/branches", [
                'headers' => $this->getHeaders(),
            ]);

            $branches = json_decode($response->getBody(), true);

            return array_map(function ($branch) {
                return [
                    'name'   => $branch['name'],
                    'commit' => $branch['commit']['sha'] ?? null,
                    'protected' => $branch['protected'] ?? false,
                ];
            }, $branches);
        } catch (GuzzleException $e) {
            $this->logger->error('GitHub API get branches failed', [
                'error' => $e->getMessage(),
                'owner' => $owner,
                'repo'  => $repo,
            ]);
            throw new \Exception('Failed to fetch branches: ' . $e->getMessage());
        }
    }

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
     */
    public function getFileContent(string $owner, string $repo, string $path, string $branch = 'main'): array
    {
        try {
            $this->logger->info('Fetching file from GitHub', [
                'owner'  => $owner,
                'repo'   => $repo,
                'path'   => $path,
                'branch' => $branch,
            ]);

            $response = $this->client->request('GET', self::API_BASE . "/repos/{$owner}/{$repo}/contents/{$path}", [
                'query' => ['ref' => $branch],
                'headers' => $this->getHeaders(),
            ]);

            $data = json_decode($response->getBody(), true);

            // Decode base64 content
            if (isset($data['content'])) {
                $content = base64_decode($data['content']);
                $json = json_decode($content, true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new \Exception('Invalid JSON in file: ' . json_last_error_msg());
                }

                return $json;
            }

            throw new \Exception('No content found in file');
        } catch (GuzzleException $e) {
            $this->logger->error('GitHub API get file content failed', [
                'error'  => $e->getMessage(),
                'owner'  => $owner,
                'repo'   => $repo,
                'path'   => $path,
                'branch' => $branch,
            ]);
            throw new \Exception('Failed to fetch file: ' . $e->getMessage());
        }
    }

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
     * @return array List of configuration files with metadata
     * @throws \Exception If API request fails
     *
     * @since 0.2.10
     */
    public function listConfigurationFiles(string $owner, string $repo, string $branch = 'main', string $path = ''): array
    {
        try {
            $this->logger->info('Listing configuration files from GitHub', [
                'owner'  => $owner,
                'repo'   => $repo,
                'branch' => $branch,
                'path'   => $path,
            ]);

            // Search in the repository for configuration files
            $searchQuery = "repo:{$owner}/{$repo} filename:openregister.json OR filename:*.openregister.json extension:json";
            
            $response = $this->client->request('GET', self::API_BASE . '/search/code', [
                'query' => [
                    'q' => $searchQuery,
                ],
                'headers' => $this->getHeaders(),
            ]);

            $data = json_decode($response->getBody(), true);

            $files = [];
            foreach ($data['items'] ?? [] as $item) {
                $configData = $this->parseConfigurationFile($owner, $repo, $item['path'], $branch);
                
                if ($configData !== null) {
                    $files[] = [
                        'path'   => $item['path'],
                        'sha'    => $item['sha'] ?? null,
                        'url'    => $item['html_url'] ?? null,
                        'config' => [
                            'title'       => $configData['info']['title'] ?? $configData['x-openregister']['title'] ?? basename($item['path']),
                            'description' => $configData['info']['description'] ?? $configData['x-openregister']['description'] ?? '',
                            'version'     => $configData['info']['version'] ?? $configData['x-openregister']['version'] ?? '1.0.0',
                            'app'         => $configData['x-openregister']['app'] ?? null,
                            'type'        => $configData['x-openregister']['type'] ?? 'manual',
                        ],
                    ];
                }
            }

            return $files;
        } catch (GuzzleException $e) {
            $this->logger->error('GitHub API list files failed', [
                'error'  => $e->getMessage(),
                'owner'  => $owner,
                'repo'   => $repo,
                'branch' => $branch,
            ]);
            throw new \Exception('Failed to list configuration files: ' . $e->getMessage());
        }
    }

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
     */
    private function parseConfigurationFile(string $owner, string $repo, string $path, string $branch = 'main'): ?array
    {
        try {
            $content = $this->getFileContent($owner, $repo, $path, $branch);

            // Validate that it's a valid OpenRegister configuration
            if (!isset($content['openapi']) || !isset($content['x-openregister'])) {
                $this->logger->debug('File does not contain required OpenRegister structure', [
                    'path' => $path,
                ]);
                return null;
            }

            return $content;
        } catch (\Exception $e) {
            $this->logger->debug('Failed to parse configuration file', [
                'path'  => $path,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Get repositories that the authenticated user has access to
     *
     * @param int $page Page number (default: 1)
     * @param int $perPage Items per page (default: 100, max: 100)
     *
     * @return array List of repositories with name, full_name, owner, etc.
     * @throws \Exception If API request fails
     */
    public function getRepositories(int $page = 1, int $perPage = 100): array
    {
        try {
            $this->logger->info('Fetching repositories from GitHub', [
                'page'    => $page,
                'per_page' => $perPage,
            ]);

            $response = $this->client->request('GET', self::API_BASE . '/user/repos', [
                'query' => [
                    'type'     => 'all', // all, owner, member
                    'sort'     => 'updated',
                    'direction' => 'desc',
                    'page'     => $page,
                    'per_page' => min($perPage, 100), // GitHub max is 100
                ],
                'headers' => $this->getHeaders(),
            ]);

            $repos = json_decode($response->getBody(), true);

            return array_map(function ($repo) {
                return [
                    'id'          => $repo['id'],
                    'name'        => $repo['name'],
                    'full_name'   => $repo['full_name'],
                    'owner'       => $repo['owner']['login'],
                    'owner_type'  => $repo['owner']['type'], // User or Organization
                    'private'     => $repo['private'],
                    'description' => $repo['description'] ?? '',
                    'default_branch' => $repo['default_branch'] ?? 'main',
                    'url'         => $repo['html_url'],
                    'api_url'     => $repo['url'],
                ];
            }, $repos);
        } catch (GuzzleException $e) {
            $this->logger->error('GitHub API get repositories failed', [
                'error' => $e->getMessage(),
            ]);
            throw new \Exception('Failed to fetch repositories: ' . $e->getMessage());
        }
    }

    /**
     * Publish a configuration file to GitHub
     *
     * Creates or updates a file in the specified repository, branch, and path.
     * Uses the GitHub Contents API which requires the file SHA for updates.
     *
     * @param string $owner       Repository owner
     * @param string $repo        Repository name
     * @param string $path        File path in repository (e.g., 'lib/Settings/config.json')
     * @param string $branch      Branch name (default: main)
     * @param string $content     File content (JSON string)
     * @param string $commitMessage Commit message
     * @param string|null $fileSha SHA of existing file (required for updates, null for new files)
     *
     * @return array Response with commit SHA, file SHA, and commit info
     * @throws \Exception If publish fails
     */
    public function publishConfiguration(
        string $owner,
        string $repo,
        string $path,
        string $branch,
        string $content,
        string $commitMessage,
        ?string $fileSha = null
    ): array {
        try {
            $this->logger->info('Publishing configuration to GitHub', [
                'owner' => $owner,
                'repo'  => $repo,
                'path'  => $path,
                'branch' => $branch,
                'is_update' => $fileSha !== null,
            ]);

            // Base64 encode the content (GitHub API requires base64)
            $encodedContent = base64_encode($content);

            $payload = [
                'message' => $commitMessage,
                'content' => $encodedContent,
                'branch'  => $branch,
            ];

            // If updating existing file, include SHA
            if ($fileSha !== null) {
                $payload['sha'] = $fileSha;
            }

            $response = $this->client->request('PUT', self::API_BASE . "/repos/{$owner}/{$repo}/contents/{$path}", [
                'headers' => $this->getHeaders(),
                'json'    => $payload,
            ]);

            $result = json_decode($response->getBody(), true);

            $this->logger->info('Configuration published successfully', [
                'commit_sha' => $result['commit']['sha'] ?? null,
                'file_sha'  => $result['content']['sha'] ?? null,
            ]);

            return [
                'success'    => true,
                'commit_sha' => $result['commit']['sha'] ?? null,
                'file_sha'   => $result['content']['sha'] ?? null,
                'commit_url' => $result['commit']['html_url'] ?? null,
                'file_url'   => $result['content']['html_url'] ?? null,
            ];
        } catch (GuzzleException $e) {
            $errorMessage = $e->getMessage();
            $statusCode = null;

            if ($e->hasResponse()) {
                $statusCode = $e->getResponse()->getStatusCode();
                $responseBody = $e->getResponse()->getBody()->getContents();
                $errorData = json_decode($responseBody, true);
                
                if (isset($errorData['message'])) {
                    $errorMessage = $errorData['message'];
                }
            }

            $this->logger->error('GitHub API publish failed', [
                'error'      => $errorMessage,
                'status_code' => $statusCode,
                'owner'      => $owner,
                'repo'       => $repo,
                'path'       => $path,
            ]);

            throw new \Exception('Failed to publish configuration: ' . $errorMessage);
        }
    }

    /**
     * Get file SHA for a specific file (needed for updates)
     *
     * @param string $owner Repository owner
     * @param string $repo  Repository name
     * @param string $path  File path in repository
     * @param string $branch Branch name (default: main)
     *
     * @return string|null File SHA or null if file doesn't exist
     * @throws \Exception If API request fails
     */
    public function getFileSha(string $owner, string $repo, string $path, string $branch = 'main'): ?string
    {
        try {
            $response = $this->client->request('GET', self::API_BASE . "/repos/{$owner}/{$repo}/contents/{$path}", [
                'query' => [
                    'ref' => $branch,
                ],
                'headers' => $this->getHeaders(),
            ]);

            $fileInfo = json_decode($response->getBody(), true);
            return $fileInfo['sha'] ?? null;
        } catch (GuzzleException $e) {
            // File doesn't exist or other error
            if ($e->hasResponse() && $e->getResponse()->getStatusCode() === 404) {
                return null; // File doesn't exist, which is fine for new files
            }
            
            $this->logger->error('GitHub API get file SHA failed', [
                'error' => $e->getMessage(),
                'owner' => $owner,
                'repo'  => $repo,
                'path'  => $path,
            ]);
            throw new \Exception('Failed to get file SHA: ' . $e->getMessage());
        }
    }

}

