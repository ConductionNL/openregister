<?php

/**
 * TransferListServiceInterface: the part of TransferListService that the transfer controller uses, and the transfer list statuses.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Edepot
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-support-transfer-list-management
 * @spec openspec/specs/edepot-transfer/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Edepot;

use InvalidArgumentException;

/**
 * The methods of TransferListService that callers outside its component use.
 * Callers depend on this interface, not on the class.
 */
interface TransferListServiceInterface {

	/**
	 * Transfer list status constants.
	 *
	 * TransferListService inherits them, so TransferListService::STATUS_* still resolves.
	 */
	public const STATUS_IN_REVIEW = 'in_review';
	public const STATUS_APPROVED = 'approved';
	public const STATUS_REJECTED = 'rejected';
	public const STATUS_IN_PROGRESS = 'in_progress';
	public const STATUS_COMPLETED = 'completed';
	public const STATUS_PARTIALLY_FAILED = 'partially_failed';
	public const STATUS_FAILED = 'failed';

	/**
	 * Approve a transfer list.
	 *
	 * @param array<string,mixed> $transferList The transfer list data.
	 * @param string              $archivistId  The approving archivist user ID.
	 *
	 * @return array<string,mixed> The updated transfer list data.
	 *
	 * @throws InvalidArgumentException If the list is not in review status.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-support-transfer-list-management
	 * @spec openspec/specs/edepot-transfer/spec.md
	 */
	public function approveTransferList(array $transferList, string $archivistId): array;

	/**
	 * Reject a transfer list in review.
	 *
	 * @param array<string,mixed> $transferList The transfer list data.
	 * @param TransferRejection   $rejection    Who rejects it, and why.
	 *
	 * @return array<string,mixed> The updated transfer list data.
	 *
	 * @throws InvalidArgumentException If the list is not in review status, or no reason is given.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-support-transfer-list-management
	 * @spec openspec/specs/edepot-transfer/spec.md
	 */
	public function reject(array $transferList, TransferRejection $rejection): array;
}//end interface
