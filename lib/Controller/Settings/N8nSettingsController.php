<?php

/**
 * OpenRegister n8n Settings Controller
 *
 * @category  Controller
 * @package   OCA\OpenRegister\Controller\Settings
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2025 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Settings;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Exception;
use OCA\OpenRegister\Service\Settings\ConfigurationSettingsHandler;
use OCA\OpenRegister\Service\SettingsService;
use Psr\Log\LoggerInterface;
use OCP\Http\Client\IClientService;

/**
 * Controller for n8n workflow integration settings.
 *
 * Handles:
 * - n8n connection configuration
 * - Connection testing
 * - Project initialization
 * - Workflow management
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Settings
 */
class N8nSettingsController extends Controller
{

    /**
     * Configuration settings handler.
     *
     * @var ConfigurationSettingsHandler
     */
    private ConfigurationSettingsHandler $configHandler;

    /**
     * Settings service.
     *
     * @var SettingsService
     */
    private SettingsService $settingsService;

    /**
     * Logger.
     *
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * HTTP client service.
     *
     * @var IClientService
     */
    private IClientService $clientService;

    /**
     * Constructor.
     *
     * @param string                       $appName         The app name.
     * @param IRequest                     $request         The request.
     * @param ConfigurationSettingsHandler $configHandler   Configuration settings handler.
     * @param SettingsService              $settingsService Settings service.
     * @param LoggerInterface              $logger          Logger.
     * @param IClientService               $clientService   HTTP client service.
     *
     * @return void
     */
    public function __construct(
        $appName,
        IRequest $request,
        ConfigurationSettingsHandler $configHandler,
        SettingsService $settingsService,
        LoggerInterface $logger,
        IClientService $clientService
    ) {
        parent::__construct(appName: $appName, request: $request);
        $this->configHandler   = $configHandler;
        $this->settingsService = $settingsService;
        $this->logger          = $logger;
        $this->clientService   = $clientService;
    }//end __construct()

    /**
     * Get n8n settings.
     *
     * Retrieves the current n8n workflow integration configuration.
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse The n8n settings.
     *
     * @psalm-return JSONResponse<200|500, array, array<never, never>>
     */
    public function getN8nSettings(): JSONResponse
    {
        try {
            $settings = $this->configHandler->getN8nSettingsOnly();

            // Mask API key for security.
            if (empty($settings['apiKey']) === false) {
                $settings['apiKey'] = $this->settingsService->maskToken($settings['apiKey']);
            }

            return new JSONResponse(data: $settings);
        } catch (Exception $e) {
            $this->logger->error('Failed to retrieve n8n settings: '.$e->getMessage());
            return new JSONResponse(
                data: ['error' => 'Failed to retrieve n8n settings: '.$e->getMessage()],
                statusCode: 500
            );
        }
    }//end getN8nSettings()

    /**
     * Update n8n settings.
     *
     * Updates the n8n workflow integration configuration.
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with updated n8n settings
     */
    public function updateN8nSettings(): JSONResponse
    {
        try {
            $data = $this->request->getParams();

            // Only save API key if not masked.
            if (isset($data['apiKey']) === true && str_contains($data['apiKey'], '***') === true) {
                // Get current settings and preserve existing API key.
                $currentSettings = $this->configHandler->getN8nSettingsOnly();
                $data['apiKey']  = $currentSettings['apiKey'];
            }

            $settings = $this->configHandler->updateN8nSettingsOnly($data);

            // Mask API key before returning.
            if (empty($settings['apiKey']) === false) {
                $settings['apiKey'] = $this->settingsService->maskToken($settings['apiKey']);
            }

            return new JSONResponse(
                data: [
                    'success' => true,
                    'message' => 'n8n settings saved successfully',
                    'data'    => $settings,
                ]
            );
        } catch (Exception $e) {
            $this->logger->error('Failed to update n8n settings: '.$e->getMessage());
            return new JSONResponse(
                data: ['error' => 'Failed to update n8n settings: '.$e->getMessage()],
                statusCode: 500
            );
        }//end try
    }//end updateN8nSettings()

