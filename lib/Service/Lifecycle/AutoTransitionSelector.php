<?php

/**
 * OpenRegister AutoTransitionSelector
 *
 * Picks the one automatic transition a stored object is eligible for, or none.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Lifecycle
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

namespace OCA\OpenRegister\Service\Lifecycle;

use OCA\OpenRegister\Db\Flow;
use Psr\Log\LoggerInterface;

/**
 * Selection, and only selection: which automatic transition may fire.
 *
 * This class decides WHICH transition is eligible; evaluating whether a rule
 * holds belongs to {@see LifecycleConditionEvaluator::holds()} and is never
 * done here. A second JSONLogic path is exactly where the scalar guard and the
 * fail-closed `isTrue()` call would drift apart, so the split is the point.
 *
 * Three ways selection answers "none" even though a rule holds, each fail-safe
 * and each logged:
 *
 * - AMBIGUITY. Two rules holding from the same state fire nothing. Declaration
 *   order is the order of keys in a stored JSON object, which MySQL's native
 *   JSON type does not preserve and PostgreSQL's `json` does, so "first wins"
 *   would pick differently per installation.
 * - SHADOWING. A transition sharing its from and to pair with an earlier
 *   declared one does not fire automatically. The save path can identify a
 *   transition by those values, so firing it risks enforcing the twin's gates
 *   and running the twin's actions rather than this transition's.
 * - AN UNRECOGNISED `executionMode`. Compared against the flow engine's two
 *   constants exactly, never lowercased, so the lifecycle and the flow engine
 *   cannot drift into two spellings of the same two words.
 */
class AutoTransitionSelector {

	/**
	 * Constructor.
	 *
	 * @param LifecycleConditionEvaluator $evaluator The one place a lifecycle rule is evaluated.
	 * @param LoggerInterface $logger Logs every refusal to select, so a silent no-move is diagnosable.
	 *
	 * @spec openspec/changes/lifecycle-auto-transitions/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		private readonly LifecycleConditionEvaluator $evaluator,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The automatic transition this object is eligible for, or null.
	 *
	 * @param array<string, mixed> $annotation The schema's x-openregister-lifecycle block.
	 * @param array<string, mixed> $objectData The object as stored, after the triggering write.
	 * @param array<string, mixed> $previous The object as it was before the triggering write.
	 * @param string $schemaSlug The schema's slug, for the log lines.
	 *
	 * @return AutoTransitionCandidate|null The one eligible move, or null.
	 *
	 * @spec openspec/changes/lifecycle-auto-transitions/specs/object-lifecycle/spec.md
	 */
	public function select(
		array $annotation,
		array $objectData,
		array $previous,
		string $schemaSlug,
	): ?AutoTransitionCandidate {
		$field = (string)($annotation['field'] ?? ($annotation['property'] ?? ''));
		$transitions = ($annotation['transitions'] ?? []);
		if ($field === '' || is_array($transitions) === false || $transitions === []) {
			return null;
		}

		$current = (string)($objectData[$field] ?? '');
		if ($current === '') {
			return null;
		}

		$holding = $this->holdingTransitions(
			transitions: $transitions,
			objectData: $objectData,
			previous: $previous,
			current: $current,
			schemaSlug: $schemaSlug,
			field: $field
		);

		if ($holding === []) {
			return null;
		}

		if (count($holding) > 1) {
			$this->logger->warning(
				'[AutoTransitionSelector] More than one automatic transition holds from this state; '
				. 'firing none. Declaration order is not a tie-breaker, because it is not preserved by '
				. 'every database Nextcloud supports.',
				[
					'schema' => $schemaSlug,
					'field' => $field,
					'from' => $current,
					'candidates' => array_column($holding, 'action'),
				]
			);
			return null;
		}

		$candidate = $holding[0];
		$mode = $this->modeOf(spec: $candidate['spec'], action: $candidate['action'], schemaSlug: $schemaSlug);
		if ($mode === null) {
			return null;
		}

		if (
			$this->shadowOf(
				transitions: $transitions,
				candidate: $candidate,
				current: $current,
				schemaSlug: $schemaSlug
			) !== null
		) {
			return null;
		}

		return new AutoTransitionCandidate(
			action: $candidate['action'],
			from: $current,
			to: $candidate['to'],
			mode: $mode
		);
	}//end select()

	/**
	 * Whether the schema's annotation declares any `autoWhen` at all.
	 *
	 * The recording listener asks this on every write, so it is a shape read
	 * over the annotation and never an evaluation.
	 *
	 * @param array<string, mixed> $annotation The schema's x-openregister-lifecycle block.
	 *
	 * @return bool True when at least one transition declares `autoWhen`.
	 *
	 * @spec openspec/changes/lifecycle-auto-transitions/specs/object-lifecycle/spec.md
	 */
	public function declaresAutoWhen(array $annotation): bool {
		$transitions = ($annotation['transitions'] ?? []);
		if (is_array($transitions) === false) {
			return false;
		}

		foreach ($transitions as $spec) {
			if (is_array($spec) === true && array_key_exists('autoWhen', $spec) === true) {
				return true;
			}
		}

		return false;
	}//end declaresAutoWhen()

