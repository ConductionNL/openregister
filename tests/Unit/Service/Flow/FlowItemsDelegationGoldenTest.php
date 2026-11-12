<?php

/**
 * Golden: the FlowItems surface answers the same after its logic moved out.
 *
 * Pinned on the code before the move: every authoring shape normalise()
 * accepts, every pairedItem spelling, every way a subject serialises, and the
 * identity spellings refreshSubjectProjection() matches on.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Service\Flow\FlowItemOptions;
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\LiveSubject;
use OCA\OpenRegister\Service\Flow\FlowSubjectItems;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * @covers \OCA\OpenRegister\Service\Flow\FlowItems
 */
final class FlowItemsDelegationGoldenTest extends TestCase {

	/**
	 * The keys keep their values.
	 *
	 * @return void
	 */
	public function testTheKeysKeepTheirValues(): void {
		$this->assertSame(
			['json', 'binary', 'pairedItem', 'output'],
			[FlowItems::JSON, FlowItems::BINARY, FlowItems::PAIRED_ITEM, FlowItems::OUTPUT]
		);
	}//end testTheKeysKeepTheirValues()

	/**
	 * Every shape normalise() accepts, and what it makes of it.
	 *
	 * @return void
	 */
	public function testNormaliseShapes(): void {
		$this->assertSame([], FlowItems::normalise(value: 'text'));
		$this->assertSame([], FlowItems::normalise(value: []));
		$this->assertSame(
			[['json' => ['a' => 1], 'binary' => [], 'pairedItem' => ['item' => 4]]],
			FlowItems::normalise(value: ['a' => 1], fromItemIndex: 4)
		);
		$this->assertSame(
			[
				['json' => ['a' => 1], 'binary' => [], 'pairedItem' => ['item' => 0]],
				['json' => ['b' => 2], 'binary' => ['f' => 'x'], 'pairedItem' => ['item' => 7], 'output' => 'yes'],
				['json' => ['c' => 3], 'binary' => [], 'pairedItem' => ['item' => 9]],
			],
			FlowItems::normalise(
				value: [
					['a' => 1],
					'skipped',
					['json' => ['b' => 2], 'binary' => ['f' => 'x'], 'pairedItem' => ['item' => '7'], 'output' => 'yes'],
					['json' => ['c' => 3], 'pairedItem' => 9, 'output' => ''],
				]
			)
		);
		$this->assertSame(
			[
				['json' => ['a' => 1], 'binary' => [], 'pairedItem' => ['item' => 2]],
				['json' => ['b' => 1], 'binary' => [], 'pairedItem' => ['item' => 2]],
			],
			FlowItems::normalise(value: [['a' => 1], ['b' => 1]], fromItemIndex: 2)
		);
		$this->assertSame(
			[['json' => ['a' => 1], 'binary' => [], 'pairedItem' => null]],
			FlowItems::normalise(value: ['json' => ['a' => 1], 'pairedItem' => 'nope'])
		);
	}//end testNormaliseShapes()

	/**
	 * item() and outputOf().
	 *
	 * @return void
	 */
	public function testItemAndOutput(): void {
		$this->assertSame(['json' => [], 'binary' => [], 'pairedItem' => null], FlowItems::item(json: []));
		$this->assertSame(
			['json' => ['x' => 1], 'binary' => ['b' => 1], 'pairedItem' => ['item' => 0], 'output' => 'o'],
			FlowItems::item(json: ['x' => 1], options: new FlowItemOptions(binary: ['b' => 1], fromItemIndex: 0, output: 'o'))
		);
		$this->assertSame(['json' => [], 'binary' => [], 'pairedItem' => null], FlowItems::item(json: [], options: new FlowItemOptions(binary: [], fromItemIndex: null, output: '')));
		$this->assertNull(FlowItems::outputOf(member: ['output' => 5]));
		$this->assertSame('a', FlowItems::outputOf(member: ['output' => 'a']));
	}//end testItemAndOutput()

