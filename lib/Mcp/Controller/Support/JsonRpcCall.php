<?php

/**
 * One JSON-RPC 2.0 call to the MCP server, with the caller who made it.
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

/**
 * The id, method and params of a JSON-RPC request that carries an id, and the authenticated caller.
 *
 * @spec openspec/specs/mcp-discovery/spec.md
 */
final class JsonRpcCall {

	/**
	 * The JSON-RPC request id.
	 *
	 * @var mixed
	 */
	public readonly mixed $id;

	/**
	 * The JSON-RPC method name.
	 *
	 * @var string
	 */
	public readonly string $method;

	/**
	 * The method parameters, empty when the request sent none.
	 *
	 * @var array<mixed>
	 */
	public readonly array $params;

	/**
	 * The uid of the authenticated caller.
	 *
	 * @var string
	 */
	public readonly string $userId;

	/**
	 * Read the call from a decoded request envelope.
	 *
	 * @param array<mixed> $request The decoded JSON-RPC envelope, already known to carry `jsonrpc` and `method`.
	 * @param string       $userId  The uid of the authenticated caller.
	 */
	public function __construct(array $request, string $userId) {
		$this->id     = ($request['id'] ?? null);
		$this->method = $request['method'];
		$this->params = ($request['params'] ?? []);
		$this->userId = $userId;
	}//end __construct()
}//end class
