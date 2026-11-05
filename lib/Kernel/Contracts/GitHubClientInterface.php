<?php

/**
 * GitHubClientInterface: the GitHub calls for configuration publishing, sync and issues.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

/**
 * Reads and writes repositories, configuration files, issues and the per-user token on GitHub. Implemented by GitHubHandler.
 */
interface GitHubClientInterface {

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
	 * @throws \GuzzleHttp\Exception\GuzzleException Network errors not covered by structured mapping
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
		string $userId,
	): array;

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
	public function enrichConfigurationDetails(string $owner, string $repo, string $path, string $branch = 'main'): ?array;

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
	public function getBranches(string $owner, string $repo): array;

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
	public function getFileContent(string $owner, string $repo, string $path, string $branch = 'main'): array;

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
	public function getFileSha(string $owner, string $repo, string $path, string $branch = 'main'): ?string;

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
	public function getRepositories(int $page = 1, int $perPage = 100): array;

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
	public function getRepositoryInfo(string $owner, string $repo): array;

	/**
	 * Get user-specific GitHub token
	 *
	 * @param string $userId The user ID
	 *
	 * @return null|string The token or null if not set
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-non-aggregated-facet-isolation
	 */
	public function getUserToken(string $userId): ?string;

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
	public function listConfigurationFiles(string $owner, string $repo, string $branch = 'main', string $path = ''): array;

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
	 * @throws \GuzzleHttp\Exception\GuzzleException When the underlying HTTP client errors in a way not covered by the
	 *                                               rate-limit / 4xx / 5xx mapping (e.g. DNS failure).
	 *
	 * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-1
	 * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-10
	 */
	public function listIssues(
		string $owner,
		string $repo,
		string $state = 'open',
		string $sort = 'reactions-+1',
		int $perPage = 30,
		?array $labels = null,
	): array;

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
	): array;

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
	public function searchConfigurations(string $search = '', int $page = 1, int $perPage = 30): array;

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
	public function setUserToken(?string $token, string $userId): void;

	/**
	 * Validate GitHub token by making a test API request
	 *
	 * @param string|null $userId The user ID (optional, uses current user if not provided)
	 *
	 * @return bool True if token is valid, false otherwise
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-non-aggregated-facet-isolation
	 */
	public function validateToken(?string $userId = null): bool;
}//end interface
