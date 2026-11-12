<?php

/**
 * Golden test for the Mail picker reads of EmailLinkService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\EmailLinkMapper;
use OCA\OpenRegister\Service\EmailLinkService;
use OCA\OpenRegister\Service\LinkServiceRuntime;
use OCA\OpenRegister\Tests\Unit\Db\RecordingQueryBuilderTrait;
use OCP\App\IAppManager;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Service\MailboxPage;

/**
 * Pins the queries, results, exceptions and log lines of the account,
 * mailbox and message reads behind the Mail picker.
 *
 * @covers \OCA\OpenRegister\Service\EmailLinkService
 * @uses   \OCA\OpenRegister\Service\MailboxPage
 * @uses   \OCA\OpenRegister\Service\MailTableQueries
 */
class EmailLinkServicePickerGoldenTest extends TestCase {
	use RecordingQueryBuilderTrait;

	private const FIXTURE = __DIR__ . '/fixtures/email-link-service-picker-golden.json';

	private const OWNER = [['user_id' => 'alice']];

	private const MESSAGES = [
		['id' => 9, 'uid' => 'u9', 'subject' => 'Nine', 'sent_at' => 1700000000, 'sender_email' => 'a@x.nl'],
		['id' => 8, 'uid' => 'u8', 'subject' => null, 'sender_email' => null],
		['id' => 7, 'uid' => 'u7', 'subject' => 'Seven', 'sent_at' => '1700000100'],
	];

	private array $logs = [];

	private int $throwOnBuilder = 0;

	/**
	 * Cases: [method, args, user, mail available, queued row sets, builder number that throws].
	 */
	private static function cases(): array {
		$accounts = [['id' => 3, 'name' => 'Work', 'email' => 'w@x.nl'], ['id' => 0, 'name' => 'Bad'], ['id' => 4, 'email' => 'p@x.nl']];
		$mailboxes = [['id' => 1, 'name' => 'INBOX'], ['id' => 2, 'name' => 'INBOX.Sent'], ['id' => 0, 'name' => 'X'], ['id' => 5, 'name' => ''], ['id' => 6, 'name' => 'Trash.']];
		return [
			'accounts no user' => ['getAvailableAccounts', [], null, true, [], 0],
			'accounts mail off' => ['getAvailableAccounts', [], 'alice', false, [], 0],
			'accounts rows' => ['getAvailableAccounts', [], 'alice', true, [$accounts], 0],
			'accounts query fails' => ['getAvailableAccounts', [], 'alice', true, [], 1],
			'mailboxes bad id' => ['getMailboxesForAccount', [0], 'alice', true, [], 0],
			'mailboxes mail off' => ['getMailboxesForAccount', [3], 'alice', false, [], 0],
			'mailboxes no user' => ['getMailboxesForAccount', [3], null, true, [], 0],
			'mailboxes not owner' => ['getMailboxesForAccount', [3], 'bob', true, [self::OWNER], 0],
			'mailboxes no account' => ['getMailboxesForAccount', [3], 'alice', true, [[]], 0],
			'mailboxes owner query fails' => ['getMailboxesForAccount', [3], 'alice', true, [], 1],
			'mailboxes rows' => ['getMailboxesForAccount', [3], 'alice', true, [self::OWNER, $mailboxes], 0],
			'mailboxes query fails' => ['getMailboxesForAccount', [3], 'alice', true, [self::OWNER], 2],
			'messages mail off' => ['getMessagesForMailbox', [3, 'INBOX', null, 2], 'alice', false, [], 0],
			'messages bad account' => ['getMessagesForMailbox', [0, 'INBOX', null, 2], 'alice', true, [], 0],
			'messages no mailbox name' => ['getMessagesForMailbox', [3, '', null, 2], 'alice', true, [], 0],
			'messages not owner' => ['getMessagesForMailbox', [3, 'INBOX', null, 2], 'bob', true, [self::OWNER], 0],
			'messages unknown mailbox' => ['getMessagesForMailbox', [3, 'INBOX', null, 2], 'alice', true, [self::OWNER, []], 0],
			'messages mailbox id zero' => ['getMessagesForMailbox', [3, 'INBOX', null, 2], 'alice', true, [self::OWNER, [['id' => 0]]], 0],
			'messages first page more' => ['getMessagesForMailbox', [3, 'INBOX', null, 2], 'alice', true, [self::OWNER, [['id' => 11]], self::MESSAGES], 0],
			'messages cursor last page' => ['getMessagesForMailbox', [3, 'INBOX', '10', 5], 'alice', true, [self::OWNER, [['id' => 11]], self::MESSAGES], 0],
			'messages empty cursor' => ['getMessagesForMailbox', [3, 'INBOX', '', 3], 'alice', true, [self::OWNER, [['id' => 11]], self::MESSAGES], 0],
			'messages text cursor' => ['getMessagesForMailbox', [3, 'INBOX', 'abc', 3], 'alice', true, [self::OWNER, [['id' => 11]], []], 0],
			'messages zero limit' => ['getMessagesForMailbox', [3, 'INBOX', null, 0], 'alice', true, [self::OWNER, [['id' => 11]], self::MESSAGES], 0],
			'messages huge limit' => ['getMessagesForMailbox', [3, 'INBOX', '-4', 999], 'alice', true, [self::OWNER, [['id' => 11]], self::MESSAGES], 0],
			'messages exact page' => ['getMessagesForMailbox', [3, 'INBOX', null, 3], 'alice', true, [self::OWNER, [['id' => 11]], self::MESSAGES], 0],
			'messages resolve fails' => ['getMessagesForMailbox', [3, 'INBOX', null, 2], 'alice', true, [self::OWNER], 2],
			'messages query fails' => ['getMessagesForMailbox', [3, 'INBOX', null, 2], 'alice', true, [self::OWNER, [['id' => 11]]], 3],
			'message row found' => ['fetchMessageRow', [3, 9], 'alice', true, [[self::MESSAGES[0]]], 0],
			'message row bare' => ['fetchMessageRow', [3, 8], 'alice', true, [[['id' => 8]]], 0],
			'message row missing' => ['fetchMessageRow', [3, 8], 'alice', true, [[]], 0],
			'message row fails' => ['fetchMessageRow', [3, 8], 'alice', true, [], 1],
		];
	}//end cases()

