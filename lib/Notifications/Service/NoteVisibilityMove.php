<?php

/**
 * A note moved across the counter: which note, and its visibility before and after.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Notifications\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/changes/timeline-entry-visibility/specs/object-interactions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service;

/**
 * One visibility move of a note.
 *
 * @psalm-immutable
 *
 * @spec openspec/changes/timeline-entry-visibility/specs/object-interactions/spec.md
 */
final class NoteVisibilityMove {
	/**
	 * Hold the values.
	 *
	 * @param int    $noteId The note that moved.
	 * @param string $from   The value before.
	 * @param string $to     The value after.
	 */
	public function __construct(
		public readonly int $noteId,
		public readonly string $from,
		public readonly string $to,
	) {
	}//end __construct()
}//end class
