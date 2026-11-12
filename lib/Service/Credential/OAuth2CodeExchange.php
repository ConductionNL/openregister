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
	 * @param OAuth2CodeRedemption $redemption The provider, grant, client and pinned host.
	 *
	 * @return array<string, mixed> The decoded token response.
	 *
	 * @throws RuntimeException When the exchange fails or the provider returns an error.
	 *
	 * @spec openspec/specs/credential-oauth2-connect/spec.md#requirement-the-callback-exchanges-the-code-and-mints-a-token-set-credential
	 */
	public function redeem(OAuth2CodeRedemption $redemption): array {
		$config = new OAuth2ProviderConfig(provider: $redemption->provider);
		$options = $this->options(config: $config, redemption: $redemption);

		try {
			$response = $this->clientService->newClient()->post(
				$config->endpoint(key: 'tokenEndpoint', instanceBaseUrl: $redemption->instanceBaseUrl),
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
	 * @param OAuth2ProviderConfig $config     The provider's oauth2 block.
	 * @param OAuth2CodeRedemption $redemption The grant and the resolved client.
	 *
	 * @return array<string, mixed> The HTTP client options.
	 *
	 * @spec exclude private request builder with no behaviour of its own
	 */
	private function options(OAuth2ProviderConfig $config, OAuth2CodeRedemption $redemption): array {
		$client = $redemption->client;
		$secret = $client['clientSecret'];
		$basic  = ($secret !== null && $config->usesBasicClientAuth() === true);

		$form = $this->codeForm(config: $config, redemption: $redemption);
		if ($secret !== null && $basic === false) {
			$form['client_secret'] = $secret;
		}

		$options = ['body' => $form, 'headers' => ['Accept' => 'application/json'], 'timeout' => 20];
		if ($basic === true) {
			$options['auth'] = [$client['clientId'], $secret];
		}

		return $options;
	}//end options()

	/**
	 * The authorization-code grant form: the code, the redirect URI, the client id and, with PKCE, the verifier.
	 *
	 * @param OAuth2ProviderConfig $config     The provider's OAuth2 behaviour.
	 * @param OAuth2CodeRedemption $redemption The grant and the client redeeming it.
	 *
	 * @return array<string, mixed> The form, without the client secret.
	 */
	private function codeForm(OAuth2ProviderConfig $config, OAuth2CodeRedemption $redemption): array {
		$form = ['grant_type' => 'authorization_code', 'code' => $redemption->grant->code, 'redirect_uri' => $redemption->grant->redirectUri];
		$form['client_id'] = $redemption->client['clientId'];
		if ($config->usesPkce() === true) {
			$form['code_verifier'] = $redemption->grant->verifier;
		}

		return $form;
	}//end codeForm()

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
