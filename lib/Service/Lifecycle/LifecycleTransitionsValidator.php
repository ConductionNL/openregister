<?php

/**
 * The rules for a lifecycle an app keeps as data.
 *
 * Some apps let a person author a state machine (decidiq's process templates
 * are objects a secretary edits). That lifecycle is not a schema annotation, so
 * {@see LifecycleAnnotationValidator} cannot be asked about it without inventing
 * a schema around it. This class takes the graph as plain data and applies the
 * same rules, plus two that only make sense for an authored graph: a declared
 * state that no transition touches, and a guard token outside the app's own
 * catalogue.
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
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Lifecycle;

/**
 * Validates a lifecycle graph passed as states, an initial state and transitions.
 *
 * @spec openspec/specs/object-lifecycle/spec.md#requirement-a-lifecycle-kept-as-data-is-validated-through-one-entry-point
 *
 * @psalm-suppress UnusedClass Public entry point for apps (decidiq process templates).
 */
final class LifecycleTransitionsValidator {

	/**
	 * Validate a lifecycle graph given as data (fail closed).
	 *
	 * The unreachable rule is deliberately narrow: a state is refused when no
	 * transition starts or ends in it and it is not the initial state. It is
	 * NOT applied to schema annotations, where an enum value nothing moves to
	 * (a legacy value kept for old objects) is legitimate and refusing it would
	 * stop shipped schemas from importing.
	 *
	 * @param array<int, mixed>        $states      State names, or `{name}` objects.
	 * @param string|null              $initial     The initial state.
	 * @param array<int|string, mixed> $transitions A list, or a map keyed by action, of `{from, to, guards?}`.
	 *                                              `from` is a state or a list of states.
	 * @param array<int, string>|null  $knownGuards The app's guard catalogue. When given, a guard token outside
	 *                                              it is refused; when null, guards are shape-checked only.
	 *
	 * @return array<int, array{code: string, message: string}> List of errors (empty = valid).
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function validate(array $states, ?string $initial, array $transitions, ?array $knownGuards=null): array {
		$check = new LifecycleGraphCheck(states: $states, knownGuards: $knownGuards);
		if ($check->stateNames === []) {
			$check->addError(code: 'lifecycle-states-empty', message: 'A lifecycle must declare at least one named state.');
		}

		$this->checkInitial(initial: $initial, check: $check);
		foreach ($transitions as $action => $spec) {
			$this->checkTransition(transition: ['label' => $this->label(action: $action), 'spec' => $spec], check: $check);
		}

		$this->checkUnreachable(initial: $initial, check: $check);

		return $check->errors();
	}//end validate()

	/**
	 * Check the initial state.
	 *
	 * @param string|null         $initial The initial state.
	 * @param LifecycleGraphCheck $check   The validation in progress.
	 *
	 * @return void
	 */
	private function checkInitial(?string $initial, LifecycleGraphCheck $check): void {
		if ($initial === null || $initial === '') {
			$check->addError(code: 'lifecycle-initial-missing', message: 'A lifecycle must declare its initial state.');
			return;
		}

		if ($check->stateNames !== [] && isset($check->stateNames[$initial]) === false) {
			$check->addError(
				code: 'lifecycle-initial-not-declared',
				message: sprintf('Initial state "%s" is not a declared state.', $initial)
			);
		}
	}//end checkInitial()

	/**
	 * Report declared states no transition starts or ends in, other than the initial one.
	 *
	 * @param string|null         $initial The initial state.
	 * @param LifecycleGraphCheck $check   The validation in progress.
	 *
	 * @return void
	 */
	private function checkUnreachable(?string $initial, LifecycleGraphCheck $check): void {
		foreach (array_keys($check->stateNames) as $name) {
			if ($check->isTouched(state: (string)$name) === false && $name !== $initial) {
				$check->addError(
					code: 'lifecycle-state-unreachable',
					message: sprintf('State "%s" is unreachable: no transition starts or ends in it.', $name)
				);
			}
		}
	}//end checkUnreachable()

	/**
	 * Check one transition: its shape, its endpoints and its guards.
	 *
	 * @param array{label: string, spec: mixed} $transition How to name the transition, and its declaration.
	 * @param LifecycleGraphCheck               $check      The validation in progress.
	 *
	 * @return void
	 */
	private function checkTransition(array $transition, LifecycleGraphCheck $check): void {
		$spec = $transition['spec'];
		if (is_array($spec) === false) {
			$check->addError(
				code: 'lifecycle-transition-malformed',
				message: sprintf('Transition %s must be an object with `from` and `to`.', $transition['label'])
			);
			return;
		}

		$this->checkFrom(transition: $transition, check: $check);
		$this->checkTo(transition: $transition, check: $check);
		if (array_key_exists('guards', $spec) === true) {
			$this->checkGuards(transition: $transition, check: $check);
		}
	}//end checkTransition()

	/**
	 * Check a transition's `from` states.
	 *
	 * @param array{label: string, spec: array<string, mixed>} $transition How to name the transition, and its declaration.
	 * @param LifecycleGraphCheck                              $check      The validation in progress.
	 *
	 * @return void
	 */
	private function checkFrom(array $transition, LifecycleGraphCheck $check): void {
		$from = $this->fromStates(from: ($transition['spec']['from'] ?? null));
		if ($from === null) {
			$check->addError(
				code: 'lifecycle-from-missing',
				message: sprintf('Transition %s must declare a non-empty `from` state or list of states.', $transition['label'])
			);
			return;
		}

		foreach ($from as $state) {
			$this->checkEndpoint(endpoint: ['label' => $transition['label'], 'side' => 'from', 'state' => $state], check: $check);
		}
	}//end checkFrom()

