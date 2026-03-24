<?php

/**
 * OpenRegister OpenConnector Transport
 *
 * Transmits SIP packages to e-Depot systems via OpenConnector synchronization.
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
class OpenConnectorTransport implements TransportInterface
{
    /**
     * Constructor.
     *
     * @param Client          $httpClient The HTTP client.
     * @param LoggerInterface $logger     Logger.
     */
    public function __construct(
        private readonly Client $httpClient,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Send a SIP package via OpenConnector.
     *
     * @param string              $sipFilePath The local path to the SIP ZIP archive.
     * @param array<string,mixed> $config      OpenConnector configuration: sourceId, baseUrl.
     *
     * @return TransportResult The result of the transport.
     */
    public function send(string $sipFilePath, array $config): TransportResult
    {
        $this->logger->info(
            message: '[OpenConnectorTransport] Starting OpenConnector transfer',
            context: ['sourceId' => ($config['sourceId'] ?? 'unknown')]
        );

        try {
            $this->validateConfig($config);

            if (file_exists($sipFilePath) === false) {
                throw new RuntimeException("SIP file not found: {$sipFilePath}");
            }

            $baseUrl  = rtrim(($config['baseUrl'] ?? 'http://localhost:8080'), '/');
            $sourceId = $config['sourceId'];

            $response = $this->httpClient->post(
                "{$baseUrl}/index.php/apps/openconnector/api/synchronizations",
                [
                    'json'    => [
                        'sourceId' => $sourceId,
                        'action'   => 'push',
                        'payload'  => [
                            'type'     => 'sip_package',
                            'filePath' => $sipFilePath,
                            'fileName' => basename($sipFilePath),
                            'fileSize' => filesize($sipFilePath),
                        ],
                    ],
                    'timeout' => 60,
                ]
            );

            $body      = json_decode((string) $response->getBody(), true);
            $callLogId = ($body['callLogId'] ?? $body['id'] ?? null);

            $this->logger->info(
                message: '[OpenConnectorTransport] Synchronization job created',
                context: [
                    'sourceId'  => $sourceId,
                    'callLogId' => $callLogId,
                ]
            );

            return new TransportResult(
                success: true,
                transferReference: (string) $callLogId
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
     */
    public function testConnection(array $config): bool
    {
        try {
            $this->validateConfig($config);

            $baseUrl  = rtrim(($config['baseUrl'] ?? 'http://localhost:8080'), '/');
            $sourceId = $config['sourceId'];

            $response = $this->httpClient->get(
                "{$baseUrl}/index.php/apps/openconnector/api/sources/{$sourceId}",
                ['timeout' => 10]
            );

            return ($response->getStatusCode() < 400);
        } catch (\Exception $e) {
            $this->logger->warning(
                message: '[OpenConnectorTransport] Connection test failed',
                context: ['error' => $e->getMessage()]
            );
            return false;
        }
    }//end testConnection()

    /**
     * Get transport name.
     *
     * @return string The transport name.
     */
    public function getName(): string
    {
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
     */
    private function validateConfig(array $config): void
    {
        if (empty($config['sourceId']) === true) {
            throw new RuntimeException('Missing required OpenConnector config: sourceId');
        }
    }//end validateConfig()
}//end class
