<?php

/**
 * OpenRegister gdpr entities platform
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

use OCP\IDBConnection;

/**
 * The platform services of the gdpr entities API.
 *
 * @spec openspec/specs/avg-verwerkingsregister/spec.md
 */
final class GdprEntitiesPlatform {
	/**
	 * Bundle the services.
	 *
	 * @param IDBConnection $db Database connection
	 *
	 * @spec openspec/specs/avg-verwerkingsregister/spec.md
	 */
	public function __construct(
		public readonly IDBConnection $db,
	) {
	}//end __construct()
}//end class
