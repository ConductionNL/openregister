<?php

/**
 * OpenRegister ObjectStateController services
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

use OCA\OpenRegister\Service\Object\ArchiveHandlerInterface;
use OCP\IUserSession;

/**
 * The collaborators of ObjectStateController, wired by DI autowiring.
 */
final class ObjectStateControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param ArchiveHandlerInterface $archiveHandler The archive and freeze verbs.
	 * @param IUserSession            $userSession    The user session.
	 */
	public function __construct(
		public readonly ArchiveHandlerInterface $archiveHandler,
		public readonly IUserSession $userSession,
	) {
	}//end __construct()
}//end class
