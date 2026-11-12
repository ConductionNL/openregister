<?php

/**
 * The entry whose text is scanned for references, and the object it hangs on.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Notifications\Service\Timeline
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service\Timeline;

/**
 * Where a set of references comes from: one entry, its object and its text.
 *
 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
 */
final class ReferenceSource {
	/**
	 * Constructor.
	 *
	 * @param string      $entryUuid  The entry.
	 * @param string      $sourceUuid The object the entry hangs on.
	 * @param string|null $text       The entry text.
	 */
	public function __construct(
		public readonly string $entryUuid,
		public readonly string $sourceUuid,
		public readonly ?string $text,
	) {
	}//end __construct()
}//end class
