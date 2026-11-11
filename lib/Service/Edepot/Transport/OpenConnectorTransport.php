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

use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * OpenConnector transport for e-Depot SIP packages.
 *
 * Creates a synchronization job in OpenConnector with the SIP file as payload.
 * Transfer status is tracked via OpenConnector's call log.
 *
 * @psalm-suppress UnusedClass
 */
class OpenConnectorTransport extends HttpSipTransport implements OpenConnectorTransportInterface {

	use SipTransportSteps;
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
			$this->assertSendable(sipFilePath: $sipFilePath, config: $config);

			return $this->jobResult(
				sourceId: $config['sourceId'],
				response: $this->postSynchronization(sipFilePath: $sipFilePath, config: $config)
			);
		} catch (\Exception $e) {
			return $this->failedTransfer(cause: $e, message: '[OpenConnectorTransport] Transfer failed');
		}
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

			return $this->probeSource(config: $config);
		} catch (\Exception $e) {
			return $this->failedConnectionTest(cause: $e, message: '[OpenConnectorTransport] Connection test failed');
		}
	}//end testConnection()

	/**
	 * Ask the connector to push the SIP package through its source.
	 *
	 * @param string               $sipFilePath The SIP package path.
	 * @param array<string, mixed> $config      The transport config (sourceId, baseUrl, appId).
	 *
	 * @return ResponseInterface The connector's answer.
	 */
	private function postSynchronization(string $sipFilePath, array $config): ResponseInterface {
		$baseUrl = rtrim(($config['baseUrl'] ?? 'http://localhost:8080'), '/');
		$appId = $this->remoteAppId(config: $config);

		return $this->httpClient->post(
			"{$baseUrl}/index.php/apps/{$appId}/api/synchronizations",
			[
				'json' => [
					'sourceId' => $config['sourceId'],
					'action' => 'push',
					'payload' => $this->sipPayload(sipFilePath: $sipFilePath),
				],
				'timeout' => 60,
			]
		);
	}//end postSynchronization()

	/**
	 * The push payload that names the SIP package.
	 *
	 * @param string $sipFilePath The SIP package path.
	 *
	 * @return array{type: string, filePath: string, fileName: string, fileSize: int|false}
	 */
	private function sipPayload(string $sipFilePath): array {
		return [
			'type' => 'sip_package',
			'filePath' => $sipFilePath,
			'fileName' => basename($sipFilePath),
			'fileSize' => filesize($sipFilePath),
		];
	}//end sipPayload()

	/**
	 * The result of a created synchronization job, referenced by its call log id.
	 *
	 * @param mixed             $sourceId The connector source id.
	 * @param ResponseInterface $response The connector's answer.
	 *
	 * @return TransportResult The successful result.
	 */
	private function jobResult(mixed $sourceId, ResponseInterface $response): TransportResult {
		$body = json_decode((string)$response->getBody(), true);
		$callLogId = ($body['callLogId'] ?? $body['id'] ?? null);

		$this->logger->info(
			message: '[OpenConnectorTransport] Synchronization job created',
			context: [
				'sourceId' => $sourceId,
				'callLogId' => $callLogId,
			]
		);

		return new TransportResult(transferReference: (string)$callLogId);
	}//end jobResult()

	/**
	 * Whether the source answers on the remote connector, trying each app id the
	 * remote may have registered, newest first; the first non-404 decides.
	 *
	 * @param array<string, mixed> $config The transport config.
	 *
	 * @return bool True when the source answered below 400.
	 */
	private function probeSource(array $config): bool {
		$baseUrl = rtrim(($config['baseUrl'] ?? 'http://localhost:8080'), '/');
		foreach ($this->remoteAppIdCandidates(config: $config) as $appId) {
			$response = $this->httpClient->get(
				"{$baseUrl}/index.php/apps/{$appId}/api/sources/{$config['sourceId']}",
				['timeout' => 10, 'http_errors' => false]
			);
			if ($response->getStatusCode() !== 404) {
				return ($response->getStatusCode() < 400);
			}
		}

		return false;
	}//end probeSource()

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
