<?php

/**
 * OpenRegister LifecycleConditionEvaluator
 *
 * Decides whether a lifecycle transition's declarative JSONLogic `condition`
 * lets the transition proceed, and if not, what the refusal says.
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

use OCA\OpenRegister\Service\Rules\ConditionDialect;
use OCP\IGroupManager;
use OCP\IUserSession;

/**
 * Evaluates a transition `condition` and produces the refusal, if any.
 *
 * Kept out of LifecycleValidationListener on purpose. The listener decides
 * WHICH transition an edit is and in what order the gates run; this class
 * decides one gate. Folding it in pushed the listener past the complexity and
 * coupling limits, and the two concerns change for different reasons.
 *
 * FAIL-CLOSED IN BOTH DIRECTIONS
 * ------------------------------
 * - An expression that cannot be evaluated refuses: {@see ConditionDialect}
 *   answers false for it, in either dialect.
 * - A condition that is present but is not a non-empty rule object refuses
 *   BEFORE evaluation. This does not rely on save-time validation having run:
 *   SchemaMapper stores most invalid lifecycle annotations with only a warning,
 *   and handed to an expression evaluator a scalar evaluates as a truthy
 *   literal, which would authorise every transition the condition was written
 *   to block.
 *
 * TWO DIALECTS, ONE EVALUATION POINT
 * ----------------------------------
 * A condition may be written in JSONLogic (`{">": [{"var": "object.bedrag"}, 500]}`)
 * or in the JSON AST (`{"gt": [{"prop": "object.bedrag"}, 500]}`). Which one it
 * is, is decided by {@see ConditionDialect}, the same class the dry run and the
 * run log's tracer ask, so a rule tried in the trial surface is evaluated by the
 * dialect the save path will use on it. Per D-7 the AST is the authored form and
 * JSONLogic is legacy, kept because schemas carry it today.
 *
 * Not `final`, and only so that {@see AutoTransitionSelector}'s tests can hand
 * it a double and prove the selector evaluates no JSONLogic of its own. It is
 * not designed for subclassing in production: there is meant to be exactly one
 * implementation of {@see holds()}, which is the whole point of the class.
 */
class LifecycleConditionEvaluator implements LifecycleConditionEvaluatorInterface {
	/**
	 * The code every condition refusal carries, malformed or merely unmet.
	 *
	 * @var string
	 */
	public const CODE_UNMET = 'lifecycle-condition-unmet';

	/**
	 * Constructor.
	 *
	 * @param IUserSession $userSession Current user session, for the `user` key.
	 * @param IGroupManager $groupManager Resolves the caller's group ids.
	 * @param LifecycleConditionReporting $reporting The refusal message's translations and the diagnostic log.
	 * @param ConditionDialect $dialect Decides which dialect a condition is written in.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	public function __construct(
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly LifecycleConditionReporting $reporting,
		private readonly ConditionDialect $dialect,
	) {
	}//end __construct()

	/**
	 * The refusal for a transition, or null when its condition lets it through.
	 *
	 * A transition declaring no condition always yields null.
	 *
	 * @param array<string, mixed> $spec The matched transition's spec.
	 * @param array<string, mixed> $newData The object as it would be saved.
	 * @param array<string, mixed> $oldData The object as currently stored.
	 * @param string $action The matched transition's name.
	 * @param string $from The lifecycle value being moved away from.
	 * @param string $to The lifecycle value being moved to.
	 * @param string $schemaSlug The schema's slug, for the log line.
	 * @param string $field The lifecycle field's name.
	 *
	 * @return array{code: string, field: string, action: string, message: string}|null
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function refusal(
		array $spec,
		array $newData,
		array $oldData,
		string $action,
		string $from,
		string $to,
		string $schemaSlug,
		string $field,
	): ?array {
		$attempt = new TransitionAttempt(
			write: new LifecycleWrite(newData: $newData, oldData: $oldData, schemaSlug: $schemaSlug, field: $field),
			move: new TransitionMove(action: $action, from: $from, to: $to)
		);
		return $this->refusalOf(spec: $spec, attempt: $attempt);
	}//end refusal()

	/**
	 * The refusal for a transition attempt, or null when its condition lets it through.
	 *
	 * @param array<string, mixed> $spec    The matched transition's spec.
	 * @param TransitionAttempt    $attempt The write and the transition it attempts.
	 *
	 * @return array{code: string, field: string, action: string, message: string}|null
	 */
	private function refusalOf(array $spec, TransitionAttempt $attempt): ?array {
		$condition = $this->activeCondition(spec: $spec);
		if ($condition === null || $this->holds(rule: $condition, attempt: $attempt) === true) {
			return null;
		}

		$context = ['schema' => $attempt->write->schemaSlug, 'action' => $attempt->move->action, 'field' => $attempt->write->field];
		$this->logMiss(condition: $condition, context: $context);

		return $this->refusalFor(spec: $spec, attempt: $attempt);
	}//end refusalOf()

