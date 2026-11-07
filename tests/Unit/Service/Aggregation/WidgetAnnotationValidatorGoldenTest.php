<?php

/**
 * Golden test for WidgetAnnotationValidator.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Aggregation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/aggregations-backend-native/spec.md
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Aggregation;

use OCA\OpenRegister\Service\Aggregation\WidgetAnnotationValidator;
use PHPUnit\Framework\TestCase;

/**
 * Runs every schema below through validate() and compares the full grid of
 * findings (code and message, in order) with
 * fixtures/widget-annotation-validator-golden.json. Set UPDATE_GOLDEN=1 to
 * rewrite the fixture.
 *
 * @covers \OCA\OpenRegister\Service\Aggregation\WidgetAnnotationValidator
 */
class WidgetAnnotationValidatorGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/widget-annotation-validator-golden.json';

	private const AGG = ['mode' => 'aggregation', 'register' => 'r', 'schema' => 's', 'aggregation' => 'a'];

	/**
	 * A widget that passes, with the given fields replaced.
	 *
	 * @param array<string, mixed> $overrides Fields to replace.
	 *
	 * @return array<string, mixed>
	 */
	private static function widget(array $overrides = []): array {
		return array_merge(['type' => 'kpi', 'title' => 'Open zaken', 'dataSource' => self::AGG], $overrides);
	}//end widget()

	/**
	 * Every case: the schema handed to validate().
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function cases(): array {
		$one = static fn (array $overrides): array => ['x-openregister-widgets' => [self::widget($overrides)]];
		return [
			'no annotation' => ['title' => 'x'],
			'null annotation' => ['x-openregister-widgets' => null],
			'string annotation' => ['x-openregister-widgets' => 'kpi'],
			'empty annotation' => ['x-openregister-widgets' => []],
			'keyed annotation' => ['x-openregister-widgets' => ['first' => 'x', 'second' => self::widget(['type' => 'pie'])]],
			'list with malformed entries' => ['x-openregister-widgets' => ['x', 5, null, self::widget(), []]],
			'every type' => [
				'x-openregister-widgets' => array_map(
					static fn (string $type): array => self::widget(['type' => $type]),
					['kpi', 'chart', 'table', 'stats', 'sparkline', 'tile', 'KPI', '']
				),
			],
			'type missing' => ['x-openregister-widgets' => [array_diff_key(self::widget(), ['type' => 1])]],
			'type integer' => $one(['type' => 5]),
			'title missing' => ['x-openregister-widgets' => [array_diff_key(self::widget(), ['title' => 1])]],
			'title empty' => $one(['title' => '']),
			'title zero' => $one(['title' => 0]),
			'title null' => $one(['title' => null]),
			'data source missing' => ['x-openregister-widgets' => [array_diff_key(self::widget(), ['dataSource' => 1])]],
			'data source string' => $one(['dataSource' => 'aggregation', 'options' => 'x']),
			'mode missing' => $one(['dataSource' => [], 'options' => 'x']),
			'mode unknown' => $one(['dataSource' => ['mode' => 'sql'], 'options' => 'x']),
			'aggregation empty' => $one(['dataSource' => ['mode' => 'aggregation']]),
			'aggregation partial' => $one(['dataSource' => ['mode' => 'aggregation', 'register' => 'r', 'schema' => '', 'aggregation' => 0]]),
			'aggregation ignores graphql query' => $one(['dataSource' => self::AGG + ['graphqlQuery' => '']]),
			'graphql without query' => $one(['dataSource' => ['mode' => 'graphql']]),
			'graphql empty query' => $one(['dataSource' => ['mode' => 'graphql', 'graphqlQuery' => '']]),
			'graphql with query' => $one(['dataSource' => ['mode' => 'graphql', 'graphqlQuery' => '{ a }']]),
			'statistics' => $one(['dataSource' => ['mode' => 'statistics']]),
			'options string' => $one(['options' => 'x']),
			'options null' => $one(['options' => null]),
			'options object' => $one(['options' => ['colour' => 'red']]),
			'options after incomplete source' => $one(['dataSource' => ['mode' => 'graphql'], 'options' => 3]),
			'everything wrong' => $one(['type' => 'pie', 'title' => '', 'dataSource' => ['mode' => 'aggregation', 'register' => 'r'], 'options' => 'x']),
			'nothing but a stop' => $one(['type' => 'pie', 'title' => '', 'dataSource' => null, 'options' => 'x']),
		];
	}//end cases()

	/**
	 * @return void
	 */
	public function testFindingsMatchTheGoldenGrid(): void {
		$validator = new WidgetAnnotationValidator();
		$actual = [];
		foreach (self::cases() as $name => $schema) {
			$actual[$name] = $validator->validate($schema);
		}

		$json = json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, $json);
		}

		$this->assertFileExists(self::FIXTURE);
		$this->assertSame(file_get_contents(self::FIXTURE), $json);
	}//end testFindingsMatchTheGoldenGrid()
}//end class
