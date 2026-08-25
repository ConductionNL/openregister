<?php

/**
 * Decides whether a principal may act as another user, right now.
 *
 * The single place that answers the question `or-delegated-identity` left open.
 * Everything else about delegation — requesting consent, answering it, expiring
 * it — feeds this method, and this method is what work is refused by.
 *
 * THREE PROPERTIES THAT MATTER MORE THAN THE CODE
 *
 * **Self is not delegation.** A principal acting as themselves short-circuits
 * before the store is touched. This keeps the common case free and keeps the
 * store answerable: one that recorded every self-action could not answer "who can
 * act as the mayor?" without first filtering out the noise, which is the only
 * question it exists for.
 *
 * **The clock is an argument.** Liveness is decided against a `$now` the caller
 * supplies, never against `new DateTime()` read in here. A grant that expired one
 * second ago and one that has not are the case that must not be decided by
 * whichever machine happens to ask, and a method that reads the clock internally
 * cannot be tested at that boundary at all.
 *
 * **A refusal says WHICH.** Denied, expired, revoked and never-granted are four
 * different facts, and a caller that can only report "no" cannot tell a user
 * whether to ask again, wait, or give up. Collapsing them is how a consent system
 * becomes a retry loop.
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
use OCA\OpenRegister\Db\DelegationGrant;
use OCA\OpenRegister\Db\DelegationGrantMapper;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resolves a delegation to a verdict, with a reason.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) DelegationVerdict is a value object with
 *   NAMED CONSTRUCTORS, and that is deliberate: its constructor is private so a
 *   verdict can never be built with `permitted` and `reason` disagreeing. A
 *   verdict reading `permitted: true, reason: denied` would be believed by
 *   whichever field the caller happened to read, and this is the one place in the
 *   app where that class of confusion decides access. Injecting a factory to
 *   satisfy the rule would add a collaborator whose only job is to call the
 *   constructor this pattern exists to hide.
 *
 * @spec openspec/specs/delegation-grants/spec.md
 */
class DelegationResolver {

