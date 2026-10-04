<?php

/**
 * MCP Server Controller
 *
 * Handles the MCP (Model Context Protocol) standard JSON-RPC 2.0 endpoint
 * for the OpenRegister MCP server. Provides Streamable HTTP transport.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/mcp-discovery/spec.md
 */

namespace OCA\OpenRegister\Controller;

use BadMethodCallException;
use Exception;
use InvalidArgumentException;
use OCA\OpenRegister\Service\Mcp\McpAgentScope;
use OCA\OpenRegister\Service\Mcp\McpProtocolService;
use OCA\OpenRegister\Service\Mcp\McpResourcesService;
use OCA\OpenRegister\Service\Mcp\McpToolsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * McpServerController handles the MCP standard JSON-RPC 2.0 endpoint
 *
 * Single POST endpoint that dispatches JSON-RPC requests to the
 * appropriate MCP service (protocol, tools, resources).
 *
 * @psalm-suppress UnusedClass - Registered via routes.php
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class McpServerController extends Controller {

	/**
	 * JSON-RPC error: Parse error
	 *
	 * @var int
	 */
	private const ERR_PARSE = -32700;

	/**
	 * JSON-RPC error: Invalid request
	 *
	 * @var int
	 */
	private const ERR_INVALID_REQUEST = -32600;

	/**
	 * JSON-RPC error: Method not found
	 *
	 * @var int
	 */
	private const ERR_METHOD_NOT_FOUND = -32601;

	/**
	 * JSON-RPC error: Invalid params
	 *
	 * @var int
	 */
	private const ERR_INVALID_PARAMS = -32602;

	/**
	 * JSON-RPC error: Internal error
	 *
	 * @var int
	 */
	private const ERR_INTERNAL = -32603;

	/**
	 * JSON-RPC error: Session required
	 *
	 * @var int
	 */
	private const ERR_SESSION_REQUIRED = -32000;

	/**
	 * JSON-RPC error: Authentication required (server-defined range)
	 *
	 * @var int
	 */
	private const ERR_UNAUTHORIZED = -32001;

	/**
	 * McpServerController constructor
	 *
	 * @param string $appName Application name
	 * @param IRequest $request Request object
	 * @param McpProtocolService $protocolService MCP protocol service
	 * @param McpToolsService $toolsService MCP tools service
	 * @param McpResourcesService $resourcesService MCP resources service
	 * @param LoggerInterface $logger Logger
	 * @param IUserSession $userSession Read per request, never at construction (#4284)
	 * @param McpAgentScope $agentScope Holds a session that declared an agent to its grant
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly McpProtocolService $protocolService,
		private readonly McpToolsService $toolsService,
		private readonly McpResourcesService $resourcesService,
		private readonly LoggerInterface $logger,
		private readonly IUserSession $userSession,
		private readonly McpAgentScope $agentScope,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Handle MCP JSON-RPC 2.0 request
	 *
	 * The caller is read from the user session here, not injected as a
	 * constructor `$userId`. Nextcloud 34 builds controllers as lazy ghosts, and
	 * the CORS middleware logs the caller out and back in with their basic-auth
	 * credentials before this runs. Anything that touches the ghost in that
	 * window (a debug-level log that serialises the stack, for one) ran the
	 * constructor while no one was logged in, so a `string $userId` threw a
	 * TypeError that reached the client as an empty 200 (#4284).
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @CORS
	 *
	 * @return Response JSON-RPC response or HTTP 202 for notifications
	 *
	 * @spec openspec/specs/mcp-discovery/spec.md
	 */
	public function handle(): Response {
		// Read and parse JSON body.
		$body = file_get_contents('php://input');
		$request = json_decode(json: $body, associative: true);

		// Refuse a caller Nextcloud did not authenticate before anything else
		// runs, with a 401 an MCP client can act on.
		$userId = $this->currentUserId();
		if ($userId === null) {
			return $this->unauthorized(request: $request);
		}

		if ($request === null) {
			return $this->jsonRpcError(
				id: null,
				code: self::ERR_PARSE,
				message: 'Parse error: invalid JSON'
			);
		}

		// Validate JSON-RPC envelope.
		if (isset($request['jsonrpc']) === false || $request['jsonrpc'] !== '2.0'
			|| isset($request['method']) === false
		) {
			return $this->jsonRpcError(
				id: $request['id'] ?? null,
				code: self::ERR_INVALID_REQUEST,
				message: 'Invalid JSON-RPC 2.0 request'
			);
		}

		$method = $request['method'];
		$params = $request['params'] ?? [];
		$id = $request['id'] ?? null;

		// Notifications (no id) — return 202 Accepted.
		if ($id === null) {
			return $this->handleNotification(method: $method);
		}

		// For initialize, no session required.
		if ($method === 'initialize') {
			return $this->handleInitialize(id: $id, params: $params, userId: $userId);
		}

		// All other methods require a valid session.
		$sessionId = $this->request->getHeader('Mcp-Session-Id');
		$sessionError = $this->sessionError(id: $id, sessionId: $sessionId);
		if ($sessionError !== null) {
			return $sessionError;
		}

		// Dispatch to method handler.
		return $this->dispatch(id: $id, method: $method, params: $params, sessionId: $sessionId, userId: $userId);
	}//end handle()

	/**
	 * The uid of the caller Nextcloud authenticated for this request
	 *
	 * @return string|null The uid, or null when no one is logged in
	 */
	private function currentUserId(): ?string {
		$userId = $this->userSession->getUser()?->getUID();
		if ($userId === null || $userId === '') {
			return null;
		}

		return $userId;
	}//end currentUserId()

	/**
	 * The JSON-RPC error for a missing or unknown MCP session, if any
	 *
	 * @param mixed $id JSON-RPC request ID
	 * @param string $sessionId The Mcp-Session-Id header value
	 *
	 * @return JSONResponse|null The error to answer with, or null when the session is valid
	 */
	private function sessionError(mixed $id, string $sessionId): ?JSONResponse {
		if ($sessionId === '') {
			return $this->jsonRpcError(
				id: $id,
				code: self::ERR_SESSION_REQUIRED,
				message: 'Mcp-Session-Id header required'
			);
		}

		if ($this->protocolService->validateSession(sessionId: $sessionId) === null) {
			return $this->jsonRpcError(
				id: $id,
				code: self::ERR_SESSION_REQUIRED,
				message: 'Invalid or expired session'
			);
		}

		return null;
	}//end sessionError()

	/**
	 * Build the 401 answer for a request without an authenticated user
	 *
	 * The body is a JSON-RPC error so an MCP client can read it, and the
	 * `WWW-Authenticate` header tells it which credentials to send.
	 *
	 * @param mixed $request The decoded request body, or null when it did not parse
	 *
	 * @return JSONResponse HTTP 401 with a JSON-RPC error
	 */
	private function unauthorized(mixed $request): JSONResponse {
		$id = null;
		if (is_array($request) === true && isset($request['id']) === true
			&& (is_string($request['id']) === true || is_int($request['id']) === true)
		) {
			$id = $request['id'];
		}

		$response = $this->jsonRpcError(
			id: $id,
			code: self::ERR_UNAUTHORIZED,
			message: 'Authentication required: send Nextcloud credentials (an app password over basic auth, or a bearer token)'
		);
		$response->setStatus(Http::STATUS_UNAUTHORIZED);
		$response->addHeader('WWW-Authenticate', 'Basic realm="Nextcloud", charset="UTF-8"');

		return $response;
	}//end unauthorized()

	/**
	 * Handle a notification (no id, no response expected)
	 *
	 * @param string $method JSON-RPC method name
	 *
	 * @return Response HTTP 202 Accepted
	 */
	private function handleNotification(string $method): Response {
		$this->logger->debug(
			message: '[MCP] Notification received',
			context: ['method' => $method]
		);

		$response = new Response();
		$response->setStatus(Http::STATUS_ACCEPTED);
		return $response;
	}//end handleNotification()

	/**
	 * Handle MCP initialize request
	 *
	 * @param mixed $id JSON-RPC request ID
	 * @param array $params Initialize parameters
	 * @param string $userId The authenticated caller
	 *
	 * @return JSONResponse JSON-RPC response with session ID header
	 */
	private function handleInitialize(mixed $id, array $params, string $userId): JSONResponse {
		// An agent the client declares is checked BEFORE a session exists, so a
		// refused agent never yields a session with the user's full rights.
		// A client names it as the `agent` initialize parameter or, for clients
		// that can only add headers, as `X-OpenRegister-Agent`.
		$agentUuid = $params['agent'] ?? null;
		if ($agentUuid === null && $this->request->getHeader('X-OpenRegister-Agent') !== '') {
			$agentUuid = $this->request->getHeader('X-OpenRegister-Agent');
		}
		if ($agentUuid !== null) {
			try {
				if (is_string($agentUuid) === false || $agentUuid === '') {
					throw new InvalidArgumentException(message: 'The agent parameter must be an agent uuid');
				}

				$this->agentScope->assertUsable(agentUuid: $agentUuid, userId: $userId);
			} catch (InvalidArgumentException $e) {
				return $this->jsonRpcError(id: $id, code: self::ERR_INVALID_PARAMS, message: $e->getMessage());
			}
		}

		try {
			$result = $this->protocolService->initialize(
				params: $params,
				userId: $userId
			);

			$response = $this->jsonRpcSuccess(
				id: $id,
				result: $result['result']
			);
			$response->addHeader('Mcp-Session-Id', $result['sessionId']);
			if (is_string($agentUuid) === true) {
				$this->agentScope->bind(sessionId: $result['sessionId'], agentUuid: $agentUuid);
			}

			return $response;
		} catch (\Exception $e) {
			$this->logger->error(
				message: '[MCP] Initialize failed',
				context: ['error' => $e->getMessage()]
			);

			return $this->jsonRpcError(
				id: $id,
				code: self::ERR_INTERNAL,
				message: 'Initialize failed: ' . $e->getMessage()
			);
		}//end try
	}//end handleInitialize()

	/**
	 * Dispatch a JSON-RPC method to the appropriate handler
	 *
	 * @param mixed $id JSON-RPC request ID
	 * @param string $method Method name
	 * @param array $params Method parameters
	 * @param string $sessionId The validated MCP session
	 * @param string $userId The authenticated caller
	 *
	 * @return JSONResponse JSON-RPC response
	 */
	private function dispatch(mixed $id, string $method, array $params, string $sessionId, string $userId): JSONResponse {
		try {
			$result = match ($method) {
				'ping' => $this->protocolService->ping(),
				'tools/list' => $this->agentScope->filterListing(
					sessionId: $sessionId,
					userId: $userId,
					listing: $this->toolsService->listTools()
				),
				'tools/call' => $this->handleToolCall(params: $params, sessionId: $sessionId, userId: $userId),
				'resources/list' => $this->resourcesService->listResources(),
				'resources/read' => $this->handleResourceRead(params: $params),
				'resources/templates/list' => $this->resourcesService->listTemplates(),
				default => throw new BadMethodCallException(
					message: 'Method not found: ' . $method
				),
			};

			return $this->jsonRpcSuccess(id: $id, result: $result);
		} catch (BadMethodCallException $e) {
			return $this->jsonRpcError(
				id: $id,
				code: self::ERR_METHOD_NOT_FOUND,
				message: $e->getMessage()
			);
		} catch (InvalidArgumentException $e) {
			return $this->jsonRpcError(
				id: $id,
				code: self::ERR_INVALID_PARAMS,
				message: $e->getMessage()
			);
		} catch (Exception $e) {
			$this->logger->error(
				message: '[MCP] Method dispatch failed',
				context: ['method' => $method, 'error' => $e->getMessage()]
			);

			return $this->jsonRpcError(
				id: $id,
				code: self::ERR_INTERNAL,
				message: $e->getMessage()
			);
		}//end try
	}//end dispatch()

	/**
	 * Handle tools/call request
	 *
	 * @param array $params Must contain name and arguments
	 * @param string $sessionId The validated MCP session
	 * @param string $userId The authenticated caller
	 *
	 * @return array Tool execution result
	 *
	 * @throws InvalidArgumentException If name is missing, or the session agent's grant does not cover the call
	 */
	private function handleToolCall(array $params, string $sessionId, string $userId): array {
		if (isset($params['name']) === false) {
			throw new InvalidArgumentException(
				message: 'Missing required parameter: name'
			);
		}

		$this->agentScope->assertMayCall(
			sessionId: $sessionId,
			userId: $userId,
			name: (string) $params['name'],
			arguments: (array) ($params['arguments'] ?? [])
		);

		return $this->toolsService->callTool(
			name: $params['name'],
			arguments: $params['arguments'] ?? []
		);
	}//end handleToolCall()

	/**
	 * Handle resources/read request
	 *
	 * @param array $params Must contain uri
	 *
	 * @return array Resource read result
	 *
	 * @throws InvalidArgumentException If uri is missing
	 */
	private function handleResourceRead(array $params): array {
		if (isset($params['uri']) === false) {
			throw new InvalidArgumentException(
				message: 'Missing required parameter: uri'
			);
		}

		return $this->resourcesService->readResource(uri: $params['uri']);
	}//end handleResourceRead()

	/**
	 * Build a JSON-RPC 2.0 success response
	 *
	 * @param mixed $id Request ID
	 * @param mixed $result Result data
	 *
	 * @return JSONResponse JSON-RPC response
	 */
	private function jsonRpcSuccess(mixed $id, mixed $result): JSONResponse {
		return new JSONResponse(
			data: [
				'jsonrpc' => '2.0',
				'id' => $id,
				'result' => $result,
			],
			statusCode: Http::STATUS_OK
		);
	}//end jsonRpcSuccess()

	/**
	 * Build a JSON-RPC 2.0 error response
	 *
	 * @param mixed $id Request ID (null for parse errors)
	 * @param int $code JSON-RPC error code
	 * @param string $message Error message
	 *
	 * @return JSONResponse JSON-RPC error response
	 */
	private function jsonRpcError(mixed $id, int $code, string $message): JSONResponse {
		return new JSONResponse(
			data: [
				'jsonrpc' => '2.0',
				'id' => $id,
				'error' => [
					'code' => $code,
					'message' => $message,
				],
			],
			statusCode: Http::STATUS_OK
		);
	}//end jsonRpcError()
}//end class
