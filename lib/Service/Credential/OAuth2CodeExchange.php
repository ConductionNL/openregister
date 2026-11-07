<?php

/**
 * OAuth2CodeExchange — redeems an authorization code at a provider's token endpoint.
 *
 * The one HTTP exchange of the connect callback, split out of
 * {@see OAuth2ConnectService} so that service decides what is minted while this
 * decides only what the token endpoint is sent and what its answer must look like.
 *
 * A failed exchange throws and writes nothing: the caller has not touched the
 * credential yet, so a refusal leaves no half-made connection behind. Messages name
 * the failure and never quote the provider's answer, which can echo the request.
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

use OCP\Http\Client\IClientService;
use RuntimeException;
use Throwable;

/**
 * Performs the authorization-code exchange.
 *
 * @spec openspec/specs/credential-oauth2-connect/spec.md#requirement-the-callback-exchanges-the-code-and-mints-a-token-set-credential
 */
class OAuth2CodeExchange {
	/**
	 * Constructor.
	 *
	 * @param IClientService $clientService NC HTTP client factory.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IClientService $clientService,
	) {
	}//end __construct()

	/**
	 * Perform the authorization-code exchange.
	 *
	 * @param array<string, mixed> $provider The catalogue provider entry.
	 * @param array{code: string, code_verifier: string, redirect_uri: string} $grant The code, PKCE verifier and callback.
	 * @param array{clientId: string, clientSecret: string|null} $client The resolved client.
	 * @param string|null $instanceBaseUrl The pinned host, when the provider has one.
	 *
	 * @return array<string, mixed> The decoded token response.
	 *
	 * @throws RuntimeException When the exchange fails or the provider returns an error.
	 *
	 * @spec openspec/specs/credential-oauth2-connect/spec.md#requirement-the-callback-exchanges-the-code-and-mints-a-token-set-credential
	 */
	public function redeem(array $provider, array $grant, array $client, ?string $instanceBaseUrl): array {
		$config = new OAuth2ProviderConfig(provider: $provider);
		$options = $this->options(config: $config, grant: $grant, client: $client);

		try {
			$response = $this->clientService->newClient()->post(
				$config->endpoint(key: 'tokenEndpoint', instanceBaseUrl: $instanceBaseUrl),
				$options
			);
			$decoded = json_decode((string)$response->getBody(), true);
		} catch (Throwable $failure) {
			throw new RuntimeException(message: 'token exchange failed: ' . $failure::class, previous: $failure);
		}

		return $this->accepted(decoded: $decoded);
	}//end redeem()

	/**
	 * The request options: the form, and basic auth where the provider wants it.
	 *
	 * @param OAuth2ProviderConfig $config The provider's oauth2 block.
	 * @param array{code: string, code_verifier: string, redirect_uri: string} $grant The code, PKCE verifier and callback.
	 * @param array{clientId: string, clientSecret: string|null} $client The resolved client.
	 *
	 * @return array<string, mixed> The HTTP client options.
	 *
	 * @spec exclude private request builder with no behaviour of its own
	 */
	private function options(OAuth2ProviderConfig $config, array $grant, array $client): array {
		$secret = $client['clientSecret'];
		$basic = ($secret !== null && $config->usesBasicClientAuth() === true);

		$form = [
			'grant_type' => 'authorization_code',
			'code' => $grant['code'],
			'redirect_uri' => $grant['redirect_uri'],
			'client_id' => $client['clientId'],
		];
		if ($config->usesPkce() === true) {
			$form['code_verifier'] = $grant['code_verifier'];
		}

		if ($secret !== null && $basic === false) {
			$form['client_secret'] = $secret;
		}

		$options = ['body' => $form, 'headers' => ['Accept' => 'application/json'], 'timeout' => 20];

		return $this->withBasicAuth(options: $options, client: $client, basic: $basic);
	}//end options()

	/**
	 * Add basic client authentication to the options when the provider wants it.
	 *
	 * @param array<string, mixed> $options The HTTP client options.
	 * @param array{clientId: string, clientSecret: string|null} $client The resolved client.
	 * @param boolean $basic Whether the client authenticates with basic auth.
	 *
	 * @return array<string, mixed> The options.
	 *
	 * @spec exclude private request builder with no behaviour of its own
	 */
	private function withBasicAuth(array $options, array $client, bool $basic): array {
		if ($basic === true) {
			$options['auth'] = [$client['clientId'], $client['clientSecret']];
		}

		return $options;
	}//end withBasicAuth()

	/**
	 * Refuse a token response that is not JSON or names an error.
	 *
	 * @param mixed $decoded The decoded response body.
	 *
	 * @return array<string, mixed> The token response.
	 *
	 * @throws RuntimeException When the body is not JSON or carries an error.
	 *
	 * @spec openspec/specs/credential-oauth2-connect/spec.md#requirement-the-callback-exchanges-the-code-and-mints-a-token-set-credential
	 */
	private function accepted(mixed $decoded): array {
		if (is_array($decoded) === false) {
			throw new RuntimeException(message: 'token endpoint returned a body that is not JSON');
		}

		$error = (string)($decoded['error'] ?? '');
		if ($error !== '') {
			throw new RuntimeException(message: 'token endpoint returned error ' . $error);
		}

		return $decoded;
	}//end accepted()
}//end class
