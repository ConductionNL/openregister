<?php

/**
 * OpenRegister GraphMoveCatalogue
 *
 * Reads a lifecycle graph's forms, side moves and reopen declaration, checks
 * the values a move's form accepts, and picks a reopen target among siblings.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/lifecycle-over-reference-field/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Lifecycle;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\InvalidTransitionInputException;
use OCA\OpenRegister\Service\Lifecycle\GraphMoveCatalogue;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Lifecycle\GraphMoveCatalogue
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Exception\InvalidTransitionInputException
 */
class GraphMoveCatalogueTest extends TestCase {

	/**
	 * The catalogue under test.
	 *
	 * @var GraphMoveCatalogue
	 */
	private GraphMoveCatalogue $catalogue;

	/**
	 * Build the catalogue.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->catalogue = new GraphMoveCatalogue();
	}//end setUp()

	/**
	 * A form keeps only named properties, and only required ones it declares.
	 *
	 * @return void
	 */
	public function testAFormKeepsOnlyNamedPropertiesAndTheirRequiredSubset(): void {
		$form = $this->catalogue->form(
			form: ['properties' => ['reason', '', 7, 'note'], 'required' => ['reason', 'unknown', 3]]
		);

		self::assertSame(['properties' => ['reason', 'note'], 'required' => ['reason']], $form);
	}//end testAFormKeepsOnlyNamedPropertiesAndTheirRequiredSubset()

	/**
	 * Anything that is not a form with at least one named property is no form.
	 *
	 * @return void
	 */
	public function testSomethingThatIsNotAFormIsNoForm(): void {
		self::assertNull($this->catalogue->form(form: null));
		self::assertNull($this->catalogue->form(form: 'reason'));
		self::assertNull($this->catalogue->form(form: ['properties' => 'reason']));
		self::assertNull($this->catalogue->form(form: ['properties' => ['', 1]]));
	}//end testSomethingThatIsNotAFormIsNoForm()

	/**
	 * A graph declares forms on the move, on its reopen, or on a side move.
	 *
	 * @return void
	 */
	public function testAGraphDeclaresFormsWhereverOneIsDeclared(): void {
		$form = ['properties' => ['reason']];

		self::assertTrue($this->catalogue->declaresForms(graph: ['form' => $form]));
		self::assertTrue($this->catalogue->declaresForms(graph: ['reopen' => ['form' => $form]]));
		self::assertTrue($this->catalogue->declaresForms(graph: ['sideMoves' => ['suspend' => ['field' => 'suspended', 'form' => $form]]]));
		self::assertFalse($this->catalogue->declaresForms(graph: ['reopen' => [], 'sideMoves' => ['suspend' => ['field' => 'suspended']]]));
		self::assertFalse($this->catalogue->declaresForms(graph: []));
	}//end testAGraphDeclaresFormsWhereverOneIsDeclared()

	/**
	 * A form declared `on: final` applies only to a move into a final state.
	 *
	 * @return void
	 */
	public function testAFinalOnlyFormAppliesOnlyToAFinalMove(): void {
		$graph = ['form' => ['on' => 'final', 'properties' => ['outcome'], 'required' => ['outcome']]];

		self::assertNull($this->catalogue->moveForm(graph: $graph, targetFinal: false));
		self::assertSame(['properties' => ['outcome'], 'required' => ['outcome']], $this->catalogue->moveForm(graph: $graph, targetFinal: true));

		$any = ['form' => ['properties' => ['note']]];
		self::assertSame(['properties' => ['note'], 'required' => []], $this->catalogue->moveForm(graph: $any, targetFinal: false));
	}//end testAFinalOnlyFormAppliesOnlyToAFinalMove()

	/**
	 * A published action carries its form's inputs; without a form it is unchanged.
	 *
	 * @return void
	 */
	public function testAnActionCarriesItsFormsInputs(): void {
		$action = ['action' => 'close', 'inputs' => []];
		self::assertSame($action, $this->catalogue->withForm(action: $action, form: null));

		$form = ['properties' => ['outcome', 'note'], 'required' => ['outcome']];
		$published = $this->catalogue->withForm(action: $action, form: $form);

		self::assertSame(
			[['field' => 'outcome', 'required' => true], ['field' => 'note', 'required' => false]],
			$published['inputs']
		);
		self::assertSame($form, $published['form']);
		self::assertSame('close', $published['action']);
	}//end testAnActionCarriesItsFormsInputs()

