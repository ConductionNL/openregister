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

use OCA\OpenRegister\Service\Credential\OAuth2ConnectServiceInterface;
use OCA\OpenRegister\Service\Credential\OAuth2EndpointsInterface;
use OCA\OpenRegister\Service\Credential\OAuth2RelayGuardInterface;
use OCA\OpenRegister\Service\Credential\OAuth2StateServiceInterface;

/**
 * The domain services of the credential oauth2 API.
 *
 * @spec openspec/specs/credential-oauth2-connect/spec.md#requirement-starting-a-connection-returns-an-authorization-url-bound-to-the-caller
 */
final class CredentialOauth2Domain {
	/**
	 * Bundle the services.
	 *
	 * @param OAuth2ConnectServiceInterface $connect   Builds authorization URLs and redeems codes.
	 * @param OAuth2StateServiceInterface   $states    Issues and redeems the signed state.
	 * @param OAuth2RelayGuardInterface     $relay     Decides where a relay may forward.
	 * @param OAuth2EndpointsInterface      $endpoints This instance's own callback, metadata and return URLs.
	 *
	 * @spec openspec/specs/credential-oauth2-connect/spec.md#requirement-starting-a-connection-returns-an-authorization-url-bound-to-the-caller
	 */
	public function __construct(
		public readonly OAuth2ConnectServiceInterface $connect,
		public readonly OAuth2StateServiceInterface $states,
		public readonly OAuth2RelayGuardInterface $relay,
		public readonly OAuth2EndpointsInterface $endpoints,
	) {
	}//end __construct()
}//end class
