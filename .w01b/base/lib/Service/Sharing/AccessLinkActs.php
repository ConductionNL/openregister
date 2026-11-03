<?php

/**
 * Performs the two writes a link holder may make, attributed to the link.
 *
 * A holder with no account can leave a comment and add a file, when the link
 * declares it. Both of those are writes by somebody who is not a user, so
 * neither can go through a path that reaches for a session.
 *
 * A comment is written with a distinct comment ACTOR TYPE, never `users`, so a
 * note left through a link can never be mistaken for one left by an account
 * that happens to share the id. It is always written as a PUBLIC timeline
 * entry: a holder with no account cannot write into the internal half of a
 * record, because the internal half is the half the organisation talks to
 * itself in.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Sharing
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Sharing;

use OCA\OpenRegister\Db\AccessLink;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\NoteService;
use OCA\OpenRegister\Service\TimelineVisibilityService;

/**
 * The comment and the upload a link may make.
 *
 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md#requirement-a-link-declares-its-capabilities-carries-an-expiry-and-may-carry-a-password-req-abl-002
 */
class AccessLinkActs {

	/**
	 * The comment actor type a link writes under.
	 *
	 * @var string
	 */
	public const LINK_ACTOR_TYPE = 'openregister_links';

	/**
	 * Constructor.
	 *
	 * @param NoteService $notes Writes the comment.
	 * @param FileService $files Stores the file.
	 */
	public function __construct(
		private readonly NoteService $notes,
		private readonly FileService $files,
	) {

	}//end __construct()

	/**
	 * Leave a comment on the object, as the link.
	 *
	 * @param AccessLink $link The link acting.
	 * @param ObjectEntity $object The object commented on.
	 * @param string $message The comment.
	 *
	 * @return array<string, mixed> The created note.
	 *
	 * @throws \Exception When the comment cannot be written.
	 *
	 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md#requirement-a-link-declares-its-capabilities-carries-an-expiry-and-may-carry-a-password-req-abl-002
	 */
	public function comment(AccessLink $link, ObjectEntity $object, string $message): array {
		return $this->notes->createNoteAs(
			objectUuid: (string)$object->getUuid(),
			message: $message,
			actorType: self::LINK_ACTOR_TYPE,
			actorId: $link->principalId(),
			visibility: TimelineVisibilityService::PUBLIC_ENTRY
		);
	}//end comment()

	/**
	 * Add a file to the object, as the link.
	 *
	 * The file is never shared on the way in: a holder adding a document must
	 * not be able to publish it further than the link they were given.
	 *
	 * @param AccessLink $link The link acting.
	 * @param ObjectEntity $object The object the file is added to.
	 * @param string $fileName The file name.
	 * @param string $content The file content.
	 *
	 * @return array<string, mixed> The stored file, formatted.
	 *
	 * @throws \Exception When the file cannot be stored.
	 *
	 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md#requirement-a-link-declares-its-capabilities-carries-an-expiry-and-may-carry-a-password-req-abl-002
	 */
	public function upload(AccessLink $link, ObjectEntity $object, string $fileName, string $content): array {
		unset($link);

		return $this->files->formatFile(
			$this->files->addFile(
				objectEntity: $object,
				fileName: $fileName,
				content: $content,
				share: false
			)
		);
	}//end upload()
}//end class
