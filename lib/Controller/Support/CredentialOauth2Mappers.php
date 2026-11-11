<?php

/**
 * OpenRegister credential oauth2 mappers
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

use OCA\OpenRegister\Service\Credential\OAuth2ConnectionRepositoryInterface;

/**
 * The mappers and repositories of the credential oauth2 API.
 *
 * @spec openspec/specs/credential-oauth2-connect/spec.md#requirement-starting-a-connection-returns-an-authorization-url-bound-to-the-caller
 */
final class CredentialOauth2Mappers {
	/**
	 * Bundle the services.
	 *
	 * @param OAuth2ConnectionRepositoryInterface $connections Loads, gates and disables a stored connection.
	 *
	 * @spec openspec/specs/credential-oauth2-connect/spec.md#requirement-starting-a-connection-returns-an-authorization-url-bound-to-the-caller
	 */
	public function __construct(
		public readonly OAuth2ConnectionRepositoryInterface $connections,
	) {
	}//end __construct()
}//end class
