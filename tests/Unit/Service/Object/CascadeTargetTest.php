<?php

/**
 * Unit tests for the cascade target: the parent it hangs under and the definition it carries.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Service\Object\CascadeTarget;
use PHPUnit\Framework\TestCase;

/**
 * A target starts without a definition, and with() hands back a copy that has one.
 *
 * @covers \OCA\OpenRegister\Service\Object\CascadeTarget
 */
class CascadeTargetTest extends TestCase {

	/**
	 * A fresh target has no property definition.
	 *
	 * @return void
	 */
	public function testAFreshTargetHasNoDefinition(): void {
		$this->assertSame([], (new CascadeTarget('parent-1', 4))->definition());

	}//end testAFreshTargetHasNoDefinition()

	/**
	 * with() returns a copy carrying the definition and the same parent, leaving the original bare.
	 *
	 * @return void
	 */
	public function testWithReturnsACopyCarryingTheDefinition(): void {
		$base   = new CascadeTarget('parent-1', null);
		$target = $base->with(['$ref' => '#/components/schemas/Note', 'inversedBy' => 'case']);
		$other  = $base->with(['$ref' => 'Task']);

		$this->assertSame(['$ref' => '#/components/schemas/Note', 'inversedBy' => 'case'], $target->definition());
		$this->assertSame(['$ref' => 'Task'], $other->definition());
		$this->assertSame('parent-1', $target->parentUuid);
		$this->assertNull($target->register);
		$this->assertSame([], $base->definition(), 'the base target is shared by every property and stays bare');

	}//end testWithReturnsACopyCarryingTheDefinition()
}//end class
