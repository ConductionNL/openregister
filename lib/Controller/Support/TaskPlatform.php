<?php

/**
 * OpenRegister task platform
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

use OCP\IGroupManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * The platform services of the task API.
 *
 * @spec openspec/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
 */
final class TaskPlatform {
	/**
	 * Bundle the services.
	 *
	 * @param IUserSession     $userSession  Names the acting identity.
	 * @param ?LoggerInterface $logger       Where an unexpected failure's
	 * @param ?IGroupManager   $groupManager Resolves the caller's groups
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
	 */
	public function __construct(
		public readonly IUserSession $userSession,
		public readonly ?LoggerInterface $logger = null,
		public readonly ?IGroupManager $groupManager = null,
	) {
	}//end __construct()
}//end class
