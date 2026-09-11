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

use OCA\OpenRegister\Service\Flow\FlowExpression;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

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
 * - An expression that cannot be evaluated refuses: `FlowExpression::isTrue()`
 *   answers false for it.
 * - A condition that is present but is not a non-empty rule object refuses
 *   BEFORE evaluation. This does not rely on save-time validation having run:
 *   SchemaMapper stores most invalid lifecycle annotations with only a warning,
 *   and handed to FlowExpression a scalar evaluates as a truthy literal, which
 *   would authorise every transition the condition was written to block.
 *
 * Not `final`, and only so that {@see AutoTransitionSelector}'s tests can hand
 * it a double and prove the selector evaluates no JSONLogic of its own. It is
 * not designed for subclassing in production: there is meant to be exactly one
 * implementation of {@see holds()}, which is the whole point of the class.
 */
class LifecycleConditionEvaluator {

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
	 * @param IL10N $l10n Translation layer for the engine's own refusal message.
	 * @param LoggerInterface $logger Logs refusals so a bad rule is diagnosable.
	 *
	 * @spec openspec/changes/lifecycle-declarative-conditions/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
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
	 * @spec openspec/changes/lifecycle-declarative-conditions/specs/object-lifecycle/spec.md
	 * @spec openspec/changes/lifecycle-auto-transitions/specs/object-lifecycle/spec.md
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
		if (array_key_exists('condition', $spec) === false || $spec['condition'] === null) {
			return null;
		}

		$condition = $spec['condition'];

		if (
			$this->holds(
				rule: $condition,
				newData: $newData,
				oldData: $oldData,
				action: $action,
				from: $from,
				to: $to,
				schemaSlug: $schemaSlug,
				field: $field
			) === true
		) {
			return null;
		}

		// LOG LEVEL ONLY, NOT A GUARD. The fail-closed refusal of a value that
		// is not a rule object lives in holds(), which has already warned about
		// it by the time this runs. This reads the same shape to decide whether
		// the miss deserves the quieter line: a well-formed rule that simply
		// does not hold is the feature working, and is debug. It is logged at
		// all because a mistyped `var` path resolves to null and is
		// indistinguishable, in the response, from an honest refusal.
		if (is_array($condition) === true && $condition !== []) {
			$this->logger->debug(
				'[LifecycleConditionEvaluator] Transition condition did not hold.',
				['schema' => $schemaSlug, 'action' => $action, 'field' => $field]
			);
		}

		return $this->refusalFor(spec: $spec, action: $action, field: $field);
	}//end refusal()

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
	 *   reaches `FlowExpression`, where a scalar evaluates as a truthy literal;
	 * - an expression that cannot be evaluated counts as not holding, because
	 *   `FlowExpression::isTrue()` answers false for it.
	 *
	 * Both directions fail closed, which means the opposite thing for the two
	 * callers and the right thing for each: a condition that cannot be trusted
	 * refuses the move, an `autoWhen` that cannot be trusted does not make one.
	 *
	 * A well-formed rule that simply does not hold logs NOTHING here. For an
	 * `autoWhen` that is the common case on every write, and a line per write
	 * per candidate would drown the log that the malformed case needs to reach.
	 *
	 * @param mixed $rule The rule to evaluate: a `condition` or an `autoWhen`.
	 * @param array<string, mixed> $newData The object as it would be saved, or as stored.
	 * @param array<string, mixed> $oldData The object as it was before the write.
	 * @param string $action The transition's name.
	 * @param string $from The lifecycle value being moved away from.
	 * @param string $to The lifecycle value being moved to.
	 * @param string $schemaSlug The schema's slug, for the log line.
	 * @param string $field The lifecycle field's name.
	 *
	 * @return bool True only when the rule is a non-empty rule object that evaluates true.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) FlowExpression is the engine's
	 * stateless expression facade; calling it statically IS the reuse.
	 *
	 * @spec openspec/changes/lifecycle-auto-transitions/specs/object-lifecycle/spec.md
	 */
	public function holds(
		mixed $rule,
		array $newData,
		array $oldData,
		string $action,
		string $from,
		string $to,
		string $schemaSlug,
		string $field,
	): bool {
		if (is_array($rule) === false || $rule === []) {
			// A fault in the schema, not the rule working, hence a warning.
			// The runtime does not trust save-time validation: a schema written
			// by a path that skipped the mapper can still carry a scalar here.
			$this->logger->warning(
				'[LifecycleConditionEvaluator] Transition condition is not a JSONLogic rule object; refusing.',
				['schema' => $schemaSlug, 'action' => $action, 'field' => $field]
			);
			return false;
		}

		return FlowExpression::isTrue(
			logic: $rule,
			data: $this->document(newData: $newData, oldData: $oldData, action: $action, from: $from, to: $to)
		);
	}//end holds()

	/**
	 * Build the refusal error for a transition.
	 *
	 * @param array<string, mixed> $spec The matched transition's spec.
	 * @param string $action The matched transition's name.
	 * @param string $field The lifecycle field's name.
	 *
	 * @return array{code: string, field: string, action: string, message: string}
	 */
	private function refusalFor(array $spec, string $action, string $field): array {
		return [
			'code' => self::CODE_UNMET,
			'field' => $field,
			'action' => $action,
			'message' => $this->message(declared: ($spec['message'] ?? null), action: $action, field: $field),
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
	 * @param array<string, mixed> $newData The object as it would be saved.
	 * @param array<string, mixed> $oldData The object as currently stored.
	 * @param string $action The matched transition's name.
	 * @param string $from The lifecycle value being moved away from.
	 * @param string $to The lifecycle value being moved to.
	 *
	 * @return array<string, mixed>
	 */
	private function document(array $newData, array $oldData, string $action, string $from, string $to): array {
		$user = $this->userSession->getUser();
		$uid = '';
		$groups = [];
		if ($user !== null) {
			$uid = $user->getUID();
			$groups = $this->groupManager->getUserGroupIds($user);
		}

		return [
			'object' => $newData,
			'previous' => $oldData,
			'user' => ['uid' => $uid, 'groups' => $groups],
			'transition' => ['action' => $action, 'from' => $from, 'to' => $to],
		];
	}//end document()

	/**
	 * The text a refusal carries.
	 *
	 * An author's `message` is passed through UNTRANSLATED in both shapes: it
	 * is their words, and a catalogue lookup would search for a string that was
	 * never in one. Only the engine's own fallback is translated.
	 *
	 * @param mixed $declared The transition's `message`: string, map, or absent.
	 * @param string $action The matched transition's name.
	 * @param string $field The lifecycle field's name.
	 *
	 * @return string
	 */
	private function message(mixed $declared, string $action, string $field): string {
		if (is_string($declared) === true && $declared !== '') {
			return $declared;
		}

		if (is_array($declared) === true) {
			$resolved = $this->fromLocaleMap(map: $declared);
			if ($resolved !== null) {
				return $resolved;
			}
		}

		return $this->l10n->t(
			'The conditions for "%1$s" are not met, so "%2$s" cannot change yet.',
			[$action, $field]
		);
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

		$candidates = [$this->l10n->getLanguageCode(), $default, 'en'];
		foreach ($candidates as $candidate) {
			if (is_string($candidate) === true && is_string($map[$candidate] ?? null) === true && $map[$candidate] !== '') {
				return $map[$candidate];
			}
		}

		foreach ($map as $text) {
			if (is_string($text) === true && $text !== '') {
				return $text;
			}
		}

		return null;
	}//end fromLocaleMap()
}//end class
