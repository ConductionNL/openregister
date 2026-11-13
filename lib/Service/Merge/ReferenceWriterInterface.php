<?php

/**
 * ReferenceWriterInterface: writes one reference move through the save path.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Merge
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Merge;

/**
 * Writes a reference move through the save path, as the merging user, so the
 * user's rights, the schema's validation and the audit trail all apply. A move
 * the user may not make is left, reported, never forced (design D-1).
 *
 * @spec openspec/changes/mdm-merge-relinks-every-reference/specs/mdm-merge/spec.md#requirement-a-merge-relinks-every-reference-to-the-losing-record
 */
interface ReferenceWriterInterface {

	/**
	 * Apply one move as the actor; false when the actor may not update the object.
	 *
	 * @param ReferenceMove $move  The field to rewrite, with its new value.
	 * @param string        $actor The merging user.
	 *
	 * @return bool True when the move was written, false when it was refused by the actor's rights.
	 *
	 * @spec openspec/changes/mdm-merge-relinks-every-reference/specs/mdm-merge/spec.md#requirement-a-merge-relinks-every-reference-to-the-losing-record
	 */
	public function write(ReferenceMove $move, string $actor): bool;
}//end interface
