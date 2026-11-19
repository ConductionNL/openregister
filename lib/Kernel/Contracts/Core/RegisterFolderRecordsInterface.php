<?php
/**
 * RegisterFolderRecordsInterface: a register's folder id, recorded as bookkeeping, as the folder handler asks it.
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
 * Records a register's folder id with a compare-and-set, and says whether another register holds that folder.
 *
 * The folder handler (Core) asks on a first upload and on a register delete; the register folder
 * recorder (Files) answers and binds the answer in its own registrar.
 *
 * @spec openspec/specs/file-actions/spec.md#requirement-recording-a-registers-folder-id-is-bookkeeping-req-rffu-002
 */
interface RegisterFolderRecordsInterface {

	/**
	 * Record a register's folder id while the stored value is still empty or what the caller read.
	 *
	 * @param int         $registerId The register the upload resolved.
	 * @param string|null $expected   The folder value read before the folder was made: null or '' for none,
	 *                                or the stale id or legacy path that no longer resolves.
	 * @param string      $folderId   The node id of the folder the file service made or found.
	 *
	 * @return bool True when this call recorded the id; false when another request recorded one first.
	 *
	 * @spec openspec/specs/file-actions/spec.md#requirement-recording-a-registers-folder-id-is-bookkeeping-req-rffu-002
	 */
	public function record(int $registerId, ?string $expected, string $folderId): bool;

	/**
	 * Whether another register row holds the same folder id, read without RBAC or organisation filters.
	 *
	 * @param string $folderId   The folder id the deleted register recorded.
	 * @param int    $registerId The register being deleted.
	 *
	 * @return bool True when another register row holds the same folder id.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function isRecordedByAnotherRegister(string $folderId, int $registerId): bool;
}//end interface
