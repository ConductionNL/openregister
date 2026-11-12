<?php

/**
 * PlaceholderResolvers: builds the placeholder resolver for one user session.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Search
 * @package  OCA\OpenRegister\Search\Service\Search
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search\Service\Search;

use OCP\IUserSession;

/**
 * The object save path builds a resolver for the session it saves in, outside the container.
 * It does that here, so code outside the Search component names only the interface.
 */
final class PlaceholderResolvers {

	/**
	 * The resolver for this session.
	 *
	 * @param IUserSession $userSession The session placeholders such as the current user resolve against.
	 *
	 * @return PlaceholderResolverInterface
	 */
	public static function forSession(IUserSession $userSession): PlaceholderResolverInterface {
		return new PlaceholderResolver(userSession: $userSession);
	}//end forSession()
}//end class
