<?php

/**
 * OpenRegister note accounts
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Files\Service
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

namespace OCA\OpenRegister\Files\Service;

use OCP\IUserManager;
use OCP\IUserSession;

/**
 * The accounts a note service answers for: the current user and the display-name lookup.
 *
 * @spec openspec/specs/file-actions/spec.md
 */
final class NoteAccounts {
	/**
	 * Bundle the collaborators.
	 *
	 * @param IUserSession $userSession User session for current user context.
	 * @param IUserManager $userManager User manager for display name resolution.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function __construct(
		public readonly IUserSession $userSession,
		public readonly IUserManager $userManager,
	) {
	}//end __construct()
}//end class
