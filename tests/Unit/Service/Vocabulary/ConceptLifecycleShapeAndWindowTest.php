<?php

/**
 * ConceptLifecycle: per-type shape checks of a concept's fields, one-sided
 * validity windows, offerability of deprecated concepts and weight parsing.
 *
 * ConceptLifecycleTest covers the main paths; this file covers each declared
 * field type and the remaining window and weight branches. Every instant is a
 * fixed UTC DateTimeImmutable, never the clock.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Vocabulary
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Vocabulary;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Service\Vocabulary\ConceptLifecycle;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Vocabulary\ConceptLifecycle
 */
class ConceptLifecycleShapeAndWindowTest extends TestCase {

	private static function at(string $instant): DateTimeImmutable {
		return new DateTimeImmutable($instant, new DateTimeZone('UTC'));
	}

	public function testEachDeclaredTypeAcceptsItsOwnValuesAndRefusesOthers(): void {
		$shape = ['properties' => [
			's' => ['type' => 'string'],
			'i' => ['type' => 'integer'],
			'is' => ['type' => 'integer'],
			'n' => ['type' => 'number'],
			'ns' => ['type' => 'number'],
			'b' => ['type' => 'boolean'],
			'a' => ['type' => 'array'],
			'o' => ['type' => 'object'],
			'x' => ['type' => 'uuid'],
			'untyped' => ['format' => 'date'],
			'loose' => 'string',
			't' => ['type' => ['string']],
		]];
		$good = ['s' => 'a', 'i' => 3, 'is' => '42', 'n' => 1.5, 'ns' => '-2.5', 'b' => false, 'a' => [1], 'o' => ['k' => 1], 'x' => 9, 'untyped' => 1, 'loose' => 1, 't' => 1];
		$bad = ['s' => 1, 'i' => 1.5, 'is' => '4.2', 'n' => 'x', 'ns' => true, 'b' => 'true', 'a' => 'x', 'o' => 'x', 'x' => 9, 'untyped' => 1, 'loose' => 1, 't' => 1];
		$lifecycle = new ConceptLifecycle();

		$this->assertSame([], $lifecycle->validateAgainstShape(['fields' => $good], $shape));
		$this->assertSame(['s', 'i', 'is', 'n', 'ns', 'b', 'a', 'o'], $lifecycle->validateAgainstShape(['fields' => $bad], $shape));
	}

	public function testAbsentOrNullFieldsFailOnlyWhenRequired(): void {
		$shape = ['properties' => ['code' => ['type' => 'string'], 'note' => ['type' => 'string']], 'required' => ['code']];
		$lifecycle = new ConceptLifecycle();

		$this->assertSame(['code'], $lifecycle->validateAgainstShape(['fields' => ['code' => null]], $shape));
		$this->assertSame(['code'], $lifecycle->validateAgainstShape(['fields' => 'not a map'], $shape));
		$this->assertSame([], $lifecycle->validateAgainstShape(['fields' => ['code' => 'A']], $shape));
	}

	public function testANonListRequiredDeclarationRequiresNothing(): void {
		$shape = ['properties' => ['code' => ['type' => 'string']], 'required' => 'code'];

		$this->assertSame([], (new ConceptLifecycle())->validateAgainstShape([], $shape));
	}

	public function testOneSidedWindowsAreDescribedAndEnforced(): void {
		$lifecycle = new ConceptLifecycle();
		$from = ['validFrom' => '2026-03-01T00:00:00+00:00'];
		$until = ['validUntil' => ' 2026-03-31T00:00:00+00:00 '];

		$this->assertSame('valid from 2026-03-01', $lifecycle->describeWindow($from));
		$this->assertSame('valid until 2026-03-31', $lifecycle->describeWindow($until));
		$this->assertFalse($lifecycle->isWithinWindow($from, self::at('2026-02-28T23:59:59')));
		$this->assertTrue($lifecycle->isWithinWindow($from, self::at('2026-03-01T00:00:00')));
		$this->assertTrue($lifecycle->isWithinWindow($until, self::at('2026-03-31T00:00:00')));
		$this->assertFalse($lifecycle->isWithinWindow($until, self::at('2026-03-31T00:00:01')));
	}

	public function testANonStringOrBlankBoundIsNoBound(): void {
		$lifecycle = new ConceptLifecycle();

		$this->assertSame('no validity window', $lifecycle->describeWindow(['validFrom' => 20260301, 'validUntil' => '  ']));
	}

	public function testADeprecatedConceptIsOfferableOnlyWhenDeprecatedIsAllowed(): void {
		$lifecycle = new ConceptLifecycle();
		$deprecated = ['deprecated' => true];
		$at = self::at('2026-01-01T00:00:00');

		$this->assertFalse($lifecycle->isOfferable($deprecated, $at));
		$this->assertTrue($lifecycle->isWithinWindow($deprecated, $at));
		$this->assertFalse($lifecycle->isWithinWindow(['deprecated' => true, 'validUntil' => '2025-01-01'], $at));
	}

	public function testWeightsParseNumbersAndNumericStringsAndRollUpSkipsNonConcepts(): void {
		$lifecycle = new ConceptLifecycle();

		$this->assertSame(2.5, $lifecycle->weightOf(['weight' => '2.5']));
		$this->assertSame(3.0, $lifecycle->weightOf(['weight' => 3]));
		$this->assertSame(0.0, $lifecycle->weightOf(['weight' => 'heavy']));
		$this->assertSame(0.0, $lifecycle->weightOf(['weight' => true]));
		$this->assertSame(5.5, $lifecycle->rollUpWeights([['weight' => 3], 'not a concept', ['weight' => '2.5'], ['weight' => null]]));
	}

	public function testABlankOrNonStringGroupIsNoGroup(): void {
		$lifecycle = new ConceptLifecycle();

		$this->assertSame('size', $lifecycle->exclusiveGroupOf(['exclusiveGroup' => ' size ']));
		$this->assertNull($lifecycle->exclusiveGroupOf(['exclusiveGroup' => '   ']));
		$this->assertNull($lifecycle->exclusiveGroupOf(['exclusiveGroup' => 7]));
	}
}
