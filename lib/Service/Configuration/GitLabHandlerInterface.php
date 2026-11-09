<?php

/**
 * GitLabHandlerInterface: what callers outside the component use of GitLabHandler.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Configuration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Configuration;

/**
 * Read projects, branches and configuration files from a GitLab instance.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own.
 */
interface GitLabHandlerInterface {

	/**
	 * Get the configured GitLab API base URL
	 *
	 * @return string GitLab API base URL
	 *
	 * @since 0.2.10
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-non-aggregated-facet-isolation
	 */
	public function getApiBase(): string;

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
	public function getBranches(int $projectId): array;

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
	public function getFileContent(int $projectId, string $path, string $ref = 'main'): array;

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
	public function getProjectByPath(string $namespace, string $project): array;

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
	public function listConfigurationFiles(int $projectId, string $ref = 'main', string $path = ''): array;

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
	public function searchConfigurations(string $search = '', int $page = 1, int $perPage = 30): array;
}//end interface
