<?php

/**
 * Twig runtime for authentication token functions.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Twig
 * @package  OCA\OpenRegister\Twig
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Twig;

use OCA\OpenRegister\Db\Source;
use OCA\OpenRegister\Service\AuthenticationService;
use Twig\Extension\RuntimeExtensionInterface;

/**
 * Runtime for fetching OAuth, DeCOS, and JWT tokens in Twig templates.
 *
 * @package OCA\OpenRegister\Twig
 */
class AuthenticationRuntime implements RuntimeExtensionInterface {
	/**
	 * Constructor.
	 *
	 * @param AuthenticationService $authService The authentication service
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		private readonly AuthenticationService $authService,
	) {

	}//end __construct()

	/**
	 * Fetch an OAuth token for a source.
	 *
	 * @param Source $source The source to authenticate with
	 *
	 * @return string The OAuth access token
	 *
	 * @throws \GuzzleHttp\Exception\GuzzleException If the request fails.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function oauthToken(Source $source): string {
		// Read getAuthConfig(), not getConfiguration(): Source has no
		// `configuration` property, column or serialized field — only a
		// leftover @method docblock — so the old call threw "configuration is
		// not a valid attribute" out of Entity::__call and every templated
		// token answered 500. The credential map that the auth service reads
		// (grant_type, tokenUrl and friends) is authConfig, flat, which is the
		// shape every other reader in the app already uses.
		$authConfig = ($source->getAuthConfig() ?? []);

		return $this->authService->fetchOAuthTokens($authConfig);
	}//end oauthToken()

	/**
	 * Fetch a DeCOS token for a source.
	 *
	 * @param Source $source The source to authenticate with
	 *
	 * @return string The DeCOS access token
	 *
	 * @throws \GuzzleHttp\Exception\GuzzleException If the request fails.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function decosToken(Source $source): string {
		// Read getAuthConfig(), not getConfiguration(): Source has no
		// `configuration` property, column or serialized field — only a
		// leftover @method docblock — so the old call threw "configuration is
		// not a valid attribute" out of Entity::__call and every templated
		// token answered 500. The credential map that the auth service reads
		// (grant_type, tokenUrl and friends) is authConfig, flat, which is the
		// shape every other reader in the app already uses.
		$authConfig = ($source->getAuthConfig() ?? []);

		return $this->authService->fetchDecosToken($authConfig);
	}//end decosToken()

	/**
	 * Fetch a JWT token for a source.
	 *
	 * @param Source $source The source to authenticate with
	 *
	 * @return string The signed JWT token
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function jwtToken(Source $source): string {
		// Read getAuthConfig(), not getConfiguration(): Source has no
		// `configuration` property, column or serialized field — only a
		// leftover @method docblock — so the old call threw "configuration is
		// not a valid attribute" out of Entity::__call and every templated
		// token answered 500. The credential map that the auth service reads
		// (grant_type, tokenUrl and friends) is authConfig, flat, which is the
		// shape every other reader in the app already uses.
		$authConfig = ($source->getAuthConfig() ?? []);

		return $this->authService->fetchJWTToken($authConfig);
	}//end jwtToken()
}//end class
