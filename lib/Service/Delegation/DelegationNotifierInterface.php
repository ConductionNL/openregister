<?php

/**
 * DelegationNotifierInterface: what callers outside the component use of DelegationNotifier.
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

use OCA\OpenRegister\Db\DelegationGrant;

/**
 * The delegation notifications the delegation endpoints send.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own.
 */
interface DelegationNotifierInterface {

	/**
	 * Withdraw the prompt for a request that has been answered.
	 *
	 * A prompt that outlives its decision is worse than no prompt: it invites the
	 * person to answer again, and the second answer either does nothing (so the
	 * UI lied) or overwrites the first (so the record no longer reflects what
	 * they decided).
	 *
	 * @param DelegationGrant $grant The answered grant.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/delegation-grants/spec.md
	 */
	public function answered(DelegationGrant $grant): void;

	/**
	 * Ask the named identity to answer.
	 *
	 * @param DelegationGrant $grant The outstanding request.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/delegation-grants/spec.md
	 */
	public function requested(DelegationGrant $grant): void;
}//end interface
