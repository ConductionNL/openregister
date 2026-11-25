<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use Exception;
use LogicException;
use OCA\OpenRegister\Db\EmailLink;
use OCA\OpenRegister\Service\EmailLinkServiceInterface;
use OCA\OpenRegister\Service\EmailMessageRef;
use OCA\OpenRegister\Service\LinkTarget;
use OCA\OpenRegister\Service\MailboxPage;

/**
 * An email link service that owns no Mail account for anybody; every other call is a test error.
 */
final class RefusingEmailLinks implements EmailLinkServiceInterface {
	/**
	 * @return bool
	 */
	public function isMailAvailable(): bool {
		return true;
	}//end isMailAvailable()

	/**
	 * @param LinkTarget      $target  The object.
	 * @param EmailMessageRef $message The message.
	 *
	 * @return EmailLink
	 */
	public function linkEmail(LinkTarget $target, EmailMessageRef $message): EmailLink {
		throw new LogicException('not used');
	}//end linkEmail()

	/**
	 * @param string  $objectUuid The object.
	 * @param integer $linkId     The link.
	 *
	 * @return void
	 */
	public function unlinkEmail(string $objectUuid, int $linkId): void {
		throw new LogicException('not used');
	}//end unlinkEmail()

	/**
	 * @return array<int, mixed>
	 */
	public function getAvailableAccounts(): array {
		return [];
	}//end getAvailableAccounts()

	/**
	 * @param integer $accountId The account.
	 *
	 * @return array<int, mixed>
	 */
	public function getMailboxesForAccount(int $accountId): array {
		return [];
	}//end getMailboxesForAccount()

	/**
	 * @param MailboxPage $page The page.
	 *
	 * @return array<string, mixed>
	 */
	public function getMessagesForMailbox(MailboxPage $page): array {
		return [];
	}//end getMessagesForMailbox()

	/**
	 * @param integer $accountId The account.
	 *
	 * @return void
	 *
	 * @throws Exception Always: no account is anybody's.
	 */
	public function assertAccountOwnership(int $accountId): void {
		throw new Exception('Mail account not accessible to this user', 403);
	}//end assertAccountOwnership()

	/**
	 * @param string      $objectUuid The object.
	 * @param string|null $cursor     The cursor.
	 * @param integer     $limit      The page size.
	 *
	 * @return array<string, mixed>
	 */
	public function getLinkedEmails(string $objectUuid, ?string $cursor = null, int $limit = 50): array {
		return [];
	}//end getLinkedEmails()
}//end class
