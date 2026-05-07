<?php

/**
 * OpenRegister REST API Transport
 *
 * Transmits SIP packages to e-Depot systems via REST API.
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
 * @spec openspec/changes/retrofit-annotate-openregister-2026-04-30/tasks.md#task-33
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
class RestApiTransport implements TransportInterface
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
     * Send a SIP package via REST API.
     *
     * @param string              $sipFilePath The local path to the SIP ZIP archive.
     * @param array<string,mixed> $config      REST configuration: endpointUrl, authenticationType, apiKey/bearerToken.
     *
     * @return TransportResult The result of the transport.
     *
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-21
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-30/tasks.md#task-33
     */
    public function send(string $sipFilePath, array $config): TransportResult
    {
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
                        'headers'   => $headers,
                        'multipart' => [
                            [
                                'name'     => 'sip',
                                'contents' => fopen($sipFilePath, 'r'),
                                'filename' => basename($sipFilePath),
                            ],
                        ],
                        'timeout'   => 300,
                    ]
                    );

            $statusCode = $response->getStatusCode();
            $body       = json_decode((string) $response->getBody(), true);

            if ($statusCode >= 200 && $statusCode < 300) {
                $transferRef = ($body['reference'] ?? $body['id'] ?? null);

                $objectResults = [];
                if (isset($body['objects']) === true && is_array($body['objects']) === true) {
                    foreach ($body['objects'] as $objResult) {
                        $uuid = ($objResult['uuid'] ?? $objResult['id'] ?? '');
                        $objectResults[$uuid] = [
                            'accepted'  => ($objResult['accepted'] ?? true),
                            'reference' => ($objResult['reference'] ?? null),
                            'error'     => ($objResult['error'] ?? null),
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
                        'statusCode'    => $statusCode,
                        'reference'     => $transferRef,
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
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-21
     */
    public function testConnection(array $config): bool
    {
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
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-21
     */
    public function getName(): string
    {
        return 'rest_api';
    }//end getName()

    /**
     * Validate REST API configuration.
     *
     * @param array<string,mixed> $config The configuration to validate.
     *
     * @return void
     *
     * @throws RuntimeException If required configuration is missing.
     *
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-21
     */
    private function validateConfig(array $config): void
    {
        if (empty($config['endpointUrl']) === true) {
            throw new RuntimeException('Missing required REST API config: endpointUrl');
        }
    }//end validateConfig()

    /**
     * Build authentication headers.
     *
     * @param array<string,mixed> $config The transport configuration.
     *
     * @return array<string,string> The auth headers.
     *
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-21
     */
    private function buildAuthHeaders(array $config): array
    {
        $headers  = [];
        $authType = ($config['authenticationType'] ?? '');

        switch ($authType) {
            case 'api_key':
                $headers['X-API-Key'] = ($config['apiKey'] ?? '');
                break;
            case 'oauth2':
                $headers['Authorization'] = 'Bearer '.($config['bearerToken'] ?? '');
                break;
            case 'certificate':
                // Certificate auth is handled at the HTTP client level, not via headers.
                break;
        }

        return $headers;
    }//end buildAuthHeaders()
}//end class
