<?php

/**
 * A file in a GitHub repository branch.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Configuration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/faceting-configuration/spec.md#requirement-non-aggregated-facet-isolation
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Configuration;

/**
 * The owner, repository, path and branch of one file, and its Contents API path.
 *
 * @spec openspec/specs/faceting-configuration/spec.md#requirement-non-aggregated-facet-isolation
 */
final class GitHubFileLocation
{

	/**
	 * Constructor.
	 *
	 * @param string $owner  The repository owner.
	 * @param string $repo   The repository name.
	 * @param string $path   The file path in the repository.
	 * @param string $branch The branch.
	 */
	public function __construct(
		public readonly string $owner,
		public readonly string $repo,
		public readonly string $path,
		public readonly string $branch,
	) {
	}//end __construct()

	/**
	 * The repository as `owner/repo`.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-non-aggregated-facet-isolation
	 */
	public function repoPath(): string {
		return "{$this->owner}/{$this->repo}";
	}//end repoPath()

	/**
	 * The path with each segment URL-encoded and the slashes kept.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-non-aggregated-facet-isolation
	 */
	public function encodedPath(): string {
		return implode('/', array_map('rawurlencode', explode('/', $this->path)));
	}//end encodedPath()
}//end class
