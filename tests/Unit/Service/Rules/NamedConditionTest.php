<?php

/**
 * Named conditions and transition-aware conditions: the refusals that must not
 * be passes, and the correction that has to reach twenty rules.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rules
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rules;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Calculation\CalculationEvaluator;
use OCA\OpenRegister\Service\Rules\ConditionDialect;
use OCA\OpenRegister\Service\Rules\ConditionRefusedException;
use OCA\OpenRegister\Service\Rules\NamedConditionEvaluator;
use OCA\OpenRegister\Service\Rules\NamedConditionLibrary;
use OCA\OpenRegister\Service\Rules\TransitionDocument;
use PHPUnit\Framework\TestCase;

/**
 * Verifies REQ-RCT-001 and REQ-RCT-002.
 */
class NamedConditionTest extends TestCase {

	/**
	 * The vocabulary.
	 *
	 * @var NamedConditionLibrary
	 */
	private NamedConditionLibrary $library;

	/**
	 * The evaluator.
	 *
	 * @var NamedConditionEvaluator
	 */
	private NamedConditionEvaluator $evaluator;

	/**
	 * The transition document builder.
	 *
	 * @var TransitionDocument
	 */
	private TransitionDocument $transition;

	/**
	 * Build the collaborators; none of them touches a database.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->library = new NamedConditionLibrary();
		$this->evaluator = new NamedConditionEvaluator(
			dialect: new ConditionDialect(ast: $this->createMock(CalculationEvaluator::class)),
			library: $this->library
		);
		$this->transition = new TransitionDocument();
	}//end setUp()

	/**
	 * A library declaring one condition on `spoed`.
	 *
	 * @return array<string, mixed> The annotation.
	 */
	private function spoedLibrary(): array {
		return [
			'spoedeisend' => [
				'description' => 'Een zaak die vandaag nog opgepakt moet worden',
				'expression' => ['==' => [['var' => 'prioriteit'], 'hoog']],
			],
		];
	}//end spoedLibrary()

	/**
	 * 🔴 One correction reaches every rule that names the condition.
	 *
	 * @return void
	 */
	public function testOneCorrectionReachesEveryRuleThatNamesIt(): void {
		$rules = [];
		for ($i = 0; $i < 20; $i++) {
			$rules['regel-' . $i] = [NamedConditionLibrary::REF => 'spoedeisend'];
		}

		$before = $this->library->libraryFrom(annotation: $this->spoedLibrary());
		foreach ($rules as $node) {
			$this->assertTrue(
				$this->evaluator->holds(node: $node, document: ['prioriteit' => 'hoog'], library: $before)
			);
		}

		// The correction: 'hoog' was the wrong value, it should be 'urgent'.
		$corrected = $this->spoedLibrary();
		$corrected['spoedeisend']['expression'] = ['==' => [['var' => 'prioriteit'], 'urgent']];
		$after = $this->library->libraryFrom(annotation: $corrected);

		foreach ($rules as $name => $node) {
			$this->assertFalse(
				$this->evaluator->holds(node: $node, document: ['prioriteit' => 'hoog'], library: $after),
				$name . ' must evaluate with the corrected expression, without being rewritten'
			);
		}
	}//end testOneCorrectionReachesEveryRuleThatNamesIt()

	/**
	 * A cycle between two named conditions is refused at save, naming both.
	 *
	 * @return void
	 */
	public function testACycleIsRefusedAtSaveNamingBoth(): void {
		$annotation = [
			'a' => ['expression' => [NamedConditionLibrary::REF => 'b']],
			'b' => ['expression' => [NamedConditionLibrary::REF => 'a']],
		];

		$refusal = $this->library->refusalFor(annotation: $annotation);

		$this->assertNotNull($refusal, 'a cycle must not be saveable');
		$this->assertStringContainsString('a', (string)$refusal);
		$this->assertStringContainsString('b', (string)$refusal);
		$this->assertStringContainsString('cycle', (string)$refusal);
	}//end testACycleIsRefusedAtSaveNamingBoth()

