<?php

/**
 * The answer to "may this principal act as that user", with its reason.
 *
 * A boolean would be enough to enforce and not enough to explain, and explaining
 * is most of the value. Four different facts hide behind "no":
 *
 *  - **denied** — somebody was asked and said no. Do not ask again yet.
 *  - **pending** — somebody was asked and has not answered. Wait.
 *  - **revoked** — this was permitted and was withdrawn. Something changed.
 *  - **none** — nobody was ever asked. Ask.
 *
 * A caller that can only report "refused" turns all four into the same dead end,
 * and the usual consequence is a retry loop: the requester asks again, the user
 * is prompted again, and consent fatigue converts the refusal into an approval on
 * the eleventh prompt. The reason is what makes the difference actionable.
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

use OCA\OpenRegister\Db\DelegationGrant;

/**
 * A permitted-or-not answer that says why.
 *
 * @spec openspec/specs/delegation-grants/spec.md
 */
final class DelegationVerdict {

	/**
	 * The principal is the identity; no delegation was involved.
	 *
	 * @var string
	 */
	public const REASON_SELF = 'self';

	/**
	 * A live grant covers the work.
	 *
	 * @var string
	 */
	public const REASON_GRANTED = 'granted';

	/**
	 * No grant exists at all. Asking is the next step.
	 *
	 * @var string
	 */
	public const REASON_NONE = 'none';

	/**
	 * A request exists and has not been answered. Waiting is the next step.
	 *
	 * @var string
	 */
	public const REASON_PENDING = 'pending';

	/**
	 * Somebody was asked and said no. Asking again is NOT the next step.
	 *
	 * @var string
	 */
	public const REASON_DENIED = 'denied';

	/**
	 * It was permitted and was withdrawn.
	 *
	 * @var string
	 */
	public const REASON_REVOKED = 'revoked';

	/**
	 * It was permitted and the permission ran out.
	 *
	 * @var string
	 */
	public const REASON_EXPIRED = 'expired';

	/**
	 * A live grant exists but does not cover this work.
	 *
	 * @var string
	 */
	public const REASON_OUT_OF_SCOPE = 'out-of-scope';

	/**
	 * One or both parties were not named.
	 *
	 * @var string
	 */
	public const REASON_UNNAMED = 'unnamed';

	/**
	 * The store could not be read, so nothing is permitted.
	 *
	 * @var string
	 */
	public const REASON_UNREADABLE = 'unreadable';

	/**
	 * Constructor.
	 *
	 * Private so a verdict can only be built through the named constructors, which
	 * keeps `permitted` and `reason` from ever disagreeing — a verdict reading
	 * `permitted: true, reason: denied` would be believed by whichever field the
	 * caller happened to read.
	 *
	 * @param boolean              $permitted Whether the work may proceed.
	 * @param string               $reason    One of the REASON_* constants.
	 * @param string               $detail    A human-readable explanation.
	 * @param DelegationGrant|null $grant     The grant relied on, when permitted.
	 */
	private function __construct(
		public readonly bool $permitted,
		public readonly string $reason,
		public readonly string $detail,
		public readonly ?DelegationGrant $grant,
	) {
	}//end __construct()

	/**
	 * Acting as yourself.
	 *
	 * @return self The verdict.
	 *
	 * @spec openspec/specs/delegation-grants/spec.md
	 */
	public static function self(): self {
		return new self(
			permitted: true,
			reason: self::REASON_SELF,
			detail: 'A principal acting as themselves is not delegation.',
			grant: null
		);
	}//end self()

	/**
	 * A live grant permits the work.
	 *
	 * @param DelegationGrant $grant The grant relied on.
	 *
	 * @return self The verdict.
	 *
	 * @spec openspec/specs/delegation-grants/spec.md
	 */
	public static function granted(DelegationGrant $grant): self {
		return new self(
			permitted: true,
			reason: self::REASON_GRANTED,
			detail: sprintf(
				'"%s" may act as "%s" under a grant given by "%s".',
				(string)$grant->getPrincipal(),
				(string)$grant->getActingAs(),
				(string)($grant->getGrantedBy() ?? 'an administrator')
			),
			grant: $grant
		);
	}//end granted()

	/**
	 * The work is refused, for a stated reason.
	 *
	 * @param string $reason One of the REASON_* constants.
	 * @param string $detail A human-readable explanation.
	 *
	 * @return self The verdict.
	 *
	 * @spec openspec/specs/delegation-grants/spec.md
	 */
	public static function refused(string $reason, string $detail): self {
		return new self(permitted: false, reason: $reason, detail: $detail, grant: null);
	}//end refused()

	/**
	 * Whether asking for consent is a sensible next step.
	 *
	 * False after a DENIAL, which is the point: re-requesting something a person
	 * has already declined is how consent fatigue is manufactured, and the
	 * eleventh identical prompt gets accepted by reflex rather than by decision.
	 *
	 * Also false while a request is already outstanding — that is what the dedup
	 * on (principal, actingAs, scope) exists for.
	 *
	 * @return boolean Whether to raise a consent request.
	 *
	 * @spec openspec/specs/delegation-grants/spec.md
	 */
	public function mayRequestConsent(): bool {
		return in_array($this->reason, [self::REASON_NONE, self::REASON_EXPIRED, self::REASON_OUT_OF_SCOPE], true);
	}//end mayRequestConsent()
}//end class
