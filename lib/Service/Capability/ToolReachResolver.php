<?php

/**
 * ToolReachResolver.
 *
 * RELOCATED FROM HERMIQ (ADR-099 §5), as a relocation with its tests and NOT a
 * rewrite. The capability axis — may agent X use tool T — is not agent-specific
 * and resolves against `ToolRegistryFacade`, which already lives here. Its codec
 * carries a measured scar (35 of 87 tools parsed wrong) and ADR-095's
 * persistence constraint, and a rewrite-while-moving would reopen both, so
 * nothing below this line changed except the namespace.
 *
 * 🔴 NAMED `Capability`, NEVER `Grant`. `Service\Delegation` answers a different
 * question — may principal P act as user B — and once both live in one codebase
 * the conflation risk rises rather than falls. A user approving a tool must not
 * be able to widen whose identity the agent wears.
 *
 * Resolves how far a tool's effects can travel — its REACH — as an axis
 * orthogonal to the CRUD `scope` a descriptor already declares.
 *
 * 🔴 Why a second axis rather than more CRUD verbs. CRUD describes what a verb
 * does to data; it says nothing about whether the effect can be taken back or
 * who can see it, which is the property that actually matters when the caller is
 * a language model. `forgetMemory` is `delete` yet reversible and private to the
 * agent; `sendMail` is `create` yet irreversible and visible to a third party.
 * The most dangerous tool in the catalogue carried the least alarming scope, and
 * the real risk lived in a boolean hint — so an operator reading `scope: create`
 * on a mail tool reasonably assumed it made a draft.
 *
 * 🔴 Reach measures BLAST RADIUS OF EFFECT AND DISCLOSURE, not the provenance of
 * bytes read. Reading another user's directory card changes nothing and tells
 * nobody, so `searchContacts` is `user`, not `instance`. Without that line every
 * OpenRegister read classifies as `instance`, which would strip the read
 * catalogue from every agent with an empty `Agent.tools` — a fleet outage
 * dressed as a security fix. Resist "but it touches their data": the question is
 * what the CALL changes and who learns of it.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Capability
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
 * @spec openspec/specs/agent-capability-reach/spec.md#requirement-an-undeclared-or-unrecognised-reach-resolves-to-external
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Capability;

/**
 * Resolves and compares a tool's reach.
 *
 * @psalm-api
 *
 * @spec openspec/specs/agent-capability-reach/spec.md#requirement-an-undeclared-or-unrecognised-reach-resolves-to-external
 */
class ToolReachResolver {

	/**
	 * The agent's own state — memory, its own tool catalogue. Invisible to
	 * anyone else and undoable by the agent itself.
	 *
	 * @var string
	 */
	public const REACH_SELF = 'self';

	/**
	 * Data the ACTING USER could already reach themselves. The agent is acting
	 * as them, so nothing crosses a boundary the user does not already stand on.
	 *
	 * @var string
	 */
	public const REACH_USER = 'user';

	/**
	 * Other users on this Nextcloud can observe or be affected. Still inside the
	 * instance, still administrable, still auditable here.
	 *
	 * @var string
	 */
	public const REACH_INSTANCE = 'instance';

	/**
	 * Leaves the building. Outbound mail, calendar invitations to third parties,
	 * public share links, webhooks, fetching a caller-supplied URL. Nothing
	 * downstream of this is recallable by Nextcloud.
	 *
	 * @var string
	 */
	public const REACH_EXTERNAL = 'external';

	/**
	 * The total order, least to most far-reaching. Index IS the rank.
	 *
	 * @var array<int, string>
	 */
	public const ORDER = [
		self::REACH_SELF,
		self::REACH_USER,
		self::REACH_INSTANCE,
		self::REACH_EXTERNAL,
	];

	/**
	 * Descriptor key carrying a declared reach.
	 *
	 * @var string
	 */
	public const REACH_KEY = 'reach';

	/**
	 * Read verbs on a `{app}.{schema}.{verb}` derived tool id.
	 *
	 * Deliberately narrow: an id whose verb is not in this set or in
	 * ToolGrantResolver::WRITE_VERBS is UNKNOWN, and unknown fails closed.
	 *
	 * @var array<int, string>
	 */
	public const READ_VERBS = ['search', 'get'];

