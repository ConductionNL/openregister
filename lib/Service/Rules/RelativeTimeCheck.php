<?php

/**
 * OpenRegister relative time check
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rules
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

namespace OCA\OpenRegister\Service\Rules;

/**
 * One object judged by a relative-time condition, at one moment.
 *
 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/flow-engine/spec.md
 */
final class RelativeTimeCheck {
	/**
	 * Name the check.
	 *
	 * @param array<string, mixed> $document The object.
	 * @param RelativeTimeMoment   $moment   The present moment and the calendar.
	 */
	public function __construct(
		public readonly array $document,
		public readonly RelativeTimeMoment $moment,
	) {
	}//end __construct()
}//end class
