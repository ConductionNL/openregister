<?php

/**
 * OpenRegister user API responses
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Support;

use OCA\OpenRegister\Service\SecurityService;
use OCP\AppFramework\Http\JSONResponse;
use RuntimeException;

/**
 * Builds the security-headed JSON responses of the user API.
 *
 * A RuntimeException thrown by the user service carries its HTTP status as the
 * exception code (0 meaning 500); some statuses carry a JSON payload as the
 * message, which is surfaced decoded.
 *
 * @spec openspec/specs/auth-system/spec.md
 */
final class UserResponses {
	/**
	 * Constructor.
	 *
	 * @param SecurityService $securityService Adds the security headers.
	 */
	public function __construct(
		private readonly SecurityService $securityService,
	) {
	}//end __construct()

	/**
	 * A JSON response with the security headers applied.
	 *
	 * @param mixed $data       The response body.
	 * @param int   $statusCode The HTTP status code.
	 *
	 * @return JSONResponse The response.
	 */
	public function secured(mixed $data, int $statusCode=200): JSONResponse {
		$response = new JSONResponse(data: $data, statusCode: $statusCode);
		return $this->securityService->addSecurityHeaders(response: $response);
	}//end secured()

	/**
	 * The `{error: message}` response with the security headers applied.
	 *
	 * @param string $message    The error message.
	 * @param int    $statusCode The HTTP status code.
	 *
	 * @return JSONResponse The response.
	 */
	public function error(string $message, int $statusCode): JSONResponse {
		return $this->secured(data: ['error' => $message], statusCode: $statusCode);
	}//end error()

	/**
	 * The response for a RuntimeException: its message under its code's status.
	 *
	 * When the status equals $payloadStatus, the message is a JSON payload and
	 * is returned decoded (falling back to the error shape when it is not JSON).
	 *
	 * @param RuntimeException $exception     The exception.
	 * @param int|null         $payloadStatus The status whose message is a JSON payload.
	 *
	 * @return JSONResponse The response.
	 */
	public function runtimeError(RuntimeException $exception, ?int $payloadStatus=null): JSONResponse {
		$status = $this->runtimeStatus(exception: $exception);
		if ($status === $payloadStatus) {
			$payload = json_decode($exception->getMessage(), true);
			return $this->secured(data: $payload ?? ['error' => $exception->getMessage()], statusCode: $status);
		}

		return $this->error(message: $exception->getMessage(), statusCode: $status);
	}//end runtimeError()

	/**
	 * The HTTP status a RuntimeException stands for: its code, or 500 without one.
	 *
	 * @param RuntimeException $exception The exception.
	 *
	 * @return int The status.
	 */
	public function runtimeStatus(RuntimeException $exception): int {
		$code = 500;
		if ($exception->getCode() !== 0) {
			$code = $exception->getCode();
		}

		return $code;
	}//end runtimeStatus()
}//end class
