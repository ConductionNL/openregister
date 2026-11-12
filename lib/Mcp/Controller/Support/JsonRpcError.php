<?php

/**
 * One JSON-RPC 2.0 error object: the code and message the MCP server answers with.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/mcp-discovery/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Mcp\Controller\Support;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;

/**
 * The `error` member of a JSON-RPC 2.0 response.
 *
 * @spec openspec/specs/mcp-discovery/spec.md
 */
final class JsonRpcError {

	/**
	 * Build the error.
	 *
	 * @param int    $code    The JSON-RPC error code.
	 * @param string $message The error message.
	 */
	public function __construct(
		public readonly int $code,
		public readonly string $message,
	) {
	}//end __construct()

	/**
	 * The JSON-RPC 2.0 response that answers the request with this error.
	 *
	 * @param mixed $id The request id, null for parse errors.
	 *
	 * @return JSONResponse The error response, HTTP 200.
	 */
	public function responseFor(mixed $id): JSONResponse {
		return new JSONResponse(
			data: [
				'jsonrpc' => '2.0',
				'id' => $id,
				'error' => [
					'code' => $this->code,
					'message' => $this->message,
				],
			],
			statusCode: Http::STATUS_OK
		);
	}//end responseFor()
}//end class
