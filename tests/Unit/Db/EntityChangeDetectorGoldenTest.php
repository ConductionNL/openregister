<?php

/**
 * Golden record of EntityChangeDetector::changed() over the value shapes it normalises.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use DateTimeImmutable;
use OCA\OpenRegister\Db\EntityChangeDetector;
use OCP\AppFramework\Db\Entity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\EntityChangeDetector
 *
 * @spec openspec/specs/activity-provider/spec.md#requirement-a-register-save-that-changes-nothing-publishes-nothing
 */
class EntityChangeDetectorGoldenTest extends TestCase {
	/**
	 * An entity that serialises to the given payload.
	 *
	 * @param array<mixed> $payload What jsonSerialize() returns.
	 *
	 * @return Entity The entity.
	 */
	private static function entity(array $payload): Entity {
		return new class ($payload) extends Entity implements \JsonSerializable {
			/**
			 * @param array<mixed> $payload What jsonSerialize() returns.
			 */
			public function __construct(private readonly array $payload) {
			}

			/**
			 * @return array<mixed>
			 */
			public function jsonSerialize(): array {
				return $this->payload;
			}
		};
	}//end entity()

	/**
	 * A JsonSerializable value object.
	 *
	 * @param mixed $data What it serialises to.
	 *
	 * @return \JsonSerializable The value.
	 */
	private static function serialisable(mixed $data): \JsonSerializable {
		return new class ($data) implements \JsonSerializable {
			public function __construct(private readonly mixed $data) {
			}

			public function jsonSerialize(): mixed {
				return $this->data;
			}
		};
	}//end serialisable()

	/**
	 * Before / after payloads and whether the detector calls it a change.
	 *
	 * @return array<string, array{0: array<mixed>, 1: array<mixed>, 2: bool}>
	 */
	public static function cases(): array {
		$at = new DateTimeImmutable('2026-01-02T03:04:05+00:00');
		$object = new \stdClass();
		return [
			'identical' => [['a' => 1, 'b' => 'x'], ['a' => 1, 'b' => 'x'], false],
			'only updated differs' => [['a' => 1, 'updated' => '2026-01-01'], ['a' => 1, 'updated' => '2026-02-02'], false],
			'updated missing on one side' => [['a' => 1, 'updated' => 'x'], ['a' => 1], false],
			'value differs' => [['a' => 1], ['a' => 2], true],
			'map key order ignored' => [['a' => 1, 'b' => 2], ['b' => 2, 'a' => 1], false],
			'nested map key order ignored' => [['m' => ['x' => 1, 'y' => 2]], ['m' => ['y' => 2, 'x' => 1]], false],
			'list order matters' => [['l' => [1, 2]], ['l' => [2, 1]], true],
			'list against reordered int-keyed map' => [['l' => ['a', 'b']], ['l' => [1 => 'b', 0 => 'a']], false],
			'int equals numeric string' => [['schema' => 28], ['schema' => '28'], false],
			'float equals integer string' => [['n' => 1.0], ['n' => '1'], false],
			'float precision differs' => [['n' => 1.5], ['n' => '1.50'], true],
			'true equals one' => [['f' => true], ['f' => '1'], false],
			'false equals zero' => [['f' => false], ['f' => 0], false],
			'false differs from empty string' => [['f' => false], ['f' => ''], true],
			'null differs from empty string' => [['f' => null], ['f' => ''], true],
			'null equals null' => [['f' => null], ['f' => null], false],
			'datetime equals its iso form' => [['d' => $at], ['d' => '2026-01-02T03:04:05+00:00'], false],
			'datetime in other zone differs' => [['d' => $at], ['d' => $at->setTimezone(new \DateTimeZone('Europe/Amsterdam'))], true],
			'serialisable equals its array' => [['s' => self::serialisable(['k' => 1, 'j' => 2])], ['s' => ['j' => '2', 'k' => '1']], false],
			'serialisable scalar' => [['s' => self::serialisable(true)], ['s' => '1'], false],
			'same object instance' => [['o' => $object], ['o' => $object], false],
			'equal but distinct objects' => [['o' => new \stdClass()], ['o' => new \stdClass()], true],
			'key added' => [['a' => 1], ['a' => 1, 'b' => null], true],
			'empty arrays' => [[], [], false],
			'empty list against empty map value' => [['x' => []], ['x' => null], true],
		];
	}//end cases()

	#[DataProvider('cases')]
	public function testChangedMatchesGolden(array $before, array $after, bool $expected): void {
		$detector = new EntityChangeDetector();
		$this->assertSame($expected, $detector->changed(self::entity($before), self::entity($after)));
		$this->assertSame($expected, $detector->changed(self::entity($after), self::entity($before)));
	}//end testChangedMatchesGolden()
}//end class