    /**
     * Test n8n connection.
     *
     * Tests the connection to the n8n instance using the provided credentials.
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with connection test result
     */
    public function testN8nConnection(): JSONResponse
    {
        try {
            $data   = $this->request->getParams();
            $url    = $data['url'] ?? '';
            $apiKey = $data['apiKey'] ?? '';

            if (empty($url) === true || empty($apiKey) === true) {
                return new JSONResponse(
                    data: [
                        'success' => false,
                        'message' => 'n8n URL and API key are required',
                    ],
                    statusCode: 400
                );
            }

            // Ensure URL doesn't end with slash.
            $url = rtrim($url, '/');

            // Test the connection by getting the current user.
            $client   = $this->clientService->newClient();
            $response = $client->get(
                $url.'/api/v1/users',
                [
                    'headers' => [
                        'Accept'        => 'application/json',
                        'X-N8N-API-KEY' => $apiKey,
                    ],
                    'timeout' => 10,
                ]
            );

            $statusCode = $response->getStatusCode();
            if ($statusCode >= 200 && $statusCode < 300) {
                $responseData = json_decode($response->getBody(), true);

                return new JSONResponse(
                    data: [
                        'success' => true,
                        'message' => 'n8n connection successful',
                        'details' => [
                            'version' => 'Connected',
                            'users'   => count($responseData['data'] ?? []),
                        ],
                    ]
                );
            }

            return new JSONResponse(
                data: [
                    'success' => false,
                    'message' => 'n8n connection failed with status code: '.$statusCode,
                ],
                statusCode: 400
            );
        } catch (Exception $e) {
            $this->logger->error('n8n connection test failed: '.$e->getMessage());
            return new JSONResponse(
                data: [
                    'success' => false,
                    'message' => 'n8n connection test failed: '.$e->getMessage(),
                ],
                statusCode: 400
            );
        }//end try
    }//end testN8nConnection()

