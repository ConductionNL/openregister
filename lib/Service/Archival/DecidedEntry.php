<?php

/**
 * One destruction-list entry and the answer it was decided with.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Archival
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Archival;

/**
 * The entry, the answer as the reviewer gave it, and the answer as the list records it.
 *
 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
 */
final class DecidedEntry {
	/**
	 * Hold the values.
	 *
	 * @param string         $entryUuid The uuid of the object the entry is about.
	 * @param ReviewerAnswer $given     The answer as the reviewer gave it, naming the reviewer.
	 * @param ReviewAnswer   $answer    The answer as it is recorded, with its transfer list.
	 */
	public function __construct(
		public readonly string $entryUuid,
		public readonly ReviewerAnswer $given,
		public readonly ReviewAnswer $answer,
	) {
	}//end __construct()
}//end class
