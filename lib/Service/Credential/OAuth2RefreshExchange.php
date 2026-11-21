<?php

/**
 * OAuth2RefreshExchange — spends a refresh token at a provider's token endpoint.
 *
 * The HTTP half of {@see OAuth2RefreshService}, split out so that service owns the
 * lock, the custody write and the relink bookkeeping while this owns only what the
 * token endpoint is sent and how its answer is classified.
 *
 * The classification is the whole point. `invalid_grant` — whether the provider
 * returns it in a body or the HTTP client raises it inside a 400 — means the grant
 * itself is gone and surfaces as {@see CredentialRelinkRequiredException}; every
 * other failure is transient and surfaces as {@see CredentialUpstreamException}.
 * Getting that wrong either relinks every connection during a provider outage or
 * retries a revoked grant forever. No message quotes the provider's answer.
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
use OCP\Http\Client\IClientService;
use Throwable;

/**
 * Performs and classifies the refresh-token exchange.
 *
 * @spec openspec/specs/credential-oauth2-token-set/spec.md#requirement-a-refresh-runs-under-a-per-credential-lock-and-rotates-atomically
 */
class OAuth2RefreshExchange {
	/**
	 * The provider error code that means the grant itself is gone.
	 *
	 * @var string
	 */
	public const TERMINAL_ERROR = 'invalid_grant';

	/**
	 * Constructor.
	 *
	 * @param OAuth2ClientResolver $clients Resolves the client id and secret for the token request.
	 * @param IClientService $clientService NC HTTP client factory for the token request.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly OAuth2ClientResolver $clients,
		private readonly IClientService $clientService,
	) {
	}//end __construct()

	/**
	 * Spend a refresh token for a refresh target and return the decoded token response.
	 *
	 * @param OAuth2RefreshTarget $target       The credential's data, its provider entry, its UUID and the acting user.
	 * @param string              $refreshToken The refresh token being spent.
	 *
	 * @return array<string, mixed> The decoded token response.
	 *
	 * @throws CredentialAccessDeniedException When the catalogue or the credential names no usable token endpoint.
	 * @throws CredentialRelinkRequiredException When the provider answered `invalid_grant`.
	 * @throws CredentialUpstreamException On any other failure.
	 *
	 * @spec openspec/specs/credential-oauth2-token-set/spec.md#requirement-an-invalid-grant-moves-the-credential-to-relink-needed-and-fails-closed
	 */
	public function redeemFor(OAuth2RefreshTarget $target, #[\SensitiveParameter] string $refreshToken): array {
		$credential = $target->credential;
		$oauth2 = $this->oauth2Of(provider: $target->provider);
		$client = $this->clients->resolve(credential: $credential, provider: (string)($credential['provider'] ?? ''), actingUserId: $target->actingUserId);
		$endpoint = $this->tokenEndpoint(oauth2: $oauth2, credential: $credential, credentialId: $target->credentialId);
		$form = $this->refreshForm(oauth2: $oauth2, refreshToken: $refreshToken, client: $client);

		return $this->accepted(decoded: $this->post(endpoint: $endpoint, form: $form, client: $client));
	}//end redeemFor()

	/**
	 * The provider entry's `oauth2` block, or an empty block when it has none.
	 *
	 * @param array<string, mixed> $provider The catalogue provider entry.
	 *
	 * @return array<string, mixed> The block.
	 *
	 * @spec exclude private catalogue accessor with no behaviour of its own
	 */
	private function oauth2Of(array $provider): array {
		$oauth2 = ($provider['oauth2'] ?? []);
		if (is_array($oauth2) === false) {
			return [];
		}

		return $oauth2;
	}//end oauth2Of()