	/**
	 * Check a transition's `to` state.
	 *
	 * @param array{label: string, spec: array<string, mixed>} $transition How to name the transition, and its declaration.
	 * @param LifecycleGraphCheck                              $check      The validation in progress.
	 *
	 * @return void
	 */
	private function checkTo(array $transition, LifecycleGraphCheck $check): void {
		$to = ($transition['spec']['to'] ?? null);
		if (is_string($to) === false || $to === '') {
			$check->addError(
				code: 'lifecycle-to-missing',
				message: sprintf('Transition %s must declare a non-empty `to` state.', $transition['label'])
			);
			return;
		}

		$this->checkEndpoint(endpoint: ['label' => $transition['label'], 'side' => 'to', 'state' => $to], check: $check);
	}//end checkTo()

	/**
	 * Read a transition's `from` as a list of states, or null when it is malformed.
	 *
	 * @param mixed $from The declared `from`.
	 *
	 * @return array<int, string>|null
	 */
	private function fromStates(mixed $from): ?array {
		$list = $from;
		if (is_string($from) === true) {
			$list = [$from];
		}

		if (is_array($list) === false || $list === [] || self::allNonEmptyStrings(values: $list) === false) {
			return null;
		}

		return array_values($list);
	}//end fromStates()

	/**
	 * Whether every value is a non-empty string.
	 *
	 * @param array<mixed> $values The values.
	 *
	 * @return bool True when they all are.
	 */
	private static function allNonEmptyStrings(array $values): bool {
		foreach ($values as $value) {
			if (is_string($value) === false || $value === '') {
				return false;
			}
		}

		return true;
	}//end allNonEmptyStrings()

	/**
	 * Check one endpoint against the declared states, and record it as touched when declared.
	 *
	 * @param array{label: string, side: string, state: string} $endpoint The transition, `from` or `to`, and the state.
	 * @param LifecycleGraphCheck                               $check    The validation in progress.
	 *
	 * @return void
	 */
	private function checkEndpoint(array $endpoint, LifecycleGraphCheck $check): void {
		if (isset($check->stateNames[$endpoint['state']]) === true) {
			$check->touch(state: $endpoint['state']);
			return;
		}

		$check->addError(
			code: sprintf('lifecycle-%s-not-declared', $endpoint['side']),
			message: sprintf(
				'Transition %s has %s-state "%s", which is not a declared state.',
				$endpoint['label'],
				$endpoint['side'],
				$endpoint['state']
			)
		);
	}//end checkEndpoint()

	/**
	 * Check a transition's guard tokens.
	 *
	 * @param array{label: string, spec: array<string, mixed>} $transition How to name the transition, and its declaration.
	 * @param LifecycleGraphCheck                              $check      The validation in progress.
	 *
	 * @return void
	 */
	private function checkGuards(array $transition, LifecycleGraphCheck $check): void {
		$guards = $transition['spec']['guards'];
		if (is_array($guards) === false) {
			$this->addGuardsMalformed(label: $transition['label'], check: $check);
			return;
		}

		foreach ($guards as $guard) {
			$this->checkGuard(guard: ['label' => $transition['label'], 'token' => $guard], check: $check);
		}
	}//end checkGuards()

	/**
	 * Check one guard token: against the catalogue when there is one, else its shape.
	 *
	 * @param array{label: string, token: mixed} $guard How to name the transition, and the declared token.
	 * @param LifecycleGraphCheck                $check The validation in progress.
	 *
	 * @return void
	 */
	private function checkGuard(array $guard, LifecycleGraphCheck $check): void {
		$token = $guard['token'];
		if ($check->knownGuards !== null) {
			if (in_array($token, $check->knownGuards, true) === false) {
				$check->addError(
					code: 'lifecycle-guard-unknown',
					message: sprintf('Transition %s declares unknown guard token "%s".', $guard['label'], self::tokenName(token: $token))
				);
			}

			return;
		}

		if (is_string($token) === false || $token === '') {
			$this->addGuardsMalformed(label: $guard['label'], check: $check);
		}
	}//end checkGuard()

	/**
	 * A guard token as a message names it: the string, or `?` for anything else.
	 *
	 * @param mixed $token The declared token.
	 *
	 * @return string The name.
	 */
	private static function tokenName(mixed $token): string {
		if (is_string($token) === true) {
			return $token;
		}

		return '?';
	}//end tokenName()

	/**
	 * Record that a transition's `guards` is not a list of guard tokens.
	 *
	 * @param string              $label How to name the transition.
	 * @param LifecycleGraphCheck $check The validation in progress.
	 *
	 * @return void
	 */
	private function addGuardsMalformed(string $label, LifecycleGraphCheck $check): void {
		$check->addError(
			code: 'lifecycle-guards-malformed',
			message: sprintf('Transition %s must declare `guards` as a list of guard tokens.', $label)
		);
	}//end addGuardsMalformed()

	/**
	 * Name a transition in a message: its action key, or its position.
	 *
	 * @param int|string $action The key in the transitions array.
	 *
	 * @return string
	 */
	private function label(int|string $action): string {
		if (is_string($action) === true) {
			return sprintf('"%s"', $action);
		}

		return sprintf('#%d', ($action + 1));
	}//end label()
}//end class
