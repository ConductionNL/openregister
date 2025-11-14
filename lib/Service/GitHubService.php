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

use GuzzleHttp\Client;
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
     * @var Client
     */
    private Client $client;

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
     * @param Client          $client HTTP client
     * @param IConfig         $config Configuration service
     * @param LoggerInterface $logger Logger instance
     */
    public function __construct(
        Client $client,
        IConfig $config,
        LoggerInterface $logger
    ) {
        $this->client = $client;
        $this->config = $config;
        $this->logger = $logger;
    }

    /**
     * Search for OpenRegister configurations on GitHub
     *
     * Uses GitHub Code Search API to find JSON files containing x-openregister property
     *
     * @param string $query  Search query (default: searches for x-openregister)
     * @param int    $page   Page number for pagination
     * @param int    $perPage Results per page (max 100)
     *
     * @return array Search results with repository info and file details
     * @throws \Exception If API request fails
     *
     * @since 0.2.10
     */
    public function searchConfigurations(string $query = '', int $page = 1, int $perPage = 30): array
    {
        try {
            // Build search query
            // Search for files containing "x-openregister" in JSON files
            $searchQuery = $query ?: 'x-openregister in:file language:json extension:json';
            
            $this->logger->info('Searching GitHub for OpenRegister configurations', [
                'query' => $searchQuery,
                'page'  => $page,
            ]);

            $response = $this->client->request('GET', self::API_BASE . '/search/code', [
                'query' => [
                    'q'        => $searchQuery,
                    'page'     => $page,
                    'per_page' => $perPage,
                ],
                'headers' => $this->getHeaders(),
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            // Parse and enrich results
            $results = [];
            foreach ($data['items'] ?? [] as $item) {
                $configData = $this->parseConfigurationFile($item['repository']['owner']['login'], $item['repository']['name'], $item['path']);
                
                if ($configData !== null) {
                    $results[] = [
                        'repository'  => $item['repository']['full_name'],
                        'owner'       => $item['repository']['owner']['login'],
                        'repo'        => $item['repository']['name'],
                        'path'        => $item['path'],
                        'url'         => $item['html_url'],
                        'stars'       => $item['repository']['stargazers_count'] ?? 0,
                        'description' => $item['repository']['description'] ?? '',
                        'config'      => [
                            'title'       => $configData['info']['title'] ?? $configData['x-openregister']['title'] ?? 'Unknown',
                            'description' => $configData['info']['description'] ?? $configData['x-openregister']['description'] ?? '',
                            'version'     => $configData['info']['version'] ?? $configData['x-openregister']['version'] ?? '1.0.0',
                            'app'         => $configData['x-openregister']['app'] ?? null,
                            'type'        => $configData['x-openregister']['type'] ?? 'manual',
                        ],
                    ];
                }
            }

            return [
                'total_count' => $data['total_count'] ?? 0,
                'items'       => $results,
                'page'        => $page,
                'per_page'    => $perPage,
            ];
        } catch (GuzzleException $e) {
            $this->logger->error('GitHub API search failed', [
                'error' => $e->getMessage(),
                'query' => $searchQuery ?? '',
            ]);
            throw new \Exception('Failed to search GitHub: ' . $e->getMessage());
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

            $branches = json_decode($response->getBody()->getContents(), true);

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

            $data = json_decode($response->getBody()->getContents(), true);

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

            $data = json_decode($response->getBody()->getContents(), true);

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
     * Get HTTP headers for GitHub API requests
     *
     * Includes authentication if token is configured
     *
     * @return array HTTP headers
     *
     * @since 0.2.10
     */
    private function getHeaders(): array
    {
        $headers = [
            'Accept'     => 'application/vnd.github.v3+json',
            'User-Agent' => 'OpenRegister-Nextcloud',
        ];

        // Add authentication token if configured
        $token = $this->config->getSystemValue('github_api_token', '');
        if (!empty($token)) {
            $headers['Authorization'] = 'token ' . $token;
        }

        return $headers;
    }
}

