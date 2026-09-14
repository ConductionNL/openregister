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
 * THE HISTORY IS READ FROM THE AUDIT TRAIL, NOT FROM A SECOND TABLE. An object's
 * `authorization` column is versioned by the same trail that versions its data,
 * so the set at a past moment is reconstructible from what is already recorded.
 * A dedicated table would have had to be written from the day it shipped to
 * answer a question about last year, which is exactly when it cannot.
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

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\ObjectEntity;

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
	 * The audit-trail column whose changes this report reads.
	 *
	 * @var string
	 */
	private const AUTHORIZATION_KEY = 'authorization';

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
				$this->fold(into: $holders, entry: $grant);
			}

			$deny = $this->denyResolver->denyBlock(authorization: $block);
			foreach ($this->grantsIn(block: $deny, level: $level, roleDefinitions: $roleDefinitions) as $rule) {
				$denied[] = $rule;
			}
		}//end foreach

		return ['holders' => array_values($holders), 'denied' => $denied];
	}//end holders()

	/**
	 * The history of one object's access set.
	 *
	 * Every trail entry whose change set touches the authorization column is one
	 * moment the access set moved, and the entry names who moved it. An entry
	 * that changed only the object's data is not one, and is dropped rather than
	 * reported as a change nobody made.
	 *
	 * @param array<int, AuditTrail> $entries The object's audit trail, newest first.
	 * @param string|null            $at      An ISO-8601 moment to report the set AS OF, or null for every change.
	 *
	 * @return array{changes: array<int, array<string, mixed>>, asOf: array<string, mixed>|null}
	 *         The changes, and the set as it stood at the named moment.
	 *
	 * @spec openspec/changes/permission-provenance-and-deny/specs/rbac-scopes/spec.md
	 */
	public function history(array $entries, ?string $at = null): array {
		$moment = $this->momentOf(value: $at);

		$changes = [];
		$asOf = null;

		foreach ($entries as $entry) {
			$change = $this->authorizationChangeIn(entry: $entry);
			if ($change === null) {
				continue;
			}

			$changes[] = $change;

			// The entries arrive newest first, so the first change at or before
			// the moment asked about is the one that produced the set standing
			// at that moment: everything after it had not happened yet.
			if ($moment !== null && $asOf === null && $change['at'] !== null) {
				$changedAt = strtotime($change['at']);
				if ($changedAt !== false && $changedAt <= $moment) {
					$asOf = [
						'at' => $change['at'],
						'holders' => $this->holders(blocks: ['object' => $change['to']])['holders'],
						'setBy' => $change['by'],
						'changedAfterwardsBy' => $this->laterChangeIn(changes: $changes),
					];
				}
			}
		}//end foreach

		return ['changes' => $changes, 'asOf' => $asOf];
	}//end history()

	/**
	 * The change that came after the one answering for a past moment.
	 *
	 * "The grant was held then, and this is the rule that removed it afterwards"
	 * is the whole of the auditor's question, and the second half is the half a
	 * point-in-time read usually leaves out.
	 *
	 * @param array<int, array<string, mixed>> $changes The changes collected so far, newest first.
	 *
	 * @return array<string, mixed>|null The later change, or null when nothing changed since.
	 */
	private function laterChangeIn(array $changes): ?array {
		if (count($changes) < 2) {
			return null;
		}

		$later = $changes[(count($changes) - 2)];

		return ['at' => $later['at'], 'by' => $later['by'], 'to' => $later['to']];
	}//end laterChangeIn()

	/**
	 * One trail entry's authorization change, or null when it carried none.
	 *
	 * @param AuditTrail $entry The trail entry.
	 *
	 * @return array<string, mixed>|null The change.
	 */
	private function authorizationChangeIn(AuditTrail $entry): ?array {
		$changed = $entry->getChanged();
		if (is_array($changed) === false || isset($changed[self::AUTHORIZATION_KEY]) === false) {
			return null;
		}

		$change = $changed[self::AUTHORIZATION_KEY];
		if (is_array($change) === false) {
			return null;
		}

		return [
			'at' => $entry->getCreated()?->format('c'),
			'by' => $entry->getUser(),
			'byName' => $entry->getUserName(),
			'action' => $entry->getAction(),
			'from' => $this->blockOf(value: ($change['old'] ?? null)),
			'to' => $this->blockOf(value: ($change['new'] ?? null)),
		];
	}//end authorizationChangeIn()

	/**
	 * One side of a change, as a block.
	 *
	 * @param mixed $value The stored value, which may be JSON text.
	 *
	 * @return array|null The block.
	 */
	private function blockOf(mixed $value): ?array {
		if (is_array($value) === true) {
			return $value;
		}

		if (is_string($value) === true && $value !== '') {
			$decoded = json_decode($value, true);
			if (is_array($decoded) === true) {
				return $decoded;
			}
		}

		return null;
	}//end blockOf()

	/**
	 * The moment a request asked about, as a timestamp.
	 *
	 * @param string|null $value The request's value.
	 *
	 * @return integer|null The timestamp, or null when nothing usable was asked.
	 */
	private function momentOf(?string $value): ?int {
		if ($value === null || trim($value) === '') {
			return null;
		}

		$moment = strtotime($value);
		if ($moment === false) {
			return null;
		}

		return $moment;
	}//end momentOf()

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

			if (in_array($key, PermissionCatalogue::CONTROL_KEYS, true) === true) {
				continue;
			}

			foreach ($entries as $entry) {
				$principal = $this->principalOf(rule: $entry);
				if ($principal === null) {
					continue;
				}

				$rules[] = [
					'principal' => $principal,
					'action' => $key,
					'level' => $level,
					'role' => null,
					'rule' => $entry,
					'conditional' => (is_array($entry) === true && isset($entry['match']) === true),
					'declared' => $this->catalogue->isGrantable($key),
				];
			}
		}//end foreach

		return $rules;
	}//end grantsIn()

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
	 * @param array<string, array<string, mixed>> $into  The map being built, keyed by principal.
	 * @param array<string, mixed>                $entry The rule.
	 *
	 * @return void
	 */
	private function fold(array &$into, array $entry): void {
		$principal = $entry['principal'];
		if (isset($into[$principal]) === false) {
			$into[$principal] = ['principal' => $principal, 'verbs' => [], 'rules' => []];
		}

		if (in_array($entry['action'], $into[$principal]['verbs'], true) === false) {
			$into[$principal]['verbs'][] = $entry['action'];
		}

		$into[$principal]['rules'][] = $entry;
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
