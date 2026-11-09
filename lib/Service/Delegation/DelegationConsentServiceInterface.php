<?php

/**
 * DelegationConsentServiceInterface: what callers outside the component use of DelegationConsentService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Delegation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Delegation;

use DateTime;
use InvalidArgumentException;
use OCA\OpenRegister\Db\DelegationGrant;

/**
 * The delegation consent steps the delegation endpoints call.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own.
 */
interface DelegationConsentServiceInterface {

	/**
	 * Answer a request.
	 *
	 * 🔴 `$answeredBy` is checked against the grant's `actingAs`. Consent over an
	 * identity comes from that identity or from an administrator, and the
	 * requester is emphatically not either — this is the check that stops a
	 * principal from approving their own request.
	 *
	 * @param DelegationGrant $grant      The request being answered.
	 * @param string          $answeredBy Who is answering.
	 * @param boolean         $allow      Whether they permit it.
	 * @param DateTime        $now        The moment of answering.
	 * @param boolean         $isAdmin    Whether the answerer is an administrator.
	 * @param DateTime|null   $until      An explicit expiry, or null for the default.
	 *
	 * @return DelegationGrant The answered grant.
	 *
	 * @throws InvalidArgumentException When the answerer may not answer this request.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) $allow IS the answer, and
	 *   $isAdmin is a fact about the answerer rather than a mode switch.
	 *
	 * @spec openspec/specs/delegation-grants/spec.md
	 */
	public function answer(
		DelegationGrant $grant,
		string $answeredBy,
		bool $allow,
		DateTime $now,
		bool $isAdmin = false,
		?DateTime $until = null,
	): DelegationGrant;

	/**
	 * What a user is told when asked to consent.
	 *
	 * Built ENTIRELY from the stored record. Nothing here reads requester-supplied
	 * free text into the sentence structure, and the reason is presented as a
	 * quoted claim BY the requester rather than as narration — so a reason reading
	 * "you must approve this immediately" is visibly something somebody typed,
	 * not something the system is saying.
	 *
	 * @param DelegationGrant $grant The pending request.
	 *
	 * @return array<string, mixed> The fields a prompt renders.
	 *
	 * @spec openspec/specs/delegation-grants/spec.md
	 */
	public function describe(DelegationGrant $grant): array;

	/**
	 * Ask `$actingAs` to let `$principal` act as them.
	 *
	 * Returns the EXISTING outstanding request when there is one, rather than
	 * creating a second. The caller cannot tell the two apart and should not need
	 * to — what it needs is "there is a request, park on it".
	 *
	 * @param string   $principal The uid that would act.
	 * @param string   $actingAs  The uid being asked.
	 * @param array    $scope     What is being asked for.
	 * @param string   $reason    Why, in the requester's words.
	 * @param DateTime $now       The moment of asking.
	 *
	 * @return DelegationGrant The outstanding request.
	 *
	 * @throws InvalidArgumentException When the request is not one that may be made.
	 *
	 * @spec openspec/specs/delegation-grants/spec.md
	 */
	public function request(
		string $principal,
		string $actingAs,
		array $scope,
		string $reason,
		DateTime $now,
	): DelegationGrant;

	/**
	 * Withdraw a granted delegation.
	 *
	 * @param DelegationGrant $grant     The grant to withdraw.
	 * @param string          $revokedBy Who is withdrawing it.
	 * @param DateTime        $now       The moment.
	 * @param boolean         $isAdmin   Whether the revoker is an administrator.
	 *
	 * @return DelegationGrant The revoked grant.
	 *
	 * @throws InvalidArgumentException When the revoker may not withdraw it.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) $isAdmin is a fact about the caller.
	 *
	 * @spec openspec/specs/delegation-grants/spec.md
	 */
	public function revoke(DelegationGrant $grant, string $revokedBy, DateTime $now, bool $isAdmin = false): DelegationGrant;
}//end interface
