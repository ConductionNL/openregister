<?php

/**
 * ToolManagementHandler behaviour tests.
 *
 * Pins the JSON-Schema to LLPhant Parameter conversion decision by decision:
 * free-form objects, nested objects, array item typing, required-parameter
 * selection and owning-tool resolution.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Mcp\Service\Chat
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Mcp\Service\Chat;

use LLPhant\Chat\FunctionInfo\FunctionInfo;
use LLPhant\Chat\FunctionInfo\Parameter;
use OCA\OpenRegister\Mcp\Db\Agent;
use OCA\OpenRegister\Mcp\Service\Chat\ToolManagementHandler;
use OCA\OpenRegister\Mcp\Service\ToolRegistry;
use OCA\OpenRegister\Mcp\Tool\ToolInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Mcp\Service\Chat\ToolManagementHandler
 * @uses   \OCA\OpenRegister\Mcp\Db\Agent
 */
class ToolManagementHandlerBehaviourTest extends TestCase {

	private ToolRegistry&MockObject $registry;

	private LoggerInterface&MockObject $logger;

	private ToolManagementHandler $handler;

	/**
	 * Build the handler with mocked collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->registry = $this->createMock(ToolRegistry::class);
		$this->logger   = $this->createMock(LoggerInterface::class);
		$this->handler  = new ToolManagementHandler($this->registry, $this->logger);
	}//end setUp()

	/**
	 * A tool mock exposing the given function definitions.
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
	 * Convert one function definition and return its single FunctionInfo.
	 *
	 * @param array<string, mixed> $function Function definition.
	 * @param list<ToolInterface> $tools Tools to search for the owner.
	 *
	 * @return FunctionInfo The converted info.
	 */
	private function convertOne(array $function, array $tools = []): FunctionInfo {
		$infos = $this->handler->convertFunctionsToFunctionInfo([$function], $tools);
		$this->assertCount(1, $infos);

		return $infos[0];
	}//end convertOne()

	/**
	 * Index parameters by name.
	 *
	 * @param list<Parameter> $parameters Parameters.
	 *
	 * @return array<string, Parameter> By name.
	 */
	private function byName(array $parameters): array {
		$out = [];
		foreach ($parameters as $parameter) {
			$out[$parameter->name] = $parameter;
		}

		return $out;
	}//end byName()

	/**
	 * Functions of all tools are flattened in order.
	 *
	 * @return void
	 */
	public function testConvertToolsToFunctionsFlattensInOrder(): void {
		$a = ['name' => 'a'];
		$b = ['name' => 'b'];
		$c = ['name' => 'c'];

		$this->assertSame([$a, $b, $c], $this->handler->convertToolsToFunctions([$this->tool([$a, $b]), $this->tool([]), $this->tool([$c])]));
		$this->assertSame([], $this->handler->convertToolsToFunctions([]));
	}//end testConvertToolsToFunctionsFlattensInOrder()

	/**
	 * Scalar parameters keep type, description, enum and format; missing type defaults to string.
	 *
	 * @return void
	 */
	public function testScalarParametersCarryTypeEnumAndFormat(): void {
		$info = $this->convertOne([
			'name' => 'f',
			'description' => 'Does f',
			'parameters' => [
				'properties' => [
					'mode' => ['type' => 'string', 'description' => 'Mode', 'enum' => ['a', 'b']],
					'when' => ['type' => 'string', 'format' => 'date-time'],
					'bare' => [],
					'junk' => 'not-a-def',
				],
			],
		]);

		$params = $this->byName($info->parameters);
		$this->assertSame('Does f', $info->description);
		$this->assertSame(['a', 'b'], $params['mode']->enum);
		$this->assertSame('Mode', $params['mode']->description);
		$this->assertSame('date-time', $params['when']->format);
		$this->assertSame('string', $params['bare']->type);
		$this->assertSame('', $params['bare']->description);
		$this->assertSame('string', $params['junk']->type);
	}//end testScalarParametersCarryTypeEnumAndFormat()

