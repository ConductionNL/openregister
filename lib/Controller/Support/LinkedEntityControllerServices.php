<?php

/**
 * OpenRegister LinkedEntityController services
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

use OCA\OpenRegister\Service\LinkedEntityServiceInterface;
use OCP\IGroupManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * The collaborators of LinkedEntityController, wired by DI autowiring.
 *
 * @spec openspec/specs/linked-entity-types/spec.md#requirement-reverse-lookup-across-tables
 */
final class LinkedEntityControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param LinkedEntityServiceInterface $linkedEntityService The linked entity service
	 * @param LoggerInterface $logger Logger
	 * @param IUserSession $userSession Active user session for caller identity
	 * @param IGroupManager $groupManager Group manager for admin checks
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md#requirement-reverse-lookup-across-tables
	 */
	public function __construct(
		public readonly LinkedEntityServiceInterface $linkedEntityService,
		public readonly LoggerInterface $logger,
		public readonly IUserSession $userSession,
		public readonly IGroupManager $groupManager,
	) {
	}//end __construct()
}//end class
