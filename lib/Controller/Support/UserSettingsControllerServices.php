<?php

/**
 * OpenRegister user-settings controller services
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
use Psr\Log\LoggerInterface;

/**
 * The services of UserSettingsController, bundled so its constructor stays small.
 *
 * @spec openspec/specs/auth-system/spec.md
 */
final class UserSettingsControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param GitHubClientInterface $gitHubService GitHub service
	 * @param LoggerInterface       $logger        Logger
	 */
	public function __construct(
		public readonly GitHubClientInterface $gitHubService,
		public readonly LoggerInterface $logger,
	) {
	}//end __construct()
}//end class
