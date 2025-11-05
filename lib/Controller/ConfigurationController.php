<?php
/**
 * OpenRegister Configuration Controller
 *
 * This file contains the controller class for handling configuration-related API requests.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

namespace OCA\OpenRegister\Controller;

use Exception;
use GuzzleHttp\Exception\GuzzleException;
use OCA\OpenRegister\Db\Configuration;
use OCA\OpenRegister\Db\ConfigurationMapper;
use OCA\OpenRegister\Service\ConfigurationService;
use OCA\OpenRegister\Service\NotificationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Class ConfigurationController
 *
 * Controller for managing configurations (CRUD and management operations).
 *
 * @package OCA\OpenRegister\Controller
 */
class ConfigurationController extends Controller
{

    /**
     * Configuration mapper instance.
     *
     * @var ConfigurationMapper The configuration mapper instance.
     */
    private ConfigurationMapper $configurationMapper;

    /**
     * Configuration service instance.
     *
     * @var ConfigurationService The configuration service instance.
     */
    private ConfigurationService $configurationService;

    /**
     * Notification service instance.
     *
     * @var NotificationService The notification service instance.
     */
    private NotificationService $notificationService;

    /**
     * Logger instance.
     *
     * @var LoggerInterface The logger instance.
     */
    private LoggerInterface $logger;


    /**
     * Constructor
     *
     * @param string               $appName              The app name
     * @param IRequest             $request              The request object
     * @param ConfigurationMapper  $configurationMapper  Configuration mapper
     * @param ConfigurationService $configurationService Configuration service
     * @param NotificationService  $notificationService  Notification service
     * @param LoggerInterface      $logger               Logger
     */
    public function __construct(
        string $appName,
        IRequest $request,
        ConfigurationMapper $configurationMapper,
        ConfigurationService $configurationService,
        NotificationService $notificationService,
        LoggerInterface $logger
    ) {
        parent::__construct($appName, $request);
        
        $this->configurationMapper  = $configurationMapper;
        $this->configurationService = $configurationService;
        $this->notificationService  = $notificationService;
        $this->logger               = $logger;

    }//end __construct()


    /**
     * Get all configurations.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse List of configurations
     */
    public function index(): JSONResponse
    {
        try {
            $configurations = $this->configurationMapper->findAll();
            
            return new JSONResponse($configurations, 200);
        } catch (Exception $e) {
            $this->logger->error('Failed to fetch configurations: '.$e->getMessage());
            
            return new JSONResponse(
                ['error' => 'Failed to fetch configurations'],
                500
            );
        }//end try

    }//end index()


    /**
     * Get a single configuration by ID.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param int $id The configuration ID
     *
     * @return JSONResponse The configuration details
     */
    public function show(int $id): JSONResponse
    {
        try {
            $configuration = $this->configurationMapper->find($id);
            
            return new JSONResponse($configuration, 200);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return new JSONResponse(
                ['error' => 'Configuration not found'],
                404
            );
        } catch (Exception $e) {
            $this->logger->error("Failed to fetch configuration {$id}: ".$e->getMessage());
            
            return new JSONResponse(
                ['error' => 'Failed to fetch configuration'],
                500
            );
        }//end try

    }//end show()


    /**
     * Create a new configuration.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse The created configuration
     */
    public function create(): JSONResponse
    {
        try {
            $data = $this->request->getParams();
            
            $configuration = new Configuration();
            $configuration->setTitle($data['title'] ?? 'New Configuration');
            $configuration->setDescription($data['description'] ?? '');
            $configuration->setType($data['type'] ?? 'manual');
            $configuration->setSourceType($data['sourceType'] ?? 'local');
            $configuration->setSourceUrl($data['sourceUrl'] ?? null);
            $configuration->setApp($data['app'] ?? null);
            $configuration->setVersion($data['version'] ?? '1.0.0');
            $configuration->setLocalVersion($data['localVersion'] ?? null);
            $configuration->setRegisters($data['registers'] ?? []);
            $configuration->setSchemas($data['schemas'] ?? []);
            $configuration->setObjects($data['objects'] ?? []);
            $configuration->setAutoUpdate($data['autoUpdate'] ?? false);
            $configuration->setNotificationGroups($data['notificationGroups'] ?? []);
            $configuration->setGithubRepo($data['githubRepo'] ?? null);
            $configuration->setGithubBranch($data['githubBranch'] ?? null);
            $configuration->setGithubPath($data['githubPath'] ?? null);
            
            $created = $this->configurationMapper->insert($configuration);
            
            $this->logger->info("Created configuration: {$created->getTitle()} (ID: {$created->getId()})");
            
            return new JSONResponse($created, 201);
        } catch (Exception $e) {
            $this->logger->error('Failed to create configuration: '.$e->getMessage());
            
            return new JSONResponse(
                ['error' => 'Failed to create configuration: '.$e->getMessage()],
                500
            );
        }//end try

    }//end create()


