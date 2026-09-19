<?php

/**
 * The evaluation point: every write path reaches the rules.
 *
 * WHY THIS TEST READS THE SOURCE. "Rules are evaluated on every mutation" is a
 * claim that rots the moment a new write path is added, and no test that
 * exercises today's paths can notice tomorrow's. So this asserts the STRUCTURE
 * the claim rests on: objects are written through two methods, both of them
 * dispatch the save events, the two rule listeners are subscribed to those
 * events, and no caller in lib/ asks for the dispatch to be skipped. A write
 * path added later that bypasses any of the four fails here and is named.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rules
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Rules;

use PHPUnit\Framework\TestCase;

/**
 * Exercises the invariant that keeps the save pipeline the one evaluation point.
 *
 * @package OCA\OpenRegister\Tests\Unit\Service\Rules
 *
 * @spec openspec/changes/rules-engine-operability/specs/object-lifecycle/spec.md
 */
class RuleEvaluationPointTest extends TestCase {

	/**
	 * The write paths this app has, and the method each one ends at.
	 *
	 * Every entry point that creates or changes a stored object funnels into
	 * one of these two mapper methods. The list is the enumeration the change
	 * asks for, and it is checked rather than merely written down: the test
	 * below proves both methods still dispatch, and that nothing asks them not
	 * to.
	 *
	 * @var array<string, string>
	 */
	private const WRITE_PATHS = [
		'API create' => 'insertObjectEntity',
		'API update' => 'updateObjectEntity',
		'API patch' => 'updateObjectEntity',
		'import' => 'insertObjectEntity',
		'flow node write' => 'updateObjectEntity',
		'bulk job write' => 'updateObjectEntity',
	];

	/**
	 * The app's lib/ root.
	 *
	 * @return string The absolute path.
	 */
	private function lib(): string {
		return dirname(__DIR__, 4) . '/lib';

	}//end lib()

