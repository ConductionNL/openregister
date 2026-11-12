<?php

/**
 * OpenRegister ObjectRelationCleanupLinks
 *
 * The four link services the relation cleanup and the timeline-link count span.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version GIT: <git-id>
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Notifications\Service\EmailServiceInterface;

/**
 * Bundles the email, calendar-event, contact and deck-card services of
 * ObjectRelationCleanupService: the timeline rows that are not notes and not tasks.
 *
 * @spec openspec/specs/deletion-audit-trail/spec.md
 */
final class ObjectRelationCleanupLinks {
	/**
	 * Bundle the link services.
	 *
	 * @param EmailServiceInterface $emailService         Email-link cleanup.
	 * @param CalendarEventService  $calendarEventService Calendar-event unlinking.
	 * @param ContactService        $contactService       Contact-link cleanup.
	 * @param DeckCardService       $deckCardService      Deck-card-link cleanup.
	 */
	public function __construct(
		public readonly EmailServiceInterface $emailService,
		public readonly CalendarEventService $calendarEventService,
		public readonly ContactService $contactService,
		public readonly DeckCardService $deckCardService,
	) {
	}//end __construct()
}//end class
