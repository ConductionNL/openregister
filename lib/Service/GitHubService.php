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
     * GitHubService constructor
     *
     * @param IClient         $client HTTP client
     * @param IConfig         $config Configuration service
     * @param LoggerInterface $logger Logger instance
     */
    public function __construct(
        IClient $client,
        IConfig $config,
        LoggerInterface $logger
    ) {
        $this->client = $client;
        $this->config = $config;
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
            // Two-phase search strategy for optimal results:
            // Phase 1: Broad path search to discover organizations
            // Phase 2: Content search within discovered organizations to validate
            
            // PHASE 1: Discover organizations with potential OpenRegister files
            // Search for ANY file containing 'openregister' in its path
            // This catches: *_openregister.json files, openregister.md docs, etc.
            $this->logger->info('Phase 1: Discovering organizations with OpenRegister files');
            $discoveryQuery = 'openregister in:path';
            if (!empty($search)) {
                $discoveryQuery .= ' ' . $search;
            }
            
            // Collect organizations from multiple pages to ensure broad coverage
            $organizations = [];
            $maxPages = 5; // Limit to first 5 pages (500 results) to avoid excessive API calls
            $itemsPerPage = 100;
            
            for ($currentPage = 1; $currentPage <= $maxPages; $currentPage++) {
                $discoveryResponse = $this->client->request('GET', self::API_BASE . '/search/code', [
                    'query' => [
                        'q'        => $discoveryQuery,
                        'page'     => $currentPage,
                        'per_page' => $itemsPerPage,
                    ],
                    'headers' => $this->getHeaders(),
                ]);
                
                $discoveryData = json_decode($discoveryResponse->getBody(), true);
                
                // Extract unique organizations from this page
                foreach ($discoveryData['items'] ?? [] as $item) {
                    $org = $item['repository']['owner']['login'];
                    if (!in_array($org, $organizations)) {
                        $organizations[] = $org;
                    }
                }
                
                // Stop if we've processed all available results
                $totalCount = $discoveryData['total_count'] ?? 0;
                if ($currentPage * $itemsPerPage >= $totalCount) {
                    break;
                }
                
                // Stop if we've found enough organizations (scalability limit)
                // GitHub search queries have length limits, so cap at ~50 orgs
                if (count($organizations) >= 50) {
                    $this->logger->info('Phase 1: Reached organization limit', [
                        'limit' => 50,
                        'total_found' => count($organizations),
                    ]);
                    break;
                }
            }
            
            $this->logger->info('Phase 1 complete: Discovered organizations', [
                'count' => count($organizations),
                'organizations' => array_slice($organizations, 0, 10), // Log first 10 only
            ]);
            
            // PHASE 2: Content search within discovered organizations
            $this->logger->info('Phase 2: Searching for x-openregister content in discovered organizations');
            
            if (empty($organizations)) {
                // No organizations found, return empty results
                return [
                    'total_count' => 0,
                    'results'     => [],
                    'page'        => $page,
                    'per_page'    => $perPage,
                ];
            }
            
            // For scalability: if we have many organizations, batch the searches
            // GitHub search queries have character limits (~256 chars for URL-safe queries)
            // Each org filter is ~20 chars on average, so batch in groups of 10
            $batchSize = 10;
            $orgBatches = array_chunk($organizations, $batchSize);
            
            $allResults = [];
            $totalConfigurations = 0;
            
            foreach ($orgBatches as $batchIndex => $orgBatch) {
                // Build content search query with organization filters for this batch
                // Only search JSON files to ensure we get actual configuration files
                $orgFilters = array_map(function($org) { return "org:$org"; }, $orgBatch);
                $contentQuery = 'x-openregister extension:json ' . implode(' ', $orgFilters);
                if (!empty($search)) {
                    $contentQuery .= ' ' . $search;
                }
                
                $this->logger->debug('Phase 2: Searching batch', [
                    'batch' => $batchIndex + 1,
                    'total_batches' => count($orgBatches),
                    'orgs_in_batch' => count($orgBatch),
                ]);
                
                try {
                    $contentResponse = $this->client->request('GET', self::API_BASE . '/search/code', [
                        'query' => [
                            'q'        => $contentQuery,
                            'page'     => 1, // Always get first page from each batch
                            'per_page' => 100, // Max per batch
                        ],
                        'headers' => $this->getHeaders(),
                    ]);
                    
                    $contentData = json_decode($contentResponse->getBody(), true);
                    $totalConfigurations += $contentData['total_count'] ?? 0;
                    
                    // Process and format results from this batch
                    foreach ($contentData['items'] ?? [] as $item) {
                        $owner = $item['repository']['owner']['login'];
                        $repo = $item['repository']['name'];
                        $defaultBranch = $item['repository']['default_branch'] ?? 'main';
                        
                        $allResults[] = [
                            'repository'  => $item['repository']['full_name'],
                            'owner'       => $owner,
                            'repo'        => $repo,
                            'path'        => $item['path'],
                            'url'         => $item['html_url'],
                            'stars'       => $item['repository']['stargazers_count'] ?? 0,
                            'description' => $item['repository']['description'] ?? '',
                            'name'        => basename($item['path'], '.json'),
                            'branch'      => $defaultBranch,
                            'raw_url'     => "https://raw.githubusercontent.com/{$owner}/{$repo}/{$defaultBranch}/{$item['path']}",
                            // Repository owner/organization info
                            'organization' => [
                                'name'        => $owner,
                                'avatar_url'  => $item['repository']['owner']['avatar_url'] ?? '',
                                'type'        => $item['repository']['owner']['type'] ?? 'User',
                                'url'         => $item['repository']['owner']['html_url'] ?? '',
                            ],
                            // Config details - use filename as fallback, will be enriched by frontend
                            'config'      => [
                                'title'       => basename($item['path'], '.json'),
                                'description' => $item['repository']['description'] ?? '',
                                'version'     => 'v.unknown',
                                'app'         => null,
                                'type'        => 'unknown',
                            ],
                        ];
                    }
                } catch (\Exception $e) {
                    $this->logger->warning('Phase 2: Batch search failed', [
                        'batch' => $batchIndex + 1,
                        'error' => $e->getMessage(),
                    ]);
                    // Continue with other batches
                }
            }
            
            // Sort by stars (popularity) and apply pagination
            usort($allResults, function($a, $b) {
                return $b['stars'] - $a['stars'];
            });
            
            // Apply user-requested pagination
            $offset = ($page - 1) * $perPage;
            $paginatedResults = array_slice($allResults, $offset, $perPage);
            
            $this->logger->info('Phase 2 complete: Content search finished', [
                'organizations_searched' => count($organizations),
                'batches_processed' => count($orgBatches),
                'total_configurations' => count($allResults),
                'returned_after_pagination' => count($paginatedResults),
            ]);

            return [
                'total_count' => count($allResults), // Total unique results found
                'results'     => $paginatedResults,
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
                    return 'GitHub API rate limit exceeded. Please wait a few minutes before trying again, or configure a GitHub API token in Settings to increase your rate limit from 60 to 5,000 requests per hour.';
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

}

