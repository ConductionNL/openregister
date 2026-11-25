<?php

/**
 * Backfills the RFC `Message-ID` of email links made before it was recorded.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Notifications\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service;

use OCA\OpenRegister\Db\EmailLink;
use OCA\OpenRegister\Db\EmailLinkMapperInterface;
use OCA\OpenRegister\Service\MailTableQueries;
use OCP\IDBConnection;

/**
 * Reads each link's `Message-ID` from the Mail app's message row and records it.
 *
 * A link whose Mail message is gone, or whose id does not fit the column,
 * keeps null: its replies stay `unthreaded`, which a person sees. The walk is
 * by id with a cursor, so such a link is read once per run, not forever.
 *
 * @spec openspec/changes/reply-threading-by-headers/specs/integration-email/spec.md
 */
class EmailLinkMessageIdBackfill {

	/**
	 * Links read per page.
	 */
	public const PAGE_SIZE = 200;

	/**
	 * The Mail app's queries.
	 *
	 * @var MailTableQueries
	 */
	private readonly MailTableQueries $mail;

	/**
	 * Constructor.
	 *
	 * @param EmailLinkMapperInterface $links The email links.
	 * @param IDBConnection            $db    The database, for the Mail app's tables.
	 */
	public function __construct(
		private readonly EmailLinkMapperInterface $links,
		private readonly IDBConnection $db,
	) {
		$this->mail = new MailTableQueries(db: $db);
	}//end __construct()

	/**
	 * Backfill every link that has none.
	 *
	 * @return array{read: int, recorded: int} How many links were read and how many got an id.
	 *
	 * @spec openspec/changes/reply-threading-by-headers/specs/integration-email/spec.md
	 */
	public function run(): array {
		$tally = ['read' => 0, 'recorded' => 0];
		if ($this->db->tableExists('mail_messages') === false) {
			return $tally;
		}

		$afterId = 0;
		do {
			$page = $this->links->findWithoutRfcMessageId(afterId: $afterId, limit: self::PAGE_SIZE);
			$tally['read'] += count($page);
			$tally['recorded'] += $this->recordPage(page: $page);
			if ($page !== []) {
				$afterId = (int) end($page)->getId();
			}
		} while (count($page) === self::PAGE_SIZE);

		return $tally;
	}//end run()

	/**
	 * Record the ids of one page of links.
	 *
	 * @param EmailLink[] $page The links.
	 *
	 * @return int How many got an id.
	 */
	private function recordPage(array $page): int {
		if ($page === []) {
			return 0;
		}

		$messageIds = array_map(static fn (EmailLink $link): int => (int) $link->getMailMessageId(), $page);
		$found = [];
		foreach ($this->mail->rowsOf(qb: $this->mail->rfcMessageIdsQuery(messageIds: array_unique($messageIds))) as $row) {
			$messageId = ($row['message_id'] ?? null);
			if (is_string($messageId) === false) {
				$messageId = null;
			}

			$found[(int) $row['id']] = EmailLink::storableMessageId(messageId: $messageId);
		}

		$recorded = 0;
		foreach ($page as $link) {
			$rfcMessageId = ($found[(int) $link->getMailMessageId()] ?? null);
			if ($rfcMessageId !== null) {
				$recorded += $this->links->recordRfcMessageId(linkId: (int) $link->getId(), rfcMessageId: $rfcMessageId);
			}
		}

		return $recorded;
	}//end recordPage()
}//end class
