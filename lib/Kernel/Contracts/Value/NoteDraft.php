<?php

/**
 * OpenRegister NoteDraft.
 *
 * A note about to be written on an object: the object, the text and the requested visibility.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Kernel\Contracts\Value
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Value;

/**
 * A note about to be written on an object: the object, the text and the requested visibility.
 *
 * @spec openspec/specs/file-actions/spec.md
 */
final class NoteDraft {
	/**
	 * Constructor.
	 *
	 * @param string      $objectUuid The object the note is written on.
	 * @param string      $message    The note text.
	 * @param string|null $visibility Requested visibility; null for the default.
	 * @param int|null    $parentId   The note this one replies to; null for a top-level note.
	 * @param string|null $readable   The visibility the writer may read (`public` for a caller
	 *                                who may not manage the object); null when they may read every note.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function __construct(
		public readonly string $objectUuid,
		public readonly string $message,
		public readonly ?string $visibility = null,
		public readonly ?int $parentId = null,
		public readonly ?string $readable = null,
	) {
	}//end __construct()
}//end class
