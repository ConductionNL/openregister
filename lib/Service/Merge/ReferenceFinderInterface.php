<?php

/**
 * ReferenceFinderInterface: the objects that reference a record, across the instance.
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
 * Finds every object that points at one record, read through the relation
 * index with no organisation or owner filter, so a reference in another
 * organisation is relinked too (design D-1).
 *
 * @spec openspec/changes/mdm-merge-relinks-every-reference/specs/mdm-merge/spec.md#requirement-a-merge-relinks-every-reference-to-the-losing-record
 */
interface ReferenceFinderInterface {

	/**
	 * The objects referencing a record.
	 *
	 * @param string $uuid The referenced record's uuid.
	 *
	 * @return iterable<ReferencingObject>
	 *
	 * @spec openspec/changes/mdm-merge-relinks-every-reference/specs/mdm-merge/spec.md#requirement-a-merge-relinks-every-reference-to-the-losing-record
	 */
	public function referencingObjects(string $uuid): iterable;
}//end interface
