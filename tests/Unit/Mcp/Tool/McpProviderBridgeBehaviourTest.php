<?php

/**
 * McpProviderBridge behaviour tests.
 *
 * Covers what the existing pass-through tests do not: id filtering, the
 * onlyMcpId restriction, schema type collapsing, function-name resolution,
 * provider failure handling and the LLPhant `__call` entry point.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Mcp\Tool
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Mcp\Tool;

use OCA\OpenRegister\Mcp\IMcpToolProvider;
use OCA\OpenRegister\Mcp\Tool\McpProviderBridge;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Mcp\Tool\McpProviderBridge
 */
class McpProviderBridgeBehaviourTest extends TestCase {

	private IMcpToolProvider&MockObject $provider;

	private LoggerInterface&MockObject $logger;

	private McpProviderBridge $bridge;

	/**
	 * Build a bridge over a mocked provider.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->provider = $this->createMock(IMcpToolProvider::class);
		$this->provider->method('getAppId')->willReturn('decidesk');
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->bridge = new McpProviderBridge($this->provider, $this->logger);
	}//end setUp()

	/**
	 * Name and description are derived from the provider's app id.
	 *
	 * @return void
	 */
	public function testNameAndDescriptionComeFromAppId(): void {
		$this->assertSame('decidesk', $this->bridge->getName());
		$this->assertSame('MCP-bridged tools from the decidesk app.', $this->bridge->getDescription());
	}//end testNameAndDescriptionComeFromAppId()

	/**
	 * Descriptors without an id are dropped; dots become underscores; description falls back.
	 *
	 * @return void
	 */
	public function testGetFunctionsSkipsIdlessDescriptorsAndFallsBack(): void {
		$this->provider->method('getTools')->willReturn([
			['name' => 'no id'],
			['id' => ''],
			['id' => 'decidesk.list', 'name' => 'List things'],
			['id' => 'decidesk.raw'],
		]);

		$functions = $this->bridge->getFunctions();

		$this->assertCount(2, $functions);
		$this->assertSame('decidesk_list', $functions[0]['name']);
		$this->assertSame('decidesk.list', $functions[0]['mcpId']);
		$this->assertSame('List things', $functions[0]['description']);
		$this->assertSame(['type' => 'object', 'properties' => []], $functions[0]['parameters']);
		$this->assertSame('decidesk.raw', $functions[1]['description']);
	}//end testGetFunctionsSkipsIdlessDescriptorsAndFallsBack()

	/**
	 * With onlyMcpId set, only the matching descriptor is exposed.
	 *
	 * @return void
	 */
	public function testOnlyMcpIdRestrictsFunctions(): void {
		$this->provider->method('getTools')->willReturn([
			['id' => 'decidesk.a', 'description' => 'A'],
			['id' => 'decidesk.b', 'description' => 'B'],
		]);

		$this->bridge->setOnlyMcpId('decidesk.b');
		$functions = $this->bridge->getFunctions();

		$this->assertCount(1, $functions);
		$this->assertSame('decidesk_b', $functions[0]['name']);
	}//end testOnlyMcpIdRestrictsFunctions()

	/**
	 * Union types collapse to the first non-null string type, or 'string'.
	 *
	 * @return void
	 */
	public function testSchemaUnionTypesAreCollapsed(): void {
		$this->provider->method('getTools')->willReturn([
			[
				'id' => 'decidesk.t',
				'inputSchema' => [
					'type' => ['null', 'object'],
					'properties' => [
						'a' => ['type' => ['null', 'integer']],
						'b' => ['type' => ['null']],
						'c' => ['type' => 'boolean'],
						'd' => 'not-an-array',
					],
				],
			],
		]);

		$parameters = $this->bridge->getFunctions()[0]['parameters'];

		$this->assertSame('object', $parameters['type']);
		$this->assertSame('integer', $parameters['properties']['a']['type']);
		$this->assertSame('string', $parameters['properties']['b']['type']);
		$this->assertSame('boolean', $parameters['properties']['c']['type']);
		$this->assertSame('not-an-array', $parameters['properties']['d']);
	}//end testSchemaUnionTypesAreCollapsed()

