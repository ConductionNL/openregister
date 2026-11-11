<?php

/**
 * OpenRegister Mail Table Queries
 *
 * Read queries over the tables of the Nextcloud Mail app.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author	Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link	  https://www.OpenRegister.app
 *
 * @spec openspec/specs/generic-integrations/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCP\DB\QueryBuilder\IParameter;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Builds and runs the account, mailbox and message queries of the Mail picker.
 *
 * @spec openspec/specs/generic-integrations/spec.md
 */
final class MailTableQueries {

	/**
	 * Mail recipient type for "from".
	 *
	 * @var int
	 */
	private const RECIPIENT_TYPE_FROM = 0;

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Database connection.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function __construct(
		private readonly IDBConnection $db,
	) {
	}//end __construct()

	/**
	 * Start a query over Mail messages with their id, uid, subject, send time and sender.
	 *
	 * @return IQueryBuilder The query, joined to nothing yet.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	private function messagesQuery(): IQueryBuilder {
		$qb = $this->db->getQueryBuilder();
		$qb->select('m.id', 'm.uid', 'm.subject', 'm.sent_at')
			->addSelect('r.email AS sender_email')
			->from('mail_messages', 'm');
		return $qb;
	}//end messagesQuery()
	/**
	 * Join the sender recipient row of each message as alias `r`.
	 *
	 * @param IQueryBuilder $qb A query started by messagesQuery().
	 *
	 * @return void
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	private function joinSender(IQueryBuilder $qb): void {
		$qb->leftJoin(
			'm',
			'mail_recipients',
			'r',
			$qb->expr()->andX(
				$qb->expr()->eq('r.message_id', 'm.id'),
				$qb->expr()->eq('r.type', $qb->createNamedParameter(self::RECIPIENT_TYPE_FROM))
			)
		);
	}//end joinSender()
	/**
	 * Build the query for the accounts of a user, by name.
	 *
	 * @param string $userId User id.
	 *
	 * @return IQueryBuilder The query.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function accountsQuery(string $userId): IQueryBuilder {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'name', 'email')
			->from('mail_accounts')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->orderBy('name', 'ASC');
		return $qb;
	}//end accountsQuery()
	/**
	 * Build the query for the mailboxes of an account, by name.
	 *
	 * @param int $accountId Mail account id.
	 *
	 * @return IQueryBuilder The query.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function mailboxesQuery(int $accountId): IQueryBuilder {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'name')
			->from('mail_mailboxes')
			->where($qb->expr()->eq('account_id', $this->intParam(qb: $qb, value: $accountId)))
			->orderBy('name', 'ASC');
		return $qb;
	}//end mailboxesQuery()
	/**
	 * Build the query for the owner of an account.
	 *
	 * @param int $accountId Mail account id.
	 *
	 * @return IQueryBuilder The query.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function accountOwnerQuery(int $accountId): IQueryBuilder {
		$qb = $this->db->getQueryBuilder();
		$qb->select('user_id')
			->from('mail_accounts')
			->where($qb->expr()->eq('id', $this->intParam(qb: $qb, value: $accountId)))
			->setMaxResults(1);
		return $qb;
	}//end accountOwnerQuery()
	/**
	 * Build the query for the id of a named mailbox of an account.
	 *
	 * @param int    $accountId Mail account id.
	 * @param string $mailbox   Mailbox name.
	 *
	 * @return IQueryBuilder The query.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function mailboxIdQuery(int $accountId, string $mailbox): IQueryBuilder {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from('mail_mailboxes')
			->where($qb->expr()->eq('account_id', $this->intParam(qb: $qb, value: $accountId)))
			->andWhere($qb->expr()->eq('name', $qb->createNamedParameter($mailbox)))
			->setMaxResults(1);
		return $qb;
	}//end mailboxIdQuery()
	/**
	 * Build the query for one page of a mailbox, newest first.
	 *
	 * @param int         $mailboxId Mailbox id.
	 * @param MailboxPage $page      The requested page.
	 *
	 * @return IQueryBuilder The query.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function mailboxMessagesQuery(int $mailboxId, MailboxPage $page): IQueryBuilder {
		$qb = $this->messagesQuery();
		$this->joinSender(qb: $qb);
		$qb->where($qb->expr()->eq('m.mailbox_id', $this->intParam(qb: $qb, value: $mailboxId)))
			->orderBy('m.sent_at', 'DESC')
			->addOrderBy('m.id', 'DESC')
			->setMaxResults($page->fetchSize());

		if ($page->cursorId() > 0) {
			$qb->andWhere($qb->expr()->lt('m.id', $this->intParam(qb: $qb, value: $page->cursorId())));
		}

		return $qb;
	}//end mailboxMessagesQuery()
	/**
	 * Build the query for one message of an account, with its sender.
	 *
	 * @param int $accountId Mail account id.
	 * @param int $messageId Mail message id.
	 *
	 * @return IQueryBuilder The query.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function messageQuery(int $accountId, int $messageId): IQueryBuilder {
		$qb = $this->messagesQuery();
		$qb->addSelect('m.message_id');
		$this->joinAccountMailbox(qb: $qb, accountId: $accountId);
		$this->joinSender(qb: $qb);
		$qb->where($qb->expr()->eq('m.id', $this->intParam(qb: $qb, value: $messageId)))->setMaxResults(1);
		return $qb;
	}//end messageQuery()

	/**
	 * Join the mailbox of a message, restricted to one account.
	 *
	 * @param IQueryBuilder $qb        The messages query.
	 * @param int           $accountId The Mail account id.
	 *
	 * @return void
	 */
	private function joinAccountMailbox(IQueryBuilder $qb, int $accountId): void {
		$qb->innerJoin(
			'm',
			'mail_mailboxes',
			'mb',
			$qb->expr()->andX(
				$qb->expr()->eq('mb.id', 'm.mailbox_id'),
				$qb->expr()->eq('mb.account_id', $this->intParam(qb: $qb, value: $accountId))
			)
		);
	}//end joinAccountMailbox()
	/**
	 * A named integer parameter on a query.
	 *
	 * @param IQueryBuilder $qb    The query.
	 * @param int           $value The value.
	 *
	 * @return IParameter The placeholder.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	private function intParam(IQueryBuilder $qb, int $value) {
		return $qb->createNamedParameter($value, IQueryBuilder::PARAM_INT);
	}//end intParam()
	/**
	 * Run a query and return all its rows.
	 *
	 * @param IQueryBuilder $qb The query.
	 *
	 * @return array<int,array<string,mixed>> The rows.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function rowsOf(IQueryBuilder $qb): array {
		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();
		return $rows;
	}//end rowsOf()
	/**
	 * Run a query and return its first row; false when there is none.
	 *
	 * @param IQueryBuilder $qb The query.
	 *
	 * @return array<string,mixed>|false The row.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function firstRowOf(IQueryBuilder $qb): array|false {
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return $row;
	}//end firstRowOf()
}//end class
