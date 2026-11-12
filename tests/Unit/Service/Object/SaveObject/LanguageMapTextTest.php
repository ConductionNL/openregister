<?php

/**
 * Unit tests for LanguageMapText.
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

use OCA\OpenRegister\Service\Object\SaveObject\LanguageMapText;
use PHPUnit\Framework\TestCase;

/**
 * The language-tag predicate and the first filled value of a language map.
 *
 * @covers \OCA\OpenRegister\Service\Object\SaveObject\LanguageMapText
 * @uses   \OCA\OpenRegister\Service\Object\LanguageKeyedMap
 */
class LanguageMapTextTest extends TestCase {

	public function testIsLanguageKeyed(): void {
		$this->assertTrue((new LanguageMapText(['nl' => 'a', 'en-GB' => 'b']))->isLanguageKeyed());
		$this->assertFalse((new LanguageMapText(['street' => 'a']))->isLanguageKeyed());
	}//end testIsLanguageKeyed()

	public function testFirstValue(): void {
		$this->assertSame('b', (new LanguageMapText(['nl' => ' ', 'en' => 'b', 'de' => 'c']))->firstValue());
		$this->assertSame('7', (new LanguageMapText(['nl' => 7]))->firstValue());
		$this->assertNull((new LanguageMapText(['nl' => '', 'en' => '']))->firstValue());
		$this->assertNull((new LanguageMapText(['nl' => ['x'], 'en' => 'b']))->firstValue());
		$this->assertNull((new LanguageMapText([]))->firstValue());
	}//end testFirstValue()
}//end class
