<?php

/**
 * Where one destruction copy is written, and what its failure names.
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
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md#requirement-a-destruction-writes-a-restorable-copy-first-req-ipc-004
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Archival;

/**
 * The path of one copy, the configured location and the object it is a copy of.
 *
 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md#requirement-a-destruction-writes-a-restorable-copy-first-req-ipc-004
 */
final class DestructionCopyTarget {

	/**
	 * Constructor.
	 *
	 * @param string $path     The file the copy is written to.
	 * @param string $location The configured copy location, as a failure names it.
	 * @param string $uuid     The object the copy is of.
	 */
	public function __construct(
		public readonly string $path,
		public readonly string $location,
		public readonly string $uuid,
	) {
	}//end __construct()
}//end class
