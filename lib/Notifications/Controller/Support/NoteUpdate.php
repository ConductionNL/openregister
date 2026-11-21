<?php

/**
 * OpenRegister note update
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Notifications\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Controller\Support;

/**
 * One accepted edit of a note: the note, its new text and visibility, and the
 * visibility it moved from.
 *
 * @spec openspec/specs/object-interactions/spec.md
 */
final class NoteUpdate {
	/**
	 * Constructor.
	 *
	 * @param ObjectNote  $target     The note and the object it hangs on.
	 * @param string|null $message    The new text, or null when the text is unchanged.
	 * @param string|null $visibility The new visibility, or null when it is unchanged.
	 * @param string|null $previous   The visibility it moved from, or null when it did not move.
	 */
	public function __construct(
		public readonly ObjectNote $target,
		public readonly ?string $message,
		public readonly ?string $visibility,
		public readonly ?string $previous,
	) {
	}//end __construct()
}//end class
