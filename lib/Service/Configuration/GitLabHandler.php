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
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\RepositorySearchPage;

/**
 * Handler for GitLab API operations
 *
 * Provides methods for:
 * - Searching for OpenRegister configurations across GitLab
 * - Fetching file contents from projects
 * - Listing branches
 * - Parsing and validating configuration files
 * - Publishing a configuration file to a project branch
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
		'publish' => 'Failed to publish file: ',
	];


	/**
	 * GitLabHandler constructor
	 *
	 * @param GitLabApiClient $api    Authenticated requests against the configured GitLab API
	 * @param LoggerInterface $logger Logger instance
	 */
	public function __construct(
		private readonly GitLabApiClient $api,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

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
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-non-aggregated-facet-isolation
	 */
	public function searchPage(string $search, RepositorySearchPage $page): array {
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
		return $this->api->getJson(path: '/search', options: ['query' => $query]);
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
			$branches = $this->api->getJson(path: "/projects/{$projectId}/repository/branches");
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
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-non-aggregated-facet-isolation
	 */
	public function fileContent(GitLabProjectRef $project, string $path): array {
		$where = ['project_id' => $project->projectId, 'path' => $path, 'ref' => $project->ref];
		try {
			$this->logger->info(message: self::LOG_TAG.'Fetching file from GitLab', context: self::logContext(extra: $where));
			$filePath = "/projects/{$project->projectId}/repository/files/".urlencode($path).'/raw';
			$json = $this->api->getJson(path: $filePath, options: ['query' => ['ref' => $project->ref]]);
		} catch (GuzzleException $e) {
			throw $this->requestFailure(exception: $e, call: new RepositoryApiCall(action: 'get file content', context: $where));
		}

		if (json_last_error() !== JSON_ERROR_NONE) {
			throw new Exception('Invalid JSON in file: '.json_last_error_msg());
		}

		return $json;
	}//end fileContent()

	/**
	 * List OpenRegister configuration files in a project directory, read from the repository tree.
	 *
	 * @param GitLabProjectRef $project The project and ref.
	 * @param string           $path    The directory path; empty for the root.
	 *
	 * @return list<array<string, mixed>> The configuration files with their metadata.
	 *
	 * @throws Exception If the API request fails.
	 *
	 * @since 0.2.10
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-non-aggregated-facet-isolation
	 */
	public function listConfigurationFiles(GitLabProjectRef $project, string $path = ''): array {
		$where = ['project_id' => $project->projectId, 'ref' => $project->ref];
		try {
			$context = self::logContext(extra: $where + ['path' => $path]);
			$this->logger->info(message: self::LOG_TAG.'Listing configuration files from GitLab', context: $context);
			$query = ['ref' => $project->ref, 'path' => $path, 'recursive' => true];
			$tree = $this->api->getJson(path: "/projects/{$project->projectId}/repository/tree", options: ['query' => $query]);
		} catch (GuzzleException $e) {
			throw $this->requestFailure(exception: $e, call: new RepositoryApiCall(action: 'list files', context: $where));
		}

		return $this->configurationFiles(project: $project, tree: $tree);
	}//end listConfigurationFiles()

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
			return $this->api->getJson(path: "/projects/{$projectPath}");
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
	 * Publish a file to a project branch: update it when it exists there, create it otherwise.
	 *
	 * Uses the Repository Files API, which takes the branch, the content and the
	 * commit message; a second publish to the same path updates that file.
	 *
	 * @param GitLabProjectRef $project       The project and the branch to commit to.
	 * @param string           $path          The file path in the project.
	 * @param string           $content       The new file content.
	 * @param string           $commitMessage The commit message.
	 *
	 * @return array{success: true, is_update: bool, file_path: mixed, branch: mixed}
	 *
	 * @throws Exception If a request fails.
	 *
	 * @spec openspec/changes/capability-rows-verified-b/specs/data-import-export/spec.md
	 */
	public function publishFile(GitLabProjectRef $project, string $path, string $content, string $commitMessage): array {
		$where = ['project_id' => $project->projectId, 'path' => $path, 'ref' => $project->ref];
		$filePath = "/projects/{$project->projectId}/repository/files/".rawurlencode($path);
		try {
			$isUpdate = $this->fileExists(filePath: $filePath, ref: $project->ref);
			$context = self::logContext(extra: $where + ['is_update' => $isUpdate]);
			$this->logger->info(message: self::LOG_TAG.'Publishing file to GitLab', context: $context);
			$body = ['branch' => $project->ref, 'content' => $content, 'commit_message' => $commitMessage];
			$result = $this->api->sendJson(method: self::writeMethod(isUpdate: $isUpdate), path: $filePath, body: $body);
		} catch (GuzzleException $e) {
			throw $this->requestFailure(exception: $e, call: new RepositoryApiCall(action: 'publish', context: $where));
		}

		return [
			'success' => true,
			'is_update' => $isUpdate,
			'file_path' => $result['file_path'] ?? $path,
			'branch' => $result['branch'] ?? $project->ref,
		];
	}//end publishFile()

	/**
	 * Whether a file exists on a ref: a 404 means it does not, any other failure is thrown.
	 *
	 * @param string $filePath The Repository Files API path of the file.
	 * @param string $ref      The branch.
	 *
	 * @return bool
	 *
	 * @throws GuzzleException If the lookup fails other than with a 404.
	 */
	private function fileExists(string $filePath, string $ref): bool {
		try {
			$this->api->getJson(path: $filePath, options: ['query' => ['ref' => $ref]]);
		} catch (RequestException $e) {
			if ($e->getResponse()?->getStatusCode() === 404) {
				return false;
			}

			throw $e;
		}

		return true;
	}//end fileExists()

	/**
	 * The Repository Files API method: PUT updates an existing file, POST creates one.
	 *
	 * @param bool $isUpdate Whether the file exists.
	 *
	 * @return string
	 */
	private static function writeMethod(bool $isUpdate): string {
		if ($isUpdate === true) {
			return 'PUT';
		}

		return 'POST';
	}//end writeMethod()

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
		return $this->api->apiBase();
	}//end getApiBase()
}//end class
