<?php

/**
 * ToolGrantResolutionException (cli-runner-governed-mcp-and-egress).
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
 * Thrown by `ToolLoop::listAgentFunctions()` when an agent's `Agent.tools`
 * grants name at least one tool but resolve to NONE — every id was unknown to
 * the catalog (a typo, a renamed or unregistered tool, or an id copied from a
 * surface exposing a different id space).
 *
 * Why an exception rather than a log line and an empty list: an empty function
 * list is also what a legitimately tool-less agent produces, so the two are
 * indistinguishable downstream. The run then continues text-only — the model
 * simply answers "I have no such tool" — and every layer reports success, which
 * is precisely the silent degradation the governed-CLI transport exists to
 * prevent. A grant set that resolves to zero is never a valid state (an agent
 * meaning "no tools" says so with `ToolGrantResolver::NO_TOOLS_SENTINEL`, and an
 * empty grant list means "all, default-denied"), so it is raised where both
 * facts — that grants WERE configured, and that they produced nothing — are
 * still in scope. A distinct exception type lets controllers return a stable,
 * actionable error naming the unresolved ids instead of matching message text.
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
 * @spec openspec/specs/governed-cli-mcp-transport/spec.md#requirement-a-turn-that-cannot-be-governed-fails-loudly-and-is-never-silently-tool-less
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Capability;

use RuntimeException;

/**
 * Signals that an agent's tool grants were configured but matched nothing.
 *
 * @spec openspec/specs/governed-cli-mcp-transport/spec.md#requirement-a-turn-that-cannot-be-governed-fails-loudly-and-is-never-silently-tool-less
 */
class ToolGrantResolutionException extends RuntimeException {

	/**
	 * The grant entries that resolved to nothing.
	 *
	 * @var array<int, string>
	 */
	private array $grants;

	/**
	 * Constructor.
	 *
	 * @param array<int, string> $grants The grant entries that produced no tools.
	 */
	public function __construct(array $grants) {
		$this->grants = array_values($grants);

		// The ids are agent CONFIGURATION, never user content or a secret, so
		// naming them is what makes the error actionable rather than a puzzle.
		parent::__construct(
			message: sprintf(
				'This agent\'s tool grants resolve to no tools: %s. Check the ids against the tool '
				. 'catalog — an agent with no tools on purpose should be granted "%s" instead.',
				implode(', ', $this->grants),
				ToolGrantResolver::NO_TOOLS_SENTINEL
			),
			code: 422
		);

	}//end __construct()

	/**
	 * The unresolved grant entries, for structured logging and API bodies.
	 *
	 * @return array<int, string> The grants that matched no tool.
	 *
	 * @spec exclude Trivial exception-payload accessor; no behavioural spec.
	 */
	public function getGrants(): array {
		return $this->grants;
	}//end getGrants()
}//end class
