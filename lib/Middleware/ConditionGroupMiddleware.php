<?php

/**
 * Condition Group Middleware
 *
 * Maps a {@see ConditionGroupException} thrown anywhere in a request (a
 * `_where` condition tree that is malformed, too deep or too large, or names a
 * leaf the schema cannot answer) onto an HTTP 400 that carries the error and
 * the node path of the refused node, so a caller can point at the rule that
 * failed instead of getting a 500.
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
 * @spec openspec/changes/list-query-condition-groups/specs/zoeken-filteren/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Middleware;

use Exception;
use OCA\OpenRegister\Exception\ConditionGroupException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Middleware;

/**
 * Middleware translating a refused `_where` tree into a 400 response.
 *
 * @package OCA\OpenRegister\Middleware
 */
class ConditionGroupMiddleware extends Middleware {

	/**
	 * Map ConditionGroupException onto a 400 Bad Request with the node path.
	 *
	 * @param Controller $controller The controller that was executing.
	 * @param string     $methodName The controller method that was executing.
	 * @param Exception  $exception  The exception that was thrown.
	 *
	 * @return Response The 400 response: `{error, path}`.
	 *
	 * @throws Exception Rethrows every exception that is not a ConditionGroupException.
	 *
	 * @spec openspec/changes/list-query-condition-groups/specs/zoeken-filteren/spec.md
	 */
	public function afterException(Controller $controller, string $methodName, Exception $exception): Response {
		if ($exception instanceof ConditionGroupException === false) {
			throw $exception;
		}

		return new JSONResponse(data: $exception->toResponseBody(), statusCode: 400);
	}//end afterException()
}//end class
