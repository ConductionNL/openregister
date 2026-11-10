<?php

/**
 * Unit tests for a handoff kind binding whose field map is missing or empty.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Handoff
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Handoff;

use OCA\OpenRegister\Service\Handoff\HandoffKindBinding;
use PHPUnit\Framework\TestCase;

/**
 * Without a field map nothing is declared and nothing is bound; with one, fields and targets are read from it.
 *
 * @covers \OCA\OpenRegister\Service\Handoff\HandoffKindBinding
 */
class HandoffKindBindingEmptyMapTest extends TestCase {

	/**
	 * A missing or empty map declares no fields and binds no target.
	 *
	 * @return void
	 */
	public function testAMissingOrEmptyMapBindsNothing(): void {
		foreach ([null, [], 'title'] as $map) {
			$binding = new HandoffKindBinding('https://schema.org/Thing', $map, ['title' => []]);

			$this->assertFalse($binding->hasFields());
			$this->assertSame([], $binding->contractFields());
			$this->assertNull($binding->target('name'));
			$this->assertFalse($binding->bindsAllOf(['name']), 'an unbound contract field is not bound');
		}

	}//end testAMissingOrEmptyMapBindsNothing()

	/**
	 * A declared map yields its fields in order and its targets.
	 *
	 * @return void
	 */
	public function testADeclaredMapIsRead(): void {
		$binding = new HandoffKindBinding('https://schema.org/Thing', ['name' => 'title', 7 => 'code'], ['title' => [], 'code' => []]);

		$this->assertSame(['name', '7'], $binding->contractFields());
		$this->assertSame('title', $binding->target('name'));
		$this->assertNull($binding->target('missing'));
		$this->assertTrue($binding->bindsAllOf(['name']));

	}//end testADeclaredMapIsRead()
}//end class
