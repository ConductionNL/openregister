<?php

/**
 * TimelineEntryScope: which entries a cross-object timeline read may return.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Db;

/**
 * The objects, the kind and the page size of a read that spans objects.
 *
 * `$objectUuids` has three states and they are deliberately not two. `null`
 * means "do not narrow by object": the caller resolves access per row. An
 * EMPTY ARRAY means "narrow to nothing" and the read returns nothing, which is
 * what a subject-scoped reader with an empty scope must get. Collapsing those
 * two onto one empty value is how an access failure turns into a full read.
 *
 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
 */
final class TimelineEntryScope {
	/**
	 * Constructor.
	 *
	 * @param array<int,string>|null $objectUuids The objects to narrow to; null does not narrow, [] narrows to nothing.
	 * @param string|null            $kind        Narrow to one kind, or null for every kind.
	 * @param integer                $limit       Page size.
	 */
	public function __construct(
		public readonly ?array $objectUuids = null,
		public readonly ?string $kind = null,
		public readonly int $limit = 50,
	) {
	}//end __construct()

	/**
	 * Whether the scope names an empty set of objects, so nothing may be read.
	 *
	 * @return boolean True for an empty object list; false for null or a non-empty list.
	 *
	 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
	 */
	public function narrowsToNothing(): bool {
		return (is_array($this->objectUuids) === true && $this->objectUuids === []);
	}//end narrowsToNothing()
}//end class
