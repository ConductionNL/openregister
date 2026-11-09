<?php

/**
 * Golden pins for McpServerController::handle(): every JSON-RPC route and refusal.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Mcp\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Mcp\Controller;

use BadMethodCallException;
use InvalidArgumentException;
use OCA\OpenRegister\Mcp\Controller\McpServerController;
use OCA\OpenRegister\Mcp\Controller\Support\McpServerControllerServices;
use OCA\OpenRegister\Mcp\Controller\Support\McpServerDomain;
use OCA\OpenRegister\Mcp\Controller\Support\McpServerPlatform;
use OCA\OpenRegister\Mcp\Service\Mcp\McpProtocolService;
use OCA\OpenRegister\Mcp\Service\Mcp\McpResourcesService;
use OCA\OpenRegister\Mcp\Service\Mcp\McpToolsService;
use OCA\OpenRegister\Service\Mcp\McpAgentScope;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins status, headers, body and every collaborator call for each request shape.
 */
class McpServerControllerJsonRpcGoldenTest extends TestCase {

	/**
	 * Collaborator calls, in order.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $calls = [];

	/**
	 * Restore the php:// stream wrapper.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		if (in_array('php', stream_get_wrappers(), true) === false || McpServerControllerGoldenInput::$body !== '') {
			@stream_wrapper_restore('php');
		}

		McpServerControllerGoldenInput::$body = '';
		parent::tearDown();
	}//end tearDown()

	/**
	 * The controller answers each request exactly as pinned.
	 *
	 * @param array<string, mixed> $scenario The request and how collaborators answer.
	 * @param array<string, mixed> $expected The pinned transcript.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testHandleMatchesTheGolden(array $scenario, array $expected): void {
		self::assertSame($expected, $this->transcript(scenario: $scenario));
	}//end testHandleMatchesTheGolden()

	/**
	 * Run one scenario and record what came back and what was called.
	 *
	 * @param array<string, mixed> $scenario The request and how collaborators answer.
	 *
	 * @return array<string, mixed>
	 */
	protected function transcript(array $scenario): array {
		$this->calls = [];
		McpServerControllerGoldenInput::$body = (string)$scenario['body'];
		stream_wrapper_unregister('php');
		stream_wrapper_register('php', McpServerControllerGoldenInput::class);
		try {
			$response = $this->controller(scenario: $scenario)->handle();
		} finally {
			stream_wrapper_restore('php');
		}

		$data = null;
		if ($response instanceof JSONResponse) {
			$data = $response->getData();
		}

		$headers = $response->getHeaders();
		return [
			'status' => $response->getStatus(),
			'session' => ($headers['Mcp-Session-Id'] ?? null),
			'authenticate' => ($headers['WWW-Authenticate'] ?? null),
			'data' => $data,
			'calls' => $this->calls,
		];
	}//end transcript()

	/**
	 * The controller with recording collaborators.
	 *
	 * @param array<string, mixed> $scenario How collaborators answer.
	 *
	 * @return McpServerController
	 */
	private function controller(array $scenario): McpServerController {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(
			fn (string $name): string => (string)($scenario['headers'][$name] ?? '')
		);

		$services = new McpServerControllerServices(
			domain: new McpServerDomain(
				protocolService: $this->protocol(scenario: $scenario),
				toolsService: $this->tools(scenario: $scenario),
				resourcesService: $this->resources(),
				agentScope: $this->agentScope(scenario: $scenario),
			),
			platform: new McpServerPlatform(logger: $this->logger(), userSession: $this->session(scenario: $scenario)),
		);

		return new McpServerController(appName: 'openregister', request: $request, services: $services);
	}//end controller()

	/**
	 * A protocol service that records its calls.
	 *
	 * @param array<string, mixed> $scenario How it answers.
	 *
	 * @return McpProtocolService
	 */
	private function protocol(array $scenario): McpProtocolService {
		$protocol = $this->createMock(McpProtocolService::class);
		$protocol->method('ping')->willReturnCallback(function (): array {
			$this->calls[] = ['ping'];
			return [];
		});
		$protocol->method('validateSession')->willReturnCallback(function (string $sessionId) use ($scenario): ?string {
			$this->calls[] = ['validateSession', $sessionId];
			if (($scenario['sessionValid'] ?? true) === true) {
				return 'admin';
			}

			return null;
		});
		$protocol->method('initialize')->willReturnCallback(function (array $params, string $userId) use ($scenario): array {
			$this->calls[] = ['initialize', $params, $userId];
			if (($scenario['initializeFails'] ?? false) === true) {
				throw new RuntimeException('store down');
			}

			return ['result' => ['protocolVersion' => '2025-03-26'], 'sessionId' => 'sess-new'];
		});

		return $protocol;
	}//end protocol()

