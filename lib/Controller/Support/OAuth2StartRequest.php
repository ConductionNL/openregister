<?php

/**
 * OpenRegister OAuth2 start request
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
 * Who starts an OAuth2 connection, to which provider, in which scope.
 *
 * @spec openspec/specs/credential-oauth2-connect/spec.md#requirement-starting-a-connection-returns-an-authorization-url-bound-to-the-caller
 */
final readonly class OAuth2StartRequest {
	/**
	 * Bundle the caller with the provider and scope a start names.
	 *
	 * @param string $uid        The initiating user.
	 * @param string $providerId The catalogue provider identifier.
	 * @param string $scope      The requested scope, as sent.
	 *
	 * @spec openspec/specs/credential-oauth2-connect/spec.md#requirement-starting-a-connection-returns-an-authorization-url-bound-to-the-caller
	 */
	public function __construct(
		public string $uid,
		public string $providerId,
		public string $scope,
	) {
	}//end __construct()
}//end class
