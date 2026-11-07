<?php

declare(strict_types=1);

/**
 * ArchivalText: which stored values count as text for which reader.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Archival
 *
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 */

namespace Unit\Service\Archival;

use OCA\OpenRegister\Service\Archival\ArchivalText;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ArchivalText.
 */
class ArchivalTextTest extends TestCase {

	/**
	 * Only a string counts for fromString().
	 *
	 * @return void
	 */
	public function testFromStringAcceptsOnlyNonBlankStrings(): void {
		$this->assertSame('a b', ArchivalText::fromString(' a b '));
		$this->assertNull(ArchivalText::fromString('   '));
		$this->assertNull(ArchivalText::fromString(5));
		$this->assertNull(ArchivalText::fromString(null));
		$this->assertNull(ArchivalText::fromString(['x']));
	}

	/**
	 * A string or an integer counts for fromStringOrInt().
	 *
	 * @return void
	 */
	public function testFromStringOrIntAcceptsIntegers(): void {
		$this->assertSame('x', ArchivalText::fromStringOrInt(" x\n"));
		$this->assertSame('0', ArchivalText::fromStringOrInt(0));
		$this->assertSame('-7', ArchivalText::fromStringOrInt(-7));
		$this->assertNull(ArchivalText::fromStringOrInt(''));
		$this->assertNull(ArchivalText::fromStringOrInt(1.5));
		$this->assertNull(ArchivalText::fromStringOrInt(true));
	}

	/**
	 * Any scalar counts for fromScalar().
	 *
	 * @return void
	 */
	public function testFromScalarAcceptsEveryScalar(): void {
		$this->assertSame('1.5', ArchivalText::fromScalar(1.5));
		$this->assertSame('1', ArchivalText::fromScalar(true));
		$this->assertNull(ArchivalText::fromScalar(false));
		$this->assertSame('3', ArchivalText::fromScalar(' 3 '));
		$this->assertNull(ArchivalText::fromScalar(null));
		$this->assertNull(ArchivalText::fromScalar([]));
	}
}
