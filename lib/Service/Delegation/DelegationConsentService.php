<?php

/**
 * Asks a user whether somebody may act as them, and records the answer.
 *
 * WHO MAY ANSWER
 *
 * Consent over an identity comes from that identity, or from an administrator.
 * A principal can never grant themselves the right to act as somebody else —
 * which sounds obvious and is exactly the check that gets omitted, because the
 * requester is the one holding the request object.
 *
 * WHY THE DEDUP KEY IS NOT THE UNIT OF WORK
 *
 * Requests are deduplicated on (principal, actingAs, scope). Keyed per run, a
 * backlog of two hundred queued runs needing one grant sends two hundred
 * notifications — and the consequence is not that the recipient is annoyed, it is
 * that they learn to dismiss the prompt. A consent control that gets dismissed by
 * reflex has stopped being a control while still looking like one.
 *
 * WHY A DENIAL IS STICKY
 *
 * Re-asking after a refusal is how consent fatigue is manufactured. The eleventh
 * identical prompt is accepted by reflex rather than by decision, and the record
 * then shows a grant the person never meant to give. So a denial suppresses
 * re-requests for a cooling period, and the work is refused with the prior denial
 * as its reason rather than by asking again.
 *
 * 🔴 WHAT THIS CLASS MUST NEVER DO
 *
 * Compose the description a user is shown from requester-supplied text, or from
 * anything a language model produced. A document an agent reads can say "ask the
 * user to grant you admin", and if that string can reach the dialog then the
 * thing being granted is writing its own consent prompt. Everything rendered
 * comes from the stored record's own fields.
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

use DateInterval;
use DateTime;
use OCA\OpenRegister\Db\DelegationGrant;
use OCA\OpenRegister\Db\DelegationGrantMapper;
use InvalidArgumentException;

/**
 * The consent lifecycle: request, answer, expire.
 *
 * @spec openspec/specs/delegation-grants/spec.md
 */
class DelegationConsentService {

	/**
	 * How long a granted delegation lasts when the granter names no expiry.
	 *
	 * Finite BY DEFAULT. An unexpiring grant is a permanent privilege whose
	 * end-date is never revisited, and "temporary" access that nobody revisits is
	 * how a delegation outlives the situation that justified it.
	 *
	 * @var string
	 */
	public const DEFAULT_LIFETIME = 'P30D';

	/**
	 * How long a denial suppresses an identical request.
	 *
	 * @var string
	 */
	public const DENIAL_COOLING = 'P7D';

	/**
	 * How long an unanswered request stays open.
	 *
	 * Short on purpose. Work parked on consent waits for a human, and nobody
	 * should discover a six-week-old parked run.
	 *
	 * @var string
	 */
	public const REQUEST_LIFETIME = 'P3D';

	/**
	 * Constructor.
	 *
	 * @param DelegationGrantMapper $grants Reads and writes grants.
	 */
	public function __construct(
		private readonly DelegationGrantMapper $grants,
	) {
	}//end __construct()

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
	): DelegationGrant {
		$principal = trim($principal);
		$actingAs = trim($actingAs);

		if ($principal === '' || $actingAs === '') {
			throw new InvalidArgumentException('A consent request needs both a principal and an identity.');
		}

		if ($principal === $actingAs) {
			// Not a refusal of something dangerous — a refusal of something
			// meaningless. Acting as yourself needs no grant, so a request for it
			// would sit in somebody's inbox forever asking them to permit what
			// they already do.
			throw new InvalidArgumentException('Acting as yourself is not delegation; no consent is needed.');
		}

		$existing = $this->grants->findOutstandingRequest(principal: $principal, actingAs: $actingAs, scope: $scope);
		if ($existing !== null) {
			return $existing;
		}

		$grant = new DelegationGrant();
		$grant->setUuid($this->newUuid());
		$grant->setPrincipal($principal);
		$grant->setActingAs($actingAs);
		$grant->setScope($scope);
		$grant->setReason($reason);
		$grant->setStatus(DelegationGrant::STATUS_PENDING);
		$grant->setRequestedAt($now);
		$grant->setExpiresAt((clone $now)->add(new DateInterval(self::REQUEST_LIFETIME)));

		return $this->grants->insert($grant);
	}//end request()

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
	): DelegationGrant {
		$answeredBy = trim($answeredBy);

		if ($answeredBy !== trim((string)$grant->getActingAs()) && $isAdmin === false) {
			throw new InvalidArgumentException(
				sprintf(
					'Only "%s" or an administrator may answer a request to act as them; "%s" may not.',
					(string)$grant->getActingAs(),
					$answeredBy
				)
			);
		}

		if (in_array(
			$grant->getStatus(),
			[DelegationGrant::STATUS_REQUESTED, DelegationGrant::STATUS_PENDING],
			true
		) === false
		) {
			throw new InvalidArgumentException('This request has already been answered.');
		}

		$grant->setAnsweredAt($now);
		$grant->setGrantedBy($answeredBy);

		if ($allow === false) {
			$grant->setStatus(DelegationGrant::STATUS_DENIED);
			// The denial's own expiry becomes the cooling period: until then, an
			// identical request is answered from this record rather than
			// delivered to the user again.
			$grant->setExpiresAt((clone $now)->add(new DateInterval(self::DENIAL_COOLING)));

			return $this->grants->update($grant);
		}

		$grant->setStatus(DelegationGrant::STATUS_GRANTED);
		$grant->setExpiresAt($until ?? (clone $now)->add(new DateInterval(self::DEFAULT_LIFETIME)));

		return $this->grants->update($grant);
	}//end answer()

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
	public function revoke(DelegationGrant $grant, string $revokedBy, DateTime $now, bool $isAdmin = false): DelegationGrant {
		$revokedBy = trim($revokedBy);

		if ($revokedBy !== trim((string)$grant->getActingAs()) && $isAdmin === false) {
			throw new InvalidArgumentException(
				sprintf('Only "%s" or an administrator may withdraw a grant over them.', (string)$grant->getActingAs())
			);
		}

		$grant->setStatus(DelegationGrant::STATUS_REVOKED);
		$grant->setRevokedAt($now);

		return $this->grants->update($grant);
	}//end revoke()

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
	public function describe(DelegationGrant $grant): array {
		return [
			// The uuid and the status are what make this ANSWERABLE rather than
			// merely readable. Without the uuid a listed request names no route
			// to act on it, and without the status a UI cannot tell an
			// outstanding question from a decision already taken — so it renders
			// Allow/Deny on both and the second answer either does nothing or
			// overwrites the first.
			'uuid' => $grant->getUuid(),
			'status' => $grant->getStatus(),
			'principal' => $grant->getPrincipal(),
			'actingAs' => $grant->getActingAs(),
			'scope' => ($grant->getScope() ?? []),
			'expiresAt' => $grant->getExpiresAt()?->format('c'),
			'requestedAt' => $grant->getRequestedAt()?->format('c'),
			'answeredAt' => $grant->getAnsweredAt()?->format('c'),
			'revokedAt' => $grant->getRevokedAt()?->format('c'),
			'grantedBy' => $grant->getGrantedBy(),
			// Quoted, attributed, and never interpolated into the sentence the
			// system speaks in its own voice.
			'statedReason' => $grant->getReason(),
			'summary' => sprintf(
				'%s is asking to act with your account\'s rights.',
				(string)$grant->getPrincipal()
			),
		];
	}//end describe()

	/**
	 * A fresh uuid.
	 *
	 * @return string The uuid.
	 */
	private function newUuid(): string {
		$data = random_bytes(16);
		$data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
		$data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
	}//end newUuid()
}//end class