	/**
	 * A condition that references itself is a cycle of one.
	 *
	 * @return void
	 */
	public function testASelfReferenceIsACycle(): void {
		$this->assertNotNull(
			$this->library->refusalFor(
				annotation: ['a' => ['expression' => [NamedConditionLibrary::REF => 'a']]]
			),
			'the shortest cycle is still a cycle'
		);
	}//end testASelfReferenceIsACycle()

	/**
	 * An unknown name is refused at save, naming it and its owner.
	 *
	 * @return void
	 */
	public function testAnUnknownNameIsRefusedAtSave(): void {
		$refusal = $this->library->refusalFor(
			annotation: $this->spoedLibrary(),
			usingNodes: ['escalatie' => [NamedConditionLibrary::REF => 'spoedeisnd']]
		);

		$this->assertNotNull($refusal, 'a typo in a reference must not be saveable');
		$this->assertStringContainsString('spoedeisnd', (string)$refusal, 'the refusal names the name');
		$this->assertStringContainsString('escalatie', (string)$refusal, 'and what referenced it');
	}//end testAnUnknownNameIsRefusedAtSave()

	/**
	 * The control: a well-formed library and reference save.
	 *
	 * Without it, every refusal above could be passing because the validator
	 * refuses everything.
	 *
	 * @return void
	 */
	public function testAWellFormedLibraryIsAccepted(): void {
		$this->assertNull(
			$this->library->refusalFor(
				annotation: $this->spoedLibrary(),
				usingNodes: ['escalatie' => [NamedConditionLibrary::REF => 'spoedeisend']]
			),
			'the control: composition that is fine is accepted'
		);
	}//end testAWellFormedLibraryIsAccepted()

	/**
	 * 🔴 An unresolvable reference REFUSES at evaluation. It does not return
	 * false, which would fail OPEN one negation later.
	 *
	 * @return void
	 */
	public function testAnUnresolvableReferenceRefusesRatherThanPasses(): void {
		try {
			$this->evaluator->holds(
				node: [NamedConditionLibrary::REF => 'weggevallen'],
				document: [],
				library: []
			);
			$this->fail('an unresolvable reference must not produce a verdict');
		} catch (ConditionRefusedException $e) {
			$this->assertSame('weggevallen', $e->getConditionName(), 'the run log gets the name');
			$this->assertNotSame('', $e->getWhy(), 'and why');
		}
	}//end testAnUnresolvableReferenceRefusesRatherThanPasses()

	/**
	 * 🔴 And it refuses INSIDE a negation, which is where returning false
	 * would have turned into "fires on everything".
	 *
	 * @return void
	 */
	public function testAnUnresolvableReferenceInsideANegationAlsoRefuses(): void {
		$this->expectException(ConditionRefusedException::class);

		$this->evaluator->holds(
			node: ['not' => [NamedConditionLibrary::REF => 'weggevallen']],
			document: [],
			library: []
		);
	}//end testAnUnresolvableReferenceInsideANegationAlsoRefuses()

	/**
	 * A reference composed inside `and` and `or` evaluates.
	 *
	 * @return void
	 */
	public function testAReferenceComposesInsideAndAndOr(): void {
		$library = $this->library->libraryFrom(annotation: $this->spoedLibrary());

		$this->assertTrue(
			$this->evaluator->holds(
				node: ['and' => [[NamedConditionLibrary::REF => 'spoedeisend'], ['==' => [['var' => 'status'], 'open']]]],
				document: ['prioriteit' => 'hoog', 'status' => 'open'],
				library: $library
			)
		);

		$this->assertFalse(
			$this->evaluator->holds(
				node: ['and' => [[NamedConditionLibrary::REF => 'spoedeisend'], ['==' => [['var' => 'status'], 'open']]]],
				document: ['prioriteit' => 'laag', 'status' => 'open'],
				library: $library
			),
			'and the composed clause still decides the verdict'
		);
	}//end testAReferenceComposesInsideAndAndOr()

	/**
	 * A reference inside a shape this evaluator cannot compose refuses.
	 *
	 * @return void
	 */
	public function testAReferenceInsideAnUncomposableShapeRefuses(): void {
		$this->expectException(ConditionRefusedException::class);

		$this->evaluator->holds(
			node: ['if' => [[NamedConditionLibrary::REF => 'spoedeisend'], true, false]],
			document: [],
			library: $this->library->libraryFrom(annotation: $this->spoedLibrary())
		);
	}//end testAReferenceInsideAnUncomposableShapeRefuses()