	/**
	 * Values the form declares, with every required one present, are accepted as given.
	 *
	 * @return void
	 */
	public function testDeclaredValuesAreAccepted(): void {
		$form = ['properties' => ['outcome', 'note'], 'required' => ['outcome']];
		$data = ['outcome' => 'granted', 'note' => ''];

		self::assertSame($data, $this->catalogue->acceptedValues(form: $form, data: $data, action: 'close'));
		self::assertSame([], $this->catalogue->acceptedValues(form: null, data: [], action: 'close'));
	}//end testDeclaredValuesAreAccepted()

	/**
	 * A value the form does not declare is refused and named.
	 *
	 * @return void
	 */
	public function testAnUndeclaredValueIsRefusedAndNamed(): void {
		$form = ['properties' => ['outcome'], 'required' => []];

		try {
			$this->catalogue->acceptedValues(form: $form, data: ['outcome' => 'x', 'secret' => 1, 3 => 'y'], action: 'close');
			self::fail('An undeclared value was accepted.');
		} catch (InvalidTransitionInputException $e) {
			self::assertSame(['secret', '3'], $e->getFields());
			self::assertSame('Transition "close" does not accept input field(s): "secret", "3".', $e->getMessage());
		}
	}//end testAnUndeclaredValueIsRefusedAndNamed()

	/**
	 * A required value that is absent, null or empty is refused and named.
	 *
	 * @return void
	 */
	public function testAMissingRequiredValueIsRefusedAndNamed(): void {
		$form = ['properties' => ['outcome', 'reason', 'note'], 'required' => ['outcome', 'reason', 'note']];

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Transition "close" is missing required form value(s): "outcome", "reason", "note".');

		$this->catalogue->acceptedValues(form: $form, data: ['reason' => null, 'note' => ''], action: 'close');
	}//end testAMissingRequiredValueIsRefusedAndNamed()

	/**
	 * A side move whose flag is set freezes the graph; an unset flag does not.
	 *
	 * @return void
	 */
	public function testASetSideMoveFlagFreezesTheGraph(): void {
		$graph = ['sideMoves' => ['suspend' => ['field' => 'suspended'], 'resume' => ['field' => 'suspended', 'set' => false]]];

		self::assertTrue($this->catalogue->frozen(graph: $graph, data: ['suspended' => true]));
		self::assertFalse($this->catalogue->frozen(graph: $graph, data: ['suspended' => false]));
		self::assertFalse($this->catalogue->frozen(graph: $graph, data: ['suspended' => 'yes']));
		self::assertFalse($this->catalogue->frozen(graph: [], data: ['suspended' => true]));
	}//end testASetSideMoveFlagFreezesTheGraph()

	/**
	 * On a running object only the side moves that change its flag, and the field-less ones, are offered.
	 *
	 * @return void
	 */
	public function testARunningObjectIsOfferedTheMovesThatChangeIt(): void {
		$graph = [
			'sideMoves' => [
				'suspend' => ['field' => 'suspended', 'label' => 'Suspend', 'form' => ['properties' => ['reason'], 'required' => ['reason']]],
				'resume' => ['field' => 'suspended', 'set' => false],
				'remind' => ['label' => ''],
				''  => ['field' => 'ignored'],
				'broken' => 'not a side move',
			],
		];

		$candidates = $this->catalogue->sideMoveCandidates(graph: $graph, data: ['suspended' => false], current: 'intake');

		self::assertSame(['suspend', 'remind'], array_column(array_column($candidates, 'published'), 'action'));

		$suspend = $candidates[0];
		self::assertSame(['suspended' => true], $suspend['write']);
		self::assertSame('intake', $suspend['published']['to']);
		self::assertSame('Suspend', $suspend['published']['label']);
		self::assertNull($suspend['published']['requires']);
		self::assertSame([['field' => 'reason', 'required' => true]], $suspend['published']['inputs']);
		self::assertSame(['properties' => ['reason'], 'required' => ['reason']], $suspend['form']);

		$remind = $candidates[1];
		self::assertSame([], $remind['write']);
		self::assertSame('remind', $remind['published']['label']);
		self::assertSame([], $remind['published']['inputs']);
		self::assertNull($remind['form']);
	}//end testARunningObjectIsOfferedTheMovesThatChangeIt()

	/**
	 * A frozen object is offered only the move that unfreezes it.
	 *
	 * @return void
	 */
	public function testAFrozenObjectIsOfferedOnlyTheMoveThatUnfreezesIt(): void {
		$graph = [
			'sideMoves' => [
				'suspend' => ['field' => 'suspended'],
				'resume' => ['field' => 'suspended', 'set' => false, 'label' => 'Resume'],
				'remind' => [],
			],
		];

		$candidates = $this->catalogue->sideMoveCandidates(graph: $graph, data: ['suspended' => true], current: 'review');

		self::assertCount(1, $candidates);
		self::assertSame('resume', $candidates[0]['published']['action']);
		self::assertSame('Resume', $candidates[0]['published']['label']);
		self::assertSame(['suspended' => false], $candidates[0]['write']);
	}//end testAFrozenObjectIsOfferedOnlyTheMoveThatUnfreezesIt()

