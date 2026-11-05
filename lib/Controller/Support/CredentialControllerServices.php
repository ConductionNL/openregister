<?php

/**
 * OpenRegister credential controller services
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

/**
 * The service bundles of the credential API.
 *
 * @spec openspec/specs/credential-broker/spec.md
 */
final class CredentialControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param ControllerSession  $session  The session bundle.
	 * @param CredentialVault    $vault    The vault bundle.
	 * @param CredentialTokens   $tokens   The tokens bundle.
	 * @param CredentialPlatform $platform The platform bundle.
	 *
	 * @spec openspec/specs/credential-broker/spec.md
	 */
	public function __construct(
		public readonly ControllerSession $session,
		public readonly CredentialVault $vault,
		public readonly CredentialTokens $tokens,
		public readonly CredentialPlatform $platform,
	) {
	}//end __construct()
}//end class
