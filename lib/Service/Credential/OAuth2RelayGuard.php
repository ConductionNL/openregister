<?php

/**
 * OAuth2RelayGuard — decides where a relay is allowed to forward a code.
 *
 * A relay exists because every network except Bluesky matches callback URLs
 * exactly, and a tenant's own domain can never be the callback of a
 * Conduction-owned developer application. So one registered callback on a relay
 * host reads the destination out of the `state` and hands the authorization code on.
 *
 * The relay CANNOT verify the state's signature: the state was signed by the
 * receiving instance with the receiving instance's own key, and giving every tenant
 * the relay's key would make the relay's key the only key on the fleet. This class
 * is the compensating control for that, and its rule is deliberately crude: forward
 * only to an origin an administrator put on the list, and only to this application's
 * own callback path on it.
 *
 * That is enough, because the relay performs no exchange and mints nothing. A forged
 * state buys an attacker one redirect to a host the relay operator already trusts,
 * and that host then refuses the state on signature verification. The property that
 * matters survives whether the relay is honest, compromised, or absent: a code can
 * only be exchanged by the instance that started the flow, holds the PKCE verifier,
 * and signed the state.
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

use OCP\IAppConfig;

/**
 * Administrator-managed allow-list for relayed OAuth2 callbacks.
 */
class OAuth2RelayGuard {
	/**
	 * App-config key holding the newline- or comma-separated list of allowed origins.
	 *
	 * @var string
	 */
	public const CONFIG_KEY = 'oauth2_relay_targets';

	/**
	 * The application id the config lives under.
	 *
	 * @var string
	 */
	private const APP_ID = 'openregister';

	/**
	 * The callback path suffix a relay may forward to. Anything else is refused.
	 *
	 * A SUFFIX rather than a whole path, because Nextcloud's own URL generator
	 * produces different prefixes for a subfolder install and for one with
	 * `index.php` in its URLs. Pinning the whole path would silently refuse every
	 * tenant whose deployment does not look like the relay's own.
	 *
	 * @var string
	 */
	public const CALLBACK_PATH_SUFFIX = '/oauth2/callback';

	/**
	 * The path segment identifying this application, required alongside the suffix.
	 *
	 * @var string
	 */
	private const APP_PATH_SEGMENT = '/openregister/';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig Holds the administrator-managed allow-list.
	 *
	 * @return void
	 */
	public function __construct(private readonly IAppConfig $appConfig) {
	}//end __construct()

	/**
	 * Whether a relay may forward a code to this callback URL.
	 *
	 * Fails CLOSED on everything: an empty allow-list forwards nowhere, so an
	 * instance that was never configured as a relay cannot accidentally act as one.
	 *
	 * @param string $callbackUrl The receiving instance's callback, taken from the state.
	 *
	 * @return boolean True when the URL is an allow-listed origin plus this application's callback path.
	 *
	 * @spec openspec/changes/credential-oauth2-connect-flow/specs/credential-oauth2-connect/spec.md#requirement-a-relay-forwards-a-code-and-never-exchanges-it
	 */
	public function permits(string $callbackUrl): bool {
		$parts = parse_url(trim($callbackUrl));
		if (is_array($parts) === false) {
			return false;
		}

		if (strtolower((string)($parts['scheme'] ?? '')) !== 'https') {
			return false;
		}

		if (isset($parts['user']) === true || isset($parts['pass']) === true) {
			return false;
		}

		$path = rtrim((string)($parts['path'] ?? ''), '/');
		if (str_ends_with($path, self::CALLBACK_PATH_SUFFIX) === false || str_contains($path, self::APP_PATH_SEGMENT) === false) {
			return false;
		}

		$origin = 'https://' . strtolower((string)($parts['host'] ?? ''));
		if (isset($parts['port']) === true) {
			$origin .= ':' . (int)$parts['port'];
		}

		return in_array($origin, $this->allowedOrigins(), true);
	}//end permits()

	/**
	 * The origins an administrator has allow-listed.
	 *
	 * @return array<int, string> The normalised origins.
	 *
	 * @spec openspec/changes/credential-oauth2-connect-flow/specs/credential-oauth2-connect/spec.md#requirement-a-relay-forwards-a-code-and-never-exchanges-it
	 */
	public function allowedOrigins(): array {
		$raw = $this->appConfig->getValueString(self::APP_ID, self::CONFIG_KEY, '');
		$entries = preg_split('/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY);
		if ($entries === false) {
			return [];
		}

		$origins = [];
		foreach ($entries as $entry) {
			$parts = parse_url(rtrim(strtolower(trim($entry)), '/'));
			if (is_array($parts) === false || strtolower((string)($parts['scheme'] ?? '')) !== 'https') {
				continue;
			}

			$host = (string)($parts['host'] ?? '');
			if ($host === '') {
				continue;
			}

			$origin = 'https://' . $host;
			if (isset($parts['port']) === true) {
				$origin .= ':' . (int)$parts['port'];
			}

			$origins[] = $origin;
		}

		return $origins;
	}//end allowedOrigins()
}//end class
