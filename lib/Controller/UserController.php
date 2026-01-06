<?php

/**
 * OpenRegister User Controller
 *
 * Controller for user management operations including profile retrieval
 * and update operations for the currently authenticated user.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use Exception;
use OCA\OpenRegister\Service\SecurityService;
use OCA\OpenRegister\Service\UserService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * UserController handles user-related API endpoints
 *
 * Provides REST API endpoints for user profile management
 * in the OpenRegister application.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @psalm-suppress UnusedClass
 */
class UserController extends Controller
{
    /**
     * Constructor
     *
     * Initializes controller with required dependencies.
     *
     * @param string          $appName         Application name
     * @param IRequest        $request         HTTP request object
     * @param UserService     $userService     User service for user operations
     * @param SecurityService $securityService Security service for input sanitization
     * @param LoggerInterface $logger          Logger for error tracking
     *
     * @return void
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly UserService $userService,
        private readonly SecurityService $securityService,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * Get current user profile
     *
     * Returns the profile information of the currently authenticated user.
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with user profile data
     *
     * @SuppressWarnings(PHPMD.ShortMethodName) Standard REST API endpoint name for current user
     */
    public function me(): JSONResponse
    {
        try {
            $currentUser = $this->userService->getCurrentUser();

            if ($currentUser === null) {
                return new JSONResponse(
                    data: ['error' => 'Not authenticated'],
                    statusCode: 401
                );
            }

            $userProfile = $this->userService->buildUserDataArray(user: $currentUser);

            return new JSONResponse(data: $userProfile);
        } catch (Exception $e) {
            $this->logger->error(
                message: 'Failed to get user profile',
                context: [
                    'error_message' => $e->getMessage(),
                    'error_code'    => $e->getCode(),
                ]
            );

            return new JSONResponse(
                data: ['error' => 'Failed to retrieve user profile'],
                statusCode: 500
            );
        }//end try
    }//end me()

    /**
     * Update current user profile
     *
     * Updates the profile information of the currently authenticated user.
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with updated user profile
     */
    public function updateMe(): JSONResponse
    {
        try {
            $currentUser = $this->userService->getCurrentUser();

            if ($currentUser === null) {
                return new JSONResponse(
                    data: ['error' => 'Not authenticated'],
                    statusCode: 401
                );
            }

            // Get request parameters.
            $data = $this->request->getParams();

            // Remove internal parameters.
            foreach (array_keys($data) as $key) {
                if (str_starts_with(haystack: $key, needle: '_') === true) {
                    unset($data[$key]);
                }
            }

            // Remove immutable fields.
            unset($data['id'], $data['uid'], $data['created']);

            // Sanitize input data.
            $sanitizedData = [];
            foreach ($data as $key => $value) {
                $sanitizedData[$key] = $this->securityService->sanitizeInput(input: $value);
            }

            // Update user properties.
            $updatedProfile = $this->userService->updateUserProperties(
                user: $currentUser,
                data: $sanitizedData
            );

            return new JSONResponse(data: $updatedProfile);
        } catch (Exception $e) {
            $this->logger->error(
                message: 'Failed to update user profile',
                context: [
                    'error_message' => $e->getMessage(),
                    'error_code'    => $e->getCode(),
                ]
            );

            return new JSONResponse(
                data: ['error' => $e->getMessage()],
                statusCode: 500
            );
        }//end try
    }//end updateMe()
}//end class
