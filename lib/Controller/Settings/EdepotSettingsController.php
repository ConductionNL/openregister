<?php

/**
 * OpenRegister e-Depot Settings Controller
 *
 * Handles e-Depot endpoint configuration and connection testing.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Settings
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

namespace OCA\OpenRegister\Controller\Settings;

use OCA\OpenRegister\Service\Edepot\EdepotTransferService;
use OCA\OpenRegister\Service\Edepot\Transport\OpenConnectorTransport;
use OCA\OpenRegister\Service\Edepot\Transport\RestApiTransport;
use OCA\OpenRegister\Service\Edepot\Transport\SftpTransport;
use OCA\OpenRegister\Service\Edepot\Transport\TransportInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Controller for e-Depot settings management.
 *
 * Provides endpoints for configuring the e-Depot endpoint, transport protocol,
 * authentication, and SIP profile. Also supports connection testing.
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class EdepotSettingsController extends Controller
{
    /**
     * Constructor.
     *
     * @param string                 $appName         The app name.
     * @param IRequest               $request         The request.
     * @param IAppConfig             $appConfig       The app configuration.
     * @param EdepotTransferService  $transferService The transfer service.
     * @param SftpTransport          $sftpTransport   SFTP transport.
     * @param RestApiTransport       $restTransport   REST API transport.
     * @param OpenConnectorTransport $ocTransport     OpenConnector transport.
     * @param LoggerInterface        $logger          Logger.
     */
    public function __construct(
        $appName,
        IRequest $request,
        private readonly IAppConfig $appConfig,
        private readonly EdepotTransferService $transferService,
        private readonly SftpTransport $sftpTransport,
        private readonly RestApiTransport $restTransport,
        private readonly OpenConnectorTransport $ocTransport,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * Get e-Depot settings.
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse The current e-Depot configuration.
     */
    public function getEdepotSettings(): JSONResponse
    {
        try {
            $config = $this->transferService->getTransportConfig();

            // Mask sensitive values.
            if (empty($config['apiKey']) === false) {
                $config['apiKey'] = '***';
            }

            if (empty($config['bearerToken']) === false) {
                $config['bearerToken'] = '***';
            }

            if (empty($config['password']) === false) {
                $config['password'] = '***';
            }

            $config['availableProfiles'] = $this->transferService->getAvailableProfiles();

            return new JSONResponse(data: $config);
        } catch (\Exception $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
        }//end try
    }//end getEdepotSettings()

    /**
     * Update e-Depot settings.
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse The update result.
     */
    public function updateEdepotSettings(): JSONResponse
    {
        try {
            $params = $this->request->getParams();

            // Validate SIP profile.
            $sipProfile = ($params['sipProfile'] ?? 'default');
            if ($this->transferService->isValidProfile($sipProfile) === false) {
                $available = implode(', ', array_keys($this->transferService->getAvailableProfiles()));
                return new JSONResponse(
                    data: ['error' => "Invalid SIP profile '{$sipProfile}'. Available: {$available}"],
                    statusCode: 400
                );
            }

            // Store configuration values.
            $configMap = [
                'endpointUrl'        => 'edepot_endpoint_url',
                'authenticationType' => 'edepot_auth_type',
                'apiKey'             => 'edepot_api_key',
                'bearerToken'        => 'edepot_bearer_token',
                'targetArchive'      => 'edepot_target_archive',
                'sipProfile'         => 'edepot_sip_profile',
                'transport'          => 'edepot_transport',
                'host'               => 'edepot_sftp_host',
                'port'               => 'edepot_sftp_port',
                'username'           => 'edepot_sftp_username',
                'password'           => 'edepot_sftp_password',
                'keyPath'            => 'edepot_sftp_key_path',
                'remotePath'         => 'edepot_sftp_remote_path',
                'sourceId'           => 'edepot_openconnector_source_id',
                'baseUrl'            => 'edepot_openconnector_base_url',
            ];

            foreach ($configMap as $paramKey => $configKey) {
                if (isset($params[$paramKey]) === true) {
                    $value = (string) $params[$paramKey];
                    // Skip masked values (don't overwrite secrets with '***').
                    if ($value === '***') {
                        continue;
                    }

                    $this->appConfig->setValueString('openregister', $configKey, $value);
                }
            }

            // Test connection if requested.
            $testResult = null;
            if (isset($params['testConnection']) === true && $params['testConnection'] === true) {
                $transport  = $this->resolveTransport(($params['transport'] ?? 'rest_api'));
                $config     = $this->transferService->getTransportConfig();
                $testResult = $transport->testConnection($config);
            }

            $response = ['success' => true];
            if ($testResult !== null) {
                $response['connectionTest'] = $testResult;
            }

            return new JSONResponse(data: $response);
        } catch (\Exception $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
        }//end try
    }//end updateEdepotSettings()

    /**
     * Test e-Depot connection.
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse The connection test result.
     */
    public function testEdepotConnection(): JSONResponse
    {
        try {
            $config    = $this->transferService->getTransportConfig();
            $transport = $this->resolveTransport(($config['transport'] ?? 'rest_api'));
            $result    = $transport->testConnection($config);

            return new JSONResponse(
                    data: [
                        'success'   => $result,
                        'transport' => $transport->getName(),
                        'message'   => ($result === true) ? 'Connection successful' : 'Connection failed',
                    ]
                    );
        } catch (\Exception $e) {
            return new JSONResponse(
                data: [
                    'success' => false,
                    'error'   => $e->getMessage(),
                ],
                statusCode: 500
            );
        }//end try
    }//end testEdepotConnection()

    /**
     * Resolve transport implementation by type name.
     *
     * @param string $type The transport type.
     *
     * @return TransportInterface The transport.
     */
    private function resolveTransport(string $type): TransportInterface
    {
        switch ($type) {
            case 'sftp':
                return $this->sftpTransport;
            case 'openconnector':
                return $this->ocTransport;
            case 'rest_api':
            default:
                return $this->restTransport;
        }
    }//end resolveTransport()
}//end class
