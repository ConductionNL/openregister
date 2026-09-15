<?php

/**
 * Makes every API answer say which contract answered it, and refuses one that
 * names a contract this instance no longer serves.
 *
 * WHY A MIDDLEWARE AND NOT A ROUTE PREFIX. There are 831 API routes in this
 * app and exactly three of them carry a version in the path. Mirroring the
 * other 828 under `/api/v1/` would be a breaking change on the day it shipped
 * — every existing integration would have to move at once, which is precisely
 * the coordinated outage this change exists to end. So the current surface
 * stays exactly where it is and becomes version 1 by declaration, and a caller
 * pins itself with a header. A future version 2 can take a path prefix of its
 * own; {@see ApiVersion::$pathPrefixes} is where it says so.
 *
 * WHAT IT DOES, IN ORDER.
 *
 *  - Before the controller: reads the version the caller speaks. A withdrawn
 *    one is refused with 410 naming the successor, an undeclared one with 400
 *    listing what is served. Everything else proceeds untouched.
 *  - After the controller: stamps `API-Version` on the response, and when the
 *    answering version is deprecated adds the RFC 8594 `Deprecation` and
 *    `Sunset` headers and a `Link` to the successor's description. That is the
 *    whole of design D-3: a client learns its deadline from the calls it is
 *    already making.
 *
 * 🔴 IT DECORATES, IT DOES NOT ROUTE. Nothing here changes which controller
 * runs. A deprecated version answers with exactly the behaviour it had before
 * being deprecated, because a deprecation that also changes behaviour is a
 * breaking change wearing a header.
 *
 * SCOPE. API paths only, decided from the request path rather than from a list
 * of controller classes: a list of 200-odd controllers would be wrong within a
 * week, and the page-shell routes that share those controllers must not have
 * an `API-Version` header attached to an HTML response.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Middleware
 * @package  OCA\OpenRegister\Middleware
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/api-as-a-versioned-surface/specs/openapi-generation/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Middleware;

use Exception;
use OCA\OpenRegister\Middleware\Exception\ApiVersionRefusedException;
use OCA\OpenRegister\Service\ApiVersion\ApiVersion;
use OCA\OpenRegister\Service\ApiVersion\ApiVersionNegotiator;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Middleware;
use OCP\IRequest;

/**
 * Stamps the answering contract version onto API responses and refuses a
 * request that names one this instance does not serve.
 *
 * @category Middleware
 * @package  OCA\OpenRegister\Middleware
 */
class ApiVersionMiddleware extends Middleware {

	/**
	 * The path fragment that marks a request as part of the API surface.
	 *
	 * @var string
	 */
	private const API_PATH_MARKER = '/api/';

	/**
	 * The path under which each version's own description is published.
	 *
	 * @var string
	 */
	private const CONTRACT_PATH = '/apps/openregister/api/versions/';

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The incoming request.
	 * @param ApiVersionNegotiator $negotiator Resolves the version a request speaks.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IRequest $request,
		private readonly ApiVersionNegotiator $negotiator,
	) {

	}//end __construct()

	/**
	 * Refuse a request naming a withdrawn or undeclared version.
	 *
	 * @param mixed $controller The controller being dispatched.
	 * @param string $methodName The method being dispatched.
	 *
	 * @return void
	 *
	 * @throws ApiVersionRefusedException When the named version cannot answer.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The Nextcloud Middleware
	 * contract fixes this signature; the decision is made from the request path,
	 * not from the controller, for the reason given in the class docblock.
	 *
	 * @spec openspec/changes/api-as-a-versioned-surface/specs/openapi-generation/spec.md
	 */
	public function beforeController($controller, $methodName): void {
		if ($this->isApiRequest() === false) {
			return;
		}

		$negotiation = $this->negotiator->negotiate(request: $this->request);

		if ($negotiation->isUnknown() === true) {
			throw ApiVersionRefusedException::unknown(
				requestedId: (string)$negotiation->requestedId,
				acceptable: $this->negotiator->acceptableIdentifiers(),
			);
		}

		$version = $negotiation->version;
		if ($version !== null && $version->isServed() === false) {
			throw ApiVersionRefusedException::withdrawn(
				version: $version,
				acceptable: $this->negotiator->acceptableIdentifiers(),
			);
		}

	}//end beforeController()

	/**
	 * Turn a refusal into its response; re-throw anything else.
	 *
	 * @param mixed $controller The controller being dispatched.
	 * @param string $methodName The method being dispatched.
	 * @param Exception $exception The thrown exception.
	 *
	 * @return Response The refusal.
	 *
	 * @throws Exception The passed-in exception when it is not ours to handle.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) Fixed by the Middleware contract.
	 *
	 * @spec openspec/changes/api-as-a-versioned-surface/specs/openapi-generation/spec.md
	 */
	public function afterException($controller, $methodName, Exception $exception): Response {
		if ($exception instanceof ApiVersionRefusedException) {
			return $exception->toResponse();
		}

		throw $exception;

	}//end afterException()

	/**
	 * Name the answering contract, and its end date when it has one.
	 *
	 * @param mixed $controller The controller that ran.
	 * @param string $methodName The method that ran.
	 * @param Response $response The response to decorate.
	 *
	 * @return Response The decorated response.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) Fixed by the Middleware contract.
	 *
	 * @spec openspec/changes/api-as-a-versioned-surface/specs/openapi-generation/spec.md
	 */
	public function afterController($controller, $methodName, Response $response): Response {
		if ($this->isApiRequest() === false) {
			return $response;
		}

		$version = $this->negotiator->negotiate(request: $this->request)->version;
		if ($version === null) {
			return $response;
		}

		$response->addHeader(ApiVersionNegotiator::RESPONSE_HEADER, $version->id);

		if ($version->isDeprecated() === false) {
			return $response;
		}

		return $this->addDeprecationHeaders(response: $response, version: $version);

	}//end afterController()

	/**
	 * Add the RFC 8594 deprecation headers for a deprecated version.
	 *
	 * @param Response $response The response to decorate.
	 * @param ApiVersion $version The deprecated version that answered.
	 *
	 * @return Response The decorated response.
	 */
	private function addDeprecationHeaders(Response $response, ApiVersion $version): Response {
		$deprecatedOn = ApiVersion::toHttpDate(isoDate: $version->deprecatedOn);
		if ($deprecatedOn !== null) {
			$response->addHeader('Deprecation', $deprecatedOn);
		} else {
			$response->addHeader('Deprecation', 'true');
		}

		$sunset = ApiVersion::toHttpDate(isoDate: $version->sunset);
		if ($sunset !== null) {
			$response->addHeader('Sunset', $sunset);
		}

		if ($version->successor !== null) {
			$response->addHeader(
				'Link',
				'<' . self::CONTRACT_PATH . $version->successor . '/oas>; rel="successor-version"'
			);
		}

		return $response;

	}//end addDeprecationHeaders()

	/**
	 * Whether the current request is part of the API surface.
	 *
	 * @return bool True when the request path names an API route.
	 */
	private function isApiRequest(): bool {
		$path = $this->request->getPathInfo();
		if (is_string($path) === false || $path === '') {
			return false;
		}

		return str_contains($path, self::API_PATH_MARKER);

	}//end isApiRequest()
}//end class
