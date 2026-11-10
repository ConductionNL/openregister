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
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * The platform services of the git hub issues API.
 *
 * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-3
 */
final class GitHubIssuesPlatform {
	/**
	 * Bundle the services.
	 *
	 * @param IUserSession    $userSession  Resolves the submitting NC user
	 * @param LoggerInterface $logger       Structured logger
	 * @param ICacheFactory   $cacheFactory Builds the distributed cache for GitHub reads
	 *
	 * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-3
	 */
	public function __construct(
		public readonly IUserSession $userSession,
		public readonly LoggerInterface $logger,
		public readonly ICacheFactory $cacheFactory,
	) {
	}//end __construct()
}//end class
