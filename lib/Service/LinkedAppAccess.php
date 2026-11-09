<?php

/**
 * How a link service reaches the Nextcloud app it links objects to.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
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

namespace OCA\OpenRegister\Service;

use OCP\IDBConnection;
use OCP\IUserManager;
use Psr\Container\ContainerInterface;

/**
 * How a link service reaches the Nextcloud app it links objects to: whether the
 * app is enabled for the current user (runtime), the app's own services
 * (container), the app's tables (db), and the users its records name (users).
 *
 * ADR-011 Rule 4 constructor aggregate for the Mail, Forms and Deck link services.
 *
 * @spec openspec/specs/generic-integrations/spec.md
 */
final class LinkedAppAccess {
	/**
	 * Bundle the collaborators.
	 *
	 * @param LinkServiceRuntime $runtime   App manager, active session and logger.
	 * @param ContainerInterface $container Container the linked app's services are resolved from.
	 * @param IDBConnection      $db        Connection for reads on the linked app's tables.
	 * @param IUserManager       $users     User lookup for the people the linked app's records name.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function __construct(
		public readonly LinkServiceRuntime $runtime,
		public readonly ContainerInterface $container,
		public readonly IDBConnection $db,
		public readonly IUserManager $users,
	) {
	}//end __construct()
}//end class
