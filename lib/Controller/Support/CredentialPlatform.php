<?php

/**
 * OpenRegister credential platform
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

use OCP\IL10N;

/**
 * The platform services of the credential API.
 *
 * @spec openspec/specs/credential-broker/spec.md
 */
final class CredentialPlatform {
	/**
	 * Bundle the services.
	 *
	 * @param ?IL10N $l10n Translates the provider titles the picker shows.
	 *
	 * @spec openspec/specs/credential-broker/spec.md
	 */
	public function __construct(
		public readonly ?IL10N $l10n = null,
	) {
	}//end __construct()
}//end class
