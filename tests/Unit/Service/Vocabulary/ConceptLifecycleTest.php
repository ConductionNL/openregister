<?php

/**
 * Unit tests for {@see \OCA\OpenRegister\Service\Vocabulary\ConceptLifecycle}.
 *
 * The asymmetry these tests exist to pin down: a retired value is no longer
 * OFFERABLE and is still RESOLVABLE. Conflating the two is what breaks a
 * dossier from 2019 when a gemeente retires a resultaattype in 2026.
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
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/code-list-lifecycle-and-hierarchy/specs/skos-concept-registers/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Vocabulary;

use DateTimeImmutable;
use OCA\OpenRegister\Service\Vocabulary\ConceptLifecycle;
use PHPUnit\Framework\TestCase;

class ConceptLifecycleTest extends TestCase {

	private ConceptLifecycle $lifecycle;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->lifecycle = new ConceptLifecycle();
	}//end setUp()

	/**
	 * A concept with no window behaves exactly as every scheme written before
	 * this change did: always offerable.
	 *
	 * @return void
	 */
	public function testAConceptWithoutAWindowIsAlwaysOfferable(): void {
		$concept = ['uri' => 'https://example.org/c/1'];

		$this->assertTrue(
			$this->lifecycle->isOfferable(
				concept: $concept,
				at: new DateTimeImmutable('2026-09-14')
			)
		);
		$this->assertSame('no validity window', $this->lifecycle->describeWindow(concept: $concept));
	}//end testAConceptWithoutAWindowIsAlwaysOfferable()

	/**
	 * The window is closed at the instant it is judged, not at "now".
	 *
	 * @return void
	 */
	public function testAWindowThatHasPassedIsNoLongerWithin(): void {
		$concept = [
			'uri' => 'https://example.org/c/1',
			'validFrom' => '2019-01-01',
			'validUntil' => '2025-12-31',
		];

		$this->assertTrue(
			$this->lifecycle->isWithinWindow(concept: $concept, at: new DateTimeImmutable('2020-06-01'))
		);
		$this->assertFalse(
			$this->lifecycle->isWithinWindow(concept: $concept, at: new DateTimeImmutable('2026-09-14'))
		);
		$this->assertFalse(
			$this->lifecycle->isWithinWindow(concept: $concept, at: new DateTimeImmutable('2018-12-31'))
		);
	}//end testAWindowThatHasPassedIsNoLongerWithin()

	/**
	 * The refusal has to NAME the window, because "this value is retired"
	 * without dates does not tell anyone which value to use instead.
	 *
	 * @return void
	 */
	public function testTheWindowIsDescribedInWords(): void {
		$this->assertSame(
			'valid from 2019-01-01 until 2025-12-31',
			$this->lifecycle->describeWindow(
				concept: ['validFrom' => '2019-01-01', 'validUntil' => '2025-12-31']
			)
		);
		$this->assertSame(
			'valid until 2025-12-31',
			$this->lifecycle->describeWindow(concept: ['validUntil' => '2025-12-31'])
		);
		$this->assertSame(
			'valid from 2019-01-01',
			$this->lifecycle->describeWindow(concept: ['validFrom' => '2019-01-01'])
		);
	}//end testTheWindowIsDescribedInWords()

	/**
	 * A typo in an administered date must not silently retire a live value,
	 * so an unparseable bound is read as absent rather than as closed.
	 *
	 * @return void
	 */
	public function testAnUnparseableBoundIsTreatedAsAbsent(): void {
		$concept = ['validUntil' => 'gisteren'];

		$this->assertTrue(
			$this->lifecycle->isWithinWindow(concept: $concept, at: new DateTimeImmutable('2026-09-14'))
		);
	}//end testAnUnparseableBoundIsTreatedAsAbsent()

	/**
	 * Deprecation and the window are different facts, and only the first is
	 * waived by `allowDeprecated`.
	 *
	 * @return void
	 */
	public function testDeprecationAndTheWindowAreSeparateFacts(): void {
		$deprecated = ['deprecated' => true];
		$at = new DateTimeImmutable('2026-09-14');

		$this->assertFalse($this->lifecycle->isOfferable(concept: $deprecated, at: $at));
		$this->assertTrue(
			$this->lifecycle->isOfferable(concept: $deprecated, at: $at, allowDeprecated: true)
		);

		$retired = ['deprecated' => true, 'validUntil' => '2020-01-01'];
		$this->assertFalse(
			$this->lifecycle->isOfferable(concept: $retired, at: $at, allowDeprecated: true),
			'allowDeprecated waives the deprecation flag, never the window.'
		);
	}//end testDeprecationAndTheWindowAreSeparateFacts()

	/**
	 * A partially weighted scheme still adds up, because an unweighted
	 * concept contributes nothing rather than breaking the sum.
	 *
	 * @return void
	 */
	public function testWeightsRollUpAndAnUnweightedConceptContributesNothing(): void {
		$this->assertSame(
			8.0,
			$this->lifecycle->rollUpWeights(
				concepts: [['weight' => 3], ['weight' => 5]]
			)
		);
		$this->assertSame(
			8.0,
			$this->lifecycle->rollUpWeights(
				concepts: [['weight' => 3], ['weight' => 5], ['uri' => 'no-weight']]
			)
		);
		$this->assertSame(0.0, $this->lifecycle->rollUpWeights(concepts: []));
	}//end testWeightsRollUpAndAnUnweightedConceptContributesNothing()

	/**
	 * A code list item is an object, so the scheme's declared shape is what
	 * validates it, and the failure names the field.
	 *
	 * @return void
	 */
	public function testAConceptIsValidatedAgainstItsSchemesDeclaredShape(): void {
		$shape = [
			'properties' => [
				'bewaartermijn' => ['type' => 'integer'],
				'grondslag' => ['type' => 'string'],
			],
			'required' => ['bewaartermijn', 'grondslag'],
		];

		$complete = ['fields' => ['bewaartermijn' => 10, 'grondslag' => 'Selectielijst 2020']];
		$this->assertSame([], $this->lifecycle->validateAgainstShape(concept: $complete, shape: $shape));

		$missing = ['uri' => 'https://example.org/c/1', 'fields' => ['bewaartermijn' => 10]];
		$this->assertSame(
			['grondslag'],
			$this->lifecycle->validateAgainstShape(concept: $missing, shape: $shape)
		);

		$illTyped = ['fields' => ['bewaartermijn' => 'tien jaar', 'grondslag' => 'x']];
		$this->assertSame(
			['bewaartermijn'],
			$this->lifecycle->validateAgainstShape(concept: $illTyped, shape: $shape)
		);
	}//end testAConceptIsValidatedAgainstItsSchemesDeclaredShape()

	/**
	 * A scheme that declares no shape validates nothing, which is what keeps
	 * every scheme written before this change importing exactly as it did.
	 *
	 * @return void
	 */
	public function testASchemeWithoutAShapeValidatesNothing(): void {
		$this->assertSame(
			[],
			$this->lifecycle->validateAgainstShape(concept: ['uri' => 'x'], shape: [])
		);
	}//end testASchemeWithoutAShapeValidatesNothing()

	/**
	 * The exclusive group and the system-defined flag are read exactly, with
	 * whitespace and absence both reading as "none".
	 *
	 * @return void
	 */
	public function testTheGroupAndTheSystemFlagAreReadExactly(): void {
		$this->assertSame('urgentie', $this->lifecycle->exclusiveGroupOf(concept: ['exclusiveGroup' => ' urgentie ']));
		$this->assertNull($this->lifecycle->exclusiveGroupOf(concept: ['exclusiveGroup' => '   ']));
		$this->assertNull($this->lifecycle->exclusiveGroupOf(concept: []));

		$this->assertTrue($this->lifecycle->isSystemDefined(concept: ['systemDefined' => true]));
		$this->assertFalse($this->lifecycle->isSystemDefined(concept: ['systemDefined' => 'true']));
		$this->assertFalse($this->lifecycle->isSystemDefined(concept: []));
	}//end testTheGroupAndTheSystemFlagAreReadExactly()
}//end class
