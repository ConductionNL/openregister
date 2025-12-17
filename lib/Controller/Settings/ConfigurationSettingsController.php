<?php

/**
 * OpenRegister Configuration Settings Controller
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

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Exception;
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Service\IndexService;
use Psr\Log\LoggerInterface;

/**
 * Controller for system configuration settings.
 *
 * Handles:
 * - RBAC settings
 * - Organisation settings
 * - Multitenancy configuration
 * - Object settings
 * - Retention policies
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Settings
 */
class ConfigurationSettingsController extends Controller
{


    /**
     * Constructor.
     *
     * @param string          $appName         The app name.
     * @param IRequest        $request         The request.
     * @param SettingsService $settingsService Settings service.
     * @param IndexService    $indexService    Index service.
     * @param LoggerInterface $logger          Logger.
     */
    public function __construct(
        $appName,
        IRequest $request,
        private readonly SettingsService $settingsService,
        private readonly IndexService $indexService,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: $appName, request: $request);

    }//end __construct()


    /**
     * Get RBAC settings only
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse RBAC configuration
     *
     * @psalm-return JSONResponse<200|500, array, array<never, never>>
     */
    public function getRbacSettings(): JSONResponse
    {
        try {
            $data = $this->settingsService->getRbacSettingsOnly();
            return new JSONResponse(data: $data);
        } catch (Exception $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
        }

    }//end getRbacSettings()


    /**
     * Update RBAC settings only
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse Updated RBAC configuration
     *
     * @psalm-return JSONResponse<200|500, array, array<never, never>>
     */
    public function updateRbacSettings(): JSONResponse
    {
        try {
            $data   = $this->request->getParams();
            $result = $this->settingsService->updateRbacSettingsOnly($data);
            return new JSONResponse(data: $result);
        } catch (Exception $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
        }

    }//end updateRbacSettings()


    /**
     * Get Organisation settings only
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse Organisation configuration
     *
     * @psalm-return JSONResponse<200|500, array, array<never, never>>
     */
    public function getOrganisationSettings(): JSONResponse
    {
        try {
            $data = $this->settingsService->getOrganisationSettingsOnly();
            return new JSONResponse(data: $data);
        } catch (Exception $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
        }

    }//end getOrganisationSettings()


    /**
     * Update Organisation settings only
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse Updated Organisation configuration
     *
     * @psalm-return JSONResponse<200|500, array, array<never, never>>
     */
    public function updateOrganisationSettings(): JSONResponse
    {
        try {
            $data   = $this->request->getParams();
            $result = $this->settingsService->updateOrganisationSettingsOnly($data);
            return new JSONResponse(data: $result);
        } catch (Exception $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
        }

    }//end updateOrganisationSettings()


    /**
     * Get Multitenancy settings only
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse Multitenancy configuration
     *
     * @psalm-return JSONResponse<200|500, array, array<never, never>>
     */
    public function getMultitenancySettings(): JSONResponse
    {
        try {
            $data = $this->settingsService->getMultitenancySettingsOnly();
            return new JSONResponse(data: $data);
        } catch (Exception $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
        }

    }//end getMultitenancySettings()


    /**
     * Update Multitenancy settings only
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse Updated Multitenancy configuration
     *
     * @psalm-return JSONResponse<200|500, array, array<never, never>>
     */
    public function updateMultitenancySettings(): JSONResponse
    {
        try {
            $data   = $this->request->getParams();
            $result = $this->settingsService->updateMultitenancySettingsOnly($data);
            return new JSONResponse(data: $result);
        } catch (Exception $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
        }

    }//end updateMultitenancySettings()


    /**
     * Get Object settings only
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse Object configuration
     *
     * @psalm-return JSONResponse<200|500, array{success: bool, error?: string, data?: array}, array<never, never>>
     */
    public function getObjectSettings(): JSONResponse
    {
        try {
            $settings = $this->settingsService->getObjectSettingsOnly();
            return new JSONResponse(
                    data: [
                        'success' => true,
                        'data'    => $settings,
                    ]
                    );
        } catch (Exception $e) {
            return new JSONResponse(
                    data: [
                        'success' => false,
                        'error'   => $e->getMessage(),
                    ],
                    statusCode: 500
                );
        }

    }//end getObjectSettings()


    /**
     * Update Object Management settings
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse Updated object settings
     *
     * @psalm-return JSONResponse<200|500, array{success: bool, error?: string, message?: 'Object settings updated successfully', data?: array}, array<never, never>>
     */
    public function updateObjectSettings(): JSONResponse
    {
        try {
            $data = $this->request->getParams();

            // Extract IDs from objects sent by frontend.
            if (($data['provider'] ?? null) !== null && is_array($data['provider']) === true) {
                $data['provider'] = $data['provider']['id'] ?? null;
            }

            $result = $this->settingsService->updateObjectSettingsOnly($data);
            return new JSONResponse(
                    data: [
                        'success' => true,
                        'message' => 'Object settings updated successfully',
                        'data'    => $result,
                    ]
                    );
        } catch (Exception $e) {
            return new JSONResponse(
                    data: [
                        'success' => false,
                        'error'   => $e->getMessage(),
                    ],
                    statusCode: 500
                );
        }//end try

    }//end updateObjectSettings()


    /**
     * PATCH Object settings (delegates to updateObjectSettings)
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse Updated settings
     */
    public function patchObjectSettings(): JSONResponse
    {
        return $this->updateObjectSettings();

    }//end patchObjectSettings()


    /**
     * Get Retention settings only
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse Retention configuration
     *
     * @psalm-return JSONResponse<200|500, array, array<never, never>>
     */
    public function getRetentionSettings(): JSONResponse
    {
        try {
            $data = $this->settingsService->getRetentionSettingsOnly();
            return new JSONResponse(data: $data);
        } catch (Exception $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
        }

    }//end getRetentionSettings()


    /**
     * Update Retention settings only
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse Updated Retention configuration
     *
     * @psalm-return JSONResponse<200|500, array, array<never, never>>
     */
    public function updateRetentionSettings(): JSONResponse
    {
        try {
            $data   = $this->request->getParams();
            $result = $this->settingsService->updateRetentionSettingsOnly($data);
            return new JSONResponse(data: $result);
        } catch (Exception $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
        }

    }//end updateRetentionSettings()


    /**
     * Get object collection field status
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse Field status for object collection
     *
     * @psalm-return JSONResponse<200|500, array{success: bool, message?: string, collection?: 'objects', status?: mixed}, array<never, never>>
     */
    public function getObjectCollectionFields(): JSONResponse
    {
        try {
            $solrSchemaService = $this->indexService;
            $status            = $solrSchemaService->getObjectCollectionFieldStatus();

            return new JSONResponse(
                    data: [
                        'success'    => true,
                        'collection' => 'objects',
                        'status'     => $status,
                    ]
                    );
        } catch (Exception $e) {
            return new JSONResponse(
                    data: [
                        'success' => false,
                        'message' => 'Failed to get object collection field status: '.$e->getMessage(),
                    ],
                    statusCode: 500
                );
        }

    }//end getObjectCollectionFields()


    /**
     * Create missing fields in object collection
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse Creation results
     *
     * @psalm-return JSONResponse<200|400|500, array{success: bool, message: string, collection?: 'objects', result?: mixed}, array<never, never>>
     */
    public function createMissingObjectFields(): JSONResponse
    {
        try {
            $solrSchemaService = $this->indexService;

            // Switch to object collection.
            $objectCollection = $this->settingsService->getSolrSettingsOnly()['objectCollection'] ?? null;
            if ($objectCollection === null || $objectCollection === '') {
                return new JSONResponse(
                        data: [
                            'success' => false,
                            'message' => 'Object collection not configured',
                        ],
                        statusCode: 400
                    );
            }

            // Create missing fields.
            $result = $solrSchemaService->mirrorSchemas(force: true);

            return new JSONResponse(
                    data: [
                        'success'    => true,
                        'collection' => 'objects',
                        'message'    => 'Missing object fields created successfully',
                        'result'     => $result,
                    ]
                    );
        } catch (Exception $e) {
            return new JSONResponse(
                    data: [
                        'success' => false,
                        'message' => 'Failed to create missing object fields: '.$e->getMessage(),
                    ],
                    statusCode: 500
                );
        }//end try

    }//end createMissingObjectFields()


}//end class
