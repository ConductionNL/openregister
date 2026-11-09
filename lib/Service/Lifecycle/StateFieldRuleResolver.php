<?php

/**
 * OpenRegister StateFieldRuleResolver
 *
 * Turns `x-openregister-lifecycle.states.<state>.fields` into the three flat
 * lists that apply to one object, for the user who asked.
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
 * @spec openspec/specs/row-field-level-security/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Lifecycle;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Rules\ConditionDialect;
use OCP\IGroupManager;
use OCP\IUserSession;

/**
 * One reading of the state's field block, shared by every door.
 *
 * The render path, the save path and the published `@self.fieldRules` must
 * agree about which fields a state hides, freezes and demands, or a form is
 * refused for a rule it was never shown. They agree because they all ask this
 * class, and it answers from the declaration alone.
 *
 * **Admin is not exempt, and that is deliberate.** Property authorization is a
 * privilege grant, so an administrator bypasses it. A state field rule is not a
 * grant: it is the case type saying that a closed dossier's decision is frozen
 * and that closing without an outcome is not a dossier. An administrator who
 * bypassed it would write a record the case type says cannot exist. An author
 * who wants a rule to spare a role says so with `groups`, which is the axis
 * that exists for exactly that.
 *
 * @spec openspec/specs/row-field-level-security/spec.md
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) The branches are the declaration's
 *   tolerated shapes, not logic: three rule kinds, two spellings of a condition, two of a
 *   field list, four kinds of group clause, and the memo's one guard. Splitting them
 *   across classes would put the schema's grammar in two places, which is the drift the
 *   single-reading rule in the docblock above exists to prevent.
 */
class StateFieldRuleResolver implements StateFieldRuleResolverInterface {

	/**
	 * The three rule kinds a state's `fields` block may declare.
	 *
	 * @var array<int, string>
	 */
	public const KINDS = ['hidden', 'readOnly', 'required'];

	/**
	 * Per-request memo of resolutions that do not read the object's data.
	 *
	 * @var array<string, StateFieldRules>
	 */
	private array $memo = [];

	/**
	 * Constructor.
	 *
	 * @param IUserSession $userSession The session naming the user a rule is resolved for.
	 * @param IGroupManager $groupManager The group memberships a `groups` clause is tested against.
	 * @param ConditionDialect $dialect The evaluator for a rule's `when` condition, in either dialect.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md
	 */
	public function __construct(
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly ConditionDialect $dialect,
	) {
	}//end __construct()

	/**
	 * The lifecycle annotation of a schema, or null when it declares none.
	 *
	 * @param Schema $schema The schema to read.
	 *
	 * @return array<string, mixed>|null The annotation.
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md
	 */
	public function annotationOf(Schema $schema): ?array {
		$configuration = ($schema->getConfiguration() ?? []);
		if (is_array($configuration) === false) {
			return null;
		}

		$annotation = ($configuration['x-openregister-lifecycle'] ?? null);
		if (is_array($annotation) === false || $annotation === []) {
			return null;
		}

		return $annotation;
	}//end annotationOf()

	/**
	 * The name of the lifecycle field, accepting the `property` alias.
	 *
	 * @param array<string, mixed> $annotation The lifecycle annotation.
	 *
	 * @return string The field name, empty when the annotation names none.
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md
	 */
	public function fieldOf(array $annotation): string {
		return (string)($annotation['field'] ?? ($annotation['property'] ?? ''));
	}//end fieldOf()

	/**
	 * The state an object is in, read off its own data.
	 *
	 * @param array<string, mixed> $annotation The lifecycle annotation.
	 * @param array<string, mixed> $data The object's data.
	 *
	 * @return string|null The state, or null when the object carries no value.
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md
	 */
	public function stateOf(array $annotation, array $data): ?string {
		$field = $this->fieldOf(annotation: $annotation);
		if ($field === '') {
			return null;
		}

		$value = ($data[$field] ?? null);
		if (is_string($value) === false || $value === '') {
			return null;
		}

		return $value;
	}//end stateOf()

	/**
	 * The rules that apply to an object as it stands.
	 *
	 * @param Schema $schema The schema the object belongs to.
	 * @param array<string, mixed> $data The object's data.
	 * @param string|null $state The state to resolve for; defaults to the object's own.
	 *
	 * @return StateFieldRules The three lists, already decided.
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md
	 */
	public function resolve(Schema $schema, array $data, ?string $state = null): StateFieldRules {
		$annotation = $this->annotationOf(schema: $schema);
		if ($annotation === null) {
			return new StateFieldRules();
		}

		$state = ($state ?? $this->stateOf(annotation: $annotation, data: $data));
		if ($state === null) {
			return new StateFieldRules();
		}

		return $this->memoised(schema: $schema, annotation: $annotation, data: $data, state: $state);
	}//end resolve()

