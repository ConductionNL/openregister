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
                    message: 'High memory usage during login',
                    context: [
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
                message: 'Login failed due to system error',
                context: ['error_message' => $e->getMessage()]
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
