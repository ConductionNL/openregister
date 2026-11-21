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
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Event\OrganisationCreatedEvent;
use OCA\OpenRegister\Kernel\Contracts\OrganisationInterface;
use OCA\OpenRegister\Event\OrganisationDeletedEvent;
use OCA\OpenRegister\Event\OrganisationUpdatedEvent;
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

	/**
	 * A real organisation entity.
	 *
	 * @param string $name The organisation's name.
	 *
	 * @return Organisation
	 */
	private static function organisation(string $name): Organisation {
		$organisation = new Organisation();
		$organisation->setId(3);
		$organisation->setUuid('9f1c2d3e-0000-4000-8000-000000000003');
		$organisation->setSlug('gemeente-noord');
		$organisation->setName($name);
		return $organisation;
	}

	/**
	 * Renaming an organisation writes one row, organisation.updated, with the old and new name.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/history-schema-and-settings-edits-audited/tasks.md
	 */
	public function testAnOrganisationRenameWritesOneRow(): void {
		$this->listener()->handle(new OrganisationUpdatedEvent(self::organisation('Gemeente Noordstad'), self::organisation('Gemeente Noord')));

		$this->assertCount(1, $this->written);
		$row = $this->written[0];
		$this->assertSame('organisation.updated', $row->getAction());
		$this->assertSame('9f1c2d3e-0000-4000-8000-000000000003', $row->getOrganisationId());
		$this->assertSame('gemeente-noord', $row->getChanged()['slug']);
		$this->assertSame([['path' => 'name', 'old' => 'Gemeente Noord', 'new' => 'Gemeente Noordstad']], $row->getChanged()['fields']);
		$this->assertArrayNotHasKey('versionBefore', $row->getChanged());
	}

	/**
	 * An organisation created by a repair step names that cause; an unchanged save writes nothing; a delete writes one row.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/history-schema-and-settings-edits-audited/tasks.md
	 */
	public function testAnOrganisationCreatedByRepairNamesItsCause(): void {
		$listener = $this->listener();
		WriteCause::runAs(
			new CauseFrame(cause: WriteCause::MIGRATION, run: 'repair-7'),
			fn () => $listener->handle(new OrganisationCreatedEvent(self::organisation('Gemeente Noord')))
		);
		$listener->handle(new OrganisationUpdatedEvent(self::organisation('Gemeente Noord'), self::organisation('Gemeente Noord')));
		$listener->handle(new OrganisationDeletedEvent(self::organisation('Gemeente Noord')));

		$this->assertSame(['organisation.created', 'organisation.deleted'], array_map(fn (AuditTrail $row): ?string => $row->getAction(), $this->written));
		$this->assertSame('migration', $this->written[0]->getCause());
		$this->assertSame('repair-7', $this->written[0]->getCauseRun());
		$this->assertSame(['slug' => 'gemeente-noord', 'title' => 'Gemeente Noord', 'version' => null, 'properties' => []], $this->written[0]->getChanged());
		$this->assertSame(64, strlen($this->written[1]->getChanged()['sha256']));
	}

	/**
	 * The organisation events carry the Core interface; an implementation that is not the
	 * stored entity has no audit identity, so it writes nothing rather than failing.
	 *
	 * @return void
	 */
	public function testAnOrganisationThatIsNotTheEntityWritesNothing(): void {
		$other    = $this->createMock(OrganisationInterface::class);
		$listener = $this->listener();
		$listener->handle(new OrganisationCreatedEvent($other));
		$listener->handle(new OrganisationUpdatedEvent($other, self::organisation('Gemeente Noord')));
		$listener->handle(new OrganisationDeletedEvent($other));

		$this->assertSame([], $this->written);
	}
}
