<?php

/**
 * EmailLinkMapperInterface: what callers outside the Db component use of EmailLinkMapper.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
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

namespace OCA\OpenRegister\Db;

use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Entity;

/**
 * The methods of EmailLinkMapper that callers outside the Db component use.
 *
 * Callers type against this interface and resolve it from the container; the
 * registrar binds it to EmailLinkMapper. Guarded by C04InterfaceBindingsTest.
 */
interface EmailLinkMapperInterface {

	/**
	 * Find a single email link by primary id.
	 *
	 * QBMapper exposes `find()` only via `@method` docblock; concrete mappers
	 * add it themselves when needed. Wraps the inherited protected
	 * `findEntity()` so callers get a typed 404 path
	 * (`DoesNotExistException`) for unknown ids.
	 *
	 * @param int $id The email link row id.
	 *
	 * @return EmailLink The matching row.
	 *
	 * @throws DoesNotExistException When $id does not resolve.
	 */
	public function find(int $id): EmailLink;

	/**
	 * Find email links by object UUID.
	 *
	 * @param string $objectUuid The object UUID.
	 * @param int|null $limit Maximum results.
	 * @param int|null $offset Results offset.
	 *
	 * @return EmailLink[] Array of email links.
	 */
	public function findByObjectUuid(string $objectUuid, ?int $limit = null, ?int $offset = null): array;

	/**
	 * One page of an object's email links, newest mail first.
	 *
	 * @param string    $objectUuid The object UUID.
	 * @param ListQuery $query      The page; unbounded for every link.
	 *
	 * @return EmailLink[] Array of email links.
	 *
	 * @spec exclude ADR-011 ListQuery variant of findByObjectUuid(), no capability of its own
	 */
	public function findByObject(string $objectUuid, ListQuery $query): array;

	/**
	 * Find email links by sender address.
	 *
	 * @param string $sender The sender email address.
	 *
	 * @return EmailLink[] Array of email links.
	 */
	public function findBySender(string $sender): array;

	/**
	 * The object uuids linked to a mail, by its RFC `Message-ID`.
	 *
	 * @param string $rfcMessageId The `Message-ID`, with its angle brackets.
	 *
	 * @return string[] The object uuids, each once.
	 *
	 * @spec openspec/changes/reply-threading-by-headers/specs/integration-email/spec.md
	 */
	public function objectUuidsByRfcMessageId(string $rfcMessageId): array;

	/**
	 * Find a specific email link by object UUID and mail message ID.
	 *
	 * @param string $objectUuid The object UUID.
	 * @param int $mailMessageId The mail message ID.
	 *
	 * @return EmailLink|null The link or null if not found.
	 */
	public function findByObjectAndMessage(string $objectUuid, int $mailMessageId): ?EmailLink;

	/**
	 * Find a specific email link by the full composite tuple.
	 *
	 * Mirrors the unique constraint added in
	 * `Version1Date20260525100000`. Returns the row matching all four
	 * coordinates (UUID + accountId + messageId + messageUid) so the
	 * Tier-2 upsert in `EmailLinkService::linkEmail()` is idempotent
	 * across re-syncs where Mail bumps the UID for the same message.
	 *
	 * @param string         $objectUuid The object UUID.
	 * @param MailMessageKey $message    The account, message id and message UID.
	 *
	 * @return EmailLink|null The link or null if not found.
	 */
	public function findByObjectAccountMessageUid(string $objectUuid, MailMessageKey $message): ?EmailLink;

	/**
	 * Delete a link by its primary key on a per-object basis.
	 *
	 * The object_uuid guard prevents a UI mistake (or a malicious
	 * client) from deleting a row that belongs to a different object.
	 *
	 * @param string $objectUuid The object UUID owning the link.
	 * @param int $linkId The link primary key.
	 *
	 * @return int Number of rows deleted (0 or 1).
	 */
	public function deleteByObjectAndId(string $objectUuid, int $linkId): int;

	/**
	 * Delete all links of an object.
	 *
	 * @param string $objectUuid The object UUID.
	 *
	 * @return int Number of deleted rows.
	 *
	 * @spec exclude shared link-mapper plumbing, not a capability of its own
	 */
	public function deleteByObjectUuid(string $objectUuid): int;

	/**
	 * Count the links of an object.
	 *
	 * @param string $objectUuid The object UUID.
	 *
	 * @return int Count of links.
	 *
	 * @spec exclude shared link-mapper plumbing, not a capability of its own
	 */
	public function countByObjectUuid(string $objectUuid): int;

	/**
	 * Insert one row.
	 *
	 * @param Entity $entity The row to insert.
	 *
	 * @return EmailLink The inserted row, with its id.
	 */
	public function insert(Entity $entity): Entity;

	/**
	 * Delete one row.
	 *
	 * @param Entity $entity The row to delete.
	 *
	 * @return EmailLink The deleted row.
	 */
	public function delete(Entity $entity): Entity;
}//end interface
