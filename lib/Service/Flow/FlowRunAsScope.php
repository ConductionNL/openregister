<?php

/**
 * Runs a contributed node's work as the run's acting identity.
 *
 * WHY THIS EXISTS. The engine stamps the run's `runAs` into every node
 * context, and its OWN storage-touching nodes wrap their work in
 * `ObjectService::runAs()` — because the permission gate (MagicRbacHandler /
 * MagicOrganizationHandler) reads the AMBIENT SESSION user, not a parameter.
 * Under the cron worker that session carries nobody, so a bare write is
 * refused as anonymous no matter whose rights the run declares. A CONTRIBUTED
 * node used to get the context key and nothing else: every consuming app had
 * to build this exact wrapper itself, and dossiq shipped three broken nodes
 * (plus MergeTemplateHandler, a fourth) before it did. The identity is
 * run-level state, so scoping to it belongs to the engine — this class is the
 * engine-owned twin of dossiq's `FlowRunAsScope`, which becomes deletable.
 *
 * 🔴 IT NARROWS, NEVER GRANTS. `runAs()` sets the session subject to a NAMED
 * user for the duration of the callable; a run whose identity cannot write is
 * still refused, and now for the right reason. Deliberately not
 * `runAsSystem()`: a flow node executes user-authored input, which is exactly
 * the caller ADR-099 forbids from reaching the userless principal.
 *
 * WHEN THE CONTEXT NAMES NOBODY the operation runs bare, under whatever
 * session is ambient — the interactive path, where the logged-in user IS the
 * acting identity and no `runAs` key was stamped.
 *
 * WHEN THE CONTEXT NAMES SOMEBODY UNUSABLE it refuses loudly. An identity
 * that resolves to no account, or to a DISABLED one, is a run that must stop,
 * not a write that silently happens as someone else. Rights are re-resolved
 * at the moment work runs precisely so a revocation takes effect: a run
 * parked for weeks must not resume with the rights of somebody who has since
 * been offboarded. The same refusal shape as `ObjectWriteNode::resolveOwner()`,
 * because it is the same rule.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-engine-consumer-seams/specs/flow-engine-consumer-seams/spec.md#requirement-a-contributed-node-executes-under-the-runs-acting-identity
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use OCA\OpenRegister\Service\ObjectService;
use OCP\IUserManager;
use RuntimeException;

/**
 * Validates the run's acting identity and scopes a callable to it.
 *
 * @spec openspec/changes/flow-engine-consumer-seams/specs/flow-engine-consumer-seams/spec.md#requirement-a-contributed-node-executes-under-the-runs-acting-identity
 */
class FlowRunAsScope {
	/**
	 * Constructor.
	 *
	 * @param IUserManager $userManager Resolves the named identity to a real account.
	 * @param ObjectService $objectService Owns the session-scoping seam every
	 *                                     RBAC and organisation predicate reads.
	 */
	public function __construct(
		private readonly IUserManager $userManager,
		private readonly ObjectService $objectService,
	) {

	}//end __construct()

	/**
	 * Run the operation as the context's acting identity, when it names one.
	 *
	 * @param array $context The node context, possibly carrying the identity
	 *                       under {@see FlowRunService::RUN_AS_CONTEXT_KEY}.
	 * @param callable $operation The node's work.
	 *
	 * @return mixed Whatever the operation returns.
	 *
	 * @throws RuntimeException When the named identity cannot be acted as.
	 *
	 * @spec openspec/changes/flow-engine-consumer-seams/specs/flow-engine-consumer-seams/spec.md#requirement-a-contributed-node-executes-under-the-runs-acting-identity
	 */
	public function call(array $context, callable $operation): mixed {
		$uid = trim((string)($context[FlowRunService::RUN_AS_CONTEXT_KEY] ?? ''));
		if ($uid === '') {
			// No acting identity declared: the interactive path, where the
			// ambient session user already answers the permission checks.
			return $operation();
		}

		$user = $this->userManager->get($uid);
		if ($user === null) {
			throw new RuntimeException(
				sprintf('This flow run\'s acting identity "%s" (runAs) is not a user account; the step is refused.', $uid)
			);
		}

		if ($user->isEnabled() === false) {
			throw new RuntimeException(
				sprintf('This flow run\'s acting identity "%s" (runAs) is a disabled account; the step is refused.', $uid)
			);
		}

		return $this->objectService->runAs($user, $operation);
	}//end call()
}//end class
