<?php

/**
 * TimelineVisibilityServiceInterface: the public surface of TimelineVisibilityService, for callers to type against.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Notifications
 * @package  OCA\OpenRegister\Notifications\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Every public method of TimelineVisibilityService. Callers depend on this interface, not on the class.
 */
interface TimelineVisibilityServiceInterface {

	/**
	 * The default: an entry nobody flagged stays behind the counter.
	 *
	 * @var string
	 */
	public const INTERNAL = 'internal';

	/**
	 * The flag that lets an entry reach a citizen.
	 *
	 * @var string
	 */
	public const PUBLIC_ENTRY = 'public';

	/**
	 * Normalise any incoming value to one of the two flags.
	 *
	 * Anything that is not exactly `public` reads as `internal`: the default is
	 * applied at read time as well as at write time, so an entry stored before
	 * the flag existed, or with a value from a future vocabulary, stays behind
	 * the counter.
	 *
	 * @param string|null $value The raw value, from a payload or from storage.
	 *
	 * @return string Either `internal` or `public`.
	 *
	 * @spec openspec/changes/timeline-entry-visibility/specs/object-interactions/spec.md
	 */
	public function normalise(?string $value): string;

	/**
	 * Whether the caller may set or change the flag on this object's entries.
	 *
	 * The right to publish an entry is the right to update the object: a
	 * reader never holds it, and the portal's subject-scoped reader never
	 * holds it either. Fails CLOSED — a schema that cannot be resolved is a
	 * refusal, never an allow.
	 *
	 * @param ObjectEntity|null $object The object the entry hangs on.
	 *
	 * @return boolean True when the caller holds `update` on the object.
	 *
	 * @spec openspec/changes/timeline-entry-visibility/specs/object-interactions/spec.md
	 */
	public function mayManage(?ObjectEntity $object): bool;

	/**
	 * The filter a read actually gets, whatever it asked for.
	 *
	 * A caller without `update` on the object is served the public view even
	 * when the query parameter is absent or says `internal`, so the filter
	 * cannot be bypassed by omitting it. A caller with `update` gets the
	 * filter it asked for, and no filter when it asked for none.
	 *
	 * @param ObjectEntity|null $object    The object whose timeline is read.
	 * @param string|null       $requested The `visibility` query parameter, when given.
	 *
	 * @return string|null `public`, `internal`, or null for the unfiltered view.
	 *
	 * @spec openspec/changes/timeline-entry-visibility/specs/integration-activity/spec.md
	 */
	public function effectiveFilter(?ObjectEntity $object, ?string $requested): ?string;

	/**
	 * Keep only the rows a filter admits.
	 *
	 * Rows are read through {@see normalise()}, so a row that never declared a
	 * flag counts as internal here too.
	 *
	 * @param array<int,array<string,mixed>> $rows   Timeline rows.
	 * @param string|null                    $filter The effective filter, or null for everything.
	 *
	 * @return array<int,mixed> The admitted rows, re-indexed.
	 *
	 * @spec openspec/changes/timeline-entry-visibility/specs/integration-activity/spec.md
	 */
	public function filterRows(array $rows, ?string $filter): array;

	/**
	 * Whether a leaf renders timeline entries and is therefore filtered.
	 *
	 * The two leaves that draw the timeline are notes and activity. Every other
	 * leaf answers exactly as it did: a file list or a deck board is not a
	 * timeline entry, and treating one as internal would empty a reader's
	 * screen for no gain.
	 *
	 * @param string $integrationId The leaf id being listed.
	 *
	 * @return boolean True when the leaf's rows carry a timeline visibility.
	 *
	 * @spec openspec/changes/timeline-entry-visibility/specs/integration-activity/spec.md
	 */
	public function isTimelineIntegration(string $integrationId): bool;
}//end interface