	/**
	 * Constructor.
	 *
	 * @param DelegationGrantMapper $grants Finds candidate grants.
	 * @param LoggerInterface       $logger Records a store that cannot be read.
	 */
	public function __construct(
		private readonly DelegationGrantMapper $grants,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * May `$principal` act as `$actingAs` at `$now`?
	 *
	 * @param string   $principal The uid that would act.
	 * @param string   $actingAs  The uid whose rights would be used.
	 * @param DateTime $now       The moment to judge against.
	 * @param array    $scope     The scope the work needs.
	 *
	 * @return DelegationVerdict The verdict, carrying its reason.
	 *
	 * @spec openspec/specs/delegation-grants/spec.md
	 */
	public function resolve(string $principal, string $actingAs, DateTime $now, array $scope = []): DelegationVerdict {
		$principal = trim($principal);
		$actingAs = trim($actingAs);

		if ($principal === '' || $actingAs === '') {
			return DelegationVerdict::refused(
				reason: DelegationVerdict::REASON_UNNAMED,
				detail: 'A delegation needs both a principal and an identity to act as.'
			);
		}

		// Self short-circuits BEFORE the store. See the class docblock.
		if ($principal === $actingAs) {
			return DelegationVerdict::self();
		}

		try {
			$candidates = $this->grants->findFor(principal: $principal, actingAs: $actingAs);
		} catch (Throwable $e) {
			// 🔴 Fail CLOSED. A store that cannot be read is not a store that
			// says yes. The alternative — treating an unreadable grant table as
			// "no restriction" — is the exact fail-open shape this subsystem has
			// already been bitten by twice (a never-injected logger, a
			// never-injected organisation service), and it fails silently.
			$this->logger->error(
				message: '[DelegationResolver] Could not read the grant store; refusing: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__, 'principal' => $principal, 'actingAs' => $actingAs]
			);

			return DelegationVerdict::refused(
				reason: DelegationVerdict::REASON_UNREADABLE,
				detail: 'The delegation store could not be read, so the delegation is refused.'
			);
		}//end try

		if ($candidates === []) {
			return DelegationVerdict::refused(
				reason: DelegationVerdict::REASON_NONE,
				detail: sprintf('"%s" holds no grant to act as "%s".', $principal, $actingAs)
			);
		}

		return $this->judge(candidates: $candidates, principal: $principal, actingAs: $actingAs, now: $now, scope: $scope);
	}//end resolve()

	/**
	 * Pick the verdict the candidate set warrants.
	 *
	 * A live grant wins outright. Absent one, the reported reason is the NEAREST
	 * MISS rather than a generic no — a revoked grant and a denied request send
	 * the requester to different places, and "you had one and it was withdrawn"
	 * is a different conversation from "you asked and were told no".
	 *
	 * @param array<int, DelegationGrant> $candidates The candidate grants.
	 * @param string                      $principal  The acting principal.
	 * @param string                      $actingAs   The identity sought.
	 * @param DateTime                    $now        The moment to judge against.
	 * @param array                       $scope      The scope needed.
	 *
	 * @return DelegationVerdict The verdict.
	 */
	private function judge(
		array $candidates,
		string $principal,
		string $actingAs,
		DateTime $now,
		array $scope,
	): DelegationVerdict {
		$nearest = DelegationVerdict::REASON_NONE;

		foreach ($candidates as $grant) {
			if ($grant->isLiveAt($now) === true && $this->covers(grant: $grant, scope: $scope) === true) {
				return DelegationVerdict::granted($grant);
			}

			$nearest = $this->nearer(current: $nearest, candidate: $this->missReason(grant: $grant, now: $now, scope: $scope));
		}

		return DelegationVerdict::refused(
			reason: $nearest,
			detail: sprintf('"%s" holds no live grant to act as "%s".', $principal, $actingAs)
		);
	}//end judge()

	/**
	 * Why this particular grant did not permit the work.
	 *
	 * @param DelegationGrant $grant The grant.
	 * @param DateTime        $now   The moment.
	 * @param array           $scope The scope needed.
	 *
	 * @return string One of DelegationVerdict's reason constants.
	 */
	private function missReason(DelegationGrant $grant, DateTime $now, array $scope): string {
		if ($grant->getStatus() === DelegationGrant::STATUS_DENIED) {
			return DelegationVerdict::REASON_DENIED;
		}

		if ($grant->getStatus() === DelegationGrant::STATUS_REVOKED || $grant->getRevokedAt() !== null) {
			return DelegationVerdict::REASON_REVOKED;
		}

		if ($grant->getStatus() === DelegationGrant::STATUS_GRANTED && $grant->isLiveAt($now) === false) {
			return DelegationVerdict::REASON_EXPIRED;
		}

		if (in_array(
			$grant->getStatus(),
			[DelegationGrant::STATUS_REQUESTED, DelegationGrant::STATUS_PENDING],
			true
		) === true
		) {
			return DelegationVerdict::REASON_PENDING;
		}

		if ($grant->isLiveAt($now) === true && $this->covers(grant: $grant, scope: $scope) === false) {
			return DelegationVerdict::REASON_OUT_OF_SCOPE;
		}

		return DelegationVerdict::REASON_NONE;
	}//end missReason()

	/**
	 * The more informative of two miss reasons.
	 *
	 * Ordered by what the requester can DO about it. "Someone said no" and "you
	 * are waiting on an answer" are actionable; "there is nothing" is not, so a
	 * specific reason always displaces the generic one.
	 *
	 * @param string $current   The reason so far.
	 * @param string $candidate The reason from this grant.
	 *
	 * @return string The reason to keep.
	 */
	private function nearer(string $current, string $candidate): string {
		$rank = [
			DelegationVerdict::REASON_NONE => 0,
			DelegationVerdict::REASON_OUT_OF_SCOPE => 1,
			DelegationVerdict::REASON_EXPIRED => 2,
			DelegationVerdict::REASON_REVOKED => 3,
			DelegationVerdict::REASON_PENDING => 4,
			DelegationVerdict::REASON_DENIED => 5,
		];

		if (($rank[$candidate] ?? 0) > ($rank[$current] ?? 0)) {
			return $candidate;
		}

		return $current;
	}//end nearer()

	/**
	 * Whether a grant's scope covers what the work needs.
	 *
	 * An EMPTY REQUESTED scope means "nothing in particular was asked for" and is
	 * covered by any live grant. An empty GRANT scope is deliberately NOT treated
	 * as unlimited: a grant that names no scope permits only the unscoped case, so
	 * that a record created without thinking about scope cannot silently become
	 * the broadest one in the store.
	 *
	 * @param DelegationGrant $grant The grant.
	 * @param array           $scope The scope needed.
	 *
	 * @return boolean Whether it covers.
	 *
	 * @spec openspec/specs/delegation-grants/spec.md
	 */
	private function covers(DelegationGrant $grant, array $scope): bool {
		if ($scope === []) {
			return true;
		}

		$granted = ($grant->getScope() ?? []);
		if ($granted === []) {
			return false;
		}

		foreach ($scope as $key => $needed) {
			if (array_key_exists($key, $granted) === false) {
				return false;
			}

			if (is_array($needed) === true && is_array($granted[$key]) === true) {
				if (array_diff($needed, $granted[$key]) !== []) {
					return false;
				}

				continue;
			}

			if ($granted[$key] !== $needed) {
				return false;
			}
		}

		return true;
	}//end covers()
}//end class
