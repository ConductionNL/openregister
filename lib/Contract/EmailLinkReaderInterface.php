<?php

/**
 * EmailLinkReaderInterface: the published read of the Nextcloud Mail messages
 * linked to an OpenRegister object.
 *
 * The links live in `openregister_email_links`, which only this app owns. A
 * consuming app that shows an object's mail beside its own activity (a client
 * page's timeline) reads them through this interface instead of the table or
 * the service class, as ADR-084 settled for {@see ObjectServiceInterface}.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Contract
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Contract;

/**
 * Reads the emails linked to an object.
 *
 * @spec openspec/specs/generic-integrations/spec.md
 */
interface EmailLinkReaderInterface {

	/**
	 * The emails linked to an object, newest mail first, a page at a time.
	 *
	 * The caller checks that its user may see the object before it asks: the
	 * read itself is by object uuid and applies no object rights.
	 *
	 * @param string      $objectUuid The object's uuid.
	 * @param string|null $cursor     The `nextCursor` of the previous page; null for the first page.
	 * @param int         $limit      Page size, 1 to 200.
	 *
	 * @return array{items: array<int,array<string,mixed>>, total: int, nextCursor: ?int} Each item has
	 *         id, objectUuid, registerId, schemaId, mailAccountId, mailMessageId, mailMessageUid,
	 *         subject, sender, mailDate (ISO 8601), metadata, linkedBy and linkedAt.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function getLinkedEmails(string $objectUuid, ?string $cursor=null, int $limit=50): array;
}//end interface
