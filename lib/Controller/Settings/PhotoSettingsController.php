<?php

/**
 * PhotoSettingsController — Admin settings for the Photos integration.
 *
 * Exposes the GPS-strip admin toggle (default OFF) described in the
 * integration-photos spec and requirement "Optional GPS Stripping."
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/integration-photos/tasks.md#task-5
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Settings;

use Exception;
use OCA\OpenRegister\Service\PhotoService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IRequest;

/**
 * Admin controller for Photos integration settings.
 *
 * Currently exposes only the GPS-strip toggle. All endpoints are admin-only.
 *
 * @psalm-suppress UnusedClass
 */
class PhotoSettingsController extends Controller
{

    /**
     * App name constant.
     */
    private const APP_NAME = 'openregister';

    /**
     * Constructor.
     *
     * @param string   $appName Application name.
     * @param IRequest $request HTTP request.
     * @param IConfig  $config  Nextcloud app config.
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly IConfig $config
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * Get current Photos integration admin settings.
     *
     * @return JSONResponse Settings as JSON.
     *
     * @NoCSRFRequired
     *
     * @spec openspec/changes/integration-photos/tasks.md#task-5
     */
    public function getPhotoSettings(): JSONResponse
    {
        try {
            // phpcs:disable CustomSn.Functions.NamedParameters -- IConfig uses positional params (__call magic)
            $value = $this->config->getAppValue(self::APP_NAME, PhotoService::CONFIG_STRIP_GPS, 'false');
            // phpcs:enable
            return new JSONResponse(data: ['stripGps' => $value === 'true']);
        } catch (Exception $e) {
            return new JSONResponse(data: ['error' => 'Operation failed'], statusCode: 500);
        }//end try
    }//end getPhotoSettings()

    /**
     * Update Photos integration admin settings.
     *
     * Accepts: { "stripGps": true|false }
     *
     * @return JSONResponse Updated settings.
     *
     * @NoCSRFRequired
     *
     * @spec openspec/changes/integration-photos/tasks.md#task-5
     */
    public function updatePhotoSettings(): JSONResponse
    {
        try {
            $data     = $this->request->getParams();
            $stripGps = filter_var($data['stripGps'] ?? false, FILTER_VALIDATE_BOOLEAN);

            $newValue = $stripGps === true ? 'true' : 'false';
            // phpcs:disable CustomSn.Functions.NamedParameters -- IConfig uses positional params (__call magic)
            $this->config->setAppValue(self::APP_NAME, PhotoService::CONFIG_STRIP_GPS, $newValue);
            // phpcs:enable

            return new JSONResponse(data: ['stripGps' => $stripGps]);
        } catch (Exception $e) {
            return new JSONResponse(data: ['error' => 'Operation failed'], statusCode: 500);
        }//end try
    }//end updatePhotoSettings()
}//end class
