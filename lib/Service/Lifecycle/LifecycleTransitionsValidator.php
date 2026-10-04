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
		$stateNames = $this->collectStateNames(states: $states);
		$errors = [];

		if ($stateNames === []) {
			$errors[] = $this->error(code: 'lifecycle-states-empty', message: 'A lifecycle must declare at least one named state.');
		}

		$errors = array_merge($errors, $this->initialErrors(initial: $initial, stateNames: $stateNames));

		$touched = [];
		foreach ($transitions as $action => $spec) {
			$label = $this->label(action: $action);
			if (is_array($spec) === false) {
				$errors[] = $this->error(
					code: 'lifecycle-transition-malformed',
					message: sprintf('Transition %s must be an object with `from` and `to`.', $label)
				);
				continue;
			}

			$result = $this->inspectTransition(spec: $spec, label: $label, stateNames: $stateNames, knownGuards: $knownGuards);
			$errors = array_merge($errors, $result['errors']);
			foreach ($result['touched'] as $state) {
				$touched[$state] = true;
			}
		}

		$errors = array_merge($errors, $this->unreachableErrors(stateNames: $stateNames, touched: $touched, initial: $initial));

		return $errors;
	}//end validate()

	/**
	 * Check the initial state.
	 *
	 * @param string|null         $initial    The initial state.
	 * @param array<string, true> $stateNames Declared states.
	 *
	 * @return array<int, array{code: string, message: string}>
	 */
	private function initialErrors(?string $initial, array $stateNames): array {
		if ($initial === null || $initial === '') {
			return [$this->error(code: 'lifecycle-initial-missing', message: 'A lifecycle must declare its initial state.')];
		}

		if ($stateNames !== [] && isset($stateNames[$initial]) === false) {
			return [
				$this->error(
					code: 'lifecycle-initial-not-declared',
					message: sprintf('Initial state "%s" is not a declared state.', $initial)
				),
			];
		}

		return [];
	}//end initialErrors()

	/**
	 * Report declared states no transition starts or ends in, other than the initial one.
	 *
	 * @param array<string, true> $stateNames Declared states.
	 * @param array<string, true> $touched    States some transition touches.
	 * @param string|null         $initial    The initial state.
	 *
	 * @return array<int, array{code: string, message: string}>
	 */
	private function unreachableErrors(array $stateNames, array $touched, ?string $initial): array {
		$errors = [];
		foreach (array_keys($stateNames) as $name) {
			if (isset($touched[$name]) === false && $name !== $initial) {
				$errors[] = $this->error(
					code: 'lifecycle-state-unreachable',
					message: sprintf('State "%s" is unreachable: no transition starts or ends in it.', $name)
				);
			}
		}

		return $errors;
	}//end unreachableErrors()

	/**
	 * Read a transition's `from` as a list of states, or null when it is malformed.
	 *
	 * @param mixed $from The declared `from`.
	 *
	 * @return array<int, string>|null
	 */
	private function fromStates(mixed $from): ?array {
		if (is_string($from) === true) {
			$from = [$from];
		}

		if (is_array($from) === false || $from === []) {
			return null;
		}

		foreach ($from as $state) {
			if (is_string($state) === false || $state === '') {
				return null;
			}
		}

		return array_values($from);
	}//end fromStates()

	/**
	 * Check one transition and report the declared states it touches.
	 *
	 * @param array<string, mixed>    $spec        The transition.
	 * @param string                  $label       How to name it in a message.
	 * @param array<string, true>     $stateNames  Declared states.
	 * @param array<int, string>|null $knownGuards The guard catalogue, or null.
	 *
	 * @return array{errors: array<int, array{code: string, message: string}>, touched: array<int, string>}
	 */
	private function inspectTransition(array $spec, string $label, array $stateNames, ?array $knownGuards): array {
		$errors = [];
		$touched = [];

		$from = $this->fromStates(from: ($spec['from'] ?? null));
		if ($from === null) {
			$errors[] = $this->error(
				code: 'lifecycle-from-missing',
				message: sprintf('Transition %s must declare a non-empty `from` state or list of states.', $label)
			);
			$from = [];
		}

		foreach ($from as $state) {
			$touched = array_merge($touched, $this->endpoint(state: $state, side: 'from', label: $label, stateNames: $stateNames, errors: $errors));
		}

		$to = ($spec['to'] ?? null);
		if (is_string($to) === false || $to === '') {
			$errors[] = $this->error(
				code: 'lifecycle-to-missing',
				message: sprintf('Transition %s must declare a non-empty `to` state.', $label)
			);
			$to = null;
		}

		if ($to !== null) {
			$touched = array_merge($touched, $this->endpoint(state: $to, side: 'to', label: $label, stateNames: $stateNames, errors: $errors));
		}

		if (array_key_exists('guards', $spec) === true) {
			$errors = array_merge(
				$errors,
				$this->guardErrors(guards: $spec['guards'], label: $label, knownGuards: $knownGuards)
			);
		}

		return ['errors' => $errors, 'touched' => $touched];
	}//end inspectTransition()

	/**
	 * Check one endpoint against the declared states.
	 *
	 * @param string                                           $state      The endpoint.
	 * @param string                                           $side       `from` or `to`.
	 * @param string                                           $label      How to name the transition.
	 * @param array<string, true>                              $stateNames Declared states.
	 * @param array<int, array{code: string, message: string}> $errors     Errors, appended to.
	 *
	 * @return array<int, string> The endpoint when it is declared, else nothing.
	 */
	private function endpoint(string $state, string $side, string $label, array $stateNames, array &$errors): array {
		if (isset($stateNames[$state]) === true) {
			return [$state];
		}

		$errors[] = $this->error(
			code: sprintf('lifecycle-%s-not-declared', $side),
			message: sprintf('Transition %s has %s-state "%s", which is not a declared state.', $label, $side, $state)
		);

		return [];
	}//end endpoint()

	/**
	 * Check a transition's guard tokens.
	 *
	 * @param mixed                   $guards      The declared guards.
	 * @param string                  $label       How to name the transition.
	 * @param array<int, string>|null $knownGuards The guard catalogue, or null.
	 *
	 * @return array<int, array{code: string, message: string}>
	 */
	private function guardErrors(mixed $guards, string $label, ?array $knownGuards): array {
		if (is_array($guards) === false) {
			return [
				$this->error(
					code: 'lifecycle-guards-malformed',
					message: sprintf('Transition %s must declare `guards` as a list of guard tokens.', $label)
				),
			];
		}

		$errors = [];
		foreach ($guards as $guard) {
			$token = '?';
			if (is_string($guard) === true) {
				$token = $guard;
			}

			if ($knownGuards !== null) {
				if (in_array($guard, $knownGuards, true) === false) {
					$errors[] = $this->error(
						code: 'lifecycle-guard-unknown',
						message: sprintf('Transition %s declares unknown guard token "%s".', $label, $token)
					);
				}

				continue;
			}

			if (is_string($guard) === false || $guard === '') {
				$errors[] = $this->error(
					code: 'lifecycle-guards-malformed',
					message: sprintf('Transition %s must declare `guards` as a list of guard tokens.', $label)
				);
			}
		}//end foreach

		return $errors;
	}//end guardErrors()

	/**
	 * Collect the declared, non-empty state names.
	 *
	 * @param array<int, mixed> $states State names, or `{name}` objects.
	 *
	 * @return array<string, true>
	 */
	private function collectStateNames(array $states): array {
		$names = [];
		foreach ($states as $state) {
			if (is_array($state) === true) {
				$state = ($state['name'] ?? null);
			}

			if (is_string($state) === true && $state !== '') {
				$names[$state] = true;
			}
		}

		return $names;
	}//end collectStateNames()

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

	/**
	 * Build one error entry.
	 *
	 * @param string $code    Stable error code.
	 * @param string $message Human-readable message.
	 *
	 * @return array{code: string, message: string}
	 */
	private function error(string $code, string $message): array {
		return ['code' => $code, 'message' => $message];
	}//end error()
}//end class
