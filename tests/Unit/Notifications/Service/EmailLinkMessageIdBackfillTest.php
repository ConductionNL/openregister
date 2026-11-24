<?php

/**
 * EmailLinkMessageIdBackfill: the Mail app's `Message-ID` recorded on old links.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Notifications\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/reply-threading-by-headers/specs/integration-email/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Notifications\Service;

use OCA\OpenRegister\Db\EmailLink;
use OCA\OpenRegister\Db\EmailLinkMapperInterface;
use OCA\OpenRegister\Notifications\Service\EmailLinkMessageIdBackfill;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Notifications\Service\EmailLinkMessageIdBackfill
 * @uses   \OCA\OpenRegister\Db\EmailLink
 * @uses   \OCA\OpenRegister\Service\MailTableQueries
 */
class EmailLinkMessageIdBackfillTest extends TestCase {

	/** @var array<int, string> The ids recorded, per link id. */
	private array $recorded = [];

	/** @var array<int, int[]> The Mail message ids asked for, per query. */
	private array $asked = [];

	private function link(int $id, int $mailMessageId): EmailLink {
		$link = new EmailLink();
		$link->setId($id);
		$link->setMailMessageId($mailMessageId);
		return $link;
	}

	/**
	 * The backfill over pages of links and the Mail app's message rows.
	 *
	 * @param array<int, EmailLink[]> $pages Each page, keyed by the cursor it answers.
	 * @param array<int, mixed>       $mail  The `message_id` of each Mail message id.
	 * @param bool                    $mailInstalled Whether the Mail table exists.
	 */
	private function backfill(array $pages, array $mail, bool $mailInstalled = true): EmailLinkMessageIdBackfill {
		$links = $this->createMock(EmailLinkMapperInterface::class);
		$links->method('findWithoutRfcMessageId')->willReturnCallback(
			static fn (int $afterId, int $limit): array => ($pages[$afterId] ?? [])
		);
		$links->method('recordRfcMessageId')->willReturnCallback(
			function (int $linkId, string $rfcMessageId): int {
				$this->recorded[$linkId] = $rfcMessageId;
				return 1;
			}
		);

		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->with('mail_messages')->willReturn($mailInstalled);
		$db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->query(mail: $mail));

		return new EmailLinkMessageIdBackfill(links: $links, db: $db);
	}

	/**
	 * A query over `mail_messages` answering the rows of the ids it was given.
	 *
	 * @param array<int, mixed> $mail The `message_id` per Mail message id.
	 */
	private function query(array $mail): IQueryBuilder {
		$ids = [];
		$qb = $this->createMock(IQueryBuilder::class);
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('in')->willReturn('in');
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturnCallback(
			static function (mixed $value) use (&$ids): string {
				$ids = $value;
				return ':ids';
			}
		);
		foreach (['select', 'from', 'where'] as $method) {
			$qb->method($method)->willReturnSelf();
		}

		$qb->method('executeQuery')->willReturnCallback(
			function () use (&$ids, $mail): IResult {
				$this->asked[] = $ids;
				$rows = [];
				foreach ($ids as $id) {
					if (array_key_exists($id, $mail) === true) {
						$rows[] = ['id' => (string) $id, 'message_id' => $mail[$id]];
					}
				}

				$result = $this->createMock(IResult::class);
				$result->method('fetchAll')->willReturn($rows);
				return $result;
			}
		);

		return $qb;
	}

	public function testEachLinkGetsTheMessageIdOfItsMailMessage(): void {
		$backfill = $this->backfill(
			pages: [0 => [$this->link(id: 3, mailMessageId: 30), $this->link(id: 4, mailMessageId: 40)]],
			mail: [30 => '<a1@gemeente.nl>', 40 => '<a2@gemeente.nl>']
		);

		$this->assertSame(['read' => 2, 'recorded' => 2], $backfill->run());
		$this->assertSame([3 => '<a1@gemeente.nl>', 4 => '<a2@gemeente.nl>'], $this->recorded);
		$this->assertSame([[30, 40]], $this->asked);
	}

	public function testALinkWhoseMailMessageIsGoneOrHasNoIdKeepsNull(): void {
		$backfill = $this->backfill(
			pages: [0 => [$this->link(id: 3, mailMessageId: 30), $this->link(id: 4, mailMessageId: 40), $this->link(id: 5, mailMessageId: 50)]],
			mail: [40 => null, 50 => '<' . str_repeat('x', 300) . '@x>']
		);

		$this->assertSame(['read' => 3, 'recorded' => 0], $backfill->run());
		$this->assertSame([], $this->recorded);
	}

	public function testAFullPageIsFollowedByTheNextFromItsLastId(): void {
		$first = [];
		for ($i = 1; $i <= EmailLinkMessageIdBackfill::PAGE_SIZE; $i++) {
			$first[] = $this->link(id: $i, mailMessageId: 1000 + $i);
		}

		$last = EmailLinkMessageIdBackfill::PAGE_SIZE;
		$backfill = $this->backfill(
			pages: [0 => $first, $last => [$this->link(id: $last + 1, mailMessageId: 7)]],
			mail: [7 => '<late@x>']
		);

		$this->assertSame(['read' => $last + 1, 'recorded' => 1], $backfill->run());
		$this->assertSame([$last + 1 => '<late@x>'], $this->recorded);
		$this->assertCount(2, $this->asked);
	}

	public function testWithoutTheMailAppNothingIsRead(): void {
		$backfill = $this->backfill(pages: [0 => [$this->link(id: 3, mailMessageId: 30)]], mail: [30 => '<a1@x>'], mailInstalled: false);

		$this->assertSame(['read' => 0, 'recorded' => 0], $backfill->run());
		$this->assertSame([], $this->asked);
	}
}
