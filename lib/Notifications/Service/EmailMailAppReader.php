<?php

/**
 * What reading the Nextcloud Mail app's messages as the current user takes.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Notifications\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service;

use OCP\App\IAppManager;
use OCP\IDBConnection;
use OCP\IUserSession;

/**
 * What reading the Nextcloud Mail app's messages as the current user takes.
 *
 * @spec openspec/specs/mail-sidebar/spec.md
 */
final readonly class EmailMailAppReader {
	/**
	 * Bundle the services.
	 *
	 * @param IAppManager $appManager App manager for checking mail app availability.
	 * @param IDBConnection $db Database connection for querying mail tables.
	 * @param IUserSession $userSession User session for getting current user.
	 */
	public function __construct(
		public IAppManager $appManager,
		public IDBConnection $db,
		public IUserSession $userSession,
	) {
	}//end __construct()
}//end class
