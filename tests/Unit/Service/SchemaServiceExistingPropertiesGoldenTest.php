<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\SchemaService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use OCA\OpenRegister\Db\SchemaCatalog;
use OCA\OpenRegister\Db\RegisterMapperInterface;

/**
 * Records the improvements analyzeExistingProperties() reports, per scenario, against
 * fixtures/schema-existing-properties-golden.json. Set UPDATE_GOLDEN=1 to rewrite it.
 *
 * @covers \OCA\OpenRegister\Service\SchemaService
 */
class SchemaServiceExistingPropertiesGoldenTest extends TestCase {

	/**
	 * One property analysis with defaults.
	 *
	 * @param array $over Values that differ from the defaults.
	 *
	 * @return array The analysis.
	 */
	private static function analysis(array $over): array {
		return $over + [
			'usage_percentage' => 60, 'usage_count' => 6, 'max_length' => 12, 'min_length' => 3,
			'types' => ['string'], 'examples' => ['a1', 'b22', 'c333', 'd4444'],
		];
	}

	/**
	 * Scenario table: [existing properties, discovered properties].
	 *
	 * @return array<string, array>
	 */
	public static function scenarios(): array {
		$a = fn (array $over = []) => self::analysis($over);
		$num = fn (array $range, array $over = []) => $a($over + ['types' => ['integer'], 'max_length' => 0, 'examples' => [1, 2, 3], 'numeric_range' => $range]);
		$existing = [
			'untyped' => [],
			'wrongType' => ['type' => 'integer'],
			'noMax' => ['type' => 'string'],
			'zeroMax' => ['type' => 'string', 'maxLength' => 0],
			'smallMax' => ['type' => 'string', 'maxLength' => 5],
			'bigMax' => ['type' => 'string', 'maxLength' => 50, 'format' => 'email', 'pattern' => 'x'],
			'fmt' => ['type' => 'string', 'maxLength' => 50, 'format' => ''],
			'pat' => ['type' => 'string', 'maxLength' => 50, 'format' => 'uri', 'pattern' => ''],
			'stringButNumbers' => ['type' => 'string', 'maxLength' => 50],
			'numMinFalse' => ['type' => 'integer', 'minimum' => false, 'maximum' => false],
			'numMinFalseFlat' => ['type' => 'integer', 'minimum' => false, 'maximum' => false],
			'numTight' => ['type' => 'number', 'minimum' => 5, 'maximum' => 6],
			'numLoose' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
			'numUnset' => ['type' => 'integer'],
			'numNoRange' => ['type' => 'integer'],
			'numAsString' => ['type' => 'integer'],
			'nullable' => ['type' => 'string', 'required' => true, 'maxLength' => 50],
			'enumish' => ['type' => 'string', 'maxLength' => 50],
			'clean' => ['type' => 'string', 'maxLength' => 50],
			'unknown' => ['type' => 'string'],
			'numEqual' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 9],
			'maxEqual' => ['type' => 'string', 'maxLength' => 12],
			'emptyFormat' => ['type' => 'string', 'maxLength' => 50],
			'stringNumWithLength' => ['type' => 'string'],
		];
		$discovered = [
			'untyped' => $a(['usage_percentage' => 95]),
			'wrongType' => $a(['usage_percentage' => 80, 'types' => ['string', 'integer']]),
			'noMax' => $a(['usage_percentage' => 50]),
			'zeroMax' => $a(['usage_percentage' => 49, 'max_length' => 700]),
			'smallMax' => $a(['usage_percentage' => 79]),
			'bigMax' => $a(['detected_format' => 'email', 'string_patterns' => ['lower']]),
			'fmt' => $a(['detected_format' => 'uri']),
			'pat' => $a(['detected_format' => '', 'string_patterns' => ['upper', 'digits']]),
			'stringButNumbers' => $num(['min' => 1, 'max' => 9]),
			'numMinFalse' => $num(['min' => 1, 'max' => 9]),
			'numMinFalseFlat' => $num(['min' => 4, 'max' => 4]),
			'numTight' => $num(['min' => 1, 'max' => 9], ['types' => ['double']]),
			'numLoose' => $num(['min' => 1, 'max' => 9]),
			'numUnset' => $num(['min' => -3, 'max' => 9]),
			'numNoRange' => $num(['min' => 0, 'max' => 0], ['numeric_range' => null]),
			'numAsString' => $a(['numeric_range' => ['min' => 2, 'max' => 8], 'min_length' => PHP_INT_MAX]),
			'nullable' => $a(['nullable' => true]),
			'enumish' => $a(['examples' => ['open', 'open', 'closed', 'open', 'closed', 'open', 'open', 'closed', 'open', 'open']]),
			'clean' => $a(['max_length' => 0]),
			'onlyDiscovered' => $a(),
			'numEqual' => $num(['min' => 1, 'max' => 9]),
			'maxEqual' => $a(),
			'emptyFormat' => $a(['detected_format' => '']),
			'stringNumWithLength' => $num(['min' => 1, 'max' => 9], ['max_length' => 5]),
		];
		$scenarios = ['all at once' => [$existing, $discovered], 'nothing in common' => [['x' => []], ['y' => $a()]]];
		foreach ($existing as $name => $config) {
			if (isset($discovered[$name]) === true) {
				$scenarios[$name] = [[$name => $config], [$name => $discovered[$name]]];
			}
		}
		return $scenarios;
	}

	/**
	 * @dataProvider scenarios
	 */
	public function testExistingPropertiesGolden(array $existing, array $discovered): void {
		$service = new SchemaService(
			new SchemaCatalog(registers: $this->createMock(RegisterMapperInterface::class), schemas: $this->createMock(SchemaMapper::class), tables: $this->createMock(MagicMapper::class)),
			$this->createMock(LoggerInterface::class)
		);
		$actual = (new ReflectionMethod(SchemaService::class, 'analyzeExistingProperties'))->invoke($service, $existing, $discovered);
		$actual = json_decode(json_encode($actual, JSON_UNESCAPED_UNICODE), true);

		$file = __DIR__ . '/fixtures/schema-existing-properties-golden.json';
		$name = $this->dataName();
		$all = is_file($file) === true ? json_decode(file_get_contents($file), true) : [];
		if (getenv('UPDATE_GOLDEN') === '1') {
			$all[$name] = $actual;
			file_put_contents($file, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
		}
		$this->assertSame($all[$name], $actual);
	}
}
