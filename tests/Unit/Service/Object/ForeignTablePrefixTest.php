<?php

/**
 * Raw SQL over the tables of other apps follows the configured table prefix.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\EmailLinkMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Notifications\Service\EmailService;
use OCA\OpenRegister\Service\Object\LinkedEntityEnricher;
use OCP\App\IAppManager;
use OCP\Comments\ICommentsManager;
use OCP\DB\IPreparedStatement;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;

/**
 * @covers \OCA\OpenRegister\Notifications\Service\EmailService
 * @covers \OCA\OpenRegister\Service\Object\LinkedEntityEnricher
 */
class ForeignTablePrefixTest extends TestCase {
	/** @var string[] The statements prepared. */
	private array $sql = [];

	/**
	 * A connection that records what is prepared and finds nothing.
	 *
	 * @return IDBConnection
	 */
	private function connection(): IDBConnection {
		$statement = $this->createMock(IPreparedStatement::class);
		$statement->method('fetch')->willReturn(false);
		$db = $this->createMock(IDBConnection::class);
		$db->method('prepare')->willReturnCallback(
			function (string $sql) use ($statement): IPreparedStatement {
				$this->sql[] = $sql;

				return $statement;
			}
		);

		return $db;
	}

	/**
	 * Every recorded statement names its tables through the placeholder.
	 *
	 * @param int $count The number of statements expected.
	 *
	 * @return void
	 */
	private function assertPrefixedStatements(int $count): void {
		$this->assertCount($count, $this->sql);
		foreach ($this->sql as $sql) {
			$this->assertStringContainsString('*PREFIX*', $sql);
			$this->assertStringNotContainsString('oc_', $sql);
		}
	}

	public function testTheEnricherReadsForeignTablesUnderTheConfiguredPrefix(): void {
		$enricher = new LinkedEntityEnricher(
			$this->connection(),
			$this->createMock(ICommentsManager::class),
			$this->createMock(IUserManager::class),
			new NullLogger()
		);

		foreach (['enrichMail' => '1/2', 'enrichContacts' => 'uid', 'enrichCalendar' => '1/uid', 'enrichTalk' => 'token', 'enrichDeck' => '3/4'] as $method => $id) {
			(new ReflectionMethod($enricher, $method))->invoke($enricher, [$id]);
		}

		$this->assertPrefixedStatements(count: 5);
	}

	public function testTheSenderSearchReadsMailTablesUnderTheConfiguredPrefix(): void {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturn(true);
		$apps->method('isInstalled')->willReturn(true);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($this->createMock(IUser::class));

		$service = new EmailService(
			$this->createMock(EmailLinkMapper::class),
			$apps,
			$this->connection(),
			$session,
			new NullLogger(),
			$this->createMock(SchemaMapper::class),
			$this->createMock(MagicMapper::class)
		);

		(new ReflectionMethod($service, 'findMessageIdsBySender'))->invoke($service, 'a@example.org');

		$this->assertPrefixedStatements(count: 1);
	}
}
