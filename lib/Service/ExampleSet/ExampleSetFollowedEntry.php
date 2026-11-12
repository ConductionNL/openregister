<?php

/**
 * OpenRegister Example Set Followed Entry
 *
 * A held change or preference entry and what following its declaration made of it.
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
 * @spec openspec/specs/example-sets/spec.md#requirement-a-held-change-or-preference-follows-its-declaration
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

/**
 * A held entry, by its record part and key, and what follow() made of it.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-held-change-or-preference-follows-its-declaration
 */
final class ExampleSetFollowedEntry {
	/**
	 * Constructor.
	 *
	 * @param string                                                         $part `changes` or `userPreferences`.
	 * @param string                                                         $name The record key.
	 * @param array{error: string, entry: array<string, mixed>, wrote: bool} $made What follow() made.
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
