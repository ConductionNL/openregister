<?php

/**
 * Unit tests for {@see \OCA\OpenRegister\Service\Vocabulary\CodedValueRefusals}.
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

use DateTimeImmutable;
use OCA\OpenRegister\Service\Vocabulary\CodedNarrowing;
use OCA\OpenRegister\Service\Vocabulary\CodedPropertyDeclaration;
use OCA\OpenRegister\Service\Vocabulary\CodedValueCheck;
use OCA\OpenRegister\Service\Vocabulary\CodedValueMatch;
use OCA\OpenRegister\Service\Vocabulary\CodedValueRefusals;
use OCA\OpenRegister\Service\Vocabulary\CodedValueRules;
use OCA\OpenRegister\Service\Vocabulary\CodedValueSubject;
use OCA\OpenRegister\Service\Vocabulary\ConceptHierarchy;
use OCA\OpenRegister\Service\Vocabulary\ConceptLifecycle;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The refusal order: validity window first, then leaf-only, then exclusive group.
 *
 * @covers \OCA\OpenRegister\Service\Vocabulary\CodedValueRefusals
 * @uses   \OCA\OpenRegister\Service\Vocabulary\CodedNarrowing
 * @uses   \OCA\OpenRegister\Service\Vocabulary\CodedPropertyDeclaration
 * @uses   \OCA\OpenRegister\Service\Vocabulary\CodedValueCheck
 * @uses   \OCA\OpenRegister\Service\Vocabulary\CodedValueMatch
 * @uses   \OCA\OpenRegister\Service\Vocabulary\CodedValueRules
 * @uses   \OCA\OpenRegister\Service\Vocabulary\CodedValueSubject
 */
class CodedValueRefusalsTest extends TestCase {

	/**
	 * The lifecycle mock.
	 *
	 * @var ConceptLifecycle&MockObject
	 */
	private ConceptLifecycle&MockObject $lifecycle;

	/**
	 * The hierarchy mock.
	 *
	 * @var ConceptHierarchy&MockObject
	 */
	private ConceptHierarchy&MockObject $hierarchy;

	/**
	 * The check every subject shares.
	 *
	 * @var CodedValueCheck
	 */
	private CodedValueCheck $check;

	/**
	 * Set up mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->lifecycle = $this->createMock(ConceptLifecycle::class);
		$this->hierarchy = $this->createMock(ConceptHierarchy::class);
		$this->hierarchy->method('labelOf')->willReturnCallback(
			static fn (array $concept, string $language): string => ($concept['label'] ?? '?').'@'.$language
		);
		$this->check = new CodedValueCheck(object: [], asOf: new DateTimeImmutable('2026-03-01T00:00:00Z'));

	}//end setUp()

	/**
	 * A subject for property "kleur".
	 *
	 * @param boolean $leafOnly Whether the field accepts only leaves.
	 *
	 * @return CodedValueSubject
	 */
	private function subject(bool $leafOnly): CodedValueSubject {
		$declaration = new CodedPropertyDeclaration('kleuren', new CodedValueRules(leafOnly: $leafOnly), new CodedNarrowing());

		return new CodedValueSubject('kleur', $declaration, $this->check, ['urn:rood' => ['uri' => 'urn:rood']]);

	}//end subject()

	/**
	 * Within the window, not leaf-only, no group: no violation.
	 *
	 * @return void
	 */
	public function testAcceptableValueHasNoViolation(): void {
		$this->lifecycle->method('isWithinWindow')->willReturn(true);
		$this->lifecycle->method('exclusiveGroupOf')->willReturn(null);
		$this->hierarchy->expects($this->never())->method('isLeaf');

		$refusals = new CodedValueRefusals($this->lifecycle, $this->hierarchy);
		$match    = new CodedValueMatch('urn:rood', ['uri' => 'urn:rood', 'label' => 'rood']);

		$this->assertNull($refusals->violationFor($this->subject(false), $match));

	}//end testAcceptableValueHasNoViolation()

	/**
	 * Outside its window, the value is refused naming the window; checked at the check's instant.
	 *
	 * @return void
	 */
	public function testOutsideWindowIsRefused(): void {
		$concept = ['uri' => 'urn:rood', 'label' => 'rood'];
		$this->lifecycle->expects($this->once())->method('isWithinWindow')
			->with($concept, $this->check->instant)
			->willReturn(false);
		$this->lifecycle->method('describeWindow')->with($concept)->willReturn('valid until 2025-12-31');
		$this->lifecycle->expects($this->never())->method('exclusiveGroupOf');

		$refusals = new CodedValueRefusals($this->lifecycle, $this->hierarchy);

		$this->assertSame(
			'The value "rood@nl" on "kleur" is outside its validity window (valid until 2025-12-31) and can no longer be chosen.',
			$refusals->violationFor($this->subject(true), new CodedValueMatch('urn:rood', $concept))
		);

	}//end testOutsideWindowIsRefused()

	/**
	 * A leaf-only field refuses a concept with narrower values, asked with the subject's concepts.
	 *
	 * @return void
	 */
	public function testNonLeafIsRefusedOnLeafOnlyField(): void {
		$this->lifecycle->method('isWithinWindow')->willReturn(true);
		$this->hierarchy->expects($this->once())->method('isLeaf')
			->with('urn:rood', ['urn:rood' => ['uri' => 'urn:rood']])
			->willReturn(false);

		$refusals = new CodedValueRefusals($this->lifecycle, $this->hierarchy);

		$this->assertSame(
			'The value "rood@nl" on "kleur" has narrower values under it, and this field accepts only the most specific value.',
			$refusals->violationFor($this->subject(true), new CodedValueMatch('x', ['uri' => 'urn:rood', 'label' => 'rood']))
		);

	}//end testNonLeafIsRefusedOnLeafOnlyField()

	/**
	 * A leaf on a leaf-only field passes through to the group check.
	 *
	 * @return void
	 */
	public function testLeafOnLeafOnlyFieldFallsThroughToGroup(): void {
		$this->lifecycle->method('isWithinWindow')->willReturn(true);
		$this->hierarchy->method('isLeaf')->willReturn(true);
		$this->lifecycle->method('exclusiveGroupOf')->willReturn('primair');

		$refusals = new CodedValueRefusals($this->lifecycle, $this->hierarchy);
		$subject  = $this->subject(true);

		$this->assertNull($refusals->violationFor($subject, new CodedValueMatch('a', ['label' => 'rood'])));
		$this->assertSame(
			'The values "rood@nl" and "blauw@nl" both belong to the group "primair", of which only one may be held.',
			$refusals->violationFor($subject, new CodedValueMatch('b', ['label' => 'blauw']))
		);

	}//end testLeafOnLeafOnlyFieldFallsThroughToGroup()
}//end class
