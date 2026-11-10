<?php

/**
 * Golden test for ImportService::withSaveOutcome().
 *
 * Records the summary it answers for bulk save results with every id shape
 * (@self.id, uuid, id, none), keyed and listed objects, missing result keys,
 * prior summary keys and a range of unchanged ratios for the deduplication
 * efficiency. Re-record with UPDATE_GOLDEN=1.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Service\ImportService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * @covers \OCA\OpenRegister\Service\ImportService
 */
class ImportServiceSaveOutcomeGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/import-save-outcome-golden.json';

	private static function objects(int $count, string $prefix): array {
		$objects = [];
		for ($i = 0; $i < $count; $i++) {
			$objects[] = ['@self' => ['id' => $prefix . $i]];
		}

		return $objects;
	}//end objects()

	private static function scenarios(): array {
		$shapes = [
			['@self' => ['id' => 'self-id'], 'uuid' => 'u', 'id' => 1],
			['@self' => ['name' => 'no id'], 'uuid' => 'uuid-only', 'id' => 2],
			['@self' => ['id' => null], 'id' => 3],
			['id' => 'plain-id'],
			['name' => 'no id at all'],
			[],
		];
		return [
			'empty result' => [[], []],
			'empty lists' => [[], ['saved' => [], 'updated' => [], 'unchanged' => []]],
			'id shapes' => [[], ['saved' => $shapes, 'updated' => array_reverse($shapes), 'unchanged' => [$shapes[3]]]],
			'keyed objects' => [[], ['saved' => ['a' => $shapes[0], 'b' => $shapes[3]], 'unchanged' => [5 => $shapes[1]]]],
			'saved only' => [['found' => 3], ['saved' => self::objects(3, 's')]],
			'unchanged only' => [[], ['unchanged' => self::objects(2, 'n')]],
			'one third unchanged' => [[], ['saved' => self::objects(1, 's'), 'updated' => self::objects(1, 'u'), 'unchanged' => self::objects(1, 'n')]],
			'two thirds unchanged' => [[], ['saved' => self::objects(1, 's'), 'unchanged' => self::objects(2, 'n')]],
			'one in seven unchanged' => [[], ['saved' => self::objects(6, 's'), 'unchanged' => self::objects(1, 'n')]],
			'prior summary keys' => [
				['found' => 9, 'created' => ['old'], 'errors' => ['e'], 'deduplication_efficiency' => 'stale'],
				['saved' => self::objects(1, 's')],
			],
			'prior efficiency replaced' => [
				['deduplication_efficiency' => 'stale'],
				['updated' => self::objects(3, 'u'), 'unchanged' => self::objects(1, 'n'), 'invalid' => [['x']]],
			],
		];
	}//end scenarios()

	public function testSaveOutcomeMatchesGolden(): void {
		$service = (new ReflectionClass(ImportService::class))->newInstanceWithoutConstructor();
		$method = new ReflectionMethod(ImportService::class, 'withSaveOutcome');
		$method->setAccessible(true);

		$actual = [];
		foreach (self::scenarios() as $name => [$summary, $saveResult]) {
			$actual[$name] = $method->invoke($service, $summary, $saveResult);
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
	}//end testSaveOutcomeMatchesGolden()
}//end class