    /**
     * Update an existing configuration.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param int $id The configuration ID
     *
     * @return JSONResponse The updated configuration
     */
    public function update(int $id): JSONResponse
    {
        try {
            $configuration = $this->configurationMapper->find($id);
            $data          = $this->request->getParams();
            
            // Update fields if provided
            if (isset($data['title']) === true) {
                $configuration->setTitle($data['title']);
            }

            if (isset($data['description']) === true) {
                $configuration->setDescription($data['description']);
            }

            if (isset($data['type']) === true) {
                $configuration->setType($data['type']);
            }

            if (isset($data['sourceType']) === true) {
                $configuration->setSourceType($data['sourceType']);
            }

            if (isset($data['sourceUrl']) === true) {
                $configuration->setSourceUrl($data['sourceUrl']);
            }

            if (isset($data['app']) === true) {
                $configuration->setApp($data['app']);
            }

            if (isset($data['version']) === true) {
                $configuration->setVersion($data['version']);
            }

            if (isset($data['localVersion']) === true) {
                $configuration->setLocalVersion($data['localVersion']);
            }

            if (isset($data['registers']) === true) {
                $configuration->setRegisters($data['registers']);
            }

            if (isset($data['schemas']) === true) {
                $configuration->setSchemas($data['schemas']);
            }

            if (isset($data['objects']) === true) {
                $configuration->setObjects($data['objects']);
            }

            if (isset($data['autoUpdate']) === true) {
                $configuration->setAutoUpdate($data['autoUpdate']);
            }

            if (isset($data['notificationGroups']) === true) {
                $configuration->setNotificationGroups($data['notificationGroups']);
            }

            if (isset($data['githubRepo']) === true) {
                $configuration->setGithubRepo($data['githubRepo']);
            }

            if (isset($data['githubBranch']) === true) {
                $configuration->setGithubBranch($data['githubBranch']);
            }

            if (isset($data['githubPath']) === true) {
                $configuration->setGithubPath($data['githubPath']);
            }

            $updated = $this->configurationMapper->update($configuration);
            
            $this->logger->info("Updated configuration: {$updated->getTitle()} (ID: {$updated->getId()})");
            
            return new JSONResponse($updated, 200);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return new JSONResponse(
                ['error' => 'Configuration not found'],
                404
            );
        } catch (Exception $e) {
            $this->logger->error("Failed to update configuration {$id}: ".$e->getMessage());
            
            return new JSONResponse(
                ['error' => 'Failed to update configuration: '.$e->getMessage()],
                500
            );
        }//end try

    }//end update()


    /**
     * Delete a configuration.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param int $id The configuration ID
     *
     * @return JSONResponse Success response
     */
    public function destroy(int $id): JSONResponse
    {
        try {
            $configuration = $this->configurationMapper->find($id);
            $this->configurationMapper->delete($configuration);
            
            $this->logger->info("Deleted configuration: {$configuration->getTitle()} (ID: {$id})");
            
            return new JSONResponse(['success' => true], 200);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return new JSONResponse(
                ['error' => 'Configuration not found'],
                404
            );
        } catch (Exception $e) {
            $this->logger->error("Failed to delete configuration {$id}: ".$e->getMessage());
            
            return new JSONResponse(
                ['error' => 'Failed to delete configuration'],
                500
            );
        }//end try

    }//end destroy()


