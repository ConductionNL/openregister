<?php

/**
 * OAuth2ProviderConfig — read access to one provider's `oauth2` catalogue block.
 *
 * The authorization request, the code exchange and the upstream revoke all read
 * the same handful of switches from a provider's `oauth2` block (PKCE, client
 * authentication, scope separator, endpoints). This holds those reads, and the one
 * rule that turns an endpoint into an absolute URL, in one place.
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
 * Typed reads over a provider's `oauth2` block.
 *
 * @spec openspec/specs/credential-broker/spec.md#requirement-the-catalogue-may-describe-an-oauth2-provider
 */
final readonly class OAuth2ProviderConfig {
	/**
	 * The provider's `oauth2` block, or an empty array when it declares none.
	 *
	 * @var array<string, mixed>
	 */
	private array $oauth2;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $provider The catalogue provider entry.
	 *
	 * @return void
	 */
	public function __construct(array $provider) {
		$oauth2 = ($provider['oauth2'] ?? []);
		if (is_array($oauth2) === false) {
			$oauth2 = [];
		}

		$this->oauth2 = $oauth2;
	}//end __construct()

	/**
	 * Whether the provider requires a PKCE S256 challenge.
	 *
	 * @return boolean True for S256.
	 *
	 * @spec exclude catalogue accessor with no behaviour of its own
	 */
	public function usesPkce(): bool {
		return (string)($this->oauth2['pkce'] ?? 'none') === 'S256';
	}//end usesPkce()

	/**
	 * Whether the client authenticates with HTTP basic auth at the token endpoint.
	 *
	 * @return boolean True for client_secret_basic.
	 *
	 * @spec exclude catalogue accessor with no behaviour of its own
	 */
	public function usesBasicClientAuth(): bool {
		return (string)($this->oauth2['clientAuth'] ?? '') === 'client_secret_basic';
	}//end usesBasicClientAuth()

	/**
	 * The separator the provider joins scopes with.
	 *
	 * @return string The separator.
	 *
	 * @spec exclude catalogue accessor with no behaviour of its own
	 */
	public function scopeSeparator(): string {
		return (string)($this->oauth2['scopeSeparator'] ?? ' ');
	}//end scopeSeparator()

	/**
	 * Extra query parameters the authorization request carries.
	 *
	 * @return array<string, mixed> The parameters, or an empty array.
	 *
	 * @spec exclude catalogue accessor with no behaviour of its own
	 */
	public function authorizationParameters(): array {
		$extra = ($this->oauth2['authorizationParameters'] ?? []);
		if (is_array($extra) === false) {
			return [];
		}

		return $extra;
	}//end authorizationParameters()

	/**
	 * Whether the block names the endpoint at all.
	 *
	 * @param string $key The endpoint key.
	 *
	 * @return boolean True when the key is present and not null.
	 *
	 * @spec exclude catalogue accessor with no behaviour of its own
	 */
	public function declares(string $key): bool {
		return ($this->oauth2[$key] ?? null) !== null;
	}//end declares()

	/**
	 * Resolve one of the provider's endpoints to an absolute URL.
	 *
	 * @param string $key The endpoint key.
	 * @param string|null $instanceBaseUrl The pinned host, for a per-instance provider.
	 *
	 * @return string The absolute endpoint URL.
	 *
	 * @throws InvalidArgumentException When the endpoint is absent or its host cannot be resolved.
	 *
	 * @spec openspec/specs/credential-broker/spec.md#requirement-the-catalogue-may-describe-an-oauth2-provider
	 */
	public function endpoint(string $key, ?string $instanceBaseUrl): string {
		$endpoint = trim((string)($this->oauth2[$key] ?? ''));
		if ($endpoint === '') {
			throw new InvalidArgumentException(message: 'the catalogue names no ' . $key . ' for this provider');
		}

		if (($this->oauth2['endpointsRelativeToInstance'] ?? false) !== true) {
			return $endpoint;
		}

		return OAuth2InstanceHost::normalise(candidate: (string)$instanceBaseUrl) . '/' . ltrim($endpoint, '/');
	}//end endpoint()
}//end class
