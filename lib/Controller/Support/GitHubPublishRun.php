<?php

/**
 * OpenRegister GitHubPublishRun
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
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

namespace OCA\OpenRegister\Controller\Support;

use OCA\OpenRegister\Db\Configuration;

/**
 * One publish of a local configuration to GitHub: the configuration and
 * the location (owner, repo, path, branch, commitMessage) it goes to.
 *
 * It never leaves ConfigurationController, which builds it.
 *
 * @spec openspec/specs/data-import-export/spec.md
 */
final class GitHubPublishRun {
	/**
	 * Start the run.
	 *
	 * @param Configuration         $configuration The configuration
	 * @param array<string, string> $params        The publishing location
	 */
	public function __construct(
		public readonly Configuration $configuration,
		public readonly array $params,
	) {
	}//end __construct()
}//end class