	/**
	 * A property-less object becomes a string with a JSON hint; a described one keeps its text.
	 *
	 * @return void
	 */
	public function testFreeFormObjectsBecomeJsonStrings(): void {
		$info = $this->convertOne([
			'name' => 'f',
			'parameters' => [
				'properties' => [
					'plain' => ['type' => 'object'],
					'described' => ['type' => 'object', 'description' => 'Filters'],
				],
			],
		]);

		$params = $this->byName($info->parameters);
		$this->assertSame('string', $params['plain']->type);
		$this->assertSame('A JSON object.', $params['plain']->description);
		$this->assertNull($params['plain']->itemsOrProperties);
		$this->assertSame('string', $params['described']->type);
		$this->assertSame('Filters (pass as a JSON object).', $params['described']->description);
	}//end testFreeFormObjectsBecomeJsonStrings()

	/**
	 * An object with properties stays an object carrying nested Parameters.
	 *
	 * @return void
	 */
	public function testObjectWithPropertiesNestsParameters(): void {
		$info = $this->convertOne([
			'name' => 'f',
			'parameters' => [
				'properties' => [
					'filter' => ['type' => 'object', 'properties' => ['status' => ['type' => 'string'], 3 => ['type' => 'integer']]],
				],
			],
		]);

		$filter = $this->byName($info->parameters)['filter'];
		$this->assertSame('object', $filter->type);
		$nested = $this->byName($filter->itemsOrProperties);
		$this->assertSame(['status', 3], array_keys($nested));
		$this->assertSame('integer', $nested[3]->type);
	}//end testObjectWithPropertiesNestsParameters()

