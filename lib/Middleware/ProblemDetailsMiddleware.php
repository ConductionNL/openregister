<?php

/**
 * Serves the JSON error responses of OpenRegister's own API as RFC 9457
 * problem details.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Middleware
 * @package  OCA\OpenRegister\Middleware
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/openapi-generation/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Middleware;

use OCA\OpenRegister\Middleware\Support\StandardProtocolPaths;
use OCA\OpenRegister\Service\Oas\ProblemDescription;
use OCA\OpenRegister\Service\Oas\ProblemDetailsBuilderInterface;
use OCA\OpenRegister\Service\Oas\ProblemStatement;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Middleware;
use OCP\IRequest;

/**
 * Turns a 4xx or 5xx JSON response under `/api/` into an
 * `application/problem+json` document with type, title, status, detail and
 * instance.
 *
 * The body keeps every member it already had, with its value unchanged, so a
 * caller reading `error` or `message` sees no difference. A problem member the
 * body already carries is kept as it is; RFC 9457 section 3.1 tells consumers
 * to ignore a member whose value has the wrong type, such as `status: "error"`.
 * Paths that speak a protocol defined elsewhere are left alone
 * ({@see StandardProtocolPaths}).
 *
 * It acts in afterController, which Nextcloud also runs for the responses that
 * other middlewares produce from exceptions.
 *
 * @spec openspec/specs/openapi-generation/spec.md
 */
class ProblemDetailsMiddleware extends Middleware {

	/**
	 * The path fragment that marks a request as part of the API surface.
	 *
	 * @var string
	 */
	private const API_PATH_MARKER = '/api/';

	/**
	 * The HTTP reason phrase used as `title` for each error status.
	 *
	 * @var array<int, string>
	 */
	private const TITLES = [
		400 => 'Bad Request',
		401 => 'Unauthorized',
		402 => 'Payment Required',
		403 => 'Forbidden',
		404 => 'Not Found',
		405 => 'Method Not Allowed',
		406 => 'Not Acceptable',
		408 => 'Request Timeout',
		409 => 'Conflict',
		410 => 'Gone',
		412 => 'Precondition Failed',
		413 => 'Content Too Large',
		415 => 'Unsupported Media Type',
		422 => 'Unprocessable Content',
		423 => 'Locked',
		424 => 'Failed Dependency',
		428 => 'Precondition Required',
		429 => 'Too Many Requests',
		451 => 'Unavailable For Legal Reasons',
		500 => 'Internal Server Error',
		501 => 'Not Implemented',
		502 => 'Bad Gateway',
		503 => 'Service Unavailable',
		504 => 'Gateway Timeout',
		507 => 'Insufficient Storage',
	];

	/**
	 * Constructor.
	 *
	 * @param IRequest                       $request  The incoming request.
	 * @param ProblemDetailsBuilderInterface $problems Builds the problem document.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IRequest $request,
		private readonly ProblemDetailsBuilderInterface $problems,
	) {
	}//end __construct()

	/**
	 * Serve a JSON error response of the API as problem details.
	 *
	 * @param mixed    $controller The controller that ran.
	 * @param string   $methodName The method that ran.
	 * @param Response $response   The response.
	 *
	 * @return Response The same response, as problem details when it qualifies.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The Middleware contract fixes this signature.
	 *
	 * @spec openspec/specs/openapi-generation/spec.md
	 */
	public function afterController($controller, $methodName, Response $response): Response {
		if ($response instanceof JSONResponse === false || $this->qualifies(response: $response) === false) {
			return $response;
		}

		$problem = $this->problemFor(data: $response->getData(), status: $response->getStatus());
		if ($problem === null) {
			return $response;
		}

		$response->setData($problem);
		$response->addHeader('Content-Type', ProblemDetailsBuilderInterface::CONTENT_TYPE . '; charset=utf-8');

		return $response;
	}//end afterController()

	/**
	 * Whether a JSON response is an error of OpenRegister's own API that is not yet problem details.
	 *
	 * @param JSONResponse $response The response.
	 *
	 * @return bool True when the response should become problem details.
	 */
	private function qualifies(JSONResponse $response): bool {
		if ($response->getStatus() < 400) {
			return false;
		}

		$contentType = (string)($response->getHeaders()['Content-Type'] ?? '');
		if (str_starts_with($contentType, 'application/problem+') === true) {
			return false;
		}

		$path = $this->request->getPathInfo();
		if (is_string($path) === false || str_contains($path, self::API_PATH_MARKER) === false) {
			return false;
		}

		return StandardProtocolPaths::matches(path: $path) === false;
	}//end qualifies()

	/**
	 * The problem details form of an error body, or null when the body is a list.
	 *
	 * @param mixed $data   The error body.
	 * @param int   $status The response status.
	 *
	 * @return array<array-key, mixed>|null The body with the missing problem members added, or null.
	 */
	private function problemFor(mixed $data, int $status): ?array {
		if ($data === null) {
			$data = [];
		}

		if (is_array($data) === false || ($data !== [] && array_is_list($data) === true)) {
			return null;
		}

		$problem = $this->problems->build(
			statement: new ProblemStatement(
				status: $status,
				title: (self::TITLES[$status] ?? 'Error'),
				description: new ProblemDescription(detail: $this->detailOf(data: $data), instance: $this->instance())
			)
		);

		return array_merge($problem, $data);
	}//end problemFor()

	/**
	 * The explanation an error body already gives, from `message` or else `error`.
	 *
	 * @param array<array-key, mixed> $data The error body.
	 *
	 * @return string The explanation, or '' when the body has none.
	 */
	private function detailOf(array $data): string {
		foreach (['message', 'error'] as $member) {
			if (is_string($data[$member] ?? null) === true && $data[$member] !== '') {
				return $data[$member];
			}
		}

		return '';
	}//end detailOf()

	/**
	 * The path of the request that failed, without its query string.
	 *
	 * @return string The request path.
	 */
	private function instance(): string {
		$uri = (string)$this->request->getRequestUri();
		$queryAt = strpos($uri, '?');
		if ($queryAt === false) {
			return $uri;
		}

		return substr($uri, 0, $queryAt);
	}//end instance()
}//end class
