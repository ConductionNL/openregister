<?php

/**
 * Each schema and register event reaches the auditor with the right action.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/history-schema-and-settings-edits-audited/design.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Listener;

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Event\RegisterCreatedEvent;
use OCA\OpenRegister\Event\RegisterDeletedEvent;
use OCA\OpenRegister\Event\RegisterUpdatedEvent;
use OCA\OpenRegister\Event\SchemaCreatedEvent;
use OCA\OpenRegister\Event\SchemaDeletedEvent;
use OCA\OpenRegister\Event\SchemaUpdatedEvent;
use OCA\OpenRegister\Listener\EntityEditAuditListener;
use OCA\OpenRegister\Service\Audit\EntityEditAuditor;
use OCA\OpenRegister\Service\WriteCause;
use OCA\OpenRegister\Service\CauseFrame;
use OCP\EventDispatcher\Event;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Listener\EntityEditAuditListener
 * @uses   \OCA\OpenRegister\Service\Audit\EntityEditAuditor
 * @uses   \OCA\OpenRegister\Service\Rbac\AuditRowWriter
 * @uses   \OCA\OpenRegister\Service\WriteCause
 * @uses   \OCA\OpenRegister\Service\CauseFrame
 * @uses   \OCA\OpenRegister\Db\AuditTrail
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Event\AbstractSchemaEvent
 * @uses   \OCA\OpenRegister\Event\AbstractRegisterEvent
 * @uses   \OCA\OpenRegister\Event\SchemaUpdatedEvent
 * @uses   \OCA\OpenRegister\Event\RegisterUpdatedEvent
 */
final class EntityEditAuditListenerTest extends TestCase {

	/**
	 * The rows the audit trail received.
	 *
	 * @var array<int, AuditTrail>
	 */
	private array $written = [];

	protected function tearDown(): void {
		WriteCause::reset();
	}

	/**
	 * The listener over a recording trail, with no session user.
	 *
	 * @return EntityEditAuditListener
	 */
	private function listener(): EntityEditAuditListener {
		$mapper = $this->createMock(AuditTrailMapperInterface::class);
		$mapper->method('insertAuditTrails')->willReturnCallback(
			function (array $entries): array {
				array_push($this->written, ...$entries);
				return $entries;
			}
		);

		return new EntityEditAuditListener(
			new EntityEditAuditor($mapper, $this->createMock(IUserSession::class), $this->createMock(LoggerInterface::class))
		);
	}

	private static function schema(string $title): Schema {
		$schema = new Schema();
		$schema->setId(7);
		$schema->setSlug('meldingen');
		$schema->setTitle($title);
		return $schema;
	}

	private static function register(string $title): Register {
		$register = new Register();
		$register->setId(4);
		$register->setSlug('zaken');
		$register->setTitle($title);
		return $register;
	}

	public function testEachEventWritesOneRowWithItsAction(): void {
		$listener = $this->listener();
		$listener->handle(new SchemaCreatedEvent(self::schema('A')));
		$listener->handle(new SchemaUpdatedEvent(self::schema('B'), self::schema('A')));
		$listener->handle(new SchemaDeletedEvent(self::schema('B')));
		$listener->handle(new RegisterCreatedEvent(self::register('A')));
		$listener->handle(new RegisterUpdatedEvent(self::register('B'), self::register('A')));
		$listener->handle(new RegisterDeletedEvent(self::register('B')));
		$listener->handle(new Event());
		$this->assertSame(
			['schema.created', 'schema.updated', 'schema.deleted', 'register.created', 'register.updated', 'register.deleted'],
			array_map(fn (AuditTrail $row): ?string => $row->getAction(), $this->written)
		);
		$this->assertSame([['path' => 'title', 'old' => 'A', 'new' => 'B']], $this->written[1]->getChanged()['fields']);
		$this->assertSame('system', $this->written[0]->getUser());
	}

	public function testTheRowNamesTheCauseOfTheWrite(): void {
		$listener = $this->listener();
		WriteCause::runAs(
			new CauseFrame(cause: WriteCause::IMPORT, run: 'run-42'),
			fn () => $listener->handle(new RegisterDeletedEvent(self::register('A')))
		);
		$this->assertSame('import', $this->written[0]->getCause());
		$this->assertSame('run-42', $this->written[0]->getCauseRun());
	}
}
