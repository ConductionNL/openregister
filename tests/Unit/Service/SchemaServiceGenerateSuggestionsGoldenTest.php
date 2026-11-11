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
 * Records the suggestions generateSuggestions() makes, per scenario, against
 * fixtures/schema-generate-suggestions-golden.json. Set UPDATE_GOLDEN=1 to rewrite it.
 *
 * @covers \OCA\OpenRegister\Service\SchemaService
 */
class SchemaServiceGenerateSuggestionsGoldenTest extends TestCase {

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
	 * Scenario table.
	 *
	 * @return array<string, array>
	 */
	public static function scenarios(): array {
		$a = fn (array $over = []) => self::analysis($over);
		return [
			'nothing discovered' => [[], []],
			'mixed set' => [
				[
					'title' => $a(['usage_percentage' => 95, 'detected_format' => 'email', 'string_patterns' => ['lower']]),
					'existing' => $a(),
					'_internal' => $a(),
					'id' => $a(),
					'status' => $a(['usage_percentage' => 80, 'examples' => ['open', 'open', 'closed', 'open', 'closed', 'open', 'open', 'closed', 'open', 'open']]),
					'count' => $a(['usage_percentage' => 50, 'types' => ['integer', 'string'], 'max_length' => 0, 'min_length' => PHP_INT_MAX, 'numeric_range' => ['min' => 1, 'max' => 9]]),
					'address' => $a(['usage_percentage' => 49, 'object_structure' => ['type' => 'object', 'keys' => ['street', 'city']]]),
					'tags' => $a(['usage_percentage' => 20, 'array_structure' => ['type' => 'list', 'item_types' => ['string' => 3]], 'types' => ['array']]),
					'meta' => $a(['usage_percentage' => 20, 'object_structure' => ['type' => 'scalar'], 'array_structure' => ['type' => 'map']]),
					'long' => $a(['usage_percentage' => 79, 'max_length' => 900, 'types' => ['string']]),
					'nopct' => array_diff_key($a(['min_length' => null]), ['usage_percentage' => 1]),
				],
				['existing' => ['type' => 'string']],
			],
		];
	}

	/**
	 * @dataProvider scenarios
	 */
	public function testGenerateSuggestionsGolden(array $discovered, array $existing): void {
		$service = new SchemaService(
			new SchemaCatalog(registers: $this->createMock(RegisterMapperInterface::class), schemas: $this->createMock(SchemaMapper::class), tables: $this->createMock(MagicMapper::class)),
			$this->createMock(LoggerInterface::class)
		);
		$actual = (new ReflectionMethod(SchemaService::class, 'generateSuggestions'))->invoke($service, $discovered, $existing);
		$actual = json_decode(json_encode($actual, JSON_UNESCAPED_UNICODE), true);

		$file = __DIR__ . '/fixtures/schema-generate-suggestions-golden.json';
		$name = $this->dataName();
		$all = is_file($file) === true ? json_decode(file_get_contents($file), true) : [];
		if (getenv('UPDATE_GOLDEN') === '1') {
			$all[$name] = $actual;
			file_put_contents($file, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
		}
		$this->assertSame($all[$name], $actual);
	}
}
