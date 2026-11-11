<?php

/**
 * OpenRegister ObjectNotesQuery.
 *
 * One read of the notes on an object: the object, the page and the
 * visibility the reader may see.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Files\Service
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

namespace OCA\OpenRegister\Files\Service;

use OCA\OpenRegister\Kernel\Contracts\ListQuery;

/**
 * One read of the notes on an object.
 *
 * @spec openspec/specs/file-actions/spec.md
 */
final class ObjectNotesQuery {
	/**
	 * Constructor.
	 *
	 * @param string      $objectUuid The object the notes are written on.
	 * @param ListQuery   $page       The limit and offset of the page.
	 * @param string|null $visibility Only notes with this visibility; null for every note.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function __construct(
		public readonly string $objectUuid,
		public readonly ListQuery $page,
		public readonly ?string $visibility = null,
	) {
	}//end __construct()
}//end class
