<?php

/**
 * OAuth2EndpointsInterface: what callers outside the component use of OAuth2Endpoints.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Credential
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Credential;

/**
 * The OAuth2 URLs the OAuth2 endpoints hand out.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own.
 */
interface OAuth2EndpointsInterface {

	/**
	 * This instance's own callback URL.
	 *
	 * @return string The absolute callback URL.
	 *
	 * @spec openspec/specs/credential-oauth2-connect/spec.md#requirement-a-relay-forwards-a-code-and-never-exchanges-it
	 */
	public function callbackUrl(): string;

	/**
	 * This instance's own AT Protocol client identifier, which is its metadata URL.
	 *
	 * @return string The absolute client-metadata URL.
	 *
	 * @spec openspec/specs/credential-oauth2-connect/spec.md#requirement-bluesky-is-its-own-client-and-mastodon-registers-per-instance
	 */
	public function clientMetadataUrl(): string;

	/**
	 * This instance's own root URL.
	 *
	 * @return string The absolute root URL.
	 *
	 * @spec openspec/specs/credential-oauth2-connect/spec.md#requirement-bluesky-is-its-own-client-and-mastodon-registers-per-instance
	 */
	public function instanceUrl(): string;

	/**
	 * Reduce a proposed return URL to one on this instance.
	 *
	 * @param string $candidate The proposed return URL.
	 *
	 * @return string An absolute URL on this instance.
	 *
	 * @spec openspec/specs/credential-oauth2-connect/spec.md#requirement-the-callback-exchanges-the-code-and-mints-a-token-set-credential
	 */
	public function safeReturnUrl(string $candidate): string;
}//end interface