	/**
	 * A tools service that records its calls.
	 *
	 * @param array<string, mixed> $scenario How it answers.
	 *
	 * @return McpToolsService
	 */
	private function tools(array $scenario): McpToolsService {
		$tools = $this->createMock(McpToolsService::class);
		$tools->method('listTools')->willReturnCallback(function (): array {
			$this->calls[] = ['listTools'];
			return ['tools' => [['name' => 'a'], ['name' => 'b']]];
		});
		$tools->method('callTool')->willReturnCallback(function (string $name, array $arguments) use ($scenario): array {
			$this->calls[] = ['callTool', $name, $arguments];
			return match ($scenario['tool'] ?? 'ok') {
				'invalid' => throw new InvalidArgumentException('bad arguments'),
				'unknown' => throw new BadMethodCallException('no such tool'),
				'boom' => throw new RuntimeException('tool crashed'),
				default => ['content' => [['type' => 'text', 'text' => 'done']]],
			};
		});

		return $tools;
	}//end tools()

	/**
	 * A resources service that records its calls.
	 *
	 * @return McpResourcesService
	 */
	private function resources(): McpResourcesService {
		$resources = $this->createMock(McpResourcesService::class);
		$resources->method('listResources')->willReturnCallback(function (): array {
			$this->calls[] = ['listResources'];
			return ['resources' => []];
		});
		$resources->method('listTemplates')->willReturnCallback(function (): array {
			$this->calls[] = ['listTemplates'];
			return ['resourceTemplates' => []];
		});
		$resources->method('readResource')->willReturnCallback(function (string $uri): array {
			$this->calls[] = ['readResource', $uri];
			return ['contents' => [['uri' => $uri]]];
		});

		return $resources;
	}//end resources()

	/**
	 * An agent scope that records its calls.
	 *
	 * @param array<string, mixed> $scenario How it answers.
	 *
	 * @return McpAgentScope
	 */
	private function agentScope(array $scenario): McpAgentScope {
		$scope = $this->createMock(McpAgentScope::class);
		$scope->method('assertUsable')->willReturnCallback(function (string $agentUuid, string $userId) use ($scenario): void {
			$this->calls[] = ['assertUsable', $agentUuid, $userId];
			if (($scenario['agentRefused'] ?? false) === true) {
				throw new InvalidArgumentException('agent not yours');
			}
		});
		$scope->method('bind')->willReturnCallback(function (string $sessionId, string $agentUuid): void {
			$this->calls[] = ['bind', $sessionId, $agentUuid];
		});
		$scope->method('filterListing')->willReturnCallback(function (string $sessionId, string $userId, array $listing): array {
			$this->calls[] = ['filterListing', $sessionId, $userId, $listing];
			return ['tools' => [$listing['tools'][0]]];
		});
		$scope->method('assertMayCall')->willReturnCallback(
			function (string $sessionId, string $userId, string $name, array $arguments) use ($scenario): void {
				$this->calls[] = ['assertMayCall', $sessionId, $userId, $name, $arguments];
				if (($scenario['callRefused'] ?? false) === true) {
					throw new InvalidArgumentException('grant does not cover it');
				}
			}
		);

		return $scope;
	}//end agentScope()

	/**
	 * A logger that records errors and debug lines.
	 *
	 * @return LoggerInterface
	 */
	private function logger(): LoggerInterface {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(function (string $message, array $context = []): void {
			$this->calls[] = ['log.error', $message, $context];
		});
		$logger->method('debug')->willReturnCallback(function (string $message, array $context = []): void {
			$this->calls[] = ['log.debug', $message, $context];
		});

		return $logger;
	}//end logger()