    /**
     * Initialize n8n project.
     *
     * Creates a project in n8n for OpenRegister workflows.
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with initialization result
     *
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public function initializeN8n(): JSONResponse
    {
        try {
            $data    = $this->request->getParams();
            $project = $data['project'] ?? 'openregister';

            // Get current settings.
            $settings = $this->configHandler->getN8nSettingsOnly();
            $url      = $settings['url'] ?? '';
            $apiKey   = $settings['apiKey'] ?? '';

            if (empty($url) === true || empty($apiKey) === true) {
                return new JSONResponse(
                    data: [
                        'success' => false,
                        'message' => 'n8n connection not configured',
                    ],
                    statusCode: 400
                );
            }

            // Ensure URL doesn't end with slash.
            $url = rtrim($url, '/');

            $client = $this->clientService->newClient();

            // Check if project already exists.
            try {
                $response = $client->get(
                    $url.'/api/v1/projects',
                    [
                        'headers' => [
                            'Accept'        => 'application/json',
                            'X-N8N-API-KEY' => $apiKey,
                        ],
                    ]
                );

                $projects   = json_decode($response->getBody(), true);
                $projectId  = null;
                $projectObj = null;

                // Check if our project exists.
                foreach ($projects['data'] ?? [] as $proj) {
                    if ($proj['name'] === $project) {
                        $projectId  = $proj['id'];
                        $projectObj = $proj;
                        break;
                    }
                }

                // Create project if it doesn't exist.
                if ($projectId === null) {
                    $createResponse = $client->post(
                        $url.'/api/v1/projects',
                        [
                            'headers' => [
                                'Accept'        => 'application/json',
                                'Content-Type'  => 'application/json',
                                'X-N8N-API-KEY' => $apiKey,
                            ],
                            'json'    => [
                                'name' => $project,
                            ],
                        ]
                    );

                    $projectObj = json_decode($createResponse->getBody(), true);
                    $projectId  = $projectObj['id'] ?? null;
                }

                if ($projectId === null) {
                    return new JSONResponse(
                        data: [
                            'success' => false,
                            'message' => 'Failed to create or find project',
                        ],
                        statusCode: 500
                    );
                }

                // Get workflow count for this project.
                $workflowsResponse = $client->get(
                    $url.'/api/v1/workflows?projectId='.$projectId,
                    [
                        'headers' => [
                            'Accept'        => 'application/json',
                            'X-N8N-API-KEY' => $apiKey,
                        ],
                    ]
                );

                $workflows = json_decode($workflowsResponse->getBody(), true);

                return new JSONResponse(
                    data: [
                        'success' => true,
                        'message' => 'n8n project initialized successfully',
                        'details' => [
                            'project'   => $project,
                            'projectId' => $projectId,
                            'workflows' => count($workflows['data'] ?? []),
                        ],
                    ]
                );
            } catch (Exception $e) {
                $this->logger->error('Project initialization failed: '.$e->getMessage());
                return new JSONResponse(
                    data: [
                        'success' => false,
                        'message' => 'Project initialization failed: '.$e->getMessage(),
                    ],
                    statusCode: 500
                );
            }//end try
        } catch (Exception $e) {
            $this->logger->error('n8n initialization failed: '.$e->getMessage());
            return new JSONResponse(
                data: [
                    'success' => false,
                    'message' => 'n8n initialization failed: '.$e->getMessage(),
                ],
                statusCode: 500
            );
        }//end try
    }//end initializeN8n()

    /**
     * Get workflows from n8n.
     *
     * Retrieves the list of workflows in the configured project.
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with workflows list
     */
    public function getWorkflows(): JSONResponse
    {
        try {
            // Get current settings.
            $settings = $this->configHandler->getN8nSettingsOnly();
            $url      = $settings['url'] ?? '';
            $apiKey   = $settings['apiKey'] ?? '';
            $project  = $settings['project'] ?? 'openregister';

            if (empty($url) === true || empty($apiKey) === true) {
                return new JSONResponse(
                    data: [
                        'success' => false,
                        'message' => 'n8n connection not configured',
                    ],
                    statusCode: 400
                );
            }

            // Ensure URL doesn't end with slash.
            $url = rtrim($url, '/');

            $client = $this->clientService->newClient();

            // Get project ID.
            $projectsResponse = $client->get(
                $url.'/api/v1/projects',
                [
                    'headers' => [
                        'Accept'        => 'application/json',
                        'X-N8N-API-KEY' => $apiKey,
                    ],
                ]
            );

            $projects  = json_decode($projectsResponse->getBody(), true);
            $projectId = null;

            foreach ($projects['data'] ?? [] as $proj) {
                if ($proj['name'] === $project) {
                    $projectId = $proj['id'];
                    break;
                }
            }

            if ($projectId === null) {
                return new JSONResponse(
                    data: [
                        'success'   => true,
                        'workflows' => [],
                        'message'   => 'Project not found. Please initialize first.',
                    ]
                );
            }

            // Get workflows.
            $workflowsResponse = $client->get(
                $url.'/api/v1/workflows?projectId='.$projectId,
                [
                    'headers' => [
                        'Accept'        => 'application/json',
                        'X-N8N-API-KEY' => $apiKey,
                    ],
                ]
            );

            $workflows = json_decode($workflowsResponse->getBody(), true);

            return new JSONResponse(
                data: [
                    'success'   => true,
                    'workflows' => $workflows['data'] ?? [],
                ]
            );
        } catch (Exception $e) {
            $this->logger->error('Failed to get workflows: '.$e->getMessage());
            return new JSONResponse(
                data: [
                    'success' => false,
                    'message' => 'Failed to get workflows: '.$e->getMessage(),
                ],
                statusCode: 500
            );
        }//end try
    }//end getWorkflows()
}//end class
