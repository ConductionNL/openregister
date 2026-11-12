<?php

/**
 * Golden characterization test for SystemEntityChange.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Notification;

use DateTimeImmutable;
use OCA\OpenRegister\Notifications\Service\Notification\SystemEntityChange;
use PHPUnit\Framework\TestCase;

/**
 * Pins the comparable form of a fixed set of values, and isReal() on pairs
 * that differ only in bookkeeping, emptiness, order or type.
 *
 * @covers \OCA\OpenRegister\Notifications\Service\Notification\SystemEntityChange
 */
final class SystemEntityChangeGoldenTest extends TestCase {

	/**
	 * The comparable form of one value, through the private normaliser.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed
	 */
	private function normalise(mixed $value): mixed {
		$change = new SystemEntityChange();
		return (new \ReflectionMethod($change, 'normalise'))->invoke($change, $value);
	}//end normalise()

	/**
	 * A serialisable object.
	 *
	 * @param mixed $data What it serialises to.
	 *
	 * @return \JsonSerializable
	 */
	private static function serialisable(mixed $data): \JsonSerializable {
		return new class($data) implements \JsonSerializable {
			public function __construct(private readonly mixed $data) {
			}

			public function jsonSerialize(): mixed {
				return $this->data;
			}
		};
	}//end serialisable()

	/**
	 * Values and their comparable forms.
	 *
	 * @return void
	 */
	public function testNormalisedForms(): void {
		$date = new DateTimeImmutable('2026-03-04T05:06:07+01:00');
		$cases = [
			[null, null],
			['', null],
			[[], null],
			[0, '0'],
			[1.5, '1.5'],
			[true, '1'],
			[false, '0'],
			['text', 'text'],
			[$date, '2026-03-04T05:06:07+01:00'],
			[self::serialisable(['b' => 1, 'a' => '']), ['b' => '1']],
			[self::serialisable(false), '0'],
			[self::serialisable(''), null],
			[['c', 'a', 2, 10], ['10', '2', 'a', 'c']],
			[[['z' => 1], ['a' => 2]], [['z' => '1'], ['a' => '2']]],
			[[['z' => 1], 'b', 'a'], [['z' => '1'], 'b', 'a']],
			[['x' => null, 'y' => ['', []]], ['y' => [null, null]]],
			[['b' => ['k' => ''], 'a' => 1, 5 => 'five', '05' => 'z'], [5 => 'five', '05' => 'z', 'a' => '1']],
			[['only' => null, 'empty' => []], null],
			[[null, ''], [null, null]],
			[$this, $this],
		];

		foreach ($cases as $index => [$value, $expected]) {
			$this->assertSame($expected, $this->normalise($value), 'case '.$index);
		}
	}//end testNormalisedForms()

	/**
	 * Pairs and whether isReal() calls them a real change.
	 *
	 * @return void
	 */
	public function testIsReal(): void {
		$change = new SystemEntityChange();
		$pairs = [
			[['a' => 1, 'updated' => 'x', 'version' => '1'], ['a' => '1', 'updated' => 'y', 'version' => '2', 'syncStatus' => 'ok'], false],
			[['a' => null], ['a' => ''], false],
			[['a' => []], [], false],
			[['required' => ['b', 'a']], ['required' => ['a', 'b']], false],
			[['props' => [['n' => 1], ['n' => 2]]], ['props' => [['n' => 2], ['n' => 1]]], true],
			[['a' => true], ['a' => 1], false],
			[['a' => false], ['a' => null], true],
			[['a' => 1, 'b' => 2], ['b' => 2, 'a' => 1], false],
			[['a' => 1], ['a' => 2], true],
			[['lastChecked' => 'x', 'created' => 'a'], ['remoteVersion' => 3, 'localVersion' => 2, 'lastSyncDate' => 'z'], false],
		];

		foreach ($pairs as $index => [$old, $new, $expected]) {
			$this->assertSame($expected, $change->isReal($old, $new), 'pair '.$index);
		}
	}//end testIsReal()
}//end class
