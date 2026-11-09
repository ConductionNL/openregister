<?php

/**
 * Golden outcomes of McpAgentScope's tool-grant gate: which tools/list
 * entries a session sees and which tools/call it refuses, with the exact
 * refusal text.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Mcp
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Mcp;

use InvalidArgumentException;
use OCA\OpenRegister\Kernel\Contracts\AgentMapperInterface;
use OCA\OpenRegister\Mcp\Db\Agent;
use OCA\OpenRegister\Mcp\Service\Mcp\McpToolCatalogueInterface;
use OCA\OpenRegister\Service\Capability\ToolGrantResolver;
use OCA\OpenRegister\Service\Mcp\McpAgentScope;
use OCA\OpenRegister\Service\Mcp\McpSessionCaller;
use OCA\OpenRegister\Service\Mcp\ToolCall;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Pins allowed and refused outcomes of filterListing() and assertMayCall().
 */
class McpAgentScopeGoldenTest extends TestCase {

	/**
	 * @var array<string, Agent>
	 */
	private array $agents = [];

	/**
	 * @var array<string, mixed>
	 */
	private array $cached = [];

	private McpAgentScope $scope;

	protected function setUp(): void {
		parent::setUp();
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturnCallback(fn (string $key): mixed => $this->cached[$key] ?? null);
		$cache->method('set')->willReturnCallback(function (string $key, mixed $value): bool {
			$this->cached[$key] = $value;
			return true;
		});
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($cache);

		$agentMapper = $this->createMock(AgentMapperInterface::class);
		$agentMapper->method('findByUuid')->willReturnCallback(function (string $uuid): Agent {
			if (isset($this->agents[$uuid]) === false) {
				throw new DoesNotExistException('no agent ' . $uuid);
			}
			return $this->agents[$uuid];
		});
		$agentMapper->method('canUserAccessAgent')->willReturnCallback(
			static fn (Agent $agent, string $userId): bool => ($agent->getIsPrivate() !== true || $agent->getOwner() === $userId)
		);

		$tools = $this->createMock(McpToolCatalogueInterface::class);
		$tools->method('listTools')->willReturn(['tools' => self::catalog()]);

		$this->scope = new McpAgentScope($agentMapper, new ToolGrantResolver(), $tools, $cacheFactory, new NullLogger());
	}//end setUp()

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private static function catalog(): array {
		$tool = static fn (string $id): array => ['id' => $id, 'name' => str_replace('.', '_', $id), 'description' => $id];
		return [
			$tool('openregister.objects.search'),
			$tool('openregister.objects.delete'),
			$tool('openregister.registers.search'),
		];
	}//end catalog()

	private function agent(string $uuid, array $tools, bool $private = false, bool $active = true): void {
		$agent = new Agent();
		$agent->setUuid($uuid);
		$agent->setName('Agent ' . $uuid);
		$agent->setTools($tools);
		$agent->setIsPrivate($private);
		$agent->setOwner('bob');
		$agent->setActive($active);
		$this->agents[$uuid] = $agent;
	}//end agent()

	private function refusal(string $sessionId, string $userId, string $name, array $arguments): ?string {
		try {
			$this->scope->assertMayCall(new McpSessionCaller($sessionId, $userId), new ToolCall($name, $arguments));
		} catch (InvalidArgumentException $e) {
			return $e->getMessage();
		}

		return null;
	}//end refusal()

	public function testASessionWithoutAnAgentSeesAndCallsEverything(): void {
		$listing = ['tools' => self::catalog()];

		$this->assertSame($listing, $this->scope->filterListing(new McpSessionCaller('s0', 'alice'), $listing));
		$this->assertNull($this->refusal('s0', 'alice', 'openregister.objects.delete', []));
	}//end testASessionWithoutAnAgentSeesAndCallsEverything()

