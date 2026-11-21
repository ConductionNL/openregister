<?php

/**
 * Announces on every unversioned API path that it is deprecated in favour of
 * its `/api/v1/` alias.
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

use OCA\OpenRegister\Middleware\Support\ApiPathAliases;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Middleware;
use OCP\IRequest;

/**
 * Adds the RFC 9745 `Deprecation` header and a `Link` to the successor path
 * to responses on a deprecated unversioned API path. It only decorates: the
 * path keeps answering exactly as before.
 *
 * Registered after ApiVersionMiddleware, so it runs before it in
 * afterController and a deprecated contract version's own headers win.
 * A response the controller already marked deprecated keeps its own headers.
 *
 * @spec openspec/specs/openapi-generation/spec.md
 */
class UnversionedPathDeprecationMiddleware extends Middleware {

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The incoming request.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IRequest $request,
	) {
	}//end __construct()

	/**
	 * Mark a response on a deprecated path.
	 *
	 * @param mixed    $controller The controller that ran.
	 * @param string   $methodName The method that ran.
	 * @param Response $response   The response.
	 *
	 * @return Response The same response, with the deprecation headers when the path is deprecated.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The Middleware contract fixes this signature.
	 *
	 * @spec openspec/specs/openapi-generation/spec.md
	 */
	public function afterController($controller, $methodName, Response $response): Response {
		$path = $this->request->getPathInfo();
		if (is_string($path) === false || $path === '') {
			return $response;
		}

		$successor = ApiPathAliases::successorOf(path: $path);
		if ($successor === null || $this->isAlreadyDeprecated(response: $response) === true) {
			return $response;
		}

		$response->addHeader('Deprecation', ApiPathAliases::DEPRECATED_SINCE);
		$response->addHeader('Link', '<' . $this->scriptPrefix() . $successor . '>; rel="successor-version"');

		return $response;
	}//end afterController()

	/**
	 * Whether the controller already marked the response deprecated, naming its own successor.
	 *
	 * @param Response $response The response.
	 *
	 * @return bool True when the response carries a `Deprecation` or `Link` header.
	 */
	private function isAlreadyDeprecated(Response $response): bool {
		$names = array_map('strtolower', array_keys($response->getHeaders()));

		return in_array('deprecation', $names, true) === true || in_array('link', $names, true) === true;
	}//end isAlreadyDeprecated()

	/**
	 * The front controller that precedes the path info in the request URI, such as `/index.php`.
	 *
	 * @return string The prefix, or '' when the URI carries none.
	 */
	private function scriptPrefix(): string {
		$uri      = (string)$this->request->getRequestUri();
		$pathInfo = (string)$this->request->getPathInfo();
		$at       = strpos($uri, $pathInfo);
		if ($pathInfo === '' || $at === false) {
			return '';
		}

		return substr($uri, 0, $at);
	}//end scriptPrefix()
}//end class