	/**
	 * Array items: scalar type kept, missing/odd items become string, object items nest or fall back.
	 *
	 * @return void
	 */
	public function testArrayItemsAreTyped(): void {
		$info = $this->convertOne([
			'name' => 'f',
			'parameters' => [
				'properties' => [
					'ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
					'untyped' => ['type' => 'array'],
					'scalarItems' => ['type' => 'array', 'items' => 'string'],
					'noTypeItems' => ['type' => 'array', 'items' => ['description' => 'x']],
					'blobs' => ['type' => 'array', 'items' => ['type' => 'object']],
					'rows' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['ok' => ['type' => 'boolean']]]],
				],
			],
		]);

		$params = $this->byName($info->parameters);
		$this->assertSame('integer', $params['ids']->itemsOrProperties);
		$this->assertSame('string', $params['untyped']->itemsOrProperties);
		$this->assertSame('string', $params['scalarItems']->itemsOrProperties);
		$this->assertSame('string', $params['noTypeItems']->itemsOrProperties);
		$this->assertSame('string', $params['blobs']->itemsOrProperties);
		$rows = $params['rows']->itemsOrProperties;
		$this->assertCount(1, $rows);
		$this->assertSame('ok', $rows[0]->name);
		$this->assertSame('boolean', $rows[0]->type);
	}//end testArrayItemsAreTyped()

	/**
	 * Only declared parameters that are listed as required are required.
	 *
	 * @return void
	 */
	public function testRequiredParametersAreTheDeclaredRequiredOnes(): void {
		$info = $this->convertOne([
			'name' => 'f',
			'parameters' => [
				'properties' => ['a' => ['type' => 'string'], 'b' => ['type' => 'string']],
				'required' => ['b', 'missing'],
			],
		]);

		$this->assertSame(['b'], array_map(static fn (Parameter $p): string => $p->name, $info->requiredParameters));
	}//end testRequiredParametersAreTheDeclaredRequiredOnes()

	/**
	 * A function without properties has no parameters and a default empty description.
	 *
	 * @return void
	 */
	public function testFunctionWithoutPropertiesHasNoParameters(): void {
		$owner = $this->tool([['name' => 'ping']]);
		$info  = $this->convertOne(['name' => 'ping'], [$owner]);

		$this->assertSame([], $info->parameters);
		$this->assertSame([], $info->requiredParameters);
		$this->assertSame('', $info->description);
		$this->assertSame($owner, $info->instance);
	}//end testFunctionWithoutPropertiesHasNoParameters()

	/**
	 * The owning tool is the first tool declaring a function of that name.
	 *
	 * @return void
	 */
	public function testOwningToolIsTheOneDeclaringTheFunction(): void {
		$other = $this->tool([['name' => 'x'], ['name' => 'y']]);
		$owner = $this->tool([['name' => 'z']]);
		$later = $this->tool([['name' => 'z']]);

		$this->assertSame($owner, $this->convertOne(['name' => 'z'], [$other, $owner, $later])->instance);
	}//end testOwningToolIsTheOneDeclaringTheFunction()

	/**
	 * A dotted tool id is looked up only as-is, never with the openregister prefix.
	 *
	 * @return void
	 */
	public function testDottedIdIsNotPrefixed(): void {
		$agent = new Agent();
		$agent->setTools(['other.tool']);
		$this->registry->expects($this->once())->method('getTool')->with('other.tool')->willReturn(null);
		$this->logger->expects($this->once())->method('warning')
			->with('[ToolManagementHandler] Tool not found', $this->callback(static fn (array $c): bool => $c['id'] === 'other.tool'));

		$this->assertSame([], $this->handler->getAgentTools($agent));
	}//end testDottedIdIsNotPrefixed()

	/**
	 * A bare id falls back to the openregister-prefixed id and binds the agent.
	 *
	 * @return void
	 */
	public function testBareIdFallsBackToPrefixedId(): void {
		$agent = new Agent();
		$agent->setTools(['objects']);
		$tool = $this->createMock(ToolInterface::class);
		$tool->expects($this->once())->method('setAgent')->with($agent);
		$this->registry->expects($this->exactly(2))->method('getTool')
			->willReturnCallback(static fn (string $id): ?ToolInterface => $id === 'openregister.objects' ? $tool : null);

		$this->assertSame([$tool], $this->handler->getAgentTools($agent));
	}//end testBareIdFallsBackToPrefixedId()
	/**
	 * No agent means no tools, without touching the registry.
	 *
	 * @return void
	 */
	public function testNoAgentGivesNoTools(): void {
		$this->registry->expects($this->never())->method('getTool');

		$this->assertSame([], $this->handler->getAgentTools(null, ['x']));
	}//end testNoAgentGivesNoTools()

	/**
	 * A selection narrows the agent's enabled tools to the intersection and logs the counts.
	 *
	 * @return void
	 */
	public function testSelectionNarrowsEnabledTools(): void {
		$agent = new Agent();
		$agent->setTools(['a.one', 'a.two', 'a.three']);
		$two = $this->createMock(ToolInterface::class);
		$this->registry->expects($this->once())->method('getTool')->with('a.two')->willReturn($two);
		$this->logger->expects($this->once())->method('info')
			->with(
				'[ToolManagementHandler] Filtering tools',
				$this->callback(
					static fn (array $c): bool => $c['agentTools'] === 3 && $c['selectedTools'] === 2 && $c['filteredTools'] === 1
				)
			);

		$this->assertSame([$two], $this->handler->getAgentTools($agent, ['a.two', 'z.none']));
	}//end testSelectionNarrowsEnabledTools()
	/**
	 * An agent with an empty tool list yields no tools, even when a selection is given.
	 *
	 * @return void
	 */
	public function testAgentWithoutToolsGivesNoTools(): void {
		$agent = new Agent();
		$agent->setTools([]);
		$this->registry->expects($this->never())->method('getTool');
		$this->logger->expects($this->never())->method('info');

		$this->assertSame([], $this->handler->getAgentTools($agent, ['a.one']));
	}//end testAgentWithoutToolsGivesNoTools()
}//end class
