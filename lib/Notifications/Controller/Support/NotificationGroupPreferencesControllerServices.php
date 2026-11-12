<?php

/**
 * The services of the group notification preference API.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Controller
 * @package  OCA\OpenRegister\Notifications\Controller\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Controller\Support;

use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\NotificationPreferencesInterface;
use OCP\IUserSession;

/**
 * The preference resolver, the schemas whose notifications a group may default, and the caller.
 */
final readonly class NotificationGroupPreferencesControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param NotificationPreferencesInterface $preferenceService The three-layer preference resolver.
	 * @param SchemaMapperInterface            $schemaMapper      Enumerates the notifications a group may hold defaults for.
	 * @param IUserSession                     $userSession       Current-user session.
	 */
	public function __construct(
		public NotificationPreferencesInterface $preferenceService,
		public SchemaMapperInterface $schemaMapper,
		public IUserSession $userSession,
	) {
	}//end __construct()
}//end class
