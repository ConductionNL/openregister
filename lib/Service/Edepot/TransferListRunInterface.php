<?php

/**
 * TransferListRunInterface: the transfer-list steps the e-Depot background jobs run.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Edepot
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

namespace OCA\OpenRegister\Service\Edepot;

use InvalidArgumentException;
use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Create a transfer list, find the objects already on one, and tell the archivists.
 *
 * Bound to TransferListService in ContractBindings.
 */
interface TransferListRunInterface {
	/**
	 * Create a new transfer list from eligible objects.
	 *
	 * @param array<int, ObjectEntity> $objects The objects eligible for transfer.
	 *
	 * @return array{
	 *     uuid: string,
	 *     status: string,
	 *     objectReferences: array<int, array{uuid: string, schema: int|null, register: int|null}>,
	 *     createdAt: string,
	 *     objectCount: int
	 * } The transfer list data.
	 *
	 * @throws InvalidArgumentException If no objects provided.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-support-transfer-list-management
	 */
	public function createTransferList(array $objects): array;

	/**
	 * Get UUIDs of objects currently on active transfer lists.
	 *
	 * @param array<int, array<string,mixed>> $activeTransferLists Active transfer list data.
	 *
	 * @return array<int, string> UUIDs of objects on active transfer lists.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-support-transfer-list-management
	 */
	public function getObjectsOnActiveTransferLists(array $activeTransferLists): array;

	/**
	 * Send notification to archivist users about a transfer list.
	 *
	 * @param array<string,mixed> $transferList The transfer list data.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-support-transfer-list-management
	 */
	public function notifyArchivists(array $transferList): void;
}//end interface
