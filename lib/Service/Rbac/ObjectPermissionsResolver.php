<?php

/**
 * Who holds which right on one object, and how that set changed.
 *
 * Provenance reads both ways (design D-10). "What may I do here" is answered by
 * `GET /api/scopes`, one caller at a time, and it is the question a client asks.
 * "Who may do this here" is the auditor's question, and it is the one no product
 * in the corpus was asked: it names the principals, their verbs and the rule
 * behind each, rather than making somebody walk the register, the schema, the
 * roles and the object block and hold four screens in their head.
 *
 * THE RULE, NOT ONLY THE ANSWER. Every entry carries where it is written and
 * what it says, because an auditor who learns that `behandelaars` may update a
 * dossier and not WHERE that was granted has to go and find it, and the finding
 * is the expensive half.
 *
 * THE HISTORY IS A SEPARATE CLASS. "Who may open this" and "who could open it in
 * March" are two questions: this one reads the rules as they stand,
 * {@see ObjectAccessHistory} reads the trail. Keeping them apart is what lets
 * each stay short enough to check by eye.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;


/**
 * Reads an object's access set out of the rules, and its history out of the trail.
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */
class ObjectPermissionsResolver {

	/**
	 * The levels a rule can be written at, most specific first.
	 *
	 * The order is the order the cascade resolves in, so a reader of this
	 * report and a reader of the verdict walk the same path.
	 *
	 * @var array<int, string>
	 */
	public const LEVELS = ['object', 'schema', 'register'];

	/**
	 * The key holding role assignments in a block.
	 *
	 * @var string
	 */
	private const ROLES_KEY = 'roles';

	/**
	 * Constructor.
	 *
	 * @param DenyResolver        $denyResolver The one reader of the deny grammar.
	 * @param PermissionCatalogue $catalogue    The grantable set, so an undeclared verb is reported as one.
	 */
	public function __construct(
		private readonly DenyResolver $denyResolver = new DenyResolver(),
		private readonly PermissionCatalogue $catalogue = new PermissionCatalogue(),
	) {
	}//end __construct()

	/**
	 * Every principal holding a right on this object, with the rule behind it.
	 *
	 * @param array<string, array|null> $blocks          Level name to the block written there.
	 * @param mixed                     $roleDefinitions The register's role definitions.
	 *
	 * @return array{holders: array<int, array<string, mixed>>, denied: array<int, array<string, mixed>>}
	 *         The access set.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function holders(array $blocks, mixed $roleDefinitions = null): array {
		$holders = [];
		$denied = [];

		foreach (self::LEVELS as $level) {
			$block = ($blocks[$level] ?? null);
			if (is_array($block) === false || $block === []) {
				continue;
			}

			$run = new HoldersRun(level: $level, roleDefinitions: $roleDefinitions);
			foreach ($this->grantsIn(block: $block, run: $run) as $grant) {
				$holders = $this->fold(into: $holders, entry: $grant);
			}

			$deny = $this->denyResolver->denyBlock(authorization: $block);
			foreach ($this->grantsIn(block: $deny, run: $run) as $rule) {
				$denied[] = $rule;
			}
		}//end foreach

		return ['holders' => array_values($holders), 'denied' => $denied];
	}//end holders()

	/**
	 * Every grant one block writes, as one entry per principal and verb.
	 *
	 * @param array|null $block The block at one level.
	 * @param HoldersRun $run   Where the block is written and the register's role definitions.
	 *
	 * @return array<int, array<string, mixed>> The rules.
	 */
	private function grantsIn(?array $block, HoldersRun $run): array {
		if (is_array($block) === false || $block === []) {
			return [];
		}

		// The control keys minus `roles`, which is handled on its own branch
		// below. Subtracting it rather than relying on the branch order keeps
		// the two readings of the same key from contradicting each other.
		$settings = array_values(array_diff(PermissionCatalogue::CONTROL_KEYS, [self::ROLES_KEY]));

		$rules = [];
		foreach ($block as $key => $entries) {
			if (is_string($key) === false || is_array($entries) === false) {
				continue;
			}

			if ($key === self::ROLES_KEY) {
				$rules = array_merge(
					$rules,
					$this->roleGrantsIn(assignments: $entries, run: $run)
				);
				continue;
			}

			if (in_array($key, $settings, true) === true) {
				continue;
			}

			$rules = array_merge($rules, $this->verbGrantsIn(entries: $entries, origin: new RuleOrigin(level: $run->level, action: $key)));
		}//end foreach

		return $rules;
	}//end grantsIn()

	/**
	 * Every entry written directly under one verb, as a rule apiece.
	 *
	 * @param array      $entries The entries as written.
	 * @param RuleOrigin $origin  Where the block is written and the verb the entries grant.
	 *
	 * @return array<int, array<string, mixed>> The rules.
	 */
	private function verbGrantsIn(array $entries, RuleOrigin $origin): array {
		$rules = [];
		foreach ($entries as $entry) {
			$rule = $this->verbRule(entry: $entry, origin: $origin);
			if ($rule !== null) {
				$rules[] = $rule;
			}
		}

		return $rules;
	}//end verbGrantsIn()

