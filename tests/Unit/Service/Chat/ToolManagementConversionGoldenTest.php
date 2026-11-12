<?php

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

/**
 * Golden test for the function conversions of ToolManagementHandler.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  Unit\Service\Chat
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://www.OpenRegister.nl
 */

namespace Unit\Service\Chat;

use LLPhant\Chat\FunctionInfo\FunctionInfo;
use LLPhant\Chat\FunctionInfo\Parameter;
use OCA\OpenRegister\Mcp\Service\Chat\ToolManagementHandler;
use OCA\OpenRegister\Mcp\Service\ToolRegistry;
use OCA\OpenRegister\Mcp\Tool\ToolInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins convertToolsToFunctions() and convertFunctionsToFunctionInfo().
 *
 * Each case is a list of tools (their function definitions). The flattened
 * functions and every FunctionInfo (name, owning tool, description, the
 * Parameter tree and the required parameters) are compared with
 * fixtures/tool-management-conversion-golden.json. Set UPDATE_GOLDEN=1 to rewrite it.
 */
class ToolManagementConversionGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/tool-management-conversion-golden.json';

	/**
	 * @return array<string, array{0: list<list<array<string, mixed>>>}>
	 */
	public static function cases(): array {
		$rich = [
			'name' => 'search_objects',
			'description' => 'Search objects',
			'parameters' => [
				'type' => 'object',
				'properties' => [
					'query' => ['type' => 'string', 'description' => 'Text'],
					'limit' => ['type' => 'integer', 'enum' => [10, 20], 'format' => 'int32'],
					'filters' => ['type' => 'object', 'description' => 'Filters', 'properties' => ['status' => ['type' => 'string'], 'deep' => ['type' => 'object', 'properties' => ['x' => ['type' => 'number']]]]],
					'free' => ['type' => 'object', 'description' => 'Free-form'],
					'freeNoDescription' => ['type' => 'object', 'properties' => []],
					'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
					'ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
					'rows' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['a' => ['type' => 'boolean', 'description' => 'A'], 'b' => 'not a def']]],
					'blobs' => ['type' => 'array', 'items' => ['type' => 'object']],
					'untyped' => ['type' => 'array'],
					'itemsScalar' => ['type' => 'array', 'items' => 'string'],
					'itemsNoType' => ['type' => 'array', 'items' => ['description' => 'x']],
					'noType' => ['description' => 'Defaults to string'],
					'notArray' => 'string',
					7 => ['type' => 'string'],
				],
				'required' => ['query', 'rows', 'missing', 'filters', '7'],
			],
		];
		$plain = ['name' => 'ping', 'parameters' => []];
		$noParams = ['name' => 'list_registers', 'description' => 'List'];
		$requiredOnly = ['name' => 'get_object', 'description' => 'Get', 'parameters' => ['required' => ['id'], 'properties' => null]];
		$nullDescription = ['name' => 'orphan', 'parameters' => ['properties' => ['id' => ['type' => 'string']], 'required' => ['id']]];
		return [
			'no tools' => [[]],
			'one rich tool' => [[[$rich, $plain]]],
			'several tools' => [[[$noParams], [$requiredOnly, $rich], [$plain]]],
			'function names repeat' => [[[$plain], [$plain, $noParams]]],
			'function without owner' => [[[$plain]], [$nullDescription, $plain]],
		];
	}//end cases()

	/**
	 * @param list<list<array<string, mixed>>> $toolFunctions
	 * @param list<array<string, mixed>>|null  $extraFunctions Functions converted besides the tools' own.
	 */
	#[DataProvider('cases')]
	public function testConversionsMatchGolden(array $toolFunctions, ?array $extraFunctions = null): void {
		$tools = [];
		foreach ($toolFunctions as $functions) {
			$tool = $this->createMock(ToolInterface::class);
			$tool->method('getFunctions')->willReturn($functions);
			$tools[] = $tool;
		}

		$handler = new ToolManagementHandler(
			$this->createMock(ToolRegistry::class),
			$this->createMock(LoggerInterface::class)
		);
		$functions = $handler->convertToolsToFunctions($tools);
		$infos = $handler->convertFunctionsToFunctionInfo($extraFunctions ?? $functions, $tools);
		$actual = json_decode(json_encode([
			'functions' => $functions,
			'infos' => array_map(fn (FunctionInfo $info): array => $this->describeInfo($info, $tools), $infos),
		]), true);

		$name = $this->dataName();
		$golden = [];
		if (is_file(self::FIXTURE) === true) {
			$golden = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			$golden[$name] = $actual;
			ksort($golden);
			file_put_contents(self::FIXTURE, json_encode($golden, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$this->assertArrayHasKey($name, $golden, 'No golden recording for this case.');
		$this->assertSame($golden[$name], $actual);
	}//end testConversionsMatchGolden()

	/**
	 * @param list<ToolInterface> $tools
	 *
	 * @return array<string, mixed>
	 */
	private function describeInfo(FunctionInfo $info, array $tools): array {
		$owner = array_search($info->instance, $tools, true);
		return [
			'name' => $info->name,
			'owner' => ($owner === false ? null : $owner),
			'description' => $info->description,
			'parameters' => array_map(fn (Parameter $p): array => $this->describeParameter($p), $info->parameters),
			'required' => array_map(fn (Parameter $p): array => ['name' => $p->name, 'same' => in_array($p, $info->parameters, true)], $info->requiredParameters),
		];
	}//end describeInfo()

	/**
	 * @return array<string, mixed>
	 */
	private function describeParameter(Parameter $parameter): array {
		$nested = $parameter->itemsOrProperties;
		if (is_array($nested) === true) {
			$nested = array_map(fn (Parameter $p): array => $this->describeParameter($p), $nested);
		}

		return [
			'name' => $parameter->name,
			'type' => $parameter->type,
			'description' => $parameter->description,
			'enum' => $parameter->enum,
			'format' => $parameter->format,
			'itemsOrProperties' => $nested,
		];
	}//end describeParameter()
}//end class
