<?php

/**
 * Thrown when a principal asked to act as another user and may not.
 *
 * WHY AN EXCEPTION AND NOT A FALSE
 * --------------------------------
 * `runAsDelegated()` returns whatever the wrapped operation returns, so there is
 * no room in the return value for "and by the way, nothing ran". A caller that
 * checked a boolean first and then called would have a window between the two;
 * a caller that forgot to check would get the operation's own "nothing found"
 * value and read it as an answer about the data.
 *
 * That failure has already been paid for here: every OpenRegister quota read
 * came back `0` on NC34 because a `catch` returned the same value a genuinely
 * empty quota returns. A refusal wearing the words of a legitimate empty result
 * cannot be observed by anyone.
 *
 * THE REASON TRAVELS WITH IT
 * --------------------------
 * The verdict is carried, not flattened into a string, because the four ways to
 * be refused send the caller to four different places: ask, wait, give up, or
 * ask an administrator. A `catch` that can only say "denied" recreates the
 * retry loop the reason codes exist to prevent.
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

use RuntimeException;

/**
 * A delegation was refused, and says why.
 *
 * @spec openspec/specs/delegation-grants/spec.md
 */
class DelegationRefused extends RuntimeException {

	/**
	 * Constructor.
	 *
	 * @param string            $principal The uid that asked to act.
	 * @param string            $actingAs  The uid it asked to act as.
	 * @param DelegationVerdict $verdict   The refusal, with its reason.
	 */
	public function __construct(
		private readonly string $principal,
		private readonly string $actingAs,
		private readonly DelegationVerdict $verdict,
	) {
		parent::__construct(
			message: sprintf(
				'Refusing to act as "%s" on behalf of "%s": %s',
				$actingAs,
				$principal,
				$verdict->detail
			)
		);
	}//end __construct()

	/**
	 * The uid that asked to act.
	 *
	 * @return string The principal.
	 *
	 * @spec openspec/specs/delegation-grants/spec.md
	 */
	public function getPrincipal(): string {
		return $this->principal;
	}//end getPrincipal()

	/**
	 * The uid it asked to act as.
	 *
	 * @return string The identity sought.
	 *
	 * @spec openspec/specs/delegation-grants/spec.md
	 */
	public function getActingAs(): string {
		return $this->actingAs;
	}//end getActingAs()

	/**
	 * The verdict, carrying its reason constant.
	 *
	 * @return DelegationVerdict The refusal.
	 *
	 * @spec openspec/specs/delegation-grants/spec.md
	 */
	public function getVerdict(): DelegationVerdict {
		return $this->verdict;
	}//end getVerdict()

	/**
	 * Whether asking the acted-as user for consent is the sensible next step.
	 *
	 * Forwarded so a caller can route without unpacking the verdict, and so the
	 * "do not re-ask after a denial" rule has exactly one implementation.
	 *
	 * @return boolean Whether to raise a consent request.
	 *
	 * @spec openspec/specs/delegation-grants/spec.md
	 */
	public function mayRequestConsent(): bool {
		return $this->verdict->mayRequestConsent();
	}//end mayRequestConsent()
}//end class
