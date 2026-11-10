<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\ContactLink;
use OCA\OpenRegister\Event\PersonLinkedEvent;
use OCA\OpenRegister\Event\PersonLinkUpdatedEvent;
use OCA\OpenRegister\Event\PersonUnlinkedEvent;
use OCP\EventDispatcher\IEventDispatcher;

/**
 * Announces what happened to a person's link on an object.
 *
 * The three events of people-on-objects in one place, so the link service
 * itself carries one dependency rather than four.
 *
 * @spec openspec/changes/people-on-objects/specs/people-on-objects/spec.md#requirement-link-changes-are-events
 */
class PersonLinkEvents {

	/**
	 * @param IEventDispatcher $events The event bus.
	 */
	public function __construct(
		private readonly IEventDispatcher $events,
	) {
	}//end __construct()

	/**
	 * A person was linked to an object.
	 *
	 * @param ContactLink $link The link as stored.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/people-on-objects/specs/people-on-objects/spec.md#requirement-link-changes-are-events
	 */
	public function linked(ContactLink $link): void {
		$this->events->dispatchTyped(new PersonLinkedEvent(link: $link));
	}//end linked()

	/**
	 * A person's link changed: its role, validity or note.
	 *
	 * @param ContactLink $link The link as stored.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/people-on-objects/specs/people-on-objects/spec.md#requirement-link-changes-are-events
	 */
	public function updated(ContactLink $link): void {
		$this->events->dispatchTyped(new PersonLinkUpdatedEvent(link: $link));
	}//end updated()

	/**
	 * A person's link was removed.
	 *
	 * @param ContactLink $link The link as it was.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/people-on-objects/specs/people-on-objects/spec.md#requirement-link-changes-are-events
	 */
	public function unlinked(ContactLink $link): void {
		$this->events->dispatchTyped(new PersonUnlinkedEvent(link: $link));
	}//end unlinked()
}//end class