	/**
	 * The transition's condition, or null when there is none to evaluate.
	 *
	 * A condition switched off from the rule inventory refuses nothing. The
	 * switch bites HERE, at the one place a transition condition is
	 * evaluated, so that the inventory reports a state it actually causes.
	 *
	 * @param array<string, mixed> $spec The matched transition's spec.
	 *
	 * @return mixed The condition, or null when absent, null or switched off.
	 */
	private function activeCondition(array $spec): mixed {
		if (array_key_exists('condition', $spec) === false || ($spec['enabled'] ?? true) === false) {
			return null;
		}

		return $spec['condition'];
	}//end activeCondition()

	/**
	 * Log a well-formed condition that did not hold.
	 *
	 * LOG LEVEL ONLY, NOT A GUARD. The fail-closed refusal of a value that
	 * is not a rule object lives in holds(), which has already warned about
	 * it by the time this runs. This reads the same shape to decide whether
	 * the miss deserves the quieter line: a well-formed rule that simply
	 * does not hold is the feature working, and is debug. It is logged at
	 * all because a mistyped `var` path resolves to null and is
	 * indistinguishable, in the response, from an honest refusal.
	 *
	 * @param mixed $condition The condition that did not hold.
	 * @param array<string, string> $context Schema, action and field.
	 *
	 * @return void
	 */
	private function logMiss(mixed $condition, array $context): void {
		if (is_array($condition) === true && $condition !== []) {
			$this->reporting->logger->debug('[LifecycleConditionEvaluator] Transition condition did not hold.', $context);
		}
	}//end logMiss()

	/**
	 * Whether a lifecycle rule holds for this object and transition.
	 *
	 * THE ONE PLACE A LIFECYCLE RULE IS EVALUATED. Both a transition's
	 * `condition` (may this move proceed) and its `autoWhen` (should this move
	 * be made) come through here, against the same document built by the same
	 * method, so the two guards the declarative-conditions link paid for are
	 * shared rather than rediscovered:
	 *
	 * - a value that is present but is NOT a non-empty rule object never
	 *   reaches an evaluator, where a scalar evaluates as a truthy literal;
	 * - an expression that cannot be evaluated counts as not holding, because
	 *   {@see ConditionDialect::holds()} answers false for it in both dialects.
	 *
	 * Both directions fail closed, which means the opposite thing for the two
	 * callers and the right thing for each: a condition that cannot be trusted
	 * refuses the move, an `autoWhen` that cannot be trusted does not make one.
	 *
	 * A well-formed rule that simply does not hold logs NOTHING here. For an
	 * `autoWhen` that is the common case on every write, and a line per write
	 * per candidate would drown the log that the malformed case needs to reach.
	 *
	 * @param mixed             $rule    The rule to evaluate: a `condition` or an `autoWhen`.
	 * @param TransitionAttempt $attempt The write (object after and before, schema slug, lifecycle field)
	 *                                   and the transition it attempts (name, from, to).
	 *
	 * @return bool True only when the rule is a non-empty rule object that evaluates true.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	public function holds(mixed $rule, TransitionAttempt $attempt): bool {
		$write = $attempt->write;
		$move  = $attempt->move;
		if (is_array($rule) === false || $rule === []) {
			// A fault in the schema, not the rule working, hence a warning.
			// The runtime does not trust save-time validation: a schema written
			// by a path that skipped the mapper can still carry a scalar here.
			$this->reporting->logger->warning(
				'[LifecycleConditionEvaluator] Transition condition is not a rule object; refusing.',
				['schema' => $write->schemaSlug, 'action' => $move->action, 'field' => $write->field]
			);
			return false;
		}

		return $this->dialect->holds(
			node: $rule,
			document: $this->documentOf(attempt: $attempt)
		);
	}//end holds()

	/**
	 * Build the refusal error for a transition.
	 *
	 * @param array<string, mixed> $spec    The matched transition's spec.
	 * @param TransitionAttempt    $attempt The refused attempt: its lifecycle field and transition name.
	 *
	 * @return array{code: string, field: string, action: string, message: string}
	 */
	private function refusalFor(array $spec, TransitionAttempt $attempt): array {
		$action = $attempt->move->action;
		$field  = $attempt->write->field;

		return [
			'code' => self::CODE_UNMET,
			'field' => $field,
			'action' => $action,
			'message' => $this->message(declared: ($spec['message'] ?? null)) ?? $this->reporting->l10n->t(
				'The conditions for "%1$s" are not met, so "%2$s" cannot change yet.',
				[$action, $field]
			),
		];
	}//end refusalFor()

