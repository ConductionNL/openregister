<?php

/**
 * TransferRecordServiceInterface: the part of TransferRecordService that the transfer controller uses.
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
 *
 * @spec openspec/changes/archival-transfer-hardening/specs/edepot-proof-of-transfer/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Edepot;

/**
 * The methods of TransferRecordService that callers outside its component use.
 * Callers depend on this interface, not on the class.
 */
interface TransferRecordServiceInterface {

	/**
	 * List all durable transfer-list records (newest first), RBAC/tenant scoped.
	 *
	 * @return array<int, array<string, mixed>> The transfer-list records.
	 *
	 * @spec openspec/changes/archival-transfer-hardening/specs/edepot-proof-of-transfer/spec.md
	 *   (Scenario: Index returns persisted transfer lists)
	 */
	public function listTransferLists(): array;

	/**
	 * Load a transfer-list record by uuid, or null when absent.
	 *
	 * @param string $uuid The transfer-list uuid.
	 *
	 * @return array<string, mixed>|null The transfer-list data, or null.
	 *
	 * @spec openspec/changes/archival-transfer-hardening/specs/edepot-proof-of-transfer/spec.md
	 *   (Scenario: Show returns a persisted transfer list)
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) WriteCause::asLookup() is the ambient audit-cause frame; there is no instance to inject.
	 */
	public function loadTransferList(string $uuid): ?array;

	/**
	 * Persist (create or update) a transfer-list record, keyed by its own uuid.
	 *
	 * @param array<string, mixed> $transferList The transfer-list array.
	 *
	 * @return array<string, mixed> The persisted transfer-list data.
	 *
	 * @spec openspec/changes/archival-transfer-hardening/specs/edepot-proof-of-transfer/spec.md
	 *   (Requirement: Durable transfer-list objects served over the API)
	 */
	public function saveTransferList(array $transferList): array;
}//end interface
