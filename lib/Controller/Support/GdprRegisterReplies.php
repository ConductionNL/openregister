<?php

/**
 * The refusal envelopes the AVG register endpoints answer with.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/verwerkingsregister-api/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Support;

use Closure;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use Throwable;

/**
 * 401, 403, 404 and 422 envelopes, and a write that answers 422 when it fails.
 *
 * @spec openspec/specs/verwerkingsregister-api/spec.md
 */
final class GdprRegisterReplies {
	/**
	 * The unauthenticated response.
	 *
	 * @return JSONResponse HTTP 401.
	 *
	 * @spec openspec/specs/verwerkingsregister-api/spec.md
	 */
	public function unauthorized(): JSONResponse {
		return new JSONResponse(
			data: ['error' => 'Authentication required'],
			statusCode: Http::STATUS_UNAUTHORIZED
		);
	}//end unauthorized()

	/**
	 * A refusal for a caller without the required privileges.
	 *
	 * @param string $message Why the caller is refused.
	 *
	 * @return JSONResponse HTTP 403.
	 *
	 * @spec openspec/specs/verwerkingsregister-api/spec.md
	 */
	public function forbidden(string $message): JSONResponse {
		return new JSONResponse(
			data: ['error' => $message],
			statusCode: Http::STATUS_FORBIDDEN
		);
	}//end forbidden()

	/**
	 * A path identifier that names nothing the caller may see.
	 *
	 * @param string $error      The error text.
	 * @param string $identifier The identifier as given.
	 *
	 * @return JSONResponse HTTP 404.
	 *
	 * @spec openspec/specs/verwerkingsregister-api/spec.md
	 */
	public function notFound(string $error, string $identifier): JSONResponse {
		return new JSONResponse(
			data: ['error' => $error, 'identifier' => $identifier],
			statusCode: Http::STATUS_NOT_FOUND
		);
	}//end notFound()

	/**
	 * A request that cannot be processed as given.
	 *
	 * @param string $message The error text.
	 *
	 * @return JSONResponse HTTP 422.
	 *
	 * @spec openspec/specs/verwerkingsregister-api/spec.md
	 */
	public function unprocessable(string $message): JSONResponse {
		return new JSONResponse(
			data: ['error' => $message],
			statusCode: Http::STATUS_UNPROCESSABLE_ENTITY
		);
	}//end unprocessable()

	/**
	 * Run a write, answering 422 with its message when it throws the given kind.
	 *
	 * Any other throwable is rethrown untouched.
	 *
	 * @param Closure $write The write; its result is returned.
	 * @param string  $catch The throwable class answered with 422.
	 *
	 * @return mixed The write's result, or the 422 response.
	 *
	 * @throws Throwable When the write throws something other than $catch.
	 *
	 * @spec openspec/specs/verwerkingsregister-api/spec.md
	 */
	public function attempt(Closure $write, string $catch): mixed {
		try {
			return $write();
		} catch (Throwable $e) {
			if (($e instanceof $catch) === false) {
				throw $e;
			}

			return $this->unprocessable(message: $e->getMessage());
		}
	}//end attempt()
}//end class
