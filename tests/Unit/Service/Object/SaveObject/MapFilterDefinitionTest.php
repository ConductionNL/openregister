<?php

/**
 * Unit tests for MapFilterDefinition.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject
 * @author   Conduction <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject;

use OCA\OpenRegister\Service\Object\SaveObject\MapFilterDefinition;
use PHPUnit\Framework\TestCase;

/**
 * Parsing a map filter definition, its first value and its lookups.
 *
 * @covers \OCA\OpenRegister\Service\Object\SaveObject\MapFilterDefinition
 */
class MapFilterDefinitionTest extends TestCase {

	public function testLookup(): void {
		$map = new MapFilterDefinition(' a = 1 , junk, b=x=y ');
		$this->assertSame('1', $map->lookup('a'));
		$this->assertSame('x=y', $map->lookup('b'));
		$this->assertSame('junk', $map->lookup('junk'));
	}//end testLookup()

	public function testFirstValue(): void {
		$this->assertSame('1', (new MapFilterDefinition('junk, a=1, b=2'))->firstValue());
		$this->assertSame('', (new MapFilterDefinition('a=, b=2'))->firstValue());
		$this->assertNull((new MapFilterDefinition('junk'))->firstValue());
	}//end testFirstValue()
}//end class