	/**
	 * fromSubject() reads jsonSerialize, then getObject, then public fields.
	 *
	 * @return void
	 */
	public function testFromSubject(): void {
		$plain    = new stdClass();
		$plain->a = 1;
		$this->assertSame([['json' => ['a' => 1], 'binary' => [], 'pairedItem' => null]], FlowItems::fromSubject(subject: $plain));

		$getter = new class {
			/**
			 * A public field the getter hides.
			 *
			 * @var string
			 */
			public string $hidden = 'no';

			/**
			 * The record.
			 *
			 * @return array<string, int>
			 */
			public function getObject(): array {
				return ['g' => 1];
			}//end getObject()
		};
		$this->assertSame([['json' => ['g' => 1], 'binary' => [], 'pairedItem' => null]], FlowItems::fromSubject(subject: $getter));

		$serialisable = new class implements \JsonSerializable {
			/**
			 * The record.
			 *
			 * @return array<string, int>
			 */
			public function jsonSerialize(): array {
				return ['s' => 1];
			}//end jsonSerialize()

			/**
			 * Never read: jsonSerialize wins.
			 *
			 * @return array<string, int>
			 */
			public function getObject(): array {
				return ['g' => 1];
			}//end getObject()
		};
		$this->assertSame([['json' => ['s' => 1], 'binary' => [], 'pairedItem' => null]], FlowItems::fromSubject(subject: $serialisable));
	}//end testFromSubject()

	/**
	 * refreshSubjectProjection() matches @self.id, then id, then uuid, and nothing else.
	 *
	 * @return void
	 */
	public function testRefreshMatchesEveryIdentitySpelling(): void {
		$subject         = new stdClass();
		$subject->status = 'live';
		$items           = [
			'k1' => ['json' => ['@self' => ['id' => ' u1 '], 'status' => 'old', 'keep' => 1]],
			'k2' => ['json' => ['id' => 'u1', 'status' => 'old']],
			'k3' => ['json' => ['id' => ' ', 'uuid' => 'u1', 'status' => 'old']],
			'k4' => ['json' => ['@self' => ['id' => ''], 'uuid' => 'u1']],
			'k5' => ['json' => ['uuid' => 'other', 'status' => 'old']],
			'k6' => 'not an item',
			'k7' => ['json' => ['@self' => ['id' => 'other'], 'id' => 'u1', 'status' => 'old']],
			'k8' => ['json' => ['id' => 'other', 'uuid' => 'u1', 'status' => 'old']],
		];

		$this->assertSame(
			[
				'k1' => ['json' => ['@self' => ['id' => ' u1 '], 'status' => 'live', 'keep' => 1]],
				'k2' => ['json' => ['id' => 'u1', 'status' => 'live']],
				'k3' => ['json' => ['id' => ' ', 'uuid' => 'u1', 'status' => 'live']],
				'k4' => ['json' => ['@self' => ['id' => ''], 'uuid' => 'u1', 'status' => 'live']],
				'k5' => ['json' => ['uuid' => 'other', 'status' => 'old']],
				'k6' => 'not an item',
				'k7' => ['json' => ['@self' => ['id' => 'other'], 'id' => 'u1', 'status' => 'old']],
				'k8' => ['json' => ['id' => 'other', 'uuid' => 'u1', 'status' => 'old']],
			],
			FlowSubjectItems::refreshSubjectProjection(items: $items, subject: new LiveSubject(subject: $subject, uuid: ' u1 '))
		);
		$this->assertSame($items, FlowSubjectItems::refreshSubjectProjection(items: $items, subject: new LiveSubject(subject: $subject, uuid: '  ')));
		$this->assertSame([], FlowSubjectItems::refreshSubjectProjection(items: [], subject: new LiveSubject(subject: $subject, uuid: 'u1')));
		$this->assertSame($items, FlowSubjectItems::refreshSubjectProjection(items: $items, subject: new LiveSubject(subject: new stdClass(), uuid: 'u1')));
	}//end testRefreshMatchesEveryIdentitySpelling()
}//end class
