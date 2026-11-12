<?php

/**
 * Behaviour tests for EmailService: sender search through the Mail app (scoped to the caller), and link guards.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Notifications\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Notifications\Service;

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

use Exception;
use OCA\OpenRegister\Db\EmailLink;
use OCA\OpenRegister\Db\EmailLinkMapperInterface;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Notifications\Service\EmailLinkedSchemas;
use OCA\OpenRegister\Notifications\Service\EmailMailAppReader;
use OCA\OpenRegister\Notifications\Service\EmailService;
use OCA\OpenRegister\Tests\Unit\Db\RecordingQueryBuilderTrait;
use OCP\App\IAppManager;
use OCP\DB\IPreparedStatement;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Notifications\Service\EmailLinkRequest;

/**
 * @covers \OCA\OpenRegister\Notifications\Service\EmailService
 * @uses   \OCA\OpenRegister\Notifications\Service\EmailMailAppReader
 * @uses   \OCA\OpenRegister\Notifications\Service\EmailLinkedSchemas
 * @uses   \OCA\OpenRegister\Db\EmailLink
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\TypedFieldsJson
 * @uses   \OCA\OpenRegister\Db\Schema\ConfigurationEntryRules
 * @uses   \OCA\OpenRegister\Db\Schema\ConfigurationValidator
 * @uses   \OCA\OpenRegister\Db\Schema\ConfigurationValueRules
 * @uses   \OCA\OpenRegister\Db\Schema\LinkedTypesRule
 */
final class EmailServiceSenderSearchTest extends TestCase {
	use RecordingQueryBuilderTrait;

	/** @var EmailLinkMapperInterface&MockObject */
	private EmailLinkMapperInterface $links;

	/** @var IAppManager&MockObject */
	private IAppManager $apps;

	/** @var IDBConnection&MockObject */
	private IDBConnection $db;

	/** @var IUserSession&MockObject */
	private IUserSession $session;

	/** @var SchemaMapperInterface&MockObject */
	private SchemaMapperInterface $schemas;

	/** @var MagicMapperInterface&MockObject */
	private MagicMapperInterface $magic;

	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;

