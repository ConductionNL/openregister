<?php

/**
 * The anti-SSRF check every server-side URL fetcher runs before a request.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Security
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Security;

use InvalidArgumentException;

/**
 * Asserts that a user-supplied URL is safe to fetch server-side.
 *
 * SecurityService::assertSafeFetchUrl() delegates here; callers outside
 * lib/Service call this class so they do not name SecurityService.
 *
 * @spec openspec/specs/apphost-store-plane/spec.md#requirement-every-outbound-registry-url-must-be-ssrf-guarded-and-must-not-follow-redirects
 */
final class SafeFetchUrl {

	/**
	 * Assert that a user-supplied URL is safe to fetch server-side (anti-SSRF).
	 *
	 * Validates scheme (http/https only) and resolves EVERY A and AAAA record
	 * for the host, rejecting the request if ANY resolved address is loopback,
	 * private (RFC-1918 / ULA), or reserved/link-local. This is the single
	 * shared guard that every server-side URL fetcher in the app MUST call
	 * before issuing a request (UploadService, FilePropertyHandler,
	 * ConfigurationController, GitHub/GitLab handlers, webhooks).
	 *
	 * Callers that follow redirects MUST re-validate every redirect target,
	 * or disable redirect following entirely, because this check is a
	 * point-in-time DNS resolution and does not pin the connection IP.
	 *
	 * @param string $url The user-supplied URL to validate.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the URL is malformed or resolves to a non-public address.
	 *
	 * @spec openspec/specs/apphost-store-plane/spec.md#requirement-every-outbound-registry-url-must-be-ssrf-guarded-and-must-not-follow-redirects
	 */
	public static function assert(string $url): void {
		$guard = new OutboundAddressGuard();
		$ips   = $guard->addressesOf(host: self::fetchableHost(url: $url));
		if (empty($ips) === true) {
			// Fail closed: a host we cannot resolve must not be fetched.
			throw new InvalidArgumentException('URL host could not be resolved.');
		}

		foreach ($ips as $ip) {
			if ($guard->isInternal(ipAddress: $ip) === true) {
				throw new InvalidArgumentException('URL resolves to a non-public address and cannot be fetched.');
			}
		}
	}//end assert()

	/**
	 * The host of a URL that is well-formed and http(s), or a refusal.
	 *
	 * @param string $url The user-supplied URL.
	 *
	 * @return string The host to resolve.
	 *
	 * @throws InvalidArgumentException When the URL is malformed or not http(s).
	 */
	private static function fetchableHost(string $url): string {
		$parts = parse_url($url);
		if ($parts === false || empty($parts['scheme']) === true || empty($parts['host']) === true) {
			throw new InvalidArgumentException('Invalid URL.');
		}

		if (in_array(strtolower($parts['scheme']), ['http', 'https'], true) === false) {
			throw new InvalidArgumentException('Only http and https URLs are allowed.');
		}

		return $parts['host'];
	}//end fetchableHost()
}//end class
