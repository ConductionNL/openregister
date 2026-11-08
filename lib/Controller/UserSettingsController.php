<?php

/**
 * OpenRegister User Settings Controller
 *
 * This file contains the controller class for handling user-specific settings,
 * particularly GitHub token management.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Controller
 * @package   OCA\OpenRegister\Controller
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 */

namespace OCA\OpenRegister\Controller;

use Exception;
use OCA\OpenRegister\Kernel\Contracts\GitHubClientInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Class UserSettingsController
 *
 * Controller for managing user-specific settings (GitHub tokens, etc.).
 *
 * @package OCA\OpenRegister\Controller
 *
 * @psalm-suppress UnusedClass
 */
class UserSettingsController extends Controller {

	/**
	 * GitHub service instance.
	 *
	 * @var GitHubClientInterface The GitHub service instance.
	 */
	private GitHubClientInterface $gitHubService;

	/**
	 * User session instance.
	 *
	 * @var IUserSession The user session instance.
	 */
	private IUserSession $userSession;

	/**
	 * Logger instance.
	 *
	 * @var LoggerInterface The logger instance.
	 */
	private LoggerInterface $logger;

	/**
	 * Constructor
	 *
	 * @param string $appName The app name
	 * @param IRequest $request The request object
	 * @param GitHubClientInterface $gitHubService GitHub service
	 * @param IUserSession $userSession User session
	 * @param LoggerInterface $logger Logger
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		GitHubClientInterface $gitHubService,
		IUserSession $userSession,
		LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);

		$this->gitHubService = $gitHubService;
		$this->userSession = $userSession;
		$this->logger = $logger;
	}//end __construct()

	/**
	 * Get current GitHub token status (without exposing the token).
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse JSON response containing GitHub token status
	 *
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/auth-system/spec.md
	 */
	public function getGitHubTokenStatus(): JSONResponse {
		return $this->forUser(
			action: fn (IUser $user): JSONResponse => $this->tokenStatus(user: $user),
			logged: 'Failed to get GitHub token status',
			failure: static fn (): string => 'Failed to get token status'
		);
	}//end getGitHubTokenStatus()

	/**
	 * The GitHub token status of the user, validating a stored token.
	 *
	 * @param IUser $user The session user.
	 *
	 * @return JSONResponse The status.
	 */
	private function tokenStatus(IUser $user): JSONResponse {
		$token = $this->gitHubService->getUserToken($user->getUID());
		if ($token === null) {
			return new JSONResponse(
				data: ['hasToken' => false, 'isValid' => false, 'message' => 'No GitHub token configured'],
				statusCode: 200
			);
		}

		// Validate the token.
		$this->gitHubService->setUserToken(token: $token, userId: $user->getUID());
		$isValid = $this->gitHubService->validateToken(userId: $user->getUID());

		return new JSONResponse(
			data: ['hasToken' => true, 'isValid' => $isValid, 'message' => $this->getTokenValidationMessage(isValid: $isValid)],
			statusCode: 200
		);
	}//end tokenStatus()

	/**
	 * Set GitHub personal access token for the current user.
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response containing result of token save operation
	 *
	 * @spec openspec/specs/auth-system/spec.md
	 */
	public function setGitHubToken(): JSONResponse {
		return $this->forUser(
			action: fn (IUser $user): JSONResponse => $this->storeToken(user: $user, token: $this->request->getParams()['token'] ?? null),
			logged: 'Failed to set GitHub token',
			failure: static fn (Exception $e): string => 'Failed to save token: ' . $e->getMessage()
		);
	}//end setGitHubToken()

	/**
	 * Validate the token and keep it for the user when valid.
	 *
	 * @param IUser       $user  The session user.
	 * @param string|null $token The token from the request.
	 *
	 * @return JSONResponse The result.
	 */
	private function storeToken(IUser $user, ?string $token): JSONResponse {
		if ($token === null || trim($token) === '') {
			return new JSONResponse(data: ['error' => 'Token is required'], statusCode: 400);
		}

		// Validate the token before saving.
		$this->gitHubService->setUserToken(token: $token, userId: $user->getUID());
		if ($this->gitHubService->validateToken(userId: $user->getUID()) === false) {
			return new JSONResponse(data: ['error' => 'Invalid GitHub token'], statusCode: 400);
		}

		// Save the token (it's already saved by setUserToken).
		$this->logger->info(
			message: "[UserSettingsController] GitHub token set for user: {$user->getUID()}",
			context: ['file' => __FILE__, 'line' => __LINE__]
		);

		return new JSONResponse(data: ['success' => true, 'message' => 'GitHub token saved successfully'], statusCode: 200);
	}//end storeToken()

	/**
	 * Remove GitHub personal access token for the current user.
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse Success or error message
	 *
	 * @spec openspec/specs/auth-system/spec.md
	 */
	public function removeGitHubToken(): JSONResponse {
		return $this->forUser(
			action: fn (IUser $user): JSONResponse => $this->clearToken(user: $user),
			logged: 'Failed to remove GitHub token',
			failure: static fn (): string => 'Failed to remove token'
		);
	}//end removeGitHubToken()

	/**
	 * Clear the user's token.
	 *
	 * @param IUser $user The session user.
	 *
	 * @return JSONResponse The result.
	 */
	private function clearToken(IUser $user): JSONResponse {
		$this->gitHubService->setUserToken(token: null, userId: $user->getUID());

		$this->logger->info(
			message: "[UserSettingsController] GitHub token removed for user: {$user->getUID()}",
			context: ['file' => __FILE__, 'line' => __LINE__]
		);

		return new JSONResponse(data: ['success' => true, 'message' => 'GitHub token removed successfully'], statusCode: 200);
	}//end clearToken()

	/**
	 * Run an action for the session user: 401 without one, a failure logged and answered with a 500.
	 *
	 * @param callable(IUser): JSONResponse $action  The action.
	 * @param string                        $logged  The logged failure message (the exception message is appended).
	 * @param callable(Exception): string   $failure The failure response message.
	 *
	 * @return JSONResponse The response.
	 */
	private function forUser(callable $action, string $logged, callable $failure): JSONResponse {
		try {
			$user = $this->userSession->getUser();
			if ($user === null) {
				return new JSONResponse(data: ['error' => 'User not authenticated'], statusCode: 401);
			}

			return $action($user);
		} catch (Exception $e) {
			$this->logger->error(
				message: '[UserSettingsController] ' . $logged . ': ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);

			return new JSONResponse(data: ['error' => $failure($e)], statusCode: 500);
		}//end try
	}//end forUser()

	/**
	 * Get token validation message
	 *
	 * @param bool $isValid Whether token is valid
	 *
	 * @return string Validation message
	 *
	 * @psalm-return 'Token is invalid or expired'|'Token is valid'
	 */
	private function getTokenValidationMessage(bool $isValid): string {
		if ($isValid === true) {
			return 'Token is valid';
		}

		return 'Token is invalid or expired';
	}//end getTokenValidationMessage()
}//end class
