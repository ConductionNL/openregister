<?php

/**
 * OpenRegister contact search controller services
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Search\Controller\Support
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

namespace OCA\OpenRegister\Search\Controller\Support;

use OCA\OpenRegister\Search\Service\Integration\ContactSearch;
use OCP\IUserSession;

/**
 * The collaborators of ContactSearchController, wired by DI autowiring.
 *
 * @spec openspec/changes/contacts-leaf-cases-panel/specs/integration-contacts/spec.md#requirement-the-contacts-leaf-offers-a-name-search
 */
final class ContactSearchControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param ContactSearch $search      The search over the user's readable address books.
	 * @param IUserSession  $userSession The signed-in user.
	 *
	 * @spec openspec/changes/contacts-leaf-cases-panel/specs/integration-contacts/spec.md#requirement-the-contacts-leaf-offers-a-name-search
	 */
	public function __construct(
		public readonly ContactSearch $search,
		public readonly IUserSession $userSession,
	) {
	}//end __construct()
}//end class
