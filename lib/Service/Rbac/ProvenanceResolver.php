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
 * @spec openspec/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

/**
 * Names the rule behind a granted action, and the deny behind an absent one.
 *
 * @spec openspec/specs/rbac-scopes/spec.md
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
	 * @param DenyResolverInterface $denyResolver The one reader of the deny grammar, whose
	 *                                   entry matcher is shared because grants and
	 *                                   denies use the same grammar.
	 * @param array<int, string>    $principals   The caller's principal names; see forPrincipals().
	 */
	public function __construct(
		private readonly DenyResolverInterface $denyResolver = new DenyResolver(),
		private readonly array $principals = [],
	) {
	}//end __construct()

	/**
	 * The same resolver, reporting for a caller with these principals.
	 *
	 * @param array<int, string> $principals The caller's principal names.
	 *
	 * @return self The resolver for that caller.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function forPrincipals(array $principals): self {
		return new self(denyResolver: $this->denyResolver, principals: $principals);
	}//end forPrincipals()

	/**
	 * The provenance of every action in one vocabulary.
	 *
	 * The levels are read most specific first, because that is the order the
	 * cascade resolves in and an answer that named a broader rule than the one
	 * that actually decided would send an administrator to the wrong screen.
	 *
	 * @param array<int, string> $actions The verbs to report on, for the caller of forPrincipals().
	 * @param ProvenanceOptions  $options The cascade blocks, the denial per action and whether denials decide yet.
	 *
	 * @return array<string, array<string, mixed>> The provenance, keyed by action.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function forActions(array $actions, ProvenanceOptions $options = new ProvenanceOptions()): array {
		$provenance = [];
		foreach ($actions as $action) {
			$grant = $this->grantFor(action: $action, blocks: $options->blocks);
			if ($options->enforced === true) {
				$provenance[$action] = $this->withDenial(grant: $grant, denial: ($options->denials[$action] ?? null));
				continue;
			}

			$provenance[$action] = $this->withStagedDenial(grant: $grant, denial: ($options->denials[$action] ?? null));
		}

		return $provenance;
	}//end forActions()

	/**
	 * The cascade's levels, most specific first.
	 *
	 * Typed, so a block that is neither an array nor null is refused here
	 * rather than read as "not configured".
	 *
	 * @param array<string, mixed> $blocks Keys: object, schema, register (each an array or null).
	 *
	 * @return array<string, array|null> Level name to block.
	 */
	private function levelsOf(array $blocks): array {
		return [
			self::SOURCE_OBJECT => $this->typedBlock(block: ($blocks['object'] ?? null)),
			self::SOURCE_SCHEMA => $this->typedBlock(block: ($blocks['schema'] ?? null)),
			self::SOURCE_REGISTER => $this->typedBlock(block: ($blocks['register'] ?? null)),
		];
	}//end levelsOf()

	/**
	 * One level's block, refused with a TypeError when it is neither an array nor null.
	 *
	 * @param array|null $block The block.
	 *
	 * @return array|null The same block.
	 */
	private function typedBlock(?array $block): ?array {
		return $block;
	}//end typedBlock()

	/**
	 * Attach an enforced denial to a grant record: the denial is the answer.
	 *
	 * @param array<string, mixed> $grant  The grant half of the record.
	 * @param array|null           $denial The denial the resolver found, or null.
	 *
	 * @return array<string, mixed> The provenance record.
	 */
	private function withDenial(array $grant, ?array $denial): array {
		// An enforced denial is the answer, whatever the grant said. Reporting
		// the grant as the source with a deny attached would describe the rule
		// that LOST, and an administrator reading it would go and edit that one.
		if ($denial !== null) {
			return $this->deniedRecord(grant: $grant, denial: $denial);
		}

		return $this->withStagedDenial(grant: $grant, denial: null);
	}//end withDenial()

	/**
	 * Attach a denial that does not change the answer yet: staged beside the grant.
	 *
	 * @param array<string, mixed> $grant  The grant half of the record.
	 * @param array|null           $denial The denial the resolver found, or null.
	 *
	 * @return array<string, mixed> The provenance record.
	 */
	private function withStagedDenial(array $grant, ?array $denial): array {
		// A staged denial rides beside the grant that still stands. This is the
		// dry run made readable: the verb is there today and this is the rule
		// that takes it away the day the switch moves (D15).
		$grant['deny'] = null;
		$grant['stagedDeny'] = $denial;
		$grant['wouldHaveBeenGrantedBy'] = null;

		return $grant;
	}//end withStagedDenial()

	/**
	 * The record of an action an enforced denial removed.
	 *
	 * @param array<string, mixed> $grant  The grant half the deny beat.
	 * @param array                $denial The denial.
	 *
	 * @return array<string, mixed> The provenance record.
	 */
	private function deniedRecord(array $grant, array $denial): array {
		// The rule the deny beat, named rather than dropped: two rules in
		// tension is what an administrator has to see to resolve it, and a
		// deny reported alone reads as "nobody ever granted you this".
		$beatenBy = null;
		if ($grant['granted'] === true) {
			$beatenBy = $grant['source'];
		}

		return [
			'action' => $grant['action'],
			'granted' => false,
			'source' => self::SOURCE_DENY,
			'rule' => ($denial['rule'] ?? null),
			'principal' => ($denial['principal'] ?? null),
			'role' => null,
			'deny' => $denial,
			'stagedDeny' => null,
			'wouldHaveBeenGrantedBy' => $beatenBy,
		];
	}//end deniedRecord()

	/**
	 * The first level whose rules name this caller for this action.
	 *
	 * @param string               $action The verb.
	 * @param array<string, mixed> $blocks Keys: object, schema, register, roleDefinitions.
	 *
	 * @return array<string, mixed> The grant half of the record.
	 */
	private function grantFor(string $action, array $blocks): array {
		$configured = $this->configuredLevels(
			levels: $this->levelsOf(blocks: $blocks)
		);
		$carrying = $this->rolesCarrying(action: $action, roleDefinitions: ($blocks['roleDefinitions'] ?? null));

		foreach ($configured as $source => $block) {
			$direct = $this->directGrantIn(block: $block, action: $action);
			if ($direct !== null) {
				return ['action' => $action, 'granted' => true, 'source' => $source] + $direct + ['role' => null];
			}

			$viaRole = $this->roleGrantIn(block: $block, carrying: $carrying);
			if ($viaRole !== null) {
				return ['action' => $action, 'granted' => true, 'source' => self::SOURCE_ROLE] + $viaRole + ['declaredAt' => $source];
			}
		}

		return $this->ungranted(action: $action, anyBlock: ($configured !== []));
	}//end grantFor()

	/**
	 * The names of the roles whose definition carries the action.
	 *
	 * @param string $action          The action asked about.
	 * @param mixed  $roleDefinitions The register's role definitions.
	 *
	 * @return array<int, int|string> The role names, as the definitions key them.
	 */
	private function rolesCarrying(string $action, mixed $roleDefinitions): array {
		$actionsByRole = (new RoleActionMap())->of(roleDefinitions: $roleDefinitions);

		return array_keys(
			array_filter(
				$actionsByRole,
				static fn (array $actions): bool => in_array(needle: $action, haystack: $actions, strict: true)
			)
		);
	}//end rolesCarrying()

	/**
	 * The levels that carry a non-empty block, in order.
	 *
	 * @param array<string, array|null> $levels Level name to block.
	 *
	 * @return array<string, array> The configured levels.
	 */
	private function configuredLevels(array $levels): array {
		$configured = [];
		foreach ($levels as $source => $block) {
			if (is_array($block) === true && $block !== []) {
				$configured[$source] = $block;
			}
		}

		return $configured;
	}//end configuredLevels()

	/**
	 * The grant half of an action no level grants.
	 *
	 * @param string $action   The verb.
	 * @param bool   $anyBlock Whether any level carried a block.
	 *
	 * @return array<string, mixed> The grant half of the record.
	 */
	private function ungranted(string $action, bool $anyBlock): array {
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
	}//end ungranted()

	/**
	 * A rule written directly under the action key that names this caller.
	 *
	 * @param array  $block  The block at one level.
	 * @param string $action The verb.
	 *
	 * @return array{rule: mixed, principal: string}|null The rule, or null.
	 */
	private function directGrantIn(array $block, string $action): ?array {
		$entries = ($block[$action] ?? null);
		if (is_array($entries) === false) {
			return null;
		}

		foreach ($entries as $entry) {
			$named = $this->denyResolver->entryNames(entry: $entry, principals: $this->principals);
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
	 * @param array                  $block    The block at one level.
	 * @param array<int, int|string> $carrying The roles whose definition carries the action.
	 *
	 * @return array{rule: array, principal: string, role: string}|null The grant, or null.
	 */
	private function roleGrantIn(array $block, array $carrying): ?array {
		$assignments = ($block[self::ROLES_KEY] ?? null);
		if (is_array($assignments) === false) {
			return null;
		}

		foreach ($assignments as $roleName => $holders) {
			if (is_array($holders) === false || $this->carries(roleName: $roleName, carrying: $carrying) === false) {
				continue;
			}

			$named = $this->firstNamed(holders: $holders);
			if ($named !== null) {
				return ['rule' => $holders, 'principal' => $named, 'role' => $roleName];
			}
		}

		return null;
	}//end roleGrantIn()

	/**
	 * Whether a role assignment's key names a role that carries the action.
	 *
	 * @param int|string             $roleName The assignment's key.
	 * @param array<int, int|string> $carrying The roles whose definition carries the action.
	 *
	 * @return bool
	 */
	private function carries(int|string $roleName, array $carrying): bool {
		return is_string($roleName) === true && in_array(needle: $roleName, haystack: $carrying, strict: true) === true;
	}//end carries()

	/**
	 * The first of the caller's principals a holder list names.
	 *
	 * @param array $holders The holder list.
	 *
	 * @return string|null The principal, or null when no holder names one.
	 */
	private function firstNamed(array $holders): ?string {
		foreach ($holders as $holder) {
			$named = $this->denyResolver->entryNames(entry: $holder, principals: $this->principals);
			if ($named !== null) {
				return $named;
			}
		}

		return null;
	}//end firstNamed()
}//end class
