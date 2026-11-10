<?php

/**
 * OpenRegister Example Set Made Entry
 *
 * What writing one declared entry made (a change, an app config key, a user
 * preference): the record part, the entry's name, and the error or the entry.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ExampleSet
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-an-install-proves-what-arrived-req-exs-006
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

/**
 * One write of a declared entry and what it made.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-an-install-proves-what-arrived-req-exs-006
 */
final class ExampleSetMadeEntry {
	/**
	 * Constructor.
	 *
	 * @param string                                               $part The record part: `changes`, `appConfig` or `userPreferences`.
	 * @param string                                               $name The entry's record name.
	 * @param array{error: string, entry: array<string, mixed>|null} $made Why the write failed, or the entry it made (null: nothing to keep).
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $part,
		public readonly string $name,
		public readonly array $made,
	) {
	}//end __construct()
}//end class
