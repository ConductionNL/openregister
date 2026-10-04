<?php

/**
 * An agent is held to its tool grant over MCP (ai-agent-limits-screen, task 1).
 *
 * Drives the REAL McpServerController, McpProtocolService, McpToolsService,
 * ToolGrantResolver and McpAgentScope. Only the database (AgentMapper), the
 * cache backend and the tool provider are stand-ins: the provider records
 * which tool actually ran, so a refusal is proven by the tool NOT running.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/agent-tool-governance/spec.md#requirement-an-agent-is-held-to-its-tool-grant-on-every-path
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\McpServerController;
use OCA\OpenRegister\Db\Agent;
use OCA\OpenRegister\Db\AgentMapper;
use OCA\OpenRegister\Mcp\IMcpToolProvider;
use OCA\OpenRegister\Service\Capability\ToolGrantResolver;
use OCA\OpenRegister\Service\Mcp\McpAgentScope;
use OCA\OpenRegister\Service\Mcp\McpProtocolService;
use OCA\OpenRegister\Service\Mcp\McpResourcesService;
use OCA\OpenRegister\Service\Mcp\McpToolsService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A php:// stream wrapper that serves a fixed request body.
 */
class AgentLimitsInputStream {

	/**
	 * @var string
	 */
	public static string $body = '';

	/**
	 * @var int
	 */
	private int $pos = 0;

	/**
	 * @var resource|null
	 */
	public $context;

	public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool {
		$this->pos = 0;
		return true;
	}

	public function stream_read(int $count): string {
		$chunk = substr(self::$body, $this->pos, $count);
		$this->pos += strlen($chunk);
		return $chunk;
	}

	public function stream_eof(): bool {
		return $this->pos >= strlen(self::$body);
	}

	public function stream_stat(): array {
		return [];
	}
}

/**
 * An in-memory ICache, so a session written by initialize is read back by the next call.
 */
class AgentLimitsArrayCache implements ICache {

	/**
	 * @var array<string, mixed>
	 */
	private array $data = [];

	public function get($key) {
		return $this->data[$key] ?? null;
	}

	public function set($key, $value, $ttl = 0) {
		$this->data[$key] = $value;
		return true;
	}

	public function hasKey($key) {
		return isset($this->data[$key]);
	}

	public function remove($key) {
		unset($this->data[$key]);
		return true;
	}

	public function clear($prefix = '') {
		$this->data = [];
		return true;
	}

	public static function isAvailable(): bool {
		return true;
	}
}

class McpAgentLimitsTest extends TestCase {

	/**
	 * Tool ids the provider actually ran, in order.
	 *
	 * @var list<string>
	 */
	private array $ran = [];

	/**
	 * @var array<string, Agent>
	 */
	private array $agents = [];

	/**
	 * @var array<string, string>
	 */
	private array $headers = [];

	private ?McpServerController $controller = null;

	protected function setUp(): void {
		parent::setUp();
		stream_wrapper_unregister('php');
		stream_wrapper_register('php', AgentLimitsInputStream::class);

		$provider = new class($this->ran) implements IMcpToolProvider {
			/** @param list<string> $ran */
			public function __construct(private array &$ran) {
			}

			public function getAppId(): string {
				return 'openregister';
			}

			public function getTools(): array {
				$tool = static fn (string $id, bool $read) => [
					'id' => $id,
					'name' => str_replace('.', '_', $id),
					'description' => $id,
					'inputSchema' => ['type' => 'object', 'properties' => ['register' => ['type' => 'string']]],
					'annotations' => ['readOnlyHint' => $read, 'destructiveHint' => !$read],
				];
				return [
					$tool('openregister.objects.search', true),
					$tool('openregister.objects.delete', false),
					$tool('openregister.registers.search', true),
				];
			}

			public function invokeTool(string $toolId, array $arguments): array {
				$this->ran[] = $toolId;
				return ['ok' => $toolId];
			}
		};

		// One cache per prefix, as Nextcloud's distributed cache keeps them apart.
		$caches = [];
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturnCallback(
			static function (string $prefix = '') use (&$caches): ICache {
				return $caches[$prefix] ??= new AgentLimitsArrayCache();
			}
		);
		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturnCallback(static fn () => bin2hex(random_bytes(16)));

		$agentMapper = $this->createMock(AgentMapper::class);
		$agentMapper->method('findByUuid')->willReturnCallback(function (string $uuid): Agent {
			if (isset($this->agents[$uuid]) === false) {
				throw new DoesNotExistException('no agent ' . $uuid);
			}
			return $this->agents[$uuid];
		});
		$agentMapper->method('canUserAccessAgent')->willReturnCallback(
			static fn (Agent $a, string $userId) => ($a->getIsPrivate() !== true || $a->getOwner() === $userId)
		);

		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(fn (string $name) => $this->headers[$name] ?? '');

		$logger = new NullLogger();
		$tools = new McpToolsService([$provider], $logger);
		$this->controller = new McpServerController(
			'openregister',
			$request,
			new McpProtocolService($cacheFactory, $random, $logger),
			$tools,
			$this->createMock(McpResourcesService::class),
			$logger,
			$this->aliceSession(),
			new McpAgentScope($agentMapper, new ToolGrantResolver(), $tools, $cacheFactory, $logger),
		);
	}

