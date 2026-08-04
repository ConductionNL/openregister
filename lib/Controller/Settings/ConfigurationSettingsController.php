<?php

/**
 * OpenRegister Configuration Settings Controller
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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
     * @param LoggerInterface $logger          Logger.
     */
    public function __construct(
        $appName,
        IRequest $request,
        private readonly SettingsService $settingsService,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * Get RBAC settings only
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with RBAC settings
     *
     * @spec openspec/specs/rbac-scopes/spec.md
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
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with updated RBAC settings
     *
     * @spec openspec/specs/rbac-scopes/spec.md
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
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with organisation settings
     *
     * @spec openspec/specs/tenant-lifecycle/spec.md
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
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with updated organisation settings
     *
     * @spec openspec/specs/tenant-lifecycle/spec.md
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
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with multitenancy settings
     *
     * @spec openspec/specs/tenant-lifecycle/spec.md
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
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with updated multitenancy settings
     *
     * @spec openspec/specs/tenant-lifecycle/spec.md
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
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with object settings
     *
     * @spec openspec/specs/production-observability/spec.md
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
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with updated object settings
     *
     * @spec openspec/specs/production-observability/spec.md
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
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with patched object settings
     *
     * @spec openspec/specs/production-observability/spec.md
     */
    public function patchObjectSettings(): JSONResponse
    {
        return $this->updateObjectSettings();
    }//end patchObjectSettings()

    /**
     * Get Retention settings only
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with retention settings
     *
     * @spec openspec/specs/retention-management/spec.md
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
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with updated retention settings
     *
     * @spec openspec/specs/retention-management/spec.md
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
     * Get archival settings (destruction scheduling, selectielijst, etc.)
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with archival settings
     *
     * @spec openspec/specs/archival-destruction-workflow/spec.md
     */
    public function getArchivalSettings(): JSONResponse
    {
        try {
            $data = $this->settingsService->getArchivalSettingsOnly();
            return new JSONResponse(data: $data);
        } catch (Exception $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
        }
    }//end getArchivalSettings()

    /**
     * Update archival settings
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with updated archival settings
     *
     * @spec openspec/specs/archival-destruction-workflow/spec.md
     */
    public function updateArchivalSettings(): JSONResponse
    {
        try {
            $data   = $this->request->getParams();
            $result = $this->settingsService->updateArchivalSettingsOnly($data);
            return new JSONResponse(data: $result);
        } catch (Exception $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
        }
    }//end updateArchivalSettings()
}//end class