	/**
	 * Build the document a condition is evaluated against.
	 *
	 * Exactly four keys, and deliberately NOT the flow engine's `json` /
	 * `binary` / `itemIndex` shape: a schema author writing a lifecycle rule is
	 * looking at an object and a transition, not at a flow item. The expression
	 * language is shared with flows; the document it reads is not.
	 *
	 * `user` is empty under `occ`, which has no session. A condition reading
	 * `user.uid` therefore refuses on the CLI unless it allows for that.
	 *
	 * PUBLIC so that the rule run log can trace a refusal against the SAME
	 * document the refusal was decided on. A tracer that rebuilt the document
	 * itself would be a second opinion about what the condition read, and would
	 * drift the first time this shape changes.
	 *
	 * @param array<string, mixed> $newData The object as it would be saved.
	 * @param array<string, mixed> $oldData The object as currently stored.
	 * @param string $action The matched transition's name.
	 * @param string $from The lifecycle value being moved away from.
	 * @param string $to The lifecycle value being moved to.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	public function document(array $newData, array $oldData, string $action, string $from, string $to): array {
		// The document reads only the two objects and the move; the schema slug
		// and field name of the write are for log lines and do not enter it.
		$write = new LifecycleWrite(newData: $newData, oldData: $oldData, schemaSlug: '', field: '');
		$move  = new TransitionMove(action: $action, from: $from, to: $to);
		return $this->documentOf(attempt: new TransitionAttempt(write: $write, move: $move));
	}//end document()

	/**
	 * Build the document a condition is evaluated against, for one attempt.
	 *
	 * @param TransitionAttempt $attempt The write (object after and before) and the move.
	 *
	 * @return array<string, mixed>
	 */
	private function documentOf(TransitionAttempt $attempt): array {
		$move = $attempt->move;
		$user = $this->userSession->getUser();
		$uid = '';
		$groups = [];
		if ($user !== null) {
			$uid = $user->getUID();
			$groups = $this->groupManager->getUserGroupIds($user);
		}

		return [
			'object' => $attempt->write->newData,
			'previous' => $attempt->write->oldData,
			'user' => ['uid' => $uid, 'groups' => $groups],
			'transition' => ['action' => $move->action, 'from' => $move->from, 'to' => $move->to],
		];
	}//end documentOf()

	/**
	 * The author's text a refusal carries, when the transition declares one.
	 *
	 * An author's `message` is passed through UNTRANSLATED in both shapes: it
	 * is their words, and a catalogue lookup would search for a string that was
	 * never in one. Only the engine's own fallback, which the caller supplies
	 * when this answers null, is translated.
	 *
	 * @param mixed $declared The transition's `message`: string, map, or absent.
	 *
	 * @return string|null The author's text, or null when there is none to use.
	 */
	private function message(mixed $declared): ?string {
		if (is_string($declared) === true && $declared !== '') {
			return $declared;
		}

		if (is_array($declared) === true) {
			return $this->fromLocaleMap(map: $declared);
		}

		return null;
	}//end message()

	/**
	 * Pick the entry of a per-locale message map for this caller.
	 *
	 * Order: the caller's configured language, then the map's `defaultLocale`,
	 * then `en`, then the first declared locale. A usable map therefore always
	 * yields text rather than falling through to the generic message.
	 *
	 * @param array<string, mixed> $map The per-locale message map.
	 *
	 * @return string|null The chosen text, or null when the map holds none.
	 */
	private function fromLocaleMap(array $map): ?string {
		$default = ($map['defaultLocale'] ?? null);
		unset($map['defaultLocale']);

		return $this->localeText(map: $map, candidates: [$this->reporting->l10n->getLanguageCode(), $default, 'en']) ?? $this->firstText(map: $map);
	}//end fromLocaleMap()

	/**
	 * The text of the first candidate locale the map carries usable text for.
	 *
	 * @param array<string, mixed> $map The locale map, without `defaultLocale`.
	 * @param array<int, mixed> $candidates The locales to try, in order.
	 *
	 * @return string|null The text, or null when no candidate has one.
	 */
	private function localeText(array $map, array $candidates): ?string {
		foreach ($candidates as $candidate) {
			if (is_string($candidate) === true && $this->usableText(text: ($map[$candidate] ?? null)) === true) {
				return $map[$candidate];
			}
		}

		return null;
	}//end localeText()

	/**
	 * The first usable text in the map, whatever its locale.
	 *
	 * @param array<string, mixed> $map The locale map, without `defaultLocale`.
	 *
	 * @return string|null The text, or null when the map has none.
	 */
	private function firstText(array $map): ?string {
		foreach ($map as $text) {
			if ($this->usableText(text: $text) === true) {
				return $text;
			}
		}

		return null;
	}//end firstText()

	/**
	 * Whether a value is a non-empty string.
	 *
	 * @param mixed $text The value.
	 *
	 * @return bool True for a non-empty string.
	 */
	private function usableText(mixed $text): bool {
		return is_string($text) === true && $text !== '';
	}//end usableText()
}//end class
