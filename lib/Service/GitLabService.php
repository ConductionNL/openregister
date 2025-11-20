<?php

declare(strict_types=1);

/*
 * OpenRegister GitLab Service
 *
 * This file contains the GitLabService class for interacting with the GitLab API
 * to discover, fetch, and manage OpenRegister configurations stored in GitLab repositories.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2025 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

namespace OCA\OpenRegister\Service;

use OCP\Http\Client\IClient;
use GuzzleHttp\Exception\GuzzleException;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Service for GitLab API operations
 *
 * Provides methods for:
 * - Searching for OpenRegister configurations across GitLab
 * - Fetching file contents from projects
 * - Listing branches
 * - Parsing and validating configuration files
 *
 * @package OCA\OpenRegister\Service
 */
class GitLabService
{

    /**
     * GitLab API base URL (can be configured for self-hosted instances)
     */
    private string $apiBase;

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
     * GitLabService constructor
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

        // Allow configuration of GitLab URL (app-level setting takes precedence over system setting)
        $this->apiBase = $this->config->getAppValue('openregister', 'gitlab_api_url', '');
        if (empty($this->apiBase)) {
            $this->apiBase = $this->config->getSystemValue('gitlab_api_url', 'https://gitlab.com/api/v4');
        }

    }//end __construct()


    /**
     * Get authentication headers for GitLab API
     *
     * @return array Headers array
     */
    private function getHeaders(): array
    {
        $headers = [];

        // Add authentication token if configured
        $token = $this->config->getAppValue('openregister', 'gitlab_api_token', '');
        if (!empty($token)) {
            $headers['PRIVATE-TOKEN'] = $token;
        }

        return $headers;

    }//end getHeaders()


    /**
     * Search for OpenRegister configurations on GitLab
     *
     * Uses GitLab Global Search API to find files containing x-openregister property
     *
     * @param string $search  Search terms to filter results (optional)
     * @param int    $page    Page number for pagination
     * @param int    $perPage Results per page (max 100)
     *
     * @return array Search results with project info and file details
     * @throws \Exception If API request fails
     *
     * @since 0.2.10
     */
    public function searchConfigurations(string $search='', int $page=1, int $perPage=30): array
    {
        try {
            // Build search query
            // Always search for x-openregister, optionally filter by additional terms
            if (!empty($search)) {
                $searchQuery = 'x-openregister '.$search;
            } else {
                $searchQuery = 'x-openregister';
            }

            $this->logger->info(
                    'Searching GitLab for OpenRegister configurations',
                    [
                        '_search' => $search,
                        'query'   => $searchQuery,
                        'page'    => $page,
                    ]
                    );

            $response = $this->client->request(
                    'GET',
                    $this->apiBase.'/search',
                    [
                        'query'   => [
                            'scope'    => 'blobs',
                            'search'   => $searchQuery,
                            'page'     => $page,
                            'per_page' => $perPage,
                        ],
                        'headers' => $this->getHeaders(),
                    ]
                    );

            $items = json_decode($response->getBody(), true);

            // Return search results without fetching file contents
            // File contents will be fetched only when user selects a specific configuration
            $results = [];
            foreach ($items as $item) {
                // Extract project ID and file path
                if (isset($item['project_id']) && isset($item['path'])) {
                    $results[] = [
                        'project_id' => $item['project_id'],
                        'path'       => $item['path'],
                        'ref'        => $item['ref'] ?? 'main',
                        'url'        => $item['data'] ?? '',
                        'name'       => basename($item['path'], '.json'),
                        // Config details will be loaded on-demand when importing
                        'config'     => [
                            'title'       => basename($item['path'], '.json'),
                            'description' => '',
                            'version'     => 'unknown',
                            'app'         => null,
                            'type'        => 'unknown',
                        ],
                    ];
                }
            }

            return [
                'total_count' => count($items),
                'results'     => $results,
                'page'        => $page,
                'per_page'    => $perPage,
            ];
        } catch (GuzzleException $e) {
            $this->logger->error(
                    'GitLab API search failed',
                    [
                        'error'   => $e->getMessage(),
                        '_search' => $search,
                        'query'   => $searchQuery ?? '',
                    ]
                    );
            throw new \Exception('Failed to search GitLab: '.$e->getMessage());
        }//end try

    }//end searchConfigurations()


    /**
     * Get list of branches for a project
     *
     * @param int $projectId GitLab project ID
     *
     * @return array List of branches with name and commit info
     * @throws \Exception If API request fails
     *
     * @since 0.2.10
     */
    public function getBranches(int $projectId): array
    {
        try {
            $this->logger->info(
                    'Fetching branches from GitLab',
                    [
                        'project_id' => $projectId,
                    ]
                    );

            $response = $this->client->request(
                    'GET',
                    $this->apiBase."/projects/{$projectId}/repository/branches",
                    [
                        'headers' => $this->getHeaders(),
                    ]
                    );

            $branches = json_decode($response->getBody(), true);

            return array_map(
                    function ($branch) {
                        return [
                            'name'      => $branch['name'],
                            'commit'    => $branch['commit']['id'] ?? null,
                            'protected' => $branch['protected'] ?? false,
                            'default'   => $branch['default'] ?? false,
                        ];
                    },
                    $branches
                    );
        } catch (GuzzleException $e) {
            $this->logger->error(
                    'GitLab API get branches failed',
                    [
                        'error'      => $e->getMessage(),
                        'project_id' => $projectId,
                    ]
                    );
            throw new \Exception('Failed to fetch branches: '.$e->getMessage());
        }//end try

    }//end getBranches()


    /**
     * Get file content from a project
     *
     * @param int    $projectId GitLab project ID
     * @param string $path      File path in project
     * @param string $ref       Branch or tag name (default: main)
     *
     * @return array Decoded JSON content
     * @throws \Exception If file cannot be fetched or parsed
     *
     * @since 0.2.10
     */
    public function getFileContent(int $projectId, string $path, string $ref='main'): array
    {
        try {
            $this->logger->info(
                    'Fetching file from GitLab',
                    [
                        'project_id' => $projectId,
                        'path'       => $path,
                        'ref'        => $ref,
                    ]
                    );

            // URL encode the file path
            $encodedPath = urlencode($path);

            $response = $this->client->request(
                    'GET',
                    $this->apiBase."/projects/{$projectId}/repository/files/{$encodedPath}/raw",
                    [
                        'query'   => ['ref' => $ref],
                        'headers' => $this->getHeaders(),
                    ]
                    );

            $content = $response->getBody();
            $json    = json_decode($content, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception('Invalid JSON in file: '.json_last_error_msg());
            }

            return $json;
        } catch (GuzzleException $e) {
            $this->logger->error(
                    'GitLab API get file content failed',
                    [
                        'error'      => $e->getMessage(),
                        'project_id' => $projectId,
                        'path'       => $path,
                        'ref'        => $ref,
                    ]
                    );
            throw new \Exception('Failed to fetch file: '.$e->getMessage());
        }//end try

    }//end getFileContent()


    /**
     * List OpenRegister configuration files in a project
     *
     * Searches for files matching naming conventions within the project
     *
     * @param int    $projectId GitLab project ID
     * @param string $ref       Branch or tag name (default: main)
     * @param string $path      Directory path to search (default: root)
     *
     * @return array List of configuration files with metadata
     * @throws \Exception If API request fails
     *
     * @since 0.2.10
     */
    public function listConfigurationFiles(int $projectId, string $ref='main', string $path=''): array
    {
        try {
            $this->logger->info(
                    'Listing configuration files from GitLab',
                    [
                        'project_id' => $projectId,
                        'ref'        => $ref,
                        'path'       => $path,
                    ]
                    );

            // Get repository tree
            $response = $this->client->request(
                    'GET',
                    $this->apiBase."/projects/{$projectId}/repository/tree",
                    [
                        'query'   => [
                            'ref'       => $ref,
                            'path'      => $path,
                            'recursive' => true,
                        ],
                        'headers' => $this->getHeaders(),
                    ]
                    );

            $tree = json_decode($response->getBody(), true);

            $files = [];
            foreach ($tree as $item) {
                // Check if file matches naming convention
                if ($item['type'] === 'blob'
                    && (str_ends_with($item['path'], 'openregister.json')
                    || str_contains($item['path'], '.openregister.json'))
                ) {
                    $configData = $this->parseConfigurationFile($projectId, $item['path'], $ref);

                    if ($configData !== null) {
                        $files[] = [
                            'path'   => $item['path'],
                            'id'     => $item['id'] ?? null,
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
            }//end foreach

            return $files;
        } catch (GuzzleException $e) {
            $this->logger->error(
                    'GitLab API list files failed',
                    [
                        'error'      => $e->getMessage(),
                        'project_id' => $projectId,
                        'ref'        => $ref,
                    ]
                    );
            throw new \Exception('Failed to list configuration files: '.$e->getMessage());
        }//end try

    }//end listConfigurationFiles()


    /**
     * Get project information by namespace/project path
     *
     * Converts owner/repo format to GitLab project ID
     *
     * @param string $namespace Project namespace (username or group)
     * @param string $project   Project name
     *
     * @return array Project information including ID
     * @throws \Exception If project not found
     *
     * @since 0.2.10
     */
    public function getProjectByPath(string $namespace, string $project): array
    {
        try {
            $projectPath = urlencode($namespace.'/'.$project);

            $this->logger->info(
                    'Fetching GitLab project by path',
                    [
                        'namespace' => $namespace,
                        'project'   => $project,
                    ]
                    );

            $response = $this->client->request(
                    'GET',
                    $this->apiBase."/projects/{$projectPath}",
                    [
                        'headers' => $this->getHeaders(),
                    ]
                    );

            return json_decode($response->getBody(), true);
        } catch (GuzzleException $e) {
            $this->logger->error(
                    'GitLab API get project failed',
                    [
                        'error'     => $e->getMessage(),
                        'namespace' => $namespace,
                        'project'   => $project,
                    ]
                    );
            throw new \Exception('Failed to fetch project: '.$e->getMessage());
        }//end try

    }//end getProjectByPath()


    /**
     * Parse and validate a configuration file
     *
     * @param int    $projectId GitLab project ID
     * @param string $path      File path
     * @param string $ref       Branch or tag name (default: main)
     *
     * @return array|null Parsed configuration or null if invalid
     *
     * @since 0.2.10
     */
    private function parseConfigurationFile(int $projectId, string $path, string $ref='main'): ?array
    {
        try {
            $content = $this->getFileContent($projectId, $path, $ref);

            // Validate that it's a valid OpenRegister configuration
            if (!isset($content['openapi']) || !isset($content['x-openregister'])) {
                $this->logger->debug(
                        'File does not contain required OpenRegister structure',
                        [
                            'path' => $path,
                        ]
                        );
                return null;
            }

            return $content;
        } catch (\Exception $e) {
            $this->logger->debug(
                    'Failed to parse configuration file',
                    [
                        'path'  => $path,
                        'error' => $e->getMessage(),
                    ]
                    );
            return null;
        }//end try

    }//end parseConfigurationFile()


    /**
     * Get the configured GitLab API base URL
     *
     * @return string GitLab API base URL
     *
     * @since 0.2.10
     */
    public function getApiBase(): string
    {
        return $this->apiBase;

    }//end getApiBase()


}//end class
