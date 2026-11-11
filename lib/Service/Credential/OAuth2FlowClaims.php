<?php

/**
 * OAuth2FlowClaims — read access to the claims one connect flow carries.
 *
 * The connect start assembles a small claims array (provider, user, scope, host,
 * client, scopes, apps) that is signed into the state and read back at the
 * callback. Every reader of those claims used to carry its own copy of the same
 * coercions; this is the one place they live, so the connect service and the
 * instance-client registrar cannot drift apart on what a claim means.
 *
 * It only reads. It validates nothing beyond what the readers did before: the
 * claims were signed by this app, and the host is re-normalised on every read
 * because {@see OAuth2InstanceHost} is the host rule, not this class.
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

/**
 * Typed reads over a connect flow's claims array.
 *
 * @spec openspec/specs/credential-oauth2-connect/spec.md#requirement-starting-a-connection-returns-an-authorization-url-bound-to-the-caller
 */
final readonly class OAuth2FlowClaims {
	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $claims The flow's claims.
	 *
	 * @return void
	 */
	public function __construct(
		private array $claims,
	) {
	}//end __construct()

	/**
	 * A claim as a string, or the default when it is absent.
	 *
	 * @param string $key The claim key.
	 * @param string $default The value for an absent claim.
	 *
	 * @return string The claim value.
	 *
	 * @spec exclude private claims accessor with no behaviour of its own
	 */
	public function string(string $key, string $default=''): string {
		return (string)($this->claims[$key] ?? $default);
	}//end string()

	/**
	 * A claim as a trimmed string.
	 *
	 * @param string $key The claim key.
	 *
	 * @return string The trimmed claim value, or an empty string.
	 *
	 * @spec exclude private claims accessor with no behaviour of its own
	 */
	public function trimmed(string $key): string {
		return trim($this->string(key: $key));
	}//end trimmed()

	/**
	 * The owning organisation, for an organisation-scoped connection.
	 *
	 * @return string|null The organisation UUID, or null.
	 *
	 * @spec exclude private claims accessor with no behaviour of its own
	 */
	public function organisation(): ?string {
		return ($this->claims['o'] ?? null);
	}//end organisation()

	/**
	 * The already-validated pinned host a flow carries, or null.
	 *
	 * @return string|null The normalised origin, or null.
	 *
	 * @throws \InvalidArgumentException When the host is not a public origin.
	 *
	 * @spec exclude claims accessor; the host rules live in OAuth2InstanceHost
	 */
	public function pinnedHost(): ?string {
		$host = $this->trimmed(key: 'h');
		if ($host === '') {
			return null;
		}

		return OAuth2InstanceHost::normalise(candidate: $host);
	}//end pinnedHost()

	/**
	 * The scopes a flow asked for, falling back to the provider's declared defaults.
	 *
	 * @param array<string, mixed> $provider The catalogue provider entry.
	 *
	 * @return array<int, string> The scopes.
	 *
	 * @spec exclude claims accessor with no behaviour of its own
	 */
	public function scopes(array $provider): array {
		$scopes = ($this->claims['sc'] ?? null);
		if (is_array($scopes) === false || $scopes === []) {
			$scopes = ($provider['oauth2']['defaultScopes'] ?? []);
		}

		if (is_array($scopes) === false) {
			return [];
		}

		return array_values(array_map('strval', $scopes));
	}//end scopes()

	/**
	 * The app ids a new connection is granted to.
	 *
	 * OPENREGISTER IS ALWAYS ON THE LIST, and this is not a courtesy to itself.
	 * It is the app that reads the connected account's identity right after the
	 * mint, and that call goes through request(), whose allowedApps guard would
	 * otherwise deny it. Leaving it off would not produce an error a person sees:
	 * it would produce a connection that never learns whose account it holds, and
	 * a panel that says "not connected yet" beside a live token forever. It buys
	 * the app no reach the entry's own allow-rules do not already permit. The
	 * daily sweep is unaffected either way — it renews through the custody leaf
	 * and the token endpoint directly, never through the proxy.
	 *
	 * @return array<int, string> The app ids.
	 *
	 * @spec exclude claims accessor with no behaviour of its own
	 */
	public function allowedApps(): array {
		$apps = ($this->claims['a'] ?? []);
		if (is_array($apps) === false) {
			$apps = [];
		}

		$apps = array_map('strval', $apps);
		if (in_array('openregister', $apps, true) === false) {
			$apps[] = 'openregister';
		}

		return array_values($apps);
	}//end allowedApps()
}//end class
