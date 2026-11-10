<?php

/**
 * Golden test for FlowItems: normalise() over every authoring shape, item(),
 * outputOf(), fromSubject() and refreshSubjectProjection().
 * Re-record with UPDATE_GOLDEN=1.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use JsonSerializable;
use OCA\OpenRegister\Service\Flow\LiveSubject;
use OCA\OpenRegister\Service\Flow\FlowSubjectItems;
use OCA\OpenRegister\Service\Flow\FlowItems;
use PHPUnit\Framework\TestCase;
use stdClass;
use Throwable;

/**
 * @covers \OCA\OpenRegister\Service\Flow\FlowItems
 */
class FlowItemsGoldenTest extends TestCase {

	/**
	 * @var string
	 */
	private const FIXTURE = __DIR__ . '/fixtures/flow-items-golden.json';

	private static function serialisable(array $data): JsonSerializable {
		return new class($data) implements JsonSerializable {
			public function __construct(private array $data) {
			}

			public function jsonSerialize(): mixed {
				return $this->data;
			}
		};
	}//end serialisable()

	private static function withGetObject(array $data): object {
		return new class($data) {
			public function __construct(private array $data) {
			}

			public function getObject(): array {
				return $this->data;
			}
		};
	}//end withGetObject()

	public function testItemsMatchGolden(): void {
		$actual = [];
		$values = [
			'string' => ['x', null],
			'null' => [null, 3],
			'empty' => [[], 1],
			'bare record' => [['a' => 1], null],
			'bare record with index' => [['a' => 1], 4],
			'single item' => [['json' => ['a' => 1], 'binary' => ['f' => 'b'], 'pairedItem' => ['item' => '7'], 'output' => 'yes'], 2],
			'single item int paired' => [['json' => ['a' => 1], 'pairedItem' => 5], null],
			'single item json not array' => [['json' => 'scalar', 'binary' => 'x'], null],
			'single item empty output' => [['json' => [], 'output' => ''], null],
			'single item numeric output' => [['json' => [], 'output' => 3], null],
			'paired without item' => [['json' => [], 'pairedItem' => ['other' => 1]], 9],
			'paired null item' => [['json' => [], 'pairedItem' => ['item' => null]], 9],
			'list' => [[['json' => ['a' => 1]], ['b' => 2], 'skip', 7, ['json' => ['c' => 3], 'pairedItem' => ['item' => 0], 'output' => 'no']], null],
			'list with index' => [[['json' => ['a' => 1]], ['b' => 2]], 6],
			'list of scalars' => [['a', 'b'], null],
			'list with nested lists' => [[[1, 2], [[3]]], null],
			'sparse keys not list' => [[1 => ['a' => 1], 2 => ['b' => 2]], null],
		];
		foreach ($values as $name => [$value, $from]) {
			$actual['normalise / ' . $name] = FlowItems::normalise(value: $value, fromItemIndex: $from);
		}

		$actual['item / defaults'] = FlowItems::item(json: ['a' => 1]);
		$actual['item / full'] = FlowItems::item(json: ['a' => 1], binary: ['b' => 2], fromItemIndex: 0, output: 'out');
		$actual['item / empty output'] = FlowItems::item(json: [], binary: [], fromItemIndex: null, output: '');
		$actual['outputOf'] = [FlowItems::outputOf(member: []), FlowItems::outputOf(member: ['output' => 'x']), FlowItems::outputOf(member: ['output' => ''])];

		$plain = new stdClass();
		$plain->id = 'p1';
		$plain->name = 'plain';
		$subjects = [
			'serialisable' => self::serialisable(['id' => 'u1', 'name' => 'live', 'status' => 'new']),
			'getObject' => self::withGetObject(['uuid' => 'u1', 'name' => 'obj']),
			'plain' => $plain,
			'serialisable scalar' => self::serialisable(['x']),
			'serialisable empty' => self::serialisable([]),
		];
		foreach ($subjects as $name => $subject) {
			$actual['fromSubject / ' . $name] = FlowItems::fromSubject(subject: $subject);
		}

		$items = [
			['json' => ['@self' => ['id' => ' u1 '], 'name' => 'old', 'answer' => 'kept'], 'binary' => [], 'pairedItem' => null],
			['json' => ['id' => 'u1', 'name' => 'old2']],
			['json' => ['uuid' => 'u1']],
			['json' => ['@self' => ['id' => ''], 'id' => 'u1']],
			['json' => ['@self' => ['id' => 'other'], 'id' => 'u1']],
			['json' => ['id' => '  ', 'uuid' => 'u1']],
			['json' => ['id' => 5, 'uuid' => 'u1']],
			['json' => ['id' => 'u2']],
			'not-an-item',
			['binary' => []],
			['json' => 'scalar'],
		];
		$cases = [
			'match' => [$items, $subjects['serialisable'], ' u1 '],
			'blank uuid' => [$items, $subjects['serialisable'], '  '],
			'no items' => [[], $subjects['serialisable'], 'u1'],
			'empty live' => [$items, $subjects['serialisable empty'], 'u1'],
			'getObject subject' => [$items, $subjects['getObject'], 'u1'],
			'keys preserved' => [[5 => ['json' => ['id' => 'u1']], 'k' => ['json' => ['id' => 'u1', 'x' => 1]]], $subjects['serialisable'], 'u1'],
		];
		foreach ($cases as $name => [$list, $subject, $uuid]) {
			try {
				$actual['refresh / ' . $name] = FlowSubjectItems::refreshSubjectProjection(items: $list, subject: new LiveSubject(subject: $subject, uuid: $uuid));
			} catch (Throwable $error) {
				$actual['refresh / ' . $name] = ['error' => get_class($error), 'message' => $error->getMessage()];
			}
		}

		$actual = json_decode((string)json_encode($actual), true);
		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$expected = json_decode((string)file_get_contents(self::FIXTURE), true);
		foreach ($expected as $name => $outcome) {
			$this->assertSame($outcome, $actual[$name] ?? null, $name);
		}

		$this->assertSame(array_keys($expected), array_keys($actual));
	}//end testItemsMatchGolden()
}//end class