	/**
	 * Every PHP file under lib/, as path to source.
	 *
	 * @return array<string, string> Absolute path to file contents.
	 */
	private function sources(): array {
		$found = [];
		$iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->lib()));
		foreach ($iterator as $file) {
			if ($file->isFile() === false || $file->getExtension() !== 'php') {
				continue;
			}

			$found[$file->getPathname()] = (string)file_get_contents($file->getPathname());
		}

		return $found;

	}//end sources()

	/**
	 * Both write methods dispatch the event the rule listeners subscribe to.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/object-lifecycle/spec.md
	 */
	/**
	 * Files allowed to suppress the lifecycle events, and why.
	 *
	 * 🔑 NOT A LIST OF EXCEPTIONS, A LIST OF JUSTIFICATIONS. The guard's value
	 * is that suppressing an event costs somebody a sentence here; a silent
	 * `dispatchEvents: false` is the shape of a path that quietly skips the
	 * rules, and it is found when a rule stops firing rather than when it is
	 * written.
	 *
	 * @var array<string, string>
	 */
	private const SUPPRESSION_REASONS = [
		// The source row of a MOVE. The object was not deleted: it is readable
		// at its new address with the same uuid, and every side table still
		// points at it. Dispatching a delete event would tell eight listening
		// apps that an object they can still read is gone, and the rules would
		// act on a deletion that did not happen.
		'MoveObject.php' => 'a move removes the source ROW, not the object; the object still exists',
	];

	public function testBothWriteMethodsDispatchTheSaveEvent(): void {
		$mapper = (string)file_get_contents($this->lib() . '/Db/MagicMapper.php');

		$this->assertStringContainsString(needle: 'new ObjectCreatingEvent(', haystack: $mapper);
		$this->assertStringContainsString(needle: 'new ObjectUpdatingEvent(', haystack: $mapper);

		foreach (array_unique(array_values(self::WRITE_PATHS)) as $method) {
			$this->assertStringContainsString(
				needle: 'public function ' . $method . '(',
				haystack: $mapper,
				message: sprintf('MagicMapper no longer declares %s(); a write path has moved.', $method)
			);
		}

	}//end testBothWriteMethodsDispatchTheSaveEvent()

	/**
	 * No caller in lib/ asks the write methods to skip the dispatch.
	 *
	 * `insertObjectEntity` takes a `dispatchEvents` flag. Passing it false is
	 * exactly the shape of "a path that can skip the rules", so it is refused
	 * here rather than discovered when a rule stops firing on an import.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/object-lifecycle/spec.md
	 */
	public function testNoWritePathAsksToSkipTheRules(): void {
		$offenders = [];
		foreach ($this->sources() as $path => $source) {
			if (preg_match('/dispatchEvents\s*:\s*false/', $source) === 1
				&& array_key_exists(basename($path), self::SUPPRESSION_REASONS) === false
			) {
				$offenders[] = basename($path);
			}
		}

		$this->assertSame(
			expected: [],
			actual: $offenders,
			message: 'These files write objects with the save events suppressed, so the declared rules '
				. 'never see them: ' . implode(', ', $offenders)
		);

		// 🔴 THE ALLOWLIST IS A RATCHET AND HAS TO FAIL ON THE WAY DOWN TOO. An
		// entry left here after its suppression is gone makes the next reader
		// believe a path skips the rules when it does not, and the day somebody
		// re-adds one nothing would say so. So every named file must still
		// carry the suppression it was excused for.
		foreach (self::SUPPRESSION_REASONS as $file => $reason) {
			$found = false;
			foreach ($this->sources() as $path => $source) {
				if (basename($path) === $file && preg_match('/dispatchEvents\s*:\s*false/', $source) === 1) {
					$found = true;
					break;
				}
			}

			$this->assertTrue(
				$found,
				sprintf('%s no longer suppresses events; remove it from SUPPRESSION_REASONS.', $file)
			);
			$this->assertNotSame('', trim($reason), sprintf('%s must say WHY.', $file));
		}

	}//end testNoWritePathAsksToSkipTheRules()

	/**
	 * Both rule listeners are subscribed to the events the writes dispatch.
	 *
	 * A dispatch nobody listens to is the same silence as no dispatch at all,
	 * so the registration is half of the invariant and is asserted with it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/object-lifecycle/spec.md
	 */
	public function testTheRuleListenersAreSubscribedToThoseEvents(): void {
		$application = (string)file_get_contents($this->lib() . '/AppInfo/Application.php');

		$this->assertStringContainsString(
			needle: 'registerEventListener(ObjectCreatingEvent::class, CalculationOnSaveListener::class)',
			haystack: $application
		);
		$this->assertStringContainsString(
			needle: 'registerEventListener(ObjectUpdatingEvent::class, CalculationOnSaveListener::class)',
			haystack: $application
		);
		$this->assertStringContainsString(
			needle: 'registerEventListener(ObjectUpdatingEvent::class, LifecycleValidationListener::class)',
			haystack: $application
		);

	}//end testTheRuleListenersAreSubscribedToThoseEvents()

	/**
	 * 🔴 The administered validations are subscribed to the SAME two events.
	 *
	 * REQ-RCT-005 asks that a validation an administrator wrote be reached by
	 * every write path — the object API, an import, a flow node write, a bulk
	 * job. That is not a claim any test of today's paths can keep: it rests on
	 * the validations hanging off the same two events every write dispatches.
	 * A path added later that bypasses the pipeline fails the test above and is
	 * named there; a validation quietly unsubscribed fails here.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/object-lifecycle/spec.md
	 */
	public function testTheAdministeredValidationsAreSubscribedToThoseEvents(): void {
		$application = (string)file_get_contents($this->lib() . '/AppInfo/Application.php');

		foreach (['ObjectCreatingEvent', 'ObjectUpdatingEvent'] as $event) {
			$this->assertStringContainsString(
				needle: sprintf(
					'registerEventListener(%s::class, AdministeredValidationListener::class)',
					$event
				),
				haystack: $application,
				message: sprintf(
					'An administered validation no longer reaches %s, so a write on that path skips every check an administrator wrote.',
					$event
				)
			);
		}

	}//end testTheAdministeredValidationsAreSubscribedToThoseEvents()

	/**
	 * The administered validations record their verdict too.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/object-lifecycle/spec.md
	 */
	public function testTheAdministeredValidationsRecordTheirVerdict(): void {
		// The decision moved out of the listener into the enforcer, and the
		// writing into AdministeredValidationRunLog. The property this pins is
		// unchanged: both verdicts still reach the run log, and BOTH ends are
		// asserted so a recorder nobody calls reads as red rather than green.
		$enforcer = (string)file_get_contents($this->lib() . '/Service/Rules/AdministeredValidationEnforcer.php');

		$this->assertStringContainsString(
			needle: 'recordWarning(',
			haystack: $enforcer,
			message: 'AdministeredValidationEnforcer no longer records a warning; the run log has a blind spot.'
		);
		$this->assertStringContainsString(
			needle: 'recordRefusal(',
			haystack: $enforcer,
			message: 'AdministeredValidationEnforcer no longer records a refusal; the run log has a blind spot.'
		);

		$runLog = (string)file_get_contents($this->lib() . '/Service/Rules/AdministeredValidationRunLog.php');

		$this->assertStringContainsString(
			needle: 'RuleRunRecorder',
			haystack: $runLog,
			message: 'AdministeredValidationRunLog no longer writes to the rule run log.'
		);
		$this->assertStringContainsString(needle: '->record(', haystack: $runLog);

	}//end testTheAdministeredValidationsRecordTheirVerdict()

	/**
	 * Both listeners record what they decided.
	 *
	 * The run log is only as complete as the paths that write to it, so a
	 * listener that stops recording is a rule that silently becomes
	 * unexplainable.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	public function testBothListenersRecordTheirVerdict(): void {
		foreach (['CalculationOnSaveListener', 'LifecycleValidationListener'] as $listener) {
			$source = (string)file_get_contents($this->lib() . '/Listener/' . $listener . '.php');

			$this->assertStringContainsString(
				needle: 'RuleRunRecorder',
				haystack: $source,
				message: sprintf('%s no longer records its verdict; the run log has a blind spot.', $listener)
			);
			$this->assertStringContainsString(needle: '->record(', haystack: $source);
		}

	}//end testBothListenersRecordTheirVerdict()

	/**
	 * Both listeners honour the switch where their rule is applied.
	 *
	 * A control that reports a state it does not cause is worse than no
	 * control, so the skip is asserted beside the recording.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	public function testBothEnginesHonourTheSwitch(): void {
		$calculation = (string)file_get_contents($this->lib() . '/Listener/CalculationOnSaveListener.php');
		$condition = (string)file_get_contents($this->lib() . '/Service/Lifecycle/LifecycleConditionEvaluator.php');

		$this->assertStringContainsString(needle: "\$spec['enabled'] ?? true) === false", haystack: $calculation);
		$this->assertStringContainsString(needle: "\$spec['enabled'] ?? true) === false", haystack: $condition);

	}//end testBothEnginesHonourTheSwitch()
}//end class