	/**
	 * The rules for a state, from the per-request memo when they do not read the object.
	 *
	 * Per-request memo, per openregister ADR-009. The key is the triple the
	 * answer actually depends on when no condition is declared: the schema,
	 * the state and who is asking. A state whose rules DO read the object's
	 * data is deliberately not cached — the requirement is that
	 * `@self.fieldRules` reports the rules that apply to this object as it
	 * stands, and a memo across objects would report the first object's.
	 *
	 * @param Schema               $schema     The schema.
	 * @param array<string, mixed> $annotation The lifecycle annotation.
	 * @param array<string, mixed> $data       The object data.
	 * @param string               $state      The state.
	 *
	 * @return StateFieldRules
	 */
	private function memoised(Schema $schema, array $annotation, array $data, string $state): StateFieldRules {
		if ($this->isConditional(annotation: $annotation, state: $state) === true) {
			return $this->resolveFromAnnotation(annotation: $annotation, data: $data, state: $state);
		}

		$key = implode('|', [(string)$schema->getId(), $state, $this->groupsKey()]);
		if (isset($this->memo[$key]) === true) {
			return $this->memo[$key];
		}

		$this->memo[$key] = $this->resolveFromAnnotation(annotation: $annotation, data: $data, state: $state);
		return $this->memo[$key];
	}//end memoised()

	/**
	 * Whether a state's rules read the object's data at all.
	 *
	 * @param array<string, mixed> $annotation The lifecycle annotation.
	 * @param string $state The state to inspect.
	 *
	 * @return bool True when any rule of the state carries a condition.
	 */
	private function isConditional(array $annotation, string $state): bool {
		$block = $this->blockFor(annotation: $annotation, state: $state);
		if ($block === null) {
			return false;
		}

		if (($block['condition'] ?? null) !== null) {
			return true;
		}

		return self::hasConditionalFields(fields: ($block['fields'] ?? []));
	}//end isConditional()

	/**
	 * Whether a block's `fields` hold an entry with a `when` / `condition`.
	 *
	 * @param mixed $fields The block's `fields`.
	 *
	 * @return bool
	 */
	private static function hasConditionalFields(mixed $fields): bool {
		if (is_array($fields) === false) {
			return false;
		}

		foreach ($fields as $entries) {
			if (self::hasConditionalEntry(entries: $entries) === true) {
				return true;
			}
		}

		return false;
	}//end hasConditionalFields()

	/**
	 * Whether a list of field-rule entries holds one with a `when` / `condition`.
	 *
	 * @param mixed $entries The entries of one kind.
	 *
	 * @return bool
	 */
	private static function hasConditionalEntry(mixed $entries): bool {
		if (is_array($entries) === false) {
			return false;
		}

		foreach ($entries as $entry) {
			if (is_array($entry) === true && ($entry['when'] ?? ($entry['condition'] ?? null)) !== null) {
				return true;
			}
		}

		return false;
	}//end hasConditionalEntry()

	/**
	 * A stable key for the current user's group membership.
	 *
	 * @return string The key.
	 */
	private function groupsKey(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return '@anonymous';
		}

