<?php

/**
 * AccessLinkActsInterface: what callers outside the component use of AccessLinkActs.
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

/**
 * Performs a comment or an upload on an object, as the access link.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own.
 */
interface AccessLinkActsInterface {

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
	public function comment(AccessLink $link, ObjectEntity $object, string $message): array;

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
	public function upload(AccessLink $link, ObjectEntity $object, string $fileName, string $content): array;
}//end interface
