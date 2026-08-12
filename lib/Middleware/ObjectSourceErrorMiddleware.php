<?php

/**
 * Object Source Error Middleware
 *
 * Maps a {@see DbalObjectSourceException} thrown anywhere in a request against
 * an object-source-backed schema (find, list, count) onto the HTTP status the
 * failure semantics spec requires: 503 when the external database is
 * unreachable, 502 when it returned an upstream error — never a bare 500.
 * Every other exception is rethrown untouched so existing error handling is
 * unaffected.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Middleware
 * @package  OCA\OpenRegister\Middleware
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/dbal-virtual-registers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Middleware;

use Exception;
use OCA\OpenRegister\Service\ObjectSource\DbalObjectSourceException;
use OCA\OpenRegister\Service\ObjectSource\DbalWriteException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Middleware;
use Psr\Log\LoggerInterface;

/**
 * Middleware translating external-database read failures into 502/503 responses.
 *
 * @package OCA\OpenRegister\Middleware
 */
class ObjectSourceErrorMiddleware extends Middleware {
	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger The app logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Map DbalObjectSourceException onto its declared HTTP status.
	 *
	 * The exception's message is non-sensitive by contract (the provider never
	 * embeds credentials or SQL in it), so it is safe to return to the client.
	 *
	 * @param Controller $controller The controller that was executing.
	 * @param string $methodName The controller method that was executing.
	 * @param Exception $exception The exception that was thrown.
	 *
	 * @return Response The 502/503 response for external-database failures.
	 *
	 * @throws Exception Rethrows every exception that is not a DbalObjectSourceException.
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	public function afterException(Controller $controller, string $methodName, Exception $exception): Response {
		// Sanitized write failures (constraint violations etc., dbal-virtual-
		// registers-crud): render the carried 4xx/5xx status; log the wrapped
		// driver exception server-side.
		if ($exception instanceof DbalWriteException === true) {
			$this->logger->warning(
				sprintf(
					'[ObjectSourceErrorMiddleware] external database write failed (%d) in %s::%s: %s',
					$exception->getStatusCode(),
					$controller::class,
					$methodName,
					$exception->getMessage()
				),
				[
					'file' => __FILE__,
					'line' => __LINE__,
					'exception' => $exception,
				]
			);

			return new JSONResponse(
				data: ['error' => $exception->getMessage()],
				statusCode: $exception->getStatusCode()
			);
		}//end if

		if ($exception instanceof DbalObjectSourceException === false) {
			throw $exception;
		}

		$this->logger->warning(
			sprintf(
				'[ObjectSourceErrorMiddleware] external database read failed (%d) in %s::%s: %s',
				$exception->getStatusCode(),
				$controller::class,
				$methodName,
				$exception->getMessage()
			),
			[
				'file' => __FILE__,
				'line' => __LINE__,
				// The underlying DBAL error (never sent to the client) — without
				// it the server log carries only the sanitized message and the
				// actual SQL failure is undiagnosable.
				'exception' => $exception,
			]
		);

		return new JSONResponse(
			data: ['error' => $exception->getMessage()],
			statusCode: $exception->getStatusCode()
		);
	}//end afterException()
}//end class
