<?php

/**
 * ExampleSetTimelineEntriesInterface: the public surface of ExampleSetTimelineEntries, for callers to type against.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Notifications
 * @package  OCA\OpenRegister\Notifications\Service\ExampleSet
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

namespace OCA\OpenRegister\Notifications\Service\ExampleSet;

/**
 * Every public method of ExampleSetTimelineEntries. Callers depend on this interface, not on the class.
 */
interface ExampleSetTimelineEntriesInterface {

	/**
	 * Whether a written entry is gone.
	 *
	 * @param array<string, mixed> $entry The record entry.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-give-its-objects-timeline-entries-by-named-people
	 */
	public function lost(array $entry): bool;

	/**
	 * Delete the entry the set wrote.
	 *
	 * @param array<string, mixed> $entry The record entry.
	 *
	 * @return string `removed` or `failed`.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-give-its-objects-timeline-entries-by-named-people
	 */
	public function revert(array $entry): string;
}//end interface
