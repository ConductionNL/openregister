<?php

/**
 * The git hosts a configuration can be fetched from.
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
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Configuration;

/**
 * The GitHub and GitLab handlers of the configuration service.
 *
 * @spec openspec/specs/data-import-export/spec.md
 */
final class ConfigurationGitHosts {
	/**
	 * Bundle the collaborators.
	 *
	 * @param GitHubHandler $githubHandler GitHub handler for GitHub operations
	 * @param GitLabHandler $gitlabHandler GitLab handler for GitLab operations
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function __construct(
		public readonly GitHubHandler $githubHandler,
		public readonly GitLabHandler $gitlabHandler,
	) {
	}//end __construct()
}//end class