	/**
	 * An unknown function name is refused without calling the provider.
	 *
	 * @return void
	 */
	public function testExecuteUnknownFunctionIsRefused(): void {
		$this->provider->method('getTools')->willReturn([['id' => 'decidesk.list']]);
		$this->provider->expects($this->never())->method('invokeTool');

		$result = $this->bridge->executeFunction('decidesk_other', []);

		$this->assertSame(true, $result['isError']);
		$this->assertSame('unknown_function', $result['error']);
		$this->assertStringContainsString('decidesk_other', $result['message']);
	}//end testExecuteUnknownFunctionIsRefused()

	/**
	 * Both the safe name and the raw MCP id resolve to the raw id.
	 *
	 * @return void
	 */
	public function testExecuteForwardsSafeAndRawNames(): void {
		$this->provider->method('getTools')->willReturn([['id' => 'decidesk.list']]);
		$this->provider->expects($this->exactly(2))->method('invokeTool')
			->with('decidesk.list', ['q' => 1])
			->willReturn(['ok' => true]);

		$this->assertSame(['ok' => true], $this->bridge->executeFunction('decidesk_list', ['q' => 1]));
		$this->assertSame(['ok' => true], $this->bridge->executeFunction('decidesk.list', ['q' => 1]));
	}//end testExecuteForwardsSafeAndRawNames()

	/**
	 * A throwing provider becomes an internal_error result and is logged.
	 *
	 * @return void
	 */
	public function testExecuteProviderFailureBecomesInternalError(): void {
		$this->provider->method('getTools')->willReturn([['id' => 'decidesk.list']]);
		$this->provider->method('invokeTool')->willThrowException(new RuntimeException('boom'));
		$this->logger->expects($this->once())->method('error')
			->with('[McpProviderBridge] Provider invocation failed', $this->anything());

		$result = $this->bridge->executeFunction('decidesk_list', []);

		$this->assertSame(['isError' => true, 'error' => 'internal_error', 'message' => 'boom'], $result);
	}//end testExecuteProviderFailureBecomesInternalError()

	/**
	 * __call with a single array argument forwards it as the parameter map, JSON-encoded.
	 *
	 * @return void
	 */
	public function testMagicCallWithSingleArrayForwardsItAndEncodes(): void {
		$this->provider->method('getTools')->willReturn([['id' => 'decidesk.list']]);
		$this->provider->expects($this->once())->method('invokeTool')
			->with('decidesk.list', ['limit' => 5])
			->willReturn(['count' => 2]);

		$this->assertSame('{"count":2}', $this->bridge->decidesk_list(['limit' => 5]));
	}//end testMagicCallWithSingleArrayForwardsItAndEncodes()

	/**
	 * __call with positional scalars forwards them as a list.
	 *
	 * @return void
	 */
	public function testMagicCallWithPositionalArgsForwardsList(): void {
		$this->provider->method('getTools')->willReturn([['id' => 'decidesk.list']]);
		$this->provider->expects($this->once())->method('invokeTool')
			->with('decidesk.list', ['a', 'b'])
			->willReturn([]);

		$this->assertSame('[]', $this->bridge->decidesk_list('a', 'b'));
	}//end testMagicCallWithPositionalArgsForwardsList()

	/**
	 * __call with no args forwards an empty map.
	 *
	 * @return void
	 */
	public function testMagicCallWithoutArgsForwardsEmptyMap(): void {
		$this->provider->method('getTools')->willReturn([['id' => 'decidesk.list']]);
		$this->provider->expects($this->once())->method('invokeTool')
			->with('decidesk.list', [])
			->willReturn(['x' => 1]);

		$this->assertSame('{"x":1}', $this->bridge->decidesk_list());
	}//end testMagicCallWithoutArgsForwardsEmptyMap()

	/**
	 * A result that json_encode cannot encode yields the encode_failed envelope.
	 *
	 * @return void
	 */
	public function testMagicCallUnencodableResultYieldsEncodeFailed(): void {
		$this->provider->method('getTools')->willReturn([['id' => 'decidesk.list']]);
		$this->provider->method('invokeTool')->willReturn(['bad' => "\xB1\x31"]);

		$this->assertSame(
			'{"isError":true,"error":"encode_failed","message":"Tool result could not be encoded."}',
			$this->bridge->decidesk_list()
		);
	}//end testMagicCallUnencodableResultYieldsEncodeFailed()
}//end class
