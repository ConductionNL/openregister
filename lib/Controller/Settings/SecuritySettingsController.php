<?php

/**
 * OpenRegister Security Settings Controller
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
use OCA\OpenRegister\Service\SecurityService;
use Psr\Log\LoggerInterface;

/**
 * Controller for security settings management.
 *
 * Handles:
 * - Rate limit management
 * - IP blocking management
 * - User lockout management
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Settings
 */
class SecuritySettingsController extends Controller
{
    /**
     * Constructor.
     *
     * @param string          $appName         The app name.
     * @param IRequest        $request         The request.
     * @param SecurityService $securityService Security service.
     * @param LoggerInterface $logger          Logger.
     */
    public function __construct(
        $appName,
        IRequest $request,
        private readonly SecurityService $securityService,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * Clear rate limits for a specific IP address.
     *
     * This method allows administrators to unblock an IP that has been
     * temporarily blocked due to suspicious activity.
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with result
     */
    public function clearIpRateLimits(): JSONResponse
    {
        try {
            $data      = $this->request->getParams();
            $ipAddress = $data['ip'] ?? null;

            if (empty($ipAddress) === true) {
                return new JSONResponse(
                    data: ['error' => 'IP address is required'],
                    statusCode: 400
                );
            }

            $this->securityService->clearIpRateLimits(ipAddress: $ipAddress);

            $this->logger->info(
                message: '[SecuritySettingsController] IP rate limits cleared by admin',
                context: ['file' => __FILE__, 'line' => __LINE__, 'ip_address' => $ipAddress]
            );

            return new JSONResponse(
                data: [
                    'success'    => true,
                    'message'    => 'IP rate limits cleared successfully',
                    'ip_address' => $ipAddress,
                ]
            );
        } catch (Exception $e) {
            $this->logger->error(
                message: '[SecuritySettingsController] Failed to clear IP rate limits',
                context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
            );

            return new JSONResponse(
                data: ['error' => $e->getMessage()],
                statusCode: 500
            );
        }//end try
    }//end clearIpRateLimits()

    /**
     * Clear rate limits for a specific user.
     *
     * This method allows administrators to unblock a user account that has been
     * temporarily locked due to too many failed login attempts.
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with result
     */
    public function clearUserRateLimits(): JSONResponse
    {
        try {
            $data     = $this->request->getParams();
            $username = $data['username'] ?? null;

            if (empty($username) === true) {
                return new JSONResponse(
                    data: ['error' => 'Username is required'],
                    statusCode: 400
                );
            }

            $this->securityService->clearUserRateLimits(username: $username);

            $this->logger->info(
                message: '[SecuritySettingsController] User rate limits cleared by admin',
                context: ['file' => __FILE__, 'line' => __LINE__, 'username' => $username]
            );

            return new JSONResponse(
                data: [
                    'success'  => true,
                    'message'  => 'User rate limits cleared successfully',
                    'username' => $username,
                ]
            );
        } catch (Exception $e) {
            $this->logger->error(
                message: '[SecuritySettingsController] Failed to clear user rate limits',
                context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
            );

            return new JSONResponse(
                data: ['error' => $e->getMessage()],
                statusCode: 500
            );
        }//end try
    }//end clearUserRateLimits()

    /**
     * Clear all rate limits (IP and user) at once.
     *
     * This method allows administrators to unblock both an IP and user
     * in a single request.
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with result
     */
    public function clearAllRateLimits(): JSONResponse
    {
        try {
            $data      = $this->request->getParams();
            $ipAddress = $data['ip'] ?? null;
            $username  = $data['username'] ?? null;

            if (empty($ipAddress) === true && empty($username) === true) {
                return new JSONResponse(
                    data: ['error' => 'At least one of IP address or username is required'],
                    statusCode: 400
                );
            }

            $cleared = [];

            if (empty($ipAddress) === false) {
                $this->securityService->clearIpRateLimits(ipAddress: $ipAddress);
                $cleared['ip_address'] = $ipAddress;
            }

            if (empty($username) === false) {
                $this->securityService->clearUserRateLimits(username: $username);
                $cleared['username'] = $username;
            }

            $this->logger->info(
                message: '[SecuritySettingsController] Rate limits cleared by admin',
                context: array_merge(['file' => __FILE__, 'line' => __LINE__], $cleared)
            );

            return new JSONResponse(
                data: [
                    'success' => true,
                    'message' => 'Rate limits cleared successfully',
                    'cleared' => $cleared,
                ]
            );
        } catch (Exception $e) {
            $this->logger->error(
                message: '[SecuritySettingsController] Failed to clear rate limits',
                context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
            );

            return new JSONResponse(
                data: ['error' => $e->getMessage()],
                statusCode: 500
            );
        }//end try
    }//end clearAllRateLimits()
}//end class
