<?php
/**
 * OpenRegister GitHub Service
 *
 * This file contains the service class for handling GitHub integration
 * in the OpenRegister application, including configuration management and publishing.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

namespace OCA\OpenRegister\Service;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use OCA\OpenRegister\Db\Configuration;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Class GitHubService
 *
 * Service for managing GitHub integration and operations.
 *
 * @package OCA\OpenRegister\Service
 */
class GitHubService
{

    /**
     * HTTP Client for making GitHub API requests.
     *
     * @var Client The HTTP client instance.
     */
    private Client $client;

    /**
     * Configuration instance for storing user settings.
     *
     * @var IConfig The configuration instance.
     */
    private IConfig $config;

    /**
     * Logger instance for logging operations.
     *
     * @var LoggerInterface The logger instance.
     */
    private LoggerInterface $logger;

    /**
     * Current user ID.
     *
     * @var string|null The current user ID.
     */
    private ?string $userId = null;

    /**
     * GitHub API base URL.
     *
     * @var string The GitHub API base URL.
     */
    private const GITHUB_API_BASE = 'https://api.github.com';


    /**
     * Constructor
     *
     * @param Client            $client The HTTP client instance
     * @param IConfig           $config The configuration instance
     * @param LoggerInterface   $logger The logger instance
     * @param string|null       $userId The current user ID
     */
    public function __construct(
        Client $client,
        IConfig $config,
        LoggerInterface $logger,
        ?string $userId = null
    ) {
        $this->client = $client;
        $this->config = $config;
        $this->logger = $logger;
        $this->userId = $userId;

    }//end __construct()


    /**
     * Set the current user ID
     *
     * @param string|null $userId The user ID to set
     *
     * @return void
     */
    public function setUserId(?string $userId): void
    {
        $this->userId = $userId;

    }//end setUserId()


    /**
     * Store GitHub personal access token for a user
     *
     * @param string $token  The GitHub personal access token
     * @param string $userId Optional user ID (uses current user if not provided)
     *
     * @return bool True if token was stored successfully
     */
    public function setUserToken(string $token, ?string $userId = null): bool
    {
        $userId = $userId ?? $this->userId;
        
        if ($userId === null) {
            $this->logger->error('Cannot store GitHub token: no user ID provided');
            return false;
        }

        try {
            // Store token encrypted in user config
            $this->config->setUserValue($userId, 'openregister', 'github_token', $token);
            $this->logger->info("GitHub token stored for user: {$userId}");
            return true;
        } catch (Exception $e) {
            $this->logger->error("Failed to store GitHub token: ".$e->getMessage());
            return false;
        }

    }//end setUserToken()


    /**
     * Get GitHub personal access token for a user
     *
     * @param string|null $userId Optional user ID (uses current user if not provided)
     *
     * @return string|null The GitHub personal access token or null if not found
     */
    public function getUserToken(?string $userId = null): ?string
    {
        $userId = $userId ?? $this->userId;
        
        if ($userId === null) {
            $this->logger->warning('Cannot retrieve GitHub token: no user ID provided');
            return null;
        }

        $token = $this->config->getUserValue($userId, 'openregister', 'github_token', '');
        
        return $token !== '' ? $token : null;

    }//end getUserToken()


    /**
     * Validate a GitHub personal access token
     *
     * Makes a test API call to verify the token is valid and has required permissions.
     *
     * @param string $token The GitHub personal access token to validate
     *
     * @return bool True if token is valid
     * @throws GuzzleException If API request fails
     */
    public function validateToken(string $token): bool
    {
        try {
            $response = $this->client->request('GET', self::GITHUB_API_BASE.'/user', [
                'headers' => [
                    'Authorization' => "Bearer {$token}",
                    'Accept'        => 'application/vnd.github+json',
                    'X-GitHub-Api-Version' => '2022-11-28',
                ],
            ]);

            $statusCode = $response->getStatusCode();
            
            if ($statusCode === 200) {
                $this->logger->info('GitHub token validation successful');
                return true;
            }

            $this->logger->warning("GitHub token validation returned unexpected status: {$statusCode}");
            return false;
        } catch (GuzzleException $e) {
            $this->logger->error('GitHub token validation failed: '.$e->getMessage());
            return false;
        }

    }//end validateToken()


