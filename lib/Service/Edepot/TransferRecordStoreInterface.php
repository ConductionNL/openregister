<?php

/**
 * TransferRecordStoreInterface: load and save one durable transfer-list record.
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

/**
 * The durable transfer-list record the transfer execution job reads and writes.
 *
 * Bound to TransferRecordService in ContractBindings.
 */
interface TransferRecordStoreInterface {
	/**
	 * Persist (create or update) a transfer-list record.
	 *
	 * @param array<string, mixed> $transferList The transfer-list array.
	 *
	 * @return array<string, mixed> The persisted transfer-list data (with its object uuid).
	 *
	 * @spec openspec/changes/archival-transfer-hardening/specs/edepot-proof-of-transfer/spec.md
	 */
	public function saveTransferList(array $transferList): array;

	/**
	 * Load a transfer-list record by uuid, or null when absent.
	 *
	 * @param string $uuid The transfer-list uuid.
	 *
	 * @return array<string, mixed>|null The transfer-list data, or null.
	 *
	 * @spec openspec/changes/archival-transfer-hardening/specs/edepot-proof-of-transfer/spec.md
	 */
	public function loadTransferList(string $uuid): ?array;
}//end interface
