<?php

/**
 * OpenRegister credential oauth2 domain
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

use OCA\OpenRegister\Service\Credential\OAuth2ConnectService;
use OCA\OpenRegister\Service\Credential\OAuth2Endpoints;
use OCA\OpenRegister\Service\Credential\OAuth2RelayGuard;
use OCA\OpenRegister\Service\Credential\OAuth2StateService;

/**
 * The domain services of the credential oauth2 API.
 *
 * @spec openspec/specs/credential-oauth2-connect/spec.md#requirement-starting-a-connection-returns-an-authorization-url-bound-to-the-caller
 */
final class CredentialOauth2Domain {
	/**
	 * Bundle the services.
	 *
	 * @param OAuth2ConnectService $connect   Builds authorization URLs and redeems codes.
	 * @param OAuth2StateService   $states    Issues and redeems the signed state.
	 * @param OAuth2RelayGuard     $relay     Decides where a relay may forward.
	 * @param OAuth2Endpoints      $endpoints This instance's own callback, metadata and return URLs.
	 *
	 * @spec openspec/specs/credential-oauth2-connect/spec.md#requirement-starting-a-connection-returns-an-authorization-url-bound-to-the-caller
	 */
	public function __construct(
		public readonly OAuth2ConnectService $connect,
		public readonly OAuth2StateService $states,
		public readonly OAuth2RelayGuard $relay,
		public readonly OAuth2Endpoints $endpoints,
	) {
	}//end __construct()
}//end class
