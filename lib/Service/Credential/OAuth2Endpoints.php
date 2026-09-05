<?php

/**
 * OAuth2Endpoints — the URLs of this instance's own connect surface.
 *
 * Three questions that all end up at `IURLGenerator` but are not the same question,
 * and one of them is a security control rather than a convenience.
 *
 * WHERE DOES A PROVIDER REDIRECT BACK TO. The callback's absolute URL, which is both
 * the value registered with a provider and, inside a signed state, the value that
 * decides whether this instance is receiving or relaying.
 *
 * WHAT IS THIS INSTANCE'S CLIENT IDENTITY. AT Protocol has no client registry: a
 * client IS the JSON document it publishes, and its identifier is that document's own
 * URL. So the metadata endpoint's own address is the client id.
 *
 * WHERE MAY THE FLOW SEND SOMEBODY AFTERWARDS. An attacker-chosen redirect target on
 * a callback is an open redirect, so a proposed return URL is reduced to a PATH on
 * this instance and anything carrying its own scheme or host is discarded in favour
 * of personal settings. The reduced value is then carried inside the signed state, so
 * the callback only ever redirects somewhere the instance itself approved rather than
 * to anything present in the request that came back.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Credential
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

namespace OCA\OpenRegister\Service\Credential;

use OCP\IURLGenerator;

/**
 * Resolves this instance's own callback, client-metadata and return URLs.
 */
class OAuth2Endpoints {
	/**
	 * Constructor.
	 *
	 * @param IURLGenerator $urlGenerator Builds this instance's absolute URLs.
	 *
	 * @return void
	 */
	public function __construct(private readonly IURLGenerator $urlGenerator) {
	}//end __construct()

	/**
	 * This instance's own callback URL.
	 *
	 * @return string The absolute callback URL.
	 *
	 * @spec openspec/changes/credential-oauth2-connect-flow/specs/credential-oauth2-connect/spec.md#requirement-a-relay-forwards-a-code-and-never-exchanges-it
	 */
	public function callbackUrl(): string {
		return $this->urlGenerator->linkToRouteAbsolute('openregister.credentialOauth2.callback');
	}//end callbackUrl()

	/**
	 * This instance's own AT Protocol client identifier, which is its metadata URL.
	 *
	 * @return string The absolute client-metadata URL.
	 *
	 * @spec openspec/changes/credential-oauth2-connect-flow/specs/credential-oauth2-connect/spec.md#requirement-bluesky-is-its-own-client-and-mastodon-registers-per-instance
	 */
	public function clientMetadataUrl(): string {
		return $this->urlGenerator->linkToRouteAbsolute('openregister.credentialOauth2.clientMetadata');
	}//end clientMetadataUrl()

	/**
	 * This instance's own root URL.
	 *
	 * @return string The absolute root URL.
	 *
	 * @spec openspec/changes/credential-oauth2-connect-flow/specs/credential-oauth2-connect/spec.md#requirement-bluesky-is-its-own-client-and-mastodon-registers-per-instance
	 */
	public function instanceUrl(): string {
		return $this->urlGenerator->getAbsoluteURL('/');
	}//end instanceUrl()

	/**
	 * Reduce a proposed return URL to one on this instance.
	 *
	 * @param string $candidate The proposed return URL.
	 *
	 * @return string An absolute URL on this instance.
	 *
	 * @spec openspec/changes/credential-oauth2-connect-flow/specs/credential-oauth2-connect/spec.md#requirement-the-callback-exchanges-the-code-and-mints-a-token-set-credential
	 */
	public function safeReturnUrl(string $candidate): string {
		$fallback = $this->urlGenerator->linkToRouteAbsolute(
			'settings.PersonalSettings.index',
			['section' => 'additional']
		);

		$candidate = trim($candidate);
		if ($candidate === '') {
			return $fallback;
		}

		$parts = parse_url($candidate);
		if (is_array($parts) === false || isset($parts['host']) === true || isset($parts['scheme']) === true) {
			return $fallback;
		}

		$path = (string)($parts['path'] ?? '');
		if (str_starts_with($path, '/') === false) {
			return $fallback;
		}

		return $this->urlGenerator->getAbsoluteURL($path);
	}//end safeReturnUrl()
}//end class
