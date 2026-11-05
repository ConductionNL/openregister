<?php

/**
 * Golden test for MariaDbSearchHandler::applyJsonFieldFilter().
 *
 * Records every query builder call the filter makes for null checks, single
 * string and non-string values, lists of mixed values and an empty list.
 * Re-record with UPDATE_GOLDEN=1.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Db\ObjectHandlers
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db\ObjectHandlers;

use OCA\OpenRegister\Db\ObjectHandlers\MariaDbSearchHandler;
use OCA\OpenRegister\Tests\Unit\Db\RecordingQueryBuilderTrait;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @covers \OCA\OpenRegister\Db\ObjectHandlers\MariaDbSearchHandler
 */
class MariaDbJsonFieldFilterGoldenTest extends TestCase {
	use RecordingQueryBuilderTrait;

	private const FIXTURE = __DIR__ . '/fixtures/json-field-filter-golden.json';

	private static function cases(): array {
		return [
			'is not null' => ['address.city', 'IS NOT NULL'],
			'is null' => ['name', 'IS NULL'],
			'single string' => ['address.city', 'Den Haag'],
			'single int' => ['age', 42],
			'single bool' => ['active', true],
			'single null' => ['gone', null],
			'list of mixed' => ['tags', ['Alpha', 7, false, 'beta']],
			'empty list' => ['tags', []],
		];
	}//end cases()

	public function testJsonFieldFilterMatchesGolden(): void {
		$reflection = new ReflectionClass(MariaDbSearchHandler::class);
		$handler = $reflection->newInstanceWithoutConstructor();
		$method = $reflection->getMethod('applyJsonFieldFilter');
		$method->setAccessible(true);

		$actual = [];
		foreach (self::cases() as $name => [$field, $value]) {
			$this->queryLog = [];
			$this->builderCount = 0;
			$this->parameterCount = 0;
			$method->invoke($handler, $this->recordingBuilder(), $field, $value);
			$actual[$name] = $this->queryLog;
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);
		foreach ($expected as $name => $outcome) {
			$this->assertSame($outcome, $actual[$name] ?? null, $name);
		}

		$this->assertSame(array_keys($expected), array_keys($actual));
	}//end testJsonFieldFilterMatchesGolden()
}//end class