	/**
	 * Every declared transition whose `autoWhen` holds from the current state.
	 *
	 * A transition whose `to` equals the current value is skipped before its
	 * rule is read: the move would change nothing and would be re-decided on
	 * every write.
	 *
	 * @param array<string, mixed> $transitions The annotation's transition map.
	 * @param array<string, mixed> $objectData The object as stored.
	 * @param array<string, mixed> $previous The object before the triggering write.
	 * @param string $current The object's current lifecycle value.
	 * @param string $schemaSlug The schema's slug, for the log lines.
	 * @param string $field The lifecycle field's name.
	 *
	 * @return list<array{action: string, to: string, index: int, spec: array<string, mixed>}>
	 *
	 * @spec openspec/changes/lifecycle-auto-transitions/specs/object-lifecycle/spec.md
	 */
	private function holdingTransitions(
		array $transitions,
		array $objectData,
		array $previous,
		string $current,
		string $schemaSlug,
		string $field,
	): array {
		$holding = [];
		$index = -1;

		foreach ($transitions as $action => $spec) {
			$index++;
			if (is_array($spec) === false || array_key_exists('autoWhen', $spec) === false) {
				continue;
			}

			$to = (string)($spec['to'] ?? '');
			if ($to === '' || $to === $current || $this->fromContains(spec: $spec, value: $current) === false) {
				continue;
			}

			$holds = $this->evaluator->holds(
				rule: $spec['autoWhen'],
				newData: $objectData,
				oldData: $previous,
				action: (string)$action,
				from: $current,
				to: $to,
				schemaSlug: $schemaSlug,
				field: $field
			);
			if ($holds === false) {
				continue;
			}

			$holding[] = [
				'action' => (string)$action,
				'to' => $to,
				'index' => $index,
				'spec' => $spec,
			];
		}//end foreach

		return $holding;
	}//end holdingTransitions()

	/**
	 * The candidate's execution mode, or null when it is not one of the two.
	 *
	 * @param array<string, mixed> $spec The candidate transition's spec.
	 * @param string $action The candidate transition's name.
	 * @param string $schemaSlug The schema's slug, for the log line.
	 *
	 * @return string|null `Flow::MODE_SYNC`, `Flow::MODE_ASYNC`, or null.
	 *
	 * @spec openspec/changes/lifecycle-auto-transitions/specs/object-lifecycle/spec.md
	 */
	private function modeOf(array $spec, string $action, string $schemaSlug): ?string {
		$mode = ($spec['executionMode'] ?? Flow::MODE_SYNC);
		if (in_array($mode, [Flow::MODE_SYNC, Flow::MODE_ASYNC], true) === true) {
			return (string)$mode;
		}

		$this->logger->warning(
			'[AutoTransitionSelector] Automatic transition declares an unrecognised executionMode; '
			. 'not firing it. Use exactly "sync" or "async".',
			['schema' => $schemaSlug, 'action' => $action, 'executionMode' => $spec['executionMode']]
		);

		return null;
	}//end modeOf()

	/**
	 * The name of an earlier-declared transition shadowing the candidate.
	 *
	 * @param array<string, mixed> $transitions The annotation's transition map.
	 * @param array{action: string, to: string, index: int, spec: array<string, mixed>} $candidate The selected move.
	 * @param string $current The object's current lifecycle value.
	 * @param string $schemaSlug The schema's slug, for the log line.
	 *
	 * @return string|null The shadowing transition's name, or null when there is none.
	 *
	 * @spec openspec/changes/lifecycle-auto-transitions/specs/object-lifecycle/spec.md
	 */
	private function shadowOf(
		array $transitions,
		array $candidate,
		string $current,
		string $schemaSlug,
	): ?string {
		$index = -1;
		foreach ($transitions as $action => $spec) {
			$index++;
			if ($index >= $candidate['index'] || is_array($spec) === false) {
				continue;
			}

			if (
				(string)($spec['to'] ?? '') !== $candidate['to']
				|| $this->fromContains(spec: $spec, value: $current) === false
			) {
				continue;
			}

			$this->logger->warning(
				'[AutoTransitionSelector] An earlier-declared transition shares this move\'s from and to '
				. 'pair, so the save path could enforce its gates and run its actions instead; '
				. 'not firing automatically.',
				[
					'schema' => $schemaSlug,
					'action' => $candidate['action'],
					'shadowedBy' => (string)$action,
					'from' => $current,
					'to' => $candidate['to'],
				]
			);

			return (string)$action;
		}//end foreach

		return null;
	}//end shadowOf()

	/**
	 * Whether a transition's `from` covers a lifecycle value.
	 *
	 * `from` may be a single state or a list; a string is read as a one-element
	 * list so both authoring shapes work, exactly as
	 * {@see LifecycleTransitionResolver::moves()} reads it.
	 *
	 * @param array<string, mixed> $spec The transition's spec.
	 * @param string $value The lifecycle value to look for.
	 *
	 * @return bool True when the transition can be taken from that value.
	 *
	 * @spec openspec/changes/lifecycle-auto-transitions/specs/object-lifecycle/spec.md
	 */
	private function fromContains(array $spec, string $value): bool {
		$from = ($spec['from'] ?? []);
		if (is_string($from) === true) {
			$from = [$from];
		}

		return is_array($from) === true && in_array($value, $from, true) === true;
	}//end fromContains()
}//end class
