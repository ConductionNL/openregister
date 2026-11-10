<?php

/**
 * OpenRegister GitLab Handler
 *
 * This file contains the GitLabHandler class for interacting with the GitLab API
 * to discover, fetch, and manage OpenRegister configurations stored in GitLab repositories.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Configuration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2025 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Configuration;

use Exception;
use GuzzleHttp\Exception\GuzzleException;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Handler for GitLab API operations
 *
 * Provides methods for:
 * - Searching for OpenRegister configurations across GitLab
 * - Fetching file contents from projects
 * - Listing branches
 * - Parsing and validating configuration files
 *
 * @package OCA\OpenRegister\Service\Configuration
 */
class GitLabHandler implements GitLabHandlerInterface {
	/**
	 * The tag every GitLab log message starts with.
	 */
	private const LOG_TAG = '[GitLabHandler] ';

	/**
	 * The exception message of a failed request, before the failure's own message, by action.
	 */
	private const FAILURE_PREFIXES = [
		'search' => 'Failed to search GitLab: ',
		'get branches' => 'Failed to fetch branches: ',
		'get file content' => 'Failed to fetch file: ',
		'list files' => 'Failed to list configuration files: ',
		'get project' => 'Failed to fetch project: ',
	];


	/**
	 * GitLab API base URL (can be configured for self-hosted instances)
	 *
	 * @var string
	 */
	private string $apiBase;

	/**
	 * HTTP client for API requests
	 *
	 * @var IClient
	 */
	private IClient $client;

	/**
	 * GitLabHandler constructor
	 *
	 * @param IClientService $clientService HTTP client service
	 * @param IAppConfig $appConfig App configuration service for app-level settings
	 * @param IConfig $config Configuration service for system-level settings
	 * @param LoggerInterface $logger Logger instance
	 */
	public function __construct(
		IClientService $clientService,
		private readonly IAppConfig $appConfig,
		private readonly IConfig $config,
		private readonly LoggerInterface $logger,
	) {
		$this->client = $clientService->newClient();

		// Allow configuration of GitLab URL (app-level setting takes precedence over system setting).
		$this->apiBase = $this->appConfig->getValueString('openregister', 'gitlab_api_url', '');
		if (empty($this->apiBase) === true) {
			$this->apiBase = $this->config->getSystemValue('gitlab_api_url', 'https://gitlab.com/api/v4');
		}
	}//end __construct()

	/**
	 * Get authentication headers for GitLab API
	 *
	 * @return string[]
	 *
	 * @psalm-return array{'PRIVATE-TOKEN'?: string}
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-non-aggregated-facet-isolation
	 */
	private function getHeaders(): array {
		$headers = [];

		// Add authentication token if configured.
		$token = $this->appConfig->getValueString('openregister', 'gitlab_api_token', '');
		if (empty($token) === false) {
			$headers['PRIVATE-TOKEN'] = $token;
		}

		return $headers;
	}//end getHeaders()

	/**
	 * The log context every GitLab log line starts with, then the line's own keys.
	 *
	 * @param array<string, mixed> $extra The line's own keys.
	 *
	 * @return array<string, mixed>
	 */
	private static function logContext(array $extra = []): array {
		return ['file' => __FILE__, 'line' => __LINE__] + $extra;
	}//end logContext()

	/**
	 * GET a path of the configured GitLab API with the API headers and decode the JSON body.
	 *
	 * @param string               $path    The path after the API base.
	 * @param array<string, mixed> $options Request options; the headers are added last.
	 *
	 * @return mixed The decoded body.
	 *
	 * @throws GuzzleException If the request fails.
	 */
	private function getJson(string $path, array $options = []): mixed {
		$response = $this->client->request('GET', $this->apiBase.$path, $options + ['headers' => $this->getHeaders()]);
		return json_decode($response->getBody(), true);
	}//end getJson()

	/**
	 * Log a failed API call and turn it into an exception for the caller.
	 *
	 * @param GuzzleException   $exception The failure.
	 * @param RepositoryApiCall $call      What failed and the call's log keys, logged after the error.
	 *
	 * @return Exception
	 */
	private function requestFailure(GuzzleException $exception, RepositoryApiCall $call): Exception {
		$this->logger->error(
			message: self::LOG_TAG."GitLab API {$call->action} failed",
			context: self::logContext(extra: ['error' => $exception->getMessage()] + $call->context)
		);
		return new Exception(self::FAILURE_PREFIXES[$call->action].$exception->getMessage());
	}//end requestFailure()

