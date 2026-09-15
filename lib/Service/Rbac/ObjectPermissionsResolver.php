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
 * @spec openspec/changes/permission-provenance-and-deny/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;


/**
 * Reads an object's access set out of the rules, and its history out of the trail.
 *
 * @spec openspec/changes/permission-provenance-and-deny/specs/rbac-scopes/spec.md
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
	 * @spec openspec/changes/permission-provenance-and-deny/specs/rbac-scopes/spec.md
	 */
	public function holders(array $blocks, mixed $roleDefinitions = null): array {
		$holders = [];
		$denied = [];

		foreach (self::LEVELS as $level) {
			$block = ($blocks[$level] ?? null);
			if (is_array($block) === false || $block === []) {
				continue;
			}

			foreach ($this->grantsIn(block: $block, level: $level, roleDefinitions: $roleDefinitions) as $grant) {
				$holders = $this->fold(into: $holders, entry: $grant);
			}

			$deny = $this->denyResolver->denyBlock(authorization: $block);
			foreach ($this->grantsIn(block: $deny, level: $level, roleDefinitions: $roleDefinitions) as $rule) {
				$denied[] = $rule;
			}
		}//end foreach

		return ['holders' => array_values($holders), 'denied' => $denied];
	}//end holders()

	/**
	 * Every grant one block writes, as one entry per principal and verb.
	 *
	 * @param array|null $block           The block at one level.
	 * @param string     $level           Where the block is written.
	 * @param mixed      $roleDefinitions The register's role definitions.
	 *
	 * @return array<int, array<string, mixed>> The rules.
	 */
	private function grantsIn(?array $block, string $level, mixed $roleDefinitions): array {
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
					$this->roleGrantsIn(assignments: $entries, level: $level, roleDefinitions: $roleDefinitions)
				);
				continue;
			}

			if (in_array($key, $settings, true) === true) {
				continue;
			}

			$rules = array_merge($rules, $this->verbGrantsIn(entries: $entries, action: $key, level: $level));
		}//end foreach

		return $rules;
	}//end grantsIn()

	/**
	 * Every entry written directly under one verb, as a rule apiece.
	 *
	 * @param array  $entries The entries as written.
	 * @param string $action  The verb they grant.
	 * @param string $level   Where the block is written.
	 *
	 * @return array<int, array<string, mixed>> The rules.
	 */
	private function verbGrantsIn(array $entries, string $action, string $level): array {
		$rules = [];
		foreach ($entries as $entry) {
			$principal = $this->principalOf(rule: $entry);
			if ($principal === null) {
				continue;
			}

			$rules[] = [
				'principal' => $principal,
				'action' => $action,
				'level' => $level,
				'role' => null,
				'rule' => $entry,
				'conditional' => (is_array($entry) === true && isset($entry['match']) === true),
				'declared' => $this->catalogue->isGrantable($action),
			];
		}

		return $rules;
	}//end verbGrantsIn()

	/**
	 * Every grant a block's role assignments write.
	 *
	 * The role is named as well as the verbs it carries, because the role is
	 * what an administrator edits. Reporting only the group would be true and
	 * useless: nobody granted that group the verb, the role did.
	 *
	 * @param array  $assignments     The block's `roles` map.
	 * @param string $level           Where the block is written.
	 * @param mixed  $roleDefinitions The register's role definitions.
	 *
	 * @return array<int, array<string, mixed>> The rules.
	 */
	private function roleGrantsIn(array $assignments, string $level, mixed $roleDefinitions): array {
		$actionsByRole = $this->actionsByRole(roleDefinitions: $roleDefinitions);

		$rules = [];
		foreach ($assignments as $roleName => $holders) {
			if (is_string($roleName) === false || is_array($holders) === false) {
				continue;
			}

			foreach (($actionsByRole[$roleName] ?? []) as $action) {
				foreach ($holders as $holder) {
					$principal = $this->principalOf(rule: $holder);
					if ($principal === null) {
						continue;
					}

					$rules[] = [
						'principal' => $principal,
						'action' => $action,
						'level' => $level,
						'role' => $roleName,
						'rule' => $holders,
						'conditional' => false,
						'declared' => $this->catalogue->isGrantable($action),
					];
				}
			}
		}//end foreach

		return $rules;
	}//end roleGrantsIn()

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
	 * Flatten role definitions into a name to actions map.
	 *
	 * `extends` is NOT followed here, for the same reason it is not followed in
	 * {@see ProvenanceResolver}: the hierarchy is resolved in PermissionHandler,
	 * and a second walk of it could disagree with the one that decides. A caller
	 * holding the resolved map passes that instead.
	 *
	 * @param mixed $roleDefinitions The register's role definitions.
	 *
	 * @return array<string, array<int, string>> Role name to its actions.
	 */
	private function actionsByRole(mixed $roleDefinitions): array {
		if (is_array($roleDefinitions) === false) {
			return [];
		}

		$map = [];
		foreach ($roleDefinitions as $key => $definition) {
			if (is_array($definition) === false) {
				continue;
			}

			if (isset($definition['name']) === true && is_array(($definition['actions'] ?? null)) === true) {
				$map[(string)$definition['name']] = array_values(array_filter($definition['actions'], 'is_string'));
				continue;
			}

			if (is_string($key) === true) {
				$map[$key] = array_values(array_filter($definition, 'is_string'));
			}
		}

		return $map;
	}//end actionsByRole()

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