    /**
     * Check remote version of a configuration.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param int $id The configuration ID
     *
     * @return JSONResponse Version information
     */
    public function checkVersion(int $id): JSONResponse
    {
        try {
            $configuration = $this->configurationMapper->find($id);
            
            // Check remote version
            $remoteVersion = $this->configurationService->checkRemoteVersion($configuration);
            
            if ($remoteVersion === null) {
                return new JSONResponse(
                    ['error' => 'Could not check remote version'],
                    500
                );
            }

            // Get version comparison
            $comparison = $this->configurationService->compareVersions($configuration);
            
            return new JSONResponse($comparison, 200);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return new JSONResponse(
                ['error' => 'Configuration not found'],
                404
            );
        } catch (GuzzleException $e) {
            $this->logger->error("Failed to check version for configuration {$id}: ".$e->getMessage());
            
            return new JSONResponse(
                ['error' => 'Failed to fetch remote version: '.$e->getMessage()],
                500
            );
        } catch (Exception $e) {
            $this->logger->error("Failed to check version for configuration {$id}: ".$e->getMessage());
            
            return new JSONResponse(
                ['error' => 'Failed to check version'],
                500
            );
        }//end try

    }//end checkVersion()


    /**
     * Preview configuration changes.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param int $id The configuration ID
     *
     * @return JSONResponse Preview of changes
     */
    public function preview(int $id): JSONResponse
    {
        try {
            $configuration = $this->configurationMapper->find($id);
            
            $preview = $this->configurationService->previewConfigurationChanges($configuration);
            
            if ($preview instanceof JSONResponse) {
                return $preview;
            }

            return new JSONResponse($preview, 200);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return new JSONResponse(
                ['error' => 'Configuration not found'],
                404
            );
        } catch (Exception $e) {
            $this->logger->error("Failed to preview configuration {$id}: ".$e->getMessage());
            
            return new JSONResponse(
                ['error' => 'Failed to preview configuration changes'],
                500
            );
        }//end try

    }//end preview()


    /**
     * Import configuration with user selection.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param int $id The configuration ID
     *
     * @return JSONResponse Import results
     */
    public function import(int $id): JSONResponse
    {
        try {
            $configuration = $this->configurationMapper->find($id);
            $data          = $this->request->getParams();
            $selection     = $data['selection'] ?? [];
            
            $result = $this->configurationService->importConfigurationWithSelection(
                $configuration,
                $selection
            );
            
            // Mark notifications as processed
            $this->notificationService->markConfigurationUpdated($configuration);
            
            $this->logger->info("Imported configuration {$configuration->getTitle()}: ".json_encode([
                'registers' => count($result['registers']),
                'schemas'   => count($result['schemas']),
                'objects'   => count($result['objects']),
            ]));
            
            return new JSONResponse([
                'success'         => true,
                'registersCount'  => count($result['registers']),
                'schemasCount'    => count($result['schemas']),
                'objectsCount'    => count($result['objects']),
            ], 200);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return new JSONResponse(
                ['error' => 'Configuration not found'],
                404
            );
        } catch (Exception $e) {
            $this->logger->error("Failed to import configuration {$id}: ".$e->getMessage());
            
            return new JSONResponse(
                ['error' => 'Failed to import configuration: '.$e->getMessage()],
                500
            );
        }//end try

    }//end import()


    /**
     * Export configuration to download or GitHub.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param int $id The configuration ID
     *
     * @return JSONResponse Export result with download URL or success message
     */
    public function export(int $id): JSONResponse
    {
        try {
            $configuration = $this->configurationMapper->find($id);
            $data          = $this->request->getParams();
            $format        = $data['format'] ?? 'json';
            $includeObjects = ($data['includeObjects'] ?? false) === true;
            
            // Export the configuration
            $exportData = $this->configurationService->exportConfig(
                $configuration,
                $includeObjects
            );
            
            // Return the export data directly for download
            return new JSONResponse($exportData, 200);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return new JSONResponse(
                ['error' => 'Configuration not found'],
                404
            );
        } catch (Exception $e) {
            $this->logger->error("Failed to export configuration {$id}: ".$e->getMessage());
            
            return new JSONResponse(
                ['error' => 'Failed to export configuration: '.$e->getMessage()],
                500
            );
        }//end try

    }//end export()


}//end class


