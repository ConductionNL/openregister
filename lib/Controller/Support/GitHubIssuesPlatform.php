<?php

/**
 * OpenRegister git hub issues platform
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

use OCP\ICacheFactory;

/**
 * The platform services of the git hub issues API.
 *
 * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-3
 */
final class GitHubIssuesPlatform {
	/**
	 * Bundle the services.
	 *
	 * @param ControllerSession $session      Resolves the submitting NC user; structured logger
	 * @param ICacheFactory     $cacheFactory Builds the distributed cache for GitHub reads
	 *
	 * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-3
	 */
	public function __construct(
		public readonly ControllerSession $session,
		public readonly ICacheFactory $cacheFactory,
	) {
	}//end __construct()
}//end class
