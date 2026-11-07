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
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
class AutoTransitionSelector {

	/**
	 * Constructor.
	 *
	 * @param LifecycleConditionEvaluator $evaluator The one place a lifecycle rule is evaluated.
	 * @param LoggerInterface $logger Logs every refusal to select, so a silent no-move is diagnosable.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
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
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function select(
		array $annotation,
		array $objectData,
		array $previous,
		string $schemaSlug,
	): ?AutoTransitionCandidate {
		$write   = ['objectData' => $objectData, 'previous' => $previous, 'schemaSlug' => $schemaSlug];
		$context = self::contextFor(annotation: $annotation, write: $write);
		if ($context === null) {
			return null;
		}

		$candidate = $this->soleHolding(context: $context);
		if ($candidate === null) {
			return null;
		}

		return $this->eligible(candidate: $candidate, context: $context);
	}//end select()

	/**
	 * What a selection reads, or null when the annotation names no field, declares no transitions
	 * or the object has no current lifecycle value.
	 *
	 * @param array<string, mixed> $annotation The schema's x-openregister-lifecycle block.
	 * @param array<string, mixed> $write      The object after and before the write, and the schema's slug.
	 *
	 * @phpstan-param array{objectData: array<string, mixed>, previous: array<string, mixed>, schemaSlug: string} $write
	 *
	 * @return AutoTransitionContext|null The context.
	 */
	private static function contextFor(array $annotation, array $write): ?AutoTransitionContext {
		$field       = (string)($annotation['field'] ?? ($annotation['property'] ?? ''));
		$transitions = self::transitionsOf(annotation: $annotation);
		$current     = (string)($write['objectData'][$field] ?? '');
		if ($field === '' || $transitions === [] || $current === '') {
			return null;
		}

		return new AutoTransitionContext(field: $field, current: $current, transitions: $transitions, write: $write);
	}//end contextFor()

	/**
	 * The annotation's transitions, or an empty list when it declares none or not as a map.
	 *
	 * @param array<string, mixed> $annotation The schema's x-openregister-lifecycle block.
	 *
	 * @return array<mixed> The transitions.
	 */
	private static function transitionsOf(array $annotation): array {
		$transitions = ($annotation['transitions'] ?? []);
		if (is_array($transitions) === false) {
			return [];
		}

		return $transitions;
	}//end transitionsOf()

	/**
	 * The one transition that holds, or null when none or more than one does.
	 *
	 * @param AutoTransitionContext $context The write being asked about.
	 *
	 * @return array{action: string, to: string, index: int, spec: array<string, mixed>}|null The candidate.
	 */
	private function soleHolding(AutoTransitionContext $context): ?array {
		$holding = $this->holdingTransitions(context: $context);
		if (count($holding) > 1) {
			$this->logAmbiguity(holding: $holding, context: $context);
			return null;
		}

		return ($holding[0] ?? null);
	}//end soleHolding()

	/**
	 * Log that more than one automatic transition holds, so none fires.
	 *
	 * @param list<array{action: string, to: string, index: int, spec: array<string, mixed>}> $holding The holding transitions.
	 * @param AutoTransitionContext                                                           $context The write being asked about.
	 *
	 * @return void
	 */
	private function logAmbiguity(array $holding, AutoTransitionContext $context): void {
		$this->logger->warning(
			'[AutoTransitionSelector] More than one automatic transition holds from this state; '
			. 'firing none. Declaration order is not a tie-breaker, because it is not preserved by '
			. 'every database Nextcloud supports.',
			[
				'schema' => $context->schemaSlug,
				'field' => $context->field,
				'from' => $context->current,
				'candidates' => array_column($holding, 'action'),
			]
		);
	}//end logAmbiguity()

