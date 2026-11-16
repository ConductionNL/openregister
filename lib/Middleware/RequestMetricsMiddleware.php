<?php

/**
 * Records every OpenRegister API request in the request metrics.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Middleware
 * @package  OCA\OpenRegister\Middleware
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Middleware;

use Exception;
use OCA\OpenRegister\Exception\DatabaseConstraintException;
use OCA\OpenRegister\Service\Observability\RequestMetricsRecorder;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Middleware;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Counts each answered request by method, route and status, times it, and counts errors by type.
 *
 * The afterController hook sees every response, also the ones another middleware
 * made out of an exception, so the status is the one the client gets. The
 * duration runs from the request's start (`REQUEST_TIME_FLOAT`) to the
 * controller's answer. The afterException hook only classifies the exception and
 * rethrows it: the response is still made by the middlewares that handle it.
 * Recording never fails a request.
 *
 * @spec openspec/changes/production-observability
 */
class RequestMetricsMiddleware extends Middleware {

	/**
	 * The prefix Nextcloud puts before OpenRegister's route names.
	 */
	private const ROUTE_PREFIX = 'openregister.';

	/**
	 * Constructor.
	 *
	 * @param IRequest               $request  The request.
	 * @param RequestMetricsRecorder $recorder The request metrics.
	 * @param ITimeFactory           $time     The clock.
	 * @param LoggerInterface        $logger   The logger.
	 */
	public function __construct(
		private readonly IRequest $request,
		private readonly RequestMetricsRecorder $recorder,
		private readonly ITimeFactory $time,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Record the answered request, then flush when a flush is due.
	 *
	 * @param Controller $controller The controller.
	 * @param string     $methodName The controller method.
	 * @param Response   $response   The response.
	 *
	 * @return Response The same response.
	 *
	 * @spec openspec/changes/production-observability
	 */
	public function afterController($controller, $methodName, Response $response): Response {
		try {
			$this->recorder->record(
				method: $this->request->getMethod(),
				route: $this->routeOf(controller: $controller, methodName: (string) $methodName),
				status: $response->getStatus(),
				durationMs: $this->elapsedMs()
			);
			$this->recorder->flushIfDue();
		} catch (Throwable $e) {
			$this->logger->debug('[Observability] request metrics not recorded: '.$e->getMessage());
		}

		return $response;
	}//end afterController()

	/**
	 * Count the exception by type and rethrow it.
	 *
	 * @param Controller $controller The controller.
	 * @param string     $methodName The controller method.
	 * @param Exception  $exception  The exception.
	 *
	 * @return Response Never; the exception is rethrown.
	 *
	 * @throws Exception Always the exception it was given.
	 *
	 * @spec openspec/changes/production-observability
	 */
	public function afterException($controller, $methodName, Exception $exception): Response {
		try {
			$this->recorder->recordError(type: self::errorTypeOf(exception: $exception));
		} catch (Throwable $e) {
			$this->logger->debug('[Observability] error metric not recorded: '.$e->getMessage());
		}

		throw $exception;
	}//end afterException()

	/**
	 * The error type of an exception: `database`, `validation` or `other`.
	 *
	 * Database: Nextcloud's and Doctrine's database exceptions and
	 * OpenRegister's constraint exception, also when wrapped as a previous
	 * exception. Validation: every exception whose class name ends in
	 * `ValidationException` (OpenRegister has several unrelated ones).
	 *
	 * @param Throwable $exception The exception.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/production-observability
	 */
	public static function errorTypeOf(Throwable $exception): string {
		for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
			if ($current instanceof \OCP\DB\Exception
				|| $current instanceof \Doctrine\DBAL\Exception
				|| $current instanceof DatabaseConstraintException
			) {
				return 'database';
			}
		}

		if (str_ends_with(get_class($exception), 'ValidationException') === true) {
			return 'validation';
		}

		return 'other';
	}//end errorTypeOf()

	/**
	 * The route key: Nextcloud's route name without the app prefix, else `<controller>.<method>`.
	 *
	 * @param Controller $controller The controller.
	 * @param string     $methodName The controller method.
	 *
	 * @return string
	 */
	private function routeOf($controller, string $methodName): string {
		$route = $this->request->getParam('_route');
		if (is_string($route) === true && str_starts_with($route, self::ROUTE_PREFIX) === true) {
			return substr($route, strlen(self::ROUTE_PREFIX));
		}

		$class = get_class($controller);
		$short = substr($class, (int) strrpos($class, '\\') + 1);
		if (str_ends_with($short, 'Controller') === true) {
			$short = substr($short, 0, -strlen('Controller'));
		}

		return lcfirst($short).'.'.$methodName;
	}//end routeOf()

	/**
	 * Milliseconds since the request started, null when the start is unknown.
	 *
	 * @return int|null
	 */
	private function elapsedMs(): ?int {
		$start = $_SERVER['REQUEST_TIME_FLOAT'] ?? null;
		if (is_float($start) === false && is_int($start) === false) {
			return null;
		}

		$now = (float) $this->time->now()->format('U.u');

		return max(0, (int) round(($now - (float) $start) * 1000));
	}//end elapsedMs()
}//end class
