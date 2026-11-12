<?php

/**
 * OpenRegister relations domain
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
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

namespace OCA\OpenRegister\Controller\Support;

use OCA\OpenRegister\Kernel\Contracts\ObjectCalendarEventsInterface;
use OCA\OpenRegister\Service\ContactServiceInterface;
use OCA\OpenRegister\Service\DeckCardServiceInterface;

/**
 * The domain services of the relations API.
 *
 * @spec openspec/changes/retrofit-2026-05-24-data-integrity-relations/tasks.md#task-1
 */
final class RelationsCollaboration {
	/**
	 * Bundle the services.
	 *
	 * @param ObjectCalendarEventsInterface $calendarEventService Calendar event service
	 * @param ContactServiceInterface       $contactService       Contact service
	 * @param DeckCardServiceInterface      $deckCardService      Deck card service
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-data-integrity-relations/tasks.md#task-1
	 */
	public function __construct(
		public readonly ObjectCalendarEventsInterface $calendarEventService,
		public readonly ContactServiceInterface $contactService,
		public readonly DeckCardServiceInterface $deckCardService,
	) {
	}//end __construct()
}//end class