	/**
	 * Composition deeper than the administered depth is refused at save.
	 *
	 * @return void
	 */
	public function testCompositionDeeperThanTheCeilingIsRefused(): void {
		$annotation = [];
		for ($i = 0; $i <= (NamedConditionLibrary::MAX_DEPTH + 1); $i++) {
			$annotation['c' . $i] = ['expression' => [NamedConditionLibrary::REF => 'c' . ($i + 1)]];
		}

		$annotation['c' . (NamedConditionLibrary::MAX_DEPTH + 2)] = ['expression' => true];

		$this->assertNotNull(
			$this->library->refusalFor(annotation: $annotation),
			'a chain deeper than the ceiling must be refused where somebody can read it'
		);
	}//end testCompositionDeeperThanTheCeilingIsRefused()

	/**
	 * The inventory says which rules use a named condition.
	 *
	 * @return void
	 */
	public function testTheInventorySaysWhoUsesIt(): void {
		$usage = $this->library->usage(
			usingNodes: [
				'escalatie' => [NamedConditionLibrary::REF => 'spoedeisend'],
				'herinnering' => ['and' => [[NamedConditionLibrary::REF => 'spoedeisend'], true]],
				'afsluiting' => ['==' => [['var' => 'status'], 'klaar']],
			]
		);

		$this->assertSame(['spoedeisend' => ['escalatie', 'herinnering']], $usage);
	}//end testTheInventorySaysWhoUsesIt()

	/**
	 * A declaration with no expression behind it is not a condition.
	 *
	 * @return void
	 */
	public function testADeclarationWithNoExpressionIsDropped(): void {
		$library = $this->library->libraryFrom(
			annotation: ['leeg' => ['description' => 'niets'], 'echt' => ['expression' => true]]
		);

		$this->assertSame(['echt'], array_keys($library), 'a name with nothing behind it is not a condition');
	}//end testADeclarationWithNoExpressionIsDropped()

	/**
	 * 🔴 The annotation is in the schema vocabulary, or it is dropped on save.
	 *
	 * @return void
	 */
	public function testTheAnnotationIsInTheSchemaVocabulary(): void {
		$this->assertContains(
			NamedConditionLibrary::ANNOTATION,
			Schema::ANNOTATION_VOCABULARY,
			'absent from the vocabulary, setConfiguration() drops the library and every rule referencing it refuses'
		);
	}//end testTheAnnotationIsInTheSchemaVocabulary()

	/**
	 * 🔴 On a create, `$before` is ABSENT, not null and not an empty array.
	 *
	 * @return void
	 */
	public function testOnACreateTheBeforeEnvelopeIsAbsent(): void {
		$document = $this->transition->build(after: ['status' => 'nieuw'], before: null);

		$this->assertArrayNotHasKey(
			TransitionDocument::BEFORE,
			$document,
			'a null before would make `$before.status == null` match every create'
		);
		$this->assertSame(['status' => 'nieuw'], $document[TransitionDocument::AFTER]);
	}//end testOnACreateTheBeforeEnvelopeIsAbsent()

	/**
	 * The after values stay at the top level, so existing rules keep meaning
	 * what they meant.
	 *
	 * @return void
	 */
	public function testTheAfterValuesStayAtTheTopLevel(): void {
		$document = $this->transition->build(after: ['status' => 'open'], before: ['status' => 'nieuw']);

		$this->assertSame('open', $document['status'], 'every rule written before this reads the value being saved');
		$this->assertSame('nieuw', $document[TransitionDocument::BEFORE]['status']);
		$this->assertSame('open', $document[TransitionDocument::AFTER]['status']);
	}//end testTheAfterValuesStayAtTheTopLevel()

