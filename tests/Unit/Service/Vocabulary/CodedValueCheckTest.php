<?php

/**
 * Unit tests for {@see \OCA\OpenRegister\Service\Vocabulary\CodedValueCheck}.
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
use OCA\OpenRegister\Service\Vocabulary\CodedValueRules;
use OCA\OpenRegister\Service\Vocabulary\CodedValueSubject;
use PHPUnit\Framework\TestCase;

/**
 * One object's coded-value check and the subjects it hands out.
 *
 * @covers \OCA\OpenRegister\Service\Vocabulary\CodedValueCheck
 * @covers \OCA\OpenRegister\Service\Vocabulary\CodedValueSubject
 * @uses   \OCA\OpenRegister\Service\Vocabulary\CodedNarrowing
 * @uses   \OCA\OpenRegister\Service\Vocabulary\CodedPropertyDeclaration
 * @uses   \OCA\OpenRegister\Service\Vocabulary\CodedValueMatch
 * @uses   \OCA\OpenRegister\Service\Vocabulary\CodedValueRules
 */
class CodedValueCheckTest extends TestCase {

	/**
	 * The instant is the given one, or now when none is given.
	 *
	 * @return void
	 */
	public function testInstant(): void {
		$asOf = new DateTimeImmutable('2020-05-05T10:00:00Z');
		$this->assertSame($asOf, (new CodedValueCheck([], $asOf))->instant);

		$before = new DateTimeImmutable();
		$now    = (new CodedValueCheck([]))->instant;
		$this->assertGreaterThanOrEqual($before->getTimestamp(), $now->getTimestamp());
		$this->assertLessThanOrEqual(time() + 1, $now->getTimestamp());

	}//end testInstant()

	/**
	 * The first claim on a key wins and later claims are told who holds it.
	 *
	 * @return void
	 */
	public function testClaimIsFirstComeFirstServed(): void {
		$check = new CodedValueCheck([]);

		$this->assertNull($check->claim('g', 'rood'));
		$this->assertSame('rood', $check->claim('g', 'blauw'));
		$this->assertSame('rood', $check->claim('g', 'geel'));
		$this->assertNull($check->claim('h', 'mat'));

	}//end testClaimIsFirstComeFirstServed()

	/**
	 * Submitted values are read from the object, null when absent.
	 *
	 * @return void
	 */
	public function testSubmitted(): void {
		$check = new CodedValueCheck(['kleur' => 'urn:rood', 'leeg' => null]);

		$this->assertSame('urn:rood', $check->submitted('kleur'));
		$this->assertNull($check->submitted('leeg'));
		$this->assertNull($check->submitted('maat'));

	}//end testSubmitted()

	/**
	 * A subject is bound to its property, declaration and this check.
	 *
	 * @return void
	 */
	public function testSubjectFor(): void {
		$check       = new CodedValueCheck(['kleur' => ['urn:a', 'urn:b']]);
		$declaration = new CodedPropertyDeclaration('kleuren', new CodedValueRules(), new CodedNarrowing());

		$subject = $check->subjectFor('kleur', $declaration);

		$this->assertInstanceOf(CodedValueSubject::class, $subject);
		$this->assertSame('kleur', $subject->property);
		$this->assertSame($declaration, $subject->declaration);
		$this->assertSame($check, $subject->check);
		$this->assertSame([], $subject->conceptsByUri);
		$this->assertSame(['urn:a', 'urn:b'], $subject->submitted());

	}//end testSubjectFor()

	/**
	 * WithConcepts() returns a new subject carrying the concepts; matched() wraps a concept.
	 *
	 * @return void
	 */
	public function testSubjectWithConceptsAndMatch(): void {
		$check   = new CodedValueCheck([]);
		$subject = $check->subjectFor('kleur', new CodedPropertyDeclaration('kleuren', new CodedValueRules(), new CodedNarrowing()));

		$withConcepts = $subject->withConcepts(['urn:a' => ['uri' => 'urn:a']]);
		$this->assertNotSame($subject, $withConcepts);
		$this->assertSame([], $subject->conceptsByUri);
		$this->assertSame(['urn:a' => ['uri' => 'urn:a']], $withConcepts->conceptsByUri);
		$this->assertSame($check, $withConcepts->check);
		$this->assertSame('kleur', $withConcepts->property);

		$match = $subject->matched('A', ['notation' => 'A']);
		$this->assertInstanceOf(CodedValueMatch::class, $match);
		$this->assertSame('A', $match->value);
		$this->assertSame('A', $match->uri());
		$this->assertSame('urn:x', $subject->matched('A', ['uri' => 'urn:x'])->uri());

	}//end testSubjectWithConceptsAndMatch()
}//end class