	/**
	 * Search for OpenRegister configurations on GitLab
	 *
	 * Uses GitLab Global Search API to find files containing x-openregister property
	 *
	 * @param string $search Search terms to filter results (optional)
	 * @param int $page Page number for pagination
	 * @param int $perPage Results per page (max 100)
	 *
	 * @return (((null|string)[]|mixed|string)[][]|int)[]
	 *
	 * @throws \Exception If API request fails
	 *
	 * @since 0.2.10
	 *
	 * @psalm-return array{total_count: int<0, max>,
	 *     results: list<array{config: array{app: null, description: '',
	 *     title: string, type: 'unknown', version: 'unknown'}, name: string,
	 *     path: mixed, project_id: mixed, ref: 'main'|mixed,
	 *     url: ''|mixed}>, page: int, per_page: int}
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-non-aggregated-facet-isolation
	 */
	public function searchConfigurations(string $search = '', int $page = 1, int $perPage = 30): array {
		return $this->searchPage(search: $search, page: new RepositorySearchPage(page: $page, perPage: $perPage));
	}//end searchConfigurations()

	/**
	 * One page of configuration files found by the blob search.
	 *
	 * @param string               $search The user's search terms, possibly empty.
	 * @param RepositorySearchPage $page   The page asked for.
	 *
	 * @return array{total_count: int, results: list<array<string, mixed>>, page: int, per_page: int}
	 *
	 * @throws Exception If the API request fails.
	 */
	private function searchPage(string $search, RepositorySearchPage $page): array {
		// Always search for x-openregister, optionally narrowed by the user's terms.
		$searchQuery = 'x-openregister';
		if (empty($search) === false) {
			$searchQuery = 'x-openregister '.$search;
		}

		$where = ['_search' => $search, 'query' => $searchQuery];
		try {
			$items = $this->searchBlobs(where: $where, page: $page);
		} catch (GuzzleException $e) {
			throw $this->requestFailure(exception: $e, call: new RepositoryApiCall(action: 'search', context: $where));
		}

		// File contents are fetched only when the user selects a configuration.
		$results = array_values(array_map(self::searchResult(...), array_filter($items, self::isFileHit(...))));
		return ['total_count' => count($items), 'results' => $results, 'page' => $page->page, 'per_page' => $page->perPage];
	}//end searchPage()

	/**
	 * Run the blob search.
	 *
	 * @param array{_search: string, query: string} $where The user's terms and the query sent.
	 * @param RepositorySearchPage                  $page  The page asked for.
	 *
	 * @return mixed The decoded hits.
	 *
	 * @throws GuzzleException If the request fails.
	 */
	private function searchBlobs(array $where, RepositorySearchPage $page): mixed {
		$context = self::logContext(extra: $where + ['page' => $page->page]);
		$this->logger->info(message: self::LOG_TAG.'Searching GitLab for OpenRegister configurations', context: $context);

		$query = ['scope' => 'blobs', 'search' => $where['query'], 'page' => $page->page, 'per_page' => $page->perPage];
		return $this->getJson(path: '/search', options: ['query' => $query]);
	}//end searchBlobs()

	/**
	 * Whether a search hit names a project and a file.
	 *
	 * @param array<string, mixed> $item The search hit.
	 *
	 * @return bool
	 */
	private static function isFileHit(array $item): bool {
		return ($item['project_id'] ?? null) !== null && ($item['path'] ?? null) !== null;
	}//end isFileHit()

	/**
	 * One search hit as a result; the configuration details are loaded on demand when importing.
	 *
	 * @param array<string, mixed> $item The search hit.
	 *
	 * @return array<string, mixed>
	 */
	private static function searchResult(array $item): array {
		$name = basename($item['path'], '.json');
		return [
			'project_id' => $item['project_id'],
			'path' => $item['path'],
			'ref' => $item['ref'] ?? 'main',
			'url' => $item['data'] ?? '',
			'name' => $name,
			'config' => ['title' => $name, 'description' => '', 'version' => 'unknown', 'app' => null, 'type' => 'unknown'],
		];
	}//end searchResult()

	/**
	 * Get list of branches for a project
	 *
	 * @param int $projectId GitLab project ID
	 *
	 * @return (false|mixed|null)[][] List of branches with name and commit info
	 *
	 * @throws \Exception If API request fails
	 *
	 * @since 0.2.10
	 *
	 * @psalm-return array<array{name: mixed, commit: mixed|null, protected: false|mixed, default: false|mixed}>
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-non-aggregated-facet-isolation
	 */
	public function getBranches(int $projectId): array {
		$where = ['project_id' => $projectId];
		try {
			$this->logger->info(message: self::LOG_TAG.'Fetching branches from GitLab', context: self::logContext(extra: $where));
			$branches = $this->getJson(path: "/projects/{$projectId}/repository/branches");
		} catch (GuzzleException $e) {
			throw $this->requestFailure(exception: $e, call: new RepositoryApiCall(action: 'get branches', context: $where));
		}

		// Format branch data for frontend.
		return array_map(self::branchSummary(...), $branches);
	}//end getBranches()

