<?php

/**
 * The filters a notification history list is narrowed by, and its moment.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Db
 * @package  OCA\OpenRegister\Notifications\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Db;

use DateTime;

/**
 * One history list question: the filter map and the moment a snooze is judged against.
 *
 * A list and its count build the same filter, so a page never disagrees with its total.
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */
final class HistoryListFilter {
	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $filters The filters, keyed as the mapper's column map plus the state flags.
	 * @param DateTime             $asOf    The moment snoozes are judged against.
	 */
	public function __construct(
		public readonly array $filters,
		public readonly DateTime $asOf,
	) {
	}//end __construct()
}//end class
