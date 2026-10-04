<?php

/**
 * Tests for LifecycleTransitionsValidator: a lifecycle passed as data.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Lifecycle
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Lifecycle;

use OCA\OpenRegister\Service\Lifecycle\LifecycleTransitionsValidator;
use PHPUnit\Framework\TestCase;

/**
 * A lifecycle that an app keeps as DATA (an authored template, not a schema
 * annotation) is checked through one entry point: empty states, the initial
 * state, malformed and dangling transitions, states no transition touches, and
 * guard tokens outside the app's catalogue.
 */
final class LifecycleTransitionsValidatorTest extends TestCase {

	private LifecycleTransitionsValidator $validator;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->validator = new LifecycleTransitionsValidator();
	}//end setUp()

	/**
	 * The error codes of a result, in order.
	 *
	 * @param array<int, array{code: string, message: string}> $errors The result.
	 *
	 * @return array<int, string>
	 */
	private function codes(array $errors): array {
		return array_column($errors, 'code');
	}//end codes()

	/**
	 * All messages of a result, joined.
	 *
	 * @param array<int, array{code: string, message: string}> $errors The result.
	 *
	 * @return string
	 */
	private function messages(array $errors): string {
		return implode(' ', array_column($errors, 'message'));
	}//end messages()

	/**
	 * A complete graph is valid.
	 *
	 * @return void
	 */
	public function testValidGraphHasNoErrors(): void {
		$errors = $this->validator->validate(
			states: ['draft', 'proposed', 'decided'],
			initial: 'draft',
			transitions: [
				['from' => 'draft', 'to' => 'proposed', 'guards' => ['quorum_met']],
				['from' => ['proposed'], 'to' => 'decided'],
			],
			knownGuards: ['quorum_met', 'chair_only']
		);

		self::assertSame([], $errors);
	}//end testValidGraphHasNoErrors()

	/**
	 * Transitions may also be keyed by action name, the way a schema declares them.
	 *
	 * @return void
	 */
	public function testTransitionsKeyedByActionAreAccepted(): void {
		$errors = $this->validator->validate(
			states: ['open', 'closed'],
			initial: 'open',
			transitions: ['close' => ['from' => 'open', 'to' => 'closed']]
		);

		self::assertSame([], $errors);
	}//end testTransitionsKeyedByActionAreAccepted()

	/**
	 * No states at all is refused.
	 *
	 * @return void
	 */
	public function testEmptyStatesAreRefused(): void {
		$errors = $this->validator->validate(states: [], initial: 'draft', transitions: []);

		self::assertContains('lifecycle-states-empty', $this->codes($errors));
	}//end testEmptyStatesAreRefused()

	/**
	 * A missing initial state is refused, and so is one that is not declared.
	 *
	 * @return void
	 */
	public function testInitialStateMustBeDeclared(): void {
		$missing = $this->validator->validate(states: ['a', 'b'], initial: null, transitions: [['from' => 'a', 'to' => 'b']]);
		$unknown = $this->validator->validate(states: ['a', 'b'], initial: 'ghost', transitions: [['from' => 'a', 'to' => 'b']]);

		self::assertContains('lifecycle-initial-missing', $this->codes($missing));
		self::assertContains('lifecycle-initial-not-declared', $this->codes($unknown));
		self::assertStringContainsString('ghost', $this->messages($unknown));
	}//end testInitialStateMustBeDeclared()

	/**
	 * A transition endpoint that is not a declared state is refused, naming it.
	 *
	 * @return void
	 */
	public function testDanglingTransitionIsRefused(): void {
		$errors = $this->validator->validate(
			states: ['draft', 'decided'],
			initial: 'draft',
			transitions: [['from' => 'draft', 'to' => 'decided'], ['from' => 'decided', 'to' => 'ghost']]
		);

		self::assertSame(['lifecycle-to-not-declared'], $this->codes($errors));
		self::assertStringContainsString('ghost', $this->messages($errors));
	}//end testDanglingTransitionIsRefused()

	/**
	 * A transition without from or to, or not an object, is refused.
	 *
	 * @return void
	 */
	public function testMalformedTransitionsAreRefused(): void {
		$errors = $this->validator->validate(
			states: ['a', 'b'],
			initial: 'a',
			transitions: ['oops', ['from' => 'a'], ['to' => 'b', 'from' => []], ['from' => 'a', 'to' => 'b']]
		);

		self::assertSame(
			['lifecycle-transition-malformed', 'lifecycle-to-missing', 'lifecycle-from-missing'],
			$this->codes($errors)
		);
	}//end testMalformedTransitionsAreRefused()

	/**
	 * A declared state that no transition touches, other than the initial one, is refused.
	 *
	 * @return void
	 */
	public function testStateNoTransitionTouchesIsRefused(): void {
		$errors = $this->validator->validate(
			states: ['draft', 'decided', 'orphan'],
			initial: 'draft',
			transitions: [['from' => 'draft', 'to' => 'decided']]
		);

		self::assertSame(['lifecycle-state-unreachable'], $this->codes($errors));
		self::assertStringContainsString('orphan', $this->messages($errors));
		self::assertStringContainsString('unreachable', $this->messages($errors));
	}//end testStateNoTransitionTouchesIsRefused()

	/**
	 * A lone initial state with no transitions is a valid one-state lifecycle.
	 *
	 * @return void
	 */
	public function testLoneInitialStateIsNotUnreachable(): void {
		self::assertSame([], $this->validator->validate(states: ['only'], initial: 'only', transitions: []));
	}//end testLoneInitialStateIsNotUnreachable()

	/**
	 * A guard token outside the catalogue is refused, so a typo never disables a guard.
	 *
	 * @return void
	 */
	public function testUnknownGuardIsRefusedAgainstACatalogue(): void {
		$errors = $this->validator->validate(
			states: ['a', 'b'],
			initial: 'a',
			transitions: [['from' => 'a', 'to' => 'b', 'guards' => ['quorum_met', 'made_up_token', 7]]],
			knownGuards: ['quorum_met']
		);

		self::assertSame(['lifecycle-guard-unknown', 'lifecycle-guard-unknown'], $this->codes($errors));
		self::assertStringContainsString('made_up_token', $this->messages($errors));
	}//end testUnknownGuardIsRefusedAgainstACatalogue()

	/**
	 * Without a catalogue, guards are shape-checked only: a non-empty string list.
	 *
	 * @return void
	 */
	public function testGuardsWithoutACatalogueAreShapeChecked(): void {
		$ok = $this->validator->validate(
			states: ['a', 'b'],
			initial: 'a',
			transitions: [['from' => 'a', 'to' => 'b', 'guards' => ['anything']]]
		);
		$bad = $this->validator->validate(
			states: ['a', 'b'],
			initial: 'a',
			transitions: [['from' => 'a', 'to' => 'b', 'guards' => 'not-a-list']]
		);

		self::assertSame([], $ok);
		self::assertSame(['lifecycle-guards-malformed'], $this->codes($bad));
	}//end testGuardsWithoutACatalogueAreShapeChecked()

	/**
	 * States may be passed as `{name}` objects, the shape an authored template keeps.
	 *
	 * @return void
	 */
	public function testStatesAsNamedObjectsAreAccepted(): void {
		$errors = $this->validator->validate(
			states: [['name' => 'a'], ['name' => 'b'], ['label' => 'no name']],
			initial: 'a',
			transitions: [['from' => 'a', 'to' => 'b']]
		);

		self::assertSame([], $errors);
	}//end testStatesAsNamedObjectsAreAccepted()
}//end class
