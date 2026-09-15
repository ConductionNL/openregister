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
 * @spec openspec/changes/field-rules-by-state/specs/row-field-level-security/spec.md
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
 * @spec openspec/changes/field-rules-by-state/specs/row-field-level-security/spec.md
 */
class StateFieldRuleResolver {

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
	 * @spec openspec/changes/field-rules-by-state/specs/row-field-level-security/spec.md
	 */
	public function resolve(Schema $schema, array $data, ?string $state = null): StateFieldRules {
		$annotation = $this->annotationOf(schema: $schema);
		if ($annotation === null) {
			return StateFieldRules::none();
		}

		$state = ($state ?? $this->stateOf(annotation: $annotation, data: $data));
		if ($state === null) {
			return StateFieldRules::none();
		}

		// Per-request memo, per openregister ADR-009. The key is the triple the
		// answer actually depends on when no condition is declared: the schema,
		// the state and who is asking. A state whose rules DO read the object's
		// data is deliberately not cached — the requirement is that
		// `@self.fieldRules` reports the rules that apply to this object as it
		// stands, and a memo across objects would report the first object's.
		$key = null;
		if ($this->isConditional(annotation: $annotation, state: $state) === false) {
			$key = implode('|', [(string)$schema->getId(), $state, $this->groupsKey()]);
			if (isset($this->memo[$key]) === true) {
				return $this->memo[$key];
			}
		}

		$rules = $this->resolveFromAnnotation(annotation: $annotation, data: $data, state: $state);
		if ($key !== null) {
			$this->memo[$key] = $rules;
		}

		return $rules;
	}//end resolve()

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

		$fields = ($block['fields'] ?? []);
		if (is_array($fields) === false) {
			return false;
		}

		foreach ($fields as $entries) {
			if (is_array($entries) === false) {
				continue;
			}

			foreach ($entries as $entry) {
				if (is_array($entry) === false) {
					continue;
				}

				if (($entry['when'] ?? ($entry['condition'] ?? null)) !== null) {
					return true;
				}
			}
		}

		return false;
	}//end isConditional()

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
	 * @spec openspec/changes/field-rules-by-state/specs/row-field-level-security/spec.md
	 */
	public function resolveFromAnnotation(array $annotation, array $data, ?string $state = null): StateFieldRules {
		$state = ($state ?? $this->stateOf(annotation: $annotation, data: $data));
		if ($state === null) {
			return StateFieldRules::none();
		}

		$block = $this->blockFor(annotation: $annotation, state: $state);
		if ($block === null) {
			return StateFieldRules::none(state: $state);
		}

		$document = $this->document(data: $data, state: $state);

		// The state block's own `condition` gates the whole block. It is the
		// condition `RuleInventoryService` publishes for a `stateFieldRule`, so
		// honouring it here is what keeps the operator's inventory honest: a
		// rule listed as conditional must actually be conditional.
		$blockCondition = ($block['condition'] ?? null);
		if ($blockCondition !== null && $this->dialect->holds(node: $blockCondition, document: $document) === false) {
			return StateFieldRules::none(state: $state);
		}

		$resolved = ['hidden' => [], 'readOnly' => [], 'required' => []];
		$messages = [];
		$fields = ($block['fields'] ?? []);
		if (is_array($fields) === false) {
			return StateFieldRules::none(state: $state);
		}

		foreach (self::KINDS as $kind) {
			$entries = ($fields[$kind] ?? []);
			if (is_array($entries) === false) {
				continue;
			}

			foreach ($entries as $entry) {
				$names = $this->applicableFields(entry: $entry, document: $document);
				foreach ($names as $name) {
					$resolved[$kind][$name] = $name;
					$message = ($entry['message'] ?? null);
					if (is_string($message) === true && $message !== '' && isset($messages[$name]) === false) {
						$messages[$name] = $message;
					}
				}
			}
		}

		return new StateFieldRules(
			state: $state,
			hidden: array_values($resolved['hidden']),
			readOnly: array_values($resolved['readOnly']),
			required: array_values($resolved['required']),
			messages: $messages
		);
	}//end resolveFromAnnotation()

	/**
	 * The state block, when the state declares one and it is switched on.
	 *
	 * @param array<string, mixed> $annotation The lifecycle annotation.
	 * @param string $state The state to read.
	 *
	 * @return array<string, mixed>|null The block, or null when there is none to apply.
	 */
	public function blockFor(array $annotation, string $state): ?array {
		$states = ($annotation['states'] ?? []);
		if (is_array($states) === false) {
			return null;
		}

		$block = ($states[$state] ?? null);
		if (is_array($block) === false || $block === []) {
			return null;
		}

		// `enabled: false` is the switch `RuleEnablementService` writes. A
		// resolver that ignored it would make the operator's off switch a
		// silent no-op, which is worse than not having one.
		if (($block['enabled'] ?? true) === false) {
			return null;
		}

		return $block;
	}//end blockFor()

	/**
	 * The document a state rule's condition is evaluated against.
	 *
	 * @param array<string, mixed> $data The object's data.
	 * @param string $state The state being resolved.
	 *
	 * @return array<string, mixed> The evaluation document.
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
	 */
	private function applicableFields(mixed $entry, array $document): array {
		if (is_array($entry) === false) {
			return [];
		}

		if ($this->appliesToUser(entry: $entry) === false) {
			return [];
		}

		$when = ($entry['when'] ?? ($entry['condition'] ?? null));
		if ($when !== null && $this->dialect->holds(node: $when, document: $document) === false) {
			return [];
		}

		$names = ($entry['fields'] ?? ($entry['field'] ?? []));
		if (is_string($names) === true) {
			$names = [$names];
		}

		if (is_array($names) === false) {
			return [];
		}

		$out = [];
		foreach ($names as $name) {
			if (is_string($name) === true && $name !== '') {
				$out[] = $name;
			}
		}

		return $out;
	}//end applicableFields()

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
	 */
	private function appliesToUser(array $entry): bool {
		$groups = ($entry['groups'] ?? null);
		if ($groups === null) {
			return true;
		}

		if (is_string($groups) === true) {
			$groups = [$groups];
		}

		if (is_array($groups) === false || $groups === []) {
			return true;
		}

		$user = $this->userSession->getUser();
		$userGroups = [];
		if ($user !== null) {
			$userGroups = $this->groupManager->getUserGroupIds($user);
		}

		foreach ($groups as $group) {
			if (is_string($group) === false) {
				continue;
			}

			if ($group === 'public') {
				return true;
			}

			if ($group === 'authenticated' && $user !== null) {
				return true;
			}

			if (in_array($group, $userGroups, true) === true) {
				return true;
			}
		}

		return false;
	}//end appliesToUser()
}//end class
