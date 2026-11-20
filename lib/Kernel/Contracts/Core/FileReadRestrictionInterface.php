<?php
/**
 * FileReadRestrictionInterface: which files a caller may read, as the file handlers ask it.
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

use OCP\Files\Node;

/**
 * The verdict on restricted files that the file handlers (Core) ask before they list, share or publish a file.
 *
 * The file restrictions (Files) answer it and bind the answer in their own registrar.
 *
 * @spec openspec/specs/file-actions/spec.md#requirement-a-restricted-file-is-readable-only-by-its-listed-principals-administrators-and-admitted-callers-req-pfrr-002
 */
interface FileReadRestrictionInterface {

	/**
	 * The verdict on several files at once, with one lookup.
	 *
	 * @param list<int> $fileIds The Nextcloud file ids.
	 *
	 * @return array<int, bool> The verdict per file id.
	 *
	 * @spec openspec/specs/file-actions/spec.md#requirement-a-restricted-file-is-readable-only-by-its-listed-principals-administrators-and-admitted-callers-req-pfrr-002
	 */
	public function readable(array $fileIds): array;

	/**
	 * Keep only the nodes the caller may read, in their order.
	 *
	 * @param array<int|string, Node> $nodes The nodes.
	 *
	 * @return list<Node> The readable nodes.
	 *
	 * @spec openspec/specs/file-actions/spec.md#requirement-every-openregister-file-path-honours-the-restriction-req-pfrr-003
	 */
	public function readableNodes(array $nodes): array;

	/**
	 * Whether a file carries a restriction at all, whoever asks. A failed lookup answers true.
	 *
	 * @param int $fileId The Nextcloud file id.
	 *
	 * @return bool True when restricted, or when that cannot be told.
	 *
	 * @spec openspec/specs/file-actions/spec.md#requirement-a-restricted-file-has-no-public-link-req-pfrr-004
	 */
	public function isRestricted(int $fileId): bool;
}//end interface