	/**
	 * One branch in the standardized format.
	 *
	 * @param array<string, mixed> $branch The branch from the API.
	 *
	 * @return array{name: mixed, commit: mixed, protected: mixed, default: mixed}
	 */
	private static function branchSummary(array $branch): array {
		return [
			'name' => $branch['name'],
			'commit' => $branch['commit']['id'] ?? null,
			'protected' => $branch['protected'] ?? false,
			'default' => $branch['default'] ?? false,
		];
	}//end branchSummary()

	/**
	 * Get file content from a project
	 *
	 * @param int $projectId GitLab project ID
	 * @param string $path File path in project
	 * @param string $ref Branch or tag name (default: main)
	 *
	 * @return array Decoded JSON content
	 * @throws \Exception If file cannot be fetched or parsed
	 *
	 * @since 0.2.10
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-non-aggregated-facet-isolation
	 */
	public function getFileContent(int $projectId, string $path, string $ref = 'main'): array {
		return $this->fileContent(project: new GitLabProjectRef(projectId: $projectId, ref: $ref), path: $path);
	}//end getFileContent()

	/**
	 * The decoded JSON content of a file in a project.
	 *
	 * @param GitLabProjectRef $project The project and ref.
	 * @param string           $path    The file path.
	 *
	 * @return array The decoded document.
	 *
	 * @throws Exception If the file cannot be fetched or parsed.
	 */
	private function fileContent(GitLabProjectRef $project, string $path): array {
		$where = ['project_id' => $project->projectId, 'path' => $path, 'ref' => $project->ref];
		try {
			$this->logger->info(message: self::LOG_TAG.'Fetching file from GitLab', context: self::logContext(extra: $where));
			$filePath = "/projects/{$project->projectId}/repository/files/".urlencode($path).'/raw';
			$json = $this->getJson(path: $filePath, options: ['query' => ['ref' => $project->ref]]);
		} catch (GuzzleException $e) {
			throw $this->requestFailure(exception: $e, call: new RepositoryApiCall(action: 'get file content', context: $where));
		}

		if (json_last_error() !== JSON_ERROR_NONE) {
			throw new Exception('Invalid JSON in file: '.json_last_error_msg());
		}

		return $json;
	}//end fileContent()

	/**
	 * List OpenRegister configuration files in a project
	 *
	 * Searches for files matching naming conventions within the project
	 *
	 * @param int $projectId GitLab project ID
	 * @param string $ref Branch or tag name (default: main)
	 * @param string $path Directory path to search (default: root)
	 *
	 * @return ((mixed|null|string)[]|mixed|null)[][] List of configuration files with metadata
	 *
	 * @throws \Exception If API request fails
	 *
	 * @since 0.2.10
	 *
	 * @psalm-return list<array{config: array{app: mixed|null,
	 *     description: ''|mixed, title: mixed|string, type: 'manual'|mixed,
	 *     version: '1.0.0'|mixed}, id: mixed|null, path: mixed}>
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-non-aggregated-facet-isolation
	 */
	public function listConfigurationFiles(int $projectId, string $ref = 'main', string $path = ''): array {
		return $this->listFiles(project: new GitLabProjectRef(projectId: $projectId, ref: $ref), path: $path);
	}//end listConfigurationFiles()

	/**
	 * The configuration files in a project directory, read from the repository tree.
	 *
	 * @param GitLabProjectRef $project The project and ref.
	 * @param string           $path    The directory path; empty for the root.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @throws Exception If the API request fails.
	 */
	private function listFiles(GitLabProjectRef $project, string $path): array {
		$where = ['project_id' => $project->projectId, 'ref' => $project->ref];
		try {
			$context = self::logContext(extra: $where + ['path' => $path]);
			$this->logger->info(message: self::LOG_TAG.'Listing configuration files from GitLab', context: $context);
			$query = ['ref' => $project->ref, 'path' => $path, 'recursive' => true];
			$tree = $this->getJson(path: "/projects/{$project->projectId}/repository/tree", options: ['query' => $query]);
		} catch (GuzzleException $e) {
			throw $this->requestFailure(exception: $e, call: new RepositoryApiCall(action: 'list files', context: $where));
		}

		return $this->configurationFiles(project: $project, tree: $tree);
	}//end listFiles()

