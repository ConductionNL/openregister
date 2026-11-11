<?php

/**
 * EmailService
 *
 * Service that wraps Nextcloud Mail message lookups and manages email-to-object links.
 * Emails are immutable; this service only creates/removes link references.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 *
 * @spec openspec/specs/mail-sidebar/spec.md#requirement-reverse-lookup-api-to-find-objects-by-mail-message-id
 * @spec openspec/specs/mail-sidebar/spec.md
 * @spec openspec/specs/mail-sidebar/spec.md
 * @spec openspec/specs/mail-sidebar/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service;

use DateTime;
use Exception;
use OCA\OpenRegister\Db\EmailLink;
use OCA\OpenRegister\Db\EmailLinkMapperInterface;
use OCA\OpenRegister\Db\MagicMapper\MagicRelationReaderInterface;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Kernel\Contracts\ListQuery;

/**
 * EmailService manages email-to-object links via the openregister_email_links table.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class EmailService implements EmailServiceInterface {

	/**
	 * Constructor.
	 *
	 * @param EmailLinkMapperInterface $emailLinkMapper Email link mapper.
	 * @param EmailMailAppReader       $mailApp         App manager, database connection and user session for the Mail app.
	 * @param EmailLinkedSchemas       $linkedSchemas   Schema and object mappers for the mail-linked schemas.
	 * @param LoggerInterface          $logger          Logger.
	 */
	public function __construct(
		private readonly EmailLinkMapperInterface $emailLinkMapper,
		private readonly EmailMailAppReader $mailApp,
		private readonly EmailLinkedSchemas $linkedSchemas,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The magic mapper in its one role here: cross-table `_mail` containment reads.
	 *
	 * @return MagicRelationReaderInterface The relation reader.
	 */
	private function relations(): MagicRelationReaderInterface {
		return $this->linkedSchemas->magicMapper;
	}//end relations()

	/**
	 * Check if the Nextcloud Mail app is installed and enabled.
	 *
	 * @return bool True if Mail app is available.
	 */
	public function isMailAvailable(): bool {
		return $this->mailApp->appManager->isEnabledForUser('mail');
	}//end isMailAvailable()

	/**
	 * Get all email links for an object.
	 *
	 * @param string    $objectUuid The object UUID.
	 * @param ListQuery $query      The page: limit (unbounded for all) and offset.
	 *
	 * @return array{results: array, total: int} Email links with total count.
	 *
	 * @spec openspec/specs/mail-sidebar/spec.md
	 */
	public function getEmailsForObject(string $objectUuid, ListQuery $query = new ListQuery(limit: ListQuery::UNBOUNDED)): array {
		$links = $this->emailLinkMapper->findByObjectUuid($objectUuid, $query->limitOrNull(), $query->offset);
		$total = $this->emailLinkMapper->countByObjectUuid($objectUuid);

		return ['results' => array_map(static fn (EmailLink $link): array => $link->jsonSerialize(), $links), 'total' => $total];
	}//end getEmailsForObject()

	/**
	 * Link an existing email to an object.
	 *
	 * @param EmailLinkRequest $request The object uuid, its register id, and the mail account and message ids.
	 *
	 * @return EmailLink The created link.
	 *
	 * @throws Exception If the email does not exist or is already linked.
	 *
	 * @spec openspec/specs/mail-sidebar/spec.md
	 */
	public function linkEmail(EmailLinkRequest $request): EmailLink {
		return $this->link(request: $request);
	}//end linkEmail()

	/**
	 * Store the link a request asks for, as the signed-in user.
	 *
	 * @param EmailLinkRequest $request The object and the Mail message to link.
	 *
	 * @return EmailLink The created link.
	 *
	 * @throws Exception If the email does not exist, is already linked, or nobody is signed in.
	 */
	private function link(EmailLinkRequest $request): EmailLink {
		// Check for duplicate.
		$existing = $this->emailLinkMapper->findByObjectAndMessage($request->objectUuid, $request->mailMessageId);
		if ($existing !== null) {
			throw new Exception('Email already linked to this object', 409);
		}

		$messageData = $this->requireMailMessage(request: $request);

		$user = $this->mailApp->userSession->getUser();
		if ($user === null) {
			throw new Exception('No user logged in');
		}

		$link = $this->newLink(request: $request, messageData: $messageData);
		$link->setLinkedBy($user->getUID());
		$link->setLinkedAt(new DateTime());

		return $this->emailLinkMapper->insert($link);
	}//end link()

	/**
	 * The Mail message a request names, refusing one the Mail app database does not have.
	 *
	 * @param EmailLinkRequest $request The object and the Mail message to link.
	 *
	 * @return array<string, mixed> The message data.
	 *
	 * @throws Exception If the message is not found (404).
	 */
	private function requireMailMessage(EmailLinkRequest $request): array {
		// Verify the email exists in the Mail app database.
		$messageData = $this->fetchMailMessage(messageId: $request->mailMessageId, accountId: $request->mailAccountId);
		if ($messageData === null) {
			throw new Exception('Mail message not found', 404);
		}

		return $messageData;
	}//end requireMailMessage()

	/**
	 * A new link row for a request, carrying the Mail message's header fields.
	 *
	 * @param EmailLinkRequest     $request     The object and the Mail message to link.
	 * @param array<string, mixed> $messageData The message as the Mail app stores it.
	 *
	 * @return EmailLink The unsaved link.
	 */
	private function newLink(EmailLinkRequest $request, array $messageData): EmailLink {
		$link = new EmailLink();
		$link->setObjectUuid($request->objectUuid);
		$link->setRegisterId($request->registerId);
		$link->setMailAccountId($request->mailAccountId);
		$link->setMailMessageId($request->mailMessageId);
		$this->copyMessageHeaders(link: $link, messageData: $messageData);

		return $link;
	}//end newLink()

	/**
	 * Copy a Mail message's header fields onto a link.
	 *
	 * @param EmailLink            $link        The unsaved link.
	 * @param array<string, mixed> $messageData The message as the Mail app stores it.
	 *
	 * @return void
	 */
	private function copyMessageHeaders(EmailLink $link, array $messageData): void {
		$link->setMailMessageUid($messageData['uid'] ?? null);
		$link->setRfcMessageId(EmailLink::storableMessageId(messageId: ($messageData['rfcMessageId'] ?? null)));
		$link->setSubject($messageData['subject'] ?? null);
		$link->setSender($messageData['sender'] ?? null);
		$link->setSenderName($messageData['senderName'] ?? null);
		if (isset($messageData['date']) === true) {
			$link->setMailDate(new DateTime($messageData['date']));
		}
	}//end copyMessageHeaders()

	/**
	 * Remove an email link.
	 *
	 * @param int $linkId The link ID.
	 *
	 * @return void
	 *
	 * @throws Exception If the link is not found.
	 *
	 * @spec exclude Legacy link-table delete by id; email link infrastructure being removed per linked-entity-types.
	 */
	public function unlinkEmail(int $linkId): void {
		try {
			$link = $this->emailLinkMapper->find($linkId);
			$this->emailLinkMapper->delete($link);
		} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
			throw new Exception('Email link not found', 404);
		}
	}//end unlinkEmail()

	/**
	 * Search email links by sender.
	 *
	 * @param string $sender The sender email address.
	 *
	 * @return array Array of email links with object UUIDs.
	 *
	 * @spec openspec/specs/mail-sidebar/spec.md
	 */
	public function searchBySender(string $sender): array {
		// Backward-compat: if the legacy openregister_email_links table is
		// still present (deployments that haven't run the drop migration
		// yet), prefer it.
		$legacy = $this->emailLinkMapper->findBySender($sender);
		if ($legacy !== []) {
			return array_map(
				static fn (EmailLink $link): array => $link->jsonSerialize(),
				$legacy
			);
		}

		// New path: find Mail messages from this sender, then for each
		// {accountId}/{messageId} ID look up which OR objects link them
		// via the `_mail` JSON column on each magic table.
		$messageIds = $this->findMessageIdsBySender(sender: $sender);
		if ($messageIds === []) {
			return [];
		}

		return $this->objectsLinkedToMessages(messageIds: $messageIds);
	}//end searchBySender()

	/**
	 * The objects, across every mail-linked schema, whose `_mail` column links one of the messages.
	 *
	 * @param array<int, string> $messageIds The "{accountId}/{messageId}" identifiers.
	 *
	 * @return array<int, array<string, string>> One row per linking object, schema by schema.
	 */
	private function objectsLinkedToMessages(array $messageIds): array {
		$results = [];
		foreach ($this->getMailLinkedSchemas() as $schema) {
			foreach ($messageIds as $linkedId) {
				$results = array_merge($results, $this->objectsLinkingMessage(schema: $schema, linkedId: $linkedId));
			}
		}

		return $results;
	}//end objectsLinkedToMessages()

	/**
	 * The objects of one schema whose `_mail` column links one message.
	 *
	 * @param \OCA\OpenRegister\Db\Schema $schema   The mail-linked schema.
	 * @param string                       $linkedId The "{accountId}/{messageId}" identifier.
	 *
	 * @return array<int, array<string, string>> One row per linking object.
	 */
	private function objectsLinkingMessage(\OCA\OpenRegister\Db\Schema $schema, string $linkedId): array {
		$entities = $this->relations()->findByLinkedEntity(
			schema: $schema,
			columnName: '_mail',
			entityId: $linkedId
		);
		$rows = [];
		foreach ($entities as $entity) {
			$rows[] = $this->linkedObjectRow(entity: $entity, linkedId: $linkedId);
		}

		return $rows;
	}//end objectsLinkingMessage()

	/**
	 * The search result row for one object that links a message.
	 *
	 * @param \OCA\OpenRegister\Db\ObjectEntity $entity   The linking object.
	 * @param string                             $linkedId The "{accountId}/{messageId}" identifier.
	 *
	 * @return array<string, string> The object's uuid, register, schema and the linked message id.
	 */
	private function linkedObjectRow(\OCA\OpenRegister\Db\ObjectEntity $entity, string $linkedId): array {
		return [
			'objectUuid' => (string)$entity->getUuid(),
			'register' => (string)$entity->getRegister(),
			'schema' => (string)$entity->getSchema(),
			'mailLinkedId' => $linkedId,
		];
	}//end linkedObjectRow()

	/**
	 * Query the Mail app's database for message IDs from a sender.
	 *
	 * @param string $sender The sender email address to query.
	 *
	 * @return array<int, string> List of "{accountId}/{messageId}" identifiers.
	 */
	private function findMessageIdsBySender(string $sender): array {
		if ($this->isMailAvailable() === false) {
			return [];
		}

		// IDOR guard: scope the scan to the caller's own Mail accounts. Without
		// this any authenticated user could pass an arbitrary sender and learn
		// which OpenRegister objects link to mail received by OTHER users'
		// accounts (cross-account leak). oc_mail_accounts.user_id owns the account.
		$user = $this->mailApp->userSession->getUser();
		if ($user === null) {
			return [];
		}

		try {
			return $this->messageIdsFromSenderForUser(sender: $sender, userId: $user->getUID());
		} catch (Exception $e) {
			$this->logger->warning('[EmailService] findMessageIdsBySender failed: ' . $e->getMessage());
			return [];
		}//end try
	}//end findMessageIdsBySender()

	/**
	 * Read the ids of the messages a sender sent to one user's own Mail accounts (at most 200).
	 *
	 * @param string $sender The sender email address, matched case-insensitively.
	 * @param string $userId The user whose Mail accounts are scanned.
	 *
	 * @return array<int, string> List of "{accountId}/{messageId}" identifiers.
	 */
	private function messageIdsFromSenderForUser(string $sender, string $userId): array {
		// The mail_recipients.type=0 is "from"; mail_recipients.email is the address.
		$sql = 'SELECT mb.account_id, m.id AS message_id
                     FROM *PREFIX*mail_messages m
                     JOIN *PREFIX*mail_recipients r ON r.message_id = m.id AND r.type = 0
                     JOIN *PREFIX*mail_mailboxes mb ON mb.id = m.mailbox_id
                     JOIN *PREFIX*mail_accounts ma ON ma.id = mb.account_id
                     WHERE LOWER(r.email) = LOWER(?) AND ma.user_id = ?
                     LIMIT 200';
		$stmt = $this->mailApp->db->prepare($sql);
		$stmt->execute([$sender, $userId]);
		$ids = [];
		while (($row = $stmt->fetch()) !== false) {
			$ids[] = ((int)$row['account_id']) . '/' . ((int)$row['message_id']);
		}

		return $ids;
	}//end messageIdsFromSenderForUser()

	/**
	 * Schemas that opt into mail linkage via `linkedTypes: ["mail"]`.
	 *
	 * @return array<int, \OCA\OpenRegister\Db\Schema>
	 */
	private function getMailLinkedSchemas(): array {
		try {
			$all = $this->linkedSchemas->schemaMapper->findAll(_multitenancy: false);
		} catch (\Throwable) {
			return [];
		}

		return array_values(
			array_filter(
				$all,
				static fn ($schema) => in_array('mail', $schema->getLinkedTypes(), true) === true
			)
		);
	}//end getMailLinkedSchemas()

	/**
	 * Delete all email links for an object (cleanup).
	 *
	 * @param string $objectUuid The object UUID.
	 *
	 * @return int Number of deleted links.
	 *
	 * @spec exclude Legacy link-table bulk cleanup delegating to EmailLinkMapper; email link infrastructure being removed per linked-entity-types.
	 */
	public function deleteLinksForObject(string $objectUuid): int {
		return $this->emailLinkMapper->deleteByObjectUuid($objectUuid);
	}//end deleteLinksForObject()

	/**
	 * Fetch a mail message from the Mail app's database.
	 *
	 * @param int $messageId The mail message ID.
	 * @param int $accountId The mail account ID.
	 *
	 * @return array|null Message data or null if not found.
	 *
	 * @spec openspec/specs/mail-sidebar/spec.md#requirement-reverse-lookup-api-to-find-objects-by-mail-message-id
	 */
	private function fetchMailMessage(int $messageId, int $accountId): ?array {
		try {
			$result = $this->mailMessageQuery(messageId: $messageId, accountId: $accountId)->executeQuery();
			$row = $result->fetch();
			$result->closeCursor();

			if ($row === false) {
				return null;
			}

			return $this->mailMessageData(row: $row);
		} catch (Exception $e) {
			$this->logger->warning('Failed to fetch mail message: ' . $e->getMessage());
			return null;
		}//end try
	}//end fetchMailMessage()

	/**
	 * The query for one message, with its sender, in one of an account's mailboxes.
	 *
	 * @param int $messageId The mail message ID.
	 * @param int $accountId The mail account ID.
	 *
	 * @return \OCP\DB\QueryBuilder\IQueryBuilder The query, at most one row.
	 */
	private function mailMessageQuery(int $messageId, int $accountId): \OCP\DB\QueryBuilder\IQueryBuilder {
		$qb = $this->mailApp->db->getQueryBuilder();
		$this->selectMessageWithSender(qb: $qb);
		$qb->where($qb->expr()->eq('m.id', $qb->createNamedParameter($messageId)))
			->andWhere(
				$qb->expr()->eq(
					'm.mailbox_id',
					$qb->createFunction(
						$this->buildMailboxSubquery(qb: $qb, accountId: $accountId)
					)
				)
			)
			->setMaxResults(1);

		return $qb;
	}//end mailMessageQuery()

	/**
	 * Select a message's header columns, joined to its "from" recipient.
	 *
	 * @param \OCP\DB\QueryBuilder\IQueryBuilder $qb The query builder.
	 *
	 * @return void
	 */
	private function selectMessageWithSender(\OCP\DB\QueryBuilder\IQueryBuilder $qb): void {
		$qb->select('m.id', 'm.uid', 'm.subject', 'm.sent_at', 'm.message_id')
			->addSelect('r.email as sender_email', 'r.label as sender_label')
			->from('mail_messages', 'm')
			->leftJoin(
				'm',
				'mail_recipients',
				'r',
				$qb->expr()->andX(
					$qb->expr()->eq('r.message_id', 'm.id'),
					$qb->expr()->eq('r.type', $qb->createNamedParameter(0))
				)
			);
	}//end selectMessageWithSender()

	/**
	 * The message data a link stores, from one Mail message row.
	 *
	 * @param array<string, mixed> $row The message row, with its sender columns.
	 *
	 * @return array<string, mixed> The uid, RFC message id, subject, sender, sender name and ISO date.
	 */
	private function mailMessageData(array $row): array {
		$sentAt = null;
		if (isset($row['sent_at']) === true) {
			$sentAt = date('c', (int)$row['sent_at']);
		}

		return [
			'uid' => (string)($row['uid'] ?? ''),
			'rfcMessageId' => $row['message_id'] ?? null,
			'subject' => $row['subject'] ?? null,
			'sender' => $row['sender_email'] ?? null,
			'senderName' => $row['sender_label'] ?? null,
			'date' => $sentAt,
		];
	}//end mailMessageData()

	/**
	 * Build the mailbox subquery string for filtering by account.
	 *
	 * @param \OCP\DB\QueryBuilder\IQueryBuilder $qb The query builder.
	 * @param int $accountId The mail account ID.
	 *
	 * @return string The subquery string.
	 */
	private function buildMailboxSubquery(\OCP\DB\QueryBuilder\IQueryBuilder $qb, int $accountId): string {
		$param = $qb->createNamedParameter($accountId);
		$sql = '(SELECT mb.id FROM *PREFIX*mail_mailboxes mb WHERE mb.account_id = ' . $param;
		return $sql . ' AND mb.id = m.mailbox_id LIMIT 1)';
	}//end buildMailboxSubquery()
}//end class