	protected function setUp(): void {
		parent::setUp();
		$this->links = $this->createMock(EmailLinkMapperInterface::class);
		$this->apps = $this->createMock(IAppManager::class);
		$this->db = $this->createMock(IDBConnection::class);
		$this->db->method('getQueryBuilder')->willReturnCallback(fn () => $this->recordingBuilder());
		$this->session = $this->createMock(IUserSession::class);
		$this->schemas = $this->createMock(SchemaMapperInterface::class);
		$this->magic = $this->createMock(MagicMapperInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	private function service(): EmailService {
		return new EmailService(
			$this->links,
			new EmailMailAppReader($this->apps, $this->db, $this->session),
			new EmailLinkedSchemas($this->schemas, $this->magic),
			$this->logger
		);
	}

	private function loginAs(string $uid): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->session->method('getUser')->willReturn($user);
	}

	private function schema(int $id, array $linkedTypes): Schema {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setConfiguration(['linkedTypes' => $linkedTypes]);
		return $schema;
	}

	private function statementYielding(array $rows, array &$executedWith): IPreparedStatement {
		$stmt = $this->createMock(IPreparedStatement::class);
		$stmt->method('execute')->willReturnCallback(
			function (array $params) use (&$executedWith) {
				$executedWith = $params;
				return $this->servedResult([]);
			}
		);
		$queue = $rows;
		$stmt->method('fetch')->willReturnCallback(
			static function () use (&$queue) {
				return $queue === [] ? false : array_shift($queue);
			}
		);
		return $stmt;
	}

	public function testLegacyLinksWinOverTheMailScan(): void {
		$link = new EmailLink();
		$link->setObjectUuid('o-1');
		$this->links->method('findBySender')->with('a@x.nl')->willReturn([$link]);
		$this->apps->expects($this->never())->method('isEnabledForUser');

		$this->assertSame('o-1', $this->service()->searchBySender('a@x.nl')[0]['objectUuid']);
	}

	public function testSenderSearchIsScopedToTheCallersOwnMailAccounts(): void {
		$this->links->method('findBySender')->willReturn([]);
		$this->apps->method('isEnabledForUser')->with('mail')->willReturn(true);
		$this->loginAs('alice');
		$executedWith = [];
		$sql = '';
		$this->db->method('prepare')->willReturnCallback(
			function (string $query) use (&$sql, &$executedWith) {
				$sql = $query;
				return $this->statementYielding([['account_id' => '3', 'message_id' => '17'], ['account_id' => 3, 'message_id' => 18]], $executedWith);
			}
		);
		$this->schemas->method('findAll')->willReturn([$this->schema(1, ['mail']), $this->schema(2, ['files'])]);
		$hit = new ObjectEntity();
		$hit->setUuid('o-9');
		$hit->setRegister('4');
		$hit->setSchema('1');
		$lookups = [];
		$this->magic->method('findByLinkedEntity')->willReturnCallback(
			function (Schema $schema, string $column, string $entityId) use (&$lookups, $hit): array {
				$lookups[] = [$schema->getId(), $column, $entityId];
				return $entityId === '3/17' ? [$hit] : [];
			}
		);

		$results = $this->service()->searchBySender('A@X.nl');

		$this->assertSame(['A@X.nl', 'alice'], $executedWith);
		$this->assertStringContainsString('ma.user_id = ?', $sql);
		$this->assertSame([[1, '_mail', '3/17'], [1, '_mail', '3/18']], $lookups);
		$this->assertSame([['objectUuid' => 'o-9', 'register' => '4', 'schema' => '1', 'mailLinkedId' => '3/17']], $results);
	}

	public function testWithoutTheMailAppNothingIsScanned(): void {
		$this->links->method('findBySender')->willReturn([]);
		$this->apps->method('isEnabledForUser')->willReturn(false);
		$this->db->expects($this->never())->method('prepare');

		$this->assertSame([], $this->service()->searchBySender('a@x.nl'));
	}

	public function testWithoutALoggedInUserNothingIsScanned(): void {
		$this->links->method('findBySender')->willReturn([]);
		$this->apps->method('isEnabledForUser')->willReturn(true);
		$this->session->method('getUser')->willReturn(null);
		$this->db->expects($this->never())->method('prepare');

		$this->assertSame([], $this->service()->searchBySender('a@x.nl'));
	}

	public function testAFailingMailQueryIsLoggedAndYieldsNothing(): void {
		$this->links->method('findBySender')->willReturn([]);
		$this->apps->method('isEnabledForUser')->willReturn(true);
		$this->loginAs('alice');
		$this->db->method('prepare')->willThrowException(new Exception('no mail tables'));
		$this->logger->expects($this->once())->method('warning')->with('[EmailService] findMessageIdsBySender failed: no mail tables');
		$this->magic->expects($this->never())->method('findByLinkedEntity');

		$this->assertSame([], $this->service()->searchBySender('a@x.nl'));
	}

	public function testAFailingSchemaListYieldsNoMatches(): void {
		$this->links->method('findBySender')->willReturn([]);
		$this->apps->method('isEnabledForUser')->willReturn(true);
		$this->loginAs('alice');
		$executedWith = [];
		$this->db->method('prepare')->willReturn($this->statementYielding([['account_id' => 1, 'message_id' => 2]], $executedWith));
		$this->schemas->method('findAll')->willThrowException(new RuntimeException('db'));
		$this->magic->expects($this->never())->method('findByLinkedEntity');

		$this->assertSame([], $this->service()->searchBySender('a@x.nl'));
	}

	public function testLinkingWithoutAUserIsRefused(): void {
		$this->links->method('findByObjectAndMessage')->willReturn(null);
		$this->queuedRows = [[['id' => 5, 'uid' => '77', 'subject' => 'S', 'sent_at' => null, 'message_id' => '<m@x>', 'sender_email' => 'a@x.nl', 'sender_label' => 'A']]];
		$this->session->method('getUser')->willReturn(null);
		$this->links->expects($this->never())->method('insert');

		$this->expectExceptionMessage('No user logged in');
		$this->service()->linkEmail(new EmailLinkRequest(objectUuid: 'o-1', registerId: 1, mailAccountId: 3, mailMessageId: 5));
	}

	public function testLinkingAMessageOutsideTheAccountIsNotFound(): void {
		$this->links->method('findByObjectAndMessage')->willReturn(null);
		$this->queuedRows = [[]];
		$this->links->expects($this->never())->method('insert');

		try {
			$this->service()->linkEmail(new EmailLinkRequest(objectUuid: 'o-1', registerId: 1, mailAccountId: 3, mailMessageId: 5));
			$this->fail('Expected a 404');
		} catch (Exception $e) {
			$this->assertSame(404, $e->getCode());
		}
		$log = implode("\n", $this->queryLog);
		$this->assertStringContainsString('(SELECT mb.id FROM *PREFIX*mail_mailboxes mb WHERE mb.account_id = :p3 AND mb.id = m.mailbox_id LIMIT 1)', $log);
		$this->assertStringContainsString('param :p3 = 3', $log);
	}

	public function testAFailingMailLookupIsLoggedAndTreatedAsNotFound(): void {
		$this->links->method('findByObjectAndMessage')->willReturn(null);
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willThrowException(new Exception('mail schema missing'));
		$this->db = $db;
		$this->logger->expects($this->once())->method('warning')->with('Failed to fetch mail message: mail schema missing');

		$this->expectExceptionCode(404);
		$this->service()->linkEmail(new EmailLinkRequest(objectUuid: 'o-1', registerId: 1, mailAccountId: 3, mailMessageId: 5));
	}
}