	/**
	 * A reopen declaration gets its defaults where it leaves something out or gets it wrong.
	 *
	 * @return void
	 */
	public function testAReopenGetsItsDefaults(): void {
		self::assertNull($this->catalogue->reopen(graph: []));
		self::assertNull($this->catalogue->reopen(graph: ['reopen' => true]));

		self::assertSame(
			['to' => 'first', 'requires' => GraphMoveCatalogue::REOPEN_REQUIRES, 'label' => 'Reopen', 'form' => null],
			$this->catalogue->reopen(graph: ['reopen' => ['requires' => '  ', 'label' => '']])
		);
		self::assertSame(
			['to' => 'first', 'requires' => GraphMoveCatalogue::REOPEN_REQUIRES, 'label' => 'Reopen', 'form' => null],
			$this->catalogue->reopen(graph: ['reopen' => ['requires' => 5, 'label' => 5]])
		);
	}//end testAReopenGetsItsDefaults()

	/**
	 * A complete reopen declaration is read as written.
	 *
	 * @return void
	 */
	public function testAReopenIsReadAsWritten(): void {
		$reopen = $this->catalogue->reopen(
			graph: ['reopen' => ['to' => ['phase' => 'review'], 'requires' => 'reopen-case', 'label' => 'Heropenen', 'form' => ['properties' => ['why']]]]
		);

		self::assertSame(
			['to' => ['phase' => 'review'], 'requires' => 'reopen-case', 'label' => 'Heropenen', 'form' => ['properties' => ['why'], 'required' => []]],
			$reopen
		);
	}//end testAReopenIsReadAsWritten()

	/**
	 * `first` reopens into the first sibling; nothing to reopen into is null.
	 *
	 * @return void
	 */
	public function testFirstReopensIntoTheFirstSibling(): void {
		$first = $this->sibling(uuid: 'a', data: []);
		$second = $this->sibling(uuid: 'b', data: []);

		self::assertSame($first, $this->catalogue->reopenTarget(target: 'first', siblings: [$first, $second]));
		self::assertNull($this->catalogue->reopenTarget(target: 'first', siblings: []));
	}//end testFirstReopensIntoTheFirstSibling()

	/**
	 * A uuid target picks that sibling; an empty or unknown uuid picks none.
	 *
	 * @return void
	 */
	public function testAUuidTargetPicksThatSibling(): void {
		$first = $this->sibling(uuid: 'a', data: []);
		$second = $this->sibling(uuid: 'b', data: []);

		self::assertSame($second, $this->catalogue->reopenTarget(target: 'b', siblings: [$first, $second]));
		self::assertNull($this->catalogue->reopenTarget(target: 'c', siblings: [$first, $second]));
		self::assertNull($this->catalogue->reopenTarget(target: '', siblings: [$first, $second]));
	}//end testAUuidTargetPicksThatSibling()

	/**
	 * A property target picks the first sibling holding every property strictly.
	 *
	 * @return void
	 */
	public function testAPropertyTargetPicksTheSiblingThatHoldsEveryProperty(): void {
		$intake = $this->sibling(uuid: 'a', data: ['phase' => 'intake', 'order' => 1]);
		$review = $this->sibling(uuid: 'b', data: ['phase' => 'review', 'order' => 2]);

		self::assertSame($review, $this->catalogue->reopenTarget(target: ['phase' => 'review', 'order' => 2], siblings: [$intake, $review]));
		self::assertNull($this->catalogue->reopenTarget(target: ['phase' => 'review', 'order' => '2'], siblings: [$intake, $review]));
		self::assertNull($this->catalogue->reopenTarget(target: ['missing' => 'x'], siblings: [$intake, $review]));
		self::assertNull($this->catalogue->reopenTarget(target: [], siblings: [$intake, $review]));
		self::assertNull($this->catalogue->reopenTarget(target: 42, siblings: [$intake, $review]));
	}//end testAPropertyTargetPicksTheSiblingThatHoldsEveryProperty()

	/**
	 * A sibling object.
	 *
	 * @param string               $uuid The uuid.
	 * @param array<string, mixed> $data The object data.
	 *
	 * @return ObjectEntity
	 */
	private function sibling(string $uuid, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($data);
		return $entity;
	}//end sibling()
}//end class
