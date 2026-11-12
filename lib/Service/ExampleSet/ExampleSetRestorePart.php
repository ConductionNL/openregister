<?php

/**
 * OpenRegister Example Set Restore Part
 *
 * One record part a remove puts back entry by entry (app config, user
 * preferences), and how one entry of it is reverted.
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
 * @spec openspec/specs/example-sets/spec.md#requirement-remove-deletes-exactly-what-the-install-made-req-exs-007
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

use Closure;

/**
 * A record part to restore and its revert.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-remove-deletes-exactly-what-the-install-made-req-exs-007
 */
final class ExampleSetRestorePart {
	/**
	 * Constructor.
	 *
	 * @param string  $part   The record part: `appConfig` or `userPreferences`.
	 * @param Closure $revert Reverts one entry: `removed`, `left` or `failed`.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $part,
		public readonly Closure $revert,
	) {
	}//end __construct()

	/**
	 * The report count this part adds to.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-remove-deletes-exactly-what-the-install-made-req-exs-007
	 */
	public function count(): string {
		if ($this->part === 'appConfig') {
			return 'app config';
		}

		return 'user preferences';
	}//end count()
}//end class
