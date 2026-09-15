<?php

declare(strict_types=1);

/**
 * UnestablishedValues tests.
 *
 * Pins the one property the helper exists for: what it returns is a fixed
 * point of the read path's strip, so a block pruned by it reads the same on
 * create and on GET.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Archival
 *
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 *
 * @spec openspec/specs/archival-annotation-vocabulary/spec.md#requirement-get-on-an-archival-schema-row-surfaces-_retention-block
 */

namespace Unit\Service\Archival;

use OCA\OpenRegister\Controller\ObjectsController;
use OCA\OpenRegister\Service\Archival\UnestablishedValues;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Tests for UnestablishedValues.
 */
class UnestablishedValuesTest extends TestCase {

	/**
	 * A value with every kind of empty at every depth, plus the answers that
	 * look empty and are not.
	 *
	 * @return array<string, mixed> The fixture.
	 */
	private function awkward(): array {
		return [
			'null' => null,
			'blank' => '',
			'zero' => 0,
			'false' => false,
			'stringZero' => '0',
			'emptyMap' => [],
			'mapOfNothing' => ['a' => null, 'b' => ['c' => '']],
			'map' => ['kept' => 'yes', 'dropped' => null],
			'list' => [['rule' => 0, 'note' => null], 'skipped', null, '', []],
			'emptyList' => [],
		];
	}

	/**
	 * The members that establish nothing go; the falsy answers stay.
	 */
	public function testDropsNothingAndKeepsFalsyAnswers(): void {
		$this->assertSame(
			[
				'zero' => 0,
				'false' => false,
				'stringZero' => '0',
				'map' => ['kept' => 'yes'],
				// A list keeps its items, as the strip does. Only maps inside
				// it are pruned.
				'list' => [['rule' => 0], 'skipped', null, '', []],
			],
			(new UnestablishedValues())->without(values: $this->awkward())
		);
	}

	/**
	 * THE PROPERTY: the result is unchanged by the read path's strip.
	 *
	 * Drives the real private `ObjectsController::stripEmptyValues()` rather
	 * than restating its rule, so a strip that learns a new kind of empty, or
	 * a helper that drifts from it, reddens here.
	 */
	public function testTheResultIsAFixedPointOfTheReadPathStrip(): void {
		$controller = (new ReflectionClass(ObjectsController::class))->newInstanceWithoutConstructor();
		$strip = new ReflectionMethod(ObjectsController::class, 'stripEmptyValues');

		$pruned = (new UnestablishedValues())->without(values: $this->awkward());

		$this->assertSame($pruned, $strip->invoke($controller, $pruned));
		// And it agrees with the strip applied to the raw value, so the two
		// rules are the same rule, not merely compatible ones.
		$this->assertSame($strip->invoke($controller, $this->awkward()), $pruned);
	}
}//end class
