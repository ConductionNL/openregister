<?php

/**
 * SchemaServiceComparisonGoldenTest
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaCatalog;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\SchemaService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Pins every branch of the property comparison steps of schema exploration
 * (type, maxLength, nullable, enum, PHP type normalisation) by exact output.
 */
class SchemaServiceComparisonGoldenTest extends TestCase {

	private SchemaService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->service = new SchemaService(
			new SchemaCatalog(
				registers: $this->createMock(RegisterMapperInterface::class),
				schemas: $this->createMock(SchemaMapper::class),
				tables: $this->createMock(MagicMapper::class)
			),
			$this->createMock(LoggerInterface::class)
		);
	}

	/**
	 * Call a private method by its named arguments.
	 *
	 * @param string               $method    The method.
	 * @param array<string, mixed> $arguments Its named arguments.
	 *
	 * @return mixed The result.
	 */
	private function call(string $method, array $arguments): mixed {
		return (new ReflectionMethod(SchemaService::class, $method))->invoke($this->service, ...$arguments);
	}

	public function testCompareTypeBranches(): void {
		$this->assertSame(
			['issues' => [], 'suggestions' => [['type' => 'type', 'field' => 'type', 'current' => null, 'recommended' => 'string', 'description' => "Consider adding type 'string' based on analysis"]]],
			$this->call('compareType', ['currentConfig' => [], 'recommendedType' => 'string'])
		);
		$this->assertSame(
			['issues' => ["Type mismatch: current type is 'integer', recommended type is 'string'"], 'suggestions' => [['type' => 'type', 'field' => 'type', 'current' => 'integer', 'recommended' => 'string', 'description' => "Analysis suggests type 'string' but schema defines 'integer'"]]],
			$this->call('compareType', ['currentConfig' => ['type' => 'integer'], 'recommendedType' => 'string'])
		);
		$this->assertSame(
			['issues' => ["Type mismatch: current type is 'integer, null', recommended type is 'string'"], 'suggestions' => [['type' => 'type', 'field' => 'type', 'current' => ['integer', 'null'], 'recommended' => 'string', 'description' => "Analysis suggests type 'string' but schema defines 'integer, null'"]]],
			$this->call('compareType', ['currentConfig' => ['type' => ['integer', 'null']], 'recommendedType' => 'string'])
		);
		$this->assertSame(['issues' => [], 'suggestions' => []], $this->call('compareType', ['currentConfig' => ['type' => ['string', 'null']], 'recommendedType' => 'string']));
		$this->assertSame(['issues' => [], 'suggestions' => []], $this->call('compareType', ['currentConfig' => ['type' => 'string'], 'recommendedType' => 'string']));
	}

	public function testMaxLengthFindingBranches(): void {
		$this->assertNull($this->call('maxLengthFinding', ['currentConfig' => [], 'analysis' => []]));
		$this->assertNull($this->call('maxLengthFinding', ['currentConfig' => [], 'analysis' => ['max_length' => 0]]));
		$this->assertSame(
			['missing_max_length', ['type' => 'constraint', 'field' => 'maxLength', 'current' => 'unlimited', 'recommended' => 1000, 'description' => 'Objects have max length of 700 characters']],
			$this->call('maxLengthFinding', ['currentConfig' => ['maxLength' => 0], 'analysis' => ['max_length' => 700]])
		);
		$this->assertSame(
			['missing_max_length', ['type' => 'constraint', 'field' => 'maxLength', 'current' => 'unlimited', 'recommended' => 20, 'description' => 'Objects have max length of 10 characters']],
			$this->call('maxLengthFinding', ['currentConfig' => [], 'analysis' => ['max_length' => 10]])
		);
		$this->assertSame(
			['max_length_too_small', ['type' => 'constraint', 'field' => 'maxLength', 'current' => 5, 'recommended' => 10, 'description' => 'Schema maxLength (5) is smaller than observed max (10)']],
			$this->call('maxLengthFinding', ['currentConfig' => ['maxLength' => 5], 'analysis' => ['max_length' => 10]])
		);
		$this->assertNull($this->call('maxLengthFinding', ['currentConfig' => ['maxLength' => 10], 'analysis' => ['max_length' => 10]]));
	}

	public function testCompareNullableConstraintBranches(): void {
		$empty = ['issues' => [], 'suggestions' => []];
		$this->assertSame($empty, $this->call('compareNullableConstraint', ['currentConfig' => ['required' => true, 'type' => 'string'], 'analysis' => []]));
		$this->assertSame(
			[
				'issues' => ['Property contains null values but is marked as required'],
				'suggestions' => [
					['type' => 'behavior', 'field' => 'required', 'current' => 'true', 'recommended' => 'false', 'description' => 'Some objects have null values for this property'],
					['type' => 'type', 'field' => 'type', 'current' => 'string', 'recommended' => ['string', 'null'], 'description' => 'Consider making this property nullable since data contains null values'],
				],
			],
			$this->call('compareNullableConstraint', ['currentConfig' => ['required' => true, 'type' => 'string'], 'analysis' => ['nullable' => true]])
		);
		$this->assertSame(
			['issues' => [], 'suggestions' => [['type' => 'type', 'field' => 'type', 'current' => 'integer', 'recommended' => ['integer', 'null'], 'description' => 'Consider making this property nullable since data contains null values']]],
			$this->call('compareNullableConstraint', ['currentConfig' => ['type' => 'integer'], 'analysis' => ['nullable_variation' => true]])
		);
		$this->assertSame($empty, $this->call('compareNullableConstraint', ['currentConfig' => ['type' => 'null'], 'analysis' => ['nullable' => true]]));
		$this->assertSame($empty, $this->call('compareNullableConstraint', ['currentConfig' => ['required' => 'yes'], 'analysis' => ['nullable' => true]]));
		$this->assertSame($empty, $this->call('compareNullableConstraint', ['currentConfig' => [], 'analysis' => ['nullable' => 1, 'nullable_variation' => 1]]));
	}

	public function testCompareEnumConstraintBranches(): void {
		$empty = ['issues' => [], 'suggestions' => []];
		$this->assertSame($empty, $this->call('compareEnumConstraint', ['currentConfig' => [], 'analysis' => []]));
		$this->assertSame($empty, $this->call('compareEnumConstraint', ['currentConfig' => [], 'analysis' => ['enum_values' => 'a']]));
		$this->assertSame($empty, $this->call('compareEnumConstraint', ['currentConfig' => [], 'analysis' => ['enum_values' => range(1, 21)]]));
		$this->assertSame(
			['issues' => [], 'suggestions' => [['type' => 'enum', 'field' => 'enum', 'current' => 'unlimited', 'recommended' => 'a, b', 'description' => 'Property appears to have predefined values: a, b']]],
			$this->call('compareEnumConstraint', ['currentConfig' => ['enum' => []], 'analysis' => ['enum_values' => ['a', 'b']]])
		);
		$this->assertSame(
			['issues' => ['Enum values in schema differ from values found in data'], 'suggestions' => [['type' => 'enum', 'field' => 'enum', 'current' => 'c, a', 'recommended' => 'a, b', 'description' => 'Data contains enum values not defined in schema']]],
			$this->call('compareEnumConstraint', ['currentConfig' => ['enum' => ['c', 'a']], 'analysis' => ['enum_values' => ['a', 'b']]])
		);
		$this->assertSame($empty, $this->call('compareEnumConstraint', ['currentConfig' => ['enum' => ['b', 'a']], 'analysis' => ['enum_values' => ['a', 'b']]]));
	}

	public function testNormalizeSingleTypeBranches(): void {
		$cases = [
			['string', ['integer_string', 'boolean_string'], 'integer'],
			['String', ['float_string', 'boolean_string'], 'number'],
			['string', ['boolean_string'], 'boolean'],
			['string', [], 'string'],
			['integer', ['float_string'], 'integer'],
			['DOUBLE', [], 'number'],
			['float', [], 'number'],
			['boolean', [], 'boolean'],
			['array', [], 'array'],
			['object', [], 'object'],
			['NULL', [], 'null'],
			['number', [], 'number'],
			['resource', [], 'string'],
			['0', [], 'string'],
			['', [], 'string'],
		];
		foreach ($cases as [$phpType, $patterns, $expected]) {
			$this->assertSame($expected, $this->call('normalizeSingleType', ['phpType' => $phpType, 'patterns' => $patterns]), $phpType);
		}
	}
}//end class
