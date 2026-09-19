<?php

/**
 * An agent is a performer, and agents are not people.
 *
 * 🔴 IT RESOLVES TO EXACTLY ONE IDENTITY AND NEVER THROUGH A GROUP. That is the
 * whole safety property. The answer guard compares the acting uid against what
 * a reference resolves to, so if an agent reference ever expanded through group
 * membership, an agent's step would become answerable by every human in that
 * group — and, the other way round, a human's step by the agent. The two must
 * not be able to answer for each other.
 *
 * It therefore does the same existence check {@see UserPrincipalResolver} does
 * and nothing else: an agent completes its turn as a REAL identity, or it
 * resolves to nobody and the step fails loudly rather than raising a task
 * addressed to something that cannot log in.
 *
 * WHY THE IDENTITY IS A NEXTCLOUD USER AT ALL
 * -------------------------------------------
 * Because the completion verb is the ordinary one. An agent finishing a task
 * takes the same path a person does — the same guard, the same audit, the same
 * outcome vocabulary — and that only works if it acts as something the guard
 * can compare against. An agent with no account is an agent nothing can
 * attribute a decision to.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Principal
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-typed-principals/specs/flow-typed-principals/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Principal;

use OCP\IUserManager;

/**
 * Resolves `{type: 'agent'}` references.
 */
class AgentPrincipalResolver implements IPrincipalResolver {

	/**
	 * The type this resolver answers for.
	 *
	 * @var string
	 */
	public const TYPE = 'agent';

	/**
	 * Constructor.
	 *
	 * @param IUserManager $users Nextcloud's user manager.
	 *
	 * @spec openspec/changes/flow-typed-principals/specs/flow-typed-principals/spec.md
	 */
	public function __construct(private readonly IUserManager $users) {

	}//end __construct()

	/**
	 * The type.
	 *
	 * @return string `agent`.
	 *
	 * @spec openspec/changes/flow-typed-principals/specs/flow-typed-principals/spec.md
	 */
	public function type(): string {
		return self::TYPE;

	}//end type()

	/**
	 * The one identity this agent acts as.
	 *
	 * 🔴 NEVER MORE THAN ONE, AND NEVER A GROUP'S MEMBERS. See the class
	 * docblock: an agent step answerable by humans, or a human step answerable
	 * by an agent, is the failure this single-identity rule exists to prevent.
	 *
	 * @param string $id The agent's identity.
	 *
	 * @return array<int, string> The uid, or nothing when no such identity exists.
	 *
	 * @spec openspec/changes/flow-typed-principals/specs/flow-typed-principals/spec.md
	 */
	public function resolve(string $id): array {
		$uid = trim($id);
		if ($uid === '' || $this->users->userExists($uid) === false) {
			return [];
		}

		return [$uid];

	}//end resolve()
}//end class
