<?php

/**
 * SaveWarningsMiddleware: a successful save's `warn` validations on its response.
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
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Middleware;

use OCA\OpenRegister\Kernel\Contracts\SaveWarningsInterface;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Middleware;

/**
 * A `warn` validation saves and returns its message (task 4.3b). The save
 * events carry errors only, so the enforcer puts each warning in the
 * request's {@see SaveWarningsInterface} and this middleware hands them to
 * the response, whichever path saved: create, update, patch, import or bulk.
 *
 * - Every successful response that saved with warnings carries
 *   `OpenRegister-Warnings: <JSON list>`.
 * - A single-object response also carries them as `@self.warnings`.
 *
 * A request that raised no warning answers exactly as before. A refused
 * save carries its warnings in the 422 body already, so a failed response
 * only clears the collector.
 *
 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/object-lifecycle/spec.md
 */
class SaveWarningsMiddleware extends Middleware {

	/**
	 * The header.
	 */
	public const HEADER = 'OpenRegister-Warnings';

	/**
	 * Constructor.
	 *
	 * @param SaveWarningsInterface $warnings The request's save warnings.
	 *
	 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		private readonly SaveWarningsInterface $warnings,
	) {
	}//end __construct()

	/**
	 * Put the request's save warnings on a successful response.
	 *
	 * @param mixed    $controller The controller.
	 * @param string   $methodName The action.
	 * @param Response $response   The response.
	 *
	 * @return Response The response.
	 *
	 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/object-lifecycle/spec.md
	 */
	public function afterController($controller, $methodName, Response $response): Response {
		$warnings = $this->warnings->drain();
		if ($warnings === [] || $response->getStatus() >= 300) {
			return $response;
		}

		$header = json_encode($warnings, JSON_UNESCAPED_SLASHES);
		if (is_string($header) === true) {
			$response->addHeader(self::HEADER, $header);
		}

		if ($response instanceof JSONResponse) {
			$this->intoBody(response: $response, warnings: $warnings);
		}

		return $response;
	}//end afterController()

	/**
	 * Add the warnings to a single-object body as `@self.warnings`.
	 *
	 * @param JSONResponse                     $response The response.
	 * @param array<int, array<string, mixed>> $warnings The warnings.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/object-lifecycle/spec.md
	 */
	private function intoBody(JSONResponse $response, array $warnings): void {
		$data = $response->getData();
		if (is_array($data) === false || is_array($data['@self'] ?? null) === false) {
			return;
		}

		$data['@self']['warnings'] = $warnings;
		$response->setData($data);
	}//end intoBody()
}//end class
