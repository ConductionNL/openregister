<?php

/**
 * OpenRegister configuration domain
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

use OCA\OpenRegister\Kernel\Contracts\GitHubClientInterface;
use OCA\OpenRegister\Service\ConfigurationServiceInterface;
use OCA\OpenRegister\Service\Configuration\GitLabHandler;
use OCA\OpenRegister\Service\NotificationServiceInterface;

/**
 * The domain services of the configuration API.
 *
 * @spec openspec/specs/data-import-export/spec.md
 */
final class ConfigurationDomain {
	/**
	 * Bundle the services.
	 *
	 * @param ConfigurationServiceInterface  $configurationService Configuration service
	 * @param NotificationServiceInterface   $notificationService  Notification service
	 * @param GitHubClientInterface $githubHandler        GitHub handler
	 * @param GitLabHandler         $gitlabHandler        GitLab handler
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function __construct(
		public readonly ConfigurationServiceInterface $configurationService,
		public readonly NotificationServiceInterface $notificationService,
		public readonly GitHubClientInterface $githubHandler,
		public readonly GitLabHandler $gitlabHandler,
	) {
	}//end __construct()
}//end class
