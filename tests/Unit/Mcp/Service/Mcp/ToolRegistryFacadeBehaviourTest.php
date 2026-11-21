<?php

/**
 * ToolRegistryFacade behaviour tests.
 *
 * Covers registry gaps (an id whose tool is gone), nameless functions,
 * declared app/subject/action overrides, the right classification incl.
 * camelCase and the "special" fallback, whitelist matching by function name
 * and mcpId, and invocation success/failure/unknown.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Mcp\Service\Mcp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Mcp\Service\Mcp;

use OCA\OpenRegister\Mcp\Service\Mcp\ToolRegistryFacade;
use OCA\OpenRegister\Mcp\Service\ToolRegistry;
use OCA\OpenRegister\Mcp\Tool\ToolInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Mcp\Service\Mcp\ToolRegistryFacade
 */
class ToolRegistryFacadeBehaviourTest extends TestCase {

	private ToolRegistry&MockObject $registry;

	private LoggerInterface&MockObject $logger;

	private ToolRegistryFacade $facade;

	/**
	 * Build the facade over a mocked registry.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->registry = $this->createMock(ToolRegistry::class);
		$this->logger   = $this->createMock(LoggerInterface::class);
		$this->facade   = new ToolRegistryFacade($this->registry, $this->logger);
	}//end setUp()

	/**
	 * Wire the registry with id => tool (null meaning "listed but unresolvable").
	 *
	 * @param array<string, ToolInterface|null> $tools Registry content.
	 *
	 * @return void
	 */
	private function withTools(array $tools): void {
		$this->registry->method('getAllTools')->willReturn(array_fill_keys(array_keys($tools), []));
		$this->registry->method('getTool')->willReturnCallback(static fn (string $id): ?ToolInterface => $tools[$id]);
	}//end withTools()

	/**
	 * A tool mock with the given functions.
	 *
	 * @param list<array<string, mixed>> $functions Function definitions.
	 *
	 * @return ToolInterface&MockObject The tool.
	 */
	private function tool(array $functions): ToolInterface&MockObject {
		$tool = $this->createMock(ToolInterface::class);
		$tool->method('getFunctions')->willReturn($functions);

		return $tool;
	}//end tool()

	/**
	 * An id whose tool cannot be resolved contributes nothing.
	 *
	 * @return void
	 */
	public function testUnresolvableRegistryIdIsSkipped(): void {
		$this->withTools(['gone.tool' => null, 'app.x' => $this->tool([['name' => 'x_list']])]);

		$this->assertSame([['name' => 'x_list']], $this->facade->listTools());
	}//end testUnresolvableRegistryIdIsSkipped()

	/**
	 * Whitelist matches on registry id, function name or mcpId — nothing else.
	 *
	 * @return void
	 */
	public function testWhitelistMatchesRegistryIdNameOrMcpId(): void {
		$this->withTools([
			'app.one' => $this->tool([['name' => 'one_a'], ['name' => 'one_b']]),
			'app.two' => $this->tool([['name' => 'two_a', 'mcpId' => 'app.two.a'], ['name' => '', 'mcpId' => ''], ['mcpId' => 5]]),
		]);

		$this->assertSame(
			['one_a', 'one_b'],
			array_column($this->facade->listTools(['app.one']), 'name')
		);
		$this->assertSame(['one_b'], array_column($this->facade->listTools(['one_b']), 'name'));
		$this->assertSame(['two_a'], array_column($this->facade->listTools(['app.two.a']), 'name'));
		$this->assertSame([], $this->facade->listTools(['nothing']));
	}//end testWhitelistMatchesRegistryIdNameOrMcpId()

	/**
	 * describeTools skips nameless functions and lets declared app/subject/action win.
	 *
	 * @return void
	 */
	public function testDescribeToolsHonoursDeclaredFacets(): void {
		$this->withTools([
			'pipelinq.leads' => $this->tool([
				['description' => 'nameless'],
				['name' => 'getLead', 'description' => 'Get', 'app' => 'crm', 'subject' => 'lead', 'action' => 'get'],
				['name' => 'frobnicate'],
			]),
			'single' => $this->tool([['name' => 'delete.thing']]),
		]);

		$described = $this->facade->describeTools();

		$this->assertCount(3, $described);
		$this->assertSame(
			[
				'name' => 'getLead',
				'description' => 'Get',
				'app' => 'crm',
				'subject' => 'lead',
				'action' => 'get',
				'tool' => 'getLead',
				'group' => 'leads',
				'right' => 'read',
			],
			$described[0]
		);
		$this->assertSame('pipelinq', $described[1]['app']);
		$this->assertNull($described[1]['subject']);
		$this->assertNull($described[1]['action']);
		$this->assertSame('', $described[1]['description']);
		$this->assertSame('special', $described[1]['right']);
		$this->assertSame('', $described[2]['group']);
		$this->assertSame('delete', $described[2]['right']);
	}//end testDescribeToolsHonoursDeclaredFacets()

	/**
	 * Unknown tool ids are an error result without invoking anything.
	 *
	 * @return void
	 */
	public function testInvokeUnknownToolIsError(): void {
		$this->withTools(['app.x' => $this->tool([['name' => 'x']])]);

		$this->assertSame(
			['result' => ['error' => 'Unknown tool: y'], 'isError' => true],
			$this->facade->invokeTool('y', [])
		);
	}//end testInvokeUnknownToolIsError()

	/**
	 * A tool is found by mcpId and its result wrapped as success.
	 *
	 * @return void
	 */
	public function testInvokeByMcpIdWrapsResult(): void {
		$tool = $this->tool([['name' => 'app_x', 'mcpId' => 'app.x']]);
		$tool->expects($this->once())->method('executeFunction')->with('app.x', ['a' => 1])->willReturn(['ok' => 1]);
		$this->withTools(['app.x' => $tool]);

		$this->assertSame(['result' => ['ok' => 1], 'isError' => false], $this->facade->invokeTool('app.x', ['a' => 1]));
	}//end testInvokeByMcpIdWrapsResult()

	/**
	 * A throwing tool is logged and reported as an error result.
	 *
	 * @return void
	 */
	public function testInvokeFailureIsLoggedAndReported(): void {
		$tool = $this->tool([['name' => 'x']]);
		$tool->method('executeFunction')->willThrowException(new RuntimeException('bad'));
		$this->withTools(['app.x' => $tool]);
		$this->logger->expects($this->once())->method('error')
			->with('[ToolRegistryFacade] Tool invocation failed', ['tool' => 'x', 'error' => 'bad']);

		$this->assertSame(['result' => ['error' => 'bad'], 'isError' => true], $this->facade->invokeTool('x', []));
	}//end testInvokeFailureIsLoggedAndReported()
}//end class
