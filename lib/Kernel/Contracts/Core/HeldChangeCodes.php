<?php
/**
 * HeldChangeCodes: the refusal codes of a write an approval chain holds, as the object API answers them.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts\Core
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Core;

/**
 * The `code` a pre-write hook puts in its errors when it holds a write for approval.
 *
 * The object API (Core) answers these codes: 202 with the pending change for a
 * held write, 409 while a pending change of the same gate is still open. The
 * change gate (Processes) stops the write with them; its own constants are
 * these values.
 *
 * @spec openspec/changes/records-change-held-for-approval/specs/approval-workflow/spec.md#requirement-an-approval-chain-can-hold-a-change-until-it-is-approved
 */
final class HeldChangeCodes {

	/**
	 * A held write: stored as a pending change, answered with 202.
	 *
	 * @var string
	 */
	public const PENDING = 'change-pending';

	/**
	 * A gated write while a pending change is open, answered with 409.
	 *
	 * @var string
	 */
	public const PENDING_OPEN = 'change-pending-open';
}//end class