    /**
     * Search GitHub for configuration files
     *
     * Searches GitHub repositories for configuration files matching the query.
     * Returns a list of matching files with their download URLs.
     *
     * @param string      $query Search query
     * @param string|null $token Optional GitHub token (uses stored token if not provided)
     *
     * @return array Array of search results
     * @throws GuzzleException If API request fails
     *
     * @phpstan-return array<array{
     *     name: string,
     *     path: string,
     *     repository: string,
     *     url: string,
     *     htmlUrl: string
     * }>
     */
    public function searchConfigurations(string $query, ?string $token = null): array
    {
        $token = $token ?? $this->getUserToken();
        
        if ($token === null) {
            $this->logger->warning('No GitHub token available for search');
            return [];
        }

        try {
            // Search for JSON and YAML configuration files
            $searchQuery = urlencode("{$query} extension:json extension:yaml extension:yml");
            
            $response = $this->client->request('GET', self::GITHUB_API_BASE."/search/code?q={$searchQuery}", [
                'headers' => [
                    'Authorization' => "Bearer {$token}",
                    'Accept'        => 'application/vnd.github+json',
                    'X-GitHub-Api-Version' => '2022-11-28',
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            
            $results = [];
            foreach ($data['items'] ?? [] as $item) {
                $results[] = [
                    'name'       => $item['name'],
                    'path'       => $item['path'],
                    'repository' => $item['repository']['full_name'],
                    'url'        => $item['git_url'],
                    'htmlUrl'    => $item['html_url'],
                ];
            }

            $this->logger->info("Found ".count($results)." configuration files on GitHub");
            return $results;
        } catch (GuzzleException $e) {
            $this->logger->error('GitHub search failed: '.$e->getMessage());
            throw $e;
        }

    }//end searchConfigurations()


    /**
     * Push configuration to GitHub repository
     *
     * Creates or updates a file in a GitHub repository with the configuration content.
     * Supports both creating new files and updating existing ones.
     *
     * @param Configuration $configuration The configuration to push
     * @param string        $content       The configuration content (JSON/YAML)
     * @param string        $message       Commit message
     * @param string|null   $token         Optional GitHub token (uses stored token if not provided)
     *
     * @return array Response from GitHub API
     * @throws Exception If push fails or required fields are missing
     * @throws GuzzleException If API request fails
     *
     * @phpstan-return array{
     *     success: bool,
     *     message: string,
     *     sha?: string,
     *     url?: string
     * }
     */
    public function pushToRepository(Configuration $configuration, string $content, string $message, ?string $token = null): array
    {
        $token = $token ?? $this->getUserToken();
        
        if ($token === null) {
            throw new Exception('No GitHub token available');
        }

        // Validate required configuration fields
        $repo = $configuration->getGithubRepo();
        if (empty($repo) === true) {
            throw new Exception('GitHub repository not configured');
        }

        $branch = $configuration->getGithubBranch() ?? 'main';
        $path   = $configuration->getGithubPath() ?? 'configuration.json';

        try {
            // First, try to get the current file (to get its SHA for updates)
            $currentSha = null;
            try {
                $getResponse = $this->client->request('GET', self::GITHUB_API_BASE."/repos/{$repo}/contents/{$path}?ref={$branch}", [
                    'headers' => [
                        'Authorization' => "Bearer {$token}",
                        'Accept'        => 'application/vnd.github+json',
                        'X-GitHub-Api-Version' => '2022-11-28',
                    ],
                ]);

                $fileData   = json_decode($getResponse->getBody()->getContents(), true);
                $currentSha = $fileData['sha'] ?? null;
                $this->logger->info("Found existing file with SHA: {$currentSha}");
            } catch (GuzzleException $e) {
                // File doesn't exist, will create new one
                $this->logger->info("File doesn't exist, will create new one");
            }

            // Prepare the payload
            $payload = [
                'message' => $message,
                'content' => base64_encode($content),
                'branch'  => $branch,
            ];

            // Add SHA if updating existing file
            if ($currentSha !== null) {
                $payload['sha'] = $currentSha;
            }

            // Create or update the file
            $response = $this->client->request('PUT', self::GITHUB_API_BASE."/repos/{$repo}/contents/{$path}", [
                'headers' => [
                    'Authorization' => "Bearer {$token}",
                    'Accept'        => 'application/vnd.github+json',
                    'X-GitHub-Api-Version' => '2022-11-28',
                    'Content-Type'  => 'application/json',
                ],
                'json' => $payload,
            ]);

            $responseData = json_decode($response->getBody()->getContents(), true);

            $this->logger->info("Successfully pushed configuration to GitHub: {$repo}/{$path}");

            return [
                'success' => true,
                'message' => 'Configuration pushed successfully',
                'sha'     => $responseData['content']['sha'] ?? null,
                'url'     => $responseData['content']['html_url'] ?? null,
            ];
        } catch (GuzzleException $e) {
            $this->logger->error('Failed to push to GitHub: '.$e->getMessage());
            throw new Exception('Failed to push to GitHub: '.$e->getMessage());
        }

    }//end pushToRepository()


    /**
     * Create a pull request with configuration changes
     *
     * Creates a new branch with the configuration and opens a pull request.
     * Useful for proposing configuration changes without direct commits.
     *
     * @param Configuration $configuration The configuration to push
     * @param string        $content       The configuration content (JSON/YAML)
     * @param string        $title         Pull request title
     * @param string        $body          Pull request description
     * @param string|null   $token         Optional GitHub token (uses stored token if not provided)
     *
     * @return array Response from GitHub API
     * @throws Exception If PR creation fails or required fields are missing
     * @throws GuzzleException If API request fails
     *
     * @phpstan-return array{
     *     success: bool,
     *     message: string,
     *     prNumber?: int,
     *     prUrl?: string
     * }
     */
    public function createPullRequest(Configuration $configuration, string $content, string $title, string $body, ?string $token = null): array
    {
        $token = $token ?? $this->getUserToken();
        
        if ($token === null) {
            throw new Exception('No GitHub token available');
        }

        // Validate required configuration fields
        $repo = $configuration->getGithubRepo();
        if (empty($repo) === true) {
            throw new Exception('GitHub repository not configured');
        }

        $baseBranch = $configuration->getGithubBranch() ?? 'main';
        $path       = $configuration->getGithubPath() ?? 'configuration.json';
        $newBranch  = 'openregister-config-'.time();

        try {
            // Step 1: Get the base branch reference
            $refResponse = $this->client->request('GET', self::GITHUB_API_BASE."/repos/{$repo}/git/ref/heads/{$baseBranch}", [
                'headers' => [
                    'Authorization' => "Bearer {$token}",
                    'Accept'        => 'application/vnd.github+json',
                    'X-GitHub-Api-Version' => '2022-11-28',
                ],
            ]);

            $refData = json_decode($refResponse->getBody()->getContents(), true);
            $baseSha = $refData['object']['sha'];

            // Step 2: Create new branch
            $this->client->request('POST', self::GITHUB_API_BASE."/repos/{$repo}/git/refs", [
                'headers' => [
                    'Authorization' => "Bearer {$token}",
                    'Accept'        => 'application/vnd.github+json',
                    'X-GitHub-Api-Version' => '2022-11-28',
                    'Content-Type'  => 'application/json',
                ],
                'json' => [
                    'ref' => "refs/heads/{$newBranch}",
                    'sha' => $baseSha,
                ],
            ]);

            // Step 3: Create/update file in new branch
            $this->client->request('PUT', self::GITHUB_API_BASE."/repos/{$repo}/contents/{$path}", [
                'headers' => [
                    'Authorization' => "Bearer {$token}",
                    'Accept'        => 'application/vnd.github+json',
                    'X-GitHub-Api-Version' => '2022-11-28',
                    'Content-Type'  => 'application/json',
                ],
                'json' => [
                    'message' => $title,
                    'content' => base64_encode($content),
                    'branch'  => $newBranch,
                ],
            ]);

            // Step 4: Create pull request
            $prResponse = $this->client->request('POST', self::GITHUB_API_BASE."/repos/{$repo}/pulls", [
                'headers' => [
                    'Authorization' => "Bearer {$token}",
                    'Accept'        => 'application/vnd.github+json',
                    'X-GitHub-Api-Version' => '2022-11-28',
                    'Content-Type'  => 'application/json',
                ],
                'json' => [
                    'title' => $title,
                    'body'  => $body,
                    'head'  => $newBranch,
                    'base'  => $baseBranch,
                ],
            ]);

            $prData = json_decode($prResponse->getBody()->getContents(), true);

            $this->logger->info("Successfully created pull request #{$prData['number']} on GitHub");

            return [
                'success'  => true,
                'message'  => 'Pull request created successfully',
                'prNumber' => $prData['number'],
                'prUrl'    => $prData['html_url'],
            ];
        } catch (GuzzleException $e) {
            $this->logger->error('Failed to create pull request: '.$e->getMessage());
            throw new Exception('Failed to create pull request: '.$e->getMessage());
        }

    }//end createPullRequest()


    /**
     * Create a new GitHub repository
     *
     * Creates a new repository on GitHub for storing configurations.
     * Useful for users who don't have an existing repository.
     *
     * @param string      $name        Repository name
     * @param string      $description Repository description
     * @param bool        $private     Whether the repository should be private
     * @param string|null $token       Optional GitHub token (uses stored token if not provided)
     *
     * @return array Response from GitHub API
     * @throws Exception If repository creation fails
     * @throws GuzzleException If API request fails
     *
     * @phpstan-return array{
     *     success: bool,
     *     message: string,
     *     repoName?: string,
     *     repoUrl?: string
     * }
     */
    public function createRepository(string $name, string $description, bool $private = false, ?string $token = null): array
    {
        $token = $token ?? $this->getUserToken();
        
        if ($token === null) {
            throw new Exception('No GitHub token available');
        }

        try {
            $response = $this->client->request('POST', self::GITHUB_API_BASE.'/user/repos', [
                'headers' => [
                    'Authorization' => "Bearer {$token}",
                    'Accept'        => 'application/vnd.github+json',
                    'X-GitHub-Api-Version' => '2022-11-28',
                    'Content-Type'  => 'application/json',
                ],
                'json' => [
                    'name'        => $name,
                    'description' => $description,
                    'private'     => $private,
                    'auto_init'   => true, // Initialize with README
                ],
            ]);

            $repoData = json_decode($response->getBody()->getContents(), true);

            $this->logger->info("Successfully created GitHub repository: {$repoData['full_name']}");

            return [
                'success'  => true,
                'message'  => 'Repository created successfully',
                'repoName' => $repoData['full_name'],
                'repoUrl'  => $repoData['html_url'],
            ];
        } catch (GuzzleException $e) {
            $this->logger->error('Failed to create GitHub repository: '.$e->getMessage());
            throw new Exception('Failed to create GitHub repository: '.$e->getMessage());
        }

    }//end createRepository()


}//end class


