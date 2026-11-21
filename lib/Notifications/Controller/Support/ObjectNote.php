<?php

/**
 * OpenRegister object note
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

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * One note on an object: the object it hangs on and the note's id.
 *
 * @spec openspec/specs/object-interactions/spec.md
 */
final class ObjectNote {
	/**
	 * Constructor.
	 *
	 * @param ObjectEntity $object The object the note hangs on.
	 * @param int          $noteId The note.
	 */
	public function __construct(
		public readonly ObjectEntity $object,
		public readonly int $noteId,
	) {
	}//end __construct()
}//end class
