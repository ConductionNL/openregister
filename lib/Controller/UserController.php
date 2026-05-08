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
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;
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
 *
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
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
     * @param IUserManager    $userManager     User manager for authentication
     * @param IUserSession    $userSession     User session manager
     * @param LoggerInterface $logger          Logger for error tracking
     * @param IL10N           $l10n            Localization service
     *
     * @return void
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly UserService $userService,
        private readonly SecurityService $securityService,
        private readonly IUserManager $userManager,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger,
        private readonly IL10N $l10n
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
     * @suppressWarnings(PHPMD.ShortMethodName) Standard REST API endpoint name for current user
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
                message: '[UserController] Failed to get user profile',
                context: [
                    'file'          => __FILE__,
                    'line'          => __LINE__,
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
                message: '[UserController] Failed to update user profile',
                context: [
                    'file'          => __FILE__,
                    'line'          => __LINE__,
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

    /**
     * Login a user based on username and password
     *
     * This method securely authenticates a user using their username/email and password,
     * with comprehensive protection against XSS and brute force attacks including:
     * - Input validation and sanitization
     * - Rate limiting per user and IP
     * - Progressive delays for repeated attempts
     * - Account and IP lockout mechanisms
     * - Security event logging
     * - Security headers in response
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @PublicPage
     *
     * @return JSONResponse A JSON response containing login result and user information
     */
    public function login(): JSONResponse
    {
        try {
            // Memory monitoring: Check initial memory usage to prevent OOM.
            $initialMemoryUsage = memory_get_usage(true);
            $memoryLimit        = ini_get('memory_limit');
            $memoryLimitBytes   = $this->convertToBytes(memoryLimit: $memoryLimit);

            // If we're already using more than 80% of memory limit, return error.
            if ($memoryLimitBytes > 0 && $initialMemoryUsage > ($memoryLimitBytes * 0.8)) {
                $response = new JSONResponse(
                    data: ['error' => 'Server memory usage too high, please try again later'],
                    statusCode: 503
                );
                return $this->securityService->addSecurityHeaders(response: $response);
            }

            // Get client IP address for rate limiting.
            $clientIp = $this->securityService->getClientIpAddress(request: $this->request);

            // Get and validate login credentials from request.
            $data = $this->request->getParams();
            $credentialValidation = $this->securityService->validateLoginCredentials(credentials: $data);

            if ($credentialValidation['valid'] === false) {
                $response = new JSONResponse(
                    data: ['error' => $credentialValidation['error']],
                    statusCode: 400
                );
                return $this->securityService->addSecurityHeaders(response: $response);
            }

            $credentials = $credentialValidation['credentials'];
            $username    = $credentials['username'];
            $password    = $credentials['password'];

            // Check rate limiting before attempting authentication.
            $rateLimitCheck = $this->securityService->checkLoginRateLimit(username: $username, ipAddress: $clientIp);
            if ($rateLimitCheck['allowed'] === false) {
                // Apply progressive delay if specified.
                if (isset($rateLimitCheck['delay']) === true) {
                    sleep($rateLimitCheck['delay']);
                }

                $response = new JSONResponse(
                    data: [
                        'error'         => $rateLimitCheck['reason'],
                        'retry_after'   => $rateLimitCheck['delay'] ?? null,
                        'lockout_until' => $rateLimitCheck['lockout_until'] ?? null,
                    ],
                    statusCode: 429
                );
                return $this->securityService->addSecurityHeaders(response: $response);
            }

            // Attempt to authenticate the user.
            $user = $this->userManager->checkPassword($username, $password);

            // Check if authentication was successful.
            if ($user === false) {
                // Record failed login attempt for rate limiting.
                $this->securityService->recordFailedLoginAttempt(
                    username: $username,
                    ipAddress: $clientIp,
                    reason: 'invalid_credentials'
                );

                // Return generic error message to prevent username enumeration.
                $response = new JSONResponse(
                    data: ['error' => 'Invalid username or password'],
                    statusCode: 401
                );
                return $this->securityService->addSecurityHeaders(response: $response);
            }

            // Check if user account is enabled.
            if ($user->isEnabled() === false) {
                // Record failed login attempt for disabled account.
                $this->securityService->recordFailedLoginAttempt(
                    username: $username,
                    ipAddress: $clientIp,
                    reason: 'account_disabled'
                );

                $response = new JSONResponse(
                    data: ['error' => 'Account is disabled'],
                    statusCode: 401
                );
                return $this->securityService->addSecurityHeaders(response: $response);
            }

            // Authentication successful - record success and clear rate limits.
            $this->securityService->recordSuccessfulLogin(username: $username, ipAddress: $clientIp);

            // Set the user in the session to create login session.
            $this->userSession->setUser($user);

            // Build user data array for response (sanitized).
            $userData = $this->userService->buildUserDataArray(user: $user);

            // Memory monitoring: Log high memory usage.
            $finalMemoryUsage    = memory_get_usage(true);
            $memoryIncreaseBytes = $finalMemoryUsage - $initialMemoryUsage;

            if ($memoryIncreaseBytes > 10 * 1024 * 1024) {
                $this->logger->warning(
                    message: '[UserController] High memory usage during login',
                    context: [
                        'file'           => __FILE__,
                        'line'           => __LINE__,
                        'user'           => $user->getUID(),
                        'initial_memory' => $initialMemoryUsage,
                        'final_memory'   => $finalMemoryUsage,
                        'increase_bytes' => $memoryIncreaseBytes,
                        'increase_mb'    => round($memoryIncreaseBytes / (1024 * 1024), 2),
                    ]
                );
            }

            // Create successful response with security headers.
            $response = new JSONResponse(
                data: [
                    'message'         => 'Login successful',
                    'user'            => $userData,
                    'session_created' => true,
                ]
            );

            return $this->securityService->addSecurityHeaders(response: $response);
        } catch (Exception $e) {
            // Log the error securely without exposing sensitive information.
            $this->logger->error(
                message: '[UserController] Login failed due to system error',
                context: ['file' => __FILE__, 'line' => __LINE__, 'error_message' => $e->getMessage()]
            );

            $response = new JSONResponse(
                data: ['error' => 'Login failed due to a system error'],
                statusCode: 500
            );
            return $this->securityService->addSecurityHeaders(response: $response);
        }//end try
    }//end login()

    /**
     * Logout the current user session
     *
     * This method securely logs out the current user by ending
     * their active session.
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @PublicPage
     *
     * @return JSONResponse A JSON response confirming logout
     */
    public function logout(): JSONResponse
    {
        $this->userSession->logout();

        $response = new JSONResponse(data: ['logout' => true]);
        return $this->securityService->addSecurityHeaders(response: $response);
    }//end logout()

    /**
     * Change the current user's password
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with result
     */
    public function changePassword(): JSONResponse
    {
        try {
            $currentUser = $this->userService->getCurrentUser();
            if ($currentUser === null) {
                return $this->errorResponse(message: 'Not authenticated', statusCode: 401);
            }

            // Rate limiting.
            $clientIp       = $this->securityService->getClientIpAddress(request: $this->request);
            $rateLimitCheck = $this->securityService->checkLoginRateLimit(
                username: $currentUser->getUID(),
                ipAddress: $clientIp
            );
            if ($rateLimitCheck['allowed'] === false) {
                $response = new JSONResponse(
                    data: ['error' => $rateLimitCheck['reason'], 'retry_after' => $rateLimitCheck['delay'] ?? null],
                    statusCode: 429
                );
                return $this->securityService->addSecurityHeaders(response: $response);
            }

            $data            = $this->request->getParams();
            $currentPassword = $this->securityService->sanitizeInput(input: $data['currentPassword'] ?? '');
            $newPassword     = $data['newPassword'] ?? '';

            if ($currentPassword === '' || $newPassword === '') {
                return $this->errorResponse(message: 'Both currentPassword and newPassword are required', statusCode: 400);
            }

            $result   = $this->userService->changePassword($currentUser, $currentPassword, $newPassword);
            $response = new JSONResponse(data: $result);
            return $this->securityService->addSecurityHeaders(response: $response);
        } catch (\RuntimeException $e) {
            $code = ($e->getCode() !== 0 ? $e->getCode() : 500);
            if ($code === 403) {
                $clientIp = $this->securityService->getClientIpAddress(request: $this->request);
                $this->securityService->recordFailedLoginAttempt(
                    username: $currentUser->getUID(),
                    ipAddress: $clientIp,
                    reason: 'password_change_incorrect'
                );
            }

            return $this->errorResponse(message: $e->getMessage(), statusCode: $code);
        } catch (Exception $e) {
            $this->logError(message: 'Failed to change password', exception: $e);
            return $this->errorResponse(message: 'Failed to change password', statusCode: 500);
        }//end try
    }//end changePassword()

    /**
     * Upload a new avatar for the current user
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with result
     */
    public function uploadAvatar(): JSONResponse
    {
        try {
            $currentUser = $this->userService->getCurrentUser();
            if ($currentUser === null) {
                return $this->errorResponse(message: 'Not authenticated', statusCode: 401);
            }

            // Read the uploaded file data from the request body.
            $data     = file_get_contents('php://input');
            $mimeType = $this->request->getHeader('Content-Type');
            $size     = strlen($data);

            if ($size === 0) {
                return $this->errorResponse(message: 'No image data provided', statusCode: 400);
            }

            $result   = $this->userService->uploadAvatar($currentUser, $data, $mimeType, $size);
            $response = new JSONResponse(data: $result);
            return $this->securityService->addSecurityHeaders(response: $response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse(message: $e->getMessage(), statusCode: ($e->getCode() !== 0 ? $e->getCode() : 500));
        } catch (Exception $e) {
            $this->logError(message: 'Failed to upload avatar', exception: $e);
            return $this->errorResponse(message: 'Failed to upload avatar', statusCode: 500);
        }//end try
    }//end uploadAvatar()

    /**
     * Delete the current user's avatar
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with result
     */
    public function deleteAvatar(): JSONResponse
    {
        try {
            $currentUser = $this->userService->getCurrentUser();
            if ($currentUser === null) {
                return $this->errorResponse(message: 'Not authenticated', statusCode: 401);
            }

            $result   = $this->userService->deleteAvatar($currentUser);
            $response = new JSONResponse(data: $result);
            return $this->securityService->addSecurityHeaders(response: $response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse(message: $e->getMessage(), statusCode: ($e->getCode() !== 0 ? $e->getCode() : 500));
        } catch (Exception $e) {
            $this->logError(message: 'Failed to delete avatar', exception: $e);
            return $this->errorResponse(message: 'Failed to delete avatar', statusCode: 500);
        }//end try
    }//end deleteAvatar()

    /**
     * Export personal data for the current user (GDPR)
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse|DataDownloadResponse JSON response with export data
     */
    public function exportData(): JSONResponse|DataDownloadResponse
    {
        try {
            $currentUser = $this->userService->getCurrentUser();
            if ($currentUser === null) {
                return $this->errorResponse(message: 'Not authenticated', statusCode: 401);
            }

            $exportData = $this->userService->exportPersonalData($currentUser);

            $filename = 'openregister-export-'.$currentUser->getUID().'-'.date('Y-m-d').'.json';
            $json     = json_encode($exportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

            return new DataDownloadResponse($json, $filename, 'application/json');
        } catch (\RuntimeException $e) {
            $code = ($e->getCode() !== 0 ? $e->getCode() : 500);
            if ($code === 429) {
                $errorData = json_decode($e->getMessage(), true);
                $response  = new JSONResponse(data: $errorData ?? ['error' => $e->getMessage()], statusCode: 429);
                return $this->securityService->addSecurityHeaders(response: $response);
            }

            return $this->errorResponse(message: $e->getMessage(), statusCode: $code);
        } catch (Exception $e) {
            $this->logError(message: 'Failed to export data', exception: $e);
            return $this->errorResponse(message: 'Failed to export data', statusCode: 500);
        }//end try
    }//end exportData()

    /**
     * Get notification preferences for the current user
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with preferences
     */
    public function getNotificationPreferences(): JSONResponse
    {
        try {
            $currentUser = $this->userService->getCurrentUser();
            if ($currentUser === null) {
                return $this->errorResponse(message: 'Not authenticated', statusCode: 401);
            }

            $prefs    = $this->userService->getNotificationPreferences($currentUser);
            $response = new JSONResponse(data: $prefs);
            return $this->securityService->addSecurityHeaders(response: $response);
        } catch (Exception $e) {
            $this->logError(message: 'Failed to get notification preferences', exception: $e);
            return $this->errorResponse(message: 'Failed to get notification preferences', statusCode: 500);
        }//end try
    }//end getNotificationPreferences()

    /**
     * Update notification preferences for the current user
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with updated preferences
     */
    public function updateNotificationPreferences(): JSONResponse
    {
        try {
            $currentUser = $this->userService->getCurrentUser();
            if ($currentUser === null) {
                return $this->errorResponse(message: 'Not authenticated', statusCode: 401);
            }

            $data = $this->request->getParams();

            // Remove internal parameters.
            foreach (array_keys($data) as $key) {
                if (str_starts_with($key, '_') === true) {
                    unset($data[$key]);
                }
            }

            $prefs    = $this->userService->setNotificationPreferences($currentUser, $data);
            $response = new JSONResponse(data: $prefs);
            return $this->securityService->addSecurityHeaders(response: $response);
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse(message: $e->getMessage(), statusCode: 400);
        } catch (Exception $e) {
            $this->logError(message: 'Failed to update notification preferences', exception: $e);
            return $this->errorResponse(message: 'Failed to update notification preferences', statusCode: 500);
        }//end try
    }//end updateNotificationPreferences()

    /**
     * Get personal activity history for the current user
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with activity list
     */
    public function getActivity(): JSONResponse
    {
        try {
            $currentUser = $this->userService->getCurrentUser();
            if ($currentUser === null) {
                return $this->errorResponse(message: 'Not authenticated', statusCode: 401);
            }

            $limit  = (int) ($this->request->getParam('_limit', '25'));
            $offset = (int) ($this->request->getParam('_offset', '0'));
            $type   = $this->request->getParam('type');
            $from   = $this->request->getParam('_from');
            $to     = $this->request->getParam('_to');

            $activity = $this->userService->getUserActivity($currentUser, $limit, $offset, $type, $from, $to);
            $response = new JSONResponse(data: $activity);
            return $this->securityService->addSecurityHeaders(response: $response);
        } catch (Exception $e) {
            $this->logError(message: 'Failed to get activity', exception: $e);
            return $this->errorResponse(message: 'Failed to get activity history', statusCode: 500);
        }//end try
    }//end getActivity()

    /**
     * List API tokens for the current user
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with token list
     */
    public function listTokens(): JSONResponse
    {
        try {
            $currentUser = $this->userService->getCurrentUser();
            if ($currentUser === null) {
                return $this->errorResponse(message: 'Not authenticated', statusCode: 401);
            }

            $tokens   = $this->userService->listApiTokens($currentUser);
            $response = new JSONResponse(data: $tokens);
            return $this->securityService->addSecurityHeaders(response: $response);
        } catch (Exception $e) {
            $this->logError(message: 'Failed to list tokens', exception: $e);
            return $this->errorResponse(message: 'Failed to list tokens', statusCode: 500);
        }//end try
    }//end listTokens()

    /**
     * Create a new API token for the current user
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with the created token
     */
    public function createToken(): JSONResponse
    {
        try {
            $currentUser = $this->userService->getCurrentUser();
            if ($currentUser === null) {
                return $this->errorResponse(message: 'Not authenticated', statusCode: 401);
            }

            $data      = $this->request->getParams();
            $name      = $this->securityService->sanitizeInput(input: $data['name'] ?? '');
            $expiresIn = $data['expiresIn'] ?? null;

            if ($name === '') {
                return $this->errorResponse(message: 'Token name is required', statusCode: 400);
            }

            $token    = $this->userService->createApiToken($currentUser, $name, $expiresIn);
            $response = new JSONResponse(data: $token, statusCode: 201);
            return $this->securityService->addSecurityHeaders(response: $response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse(message: $e->getMessage(), statusCode: ($e->getCode() !== 0 ? $e->getCode() : 500));
        } catch (Exception $e) {
            $this->logError(message: 'Failed to create token', exception: $e);
            return $this->errorResponse(message: 'Failed to create token', statusCode: 500);
        }//end try
    }//end createToken()

    /**
     * Revoke an API token for the current user
     *
     * @param string $id The token ID to revoke
     *
     * @return JSONResponse JSON response with result
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function revokeToken(string $id): JSONResponse
    {
        try {
            $currentUser = $this->userService->getCurrentUser();
            if ($currentUser === null) {
                return $this->errorResponse(message: 'Not authenticated', statusCode: 401);
            }

            $result   = $this->userService->revokeApiToken($currentUser, $id);
            $response = new JSONResponse(data: $result);
            return $this->securityService->addSecurityHeaders(response: $response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse(message: $e->getMessage(), statusCode: ($e->getCode() !== 0 ? $e->getCode() : 500));
        } catch (Exception $e) {
            $this->logError(message: 'Failed to revoke token', exception: $e);
            return $this->errorResponse(message: 'Failed to revoke token', statusCode: 500);
        }//end try
    }//end revokeToken()

    /**
     * Request account deactivation for the current user
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with result
     */
    public function requestDeactivation(): JSONResponse
    {
        try {
            $currentUser = $this->userService->getCurrentUser();
            if ($currentUser === null) {
                return $this->errorResponse(message: 'Not authenticated', statusCode: 401);
            }

            $data   = $this->request->getParams();
            $reason = $this->securityService->sanitizeInput(input: $data['reason'] ?? '');

            $result   = $this->userService->requestDeactivation($currentUser, $reason);
            $response = new JSONResponse(data: $result);
            return $this->securityService->addSecurityHeaders(response: $response);
        } catch (\RuntimeException $e) {
            $code = ($e->getCode() !== 0 ? $e->getCode() : 500);
            if ($code === 409) {
                $errorData = json_decode($e->getMessage(), true);
                $response  = new JSONResponse(data: $errorData ?? ['error' => $e->getMessage()], statusCode: 409);
                return $this->securityService->addSecurityHeaders(response: $response);
            }

            return $this->errorResponse(message: $e->getMessage(), statusCode: $code);
        } catch (Exception $e) {
            $this->logError(message: 'Failed to request deactivation', exception: $e);
            return $this->errorResponse(message: 'Failed to request deactivation', statusCode: 500);
        }//end try
    }//end requestDeactivation()

    /**
     * Get deactivation request status for the current user
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with status
     */
    public function getDeactivationStatus(): JSONResponse
    {
        try {
            $currentUser = $this->userService->getCurrentUser();
            if ($currentUser === null) {
                return $this->errorResponse(message: 'Not authenticated', statusCode: 401);
            }

            $status   = $this->userService->getDeactivationStatus($currentUser);
            $response = new JSONResponse(data: $status);
            return $this->securityService->addSecurityHeaders(response: $response);
        } catch (Exception $e) {
            $this->logError(message: 'Failed to get deactivation status', exception: $e);
            return $this->errorResponse(message: 'Failed to get deactivation status', statusCode: 500);
        }//end try
    }//end getDeactivationStatus()

    /**
     * Cancel a pending deactivation request for the current user
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with result
     */
    public function cancelDeactivation(): JSONResponse
    {
        try {
            $currentUser = $this->userService->getCurrentUser();
            if ($currentUser === null) {
                return $this->errorResponse(message: 'Not authenticated', statusCode: 401);
            }

            $result   = $this->userService->cancelDeactivation($currentUser);
            $response = new JSONResponse(data: $result);
            return $this->securityService->addSecurityHeaders(response: $response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse(message: $e->getMessage(), statusCode: ($e->getCode() !== 0 ? $e->getCode() : 500));
        } catch (Exception $e) {
            $this->logError(message: 'Failed to cancel deactivation', exception: $e);
            return $this->errorResponse(message: 'Failed to cancel deactivation', statusCode: 500);
        }//end try
    }//end cancelDeactivation()

    /**
     * Create a standardized error response with security headers
     *
     * @param string $message    The error message
     * @param int    $statusCode The HTTP status code
     *
     * @return JSONResponse The error response
     */
    private function errorResponse(string $message, int $statusCode): JSONResponse
    {
        $response = new JSONResponse(
            data: ['error' => $message],
            statusCode: $statusCode
        );
        return $this->securityService->addSecurityHeaders(response: $response);
    }//end errorResponse()

    /**
     * Log an error with standard context
     *
     * @param string    $message   The log message
     * @param Exception $exception The exception that occurred
     *
     * @return void
     */
    private function logError(string $message, Exception $exception): void
    {
        $this->logger->error(
            message: '[UserController] '.$message,
            context: [
                'file'          => __FILE__,
                'line'          => __LINE__,
                'error_message' => $exception->getMessage(),
                'error_code'    => $exception->getCode(),
            ]
        );
    }//end logError()

    /**
     * Convert PHP memory limit string to bytes
     *
     * This helper method converts PHP memory limit strings (like "128M", "1G")
     * to bytes for memory usage comparisons.
     *
     * @param string $memoryLimit The memory limit string from PHP ini
     *
     * @return int The memory limit in bytes, or 0 if unlimited
     */
    private function convertToBytes(string $memoryLimit): int
    {
        // If memory limit is -1, it means unlimited.
        if ($memoryLimit === '-1') {
            return 0;
        }

        // Convert the memory limit to bytes.
        $memoryLimit = trim($memoryLimit);
        $last        = strtolower($memoryLimit[strlen($memoryLimit) - 1]);
        $value       = (int) $memoryLimit;

        switch ($last) {
            case 'g':
                $value *= 1024;
                // Fall through.
            case 'm':
                $value *= 1024;
                // Fall through.
            case 'k':
                $value *= 1024;
        }

        return $value;
    }//end convertToBytes()
}//end class
