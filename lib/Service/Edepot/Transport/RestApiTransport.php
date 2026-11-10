<?php

/**
 * OpenRegister REST API Transport
 *
 * Transmits SIP packages to e-Depot systems via REST API.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Edepot\Transport
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/edepot-transfer/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Edepot\Transport;

use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * REST API transport for e-Depot SIP packages.
 *
 * Sends SIP packages as multipart uploads to a REST endpoint. Supports
 * API key and OAuth2 bearer token authentication.
 *
 * @psalm-suppress UnusedClass
 */
class RestApiTransport extends HttpSipTransport implements RestApiTransportInterface {

	use SipTransportSteps;

	/**
	 * API-key authentication (`X-API-Key` header).
	 *
	 * @var string
	 */
	public const AUTH_API_KEY = 'api_key';

	/**
	 * OAuth2 bearer-token authentication (`Authorization: Bearer` header).
	 *
	 * @var string
	 */
	public const AUTH_OAUTH2 = 'oauth2';

	/**
	 * Client-certificate authentication, applied at the HTTP client level.
	 *
	 * @var string
	 */
	public const AUTH_CERTIFICATE = 'certificate';

	/**
	 * The authentication types the e-Depot transfer spec declares. Anything
	 * outside this list — including the empty default of an unconfigured
	 * instance — is refused by `validateConfig()`.
	 *
	 * @var array<int, string>
	 */
	public const SUPPORTED_AUTH_TYPES = [
		self::AUTH_API_KEY,
		self::AUTH_OAUTH2,
		self::AUTH_CERTIFICATE,
	];

	/**
	 * Send a SIP package via REST API.
	 *
	 * @param string $sipFilePath The local path to the SIP ZIP archive.
	 * @param array<string,mixed> $config REST configuration: endpointUrl, authenticationType, apiKey/bearerToken.
	 *
	 * @return TransportResult The result of the transport.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-assemble-sip-packages-for-e-depot-transfer
	 * @spec openspec/specs/edepot-transfer/spec.md
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 */
	public function send(string $sipFilePath, array $config): TransportResult {
		$this->logger->info(
			message: '[RestApiTransport] Starting REST API transfer',
			context: ['endpoint' => ($config['endpointUrl'] ?? 'unknown')]
		);

		try {
			$this->assertSendable(sipFilePath: $sipFilePath, config: $config);

			return $this->acceptedOrThrow(response: $this->postSip(sipFilePath: $sipFilePath, config: $config));
		} catch (GuzzleException | \Exception $e) {
			return $this->failedTransfer(cause: $e, message: '[RestApiTransport] REST API transfer failed');
		}
	}//end send()

	/**
	 * Test REST API connection.
	 *
	 * @param array<string,mixed> $config REST configuration.
	 *
	 * @return bool True if connection test succeeds.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-assemble-sip-packages-for-e-depot-transfer
	 */
	public function testConnection(array $config): bool {
		try {
			$this->validateConfig(config: $config);

			return $this->probeEndpoint(config: $config);
		} catch (\Exception $e) {
			return $this->failedConnectionTest(cause: $e, message: '[RestApiTransport] Connection test failed');
		}
	}//end testConnection()

	/**
	 * Whether the endpoint answers an authenticated GET below 400.
	 *
	 * @param array<string, mixed> $config The transport config.
	 *
	 * @return bool
	 *
	 * @throws GuzzleException When the request fails.
	 */
	private function probeEndpoint(array $config): bool {
		$headers = $this->buildAuthHeaders(config: $config);

		$response = $this->httpClient->get(
			$config['endpointUrl'],
			[
				'headers' => $headers,
				'timeout' => 10,
			]
		);

		return ($response->getStatusCode() < 400);
	}//end probeEndpoint()

	/**
	 * Post the SIP package as multipart with the configured authentication headers.
	 *
	 * @param string               $sipFilePath The SIP package path.
	 * @param array<string, mixed> $config      The transport config.
	 *
	 * @return ResponseInterface The e-Depot's answer.
	 *
	 * @throws GuzzleException When the request fails.
	 */
	private function postSip(string $sipFilePath, array $config): ResponseInterface {
		$headers = $this->buildAuthHeaders(config: $config);

		return $this->httpClient->post(
			$config['endpointUrl'],
			[
				'headers' => $headers,
				'multipart' => [$this->sipPart(sipFilePath: $sipFilePath)],
				'timeout' => 300,
			]
		);
	}//end postSip()

	/**
	 * The multipart part that carries the SIP package.
	 *
	 * @param string $sipFilePath The SIP package path.
	 *
	 * @return array{name: string, contents: resource|false, filename: string}
	 */
	private function sipPart(string $sipFilePath): array {
		return [
			'name' => 'sip',
			'contents' => fopen($sipFilePath, 'r'),
			'filename' => basename($sipFilePath),
		];
	}//end sipPart()

	/**
	 * The result of a 2xx answer; any other status is an error.
	 *
	 * @param ResponseInterface $response The e-Depot's answer.
	 *
	 * @return TransportResult The result of an accepted transfer.
	 *
	 * @throws RuntimeException When the status is not 2xx.
	 */
	private function acceptedOrThrow(ResponseInterface $response): TransportResult {
		$statusCode = $response->getStatusCode();
		$body = json_decode((string)$response->getBody(), true);

		if ($statusCode >= 200 && $statusCode < 300) {
			return $this->acceptedResult(body: $body, statusCode: $statusCode);
		}

		$errorMsg = ($body['error'] ?? $body['message'] ?? "HTTP {$statusCode}");
		throw new RuntimeException("e-Depot API returned error: {$errorMsg}");
	}//end acceptedOrThrow()