	public function testTheListingIsNarrowedToTheGrant(): void {
		$this->agent('a1', ['openregister.objects.search', 'openregister.registers.search']);
		$this->scope->bind('s1', 'a1');

		$listing = $this->scope->filterListing(new McpSessionCaller('s1', 'alice'), ['tools' => self::catalog()]);

		$this->assertSame(['openregister.objects.search', 'openregister.registers.search'], array_column($listing['tools'], 'id'));
		$this->assertSame([0, 1], array_keys($listing['tools']));
	}//end testTheListingIsNarrowedToTheGrant()

	/**
	 * @return array<string, array{0: array<int, string>, 1: string, 2: array<string, mixed>, 3: string|null}>
	 */
	public static function calls(): array {
		return [
			'granted by id' => [['openregister.objects.search'], 'openregister.objects.search', [], null],
			'granted by short name' => [['openregister.objects.search'], 'openregister_objects_search', [], null],
			'not granted' => [
				['openregister.objects.search'],
				'openregister.objects.delete',
				[],
				'Tool openregister.objects.delete is not in the grant of agent Agent a1',
			],
			'not granted by short name' => [
				['openregister.objects.search'],
				'openregister_objects_delete',
				[],
				'Tool openregister.objects.delete is not in the grant of agent Agent a1',
			],
			'unknown tool' => [
				['openregister.objects.search'],
				'nope.tool',
				[],
				'Tool nope.tool is not in the grant of agent Agent a1',
			],
			'pinned argument matches' => [['openregister.objects.search?register=zaken'], 'openregister.objects.search', ['register' => 'zaken'], null],
			'pinned argument differs' => [
				['openregister.objects.search?register=zaken'],
				'openregister.objects.search',
				['register' => 'hr'],
				'Tool openregister.objects.search is granted to agent Agent a1 only with argument register = zaken',
			],
			'pinned argument missing' => [
				['openregister.objects.search?register=zaken'],
				'openregister.objects.search',
				[],
				'Tool openregister.objects.search is granted to agent Agent a1 only with argument register = zaken',
			],
			'set argument member' => [['openregister.objects.search?register=in:zaken,hr'], 'openregister.objects.search', ['register' => 'hr'], null],
			'set argument outside' => [
				['openregister.objects.search?register=in:zaken,hr'],
				'openregister.objects.search',
				['register' => 'fin'],
				'Tool openregister.objects.search is granted to agent Agent a1 only with argument register in zaken, hr',
			],
			'non-string argument refused' => [
				['openregister.objects.search?register=zaken'],
				'openregister.objects.search',
				['register' => ['zaken']],
				'Tool openregister.objects.search is granted to agent Agent a1 only with argument register = zaken',
			],
		];
	}//end calls()

	/**
	 * @dataProvider calls
	 *
	 * @param array<int, string>   $grant
	 * @param array<string, mixed> $arguments
	 */
	public function testToolCallOutcome(array $grant, string $name, array $arguments, ?string $expected): void {
		$this->agent('a1', $grant);
		$this->scope->bind('s1', 'a1');

		$this->assertSame($expected, $this->refusal('s1', 'alice', $name, $arguments));
	}//end testToolCallOutcome()

	public function testABoundAgentThatTurnedPrivateRefusesEveryPath(): void {
		$this->agent('p1', ['openregister.objects.search'], private: true);
		$this->scope->bind('s1', 'p1');

		$this->assertSame('Agent p1 is not available to this user', $this->refusal('s1', 'alice', 'openregister.objects.search', []));
		$this->expectException(InvalidArgumentException::class);
		$this->scope->filterListing(new McpSessionCaller('s1', 'alice'), ['tools' => self::catalog()]);
	}//end testABoundAgentThatTurnedPrivateRefusesEveryPath()

	public function testABoundAgentThatIsGoneOrInactiveRefuses(): void {
		$this->agent('off', ['openregister.objects.search'], active: false);
		$this->scope->bind('s1', 'off');
		$this->scope->bind('s2', 'gone');

		$this->assertSame('Agent off is not available to this user', $this->refusal('s1', 'alice', 'openregister.objects.search', []));
		$this->assertSame('Unknown agent: gone', $this->refusal('s2', 'alice', 'openregister.objects.search', []));
	}//end testABoundAgentThatIsGoneOrInactiveRefuses()
}//end class