	/**
	 * Build the refresh request's form body.
	 *
	 * Meta has no refresh_token grant: a short-lived token is traded for a
	 * long-lived one against the same endpoint, with the CURRENT token in a
	 * differently named field. Naming the grant in the catalogue is what lets
	 * one code path serve both shapes.
	 *
	 * @param array<string, mixed> $oauth2 The provider entry's `oauth2` block.
	 * @param string $refreshToken The refresh token being spent.
	 * @param array{clientId: string, clientSecret: string|null} $client The resolved client.
	 *
	 * @return array<string, string> The form fields.
	 *
	 * @spec openspec/specs/credential-oauth2-token-set/spec.md#requirement-a-refresh-runs-under-a-per-credential-lock-and-rotates-atomically
	 */
	private function refreshForm(array $oauth2, #[\SensitiveParameter] string $refreshToken, array $client): array {
		$form = ['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken];
		if ((string)($oauth2['refreshGrant'] ?? 'refresh_token') === 'fb_exchange_token') {
			$form = ['grant_type' => 'fb_exchange_token', 'fb_exchange_token' => $refreshToken];
		}

		$form['client_id'] = $client['clientId'];
		if ($client['clientSecret'] !== null && (string)($oauth2['clientAuth'] ?? '') !== 'client_secret_basic') {
			$form['client_secret'] = $client['clientSecret'];
		}

		return $form;
	}//end refreshForm()

	/**
	 * Resolve the absolute token endpoint for a provider.
	 *
	 * @param array<string, mixed> $oauth2 The provider entry's `oauth2` block.
	 * @param array<string, mixed> $credential The credential object's serialised data.
	 * @param string $credentialId The credential UUID.
	 *
	 * @return string The absolute token endpoint URL.
	 *
	 * @throws CredentialAccessDeniedException When the catalogue names no usable token endpoint.
	 *
	 * @spec openspec/specs/credential-broker/spec.md#requirement-the-catalogue-may-describe-an-oauth2-provider
	 */
	private function tokenEndpoint(array $oauth2, array $credential, string $credentialId): string {
		$endpoint = trim((string)($oauth2['tokenEndpoint'] ?? ''));
		if ($endpoint === '') {
			throw new CredentialAccessDeniedException(message: 'no token endpoint in the catalogue for credential ' . $credentialId);
		}

		if (($oauth2['endpointsRelativeToInstance'] ?? false) !== true) {
			return $endpoint;
		}

		return $this->instanceOrigin(credential: $credential, credentialId: $credentialId) . '/' . ltrim($endpoint, '/');
	}//end tokenEndpoint()

	/**
	 * The credential's pinned host, re-validated.
	 *
	 * @param array<string, mixed> $credential The credential object's serialised data.
	 * @param string $credentialId The credential UUID.
	 *
	 * @return string The normalised origin.
	 *
	 * @throws CredentialAccessDeniedException When the credential has no usable instance host.
	 *
	 * @spec openspec/specs/credential-oauth2-token-set/spec.md#requirement-a-per-account-host-is-pinned-at-mint-and-immutable-afterwards
	 */
	private function instanceOrigin(array $credential, string $credentialId): string {
		try {
			return OAuth2InstanceHost::normalise(candidate: (string)($credential['instanceBaseUrl'] ?? ''));
		} catch (InvalidArgumentException $badHost) {
			throw new CredentialAccessDeniedException(
				message: 'credential ' . $credentialId . ' has no usable instance host: ' . $badHost->getMessage(),
				previous: $badHost
			);
		}
	}//end instanceOrigin()

	/**
	 * Post the token request and decode its body.
	 *
	 * @param string $endpoint The absolute token endpoint.
	 * @param array<string, string> $form The form body.
	 * @param array{clientId: string, clientSecret: string|null} $client The resolved client.
	 *
	 * @return mixed The decoded body.
	 *
	 * @throws CredentialRelinkRequiredException When the transport failure carries `invalid_grant`.
	 * @throws CredentialUpstreamException On any other transport failure.
	 *
	 * @spec openspec/specs/credential-oauth2-token-set/spec.md#requirement-an-invalid-grant-moves-the-credential-to-relink-needed-and-fails-closed
	 */
	private function post(string $endpoint, array $form, array $client): mixed {
		$options = ['body' => $form, 'headers' => ['Accept' => 'application/json'], 'timeout' => 20];
		if ($client['clientSecret'] !== null && isset($form['client_secret']) === false) {
			$options['auth'] = [$client['clientId'], $client['clientSecret']];
		}

		try {
			$response = $this->clientService->newClient()->post($endpoint, $options);
			return json_decode((string)$response->getBody(), true);
		} catch (Throwable $failure) {
			throw $this->classified(failure: $failure);
		}
	}//end post()

	/**
	 * Turn a transport failure into the typed exception the caller acts on.
	 *
	 * @param Throwable $failure The transport failure.
	 *
	 * @return CredentialRelinkRequiredException|CredentialUpstreamException The exception to raise.
	 *
	 * @spec openspec/specs/credential-oauth2-token-set/spec.md#requirement-an-invalid-grant-moves-the-credential-to-relink-needed-and-fails-closed
	 */
	private function classified(Throwable $failure): CredentialRelinkRequiredException|CredentialUpstreamException {
		$error = $this->errorCodeOf(throwable: $failure);
		if ($error === self::TERMINAL_ERROR) {
			return new CredentialRelinkRequiredException(message: 'refresh refused: ' . $error, previous: $failure);
		}

		return new CredentialUpstreamException(message: 'token endpoint unreachable or failing: ' . $failure::class, previous: $failure);
	}//end classified()

	/**
	 * Refuse a token response that is not JSON or names an error.
	 *
	 * @param mixed $decoded The decoded body.
	 *
	 * @return array<string, mixed> The token response.
	 *
	 * @throws CredentialRelinkRequiredException When the body names `invalid_grant`.
	 * @throws CredentialUpstreamException When the body is not JSON or names any other error.
	 *
	 * @spec openspec/specs/credential-oauth2-token-set/spec.md#requirement-an-invalid-grant-moves-the-credential-to-relink-needed-and-fails-closed
	 */
	private function accepted(mixed $decoded): array {
		if (is_array($decoded) === false) {
			throw new CredentialUpstreamException(message: 'token endpoint returned a body that is not JSON');
		}

		$error = (string)($decoded['error'] ?? '');
		if ($error === self::TERMINAL_ERROR) {
			throw new CredentialRelinkRequiredException(message: 'refresh refused: ' . $error);
		}

		if ($error !== '') {
			throw new CredentialUpstreamException(message: 'token endpoint returned error ' . $error);
		}

		return $decoded;
	}//end accepted()

	/**
	 * Read an OAuth2 error code out of a transport failure, without quoting the body.
	 *
	 * A `invalid_grant` commonly arrives as an HTTP 400 with a JSON body, which the
	 * NC client raises rather than returns. Reading the code out of it is what keeps
	 * a revoked grant from being misfiled as a transient failure and retried forever.
	 *
	 * @param Throwable $throwable The transport failure.
	 *
	 * @return string The OAuth2 error code, or an empty string when there is none.
	 *
	 * @spec exclude private error-code extraction; asserted through the terminal and transient branches
	 */
	private function errorCodeOf(Throwable $throwable): string {
		$decoded = json_decode($this->responseBodyOf(throwable: $throwable), true);
		if (is_array($decoded) === false) {
			return '';
		}

		return (string)($decoded['error'] ?? '');
	}//end errorCodeOf()

	/**
	 * The response body a transport failure carries, or an empty string.
	 *
	 * @param Throwable $throwable The transport failure.
	 *
	 * @return string The body.
	 *
	 * @spec exclude private error-code extraction helper
	 */
	private function responseBodyOf(Throwable $throwable): string {
		if (method_exists($throwable, 'getResponse') === false) {
			return '';
		}

		$response = $throwable->getResponse();
		if ($response === null || method_exists($response, 'getBody') === false) {
			return '';
		}

		return (string)$response->getBody();
	}//end responseBodyOf()
}//end class
