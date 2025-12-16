<?php

/**
 * OpenRegister API Token Settings Controller
 *
 * @category  Controller
 * @package   OCA\OpenRegister\Controller\Settings
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Settings;

use OCP\IAppConfig;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Exception;
use OCA\OpenRegister\Service\SettingsService;
use Psr\Log\LoggerInterface;

/**
 * Controller for API token management.
 *
 * Handles:
 * - GitHub API tokens
 * - GitLab API tokens
 * - Token testing and validation
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Settings
 */
class ApiTokenSettingsController extends Controller
{


    /**
     * Constructor.
     *
     * @param string          $appName         The app name.
     * @param IRequest        $request         The request.
     * @param IAppConfig      $config          App configuration.
     * @param SettingsService $settingsService Settings service.
     * @param LoggerInterface $logger          Logger.
     */
    public function __construct(
        $appName,
        IRequest $request,
        private readonly IAppConfig $config,
        private readonly SettingsService $settingsService,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: $appName, request: $request);

    }//end __construct()


    /**
     * Get API tokens for GitHub and GitLab
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse The API tokens
     *
     * @psalm-return JSONResponse<200|500, array{error?: string, github_token?: string, gitlab_token?: string, gitlab_url?: string}, array<never, never>>
     */
    public function getApiTokens(): JSONResponse
    {
    try {
        $githubToken = $this->config->getValueString('openregister', 'github_api_token', '');
        $gitlabToken = $this->config->getValueString('openregister', 'gitlab_api_token', '');
        $gitlabUrl   = $this->config->getValueString('openregister', 'gitlab_api_url', '');

        // Mask tokens for security (only show first/last few characters).
        $maskedGithubToken = '';
        if ($githubToken !== '') {
            $maskedGithubToken = $this->settingsService->maskToken($githubToken);
        }

        $maskedGithubToken = '';
        if ($gitlabToken !== '') {
            $maskedGitlabToken = $this->settingsService->maskToken($gitlabToken);
        }

        return new JSONResponse(
                data: [
                    'github_token' => $maskedGithubToken,
                    'gitlab_token' => $maskedGitlabToken,
                    'gitlab_url'   => $gitlabUrl,
                ]
                );
    } catch (Exception $e) {
        return new JSONResponse(
            data: [
                'error' => 'Failed to retrieve API tokens: '.$e->getMessage(),
            ],
            statusCode: 500
        );
    }//end try

    }//end getApiTokens()


    /**
     * Save API tokens for GitHub and GitLab
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse Success or error message
     *
     * @psalm-return JSONResponse<200|500, array{error?: string, success?: true, message?: 'API tokens saved successfully'}, array<never, never>>
     */
    public function saveApiTokens(): JSONResponse
    {
        try {
            $data = $this->request->getParams();

            if (($data['github_token'] ?? null) !== null) {
                // Only save if not masked.
                if (str_contains($data['github_token'], '***') === false) {
                    $this->config->setValueString('openregister', 'github_api_token', $data['github_token']);
                }
            }

            if (($data['gitlab_token'] ?? null) !== null) {
                // Only save if not masked.
                if (str_contains($data['gitlab_token'], '***') === false) {
                    $this->config->setValueString('openregister', 'gitlab_api_token', $data['gitlab_token']);
                }
            }

            if (($data['gitlab_url'] ?? null) !== null) {
                $this->config->setValueString('openregister', 'gitlab_api_url', $data['gitlab_url']);
            }

            return new JSONResponse(
                    data: [
                        'success' => true,
                        'message' => 'API tokens saved successfully',
                    ]
                    );
        } catch (Exception $e) {
            return new JSONResponse(
                data: [
                    'error' => 'Failed to save API tokens: '.$e->getMessage(),
                ],
                statusCode: 500
            );
        }//end try

    }//end saveApiTokens()


    /**
     * Test GitHub API token
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse Test result
     */
    public function testGitHubToken(): JSONResponse
    {
        try {
            $data  = $this->request->getParams();
            $token = $data['token'] ?? $this->config->getValueString('openregister', 'github_api_token', '');

            if (empty($token) === true) {
                return new JSONResponse(
                        data: [
                            'success' => false,
                            'message' => 'No GitHub token provided',
                        ],
                        statusCode: 400
                    );
            }

            // Test the token by making a simple API call.
            $client   = \OC::$server->get(\OCP\Http\Client\IClientService::class)->newClient();
            $response = $client->get(
                    'https://api.github.com/user',
                    [
                        'headers' => [
                            'Accept'               => 'application/vnd.github+json',
                            'Authorization'        => 'Bearer '.$token,
                            'X-GitHub-Api-Version' => '2022-11-28',
                        ],
                    ]
                    );

            $data = json_decode($response->getBody(), true);

            return new JSONResponse(
                    data: [
                        'success'  => true,
                        'message'  => 'GitHub token is valid',
                        'username' => $data['login'] ?? 'Unknown',
                        'scopes'   => $response->getHeader('X-OAuth-Scopes') ?? [],
                    ]
                    );
        } catch (Exception $e) {
            return new JSONResponse(
                    data: [
                        'success' => false,
                        'message' => 'GitHub token test failed: '.$e->getMessage(),
                    ],
                    statusCode: 400
                );
        }//end try

    }//end testGitHubToken()


    /**
     * Test GitLab API token
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse Test result
     */
    public function testGitLabToken(): JSONResponse
    {
        try {
            $data   = $this->request->getParams();
            $token  = $data['token'] ?? $this->config->getValueString('openregister', 'gitlab_api_token', '');
            $apiUrl = $data['url'] ?? $this->config->getValueString('openregister', 'gitlab_api_url', 'https://gitlab.com/api/v4');

            if (empty($token) === true) {
                return new JSONResponse(
                        data: [
                            'success' => false,
                            'message' => 'No GitLab token provided',
                        ],
                        statusCode: 400
                    );
            }

            // Ensure API URL doesn't end with slash.
            $apiUrl = rtrim($apiUrl, '/');

            // Default to gitlab.com if no URL provided.
            if (empty($apiUrl) === true) {
                $apiUrl = 'https://gitlab.com/api/v4';
            }

            // Test the token by making a simple API call.
            $client   = \OC::$server->get(\OCP\Http\Client\IClientService::class)->newClient();
            $response = $client->get(
                    $apiUrl.'/user',
                    [
                        'headers' => [
                            'PRIVATE-TOKEN' => $token,
                        ],
                    ]
                    );

            $data = json_decode($response->getBody(), true);

            return new JSONResponse(
                    data: [
                        'success'  => true,
                        'message'  => 'GitLab token is valid',
                        'username' => $data['username'] ?? 'Unknown',
                        'instance' => $apiUrl,
                    ]
                    );
        } catch (Exception $e) {
            return new JSONResponse(
                    data: [
                        'success' => false,
                        'message' => 'GitLab token test failed: '.$e->getMessage(),
                    ],
                    statusCode: 400
                );
        }//end try

    }//end testGitLabToken()


    }//end class
