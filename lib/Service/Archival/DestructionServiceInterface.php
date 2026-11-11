<?php

/**
 * DestructionServiceInterface: what callers outside the component use of DestructionService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Archival
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

namespace OCA\OpenRegister\Service\Archival;

/**
 * The destruction list decisions the archival endpoints record.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own.
 */
interface DestructionServiceInterface {

	/**
	 * Destruction list status constants.
	 */
	public const STATUS_IN_REVIEW = 'in_review';
	public const STATUS_APPROVED = 'approved';
	public const STATUS_AWAITING_SECOND = 'awaiting_second_approval';
	public const STATUS_REJECTED = 'rejected';
	public const STATUS_COMPLETED = 'completed';

	/**
	 * The choice an archivist makes when approving a list.
	 *
	 * @param string                $action           The approval action: 'approve_all' or 'approve_partial'.
	 * @param array<int, string>    $excludedIds      UUIDs of objects to exclude (for partial approval).
	 * @param array<string, string> $exclusionReasons Reasons per excluded object UUID.
	 *
	 * @return ApprovalChoice The choice, to hand to {@see approveList()}.
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	public function approvalChoice(string $action, array $excludedIds, array $exclusionReasons): ApprovalChoice;

	/**
	 * Approve a destruction list (full or partial).
	 *
	 * The approval is recorded under the CANONICAL `userId` key: it is the shape
	 * RetentionController writes, and the shape both readers of the approvals
	 * list consume — RetentionService::certify() and
	 * DestructionExecutionJob both do array_column($approvals, 'userId'). This
	 * method previously wrote `approvedBy`, so the destruction certificate's
	 * approver list — the legal record of WHO authorised the destruction —
	 * came out empty (openregister#393).
	 *
	 * A recorded retain/transfer is binding; see withholdDecidedEntries() for
	 * why it is derived here rather than asked of the approver. It runs AFTER
	 * handlePartialApproval(), which REPLACES `objects`.
	 *
	 * @param array<string, mixed> $destructionList The destruction list data.
	 * @param ApprovalRequest      $request         The choice (from {@see approvalChoice()}), whether two-step
	 *                                              approval is required, and the persisted list's uuid the
	 *                                              execution job reloads.
	 *
	 * @return array<string, mixed> The updated destruction list.
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	public function approveList(array $destructionList, ApprovalRequest $request): array;

	/**
	 * Reject an entire destruction list.
	 *
	 * @param array<string, mixed> $destructionList The destruction list data.
	 * @param string $reason The reason for rejection.
	 *
	 * @return array<string, mixed> The updated destruction list.
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	public function rejectList(array $destructionList, string $reason): array;
	/**
	 * Withhold the entries a reviewer decided against (a recorded retain or transfer).
	 *
	 * Idempotent: an entry already moved to `excludedObjects` is no longer in
	 * `objects`, so a second approval finds nothing left to move.
	 *
	 * @param array<string, mixed> $destructionList The destruction list data.
	 *
	 * @return array<string, mixed> The list, with decided-against entries withheld.
	 *
	 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
	 */
	public function withholdDecidedEntries(array $destructionList): array;
}//end interface