		$groups = $this->groupManager->getUserGroupIds($user);
		sort($groups);
		return ($user->getUID() . ':' . implode(',', $groups));
	}//end groupsKey()

	/**
	 * The rules that apply, read straight from an annotation.
	 *
	 * Separate from {@see self::resolve()} because the validator, the trial
	 * surface and the unit tests hold an annotation rather than a stored
	 * schema, and building a Schema entity to ask one question would put the
	 * mapper in the way of a pure decision.
	 *
	 * @param array<string, mixed> $annotation The lifecycle annotation.
	 * @param array<string, mixed> $data The object's data.
	 * @param string|null $state The state to resolve for; defaults to the object's own.
	 *
	 * @return StateFieldRules The three lists, already decided.
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Each branch is one way the declaration
	 *   can legitimately say "nothing applies"; see the class docblock.
	 * @SuppressWarnings(PHPMD.NPathComplexity) Same reason.
	 */
	public function resolveFromAnnotation(array $annotation, array $data, ?string $state = null): StateFieldRules {
		$state = ($state ?? $this->stateOf(annotation: $annotation, data: $data));
		if ($state === null) {
			return new StateFieldRules();
		}

		$block = $this->blockFor(annotation: $annotation, state: $state);
		if ($block === null) {
			return new StateFieldRules(state: $state);
		}

		return $this->rulesFromBlock(state: $state, block: $block, data: $data);
	}//end resolveFromAnnotation()

	/**
	 * The rules a state block yields for this object and caller.
	 *
	 * @param string               $state The state.
	 * @param array<string, mixed> $block The state's block.
	 * @param array<string, mixed> $data  The object data.
	 *
	 * @return StateFieldRules
	 */
	private function rulesFromBlock(string $state, array $block, array $data): StateFieldRules {
		$document = $this->document(data: $data, state: $state);

		// The state block's own `condition` gates the whole block. It is the
		// condition `RuleInventoryService` publishes for a `stateFieldRule`, so
		// honouring it here is what keeps the operator's inventory honest: a
		// rule listed as conditional must actually be conditional.
		if ($this->blockConditionHolds(block: $block, document: $document) === false) {
			return new StateFieldRules(state: $state);
		}

		$fields = ($block['fields'] ?? []);
		if (is_array($fields) === false) {
			return new StateFieldRules(state: $state);
		}

		[$resolved, $messages] = $this->collectFields(fields: $fields, document: $document);

		return new StateFieldRules(
			state: $state,
			hidden: array_values($resolved['hidden']),
			readOnly: array_values($resolved['readOnly']),
			required: array_values($resolved['required']),
			messages: $messages
		);
	}//end rulesFromBlock()

	/**
	 * Whether the block's own `condition` holds (a block without one always holds).
	 *
	 * @param array<string, mixed> $block    The state's block.
	 * @param array<string, mixed> $document The condition document.
	 *
	 * @return bool
	 */
	private function blockConditionHolds(array $block, array $document): bool {
		$blockCondition = ($block['condition'] ?? null);
		return $blockCondition === null || $this->dialect->holds(node: $blockCondition, document: $document) === true;
	}//end blockConditionHolds()

	/**
	 * The fields each kind names for this caller and object, and the first message per field.
	 *
	 * @param array<string, mixed> $fields   The block's `fields`.
	 * @param array<string, mixed> $document The condition document.
	 *
	 * @return array{0: array<string, array<string, string>>, 1: array<string, string>} [fields by kind, messages].
	 */
	private function collectFields(array $fields, array $document): array {
		$resolved = ['hidden' => [], 'readOnly' => [], 'required' => []];
		$messages = [];

		foreach (self::KINDS as $kind) {
			$entries = ($fields[$kind] ?? []);
			if (is_array($entries) === false) {
				continue;
			}

			foreach ($entries as $entry) {
				[$resolved[$kind], $messages] = $this->collectEntry(entry: $entry, document: $document, names: $resolved[$kind], messages: $messages);
			}
		}

		return [$resolved, $messages];
	}//end collectFields()

	/**
	 * Add one entry's applicable fields to its kind, and its message to fields that have none yet.
	 *
	 * @param mixed                 $entry    The rule entry.
	 * @param array<string, mixed>  $document The condition document.
	 * @param array<string, string> $names    The kind's fields so far.
	 * @param array<string, string> $messages The messages so far.
	 *
	 * @return array{0: array<string, string>, 1: array<string, string>} [the kind's fields, messages].
	 */
	private function collectEntry(mixed $entry, array $document, array $names, array $messages): array {
		foreach ($this->applicableFields(entry: $entry, document: $document) as $name) {
			$names[$name] = $name;
			$message = ($entry['message'] ?? null);
			if (is_string($message) === true && $message !== '' && isset($messages[$name]) === false) {
				$messages[$name] = $message;
			}
		}

		return [$names, $messages];
	}//end collectEntry()

	/**
	 * The state block, when the state declares one and it is switched on.
	 *
	 * @param array<string, mixed> $annotation The lifecycle annotation.
	 * @param string $state The state to read.
	 *
	 * @return array<string, mixed>|null The block, or null when there is none to apply.
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md
	 */
	public function blockFor(array $annotation, string $state): ?array {
		$states = ($annotation['states'] ?? []);
		if (is_array($states) === false) {
			return null;
		}

		$block = ($states[$state] ?? null);
		if (self::isEnabledBlock(block: $block) === false) {
			return null;
		}

		return $block;
	}//end blockFor()

	/**
	 * Whether a state block is a non-empty object that is not switched off.
	 *
	 * `enabled: false` is the switch `RuleEnablementService` writes. A
	 * resolver that ignored it would make the operator's off switch a
	 * silent no-op, which is worse than not having one.
	 *
	 * @param mixed $block The state's block.
	 *
	 * @return bool
	 */
	private static function isEnabledBlock(mixed $block): bool {
		if (is_array($block) === false || $block === []) {
			return false;
		}

		return ($block['enabled'] ?? true) !== false;
	}//end isEnabledBlock()

	/**
	 * The document a state rule's condition is evaluated against.
	 *
	 * @param array<string, mixed> $data The object's data.
	 * @param string $state The state being resolved.
	 *
	 * @return array<string, mixed> The evaluation document.
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md
	 */
	public function document(array $data, string $state): array {
		$user = $this->userSession->getUser();
		$uid = '';
		$groups = [];
		if ($user !== null) {
			$uid = $user->getUID();
			$groups = $this->groupManager->getUserGroupIds($user);
		}

		return [
			'object' => $data,
			'user' => ['uid' => $uid, 'groups' => $groups],
			'state' => $state,
		];
	}//end document()

	/**
	 * The field names of one entry, when the entry applies to this user and object.
	 *
	 * @param mixed $entry The declared entry.
	 * @param array<string, mixed> $document The evaluation document.
	 *
	 * @return array<int, string> The field names, empty when the entry does not apply.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) One branch per tolerated spelling of
	 *   `fields`, `field`, `when` and `condition`.
	 */
	private function applicableFields(mixed $entry, array $document): array {
		if (is_array($entry) === false) {
			return [];
		}

		if ($this->appliesToUser(entry: $entry) === false) {
			return [];
		}

		if ($this->whenHolds(entry: $entry, document: $document) === false) {
			return [];
		}

		return self::entryFieldNames(entry: $entry);
	}//end applicableFields()

	/**
	 * Whether an entry's `when` / `condition` holds (an entry without one always holds).
	 *
	 * @param array<string, mixed> $entry    The rule entry.
	 * @param array<string, mixed> $document The condition document.
	 *
	 * @return bool
	 */
	private function whenHolds(array $entry, array $document): bool {
		$when = ($entry['when'] ?? ($entry['condition'] ?? null));
		return $when === null || $this->dialect->holds(node: $when, document: $document) === true;
	}//end whenHolds()

	/**
	 * The non-empty field names an entry lists under `fields` (or `field`), one string or a list.
	 *
	 * @param array<string, mixed> $entry The rule entry.
	 *
	 * @return array<int, string>
	 */
	private static function entryFieldNames(array $entry): array {
		$names = ($entry['fields'] ?? ($entry['field'] ?? []));
		if (is_string($names) === true) {
			$names = [$names];
		}

		if (is_array($names) === false) {
			return [];
		}

		return self::nonEmptyStrings(values: $names);
	}//end entryFieldNames()

	/**
	 * The non-empty strings of a list, in order.
	 *
	 * @param array<int|string, mixed> $values The list.
	 *
	 * @return array<int, string>
	 */
	private static function nonEmptyStrings(array $values): array {
		$out = [];
		foreach ($values as $value) {
			if (is_string($value) === true && $value !== '') {
				$out[] = $value;
			}
		}

		return $out;
	}//end nonEmptyStrings()

	/**
	 * Whether an entry's `groups` clause covers the current user.
	 *
	 * An entry without `groups` applies to everyone, which is the declared
	 * contract and the reason a case type can freeze a field for the whole
	 * organisation in one line.
	 *
	 * @param array<string, mixed> $entry The declared entry.
	 *
	 * @return bool True when the entry applies.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) `groups` has four accepted forms and
	 *   two pseudo-groups; each is a branch, and each is a documented part of the contract.
	 * @SuppressWarnings(PHPMD.NPathComplexity) Same reason.
	 */
	private function appliesToUser(array $entry): bool {
		$groups = self::entryGroups(entry: $entry);
		if ($groups === null) {
			return true;
		}

		$user = $this->userSession->getUser();
		$userGroups = [];
		if ($user !== null) {
			$userGroups = $this->groupManager->getUserGroupIds($user);
		}

		foreach ($groups as $group) {
			if (self::groupAdmits(group: $group, signedIn: $user !== null, userGroups: $userGroups) === true) {
				return true;
			}
		}

		return false;
	}//end appliesToUser()

	/**
	 * The groups an entry is scoped to, or null when it applies to everyone.
	 *
	 * A missing, empty or unreadable (non-list, non-string) `groups` scopes the entry to no one in particular.
	 *
	 * @param array<string, mixed> $entry The rule entry.
	 *
	 * @return array<int|string, mixed>|null
	 */
	private static function entryGroups(array $entry): ?array {
		$groups = ($entry['groups'] ?? null);
		if (is_string($groups) === true) {
			$groups = [$groups];
		}

		if (is_array($groups) === false || $groups === []) {
			return null;
		}

		return $groups;
	}//end entryGroups()

	/**
	 * Whether one scoping group admits the caller: `public` always, `authenticated` when signed in, else membership.
	 *
	 * @param mixed              $group      The scoping group.
	 * @param bool               $signedIn   Whether a user is signed in.
	 * @param array<int, string> $userGroups The caller's groups.
	 *
	 * @return bool
	 */
	private static function groupAdmits(mixed $group, bool $signedIn, array $userGroups): bool {
		if (is_string($group) === false) {
			return false;
		}

		return $group === 'public'
			|| ($group === 'authenticated' && $signedIn === true)
			|| in_array($group, $userGroups, true) === true;
	}//end groupAdmits()
}//end class