	public function testPickerReadsMatchGolden(): void {
		$actual = [];
		foreach (self::cases() as $name => [$method, $args, $uid, $mailOn, $rows, $throwOn]) {
			$this->queryLog = [];
			$this->logs = [];
			$this->builderCount = 0;
			$this->parameterCount = 0;
			$this->queuedRows = $rows;
			$this->throwOnBuilder = $throwOn;
			$service = $this->makeService($uid, $mailOn);
			$outcome = [];
			try {
				$call = new \ReflectionMethod(EmailLinkService::class, $method);
				if ($method === 'getMessagesForMailbox') {
					$args = [(new MailboxPage($args[0], $args[1]))->startingAfter($args[2], $args[3])];
				}

				$outcome['result'] = $call->invokeArgs($service, $args);
			} catch (\Exception $e) {
				$outcome['thrown'] = get_class($e) . ': ' . $e->getMessage() . ' (' . $e->getCode() . ')';
			}

			$outcome['queries'] = $this->queryLog;
			$outcome['logs'] = $this->logs;
			$actual[$name] = $outcome;
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$expected = json_decode((string)file_get_contents(self::FIXTURE), true);
		foreach ($expected as $name => $outcome) {
			$this->assertSame($outcome, $actual[$name] ?? null, $name);
		}

		$this->assertSame(array_keys($expected), array_keys($actual));
	}//end testPickerReadsMatchGolden()

	private function makeService(?string $uid, bool $mailOn): EmailLinkService {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isEnabledForUser')->willReturn($mailOn);
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(
			function () {
				if ($this->builderCount + 1 === $this->throwOnBuilder) {
					$this->builderCount++;
					throw new RuntimeException('db down');
				}

				return $this->recordingBuilder();
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message): void {
				$this->logs[] = $message;
			}
		);
		return new EmailLinkService($this->createMock(EmailLinkMapper::class), $db, new LinkServiceRuntime($appManager, $session, $logger));
	}//end makeService()
}//end class