	/**
	 * Entering a status is distinguishable from being in it.
	 *
	 * @return void
	 */
	public function testEnteringAStatusIsDistinguishableFromBeingInIt(): void {
		$movedIn = [
			'and' => [
				['==' => [['var' => '$after.status'], 'afgehandeld']],
				['!=' => [['var' => '$before.status'], 'afgehandeld']],
			],
		];
		$isIn = ['==' => [['var' => '$after.status'], 'afgehandeld']];

		$firstSave = $this->transition->build(
			after: ['status' => 'afgehandeld'],
			before: ['status' => 'in behandeling']
		);
		$secondSave = $this->transition->build(
			after: ['status' => 'afgehandeld'],
			before: ['status' => 'afgehandeld']
		);

		$this->assertTrue($this->evaluator->holds(node: $movedIn, document: $firstSave, library: []));
		$this->assertFalse(
			$this->evaluator->holds(node: $movedIn, document: $secondSave, library: []),
			'"moved into" must fire on the first save only'
		);

		$this->assertTrue($this->evaluator->holds(node: $isIn, document: $firstSave, library: []));
		$this->assertTrue(
			$this->evaluator->holds(node: $isIn, document: $secondSave, library: []),
			'and "is in" must fire on both, or the two are still the same condition'
		);
	}//end testEnteringAStatusIsDistinguishableFromBeingInIt()

	/**
	 * A rule reading the prior value without declaring it is refused.
	 *
	 * @return void
	 */
	public function testARuleReadingThePriorValueWithoutDeclaringItIsRefused(): void {
		$refusal = $this->transition->refusalFor(
			ruleName: 'escalatie',
			node: ['!=' => [['var' => '$before.status'], 'open']],
			rule: [],
			trigger: 'update'
		);

		$this->assertNotNull($refusal, 'an undeclared read makes the declaration decoration');
		$this->assertStringContainsString('escalatie', (string)$refusal);
	}//end testARuleReadingThePriorValueWithoutDeclaringItIsRefused()

	/**
	 * 🔴 A rule needing a before value cannot be attached to a create.
	 *
	 * @return void
	 */
	public function testARuleNeedingABeforeValueCannotBeAttachedToACreate(): void {
		$refusal = $this->transition->refusalFor(
			ruleName: 'escalatie',
			node: ['!=' => [['var' => '$before.status'], 'open']],
			rule: [TransitionDocument::REQUIRES_PRIOR => true],
			trigger: 'create'
		);

		$this->assertNotNull($refusal, 'it would never match, which is worse than failing');
		$this->assertStringContainsString('escalatie', (string)$refusal, 'the refusal names the rule');
	}//end testARuleNeedingABeforeValueCannotBeAttachedToACreate()

	/**
	 * The control: the same rule on an update trigger is accepted.
	 *
	 * @return void
	 */
	public function testTheSameRuleOnAnUpdateTriggerIsAccepted(): void {
		$this->assertNull(
			$this->transition->refusalFor(
				ruleName: 'escalatie',
				node: ['!=' => [['var' => '$before.status'], 'open']],
				rule: [TransitionDocument::REQUIRES_PRIOR => true],
				trigger: 'update'
			),
			'the control: a declared prior read on a trigger that has one is fine'
		);
	}//end testTheSameRuleOnAnUpdateTriggerIsAccepted()

	/**
	 * The run log gets which operand decided the verdict.
	 *
	 * @return void
	 */
	public function testTheRunLogRecordsWhichOperandDecided(): void {
		$node = ['!=' => [['var' => '$before.status'], ['var' => '$after.status']]];

		$this->assertSame(
			'transition',
			$this->transition->decidedBy(
				node: $node,
				document: $this->transition->build(after: ['status' => 'open'], before: ['status' => 'nieuw'])
			)
		);

		$this->assertSame(
			'unchanged',
			$this->transition->decidedBy(
				node: $node,
				document: $this->transition->build(after: ['status' => 'open'], before: ['status' => 'open'])
			),
			'a rule whose two sides hold the same value did not turn on a move that never happened'
		);

		$this->assertSame(
			'after',
			$this->transition->decidedBy(
				node: ['==' => [['var' => 'status'], 'open']],
				document: $this->transition->build(after: ['status' => 'open'], before: ['status' => 'nieuw'])
			)
		);
	}//end testTheRunLogRecordsWhichOperandDecided()
}//end class