	/**
	 * The result of an accepted transfer: successful unless an object was rejected.
	 *
	 * @param mixed $body       The decoded answer body.
	 * @param int   $statusCode The HTTP status.
	 *
	 * @return TransportResult The result.
	 */
	private function acceptedResult(mixed $body, int $statusCode): TransportResult {
		$transferRef = ($body['reference'] ?? $body['id'] ?? null);
		$result = new TransportResult(transferReference: $transferRef, objectResults: $this->objectResults(body: $body));

		$this->logger->info(
			message: '[RestApiTransport] REST API transfer completed',
			context: [
				'statusCode' => $statusCode,
				'reference' => $transferRef,
				'hasRejections' => $result->hasRejections(),
			]
		);

		return $result;
	}//end acceptedResult()

	/**
	 * The per-object outcomes in an answer body, keyed by object uuid.
	 *
	 * @param mixed $body The decoded answer body.
	 *
	 * @return array<string, array{accepted: mixed, reference: mixed, error: mixed}> The outcomes.
	 */
	private function objectResults(mixed $body): array {
		$objectResults = [];
		if (isset($body['objects']) === false || is_array($body['objects']) === false) {
			return $objectResults;
		}

		foreach ($body['objects'] as $objResult) {
			$uuid = ($objResult['uuid'] ?? $objResult['id'] ?? '');
			$objectResults[$uuid] = [
				'accepted' => ($objResult['accepted'] ?? true),
				'reference' => ($objResult['reference'] ?? null),
				'error' => ($objResult['error'] ?? null),
			];
		}

		return $objectResults;
	}//end objectResults()

	/**
	 * Get transport name.
	 *
	 * @return string The transport name.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-assemble-sip-packages-for-e-depot-transfer
	 */
	public function getName(): string {
		return 'rest_api';
	}//end getName()

	/**
	 * Validate REST API configuration.
	 *
	 * Fails CLOSED on authentication. `buildAuthHeaders()` returns an empty
	 * header set for an unrecognised `authenticationType`, and an
	 * `X-API-Key: ` / `Authorization: Bearer ` header for a selected type
	 * whose credential is blank. Either way the archival SIP package — the
	 * records themselves — would leave the instance unauthenticated, and the
	 * only signal would be whatever the far end chose to answer. The spec
	 * names exactly three authentication types (`api_key`, `certificate`,
	 * `oauth2`); anything else, including the empty default of an
	 * unconfigured instance, is a refusal here rather than a silent
	 * downgrade at send time.
	 *
	 * @param array<string,mixed> $config The configuration to validate.
	 *
	 * @return void
	 *
	 * @throws RuntimeException If required configuration is missing.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-support-configurable-e-depot-endpoint-settings
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-assemble-sip-packages-for-e-depot-transfer
	 */
	private function validateConfig(array $config): void {
		if (empty($config['endpointUrl']) === true) {
			throw new RuntimeException('Missing required REST API config: endpointUrl');
		}

		$authType = $this->supportedAuthType(config: $config);
		$this->assertCredentialFor(authType: $authType, config: $config);
	}//end validateConfig()

	/**
	 * The configured authentication type, or a refusal when it is missing or unsupported.
	 *
	 * @param array<string, mixed> $config The transport config.
	 *
	 * @return string One of SUPPORTED_AUTH_TYPES.
	 *
	 * @throws RuntimeException When the type is missing or unsupported.
	 */
	private function supportedAuthType(array $config): string {
		$authType = (string)($config['authenticationType'] ?? '');
		if (in_array($authType, self::SUPPORTED_AUTH_TYPES, true) === false) {
			throw new RuntimeException(
				'Missing or unsupported REST API config: authenticationType must be one of '
				. implode(', ', self::SUPPORTED_AUTH_TYPES)
				. ' — refusing to transfer a SIP package unauthenticated'
			);
		}

		return $authType;
	}//end supportedAuthType()

	/**
	 * Refuse an API-key or OAuth2 config whose credential is empty.
	 *
	 * @param string               $authType The validated authentication type.
	 * @param array<string, mixed> $config   The transport config.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the credential the type needs is empty.
	 */
	private function assertCredentialFor(string $authType, array $config): void {
		$needs = [self::AUTH_API_KEY => 'apiKey', self::AUTH_OAUTH2 => 'bearerToken'];
		$key = ($needs[$authType] ?? null);
		if ($key !== null && empty($config[$key]) === true) {
			throw new RuntimeException(
				"Missing required REST API config: {$key} is empty while authenticationType is {$authType}"
			);
		}
	}//end assertCredentialFor()

	/**
	 * Build authentication headers.
	 *
	 * @param array<string,mixed> $config The transport configuration.
	 *
	 * @return array<string,string> The auth headers.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-assemble-sip-packages-for-e-depot-transfer
	 */
	private function buildAuthHeaders(array $config): array {
		// `validateConfig()` has already refused an unsupported type and a
		// blank credential for the selected type, so every branch below is
		// reached with a value present. This method must NOT be the place
		// that decides what "no authentication configured" means — it has no
		// way to refuse, and returning an empty header set from here is what
		// made an unauthenticated transfer look like a configured one.
		$headers = [];
		$authType = (string)($config['authenticationType'] ?? '');

		switch ($authType) {
			case self::AUTH_API_KEY:
				$headers['X-API-Key'] = ($config['apiKey'] ?? '');
				break;
			case self::AUTH_OAUTH2:
				$headers['Authorization'] = 'Bearer ' . ($config['bearerToken'] ?? '');
				break;
			case self::AUTH_CERTIFICATE:
				// Certificate auth is handled at the HTTP client level, not via headers.
				break;
		}

		return $headers;
	}//end buildAuthHeaders()
}//end class