	/**
	 * Resolve a tool's reach.
	 *
	 * Order of resolution: a valid declared value wins; otherwise a derived
	 * `{app}.{schema}.{verb}` id is inferred from its verb; otherwise EXTERNAL.
	 *
	 * 🔴 The fallback is EXTERNAL and never `self`. A descriptor that forgot to
	 * declare a reach is exactly the case that must not slip through, and a
	 * permissive default would mean the tools nobody thought carefully about are
	 * the ones that run unsupervised. Reach is also never derived from `scope`:
	 * that inference is precisely the conflation this class exists to undo — a
	 * `read` scope would have made `webFetch` look harmless.
	 *
	 * @param string $toolId The tool id.
	 * @param array|null $descriptor The tool descriptor, when one is known.
	 *
	 * @return string One of the ORDER constants.
	 *
	 * @spec openspec/specs/agent-capability-reach/spec.md#requirement-an-undeclared-or-unrecognised-reach-resolves-to-external
	 */
	public static function resolve(string $toolId, ?array $descriptor = null): string {
		$declared = ($descriptor[self::REACH_KEY] ?? null);
		if (is_string($declared) === true && in_array($declared, self::ORDER, true) === true) {
			return $declared;
		}

		return self::inferFromId(toolId: $toolId);
	}//end resolve()

	/**
	 * Infer a reach from a derived `{app}.{schema}.{verb}` id.
	 *
	 * Only the three-segment derived shape is inferable. A two-segment curated
	 * id (`hermiq.sendMail`) carries no verb to read, so it MUST declare its
	 * reach — and fails closed when it does not.
	 *
	 * @param string $toolId The tool id.
	 *
	 * @return string One of the ORDER constants.
	 *
	 * @spec openspec/specs/agent-capability-reach/spec.md#requirement-an-undeclared-or-unrecognised-reach-resolves-to-external
	 */
	private static function inferFromId(string $toolId): string {
		$segments = explode('.', $toolId);
		if (count($segments) !== 3) {
			return self::REACH_EXTERNAL;
		}

		$verb = end($segments);

		if (in_array($verb, self::READ_VERBS, true) === true) {
			// Reading an object the caller may already read: no effect, no
			// disclosure to anyone else.
			return self::REACH_USER;
		}

		if (in_array($verb, ToolGrantResolver::WRITE_VERBS, true) === true) {
			// Writing an OpenRegister object other users can see.
			return self::REACH_INSTANCE;
		}

		return self::REACH_EXTERNAL;
	}//end inferFromId()

	/**
	 * The rank of a reach in the total order, or -1 when it is not one.
	 *
	 * @param string $reach A reach value.
	 *
	 * @return int The rank, or -1.
	 *
	 * @spec openspec/specs/agent-capability-reach/spec.md#requirement-every-tool-descriptor-declares-a-reach-on-a-closed-ordered-vocabulary
	 */
	public static function rank(string $reach): int {
		$rank = array_search($reach, self::ORDER, true);
		if ($rank === false) {
			return -1;
		}

		return (int)$rank;
	}//end rank()

	/**
	 * Whether one reach is at least as far-reaching as another.
	 *
	 * An unrecognised value is never "at least" anything — callers asking
	 * "is this >= instance" get false for garbage, and the fail-closed decision
	 * belongs to `resolve()`, which never emits garbage in the first place.
	 *
	 * @param string $reach The reach to test.
	 * @param string $threshold The threshold to compare against.
	 *
	 * @return bool True when $reach >= $threshold in the total order.
	 *
	 * @spec openspec/specs/agent-capability-reach/spec.md#requirement-every-tool-descriptor-declares-a-reach-on-a-closed-ordered-vocabulary
	 */
	public static function atLeast(string $reach, string $threshold): bool {
		$left = self::rank(reach: $reach);
		$right = self::rank(reach: $threshold);

		if ($left === -1 || $right === -1) {
			return false;
		}

		return ($left >= $right);
	}//end atLeast()

	/**
	 * The more far-reaching of two values — used when one capability composes
	 * another, so a delegation cannot launder reach.
	 *
	 * @param string $left A reach value.
	 * @param string $right A reach value.
	 *
	 * @return string The greater of the two.
	 *
	 * @spec openspec/specs/agent-capability-reach/spec.md#requirement-a-delegation-cannot-launder-reach
	 */
	public static function max(string $left, string $right): string {
		$leftRank = self::rank(reach: $left);
		$rightRank = self::rank(reach: $right);

		// An unrecognised operand fails closed rather than losing the comparison.
		if ($leftRank === -1 || $rightRank === -1) {
			return self::REACH_EXTERNAL;
		}

		if ($leftRank >= $rightRank) {
			return $left;
		}

		return $right;
	}//end max()
}//end class
