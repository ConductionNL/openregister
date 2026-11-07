<?php

/**
 * AccessLinkReaderInterface: the public surface of AccessLinkReader, for callers to type against.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Files
 * @package  OCA\OpenRegister\Files\Service\Sharing
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

use OCA\OpenRegister\Db\AccessLink;
use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Every public method of AccessLinkReader. Callers depend on this interface, not on the class.
 */
interface AccessLinkReaderInterface {

	/**
	 * The object a link's subject resolves to, or null.
	 *
	 * A file link resolves to the object that carries the file, because a file
	 * has no rules of its own here: it belongs to an object and inherits them.
	 * A view link resolves to no single object, so this answers null for one.
	 *
	 * @param AccessLink $link The link.
	 *
	 * @return ObjectEntity|null The object, or null.
	 *
	 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md#requirement-a-publication-link-opens-one-object-view-or-file-as-its-own-principal-req-abl-001
	 */
	public function subjectObject(AccessLink $link): ?ObjectEntity;

	/**
	 * What the link serves, filtered, or null when the subject is gone.
	 *
	 * @param AccessLink $link The link.
	 * @param ObjectEntity|null $object The subject, when the caller already resolved it.
	 *
	 * @return array<string, mixed>|null The body, or null when there is nothing to serve.
	 *
	 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md#requirement-a-link-never-sees-past-the-objects-own-rules-req-abl-004
	 */
	public function read(AccessLink $link, ?ObjectEntity $object = null): ?array;

	/**
	 * One object, reduced to what an anonymous caller may read.
	 *
	 * Public because a link is not the only surface that answers without a
	 * session: an object share token does too, and it was serving the object
	 * whole, `@self.authorization` included (openregister#3818). Two surfaces
	 * with the same audience get the same projection, from here, rather than a
	 * second allow-list that drifts.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return array<string, mixed> The published projection.
	 *
	 * @spec openspec/changes/public-pages-open-without-a-session/specs/apphost-public-pages/spec.md#requirement-an-anonymous-caller-reads-no-more-than-the-access-link-reader-publishes-req-pub-003
	 */
	public function publish(ObjectEntity $object): array;
}//end interface
