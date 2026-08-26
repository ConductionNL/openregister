<?php

/**
 * OpenRegister OpenConnector Transport
 *
 * Transmits SIP packages to e-Depot systems via OpenConnector synchronization.
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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Edepot\Transport;

use GuzzleHttp\Client;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * OpenConnector transport for e-Depot SIP packages.
 *
 * Creates a synchronization job in OpenConnector with the SIP file as payload.
 * Transfer status is tracked via OpenConnector's call log.
 *
 * @psalm-suppress UnusedClass
 */
class OpenConnectorTransport implements TransportInterface {
	/**
	 * Constructor.
	 *
	 * @param Client $httpClient The HTTP client.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-assemble-sip-packages-for-e-depot-transfer
	 */
	public function __construct(
		private readonly Client $httpClient,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Send a SIP package via OpenConnector.
	 *
	 * @param string $sipFilePath The local path to the SIP ZIP archive.
	 * @param array<string,mixed> $config OpenConnector configuration: sourceId, baseUrl.
	 *
	 * @return TransportResult The result of the transport.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-assemble-sip-packages-for-e-depot-transfer
	 */
	public function send(string $sipFilePath, array $config): TransportResult {
		$this->logger->info(
			message: '[OpenConnectorTransport] Starting OpenConnector transfer',
			context: ['sourceId' => ($config['sourceId'] ?? 'unknown')]
		);

		try {
			$this->validateConfig(config: $config);

			if (file_exists($sipFilePath) === false) {
				throw new RuntimeException("SIP file not found: {$sipFilePath}");
			}

			$baseUrl = rtrim(($config['baseUrl'] ?? 'http://localhost:8080'), '/');
			$sourceId = $config['sourceId'];
			$appId = $this->remoteAppId(config: $config);

			$response = $this->httpClient->post(
				"{$baseUrl}/index.php/apps/{$appId}/api/synchronizations",
				[
					'json' => [
						'sourceId' => $sourceId,
						'action' => 'push',
						'payload' => [
							'type' => 'sip_package',
							'filePath' => $sipFilePath,
							'fileName' => basename($sipFilePath),
							'fileSize' => filesize($sipFilePath),
						],
					],
					'timeout' => 60,
				]
			);

			$body = json_decode((string)$response->getBody(), true);
			$callLogId = ($body['callLogId'] ?? $body['id'] ?? null);

			$this->logger->info(
				message: '[OpenConnectorTransport] Synchronization job created',
				context: [
					'sourceId' => $sourceId,
					'callLogId' => $callLogId,
				]
			);

			return new TransportResult(
				success: true,
				transferReference: (string)$callLogId
			);
		} catch (\Exception $e) {
			$this->logger->error(
				message: '[OpenConnectorTransport] Transfer failed',
				context: ['error' => $e->getMessage()]
			);

			return new TransportResult(
				success: false,
				errorMessage: $e->getMessage()
			);
		}//end try
	}//end send()

	/**
	 * Test OpenConnector connection.
	 *
	 * @param array<string,mixed> $config OpenConnector configuration.
	 *
	 * @return bool True if connection test succeeds.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-assemble-sip-packages-for-e-depot-transfer
	 */
	public function testConnection(array $config): bool {
		try {
			$this->validateConfig(config: $config);

			$baseUrl = rtrim(($config['baseUrl'] ?? 'http://localhost:8080'), '/');
			$sourceId = $config['sourceId'];

			// The remote is a DIFFERENT instance, so the local app manager
			// cannot say which id it registered. Probe the candidates newest
			// first and treat the first non-404 as the live one.
			foreach ($this->remoteAppIdCandidates(config: $config) as $appId) {
				$response = $this->httpClient->get(
					"{$baseUrl}/index.php/apps/{$appId}/api/sources/{$sourceId}",
					['timeout' => 10, 'http_errors' => false]
				);

				if ($response->getStatusCode() !== 404) {
					return ($response->getStatusCode() < 400);
				}
			}

			return false;
		} catch (\Exception $e) {
			$this->logger->warning(
				message: '[OpenConnectorTransport] Connection test failed',
				context: ['error' => $e->getMessage()]
			);
			return false;
		}
	}//end testConnection()

	/**
	 * Candidate app ids for the REMOTE instance, newest first.
	 *
	 * OpenConnector was renamed to integriq. The new id ships on development;
	 * beta and main still register the old one. This transport talks to another
	 * instance over HTTP, so the local IAppManager cannot answer which id that
	 * instance uses — and a URL segment is a routing key, so guessing wrong is
	 * a 404 rather than an error we could interpret.
	 *
	 * An explicit `appId` in the transport config wins when set; otherwise both
	 * names are offered, newest first.
	 *
	 * @param array<string,mixed> $config OpenConnector configuration.
	 *
	 * @return list<string> Candidate ids in priority order.
	 */
	private function remoteAppIdCandidates(array $config): array {
		$configured = trim((string)($config['appId'] ?? ''));
		if ($configured !== '') {
			return [$configured];
		}

		return ['integriq', 'openconnector'];
	}//end remoteAppIdCandidates()

	/**
	 * The app id to address the remote instance with.
	 *
	 * `send()` performs a state-changing POST, so it does not probe — a probe
	 * would risk creating the synchronisation twice. It takes the first
	 * candidate, which an operator can pin via the `appId` config key when the
	 * remote has not been renamed yet.
	 *
	 * @param array<string,mixed> $config OpenConnector configuration.
	 *
	 * @return string The app id to use in the URL.
	 */
	private function remoteAppId(array $config): string {
		return $this->remoteAppIdCandidates(config: $config)[0];
	}//end remoteAppId()

	/**
	 * Get transport name.
	 *
	 * @return string The transport name.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-assemble-sip-packages-for-e-depot-transfer
	 */
	public function getName(): string {
		return 'openconnector';
	}//end getName()

	/**
	 * Validate OpenConnector configuration.
	 *
	 * @param array<string,mixed> $config The configuration to validate.
	 *
	 * @return void
	 *
	 * @throws RuntimeException If required configuration is missing.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-assemble-sip-packages-for-e-depot-transfer
	 */
	private function validateConfig(array $config): void {
		if (empty($config['sourceId']) === true) {
			throw new RuntimeException('Missing required OpenConnector config: sourceId');
		}
	}//end validateConfig()
}//end class
