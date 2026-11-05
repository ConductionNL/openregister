<?php

/**
 * OpenRegister dsar case platform
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

use OCP\IUserSession;

/**
 * The platform services of the dsar case API.
 *
 * @spec openspec/specs/dsar-case-api/spec.md
 */
final class DsarCasePlatform {
	/**
	 * Bundle the services.
	 *
	 * @param IUserSession $userSession Current caller (anonymous rejection).
	 *
	 * @spec openspec/specs/dsar-case-api/spec.md
	 */
	public function __construct(
		public readonly IUserSession $userSession,
	) {
	}//end __construct()
}//end class
