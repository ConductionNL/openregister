<?php

/**
 * Maps a refused related-row filter onto an HTTP 400.
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
 * @spec openspec/changes/query-referenced-schema-rows/specs/zoeken-filteren/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Middleware;

use Exception;
use OCA\OpenRegister\Exception\ExplicitRevealQueryException;
use OCA\OpenRegister\Exception\RelatedRowFilterNotSupportedException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Middleware;

/**
 * A `_related`/`_referenced` filter on a multi-schema facet (or search) route is
 * refused, not dropped. This answers that refusal with a 400 and the exception's
 * message instead of the opaque 500 an uncaught exception produces.
 *
 * @spec openspec/changes/query-referenced-schema-rows/specs/zoeken-filteren/spec.md
 */
class RelatedRowFilterMiddleware extends Middleware {

	/**
	 * Map RelatedRowFilterNotSupportedException and ExplicitRevealQueryException onto a 400 response.
	 *
	 * @param Controller $controller The controller that was executing.
	 * @param string     $methodName The controller method that was executing.
	 * @param Exception  $exception  The exception that was thrown.
	 *
	 * @return Response The 400 response.
	 *
	 * @throws Exception Rethrows every other exception.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is the Middleware contract.
	 *
	 * @spec openspec/changes/query-referenced-schema-rows/specs/zoeken-filteren/spec.md
	 */
	public function afterException(Controller $controller, string $methodName, Exception $exception): Response {
		if ($exception instanceof RelatedRowFilterNotSupportedException === false
			&& $exception instanceof ExplicitRevealQueryException === false
		) {
			throw $exception;
		}

		return new JSONResponse(data: ['error' => $exception->getMessage()], statusCode: 400);
	}//end afterException()
}//end class