	/**
	 * The blobs of a repository tree that are valid configuration files, as file entries.
	 *
	 * @param GitLabProjectRef                 $project The project and ref.
	 * @param array<int, array<string, mixed>> $tree    The repository tree.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function configurationFiles(GitLabProjectRef $project, array $tree): array {
		$files = [];
		foreach ($tree as $item) {
			if (self::isConfigurationBlob(item: $item) === false) {
				continue;
			}

			$configData = $this->parseConfigurationFile(project: $project, path: $item['path']);
			if ($configData !== null) {
				$files[] = self::fileEntry(item: $item, configData: $configData);
			}
		}

		return $files;
	}//end configurationFiles()

	/**
	 * Whether a tree entry is a file named by the configuration naming convention.
	 *
	 * @param array<string, mixed> $item The tree entry.
	 *
	 * @return bool
	 */
	private static function isConfigurationBlob(array $item): bool {
		return $item['type'] === 'blob'
			&& (str_ends_with($item['path'], 'openregister.json') === true || str_contains($item['path'], '.openregister.json') === true);
	}//end isConfigurationBlob()

	/**
	 * One configuration file entry: where it is and what it says about itself.
	 *
	 * @param array<string, mixed> $item       The tree entry.
	 * @param array<string, mixed> $configData The parsed configuration.
	 *
	 * @return array<string, mixed>
	 */
	private static function fileEntry(array $item, array $configData): array {
		$info = $configData['info'] ?? [];
		$xOpenReg = $configData['x-openregister'] ?? [];
		return [
			'path' => $item['path'],
			'id' => $item['id'] ?? null,
			'config' => [
				'title' => $info['title'] ?? $xOpenReg['title'] ?? basename($item['path']),
				'description' => $info['description'] ?? $xOpenReg['description'] ?? '',
				'version' => $info['version'] ?? $xOpenReg['version'] ?? '1.0.0',
				'app' => $xOpenReg['app'] ?? null,
				'type' => $xOpenReg['type'] ?? 'manual',
			],
		];
	}//end fileEntry()

	/**
	 * Get project information by namespace/project path
	 *
	 * Converts owner/repo format to GitLab project ID
	 *
	 * @param string $namespace Project namespace (username or group)
	 * @param string $project Project name
	 *
	 * @return array Project information including ID
	 * @throws \Exception If project not found
	 *
	 * @since 0.2.10
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-non-aggregated-facet-isolation
	 */
	public function getProjectByPath(string $namespace, string $project): array {
		$where = ['namespace' => $namespace, 'project' => $project];
		try {
			$projectPath = urlencode($namespace.'/'.$project);
			$this->logger->info(message: self::LOG_TAG.'Fetching GitLab project by path', context: self::logContext(extra: $where));
			return $this->getJson(path: "/projects/{$projectPath}");
		} catch (GuzzleException $e) {
			throw $this->requestFailure(exception: $e, call: new RepositoryApiCall(action: 'get project', context: $where));
		}
	}//end getProjectByPath()

	/**
	 * Parse and validate a configuration file
	 *
	 * @param GitLabProjectRef $project The project and ref.
	 * @param string           $path    File path
	 *
	 * @return array|null Parsed configuration or null if invalid
	 *
	 * @since 0.2.10
	 *
	 * @psalm-return array{openapi: mixed, 'x-openregister': mixed,...}|null
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-non-aggregated-facet-isolation
	 */
	private function parseConfigurationFile(GitLabProjectRef $project, string $path): ?array {
		try {
			$content = $this->fileContent(project: $project, path: $path);
		} catch (Exception $e) {
			$this->logSkippedFile(reason: 'Failed to parse configuration file', context: ['path' => $path, 'error' => $e->getMessage()]);
			return null;
		}

		// A valid OpenRegister configuration has both markers.
		if (isset($content['openapi']) === true && isset($content['x-openregister']) === true) {
			return $content;
		}

		$this->logSkippedFile(reason: 'File does not contain required OpenRegister structure', context: ['path' => $path]);
		return null;
	}//end parseConfigurationFile()

	/**
	 * Log, at debug level, why a file is not listed as a configuration.
	 *
	 * @param string               $reason  The reason.
	 * @param array<string, mixed> $context The file's log keys.
	 *
	 * @return void
	 */
	private function logSkippedFile(string $reason, array $context): void {
		$this->logger->debug(message: self::LOG_TAG.$reason, context: self::logContext(extra: $context));
	}//end logSkippedFile()

	/**
	 * Get the configured GitLab API base URL
	 *
	 * @return string GitLab API base URL
	 *
	 * @since 0.2.10
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-non-aggregated-facet-isolation
	 */
	public function getApiBase(): string {
		return $this->apiBase;
	}//end getApiBase()
}//end class
