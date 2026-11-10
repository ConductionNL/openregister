<?php

/**
 * LifecycleTransitionOptionsValidator: the rules of a transition's optional keys.
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
use OCA\OpenRegister\Service\Rules\ConditionDialect;

/**
 * Shape-checks the optional keys of a static-mode transition. A malformed key is
 * refused at schema save rather than left to fail, or to pass, at transition time.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class LifecycleTransitionOptionsValidator {

	use LifecycleErrorBuilding;

	/**
	 * The refusals of a transition's `condition`, per rule problem; `%s` is the action.
	 *
	 * @var array<string, string>
	 */
	private const CONDITION_REFUSALS = [
		'scalar' => 'Transition "%s" `condition` must be a rule object, in the JSON AST such as '
			. '{"not": {"eq": [{"prop": "object.motivering"}, null]}} or in the legacy '
			. 'JSONLogic such as {"!!": {"var": "object.motivering"}}. '
			. 'A string or other scalar is refused: '
			. 'it would evaluate as a literal and allow every attempt. The '
			. '"@self.field == \'value\'" form belongs on an `actions[]` entry, not here.',
		'empty' => 'Transition "%s" `condition` must not be empty.',
		'invalid' => 'Transition "%s" `condition` is neither a valid JSON-AST expression such as '
			. '{"gt": [{"prop": "object.bedrag"}, 500]} nor a valid JSONLogic one such as '
			. '{">": [{"var": "object.bedrag"}, 500]}.',
	];

	/**
	 * The refusals of a transition's `autoWhen`, per rule problem; `%s` is the action.
	 *
	 * @var array<string, string>
	 */
	private const AUTO_WHEN_REFUSALS = [
		'scalar' => 'Transition "%s" `autoWhen` must be a rule object, in the JSON AST such as '
			. '{"not": {"eq": [{"prop": "object.motivering"}, null]}} or in the legacy '
			. 'JSONLogic such as {"!!": {"var": "object.motivering"}}. '
			. 'A string or other scalar is refused: '
			. 'it would evaluate as a literal and fire the transition on every write from '
			. 'its `from` state. The "@self.field == \'value\'" form belongs on an '
			. '`actions[]` entry, not here.',
		'empty' => 'Transition "%s" `autoWhen` must not be empty.',
		'invalid' => 'Transition "%s" `autoWhen` is neither a valid JSON-AST expression nor a valid '
			. 'JSONLogic one. Write it as a rule object, for example '
			. '{"not": {"eq": [{"prop": "object.motivering"}, null]}}.',
	];

	/**
	 * Constructor.
	 *
	 * @param LifecycleMessageValidator $messages Owns the shape rules of a refusal `message`.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly LifecycleMessageValidator $messages = new LifecycleMessageValidator(),
	) {
	}//end __construct()

	/**
	 * Shape-check a transition's optional keys.
	 *
	 * `requires` is a non-empty DI tag (resolved at install and first use, not
	 * here); `authorization` a group/role gate; `condition` a JSONLogic
	 * precondition; `autoWhen`/`executionMode` make the transition fire on its own
	 * (refused when malformed, as a stored broken rule fires on every write);
	 * `message` the refusal text of a declined condition.
	 *
	 * @param array<string, mixed> $spec   The transition declaration.
	 * @param string               $action The action name.
	 *
	 * @return array<int, array{code: string, message: string}> List of errors (empty = valid).
	 */
	public function validate(array $spec, string $action): array {
		$errors = $this->validateRequires(spec: $spec, action: $action);
		if (isset($spec['authorization']) === true) {
			$errors = array_merge($errors, $this->validateTransitionAuthorization(authorization: $spec['authorization'], action: $action));
		}

		if (isset($spec['condition']) === true) {
			$errors = array_merge($errors, $this->validateTransitionCondition(condition: $spec['condition'], action: $action));
		}

		$errors = array_merge($errors, $this->validateAutomaticTransition(spec: $spec, action: $action));
		if (isset($spec['message']) === true) {
			$errors = array_merge($errors, $this->messages->validate(message: $spec['message'], action: $action));
		}

		return $errors;
	}//end validate()


	/**
	 * Check a transition's optional `requires`: a non-empty DI tag.
	 *
	 * @param array<string, mixed> $spec   The transition declaration.
	 * @param string               $action The action name.
	 *
	 * @return array<int, array{code: string, message: string}> List of errors (empty = valid).
	 */
	private function validateRequires(array $spec, string $action): array {
		if (isset($spec['requires']) === true && (is_string($spec['requires']) === false || $spec['requires'] === '')) {
			$message = sprintf('Transition "%s" `requires` must be a non-empty DI tag string.', $action);
			return [$this->error(code: 'lifecycle-requires-malformed', message: $message)];
		}

		return [];
	}//end validateRequires()


	/**
	 * Shape-check a transition's optional `authorization` list.
	 *
	 * Valid: a non-empty array whose entries are either non-empty NC group id
	 * strings or `{ "role": "<non-empty string>" }` objects. Returns a single
	 * structured error on the first violation, or none when valid.
	 *
	 * @param mixed $authorization The raw `authorization` value off the transition spec.
	 * @param string $action Transition action name, for the error message.
	 *
	 * @return array<int, array{code: string, message: string}> The first error, if any.
	 */
	private function validateTransitionAuthorization(mixed $authorization, string $action): array {
		if (is_array($authorization) === false || $authorization === []) {
			$message = sprintf('Transition "%s" `authorization` must be a non-empty array of group ids or {role} objects.', $action);
			return [$this->error(code: 'lifecycle-authorization-malformed', message: $message)];
		}

		foreach ($authorization as $entry) {
			if ($this->isAuthorizationEntry(entry: $entry) === false) {
				$message = sprintf('Transition "%s" `authorization` entries must be non-empty group id strings or {"role":"<name>"} objects.', $action);
				return [$this->error(code: 'lifecycle-authorization-entry-malformed', message: $message)];
			}
		}

		return [];
	}//end validateTransitionAuthorization()


	/**
	 * Whether an `authorization` entry is a non-empty group id or a `{role}` object.
	 *
	 * @param mixed $entry The entry.
	 *
	 * @return bool
	 */
	private function isAuthorizationEntry(mixed $entry): bool {
		if (is_array($entry) === true) {
			$entry = ($entry['role'] ?? null);
		}

		return is_string($entry) === true && $entry !== '';
	}//end isAuthorizationEntry()


	/**
	 * Shape-check a transition's optional `condition`, in either dialect.
	 *
	 * 🔴 A SCALAR IS REFUSED, AND THAT IS THE POINT OF THIS METHOD.
	 * An expression validator answers TRUE for any non-array, because in a
	 * flow a scalar is a literal and a literal is always well-formed. Here the
	 * expression decides whether a transition may proceed, and a truthy literal
	 * authorises EVERY attempt — it fails OPEN, silently, in the one place that
	 * exists to say no.
	 *
	 * The trap is not hypothetical. This annotation already carries a second
	 * `condition` key one level deeper, on `transitions.<action>.actions[]`,
	 * written in a different dialect: the `@self.<field> == '<value>'` string
	 * that {@see LifecycleActionExecutor::evaluateCondition()} parses by regex.
	 * An author who copies that form up one level writes something that looks
	 * right, stores cleanly, and gates nothing.
	 *
	 * @param mixed $condition Raw value of the transition's `condition` key.
	 * @param string $action The transition name, for the error message.
	 *
	 * @return array<int, array{code: string, message: string}> The error, if any.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function validateTransitionCondition(mixed $condition, string $action): array {
		$problem = $this->ruleProblem(rule: $condition);
		if ($problem === null) {
			return [];
		}

		return [$this->error(code: 'lifecycle-condition-malformed', message: sprintf(self::CONDITION_REFUSALS[$problem], $action))];
	}//end validateTransitionCondition()


	/**
	 * What is wrong with a rule: a scalar, empty, or in neither dialect.
	 *
	 * @param mixed $rule The rule.
	 *
	 * @return string|null `scalar`, `empty`, `invalid`, or null when well-formed.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) ConditionDialect's dialect lookup reads one
	 * constant table and holds no state; calling it statically IS the reuse, and it is
	 * the SAME lookup the save path makes, which is what keeps the two from drifting.
	 */
	private function ruleProblem(mixed $rule): ?string {
		if (is_array($rule) === false) {
			return 'scalar';
		}

		if ($rule === []) {
			return 'empty';
		}

		if (ConditionDialect::isValidCondition(node: $rule) === false) {
			return 'invalid';
		}

		return null;
	}//end ruleProblem()


	/**
	 * Shape-check a transition's optional `autoWhen` and `executionMode`.
	 *
	 * All four codes this method can return REFUSE the schema save rather than
	 * warn, which is a departure from the advisory treatment most lifecycle
	 * errors get. The reason is the direction each failure takes:
	 *
	 * - a stored scalar `autoWhen` evaluates as a truthy literal, so the
	 *   transition would fire on every write from its `from` state, writing an
	 *   audit row and a round of notifications each time;
	 * - an unknown `executionMode`, a required input beside `autoWhen` and an
	 *   `autoWhen` on a graph block all describe a move that can NEVER happen,
	 *   which is the silent no-op class the declarative-conditions link refused.
	 *
	 * Refusing breaks no existing import: no register can carry either key
	 * before this change ships them.
	 *
	 * @param array<string, mixed> $spec The transition's spec.
	 * @param string $action The transition name, for the error messages.
	 *
	 * @return array<int, array{code: string, message: string}> Errors (empty = valid).
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function validateAutomaticTransition(array $spec, string $action): array {
		$errors = [];
		if (array_key_exists('autoWhen', $spec) === true) {
			$errors = $this->validateAutoWhen(spec: $spec, action: $action);
		}

		if (array_key_exists('executionMode', $spec) === true) {
			$errors = array_merge($errors, $this->validateExecutionMode(mode: $spec['executionMode'], action: $action));
		}

		return $errors;
	}//end validateAutomaticTransition()


	/**
	 * Check a declared `autoWhen`: its rule, and that no input is required beside it.
	 *
	 * @param array<string, mixed> $spec   The transition's spec.
	 * @param string               $action The transition name, for the error messages.
	 *
	 * @return array<int, array{code: string, message: string}> Errors (empty = valid).
	 */
	private function validateAutoWhen(array $spec, string $action): array {
		$errors = $this->validateAutoWhenRule(autoWhen: $spec['autoWhen'], action: $action);
		if ($this->declaresRequiredInput(inputs: ($spec['inputs'] ?? [])) === true) {
			$template = 'Transition "%s" declares `autoWhen` beside a required `inputs` entry. '
				. 'An automatic move carries no payload, so this transition could only ever '
				. 'be refused. Drop `required: true`, or drop `autoWhen`.';
			$errors[] = $this->error(code: 'lifecycle-autowhen-requires-input', message: sprintf($template, $action));
		}

		return $errors;
	}//end validateAutoWhen()


	/**
	 * Check a declared `executionMode`: exactly one of the flow engine's two.
	 *
	 * @param mixed  $mode   The declared value.
	 * @param string $action The transition name, for the error message.
	 *
	 * @return array<int, array{code: string, message: string}> Errors (empty = valid).
	 */
	private function validateExecutionMode(mixed $mode, string $action): array {
		if (in_array($mode, [Flow::MODE_SYNC, Flow::MODE_ASYNC], true) === true) {
			return [];
		}

		$shown = gettype($mode);
		if (is_scalar($mode) === true) {
			$shown = (string)$mode;
		}

		$template = 'Transition "%s" `executionMode` "%s" must be exactly "%s" or "%s". '
			. 'Case variants are refused rather than normalised, so the lifecycle and the '
			. 'flow engine cannot drift into two spellings of the same two words.';

		return [$this->error(code: 'lifecycle-execution-mode-malformed', message: sprintf($template, $action, $shown, Flow::MODE_SYNC, Flow::MODE_ASYNC))];
	}//end validateExecutionMode()


	/**
	 * Shape-check the rule object of a transition's `autoWhen`.
	 *
	 * 🔴 A SCALAR IS REFUSED, AND THAT IS THE POINT OF THIS METHOD, for the
	 * same reason {@see validateTransitionCondition()} refuses one: a scalar
	 * handed to an expression validator is a literal and always valid. Here a
	 * truthy literal does not merely fail open once, it fires the transition
	 * again on every write.
	 *
	 * `autoWhen` is evaluated by {@see \OCA\OpenRegister\Service\Lifecycle\LifecycleConditionEvaluator::holds()},
	 * the same method a `condition` goes through, so it accepts the same two
	 * dialects. Accepting fewer here would refuse at save a rule the engine
	 * would have run.
	 *
	 * @param mixed $autoWhen Raw value of the transition's `autoWhen` key.
	 * @param string $action The transition name, for the error message.
	 *
	 * @return array<int, array{code: string, message: string}> The error, if any.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function validateAutoWhenRule(mixed $autoWhen, string $action): array {
		$problem = $this->ruleProblem(rule: $autoWhen);
		if ($problem === null) {
			return [];
		}

		return [$this->error(code: 'lifecycle-autowhen-malformed', message: sprintf(self::AUTO_WHEN_REFUSALS[$problem], $action))];
	}//end validateAutoWhenRule()


	/**
	 * Whether a transition's `inputs` declaration carries a required entry.
	 *
	 * Malformed entries are skipped rather than fatal, matching
	 * {@see TransitionEngine::normaliseDeclaredInputs()}: a broken declaration
	 * allowlists nothing, and reporting it is that method's `inputs` contract,
	 * not this one's.
	 *
	 * @param mixed $inputs The transition's declared `inputs` list.
	 *
	 * @return bool True when at least one declared input is `required`.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function declaresRequiredInput(mixed $inputs): bool {
		if (is_array($inputs) === false) {
			return false;
		}

		foreach ($inputs as $input) {
			if (is_array($input) === true && ($input['required'] ?? false) === true) {
				return true;
			}
		}

		return false;
	}//end declaresRequiredInput()
}//end class
