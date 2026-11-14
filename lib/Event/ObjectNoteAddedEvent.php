<?php

/**
 * A note was added to an object and is saved.
 *
 * Dispatched by NoteService after the comment is saved, for every note,
 * whoever wrote it: a user, or an access link (actor type
 * `openregister_links`). It is what a `noteAdded` notification rule will fire
 * on (notifications-new-notes-and-referrers, D-1).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Event
 * @package  OCA\OpenRegister\Event
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/notifications-new-notes-and-referrers/design.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Event;

use OCP\EventDispatcher\Event;

/**
 * The object, the note, who wrote it and who may see it.
 *
 * @spec openspec/changes/notifications-new-notes-and-referrers/design.md
 */
class ObjectNoteAddedEvent extends Event {

	/**
	 * Constructor.
	 *
	 * @param string $objectUuid The object the note hangs on.
	 * @param string $noteId     The saved note's id.
	 * @param string $actorType  The author's actor type, e.g. `users` or `openregister_links`.
	 * @param string $actorId    The author's actor id.
	 * @param string $visibility The note's normalised visibility, e.g. `public` or `internal`.
	 *
	 * @spec openspec/changes/notifications-new-notes-and-referrers/design.md
	 */
	public function __construct(
		private readonly string $objectUuid,
		private readonly string $noteId,
		private readonly string $actorType,
		private readonly string $actorId,
		private readonly string $visibility,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * The object the note hangs on.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/notifications-new-notes-and-referrers/design.md
	 */
	public function getObjectUuid(): string {
		return $this->objectUuid;
	}//end getObjectUuid()

	/**
	 * The saved note's id.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/notifications-new-notes-and-referrers/design.md
	 */
	public function getNoteId(): string {
		return $this->noteId;
	}//end getNoteId()

	/**
	 * The author's actor type.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/notifications-new-notes-and-referrers/design.md
	 */
	public function getActorType(): string {
		return $this->actorType;
	}//end getActorType()

	/**
	 * The author's actor id.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/notifications-new-notes-and-referrers/design.md
	 */
	public function getActorId(): string {
		return $this->actorId;
	}//end getActorId()

	/**
	 * The note's normalised visibility.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/notifications-new-notes-and-referrers/design.md
	 */
	public function getVisibility(): string {
		return $this->visibility;
	}//end getVisibility()
}//end class
