<?php

/**
 * A decision was refused because the decider is the sequence's requester.
 *
 * Deliberately NOT a {@see TaskAccessDeniedException}: the requester may be
 * perfectly authorized (a member of the candidate group, even the assignee)
 * and is still refused, and the reason must say WHICH of the two applied
 * (flow-approval-consolidation design D-8; the retired engine documented the
 * same ordering at `ApprovalService.php:165-168`). Callers map this to an
 * honest "you asked for this approval yourself" rather than "you are not
 * allowed to decide approvals".
 *
 * Also thrown for a DELEGATED self-decision: an `on_behalf_of` that resolves
 * to the requester is the same self-decision, and this is the one place the
 * migrated behaviour is deliberately stricter than what it replaces.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-approval-consolidation/specs/approval-workflow/spec.md#req-009
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Exception;

use RuntimeException;

/**
 * A refused self-decision on an approval sequence.
 *
 * @spec openspec/changes/flow-approval-consolidation/specs/approval-workflow/spec.md#req-009
 */
class TaskSeparationOfDutiesException extends RuntimeException {
}//end class
