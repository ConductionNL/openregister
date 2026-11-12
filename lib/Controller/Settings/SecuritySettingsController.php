<?php

/**
 * OpenRegister Security Settings Controller
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

use Exception;
use OCA\OpenRegister\AppInfo\Application;
use OCA\OpenRegister\Controller\Support\SecuritySettingsControllerServices;
use OCA\OpenRegister\Service\RateLimitResetInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
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
class SecuritySettingsController extends Controller {
	/**
	 * Security service.
	 *
	 * @var RateLimitResetInterface
	 */
	private readonly RateLimitResetInterface $securityService;

	/**
	 * Logger.
	 *
	 * @var LoggerInterface
	 */
	private readonly LoggerInterface $logger;

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param SecuritySettingsControllerServices $services The controller's collaborators.
	 */
	public function __construct(
		IRequest $request,
		SecuritySettingsControllerServices $services,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
		$this->securityService = $services->securityService;
		$this->logger = $services->logger;
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
	 *
	 * @spec openspec/specs/production-observability/spec.md
	 */
	public function clearIpRateLimits(): JSONResponse {
		try {
			return $this->clearIp(ipAddress: $this->request->getParams()['ip'] ?? null);
		} catch (Exception $e) {
			return $this->failure(message: 'Failed to clear IP rate limits', error: $e);
		}
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
	 *
	 * @spec openspec/specs/production-observability/spec.md
	 */
	public function clearUserRateLimits(): JSONResponse {
		try {
			return $this->clearUser(username: $this->request->getParams()['username'] ?? null);
		} catch (Exception $e) {
			return $this->failure(message: 'Failed to clear user rate limits', error: $e);
		}
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
	 *
	 * @spec openspec/specs/production-observability/spec.md
	 */
	public function clearAllRateLimits(): JSONResponse {
		try {
			$data = $this->request->getParams();
			return $this->clearBoth(ipAddress: $data['ip'] ?? null, username: $data['username'] ?? null);
		} catch (Exception $e) {
			return $this->failure(message: 'Failed to clear rate limits', error: $e);
		}
	}//end clearAllRateLimits()

	/**
	 * Clear the rate limits of one IP address.
	 *
	 * @param mixed $ipAddress The requested IP address.
	 *
	 * @return JSONResponse The result
	 */
	private function clearIp(mixed $ipAddress): JSONResponse {
		if (empty($ipAddress) === true) {
			return $this->badRequest(error: 'IP address is required');
		}

		$this->securityService->clearIpRateLimits(ipAddress: $ipAddress);
		$this->logCleared(message: 'IP rate limits cleared by admin', cleared: ['ip_address' => $ipAddress]);

		return new JSONResponse(
			data: ['success' => true, 'message' => 'IP rate limits cleared successfully', 'ip_address' => $ipAddress]
		);
	}//end clearIp()

	/**
	 * Clear the rate limits of one user.
	 *
	 * @param mixed $username The requested username.
	 *
	 * @return JSONResponse The result
	 */
	private function clearUser(mixed $username): JSONResponse {
		if (empty($username) === true) {
			return $this->badRequest(error: 'Username is required');
		}

		$this->securityService->clearUserRateLimits(username: $username);
		$this->logCleared(message: 'User rate limits cleared by admin', cleared: ['username' => $username]);

		return new JSONResponse(
			data: ['success' => true, 'message' => 'User rate limits cleared successfully', 'username' => $username]
		);
	}//end clearUser()

	/**
	 * Clear the rate limits of whichever of an IP address and a user is given.
	 *
	 * @param mixed $ipAddress The requested IP address.
	 * @param mixed $username  The requested username.
	 *
	 * @return JSONResponse The result
	 */
	private function clearBoth(mixed $ipAddress, mixed $username): JSONResponse {
		if (empty($ipAddress) === true && empty($username) === true) {
			return $this->badRequest(error: 'At least one of IP address or username is required');
		}

		$cleared = $this->clearGiven(ipAddress: $ipAddress, username: $username);
		$this->logCleared(message: 'Rate limits cleared by admin', cleared: $cleared);

		return new JSONResponse(
			data: ['success' => true, 'message' => 'Rate limits cleared successfully', 'cleared' => $cleared]
		);
	}//end clearBoth()

	/**
	 * Clear the IP and user rate limits that were given, IP first.
	 *
	 * @param mixed $ipAddress The requested IP address.
	 * @param mixed $username  The requested username.
	 *
	 * @return array<string, mixed> What was cleared
	 */
	private function clearGiven(mixed $ipAddress, mixed $username): array {
		$cleared = [];
		if (empty($ipAddress) === false) {
			$this->securityService->clearIpRateLimits(ipAddress: $ipAddress);
			$cleared['ip_address'] = $ipAddress;
		}

		if (empty($username) === false) {
			$this->securityService->clearUserRateLimits(username: $username);
			$cleared['username'] = $username;
		}

		return $cleared;
	}//end clearGiven()

	/**
	 * A 400 response carrying one error message.
	 *
	 * @param string $error The error message.
	 *
	 * @return JSONResponse The response
	 */
	private function badRequest(string $error): JSONResponse {
		return new JSONResponse(data: ['error' => $error], statusCode: 400);
	}//end badRequest()

	/**
	 * Log which rate limits an administrator cleared.
	 *
	 * @param string               $message The log message, without the class prefix.
	 * @param array<string, mixed> $cleared What was cleared.
	 *
	 * @return void
	 */
	private function logCleared(string $message, array $cleared): void {
		$this->logger->info(
			message: '[SecuritySettingsController] '.$message,
			context: array_merge(['file' => __FILE__, 'line' => __LINE__], $cleared)
		);
	}//end logCleared()

	/**
	 * Log a failed clear and answer it with a 500 response.
	 *
	 * @param string    $message The log message, without the class prefix.
	 * @param Exception $error   The failure.
	 *
	 * @return JSONResponse The response
	 */
	private function failure(string $message, Exception $error): JSONResponse {
		$this->logger->error(
			message: '[SecuritySettingsController] '.$message,
			context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $error->getMessage()]
		);

		return new JSONResponse(data: ['error' => $error->getMessage()], statusCode: 500);
	}//end failure()
}//end class
