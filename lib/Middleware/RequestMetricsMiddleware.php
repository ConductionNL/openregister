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
use OCA\OpenRegister\Kernel\Contracts\RequestMetricsInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Middleware;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;
use OCP\IUserSession;
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
	 * @param RequestMetricsInterface $recorder The request metrics.
	 * @param ITimeFactory           $time     The clock.
	 * @param LoggerInterface        $logger   The logger.
	 * @param IUserSession|null      $session  The signed-in user, for the request log entry.
	 */
	public function __construct(
		private readonly IRequest $request,
		private readonly RequestMetricsInterface $recorder,
		private readonly ITimeFactory $time,
		private readonly LoggerInterface $logger,
		private readonly ?IUserSession $session = null,
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
		$durationMs = $this->elapsedMs();
		try {
			$this->recorder->record(
				method: $this->request->getMethod(),
				route: $this->routeOf(controller: $controller, methodName: (string) $methodName),
				status: $response->getStatus(),
				durationMs: $durationMs
			);
			$this->recorder->flushIfDue();
		} catch (Throwable $e) {
			$this->logger->debug('[Observability] request metrics not recorded: '.$e->getMessage());
		}

		try {
			$context = [...$this->requestContext(), 'status_code' => $response->getStatus(), 'duration_ms' => $durationMs];
			$this->logger->info('[OpenRegister] {method} {path} answered {status_code} in {duration_ms} ms', $context);
		} catch (Throwable $e) {
			$this->logger->debug('[Observability] request log entry not written: '.$e->getMessage());
		}

		return $response;
	}//end afterController()

	/**
	 * The structured fields of the request, the same on its answer and on its error entry.
	 *
	 * `request_id` is Nextcloud's request id, which Nextcloud also writes as `reqId` on every
	 * other log entry of the request, so all entries of one request correlate. Only the route's
	 * `register` and `schema` parameters are taken from the request: never its body, query
	 * values or object data, so no personal data reaches the log.
	 *
	 * @return array{request_id: string, user: string|null, method: string, path: string, register: string|null, schema: string|null}
	 *
	 * @spec openspec/changes/production-observability/specs/production-observability/spec.md#requirement-structured-logging
	 */
	private function requestContext(): array {
		$path = (string) parse_url((string) $this->request->getRequestUri(), PHP_URL_PATH);

		return [
			'request_id' => (string) $this->request->getId(),
			'user' => $this->session?->getUser()?->getUID(),
			'method' => (string) $this->request->getMethod(),
			'path' => $path,
			'register' => self::scalarParam(value: $this->request->getParam('register')),
			'schema' => self::scalarParam(value: $this->request->getParam('schema')),
		];
	}//end requestContext()

	/**
	 * A route parameter as a string of at most 64 characters, or null when absent or not scalar.
	 *
	 * @param mixed $value The parameter.
	 *
	 * @return string|null
	 */
	private static function scalarParam(mixed $value): ?string {
		if (is_scalar($value) === false || (string) $value === '') {
			return null;
		}

		return substr((string) $value, 0, 64);
	}//end scalarParam()

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
		$type = self::errorTypeOf(exception: $exception);
		try {
			$this->recorder->recordError(type: $type);
		} catch (Throwable $e) {
			$this->logger->debug('[Observability] error metric not recorded: '.$e->getMessage());
		}

		if ($type === 'database') {
			$this->logDatabaseError(controller: $controller, methodName: (string) $methodName, exception: $exception);
		}

		throw $exception;
	}//end afterException()

	/**
	 * Log a database failure at ERROR with its class, message, stack trace and request context.
	 *
	 * A database message can quote the values of the row it refused, so quoted literals are
	 * replaced by `?`, and the stack trace lists frames without their arguments.
	 *
	 * Only database failures are logged here: a validation or other exception is answered as a
	 * client error by the middleware after this one, or logged by Nextcloud when it is not.
	 *
	 * @param Controller $controller The controller.
	 * @param string     $methodName The controller method.
	 * @param Exception  $exception  The exception.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/production-observability/specs/production-observability/spec.md#requirement-structured-logging
	 */
	private function logDatabaseError($controller, string $methodName, Exception $exception): void {
		try {
			$request = $this->requestContext();
			$this->logger->error(
				'[OpenRegister] {method} {path} failed: {error_type}',
				[
					...$request,
					'error_type' => get_class($exception),
					'error_message' => self::withoutLiterals(message: $exception->getMessage()),
					'stack_trace' => self::traceOf(exception: $exception),
					'context' => [
						'register' => $request['register'],
						'schema' => $request['schema'],
						'action' => $this->routeOf(controller: $controller, methodName: $methodName),
					],
				]
			);
		} catch (Throwable $e) {
			$this->logger->debug('[Observability] error log entry not written: '.$e->getMessage());
		}
	}//end logDatabaseError()

	/**
	 * A message with every quoted literal replaced by `?`.
	 *
	 * @param string $message The message.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/production-observability/specs/production-observability/spec.md#requirement-structured-logging
	 */
	public static function withoutLiterals(string $message): string {
		return (string) preg_replace(['/\'(?:[^\'\\\\]|\\\\.)*\'/s', '/"(?:[^"\\\\]|\\\\.)*"/s'], ["'?'", '"?"'], $message);
	}//end withoutLiterals()

	/**
	 * The stack trace of an exception, one `#n file(line): class->function()` frame per line, without arguments.
	 *
	 * @param Throwable $exception The exception.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/production-observability/specs/production-observability/spec.md#requirement-structured-logging
	 */
	public static function traceOf(Throwable $exception): string {
		$lines = [];
		foreach ($exception->getTrace() as $index => $frame) {
			$where = '[internal]';
			if (isset($frame['file']) === true) {
				$where = $frame['file'].'('.($frame['line'] ?? 0).')';
			}

			$lines[] = '#'.$index.' '.$where.': '.($frame['class'] ?? '').($frame['type'] ?? '').$frame['function'].'()';
		}

		return implode("\n", $lines);
	}//end traceOf()

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
