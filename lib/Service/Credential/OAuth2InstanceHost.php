<?php

/**
 * OAuth2InstanceHost — validates and normalises a per-account API host.
 *
 * Mastodon and Bluesky have no single API host: the host belongs to the connected
 * ACCOUNT's own server. Their catalogue entries therefore declare
 * `baseUrlFrom: "instanceBaseUrl"` instead of a fixed `baseUrl`, and the host comes
 * from the credential's own metadata.
 *
 * That MOVES the host-lock rather than removing it, and this class is where the
 * move is paid for. The host is checked once, at mint, and then pinned onto the
 * credential and made immutable, so a credential is locked to one server for its
 * whole life — a narrower statement than a shared catalogue entry can make, because
 * it is per credential rather than per provider.
 *
 * The rules exist for two different reasons. `https`, no userinfo, no query and no
 * fragment keep the resolved URL from being anything other than a host: a userinfo
 * component in particular is how `https://api.example.com@evil.example/` reads as
 * one host to a person and another to a parser. The private-address rules stop the
 * broker being turned into a request forger against the instance's own network —
 * the broker holds credentials and makes outbound calls, which is precisely the
 * shape an SSRF wants.
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

use InvalidArgumentException;

/**
 * Syntactic and network-safety guard for a per-account API host.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) Deliberately static and stateless. This is a
 * security predicate with no collaborators and no configuration; making it injectable
 * would let a caller substitute a version that says yes, which is the one thing it
 * must never be possible to do to a host-lock.
 */
final class OAuth2InstanceHost {
	/**
	 * Host names that always name the instance itself.
	 *
	 * @var array<int, string>
	 */
	private const LOOPBACK_NAMES = ['localhost', 'localhost.localdomain', 'ip6-localhost', 'ip6-loopback'];

	/**
	 * Validate a candidate instance base URL and return its normalised origin.
	 *
	 * @param string $candidate The URL supplied at connect time.
	 *
	 * @return string The normalised origin (`https://host` with no trailing slash).
	 *
	 * @throws InvalidArgumentException When the candidate is not a safe, absolute https origin.
	 *
	 * @spec openspec/changes/credential-oauth2-token-set/specs/credential-oauth2-token-set/spec.md#requirement-a-per-account-host-is-pinned-at-mint-and-immutable-afterwards
	 */
	public static function normalise(string $candidate): string {
		$candidate = trim($candidate);
		if ($candidate === '') {
			throw new InvalidArgumentException(message: 'instance base URL is empty');
		}

		$parts = parse_url($candidate);
		if (is_array($parts) === false) {
			throw new InvalidArgumentException(message: 'instance base URL is not a URL');
		}

		self::assertOriginShape(parts: $parts);

		$host = strtolower((string)($parts['host'] ?? ''));
		self::assertPublicHost(host: $host);

		return 'https://' . $host;
	}//end normalise()

	/**
	 * Reject anything that is not a bare, absolute https origin.
	 *
	 * The scheme and port rules are about transport. The userinfo, path, query and
	 * fragment rules are about ambiguity: `https://api.example.com@evil.example/`
	 * reads as one host to a person and another to a parser, and a base URL carrying
	 * a path would silently prefix every allow-rule the catalogue declares.
	 *
	 * @param array<string, mixed> $parts The parsed URL.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the URL is not a bare https origin.
	 *
	 * @spec openspec/changes/credential-oauth2-token-set/specs/credential-oauth2-token-set/spec.md#requirement-a-per-account-host-is-pinned-at-mint-and-immutable-afterwards
	 */
	private static function assertOriginShape(array $parts): void {
		if (strtolower((string)($parts['scheme'] ?? '')) !== 'https') {
			throw new InvalidArgumentException(message: 'instance base URL must use https');
		}

		if (isset($parts['user']) === true || isset($parts['pass']) === true) {
			throw new InvalidArgumentException(message: 'instance base URL must carry no userinfo');
		}

		if (isset($parts['query']) === true || isset($parts['fragment']) === true) {
			throw new InvalidArgumentException(message: 'instance base URL must carry no query or fragment');
		}

		if (trim((string)($parts['path'] ?? ''), '/') !== '') {
			throw new InvalidArgumentException(message: 'instance base URL must be an origin, with no path');
		}

		$port = ($parts['port'] ?? null);
		if ($port !== null && (int)$port !== 443) {
			throw new InvalidArgumentException(message: 'instance base URL must use the default https port');
		}
	}//end assertOriginShape()

	/**
	 * Reject a host that is not a public, registrable domain name.
	 *
	 * An IP literal is refused outright rather than range-checked. A per-account API
	 * host is somebody's server with a name; accepting a literal would mean accepting
	 * every address the range checks failed to think of, and there is no legitimate
	 * connection that needs one.
	 *
	 * @param string $host The lower-cased host component.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the host is empty, a literal address, or a loopback name.
	 *
	 * @spec openspec/changes/credential-oauth2-token-set/specs/credential-oauth2-token-set/spec.md#requirement-a-per-account-host-is-pinned-at-mint-and-immutable-afterwards
	 */
	private static function assertPublicHost(string $host): void {
		if ($host === '') {
			throw new InvalidArgumentException(message: 'instance base URL carries no host');
		}

		if (in_array($host, self::LOOPBACK_NAMES, true) === true) {
			throw new InvalidArgumentException(message: 'instance base URL may not name the instance itself');
		}

		if (filter_var($host, FILTER_VALIDATE_IP) !== false || str_contains($host, ':') === true) {
			throw new InvalidArgumentException(message: 'instance base URL must name a domain, not an address');
		}

		if (str_contains($host, '.') === false) {
			throw new InvalidArgumentException(message: 'instance base URL must name a registrable domain');
		}

		if (preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $host) !== 1) {
			throw new InvalidArgumentException(message: 'instance base URL host is not a valid domain name');
		}

		if (str_ends_with($host, '.local') === true || str_ends_with($host, '.internal') === true) {
			throw new InvalidArgumentException(message: 'instance base URL may not name a private-network domain');
		}
	}//end assertPublicHost()
}//end class
