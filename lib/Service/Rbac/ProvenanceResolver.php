<?php

/**
 * Which rule decided this, and where it was written.
 *
 * A security officer asking "why can this person update this dossier" got a
 * yes. That is an answer to a different question. The resolver already walks
 * the register default, the schema rule, the role, the per-object grant and the
 * ancestor chain, and it already knows which step answered. It simply never said
 * so, and the cost of saying it is a field on a decision that has already been
 * taken (ADR-009), not a second pass.
 *
 * WHAT AN ANSWER CARRIES:
 *
 *   granted  whether the caller may do it
 *   source   the level that decided: object, schema, register, role,
 *            deny, default-open, or none
 *   rule     the entry as written, so an administrator can find it
 *   principal which of the caller's principals the entry named
 *   role     the role name, when a role is what carried the grant
 *   deny     the deny that removed the verb, when one did
 *
 * THE ABSENCES MATTER MOST. A verb a broader rule would have granted and a deny
 * removed is reported WITH that deny. An absence with no reason is the worst
 * answer this layer can give: the case worker sees nothing, the administrator
 * sees rules that look correct, and the only way to connect them is to read the
 * cascade by hand.
 *
 * AND WHAT IS ABOUT TO CHANGE. The deny ships staged (D15), so a staged deny is
 * reported beside the grant that still stands, as `stagedDeny`. The same field
 * that answers "why may this person act" therefore answers "and what stops them
 * the day this is turned on", which is the report an administrator needs before
 * they set the switch and not after.
 *
 * PURE ON PURPOSE. This class reads blocks and returns a record. It resolves no
 * session, loads no register and asks no mapper, so the scopes endpoint, the
 * object read and a test can all hand it the same three blocks and get the same
 * answer. Every caller resolves the cascade its own way already; a second
 * resolution here would be a fourth reading of the same grammar.
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
 * Names the rule behind a granted action, and the deny behind an absent one.
 *
 * @spec openspec/changes/permission-provenance-and-deny/specs/rbac-scopes/spec.md
 */
class ProvenanceResolver {

	/**
	 * The grant was written on the object's own `_authorization`.
	 *
	 * @var string
	 */
	public const SOURCE_OBJECT = 'object';

	/**
	 * The grant was written on the schema.
	 *
	 * @var string
	 */
	public const SOURCE_SCHEMA = 'schema';

	/**
	 * The grant was written on the register, as the default for its schemas.
	 *
	 * @var string
	 */
	public const SOURCE_REGISTER = 'register';

	/**
	 * The grant came from a named role the caller holds.
	 *
	 * @var string
	 */
	public const SOURCE_ROLE = 'role';

	/**
	 * A deny removed the verb.
	 *
	 * @var string
	 */
	public const SOURCE_DENY = 'deny';

	/**
	 * No block anywhere in the cascade, so nothing narrowed the verb.
	 *
	 * @var string
	 */
	public const SOURCE_DEFAULT_OPEN = 'default-open';

	/**
	 * Blocks exist and none of them names this caller.
	 *
	 * @var string
	 */
	public const SOURCE_NONE = 'none';

	/**
	 * The key holding role assignments in a block.
	 *
	 * @var string
	 */
	private const ROLES_KEY = 'roles';

	/**
	 * Constructor.
	 *
	 * @param DenyResolver $denyResolver The one reader of the deny grammar, whose
	 *                                   entry matcher is shared because grants and
	 *                                   denies use the same grammar.
	 */
	public function __construct(
		private readonly DenyResolver $denyResolver = new DenyResolver(),
	) {
	}//end __construct()

