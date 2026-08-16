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

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * REST API transport for e-Depot SIP packages.
 *
 * Sends SIP packages as multipart uploads to a REST endpoint. Supports
 * API key and OAuth2 bearer token authentication.
 *
 * @psalm-suppress UnusedClass
 */
class RestApiTransport implements TransportInterface {

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
	 * Constructor.
	 *
	 * @param Client $httpClient The HTTP client.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly Client $httpClient,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

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
			$this->validateConfig(config: $config);

			if (file_exists($sipFilePath) === false) {
				throw new RuntimeException("SIP file not found: {$sipFilePath}");
			}

			$headers = $this->buildAuthHeaders(config: $config);

			$response = $this->httpClient->post(
				$config['endpointUrl'],
				[
					'headers' => $headers,
					'multipart' => [
						[
							'name' => 'sip',
							'contents' => fopen($sipFilePath, 'r'),
							'filename' => basename($sipFilePath),
						],
					],
					'timeout' => 300,
				]
			);

			$statusCode = $response->getStatusCode();
			$body = json_decode((string)$response->getBody(), true);

			if ($statusCode >= 200 && $statusCode < 300) {
				$transferRef = ($body['reference'] ?? $body['id'] ?? null);

				$objectResults = [];
				if (isset($body['objects']) === true && is_array($body['objects']) === true) {
					foreach ($body['objects'] as $objResult) {
						$uuid = ($objResult['uuid'] ?? $objResult['id'] ?? '');
						$objectResults[$uuid] = [
							'accepted' => ($objResult['accepted'] ?? true),
							'reference' => ($objResult['reference'] ?? null),
							'error' => ($objResult['error'] ?? null),
						];
					}
				}

				$hasRejections = false;
				foreach ($objectResults as $result) {
					if ($result['accepted'] === false) {
						$hasRejections = true;
						break;
					}
				}

				$this->logger->info(
					message: '[RestApiTransport] REST API transfer completed',
					context: [
						'statusCode' => $statusCode,
						'reference' => $transferRef,
						'hasRejections' => $hasRejections,
					]
				);

				return new TransportResult(
					success: ($hasRejections === false),
					objectResults: $objectResults,
					transferReference: $transferRef
				);
			}//end if

			$errorMsg = ($body['error'] ?? $body['message'] ?? "HTTP {$statusCode}");
			throw new RuntimeException("e-Depot API returned error: {$errorMsg}");
		} catch (GuzzleException $e) {
			$this->logger->error(
				message: '[RestApiTransport] REST API transfer failed',
				context: ['error' => $e->getMessage()]
			);

			return new TransportResult(
				success: false,
				errorMessage: $e->getMessage()
			);
		} catch (\Exception $e) {
			$this->logger->error(
				message: '[RestApiTransport] REST API transfer failed',
				context: ['error' => $e->getMessage()]
			);

			return new TransportResult(
				success: false,
				errorMessage: $e->getMessage()
			);
		}//end try
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
			$headers = $this->buildAuthHeaders(config: $config);

			$response = $this->httpClient->get(
				$config['endpointUrl'],
				[
					'headers' => $headers,
					'timeout' => 10,
				]
			);

			return ($response->getStatusCode() < 400);
		} catch (\Exception $e) {
			$this->logger->warning(
				message: '[RestApiTransport] Connection test failed',
				context: ['error' => $e->getMessage()]
			);
			return false;
		}
	}//end testConnection()

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

		$authType = (string)($config['authenticationType'] ?? '');
		if (in_array($authType, self::SUPPORTED_AUTH_TYPES, true) === false) {
			throw new RuntimeException(
				'Missing or unsupported REST API config: authenticationType must be one of '
				. implode(', ', self::SUPPORTED_AUTH_TYPES)
				. ' — refusing to transfer a SIP package unauthenticated'
			);
		}

		if ($authType === self::AUTH_API_KEY && empty($config['apiKey']) === true) {
			throw new RuntimeException(
				'Missing required REST API config: apiKey is empty while authenticationType is ' . self::AUTH_API_KEY
			);
		}

		if ($authType === self::AUTH_OAUTH2 && empty($config['bearerToken']) === true) {
			throw new RuntimeException(
				'Missing required REST API config: bearerToken is empty while authenticationType is ' . self::AUTH_OAUTH2
			);
		}
	}//end validateConfig()

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
