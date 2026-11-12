<?php

/**
 * EmailLinkServiceInterface: the email-link operations of EmailLinkService that controllers use, on top of the EmailLinkReaderInterface contract.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
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

namespace OCA\OpenRegister\Service;

use Exception;
use OCA\OpenRegister\Contract\EmailLinkReaderInterface;
use OCA\OpenRegister\Db\EmailLink;

/**
 * The methods of EmailLinkService that callers outside the Service component use;
 * getLinkedEmails() comes from EmailLinkReaderInterface.
 */
interface EmailLinkServiceInterface extends EmailLinkReaderInterface {

	/**
	 * Whether NC Mail is installed + enabled for the current user.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function isMailAvailable(): bool;

	/**
	 * Link an existing Mail message to an OR object (idempotent upsert).
	 *
	 * Matches on the full composite tuple
	 * `(objectUuid, mailAccountId, mailMessageId, mailMessageUid)` so a
	 * re-sync that bumps the UID for the same message does not create a
	 * duplicate. When the row already exists it is returned unchanged;
	 * otherwise a fresh row is inserted with subject/sender/sent-at
	 * harvested from Mail's tables at link-time so the list path can
	 * render without a join.
	 *
	 * @param LinkTarget      $target  The object to link to.
	 * @param EmailMessageRef $message The Mail message (account, id, optional uid).
	 *
	 * @return EmailLink The persisted link row.
	 *
	 * @throws Exception On missing user (401), Mail unavailable (503),
	 *                   or message-not-found (404).
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function linkEmail(LinkTarget $target, EmailMessageRef $message): EmailLink;

	/**
	 * Unlink a Mail message from an OR object.
	 *
	 * @param string $objectUuid Parent OR object uuid.
	 * @param int $linkId Link primary key.
	 *
	 * @return void
	 *
	 * @throws Exception When no matching link is found (404).
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function unlinkEmail(string $objectUuid, int $linkId): void;

	/**
	 * Return the IMAP accounts visible to the current user.
	 *
	 * Each row is `{id, label, email}` — the minimum the picker step 1
	 * needs. Backed by `oc_mail_accounts.user_id = <current uid>`.
	 *
	 * @return array<int,array{id:int,label:string,email:string}>
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function getAvailableAccounts(): array;

	/**
	 * Return the mailboxes for an account visible to the current user.
	 *
	 * Step 2 of the picker. Returns `{id, name, displayName}` for the
	 * mailboxes belonging to the account — UI sorts/groups client-side.
	 *
	 * @param int $accountId Mail account id.
	 *
	 * @return array<int,array{id:int,name:string,displayName:string}>
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function getMailboxesForAccount(int $accountId): array;

	/**
	 * Return messages in a mailbox for the 3rd picker step.
	 *
	 * Cursor pagination keyed on the message numeric id. Returned shape
	 * per row is `{id, uid, subject, sender, date}` and the response
	 * wraps `{items, nextCursor}`.
	 *
	 * @param MailboxPage $page The mail account id (ownership-checked), the mailbox path (matches mail_mailboxes.name),
	 *                          the numeric message id to start after (null for the first page) and the page size
	 *                          (clamped to [1, MAX_LIMIT]).
	 *
	 * @return array{items: array<int,array{id:int,uid:string,subject:?string,sender:?string,date:?string}>, nextCursor: ?int}
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function getMessagesForMailbox(MailboxPage $page): array;

	/**
	 * Verify the active user owns the Mail account; throw on mismatch.
	 *
	 * Defence-in-depth — Mail's middleware already enforces this server-side
	 * for its own endpoints, but the picker reaches into the raw tables
	 * so we re-check ownership before exposing any per-account data.
	 *
	 * @param int $accountId Mail account id.
	 *
	 * @return void
	 *
	 * @throws Exception When the active user does not own the account.
	 */
	public function assertAccountOwnership(int $accountId): void;
}//end interface