	/**
	 * The provenance of one action for one caller.
	 *
	 * The levels are read most specific first, because that is the order the
	 * cascade resolves in and an answer that named a broader rule than the one
	 * that actually decided would send an administrator to the wrong screen.
	 *
	 * @param string             $action                 The verb.
	 * @param array<int, string> $principals             The caller's principal names.
	 * @param array|null         $objectAuthorization    The object's own block.
	 * @param array|null         $schemaAuthorization    The schema's block.
	 * @param array|null         $registerAuthorization  The register's block.
	 * @param mixed              $roleDefinitions        The register's role definitions.
	 * @param array|null         $denial                 The denial the resolver found, or null.
	 * @param bool               $denyEnforced           Whether a denial changes the answer yet.
	 *
	 * @return array<string, mixed> The provenance record.
	 *
	 * @spec openspec/changes/permission-provenance-and-deny/specs/rbac-scopes/spec.md
	 */
	public function forAction(
		string $action,
		array $principals,
		?array $objectAuthorization = null,
		?array $schemaAuthorization = null,
		?array $registerAuthorization = null,
		mixed $roleDefinitions = null,
		?array $denial = null,
		bool $denyEnforced = true,
	): array {
		$grant = $this->grantFor(
			action: $action,
			principals: $principals,
			levels: [
				self::SOURCE_OBJECT => $objectAuthorization,
				self::SOURCE_SCHEMA => $schemaAuthorization,
				self::SOURCE_REGISTER => $registerAuthorization,
			],
			roleDefinitions: $roleDefinitions
		);

		// An enforced denial is the answer, whatever the grant said. Reporting
		// the grant as the source with a deny attached would describe the rule
		// that LOST, and an administrator reading it would go and edit that one.
		if ($denial !== null && $denyEnforced === true) {
			// The rule the deny beat, named rather than dropped: two rules in
			// tension is what an administrator has to see to resolve it, and a
			// deny reported alone reads as "nobody ever granted you this".
			$beatenBy = null;
			if ($grant['granted'] === true) {
				$beatenBy = $grant['source'];
			}

			return [
				'action' => $action,
				'granted' => false,
				'source' => self::SOURCE_DENY,
				'rule' => ($denial['rule'] ?? null),
				'principal' => ($denial['principal'] ?? null),
				'role' => null,
				'deny' => $denial,
				'stagedDeny' => null,
				'wouldHaveBeenGrantedBy' => $beatenBy,
			];
		}

		// A staged denial rides beside the grant that still stands. This is the
		// dry run made readable: the verb is there today and this is the rule
		// that takes it away the day the switch moves (D15).
		$grant['deny'] = null;
		$grant['stagedDeny'] = $denial;
		$grant['wouldHaveBeenGrantedBy'] = null;

		return $grant;
	}//end forAction()

	/**
	 * The provenance of every action in one vocabulary.
	 *
	 * @param array<int, string>              $actions    The verbs to report on.
	 * @param array<int, string>              $principals The caller's principal names.
	 * @param array<string, mixed>            $blocks     Keys: object, schema, register, roleDefinitions.
	 * @param array<string, array|null>       $denials    The denial per action, where there is one.
	 * @param bool                            $enforced   Whether a denial changes an answer yet.
	 *
	 * @return array<string, array<string, mixed>> The provenance, keyed by action.
	 *
	 * @spec openspec/changes/permission-provenance-and-deny/specs/rbac-scopes/spec.md
	 */
	public function forActions(
		array $actions,
		array $principals,
		array $blocks = [],
		array $denials = [],
		bool $enforced = true,
	): array {
		$provenance = [];
		foreach ($actions as $action) {
			$provenance[$action] = $this->forAction(
				action: $action,
				principals: $principals,
				objectAuthorization: ($blocks['object'] ?? null),
				schemaAuthorization: ($blocks['schema'] ?? null),
				registerAuthorization: ($blocks['register'] ?? null),
				roleDefinitions: ($blocks['roleDefinitions'] ?? null),
				denial: ($denials[$action] ?? null),
				denyEnforced: $enforced
			);
		}

		return $provenance;
	}//end forActions()

