<?php

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

/**
 * Golden test for McpAnnotationValidator.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Mcp
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://www.OpenRegister.nl
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Mcp;

use OCA\OpenRegister\Mcp\Service\Mcp\McpAnnotationValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins validate(): every error code and message, in order, for each schema shape.
 *
 * The errors are compared with fixtures/mcp-annotation-validator-golden.json.
 * Set UPDATE_GOLDEN=1 to rewrite it.
 *
 * @covers \OCA\OpenRegister\Mcp\Service\Mcp\McpAnnotationValidator
 * @uses   \OCA\OpenRegister\Mcp\Service\Mcp\McpVerbAnnotation
 */
class McpAnnotationValidatorGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/mcp-annotation-validator-golden.json';

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		$props = ['title' => ['type' => 'string'], 'status' => ['type' => 'string'], '0' => ['type' => 'string']];
		$full = [
			'search' => ['description' => 'Find', 'scope' => 'read', 'filters' => ['title', 'status'], 'readOnlyHint' => true],
			'get' => ['scope' => 'read', 'idempotentHint' => false],
			'create' => ['scope' => 'create', 'destructiveHint' => false],
			'update' => ['scope' => 'update'],
			'delete' => ['scope' => 'delete', 'destructiveHint' => true],
		];
		return [
			'no annotation' => [['properties' => $props]],
			'null annotation' => [['x-openregister-mcp' => null]],
			'string annotation' => [['x-openregister-mcp' => 'yes']],
			'empty annotation' => [['x-openregister-mcp' => []]],
			'enabled only' => [['x-openregister-mcp' => ['enabled' => true]]],
			'enabled string' => [['x-openregister-mcp' => ['enabled' => 'true']]],
			'enabled null' => [['x-openregister-mcp' => ['enabled' => null, 'tools' => []]]],
			'enabled int' => [['x-openregister-mcp' => ['enabled' => 1, 'tools' => null]]],
			'tools string' => [['x-openregister-mcp' => ['enabled' => false, 'tools' => 'all']]],
			'full valid' => [['properties' => $props, 'x-openregister-mcp' => ['enabled' => true, 'tools' => $full]]],
			'properties not array' => [['properties' => 'x', 'x-openregister-mcp' => ['enabled' => true, 'tools' => ['search' => ['filters' => ['title']]]]]],
			'unknown verbs and bad configs' => [[
				'properties' => $props,
				'x-openregister-mcp' => [
					'tools' => [
						'list' => [],
						0 => ['scope' => 'read'],
						'get' => 'yes',
						'create' => null,
						'search' => [],
					],
				],
			]],
			'every key error' => [[
				'properties' => $props,
				'x-openregister-mcp' => [
					'enabled' => 'no',
					'tools' => [
						'search' => [
							'description' => 5,
							'scope' => 'write',
							'filters' => ['title', 7, 'missing', 0, '0', null, 'status'],
							'readOnlyHint' => 'yes',
							'destructiveHint' => null,
							'idempotentHint' => 1,
							'extra' => true,
							3 => 'x',
						],
						'get' => ['filters' => ['title'], 'scope' => null, 'description' => null],
						'update' => ['filters' => 'title', 'title' => 'x'],
						'delete' => ['scope' => 'READ', 'description' => ''],
					],
				],
			]],
			'search filters scalar' => [['properties' => $props, 'x-openregister-mcp' => ['enabled' => true, 'tools' => ['search' => ['filters' => 'title']]]]],
			'search filters null' => [['properties' => $props, 'x-openregister-mcp' => ['enabled' => true, 'tools' => ['search' => ['filters' => null]]]]],
			'search filters keyed' => [['properties' => $props, 'x-openregister-mcp' => ['enabled' => true, 'tools' => ['search' => ['filters' => ['a' => 'title', 'b' => 'nope', 'c' => []]]]]]],
		];
	}//end cases()

	/**
	 * @param array<string, mixed> $schema
	 */
	#[DataProvider('cases')]
	public function testValidateMatchesGolden(array $schema): void {
		$actual = (new McpAnnotationValidator())->validate($schema);
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
	}//end testValidateMatchesGolden()
}//end class
