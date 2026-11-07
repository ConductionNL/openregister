<?php

/**
 * OpenRegister credential oauth2 controller services
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
 * The service bundles of the credential oauth2 API.
 *
 * @spec openspec/specs/credential-oauth2-connect/spec.md#requirement-starting-a-connection-returns-an-authorization-url-bound-to-the-caller
 */
final class CredentialOauth2ControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param CredentialOauth2Mappers  $mappers  The mappers bundle.
	 * @param CredentialOauth2Domain   $domain   The domain bundle.
	 * @param AccessLinkPlatform       $platform The platform bundle.
	 *
	 * @spec openspec/specs/credential-oauth2-connect/spec.md#requirement-starting-a-connection-returns-an-authorization-url-bound-to-the-caller
	 */
	public function __construct(
		public readonly CredentialOauth2Mappers $mappers,
		public readonly CredentialOauth2Domain $domain,
		public readonly AccessLinkPlatform $platform,
	) {
	}//end __construct()
}//end class
