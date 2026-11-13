<?php

/**
 * OpenRegister User Controller
 *
 * Controller for user management operations including profile retrieval
 * and update operations for the currently authenticated user.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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
use InvalidArgumentException;
use OCA\OpenRegister\AppInfo\Application;
use OCA\OpenRegister\Controller\Support\UserControllerServices;
use OCA\OpenRegister\Controller\Support\UserResponses;
use OCA\OpenRegister\Service\FailedAttempt;
use OCA\OpenRegister\Service\LoginSecurityInterface;
use OCA\OpenRegister\Service\User\ActivityPeriod;
use OCA\OpenRegister\Service\User\ActivityQuery;
use OCA\OpenRegister\Service\User\ApiTokenRequest;
use OCA\OpenRegister\Service\User\AvatarUpload;
use OCA\OpenRegister\Service\User\PasswordChange;
use OCA\OpenRegister\Service\UserServiceInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;

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
class UserController extends Controller {
	/**
	 * User service for user operations
	 *
	 * @var UserServiceInterface
	 */
	private readonly UserServiceInterface $userService;

	/**
	 * Security service for input sanitization
	 *
	 * @var LoginSecurityInterface
	 */
	private readonly LoginSecurityInterface $securityService;

	/**
	 * User manager for authentication
	 *
	 * @var IUserManager
	 */
	private readonly IUserManager $userManager;

	/**
	 * User session manager
	 *
	 * @var IUserSession
	 */
	private readonly IUserSession $userSession;

	/**
	 * Logger for error tracking
	 *
	 * @var LoggerInterface
	 */
	private readonly LoggerInterface $logger;

	/**
	 * Localization service
	 *
	 * @var IL10N
	 */
	private readonly IL10N $l10n;

	/**
	 * Builds the security-headed responses
	 *
	 * @var UserResponses
	 */
	private readonly UserResponses $responses;

	/**
	 * Constructor
	 *
	 * Initializes controller with required dependencies.
	 *
	 * @param IRequest $request HTTP request object
	 * @param UserControllerServices $services The user controller services.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		UserControllerServices $services,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
		$this->userService = $services->domain->userService;
		$this->securityService = $services->domain->securityService;
		$this->userManager = $services->domain->userManager;
		$this->userSession = $services->domain->userSession;
		$this->logger = $services->platform->logger;
		$this->l10n = $services->platform->l10n;
		$this->responses = new UserResponses(securityService: $this->securityService);
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
	 *
	 * @spec openspec/specs/auth-system/spec.md
	 */
	public function me(): JSONResponse {
		return $this->forProfileUser(
			action: fn (IUser $user): JSONResponse => new JSONResponse(
				data: $this->userService->buildUserDataArray(user: $user)
			),
			messages: ['log' => 'Failed to get user profile', 'failure' => 'Failed to retrieve user profile']
		);
	}//end me()

	/**
	 * Update current user profile
	 *
	 * Updates the profile information of the currently authenticated user.
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse JSON response with updated user profile
	 *
	 * @spec openspec/specs/auth-system/spec.md
	 */
	public function updateMe(): JSONResponse {
		return $this->forProfileUser(
			action: fn (IUser $user): JSONResponse => new JSONResponse(
				data: $this->userService->updateUserProperties(user: $user, data: $this->profileUpdates())
			),
			messages: ['log' => 'Failed to update user profile']
		);
	}//end updateMe()

	/**
	 * The profile changes of the request: internal and immutable fields removed, values sanitized.
	 *
	 * @return array The sanitized profile changes.
	 */
	private function profileUpdates(): array {
		$data = $this->withoutInternalParams(data: $this->request->getParams());

		// Remove immutable fields.
		unset($data['id'], $data['uid'], $data['created']);

		$sanitizedData = [];
		foreach ($data as $key => $value) {
			$sanitizedData[$key] = $this->securityService->sanitizeInput(input: $value);
		}

		return $sanitizedData;
	}//end profileUpdates()

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
	 * The AnonRateLimit attribute below is a framework ceiling ALONGSIDE the
	 * app-level limiter, not instead of it. SecurityService::checkLoginRateLimit()
	 * already keys on username + IP with a progressive delay, which is
	 * finer-grained than anything the framework can do from an attribute. It
	 * bounds the volume that reaches that logic at all; it is not a claim that
	 * the login was unprotected.
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @PublicPage
	 *
	 * @return JSONResponse A JSON response containing login result and user information
	 *
	 * @spec openspec/specs/auth-system/spec.md
	 * @spec openspec/changes/platform-provided-capabilities/specs/platform-provided-capabilities/spec.md#REQ-PPC-002
	 * @spec openspec/changes/platform-provided-capabilities/specs/platform-provided-capabilities/spec.md#REQ-PPC-004
	 */
	#[AnonRateLimit(limit: 20, period: 60)]
	public function login(): JSONResponse {
		try {
			return $this->authenticate(initialMemoryUsage: memory_get_usage(true));
		} catch (Exception $e) {
			// Log the error securely without exposing sensitive information.
			$this->logger->error(
				message: '[UserController] Login failed due to system error',
				context: ['file' => __FILE__, 'line' => __LINE__, 'error_message' => $e->getMessage()]
			);

			return $this->responses->error(message: 'Login failed due to a system error', statusCode: 500);
		}//end try
	}//end login()

	/**
	 * Refuse under memory pressure, then validate the credentials and check them.
	 *
	 * @param int $initialMemoryUsage Memory usage when the login started.
	 *
	 * @return JSONResponse The login outcome.
	 */
	private function authenticate(int $initialMemoryUsage): JSONResponse {
		if ($this->memoryTooHigh(initialMemoryUsage: $initialMemoryUsage) === true) {
			return $this->responses->error(message: 'Server memory usage too high, please try again later', statusCode: 503);
		}

		$clientIp             = $this->securityService->getClientIpAddress(request: $this->request);
		$credentialValidation = $this->securityService->validateLoginCredentials(credentials: $this->request->getParams());
		if ($credentialValidation['valid'] === false) {
			return $this->responses->error(message: $credentialValidation['error'], statusCode: 400);
		}

		$credentials = $credentialValidation['credentials'];

		return $this->checkCredentials(
			credentials: $credentials,
			attempt: ['username' => $credentials['username'], 'ipAddress' => $clientIp, 'initialMemoryUsage' => $initialMemoryUsage]
		);
	}//end authenticate()

	/**
	 * Whether memory usage is already above 80% of the memory limit (refused to prevent OOM).
	 *
	 * @param int $initialMemoryUsage Memory usage when the login started.
	 *
	 * @return bool True when the login must be refused.
	 */
	private function memoryTooHigh(int $initialMemoryUsage): bool {
		$memoryLimitBytes = $this->convertToBytes(memoryLimit: ini_get('memory_limit'));
		return $memoryLimitBytes > 0 && $initialMemoryUsage > ($memoryLimitBytes * 0.8);
	}//end memoryTooHigh()

	/**
	 * Apply the rate limit, check the password and start the session.
	 *
	 * @param array{username: string, password: string}                           $credentials The validated credentials.
	 * @param array{username: string, ipAddress: string, initialMemoryUsage: int} $attempt     The login attempt: the username, the client
	 *                                                                                         IP address and the memory usage when the
	 *                                                                                         login started.
	 *
	 * @return JSONResponse The login outcome.
	 */
	private function checkCredentials(array $credentials, array $attempt): JSONResponse {
		// Rate limiting is checked before attempting authentication.
		$rateLimitCheck = $this->securityService->checkLoginRateLimit(username: $attempt['username'], ipAddress: $attempt['ipAddress']);
		if ($rateLimitCheck['allowed'] === false) {
			return $this->rateLimitedLoginResponse(rateLimitCheck: $rateLimitCheck);
		}

		$user    = $this->userManager->checkPassword($attempt['username'], $credentials['password']);
		$refusal = $this->refusedLogin(user: $user, attempt: $attempt);
		if ($refusal !== null) {
			return $refusal;
		}

		return $this->startSession(user: $user, attempt: $attempt);
	}//end checkCredentials()

	/**
	 * Record the successful login (clears rate limits) and create the login session.
	 *
	 * @param IUser                                                               $user    The authenticated user.
	 * @param array{username: string, ipAddress: string, initialMemoryUsage: int} $attempt The login attempt, with the memory usage when
	 *                                                                                     the login started.
	 *
	 * @return JSONResponse The successful login response.
	 */
	private function startSession(IUser $user, array $attempt): JSONResponse {
		$this->securityService->recordSuccessfulLogin(username: $attempt['username'], ipAddress: $attempt['ipAddress']);
		$this->userSession->setUser($user);
		$userData = $this->userService->buildUserDataArray(user: $user);
		$this->logLoginMemoryIncrease(user: $user, initialMemoryUsage: $attempt['initialMemoryUsage']);

		return $this->responses->secured(
			data: ['message' => 'Login successful', 'user' => $userData, 'session_created' => true]
		);
	}//end startSession()

	/**
	 * The 429 response of a rate-limited login, after its progressive delay.
	 *
	 * @param array $rateLimitCheck The rate limit verdict (reason, delay, lockout_until).
	 *
	 * @return JSONResponse The response.
	 */
	private function rateLimitedLoginResponse(array $rateLimitCheck): JSONResponse {
		if (isset($rateLimitCheck['delay']) === true) {
			sleep($rateLimitCheck['delay']);
		}

		return $this->responses->secured(
			data: [
				'error'         => $rateLimitCheck['reason'],
				'retry_after'   => $rateLimitCheck['delay'] ?? null,
				'lockout_until' => $rateLimitCheck['lockout_until'] ?? null,
			],
			statusCode: 429
		);
	}//end rateLimitedLoginResponse()

	/**
	 * The 401 response for wrong credentials or a disabled account, recorded as a failed attempt.
	 *
	 * Wrong credentials get a generic message to prevent username enumeration.
	 *
	 * @param IUser|false                                 $user    The authenticated user, false on wrong credentials.
	 * @param array{username: string, ipAddress: string} $attempt The login attempt.
	 *
	 * @return JSONResponse|null The refusal, or null when the user may log in.
	 */
	private function refusedLogin(IUser|false $user, array $attempt): ?JSONResponse {
		$reason  = 'account_disabled';
		$message = 'Account is disabled';
		if ($user === false) {
			$reason  = 'invalid_credentials';
			$message = 'Invalid username or password';
		} else if ($user->isEnabled() !== false) {
			return null;
		}

		$this->securityService->recordFailedLoginAttempt(
			attempt: new FailedAttempt(subject: $attempt['username'], ipAddress: $attempt['ipAddress'], reason: $reason)
		);
		return $this->responses->error(message: $message, statusCode: 401);
	}//end refusedLogin()

	/**
	 * Log a login that raised memory usage by more than 10 MB.
	 *
	 * @param IUser $user               The user who logged in.
	 * @param int   $initialMemoryUsage Memory usage when the login started.
	 *
	 * @return void
	 */
	private function logLoginMemoryIncrease(IUser $user, int $initialMemoryUsage): void {
		$finalMemoryUsage    = memory_get_usage(true);
		$memoryIncreaseBytes = $finalMemoryUsage - $initialMemoryUsage;
		if ($memoryIncreaseBytes <= 10 * 1024 * 1024) {
			return;
		}

		$this->logger->warning(
			message: '[UserController] High memory usage during login',
			context: [
				'file' => __FILE__, 'line' => __LINE__, 'user' => $user->getUID(),
				'initial_memory' => $initialMemoryUsage, 'final_memory' => $finalMemoryUsage,
				'increase_bytes' => $memoryIncreaseBytes, 'increase_mb' => round($memoryIncreaseBytes / (1024 * 1024), 2),
			]
		);
	}//end logLoginMemoryIncrease()

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
	 *
	 * @spec openspec/specs/auth-system/spec.md
	 */
	#[AnonRateLimit(limit: 60, period: 60)]
	public function logout(): JSONResponse {
		$this->userSession->logout();

		return $this->responses->secured(data: ['logout' => true]);
	}//end logout()

	/**
	 * Change the current user's password
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse JSON response with result
	 *
	 * @spec openspec/specs/auth-system/spec.md
	 */
	public function changePassword(): JSONResponse {
		return $this->forCurrentUser(
			action: fn (IUser $user): JSONResponse => $this->changePasswordFor(user: $user),
			onFailure: fn (Exception $e, ?IUser $user): JSONResponse => $this->passwordChangeFailure(exception: $e, user: $user)
		);
	}//end changePassword()

	/**
	 * Change the password of the session user, rate limited like a login.
	 *
	 * @param IUser $user The session user.
	 *
	 * @return JSONResponse The result.
	 */
	private function changePasswordFor(IUser $user): JSONResponse {
		$clientIp       = $this->securityService->getClientIpAddress(request: $this->request);
		$rateLimitCheck = $this->securityService->checkLoginRateLimit(username: $user->getUID(), ipAddress: $clientIp);
		if ($rateLimitCheck['allowed'] === false) {
			$refusal = ['error' => $rateLimitCheck['reason'], 'retry_after' => $rateLimitCheck['delay'] ?? null];
			return $this->responses->secured(data: $refusal, statusCode: 429);
		}

		return $this->applyPasswordChange(user: $user, data: $this->request->getParams());
	}//end changePasswordFor()

	/**
	 * Change the password with the current and new password of the request.
	 *
	 * Only the current password is sanitized; the new one is passed on as given.
	 *
	 * @param IUser $user The session user.
	 * @param array $data The request parameters.
	 *
	 * @return JSONResponse The result.
	 */
	private function applyPasswordChange(IUser $user, array $data): JSONResponse {
		$currentPassword = $this->securityService->sanitizeInput(input: $data['currentPassword'] ?? '');
		$newPassword     = $data['newPassword'] ?? '';
		if ($currentPassword === '' || $newPassword === '') {
			return $this->responses->error(message: 'Both currentPassword and newPassword are required', statusCode: 400);
		}

		$change = new PasswordChange(currentPassword: $currentPassword, newPassword: $newPassword);
		return $this->responses->secured(data: $this->userService->applyPasswordChange(user: $user, change: $change));
	}//end changePasswordFor()

	/**
	 * The response for a failed password change; a wrong current password (403) counts as a failed login attempt.
	 *
	 * @param Exception  $exception The failure.
	 * @param IUser|null $user      The session user.
	 *
	 * @return JSONResponse The response.
	 */
	private function passwordChangeFailure(Exception $exception, ?IUser $user): JSONResponse {
		if (($exception instanceof RuntimeException) === false) {
			return $this->unexpected(exception: $exception, message: 'Failed to change password');
		}

		if ($this->responses->runtimeStatus(exception: $exception) === 403) {
			$clientIp = $this->securityService->getClientIpAddress(request: $this->request);
			$this->securityService->recordFailedLoginAttempt(
				attempt: new FailedAttempt(subject: $user->getUID(), ipAddress: $clientIp, reason: 'password_change_incorrect')
			);
		}

		return $this->responses->runtimeError(exception: $exception);
	}//end passwordChangeFailure()

	/**
	 * Upload a new avatar for the current user
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse JSON response with result
	 *
	 * @spec openspec/specs/auth-system/spec.md
	 */
	public function uploadAvatar(): JSONResponse {
		return $this->forCurrentUser(
			action: fn (IUser $user): JSONResponse => $this->uploadAvatarFor(user: $user),
			onFailure: fn (Exception $e): JSONResponse => $this->runtimeFailure(exception: $e, message: 'Failed to upload avatar')
		);
	}//end uploadAvatar()

	/**
	 * Store the image in the request body as the avatar of the session user.
	 *
	 * @param IUser $user The session user.
	 *
	 * @return JSONResponse The result.
	 */
	private function uploadAvatarFor(IUser $user): JSONResponse {
		// Read the uploaded file data from the request body.
		$data     = file_get_contents('php://input');
		$mimeType = $this->request->getHeader('Content-Type');
		$size     = strlen($data);
		if ($size === 0) {
			return $this->responses->error(message: 'No image data provided', statusCode: 400);
		}

		$upload = new AvatarUpload(data: $data, mimeType: $mimeType, size: $size);
		return $this->responses->secured(data: $this->userService->setAvatar(user: $user, upload: $upload));
	}//end uploadAvatarFor()

	/**
	 * Delete the current user's avatar
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse JSON response with result
	 *
	 * @spec openspec/specs/auth-system/spec.md
	 */
	public function deleteAvatar(): JSONResponse {
		return $this->forCurrentUser(
			action: fn (IUser $user): JSONResponse => $this->responses->secured(data: $this->userService->deleteAvatar($user)),
			onFailure: fn (Exception $e): JSONResponse => $this->runtimeFailure(exception: $e, message: 'Failed to delete avatar')
		);
	}//end deleteAvatar()

	/**
	 * Export personal data for the current user (GDPR)
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse|DataDownloadResponse JSON response with export data
	 *
	 * @spec openspec/specs/auth-system/spec.md
	 */
	public function exportData(): JSONResponse|DataDownloadResponse {
		return $this->forCurrentUser(
			action: fn (IUser $user): DataDownloadResponse => $this->exportFor(user: $user),
			onFailure: fn (Exception $e): JSONResponse => $this->exportFailure(exception: $e)
		);
	}//end exportData()

	/**
	 * The personal data of the session user as a JSON download.
	 *
	 * @param IUser $user The session user.
	 *
	 * @return DataDownloadResponse The download.
	 */
	private function exportFor(IUser $user): DataDownloadResponse {
		$exportData = $this->userService->exportPersonalData($user);

		$filename = 'openregister-export-' . $user->getUID() . '-' . date('Y-m-d') . '.json';
		$json     = json_encode($exportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

		return new DataDownloadResponse($json, $filename, 'application/json');
	}//end exportFor()

	/**
	 * Get notification preferences for the current user
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with preferences
	 *
	 * @spec openspec/specs/auth-system/spec.md
	 */
	public function getNotificationPreferences(): JSONResponse {
		return $this->forCurrentUser(
			action: fn (IUser $user): JSONResponse => $this->responses->secured(
				data: $this->userService->getNotificationPreferences($user)
			),
			onFailure: fn (Exception $e): JSONResponse => $this->unexpected(
				exception: $e,
				message: 'Failed to get notification preferences'
			)
		);
	}//end getNotificationPreferences()

	/**
	 * Update notification preferences for the current user
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse JSON response with updated preferences
	 *
	 * @spec openspec/specs/auth-system/spec.md
	 */
	public function updateNotificationPreferences(): JSONResponse {
		return $this->forCurrentUser(
			action: fn (IUser $user): JSONResponse => $this->responses->secured(
				data: $this->userService->setNotificationPreferences(
					$user,
					$this->withoutInternalParams(data: $this->request->getParams())
				)
			),
			onFailure: fn (Exception $e): JSONResponse => $this->notificationPreferencesFailure(exception: $e)
		);
	}//end updateNotificationPreferences()

	/**
	 * The response for a failed preferences update: invalid preferences are a 400.
	 *
	 * @param Exception $exception The failure.
	 *
	 * @return JSONResponse The response.
	 */
	private function notificationPreferencesFailure(Exception $exception): JSONResponse {
		if ($exception instanceof InvalidArgumentException) {
			return $this->responses->error(message: $exception->getMessage(), statusCode: 400);
		}

		return $this->unexpected(exception: $exception, message: 'Failed to update notification preferences');
	}//end notificationPreferencesFailure()

	/**
	 * Get personal activity history for the current user
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with activity list
	 *
	 * @spec openspec/specs/auth-system/spec.md
	 */
	public function getActivity(): JSONResponse {
		return $this->forCurrentUser(
			action: fn (IUser $user): JSONResponse => $this->activityFor(user: $user),
			onFailure: fn (Exception $e): JSONResponse => $this->activityFailure(exception: $e)
		);
	}//end getActivity()

	/**
	 * The activity of the session user, filtered by the request's paging and filter parameters.
	 *
	 * @param IUser $user The session user.
	 *
	 * @return JSONResponse The activity list.
	 */
	private function activityFor(IUser $user): JSONResponse {
		$limit  = (int) ($this->request->getParam('_limit', '25'));
		$offset = (int) ($this->request->getParam('_offset', '0'));
		$type   = $this->request->getParam('type');
		$from   = $this->request->getParam('_from');
		$to     = $this->request->getParam('_to');

		return $this->responses->secured(
			data: $this->userService->activity(
				user: $user,
				query: new ActivityQuery(limit: $limit, offset: $offset, type: $type, period: new ActivityPeriod(from: $from, to: $to))
			)
		);
	}//end activityFor()

	/**
	 * List API tokens for the current user
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with token list
	 *
	 * @spec openspec/specs/auth-system/spec.md
	 */
	public function listTokens(): JSONResponse {
		return $this->forCurrentUser(
			action: fn (IUser $user): JSONResponse => $this->responses->secured(data: $this->userService->listApiTokens($user)),
			onFailure: fn (Exception $e): JSONResponse => $this->unexpected(exception: $e, message: 'Failed to list tokens')
		);
	}//end listTokens()

	/**
	 * Create a new API token for the current user
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse JSON response with the created token
	 *
	 * @spec openspec/specs/auth-system/spec.md
	 */
	public function createToken(): JSONResponse {
		return $this->forCurrentUser(
			action: fn (IUser $user): JSONResponse => $this->createTokenFor(user: $user),
			onFailure: fn (Exception $e): JSONResponse => $this->runtimeFailure(exception: $e, message: 'Failed to create token')
		);
	}//end createToken()

	/**
	 * Create the API token the request names for the session user.
	 *
	 * @param IUser $user The session user.
	 *
	 * @return JSONResponse The created token (201).
	 */
	private function createTokenFor(IUser $user): JSONResponse {
		$data      = $this->request->getParams();
		$name      = $this->securityService->sanitizeInput(input: $data['name'] ?? '');
		$expiresIn = $data['expiresIn'] ?? null;
		if ($name === '') {
			return $this->responses->error(message: 'Token name is required', statusCode: 400);
		}

		$token = $this->userService->issueApiToken(user: $user, request: new ApiTokenRequest(name: $name, expiresIn: $expiresIn));
		return $this->responses->secured(data: $token, statusCode: 201);
	}//end createTokenFor()

	/**
	 * Revoke an API token for the current user
	 *
	 * @param string $id The token ID to revoke
	 *
	 * @return JSONResponse JSON response with result
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/specs/auth-system/spec.md
	 */
	public function revokeToken(string $id): JSONResponse {
		return $this->forCurrentUser(
			action: fn (IUser $user): JSONResponse => $this->responses->secured(data: $this->userService->revokeApiToken($user, $id)),
			onFailure: fn (Exception $e): JSONResponse => $this->runtimeFailure(exception: $e, message: 'Failed to revoke token')
		);
	}//end revokeToken()

	/**
	 * Request account deactivation for the current user
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse JSON response with result
	 *
	 * @spec openspec/specs/auth-system/spec.md
	 */
	public function requestDeactivation(): JSONResponse {
		return $this->forCurrentUser(
			action: fn (IUser $user): JSONResponse => $this->responses->secured(
				data: $this->userService->requestDeactivation(
					$user,
					$this->securityService->sanitizeInput(input: $this->request->getParams()['reason'] ?? '')
				)
			),
			onFailure: fn (Exception $e): JSONResponse => $this->deactivationRequestFailure(exception: $e)
		);
	}//end requestDeactivation()

	/**
	 * Get deactivation request status for the current user
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with status
	 *
	 * @spec openspec/specs/auth-system/spec.md
	 */
	public function getDeactivationStatus(): JSONResponse {
		return $this->forCurrentUser(
			action: fn (IUser $user): JSONResponse => $this->responses->secured(
				data: $this->userService->getDeactivationStatus($user)
			),
			onFailure: fn (Exception $e): JSONResponse => $this->unexpected(
				exception: $e,
				message: 'Failed to get deactivation status'
			)
		);
	}//end getDeactivationStatus()

	/**
	 * Cancel a pending deactivation request for the current user
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse JSON response with result
	 *
	 * @spec openspec/specs/auth-system/spec.md
	 */
	public function cancelDeactivation(): JSONResponse {
		return $this->forCurrentUser(
			action: fn (IUser $user): JSONResponse => $this->responses->secured(data: $this->userService->cancelDeactivation($user)),
			onFailure: fn (Exception $e): JSONResponse => $this->runtimeFailure(exception: $e, message: 'Failed to cancel deactivation')
		);
	}//end cancelDeactivation()

	/**
	 * Run a profile action for the session user: 401 without a session, the failure logged as a 500.
	 *
	 * Profile responses carry no security headers.
	 *
	 * @param callable(IUser): JSONResponse        $action   The action.
	 * @param array{log: string, failure?: string} $messages The logged failure message, and the failure response
	 *                                                       message; without one the exception message is exposed.
	 *
	 * @return JSONResponse The response.
	 */
	private function forProfileUser(callable $action, array $messages): JSONResponse {
		try {
			$currentUser = $this->userService->getCurrentUser();
			if ($currentUser === null) {
				return new JSONResponse(data: ['error' => 'Not authenticated'], statusCode: 401);
			}

			return $action($currentUser);
		} catch (Exception $e) {
			$this->logError(message: $messages['log'], exception: $e);
			return new JSONResponse(data: ['error' => ($messages['failure'] ?? $e->getMessage())], statusCode: 500);
		}//end try
	}//end forProfileUser()

	/**
	 * Run an action for the session user: 401 without a session, any failure handed to $onFailure.
	 *
	 * @param callable(IUser): T                            $action    The action.
	 * @param callable(Exception, IUser|null): JSONResponse $onFailure The failure response; it gets the session user when known.
	 *
	 * @return T|JSONResponse The response.
	 *
	 * @template T of JSONResponse|DataDownloadResponse
	 */
	private function forCurrentUser(callable $action, callable $onFailure): JSONResponse|DataDownloadResponse {
		$currentUser = null;
		try {
			$currentUser = $this->userService->getCurrentUser();
			if ($currentUser === null) {
				return $this->responses->error(message: 'Not authenticated', statusCode: 401);
			}

			return $action($currentUser);
		} catch (Exception $e) {
			return $onFailure($e, $currentUser);
		}//end try
	}//end forCurrentUser()

	/**
	 * The response for a failure: a RuntimeException under its code's status, anything else a logged 500.
	 *
	 * @param Exception $exception The failure.
	 * @param string    $message   The 500 response and log message.
	 *
	 * @return JSONResponse The response.
	 */
	private function runtimeFailure(Exception $exception, string $message): JSONResponse {
		if ($exception instanceof RuntimeException) {
			return $this->responses->runtimeError(exception: $exception);
		}

		return $this->unexpected(exception: $exception, message: $message);
	}//end runtimeFailure()

	/**
	 * The response for a failed export: a throttle (429) carries a JSON payload in its message.
	 *
	 * @param Exception $exception The failure.
	 *
	 * @return JSONResponse The response.
	 */
	private function exportFailure(Exception $exception): JSONResponse {
		if ($exception instanceof RuntimeException) {
			return $this->responses->runtimeError(exception: $exception, payloadStatus: 429);
		}

		return $this->unexpected(exception: $exception, message: 'Failed to export data');
	}//end exportFailure()

	/**
	 * The response for a failed deactivation request: a duplicate (409) carries a JSON payload in its message.
	 *
	 * @param Exception $exception The failure.
	 *
	 * @return JSONResponse The response.
	 */
	private function deactivationRequestFailure(Exception $exception): JSONResponse {
		if ($exception instanceof RuntimeException) {
			return $this->responses->runtimeError(exception: $exception, payloadStatus: 409);
		}

		return $this->unexpected(exception: $exception, message: 'Failed to request deactivation');
	}//end deactivationRequestFailure()

	/**
	 * The response for a failed activity read; the log names the read, the response the history.
	 *
	 * @param Exception $exception The failure.
	 *
	 * @return JSONResponse The response.
	 */
	private function activityFailure(Exception $exception): JSONResponse {
		$this->logError(message: 'Failed to get activity', exception: $exception);
		return $this->responses->error(message: 'Failed to get activity history', statusCode: 500);
	}//end activityFailure()

	/**
	 * Log an unexpected failure and hide it behind a 500.
	 *
	 * @param Exception $exception The failure.
	 * @param string    $message   The response and log message.
	 *
	 * @return JSONResponse The response.
	 */
	private function unexpected(Exception $exception, string $message): JSONResponse {
		$this->logError(message: $message, exception: $exception);
		return $this->responses->error(message: $message, statusCode: 500);
	}//end unexpected()

	/**
	 * The request parameters without the internal (underscore-prefixed) ones.
	 *
	 * @param array $data The request parameters.
	 *
	 * @return array The parameters without the internal ones.
	 */
	private function withoutInternalParams(array $data): array {
		foreach (array_keys($data) as $key) {
			if (str_starts_with(haystack: $key, needle: '_') === true) {
				unset($data[$key]);
			}
		}

		return $data;
	}//end withoutInternalParams()

	/**
	 * Log an error with standard context
	 *
	 * @param string $message The log message
	 * @param Exception $exception The exception that occurred
	 *
	 * @return void
	 */
	private function logError(string $message, Exception $exception): void {
		$this->logger->error(
			message: '[UserController] ' . $message,
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'error_message' => $exception->getMessage(),
				'error_code' => $exception->getCode(),
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
	private function convertToBytes(string $memoryLimit): int {
		// If memory limit is -1, it means unlimited.
		if ($memoryLimit === '-1') {
			return 0;
		}

		$memoryLimit = trim($memoryLimit);
		$suffix      = strtolower($memoryLimit[strlen($memoryLimit) - 1]);
		$exponent    = ['k' => 1, 'm' => 2, 'g' => 3][$suffix] ?? 0;

		return (int) $memoryLimit * (1024 ** $exponent);
	}//end convertToBytes()
}//end class
