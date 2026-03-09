<?php

/**
 * MCP Server Controller
 *
 * Handles the MCP (Model Context Protocol) standard JSON-RPC 2.0 endpoint
 * for the OpenRegister MCP server. Provides Streamable HTTP transport.
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
 */

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Service\Mcp\McpProtocolService;
use OCA\OpenRegister\Service\Mcp\McpResourcesService;
use OCA\OpenRegister\Service\Mcp\McpToolsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
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
class McpServerController extends Controller
{

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
     * McpServerController constructor
     *
     * @param string             $appName         Application name
     * @param IRequest           $request         Request object
     * @param McpProtocolService $protocolService MCP protocol service
     * @param McpToolsService    $toolsService    MCP tools service
     * @param McpResourcesService $resourcesService MCP resources service
     * @param LoggerInterface    $logger          Logger
     * @param string             $userId          Authenticated user ID
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly McpProtocolService $protocolService,
        private readonly McpToolsService $toolsService,
        private readonly McpResourcesService $resourcesService,
        private readonly LoggerInterface $logger,
        private readonly string $userId
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * Handle MCP JSON-RPC 2.0 request
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @CORS
     *
     * @return Response JSON-RPC response or HTTP 202 for notifications
     */
    public function handle(): Response
    {
        // Read and parse JSON body.
        $body = file_get_contents('php://input');
        $request = json_decode(json: $body, associative: true);

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
        $id     = $request['id'] ?? null;

        // Notifications (no id) — return 202 Accepted.
        if ($id === null) {
            return $this->handleNotification(method: $method);
        }

        // For initialize, no session required.
        if ($method === 'initialize') {
            return $this->handleInitialize(id: $id, params: $params);
        }

        // All other methods require a valid session.
        $sessionId = $this->request->getHeader('Mcp-Session-Id');
        if (empty($sessionId) === true) {
            return $this->jsonRpcError(
                id: $id,
                code: self::ERR_SESSION_REQUIRED,
                message: 'Mcp-Session-Id header required'
            );
        }

        $sessionUserId = $this->protocolService->validateSession(sessionId: $sessionId);
        if ($sessionUserId === null) {
            return $this->jsonRpcError(
                id: $id,
                code: self::ERR_SESSION_REQUIRED,
                message: 'Invalid or expired session'
            );
        }

        // Dispatch to method handler.
        return $this->dispatch(id: $id, method: $method, params: $params);
    }//end handle()

    /**
     * Handle a notification (no id, no response expected)
     *
     * @param string $method JSON-RPC method name
     *
     * @return Response HTTP 202 Accepted
     */
    private function handleNotification(string $method): Response
    {
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
     * @param mixed $id     JSON-RPC request ID
     * @param array $params Initialize parameters
     *
     * @return JSONResponse JSON-RPC response with session ID header
     */
    private function handleInitialize(mixed $id, array $params): JSONResponse
    {
        try {
            $result = $this->protocolService->initialize(
                params: $params,
                userId: $this->userId
            );

            $response = $this->jsonRpcSuccess(
                id: $id,
                result: $result['result']
            );
            $response->addHeader('Mcp-Session-Id', $result['sessionId']);

            return $response;
        } catch (\Exception $e) {
            $this->logger->error(
                message: '[MCP] Initialize failed',
                context: ['error' => $e->getMessage()]
            );

            return $this->jsonRpcError(
                id: $id,
                code: self::ERR_INTERNAL,
                message: 'Initialize failed: '.$e->getMessage()
            );
        }//end try
    }//end handleInitialize()

    /**
     * Dispatch a JSON-RPC method to the appropriate handler
     *
     * @param mixed  $id     JSON-RPC request ID
     * @param string $method Method name
     * @param array  $params Method parameters
     *
     * @return JSONResponse JSON-RPC response
     */
    private function dispatch(mixed $id, string $method, array $params): JSONResponse
    {
        try {
            $result = match ($method) {
                'ping'                     => $this->protocolService->ping(),
                'tools/list'               => $this->toolsService->listTools(),
                'tools/call'               => $this->handleToolCall(params: $params),
                'resources/list'           => $this->resourcesService->listResources(),
                'resources/read'           => $this->handleResourceRead(params: $params),
                'resources/templates/list' => $this->resourcesService->listTemplates(),
                default                    => throw new \BadMethodCallException(
                    message: 'Method not found: '.$method
                ),
            };

            return $this->jsonRpcSuccess(id: $id, result: $result);
        } catch (\BadMethodCallException $e) {
            return $this->jsonRpcError(
                id: $id,
                code: self::ERR_METHOD_NOT_FOUND,
                message: $e->getMessage()
            );
        } catch (\InvalidArgumentException $e) {
            return $this->jsonRpcError(
                id: $id,
                code: self::ERR_INVALID_PARAMS,
                message: $e->getMessage()
            );
        } catch (\Exception $e) {
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
     *
     * @return array Tool execution result
     *
     * @throws \InvalidArgumentException If name is missing
     */
    private function handleToolCall(array $params): array
    {
        if (isset($params['name']) === false) {
            throw new \InvalidArgumentException(
                message: 'Missing required parameter: name'
            );
        }

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
     * @throws \InvalidArgumentException If uri is missing
     */
    private function handleResourceRead(array $params): array
    {
        if (isset($params['uri']) === false) {
            throw new \InvalidArgumentException(
                message: 'Missing required parameter: uri'
            );
        }

        return $this->resourcesService->readResource(uri: $params['uri']);
    }//end handleResourceRead()

    /**
     * Build a JSON-RPC 2.0 success response
     *
     * @param mixed $id     Request ID
     * @param mixed $result Result data
     *
     * @return JSONResponse JSON-RPC response
     */
    private function jsonRpcSuccess(mixed $id, mixed $result): JSONResponse
    {
        return new JSONResponse(
            data: [
                'jsonrpc' => '2.0',
                'id'      => $id,
                'result'  => $result,
            ],
            statusCode: Http::STATUS_OK
        );
    }//end jsonRpcSuccess()

    /**
     * Build a JSON-RPC 2.0 error response
     *
     * @param mixed  $id      Request ID (null for parse errors)
     * @param int    $code    JSON-RPC error code
     * @param string $message Error message
     *
     * @return JSONResponse JSON-RPC error response
     */
    private function jsonRpcError(mixed $id, int $code, string $message): JSONResponse
    {
        return new JSONResponse(
            data: [
                'jsonrpc' => '2.0',
                'id'      => $id,
                'error'   => [
                    'code'    => $code,
                    'message' => $message,
                ],
            ],
            statusCode: Http::STATUS_OK
        );
    }//end jsonRpcError()
}//end class