	/**
	 * The candidate as a move, or null when its mode is unrecognised or an earlier transition shadows it.
	 *
	 * @param array{action: string, to: string, index: int, spec: array<string, mixed>} $candidate The selected move.
	 * @param AutoTransitionContext                                                     $context   The write being asked about.
	 *
	 * @return AutoTransitionCandidate|null The move.
	 */
	private function eligible(array $candidate, AutoTransitionContext $context): ?AutoTransitionCandidate {
		$mode = $this->modeOf(candidate: $candidate, context: $context);
		if ($mode === null) {
			return null;
		}

		$shadow = self::shadowOf(candidate: $candidate, context: $context);
		if ($shadow !== null) {
			$this->logShadow(candidate: $candidate + ['shadowedBy' => $shadow], context: $context);
			return null;
		}

		return new AutoTransitionCandidate(action: $candidate['action'], from: $context->current, to: $candidate['to'], mode: $mode);
	}//end eligible()

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
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function declaresAutoWhen(array $annotation): bool {
		foreach (self::transitionsOf(annotation: $annotation) as $spec) {
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
	 * @param AutoTransitionContext $context The write being asked about.
	 *
	 * @return list<array{action: string, to: string, index: int, spec: array<string, mixed>}>
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function holdingTransitions(AutoTransitionContext $context): array {
		$holding = [];
		$index   = -1;
		foreach ($context->transitions as $action => $spec) {
			$index++;
			$candidate = $this->holdingCandidate(move: ['action' => (string)$action, 'index' => $index, 'spec' => $spec], context: $context);
			if ($candidate !== null) {
				$holding[] = $candidate;
			}
		}

		return $holding;
	}//end holdingTransitions()

	/**
	 * One declared transition as a candidate, when it is automatic, leaves the current state and its rule holds.
	 *
	 * @param array{action: string, index: int, spec: mixed} $move    The transition's name, position and spec.
	 * @param AutoTransitionContext                          $context The write being asked about.
	 *
	 * @return array{action: string, to: string, index: int, spec: array<string, mixed>}|null The candidate.
	 */
	private function holdingCandidate(array $move, AutoTransitionContext $context): ?array {
		$to = self::autoTarget(spec: $move['spec'], current: $context->current);
		if ($to === null) {
			return null;
		}

		$candidate = ['action' => $move['action'], 'to' => $to, 'index' => $move['index'], 'spec' => $move['spec']];
		if ($this->ruleHolds(candidate: $candidate, context: $context) === false) {
			return null;
		}

		return $candidate;
	}//end holdingCandidate()

	/**
	 * The state an automatic transition leads to from the current value, or null when it is not one.
	 *
	 * @param mixed  $spec    The transition's spec.
	 * @param string $current The object's current lifecycle value.
	 *
	 * @return string|null The target state.
	 */
	private static function autoTarget(mixed $spec, string $current): ?string {
		if (is_array($spec) === false || array_key_exists('autoWhen', $spec) === false) {
			return null;
		}

		return self::targetFrom(spec: $spec, current: $current);
	}//end autoTarget()

	/**
	 * The state a transition leads to from the current value, or null when it changes nothing or cannot be taken.
	 *
	 * @param array<string, mixed> $spec    The transition's spec.
	 * @param string               $current The object's current lifecycle value.
	 *
	 * @return string|null The target state.
	 */
	private static function targetFrom(array $spec, string $current): ?string {
		$to = (string)($spec['to'] ?? '');
		if ($to === '' || $to === $current || self::fromContains(spec: $spec, value: $current) === false) {
			return null;
		}

		return $to;
	}//end targetFrom()

	/**
	 * Whether a candidate's `autoWhen` holds, asked of the one place a lifecycle rule is evaluated.
	 *
	 * @param array{action: string, to: string, index: int, spec: array<string, mixed>} $candidate The candidate.
	 * @param AutoTransitionContext                                                     $context   The write being asked about.
	 *
	 * @return bool True when the rule holds.
	 */
	private function ruleHolds(array $candidate, AutoTransitionContext $context): bool {
		return $this->evaluator->holds(
			rule: $candidate['spec']['autoWhen'],
			newData: $context->objectData,
			oldData: $context->previous,
			action: $candidate['action'],
			from: $context->current,
			to: $candidate['to'],
			schemaSlug: $context->schemaSlug,
			field: $context->field
		);
	}//end ruleHolds()

	/**
	 * The candidate's execution mode, or null when it is not one of the two.
	 *
	 * @param array{action: string, to: string, index: int, spec: array<string, mixed>} $candidate The candidate.
	 * @param AutoTransitionContext                                                     $context   The write being asked about.
	 *
	 * @return string|null `Flow::MODE_SYNC`, `Flow::MODE_ASYNC`, or null.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function modeOf(array $candidate, AutoTransitionContext $context): ?string {
		$mode = ($candidate['spec']['executionMode'] ?? Flow::MODE_SYNC);
		if (in_array($mode, [Flow::MODE_SYNC, Flow::MODE_ASYNC], true) === true) {
			return (string)$mode;
		}

		$this->logger->warning(
			'[AutoTransitionSelector] Automatic transition declares an unrecognised executionMode; '
			. 'not firing it. Use exactly "sync" or "async".',
			['schema' => $context->schemaSlug, 'action' => $candidate['action'], 'executionMode' => $candidate['spec']['executionMode']]
		);

		return null;
	}//end modeOf()

	/**
	 * The name of an earlier-declared transition shadowing the candidate.
	 *
	 * @param array{action: string, to: string, index: int, spec: array<string, mixed>} $candidate The selected move.
	 * @param AutoTransitionContext                                                     $context   The write being asked about.
	 *
	 * @return string|null The shadowing transition's name, or null when there is none.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private static function shadowOf(array $candidate, AutoTransitionContext $context): ?string {
		$index = -1;
		$pair  = ['from' => $context->current, 'to' => $candidate['to']];
		foreach ($context->transitions as $action => $spec) {
			$index++;
			if ($index < $candidate['index'] && self::sharesPair(spec: $spec, pair: $pair) === true) {
				return (string)$action;
			}
		}

		return null;
	}//end shadowOf()

	/**
	 * Whether a transition can be taken from a state and leads to another.
	 *
	 * @param mixed                            $spec The transition's spec.
	 * @param array{from: string, to: string} $pair The from and to states.
	 *
	 * @return bool True when it shares the pair.
	 */
	private static function sharesPair(mixed $spec, array $pair): bool {
		return is_array($spec) === true
			&& (string)($spec['to'] ?? '') === $pair['to']
			&& self::fromContains(spec: $spec, value: $pair['from']) === true;
	}//end sharesPair()

	/**
	 * Log that an earlier-declared transition shadows the candidate, so it does not fire.
	 *
	 * @param array{action: string, to: string, shadowedBy: string} $candidate The candidate and the transition shadowing it.
	 * @param AutoTransitionContext                                 $context   The write being asked about.
	 *
	 * @return void
	 */
	private function logShadow(array $candidate, AutoTransitionContext $context): void {
		$this->logger->warning(
			'[AutoTransitionSelector] An earlier-declared transition shares this move\'s from and to '
			. 'pair, so the save path could enforce its gates and run its actions instead; '
			. 'not firing automatically.',
			[
				'schema' => $context->schemaSlug,
				'action' => $candidate['action'],
				'shadowedBy' => $candidate['shadowedBy'],
				'from' => $context->current,
				'to' => $candidate['to'],
			]
		);
	}//end logShadow()

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
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private static function fromContains(array $spec, string $value): bool {
		$from = ($spec['from'] ?? []);
		if (is_string($from) === true) {
			$from = [$from];
		}

		return is_array($from) === true && in_array($value, $from, true) === true;
	}//end fromContains()
}//end class
