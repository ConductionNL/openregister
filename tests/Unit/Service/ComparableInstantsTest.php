<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Service\ComparableInstants;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Two dates compare as UTC instants; anything else passes through untouched.
 */
#[CoversClass(ComparableInstants::class)]
class ComparableInstantsTest extends TestCase {
	private ComparableInstants $instants;

	protected function setUp(): void {
		$this->instants = new ComparableInstants();
	}

	public function testAnIsoDateAndSqlNowBecomeInstants(): void {
		[$left, $right] = $this->instants->pair('2026-09-30T20:00:00+00:00', '2026-09-30 21:14:43');

		$this->assertSame(1790798400.0, $left);
		$this->assertSame(1790802883.0, $right);
		$this->assertLessThan($right, $left);
	}

	public function testAnOffsetIsConvertedToUtc(): void {
		$this->assertSame(
			$this->instants->toInstant('2026-09-30T20:30:00Z'),
			$this->instants->toInstant('2026-09-30T22:30:00+02:00')
		);
	}

	public function testADateWithoutTimeIsMidnightUtc(): void {
		$this->assertSame(1790726400.0, $this->instants->toInstant('2026-09-30'));
	}

	public function testAFractionIsKept(): void {
		$this->assertSame(1790798400.5, $this->instants->toInstant('2026-09-30T20:00:00.5Z'));
	}

	public function testANonDateStringIsNotAnInstant(): void {
		$this->assertNull($this->instants->toInstant('banana'));
	}

	public function testANonStringIsNotAnInstant(): void {
		$this->assertNull($this->instants->toInstant(5));
		$this->assertNull($this->instants->toInstant(null));
	}

	public function testADateShapedStringThatDoesNotParseIsNotAnInstant(): void {
		$this->assertNull($this->instants->toInstant('2026-13-45'));
	}

	public function testAPairWithOneNonDateIsReturnedUnchanged(): void {
		$this->assertSame(['apple', '2026-09-30 21:14:43'], $this->instants->pair('apple', '2026-09-30 21:14:43'));
		$this->assertSame([5, 7], $this->instants->pair(5, 7));
	}
}
