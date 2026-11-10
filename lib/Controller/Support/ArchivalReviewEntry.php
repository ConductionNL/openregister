<?php

/**
 * OpenRegister archival review entry
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

namespace OCA\OpenRegister\Controller\Support;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * One entry of a destruction list, as a review act addresses it.
 *
 * The list, the record the entry is about and the list's own data travel
 * together through every review write: the act changes the data, saves it on
 * the list and records the entry it was about.
 *
 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
 */
final readonly class ArchivalReviewEntry {
	/**
	 * Bundle a destruction list with the entry an act is about.
	 *
	 * @param ObjectEntity         $list     The destruction list object.
	 * @param string               $entryId  The uuid of the record the entry is about.
	 * @param array<string, mixed> $listData The list's own data.
	 *
	 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
	 */
	public function __construct(
		public ObjectEntity $list,
		public string $entryId,
		public array $listData,
	) {
	}//end __construct()
}//end class
