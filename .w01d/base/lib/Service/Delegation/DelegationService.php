<?php

/**
 * Runs work as another user, but only when a grant says it may.
 *
 * WHERE THIS SITS
 * ---------------
 * `ObjectService::runAs()` is the PRIMITIVE: hand it an `IUser` and a callable
 * and the callable executes with that user's RBAC and tenancy. It narrows, it
 * grants nothing, and it asks no questions — a caller who already holds an
 * `IUser` has, by construction, already been authorised to hold one.
 *
 * This class is the AUTHORIZATION layer directly above it. It takes two uids
 * rather than an `IUser`, because its whole job is to decide whether the first
 * may become the second. On a yes it forwards to the primitive; on a no it
 * throws {@see DelegationRefused} carrying the reason.
 *
 * Keeping the two apart is deliberate. Folding the grant check into `runAs()`
 * would make the primitive unusable by the callers that legitimately have no
 * delegation to check — a request handler running as its own authenticated
 * user, a job replaying its recorded actor — and the usual answer to that is a
 * `$skipCheck` flag, which is a security check with an off switch.
 *
 * WHAT IT DOES NOT DO
 * -------------------
 * It does not ask for consent. A refusal that `mayRequestConsent()` says is
 * askable routes to {@see DelegationConsentService} — by the CALLER, because
 * only the caller knows whether there is a person present to answer and what
 * the work was. Raising a request from inside the refusal path would make every
 * unattended retry generate a prompt.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Delegation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/delegation-grants/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Delegation;

use DateTime;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * The guarded form of "act as this user".
 *
 * @SuppressWarnings(PHPMD.StaticAccess) DelegationVerdict is a value object with
 *   NAMED CONSTRUCTORS and a private constructor, so a verdict can never be
 *   built with `permitted` and `reason` disagreeing — a verdict reading
 *   `permitted: true, reason: denied` would be believed by whichever field the
 *   caller happened to read, and this subsystem is where that class of confusion
 *   decides access. Injecting a factory to satisfy the rule would add a
 *   collaborator whose only job is to call the constructor the pattern hides.
 *
 * @spec openspec/specs/delegation-grants/spec.md
 */
class DelegationService {

	/**
	 * Constructor.
	 *
	 * @param DelegationResolver $resolver      Decides whether the delegation is permitted.
	 * @param IUserManager       $userManager   Resolves a uid to an account.
	 * @param ObjectService      $objectService Owns the identity-switch primitive.
	 * @param LoggerInterface    $logger        Records every delegation, taken or refused.
	 */
	public function __construct(
		private readonly DelegationResolver $resolver,
		private readonly IUserManager $userManager,
		private readonly ObjectService $objectService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Run `$operation` as `$actingAs`, on behalf of `$principal`.
	 *
	 * Acting as yourself short-circuits the store entirely — see
	 * {@see DelegationResolver} for why that matters beyond speed.
	 *
	 * @param string        $principal The uid the work is being done by.
	 * @param string        $actingAs  The uid whose rights the work should use.
	 * @param callable      $operation The work.
	 * @param array         $scope     What the work needs, for scope matching.
	 * @param DateTime|null $now       The moment to judge liveness at; defaults to now.
	 *
	 * @return mixed Whatever the operation returns.
	 *
	 * @throws DelegationRefused When no live grant covers the delegation.
	 *
	 * @spec openspec/specs/delegation-grants/spec.md
	 */
	public function runAsDelegated(
		string $principal,
		string $actingAs,
		callable $operation,
		array $scope = [],
		?DateTime $now = null,
	) {
		$verdict = $this->verdictFor(
			principal: $principal,
			actingAs: $actingAs,
			scope: $scope,
			now: ($now ?? new DateTime())
		);

		if ($verdict->permitted === false) {
			// 🔴 Log the refusal at WARNING, not INFO. A delegation that was
			// asked for and refused is the security-relevant event here: the
			// permitted case is ordinary operation, and burying refusals at the
			// same level as successes is how a probing pattern becomes
			// invisible in a log nobody greps.
			$this->logger->warning(
				message: '[DelegationService] Delegation refused',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'principal' => $principal,
					'actingAs' => $actingAs,
					'reason' => $verdict->reason,
					'scope' => $scope,
				]
			);

			throw new DelegationRefused(principal: $principal, actingAs: $actingAs, verdict: $verdict);
		}

		if ($verdict->reason === DelegationVerdict::REASON_SELF) {
			// No identity switch is needed or wanted. Switching to the session's
			// own user would still be a switch, and one that a `finally` has to
			// undo — pure risk for no behaviour change.
			return $operation();
		}

		$user = $this->userManager->get($actingAs);
		if ($user === null) {
			// A grant naming an account that no longer exists is not a licence
			// to run as nobody. `runAs(null)` is not even expressible, but the
			// tempting repair — fall back to the principal — would silently
			// execute the work under the WRONG identity while the audit trail
			// said otherwise.
			$gone = DelegationVerdict::refused(
				reason: DelegationVerdict::REASON_UNNAMED,
				detail: sprintf('"%s" resolves to no account, so it cannot be acted as.', $actingAs)
			);

			$this->logger->error(
				message: '[DelegationService] A live grant names an account that does not resolve',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'principal' => $principal,
					'actingAs' => $actingAs,
					'grantId' => $verdict->grant?->getId(),
				]
			);

			throw new DelegationRefused(principal: $principal, actingAs: $actingAs, verdict: $gone);
		}//end if

		$this->logger->info(
			message: '[DelegationService] Acting on behalf of another user',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'principal' => $principal,
				'actingAs' => $actingAs,
				'grantId' => $verdict->grant?->getId(),
				'grantedBy' => $verdict->grant?->getGrantedBy(),
				'statedReason' => $verdict->grant?->getReason(),
				'scope' => $scope,
			]
		);

		return $this->objectService->runAs(user: $user, operation: $operation);
	}//end runAsDelegated()

	/**
	 * Would this delegation be permitted, without performing it?
	 *
	 * Save-time validation needs the answer without the work — a flow author
	 * naming a colleague in a schedule trigger must be told at save time, not at
	 * 03:00 when the schedule fires into a log nobody is reading.
	 *
	 * 🔴 A pass here is NOT standing authorization. It answers "may this be
	 * saved", and the grant it relied on can be revoked between then and the
	 * first firing, which is exactly why the fire path re-resolves rather than
	 * trusting that the definition was once valid.
	 *
	 * @param string        $principal The uid the work would be done by.
	 * @param string        $actingAs  The uid whose rights it would use.
	 * @param array         $scope     What the work needs.
	 * @param DateTime|null $now       The moment to judge at; defaults to now.
	 *
	 * @return DelegationVerdict The verdict, carrying its reason.
	 *
	 * @spec openspec/specs/delegation-grants/spec.md
	 */
	public function verdictFor(
		string $principal,
		string $actingAs,
		array $scope = [],
		?DateTime $now = null,
	): DelegationVerdict {
		return $this->resolver->resolve(
			principal: $principal,
			actingAs: $actingAs,
			now: ($now ?? new DateTime()),
			scope: $scope
		);
	}//end verdictFor()
}//end class