	/**
	 * The first level whose rules name this caller for this action.
	 *
	 * @param string                    $action          The verb.
	 * @param array<int, string>        $principals      The caller's principal names.
	 * @param array<string, array|null> $levels          Level name to block, most specific first.
	 * @param mixed                     $roleDefinitions The register's role definitions.
	 *
	 * @return array<string, mixed> The grant half of the record.
	 */
	private function grantFor(string $action, array $principals, array $levels, mixed $roleDefinitions): array {
		$anyBlock = false;

		foreach ($levels as $source => $block) {
			if (is_array($block) === false || $block === []) {
				continue;
			}

			$anyBlock = true;

			$direct = $this->directGrantIn(block: $block, action: $action, principals: $principals);
			if ($direct !== null) {
				return [
					'action' => $action,
					'granted' => true,
					'source' => $source,
					'rule' => $direct['rule'],
					'principal' => $direct['principal'],
					'role' => null,
				];
			}

			$viaRole = $this->roleGrantIn(
				block: $block,
				action: $action,
				principals: $principals,
				roleDefinitions: $roleDefinitions
			);
			if ($viaRole !== null) {
				return [
					'action' => $action,
					'granted' => true,
					'source' => self::SOURCE_ROLE,
					'rule' => $viaRole['rule'],
					'principal' => $viaRole['principal'],
					'role' => $viaRole['role'],
					'declaredAt' => $source,
				];
			}
		}//end foreach

		// No block anywhere is not the same as a block that refuses. The first
		// is a schema nobody has configured, and the difference is the whole of
		// what an administrator needs to know.
		$source = self::SOURCE_NONE;
		if ($anyBlock === false) {
			$source = self::SOURCE_DEFAULT_OPEN;
		}

		return [
			'action' => $action,
			'granted' => ($anyBlock === false),
			'source' => $source,
			'rule' => null,
			'principal' => null,
			'role' => null,
		];
	}//end grantFor()

	/**
	 * A rule written directly under the action key that names this caller.
	 *
	 * @param array              $block      The block at one level.
	 * @param string             $action     The verb.
	 * @param array<int, string> $principals The caller's principal names.
	 *
	 * @return array{rule: mixed, principal: string}|null The rule, or null.
	 */
	private function directGrantIn(array $block, string $action, array $principals): ?array {
		$entries = ($block[$action] ?? null);
		if (is_array($entries) === false) {
			return null;
		}

		foreach ($entries as $entry) {
			$named = $this->denyResolver->entryNames(entry: $entry, principals: $principals);
			if ($named !== null) {
				return ['rule' => $entry, 'principal' => $named];
			}
		}

		return null;
	}//end directGrantIn()

	/**
	 * A grant that reaches this caller through a named role.
	 *
	 * The role is what an administrator edits, so the role is what the answer
	 * names. Reporting the group behind it would be true and useless: nobody
	 * granted that group the verb, the role did.
	 *
	 * @param array              $block           The block at one level.
	 * @param string             $action          The verb.
	 * @param array<int, string> $principals      The caller's principal names.
	 * @param mixed              $roleDefinitions The register's role definitions.
	 *
	 * @return array{rule: mixed, principal: string, role: string}|null The grant, or null.
	 */
	private function roleGrantIn(array $block, string $action, array $principals, mixed $roleDefinitions): ?array {
		$assignments = ($block[self::ROLES_KEY] ?? null);
		if (is_array($assignments) === false) {
			return null;
		}

		$actionsByRole = $this->actionsByRole(roleDefinitions: $roleDefinitions);

		foreach ($assignments as $roleName => $holders) {
			if (is_string($roleName) === false || is_array($holders) === false) {
				continue;
			}

			$actions = ($actionsByRole[$roleName] ?? null);
			if ($actions === null || in_array(needle: $action, haystack: $actions, strict: true) === false) {
				continue;
			}

			foreach ($holders as $holder) {
				$named = $this->denyResolver->entryNames(entry: $holder, principals: $principals);
				if ($named !== null) {
					return ['rule' => $holders, 'principal' => $named, 'role' => $roleName];
				}
			}
		}//end foreach

		return null;
	}//end roleGrantIn()

	/**
	 * Flatten role definitions into a name to actions map.
	 *
	 * `extends` is NOT followed here. The hierarchy is resolved in
	 * {@see \OCA\OpenRegister\Service\Object\PermissionHandler::expandRoles()},
	 * and a second walk of it here could disagree with the first, which would
	 * make the provenance name a role that did not actually decide. Callers that
	 * need inherited actions pass the already-resolved definitions.
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

			// Two shapes reach this: the list of definitions a register stores,
			// each with its own `name`, and the flat `name => actions` map the
			// hierarchy resolver produces. Both are read rather than one being
			// declared canonical, because a caller holding the resolved map is
			// exactly the caller whose answer is most accurate.
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
}//end class
