<?php

/**
 * Golden characterization test for ScheduledFilterParser::parse().
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Service\Notification;

use OCA\OpenRegister\Service\Notification\ScheduledFilterParser;
use PHPUnit\Framework\TestCase;

/**
 * Parses a fixed corpus of filters and compares every AST and error list
 * with `fixtures/scheduled-filter-parser-golden.json`. Set
 * `OR_GOLDEN_WRITE=1` to regenerate the fixture.
 *
 * @covers \OCA\OpenRegister\Service\Notification\ScheduledFilterParser
 * @uses   \OCA\OpenRegister\Service\Notification\ScheduledFilterGrammar
 * @uses   \OCA\OpenRegister\Service\Notification\ScheduledFilterPosition
 */
final class ScheduledFilterParserGoldenTest extends TestCase {

	/**
	 * Path of the golden file.
	 *
	 * @return string
	 */
	private static function goldenPath(): string {
		return __DIR__.'/fixtures/scheduled-filter-parser-golden.json';
	}//end goldenPath()

	/**
	 * The filters, by case name.
	 *
	 * @return array<string, array<string|int, mixed>>
	 */
	private static function corpus(): array {
		$deep = ['field' => 'x', 'operator' => 'equals', 'value' => 1];
		for ($level = 0; $level < 6; $level++) {
			$deep = ['all' => [$deep]];
		}

		return [
			'empty' => [],
			'scalars' => ['status' => 'open', 'count' => 3, 'flag' => true, 'none' => null, 7 => 'numeric-key'],
			'bare-list' => ['status' => ['open', 'closed']],
			'empty-list' => ['status' => []],
			'op-key' => ['status' => ['op' => 'equals', 'value' => 'x']],
			'op-key-array' => ['status' => ['op' => ['a'], 'value' => 'x']],
			'no-operator' => ['status' => ['value' => 'x']],
			'unknown-operator' => ['status' => ['operator' => 'like', 'value' => 'x']],
			'numeric-operator' => ['status' => ['operator' => 5, 'value' => 'x']],
			'missing-value' => ['status' => ['operator' => 'equals']],
			'missing-values' => ['status' => ['operator' => 'in', 'value' => ['a']]],
			'equals-ok' => ['status' => ['operator' => 'equals', 'value' => 'open', 'field' => 'ignored']],
			'not-equals-array' => ['status' => ['operator' => 'notEquals', 'value' => ['a']]],
			'equals-null' => ['status' => ['operator' => 'equals', 'value' => null]],
			'in-ok' => ['status' => ['operator' => 'in', 'values' => ['a', 'b']]],
			'in-empty' => ['status' => ['operator' => 'in', 'values' => []]],
			'not-in-map' => ['status' => ['operator' => 'notIn', 'values' => ['a' => 1]]],
			'not-in-scalar' => ['status' => ['operator' => 'notIn', 'values' => 'a']],
			'within-ok' => ['due' => ['operator' => 'withinNext', 'value' => 'P7D']],
			'within-negative' => ['due' => ['operator' => 'withinNext', 'value' => '-PT24H']],
			'within-bad' => ['due' => ['operator' => 'withinNext', 'value' => '7 days']],
			'older-int' => ['due' => ['operator' => 'olderThan', 'value' => 7]],
			'older-empty' => ['due' => ['operator' => 'olderThan', 'value' => '']],
			'before-now' => ['due' => ['operator' => 'before', 'value' => 'now']],
			'before-date' => ['due' => ['operator' => 'before', 'value' => '2026-01-01']],
			'after-duration' => ['due' => ['operator' => 'after', 'value' => '-P7D']],
			'after-bad' => ['due' => ['operator' => 'after', 'value' => 'not a date at all']],
			'after-array' => ['due' => ['operator' => 'after', 'value' => ['x']]],
			'combinator-ok' => ['any' => [['field' => 'a', 'operator' => 'equals', 'value' => 1], ['field' => 'b', 'operator' => 'in', 'values' => [1]]]],
			'combinator-map' => ['all' => ['a' => 1]],
			'combinator-scalar' => ['any' => 'x'],
			'combinator-empty' => ['all' => []],
			'clause-not-array' => ['all' => ['x', 3]],
			'clause-no-field' => ['all' => [['operator' => 'equals', 'value' => 1]]],
			'clause-int-field' => ['all' => [['field' => 4, 'operator' => 'equals', 'value' => 1]]],
			'clause-bad-operator' => ['any' => [['field' => 'a', 'operator' => 'nope']]],
			'clause-op-key' => ['any' => [['field' => 'a', 'op' => 'equals', 'value' => 1]]],
			'nested' => ['all' => [['any' => [['field' => 'a', 'operator' => 'equals', 'value' => 1]], 'all' => [['field' => 'b', 'operator' => 'before', 'value' => 'now']]]]],
			'nested-errors' => ['all' => [['any' => ['bad', ['field' => 'a']]]]],
			'too-deep' => $deep,
			'mixed' => ['status' => 'open', 'any' => [['field' => 'a', 'operator' => 'equals', 'value' => 1]], 'tags' => ['x'], 'bad' => []],
			'many-errors' => ['a' => [], 'b' => ['operator' => 'x'], 'all' => 'y', 'c' => ['operator' => 'in', 'values' => []]],
		];
	}//end corpus()

	/**
	 * Every filter parses to the recorded AST and errors.
	 *
	 * @return void
	 */
	public function testParseMatchesGolden(): void {
		$parser = new ScheduledFilterParser();
		$actual = [];
		foreach (self::corpus() as $name => $filter) {
			$actual[$name] = $parser->parse($filter, 'rule-'.$name);
		}

		$actual['no-rule-key'] = $parser->parse(['status' => []]);
		$actual = json_decode((string)json_encode($actual), true);

		if (getenv('OR_GOLDEN_WRITE') === '1') {
			file_put_contents(self::goldenPath(), json_encode($actual, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n");
			$this->markTestSkipped('golden written');
		}

		$golden = json_decode((string)file_get_contents(self::goldenPath()), true);
		$this->assertSame($golden, $actual);
	}//end testParseMatchesGolden()
}//end class