	/**
	 * One entry under a verb as a rule, or null when it names no principal.
	 *
	 * @param mixed      $entry  The entry as written.
	 * @param RuleOrigin $origin Where the block is written and the verb the entry grants.
	 *
	 * @return array<string, mixed>|null The rule.
	 */
	private function verbRule(mixed $entry, RuleOrigin $origin): ?array {
		$principal = $this->principalOf(rule: $entry);
		if ($principal === null) {
			return null;
		}

		return [
			'principal' => $principal,
			'action' => $origin->action,
			'level' => $origin->level,
			'role' => null,
			'rule' => $entry,
			'conditional' => (is_array($entry) === true && isset($entry['match']) === true),
			'declared' => $this->catalogue->isGrantable($origin->action),
		];
	}//end verbRule()

	/**
	 * Every grant a block's role assignments write.
	 *
	 * The role is named as well as the verbs it carries, because the role is
	 * what an administrator edits. Reporting only the group would be true and
	 * useless: nobody granted that group the verb, the role did.
	 *
	 * @param array      $assignments The block's `roles` map.
	 * @param HoldersRun $run         Where the block is written and the register's role definitions.
	 *
	 * @return array<int, array<string, mixed>> The rules.
	 */
	private function roleGrantsIn(array $assignments, HoldersRun $run): array {
		$actionsByRole = (new RoleActionMap())->of(roleDefinitions: $run->roleDefinitions);

		$rules = [];
		foreach ($assignments as $roleName => $holders) {
			if (is_string($roleName) === true && is_array($holders) === true) {
				$role = ['level' => $run->level, 'role' => $roleName, 'rule' => $holders];
				$rules = [...$rules, ...$this->roleRules(role: $role, actions: ($actionsByRole[$roleName] ?? []))];
			}
		}

		return $rules;
	}//end roleGrantsIn()

	/**
	 * One rule per action of a role and principal its holders name.
	 *
	 * @param array{level: string, role: string, rule: array} $role    The level, role name and holder list.
	 * @param array<int, string>                              $actions The role's actions.
	 *
	 * @return array<int, array<string, mixed>> The rules, action by action.
	 */
	private function roleRules(array $role, array $actions): array {
		$rules = [];
		foreach ($actions as $action) {
			foreach ($role['rule'] as $holder) {
				$principal = $this->principalOf(rule: $holder);
				if ($principal !== null) {
					$rules[] = $this->roleRule(principal: $principal, role: [...$role, 'action' => $action]);
				}
			}
		}

		return $rules;
	}//end roleRules()

	/**
	 * The rule one role gives one principal for one action.
	 *
	 * @param string                                                          $principal The principal.
	 * @param array{level: string, role: string, rule: array, action: string} $role      The level, role name, holder list and the action.
	 *
	 * @return array<string, mixed> The rule.
	 */
	private function roleRule(string $principal, array $role): array {
		return [
			'principal' => $principal,
			'action' => $role['action'],
			'level' => $role['level'],
			'role' => $role['role'],
			'rule' => $role['rule'],
			'conditional' => false,
			'declared' => $this->catalogue->isGrantable($role['action']),
		];
	}//end roleRule()

	/**
	 * Fold one rule into the principal it names.
	 *
	 * Returns the map rather than taking it by reference: a by-ref parameter
	 * carries a type the caller has to keep true by hand, and the analyser was
	 * right that the caller's array had drifted from it.
	 *
	 * @param array<string, mixed> $into  The map being built, keyed by principal.
	 * @param array<string, mixed> $entry The rule.
	 *
	 * @return array<string, mixed> The map, with this rule folded in.
	 */
	private function fold(array $into, array $entry): array {
		$principal = (string)$entry['principal'];
		if (isset($into[$principal]) === false) {
			$into[$principal] = ['principal' => $principal, 'verbs' => [], 'rules' => []];
		}

		if (in_array($entry['action'], $into[$principal]['verbs'], true) === false) {
			$into[$principal]['verbs'][] = $entry['action'];
		}

		$into[$principal]['rules'][] = $entry;

		return $into;
	}//end fold()

	/**
	 * The principal one entry names.
	 *
	 * @param mixed $rule The entry, a bare name or an object naming a principal.
	 *
	 * @return string|null The principal, or null when the entry names none.
	 */
	private function principalOf(mixed $rule): ?string {
		if (is_string($rule) === true && $rule !== '') {
			return $rule;
		}

		if (is_array($rule) === false) {
			return null;
		}

		foreach (['principal', 'group', 'role', 'user'] as $key) {
			$value = ($rule[$key] ?? null);
			if (is_string($value) === true && $value !== '') {
				return $value;
			}
		}

		return null;
	}//end principalOf()
}//end class