	/**
	 * A user session for the scenario's user, or nobody.
	 *
	 * @param array<string, mixed> $scenario Names the user.
	 *
	 * @return IUserSession
	 */
	private function session(array $scenario): IUserSession {
		$uid = 'admin';
		if (array_key_exists('user', $scenario) === true) {
			$uid = $scenario['user'];
		}

		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn((string)$uid);
		}

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		return $session;
	}//end session()

	/**
	 * The request shapes, without their pins.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function scenarios(): array {
		$session = ['Mcp-Session-Id' => 'sess-1'];
		return [
			'nobody logged in' => ['user' => null, 'body' => '{"jsonrpc":"2.0","id":7,"method":"ping"}'],
			'nobody logged in, unparseable' => ['user' => null, 'body' => 'nope'],
			'parse error' => ['body' => '{'],
			'empty body' => ['body' => ''],
			'wrong version' => ['body' => '{"jsonrpc":"1.0","id":3,"method":"ping"}'],
			'no method' => ['body' => '{"jsonrpc":"2.0","id":"x"}'],
			'notification' => ['body' => '{"jsonrpc":"2.0","method":"notifications/initialized"}'],
			'initialize' => ['body' => '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"clientInfo":{"name":"c"}}}'],
			'initialize with agent param' => ['body' => '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"agent":"ag-1"}}'],
			'initialize with agent header' => [
				'body' => '{"jsonrpc":"2.0","id":1,"method":"initialize"}',
				'headers' => ['X-OpenRegister-Agent' => 'ag-2'],
			],
			'initialize with non-string agent' => ['body' => '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"agent":5}}'],
			'initialize with empty agent' => ['body' => '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"agent":""}}'],
			'initialize with refused agent' => [
				'body' => '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"agent":"ag-1"}}',
				'agentRefused' => true,
			],
			'initialize fails' => [
				'body' => '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"agent":"ag-1"}}',
				'initializeFails' => true,
			],
			'no session header' => ['body' => '{"jsonrpc":"2.0","id":2,"method":"ping"}'],
			'expired session' => ['body' => '{"jsonrpc":"2.0","id":2,"method":"ping"}', 'headers' => $session, 'sessionValid' => false],
			'ping' => ['body' => '{"jsonrpc":"2.0","id":2,"method":"ping"}', 'headers' => $session],
			'tools/list' => ['body' => '{"jsonrpc":"2.0","id":"t","method":"tools/list"}', 'headers' => $session],
			'tools/call' => [
				'body' => '{"jsonrpc":"2.0","id":4,"method":"tools/call","params":{"name":"x","arguments":{"a":1}}}',
				'headers' => $session,
			],
			'tools/call without arguments' => ['body' => '{"jsonrpc":"2.0","id":4,"method":"tools/call","params":{"name":"x"}}', 'headers' => $session],
			'tools/call without name' => ['body' => '{"jsonrpc":"2.0","id":4,"method":"tools/call","params":{}}', 'headers' => $session],
			'tools/call refused by grant' => [
				'body' => '{"jsonrpc":"2.0","id":4,"method":"tools/call","params":{"name":"x"}}',
				'headers' => $session,
				'callRefused' => true,
			],
			'tools/call invalid' => [
				'body' => '{"jsonrpc":"2.0","id":4,"method":"tools/call","params":{"name":"x"}}',
				'headers' => $session,
				'tool' => 'invalid',
			],
			'tools/call unknown' => [
				'body' => '{"jsonrpc":"2.0","id":4,"method":"tools/call","params":{"name":"x"}}',
				'headers' => $session,
				'tool' => 'unknown',
			],
			'tools/call crashes' => [
				'body' => '{"jsonrpc":"2.0","id":4,"method":"tools/call","params":{"name":"x"}}',
				'headers' => $session,
				'tool' => 'boom',
			],
			'resources/list' => ['body' => '{"jsonrpc":"2.0","id":5,"method":"resources/list"}', 'headers' => $session],
			'resources/read' => ['body' => '{"jsonrpc":"2.0","id":5,"method":"resources/read","params":{"uri":"or://a"}}', 'headers' => $session],
			'resources/read without uri' => ['body' => '{"jsonrpc":"2.0","id":5,"method":"resources/read"}', 'headers' => $session],
			'resources/templates/list' => ['body' => '{"jsonrpc":"2.0","id":5,"method":"resources/templates/list"}', 'headers' => $session],
			'unknown method' => ['body' => '{"jsonrpc":"2.0","id":6,"method":"nope/nope"}', 'headers' => $session],
		];
	}//end scenarios()

	/**
	 * Each scenario with its pinned transcript.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>}>
	 */
	public static function cases(): array {
		$cases = [];
		foreach (self::scenarios() as $name => $scenario) {
			$cases[$name] = [$scenario, self::pins()[$name]];
		}

		return $cases;
	}//end cases()

	/**
	 * The pinned transcripts.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function pins(): array {
		return [
			'nobody logged in' => [
				'status' => 401,
				'session' => null,
				'authenticate' => 'Basic realm="Nextcloud", charset="UTF-8"',
				'data' => [
					'jsonrpc' => '2.0',
					'id' => 7,
					'error' => [
						'code' => -32001,
						'message' => 'Authentication required: send Nextcloud credentials (an app password over basic auth, or a'
							.' bearer token)',
					],
				],
				'calls' => [],
			],
			'nobody logged in, unparseable' => [
				'status' => 401,
				'session' => null,
				'authenticate' => 'Basic realm="Nextcloud", charset="UTF-8"',
				'data' => [
					'jsonrpc' => '2.0',
					'id' => null,
					'error' => [
						'code' => -32001,
						'message' => 'Authentication required: send Nextcloud credentials (an app password over basic auth, or a'
							.' bearer token)',
					],
				],
				'calls' => [],
			],
			'parse error' => [
				'status' => 200,
				'session' => null,
				'authenticate' => null,
				'data' => [
					'jsonrpc' => '2.0',
					'id' => null,
					'error' => [
						'code' => -32700,
						'message' => 'Parse error: invalid JSON',
					],
				],
				'calls' => [],
			],
			'empty body' => [
				'status' => 200,
				'session' => null,
				'authenticate' => null,
				'data' => [
					'jsonrpc' => '2.0',
					'id' => null,
					'error' => [
						'code' => -32700,
						'message' => 'Parse error: invalid JSON',
					],
				],
				'calls' => [],
			],
			'wrong version' => [
				'status' => 200,
				'session' => null,
				'authenticate' => null,
				'data' => [
					'jsonrpc' => '2.0',
					'id' => 3,
					'error' => [
						'code' => -32600,
						'message' => 'Invalid JSON-RPC 2.0 request',
					],
				],
				'calls' => [],
			],
			'no method' => [
				'status' => 200,
				'session' => null,
				'authenticate' => null,
				'data' => [
					'jsonrpc' => '2.0',
					'id' => 'x',
					'error' => [
						'code' => -32600,
						'message' => 'Invalid JSON-RPC 2.0 request',
					],
				],
				'calls' => [],
			],
			'notification' => [
				'status' => 202,
				'session' => null,
				'authenticate' => null,
				'data' => null,
				'calls' => [
					[
						'log.debug',
						'[MCP] Notification received',
						[
							'method' => 'notifications/initialized',
						],
					],
				],
			],
			'initialize' => [
				'status' => 200,
				'session' => 'sess-new',
				'authenticate' => null,
				'data' => [
					'jsonrpc' => '2.0',
					'id' => 1,
					'result' => [
						'protocolVersion' => '2025-03-26',
					],
				],
				'calls' => [
					[
						'initialize',
						[
							'clientInfo' => [
								'name' => 'c',
							],
						],
						'admin',
					],
				],
			],
			'initialize with agent param' => [
				'status' => 200,
				'session' => 'sess-new',
				'authenticate' => null,
				'data' => [
					'jsonrpc' => '2.0',
					'id' => 1,
					'result' => [
						'protocolVersion' => '2025-03-26',
					],
				],
				'calls' => [
					[
						'assertUsable',
						'ag-1',
						'admin',
					],
					[
						'initialize',
						[
							'agent' => 'ag-1',
						],
						'admin',
					],
					[
						'bind',
						'sess-new',
						'ag-1',
					],
				],
			],
			'initialize with agent header' => [
				'status' => 200,
				'session' => 'sess-new',
				'authenticate' => null,
				'data' => [
					'jsonrpc' => '2.0',
					'id' => 1,
					'result' => [
						'protocolVersion' => '2025-03-26',
					],
				],
				'calls' => [
					[
						'assertUsable',
						'ag-2',
						'admin',
					],
					[
						'initialize',
						[],
						'admin',
					],
					[
						'bind',
						'sess-new',
						'ag-2',
					],
				],
			],
			'initialize with non-string agent' => [
				'status' => 200,
				'session' => null,
				'authenticate' => null,
				'data' => [
					'jsonrpc' => '2.0',
					'id' => 1,
					'error' => [
						'code' => -32602,
						'message' => 'The agent parameter must be an agent uuid',
					],
				],
				'calls' => [],
			],
			'initialize with empty agent' => [
				'status' => 200,
				'session' => null,
				'authenticate' => null,
				'data' => [
					'jsonrpc' => '2.0',
					'id' => 1,
					'error' => [
						'code' => -32602,
						'message' => 'The agent parameter must be an agent uuid',
					],
				],
				'calls' => [],
			],
			'initialize with refused agent' => [
				'status' => 200,
				'session' => null,
				'authenticate' => null,
				'data' => [
					'jsonrpc' => '2.0',
					'id' => 1,
					'error' => [
						'code' => -32602,
						'message' => 'agent not yours',
					],
				],
				'calls' => [
					[
						'assertUsable',
						'ag-1',
						'admin',
					],
				],
			],
			'initialize fails' => [
				'status' => 200,
				'session' => null,
				'authenticate' => null,
				'data' => [
					'jsonrpc' => '2.0',
					'id' => 1,
					'error' => [
						'code' => -32603,
						'message' => 'Initialize failed: store down',
					],
				],
				'calls' => [
					[
						'assertUsable',
						'ag-1',
						'admin',
					],
					[
						'initialize',
						[
							'agent' => 'ag-1',
						],
						'admin',
					],
					[
						'log.error',
						'[MCP] Initialize failed',
						[
							'error' => 'store down',
						],
					],
				],
			],
			'no session header' => [
				'status' => 200,
				'session' => null,
				'authenticate' => null,
				'data' => [
					'jsonrpc' => '2.0',
					'id' => 2,
					'error' => [
						'code' => -32000,
						'message' => 'Mcp-Session-Id header required',
					],
				],
				'calls' => [],
			],
			'expired session' => [
				'status' => 200,
				'session' => null,
				'authenticate' => null,
				'data' => [
					'jsonrpc' => '2.0',
					'id' => 2,
					'error' => [
						'code' => -32000,
						'message' => 'Invalid or expired session',
					],
				],
				'calls' => [
					[
						'validateSession',
						'sess-1',
					],
				],
			],
			'ping' => [
				'status' => 200,
				'session' => null,
				'authenticate' => null,
				'data' => [
					'jsonrpc' => '2.0',
					'id' => 2,
					'result' => [],
				],
				'calls' => [
					[
						'validateSession',
						'sess-1',
					],
					[
						'ping',
					],
				],
			],
			'tools/list' => [
				'status' => 200,
				'session' => null,
				'authenticate' => null,
				'data' => [
					'jsonrpc' => '2.0',
					'id' => 't',
					'result' => [
						'tools' => [
							[
								'name' => 'a',
							],
						],
					],
				],
				'calls' => [
					[
						'validateSession',
						'sess-1',
					],
					[
						'listTools',
					],
					[
						'filterListing',
						'sess-1',
						'admin',
						[
							'tools' => [
								[
									'name' => 'a',
								],
								[
									'name' => 'b',
								],
							],
						],
					],
				],
			],
			'tools/call' => [
				'status' => 200,
				'session' => null,
				'authenticate' => null,
				'data' => [
					'jsonrpc' => '2.0',
					'id' => 4,
					'result' => [
						'content' => [
							[
								'type' => 'text',
								'text' => 'done',
							],
						],
					],
				],
				'calls' => [
					[
						'validateSession',
						'sess-1',
					],
					[
						'assertMayCall',
						'sess-1',
						'admin',
						'x',
						[
							'a' => 1,
						],
					],
					[
						'callTool',
						'x',
						[
							'a' => 1,
						],
					],
				],
			],
			'tools/call without arguments' => [
				'status' => 200,
				'session' => null,
				'authenticate' => null,
				'data' => [
					'jsonrpc' => '2.0',
					'id' => 4,
					'result' => [
						'content' => [
							[
								'type' => 'text',
								'text' => 'done',
							],
						],
					],
				],
				'calls' => [
					[
						'validateSession',
						'sess-1',
					],
					[
						'assertMayCall',
						'sess-1',
						'admin',
						'x',
						[],
					],
					[
						'callTool',
						'x',
						[],
					],
				],
			],
			'tools/call without name' => [
				'status' => 200,
				'session' => null,
				'authenticate' => null,
				'data' => [
					'jsonrpc' => '2.0',
					'id' => 4,
					'error' => [
						'code' => -32602,
						'message' => 'Missing required parameter: name',
					],
				],
				'calls' => [
					[
						'validateSession',
						'sess-1',
					],
				],
			],
			'tools/call refused by grant' => [
				'status' => 200,
				'session' => null,
				'authenticate' => null,
				'data' => [
					'jsonrpc' => '2.0',
					'id' => 4,
					'error' => [
						'code' => -32602,
						'message' => 'grant does not cover it',
					],
				],
				'calls' => [
					[
						'validateSession',
						'sess-1',
					],
					[
						'assertMayCall',
						'sess-1',
						'admin',
						'x',
						[],
					],
				],
			],
			'tools/call invalid' => [
				'status' => 200,
				'session' => null,
				'authenticate' => null,
				'data' => [
					'jsonrpc' => '2.0',
					'id' => 4,
					'error' => [
						'code' => -32602,
						'message' => 'bad arguments',
					],
				],
				'calls' => [
					[
						'validateSession',
						'sess-1',
					],
					[
						'assertMayCall',
						'sess-1',
						'admin',
						'x',
						[],
					],
					[
						'callTool',
						'x',
						[],
					],
				],
			],
			'tools/call unknown' => [
				'status' => 200,
				'session' => null,
				'authenticate' => null,
				'data' => [
					'jsonrpc' => '2.0',
					'id' => 4,
					'error' => [
						'code' => -32601,
						'message' => 'no such tool',
					],
				],
				'calls' => [
					[
						'validateSession',
						'sess-1',
					],
					[
						'assertMayCall',
						'sess-1',
						'admin',
						'x',
						[],
					],
					[
						'callTool',
						'x',
						[],
					],
				],
			],
			'tools/call crashes' => [
				'status' => 200,
				'session' => null,
				'authenticate' => null,
				'data' => [
					'jsonrpc' => '2.0',
					'id' => 4,
					'error' => [
						'code' => -32603,
						'message' => 'tool crashed',
					],
				],
				'calls' => [
					[
						'validateSession',
						'sess-1',
					],
					[
						'assertMayCall',
						'sess-1',
						'admin',
						'x',
						[],
					],
					[
						'callTool',
						'x',
						[],
					],
					[
						'log.error',
						'[MCP] Method dispatch failed',
						[
							'method' => 'tools/call',
							'error' => 'tool crashed',
						],
					],
				],
			],
			'resources/list' => [
				'status' => 200,
				'session' => null,
				'authenticate' => null,
				'data' => [
					'jsonrpc' => '2.0',
					'id' => 5,
					'result' => [
						'resources' => [],
					],
				],
				'calls' => [
					[
						'validateSession',
						'sess-1',
					],
					[
						'listResources',
					],
				],
			],
			'resources/read' => [
				'status' => 200,
				'session' => null,
				'authenticate' => null,
				'data' => [
					'jsonrpc' => '2.0',
					'id' => 5,
					'result' => [
						'contents' => [
							[
								'uri' => 'or://a',
							],
						],
					],
				],
				'calls' => [
					[
						'validateSession',
						'sess-1',
					],
					[
						'readResource',
						'or://a',
					],
				],
			],
			'resources/read without uri' => [
				'status' => 200,
				'session' => null,
				'authenticate' => null,
				'data' => [
					'jsonrpc' => '2.0',
					'id' => 5,
					'error' => [
						'code' => -32602,
						'message' => 'Missing required parameter: uri',
					],
				],
				'calls' => [
					[
						'validateSession',
						'sess-1',
					],
				],
			],
			'resources/templates/list' => [
				'status' => 200,
				'session' => null,
				'authenticate' => null,
				'data' => [
					'jsonrpc' => '2.0',
					'id' => 5,
					'result' => [
						'resourceTemplates' => [],
					],
				],
				'calls' => [
					[
						'validateSession',
						'sess-1',
					],
					[
						'listTemplates',
					],
				],
			],
			'unknown method' => [
				'status' => 200,
				'session' => null,
				'authenticate' => null,
				'data' => [
					'jsonrpc' => '2.0',
					'id' => 6,
					'error' => [
						'code' => -32601,
						'message' => 'Method not found: nope/nope',
					],
				],
				'calls' => [
					[
						'validateSession',
						'sess-1',
					],
				],
			],
		];
	}//end pins()
}//end class
