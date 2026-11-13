<?php

/**
 * Golden test: what LinkedEntityEnricher returns for mail, contacts, notes, Talk and Deck ids.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use DateTime;
use OCA\OpenRegister\Service\Object\LinkedEntityEnricher;
use OCP\Comments\IComment;
use OCP\Comments\ICommentsManager;
use OCP\DB\IPreparedStatement;
use OCP\DB\IResult;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins the enriched entries, the queries run and the debug log for every raw-SQL type
 * and for notes, against fixtures/linked-entity-enricher-golden.json.
 * Set UPDATE_GOLDEN=1 to rewrite the fixture.
 *
 * @covers \OCA\OpenRegister\Service\Object\LinkedEntityEnricher
 * @uses \OCA\OpenRegister\Service\Object\LinkedEntityQuery
 */
class LinkedEntityEnricherGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/linked-entity-enricher-golden.json';

	/**
	 * Rows by the JSON of the bound parameters; `"throw"` makes execute() fail, absent keys find nothing.
	 */
	private const ROWS = [
		'[5]' => ['subject' => 'Offer', 'sender' => 'anna@example.org', 'sent_at' => 1700000000],
		'[6]' => [],
		'[9]' => 'throw',
		'["card-1"]' => ['carddata' => "BEGIN:VCARD\nFN:Anna Smit\nEMAIL;TYPE=work:anna@example.org\nEND:VCARD"],
		'["card-2"]' => ['carddata' => "BEGIN:VCARD\nEND:VCARD"],
		'["card-x"]' => 'throw',
		'["room-1"]' => ['name' => 'Case room', 'type' => '3'],
		'["room-2"]' => [],
		'["room-x"]' => 'throw',
		'[11,4]' => ['title' => 'Card', 'board_title' => 'Board', 'stack_title' => 'Doing'],
		'[12,4]' => [],
		'[13,4]' => 'throw',
	];

	public function testEnrichedEntriesMatchGolden(): void {
		$queries = [];
		$logs    = [];
		$db      = $this->createMock(IDBConnection::class);
		$db->method('prepare')->willReturnCallback(function (string $sql) use (&$queries): IPreparedStatement {
			$stmt = $this->createMock(IPreparedStatement::class);
			$row  = false;
			$stmt->method('execute')->willReturnCallback(function (array $params) use ($sql, &$queries, &$row): IResult {
				$key       = json_encode($params);
				$queries[] = [preg_replace('/\s+/', ' ', $sql), $params];
				if ((self::ROWS[$key] ?? null) === 'throw') {
					throw new RuntimeException('database down');
				}

				$row = self::ROWS[$key] ?? false;
				return $this->createMock(IResult::class);
			});
			$stmt->method('fetch')->willReturnCallback(function () use (&$row) {
				return $row;
			});
			return $stmt;
		});

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('debug')->willReturnCallback(function ($message, $context = []) use (&$logs): void {
			$logs[] = [$message, $context];
		});

		$enricher = new LinkedEntityEnricher($db, $this->comments(), $this->users(), $logger);

		$actual = [
			'enriched' => $enricher->enrich(
				[
					'_mail' => ['1/5', '1/6', '1/9', 'no-slash', '1/7'],
					'_contacts' => ['card-1', 'card-2', 'card-x', 'card-none'],
					'_notes' => ['c1', 'c2', 'c-missing'],
					'_talk' => ['room-1', 'room-2', 'room-x', 'room-none'],
					'_deck' => ['4/11', '4/12', '4/13', 'deck', '4/99'],
					'_calendar' => 'not-a-list',
				],
				['_mail' => '1', '_contacts' => '1', '_notes' => '1', '_talk' => '1', '_deck' => '1', '_calendar' => '1']
			),
			'queries' => $queries,
			'logs' => $logs,
		];

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$this->assertSame(json_decode((string) file_get_contents(self::FIXTURE), true), $actual);
	}//end testEnrichedEntriesMatchGolden()

	private function comments(): ICommentsManager {
		$known = [];
		foreach (['c1' => 'anna', 'c2' => 'ghost'] as $id => $actor) {
			$comment = $this->createMock(IComment::class);
			$comment->method('getActorId')->willReturn($actor);
			$comment->method('getMessage')->willReturn('Note ' . $id);
			$comment->method('getCreationDateTime')->willReturn(new DateTime('2026-01-02T03:04:05+00:00'));
			$known[$id] = $comment;
		}

		$manager = $this->createMock(ICommentsManager::class);
		$manager->method('get')->willReturnCallback(static function (string $id) use ($known): IComment {
			return $known[$id] ?? throw new RuntimeException('not found');
		});

		return $manager;
	}//end comments()

	private function users(): IUserManager {
		$anna = $this->createMock(IUser::class);
		$anna->method('getDisplayName')->willReturn('Anna Smit');

		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(static fn (string $uid): ?IUser => ($uid === 'anna' ? $anna : null));

		return $users;
	}//end users()
}//end class
