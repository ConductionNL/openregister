<?php

/**
 * OpenRegister git hub issues domain
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
use OCA\OpenRegister\Service\Configuration\GitHubGuardsInterface;
use OCA\OpenRegister\Service\Configuration\GitHubRequestValidatorInterface;
use OCA\OpenRegister\Service\Configuration\RateLimiterServiceInterface;

/**
 * The domain services of the git hub issues API.
 *
 * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-3
 */
final class GitHubIssuesDomain {
	/**
	 * Bundle the services.
	 *
	 * @param GitHubClientInterface           $githubHandler Reused HTTP client / token / attribution logic
	 * @param GitHubGuardsInterface           $guards        Policy guards (feature flag, repo allowlist, GET rate limit)
	 * @param GitHubRequestValidatorInterface $validator     Pure-function input validators
	 * @param RateLimiterServiceInterface     $rateLimiter   Cache-backed rate limiter with fail-closed contract
	 *
	 * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-3
	 */
	public function __construct(
		public readonly GitHubClientInterface $githubHandler,
		public readonly GitHubGuardsInterface $guards,
		public readonly GitHubRequestValidatorInterface $validator,
		public readonly RateLimiterServiceInterface $rateLimiter,
	) {
	}//end __construct()
}//end class
