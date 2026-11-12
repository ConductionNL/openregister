<?php

/**
 * The token-fetching side of AuthenticationService.
 *
 * The Twig authentication runtime types against this interface; ContractBindings
 * aliases it to AuthenticationService, so it gets the same shared instance.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

/**
 * The token-fetching side of AuthenticationService.
 */
interface AuthenticationTokenFetcherInterface {

	/**
	 * Request an OAuth access token with the given configuration.
	 *
	 * @param array $configuration The OAuth configuration.
	 *
	 * @return string The resulting access token.
	 */
	public function fetchOAuthTokens(array $configuration): string;

	/**
	 * Fetch an access token from DeCOS.
	 *
	 * @param array $configuration The source configuration.
	 *
	 * @return string The access token.
	 */
	public function fetchDecosToken(array $configuration): string;

	/**
	 * Generate a JWT token for authentication.
	 *
	 * @param array $configuration The auth configuration (payload, algorithm, secret).
	 *
	 * @return string The generated JWT token.
	 */
	public function fetchJWTToken(array $configuration): string;
}//end interface
