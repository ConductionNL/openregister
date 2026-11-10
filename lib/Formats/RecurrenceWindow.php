<?php

/**
 * OpenRegister recurrence window
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Formats
 * @package  OCA\OpenRegister\Formats
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

namespace OCA\OpenRegister\Formats;

use DateTimeInterface;

/**
 * The next occurrences of an RFC 5545 RRULE: the rule, how many, and from when.
 *
 * @spec openspec/specs/extended-field-types/spec.md#REQ-EFT-003
 */
final class RecurrenceWindow {
	/**
	 * Constructor.
	 *
	 * @param string                 $rrule The RFC 5545 RRULE string.
	 * @param int                    $count The number of occurrences to emit.
	 * @param DateTimeInterface|null $start The iteration start, null for now.
	 */
	public function __construct(
		public readonly string $rrule,
		public readonly int $count,
		public readonly ?DateTimeInterface $start = null,
	) {
	}//end __construct()
}//end class
