<?php

/**
 * A request to mint one calendar feed token.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Calendar
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/calendar-provider/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Calendar;

use OCA\OpenRegister\Db\CalendarFeedToken;

/**
 * Who the token is for, what it covers and how long it lives.
 *
 * The principal is decided at the HTTP boundary and carried here, so the
 * identity a token names stays visible where it is chosen.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Calendar
 *
 * @spec openspec/specs/calendar-provider/spec.md
 */
final readonly class FeedTokenMint {

	/**
	 * Constructor.
	 *
	 * @param string            $userId     The principal the token will name.
	 * @param CalendarFeedToken $draft      The unsaved token: scopeType, scopeId and label.
	 * @param int|null          $ttlSeconds Seconds until the token expires; null never expires.
	 */
	public function __construct(
		public string $userId,
		public CalendarFeedToken $draft,
		public ?int $ttlSeconds = null,
	) {
	}//end __construct()
}//end class