	/**
	 * A user session holding alice, the caller every test speaks as.
	 */
	private function aliceSession(): IUserSession {
		$alice = $this->createMock(IUser::class);
		$alice->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($alice);

		return $session;
	}

	protected function tearDown(): void {
		stream_wrapper_restore('php');
		parent::tearDown();
	}

	private function agent(string $uuid, array $tools, bool $private = false, bool $active = true): void {
		$agent = new Agent();
		$agent->setUuid($uuid);
		$agent->setName('Agent ' . $uuid);
		$agent->setTools($tools);
		$agent->setIsPrivate($private);
		$agent->setOwner('bob');
		$agent->setActive($active);
		$this->agents[$uuid] = $agent;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function rpc(string $method, array $params = []): array {
		AgentLimitsInputStream::$body = json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params]);
		$response = $this->controller->handle();
		if ($method === 'initialize') {
			$session = $response->getHeaders()['Mcp-Session-Id'] ?? '';
			$this->headers['Mcp-Session-Id'] = $session;
		}
		return $response->getData();
	}

	public function testACallOutsideTheAgentGrantIsRefusedAndNamesTheTool(): void {
		$this->agent('a1', ['openregister.objects.search']);
		$this->rpc('initialize', ['agent' => 'a1']);

		$out = $this->rpc('tools/call', ['name' => 'openregister.objects.delete', 'arguments' => []]);

		$this->assertArrayHasKey('error', $out, 'a delete outside the grant must be refused');
		$this->assertStringContainsString('openregister.objects.delete', $out['error']['message']);
		$this->assertSame([], $this->ran, 'the refused tool must not run');
	}

	public function testACallInsideTheAgentGrantRuns(): void {
		$this->agent('a1', ['openregister.objects.search']);
		$this->rpc('initialize', ['agent' => 'a1']);

		$out = $this->rpc('tools/call', ['name' => 'openregister_objects_search', 'arguments' => []]);

		$this->assertArrayHasKey('result', $out);
		$this->assertSame(['openregister.objects.search'], $this->ran);
	}

	public function testToolsListShowsTheAgentOnlyItsGrant(): void {
		$this->agent('a1', ['openregister.objects.search']);
		$this->rpc('initialize', ['agent' => 'a1']);

		$out = $this->rpc('tools/list');

		$this->assertSame(['openregister.objects.search'], array_column($out['result']['tools'], 'id'));
	}

	public function testANarrowedGrantAppliesToAnOpenSession(): void {
		$this->agent('a1', ['openregister.objects.search', 'openregister.registers.search']);
		$this->rpc('initialize', ['agent' => 'a1']);
		$this->agents['a1']->setTools(['openregister.objects.search']);

		$out = $this->rpc('tools/call', ['name' => 'openregister.registers.search', 'arguments' => []]);

		$this->assertArrayHasKey('error', $out);
		$this->assertSame([], $this->ran);
	}

	public function testAnArgumentScopedGrantRefusesAnotherValue(): void {
		$this->agent('a1', ['openregister.objects.search?register=zaken']);
		$this->rpc('initialize', ['agent' => 'a1']);

		$refused = $this->rpc('tools/call', ['name' => 'openregister.objects.search', 'arguments' => ['register' => 'hr']]);
		$allowed = $this->rpc('tools/call', ['name' => 'openregister.objects.search', 'arguments' => ['register' => 'zaken']]);

		$this->assertArrayHasKey('error', $refused);
		$this->assertStringContainsString('register', $refused['error']['message']);
		$this->assertArrayHasKey('result', $allowed);
		$this->assertSame(['openregister.objects.search'], $this->ran);
	}

	public function testAnUnknownOrInaccessibleAgentIsRefusedAtInitialize(): void {
		$this->agent('private', ['openregister.objects.search'], private: true);
		$this->agent('off', ['openregister.objects.search'], active: false);

		foreach (['nope', 'private', 'off'] as $uuid) {
			$out = $this->rpc('initialize', ['agent' => $uuid]);
			$this->assertArrayHasKey('error', $out, 'initialize as ' . $uuid . ' must be refused, not silently unscoped');
		}
	}

	public function testASessionWithoutAnAgentKeepsTheUserRights(): void {
		$this->rpc('initialize', []);

		$out = $this->rpc('tools/call', ['name' => 'openregister.objects.delete', 'arguments' => []]);

		$this->assertArrayHasKey('result', $out);
		$this->assertSame(['openregister.objects.delete'], $this->ran);
	}
}
