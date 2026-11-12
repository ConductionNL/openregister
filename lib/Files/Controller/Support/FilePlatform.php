<?php

/**
 * OpenRegister file platform services
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

namespace OCA\OpenRegister\Files\Controller\Support;

use OCP\EventDispatcher\IEventDispatcher;
use OCP\IL10N;
use OCP\IUserManager;
use OCP\IUserSession;

/**
 * Users, events, the session and translations for the files API.
 *
 * @spec openspec/specs/file-actions/spec.md
 */
final class FilePlatform {
	/**
	 * Bundle the services.
	 *
	 * @param IUserManager     $userManager     The user manager.
	 * @param IEventDispatcher $eventDispatcher The event dispatcher.
	 * @param ?IUserSession    $userSession     The user session, null when absent.
	 * @param ?IL10N           $l10n            The translations, null when absent.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function __construct(
		public readonly IUserManager $userManager,
		public readonly IEventDispatcher $eventDispatcher,
		public readonly ?IUserSession $userSession = null,
		public readonly ?IL10N $l10n = null,
	) {
	}//end __construct()
}//end class
