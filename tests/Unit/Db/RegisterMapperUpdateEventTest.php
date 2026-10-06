<?php

/**
 * RegisterMapper::update() event tests.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\RegisterUpdatedEvent;
use OCP\AppFramework\Db\Entity;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Drives the real RegisterMapper::update() and checks which saves dispatch
 * a RegisterUpdatedEvent.
 *
 * Only the database and the access checks are stood in for: the stored row is
 * mapped through the real Register entity, exactly as findEntity() would, and
 * the dispatched event is the real RegisterUpdatedEvent.
 *
 * @spec openspec/changes/object-update-names-the-object/specs/activity-provider/spec.md#requirement-a-register-save-that-changes-nothing-publishes-nothing
 */
class RegisterMapperUpdateEventTest extends TestCase {
	/**
	 * The register row as the database holds it.
	 *
	 * @var array<string, mixed>
	 */
	private const STORED_ROW = [
		'id' => '20',
		'uuid' => '56700377-32ae-4fb2-bd20-346c68a51364',
		'slug' => 'pipelinq',
		'title' => 'Pipelinq CRM Register',
		'version' => '1.0.0',
		'description' => 'Client Relationship Management register.',
		'schemas' => '[28,29,30]',
		'source' => 'internal',
		'table_prefix' => '',
		'folder' => '96',
		'updated' => '2026-10-06 11:38:31',
		'created' => '2026-10-06 11:38:31',
		'owner' => 'pipelinq',
		'application' => 'pipelinq',
		'organisation' => '9ba9226e-0d4b-4039-b9a6-166ade0c8a56',
		'type' => 'application',
	];

	/**
	 * Events the mapper dispatched.
	 *
	 * @var array<int, object>
	 */
	private array $dispatched = [];

	/**
	 * Build a mapper whose database answers with STORED_ROW.
	 *
	 * @return RegisterMapper The mapper under test.
	 */
	private function mapper(): RegisterMapper {
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('id = :id');

		$qb = $this->createMock(IQueryBuilder::class);
		foreach (['select', 'from', 'where', 'update', 'set', 'andWhere'] as $chain) {
			$qb->method($chain)->willReturnSelf();
		}

		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturn(':p');
		$qb->method('executeStatement')->willReturn(1);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			function (object $event): void {
				$this->dispatched[] = $event;
			}
		);

		return new class(
			$db,
			$this->createMock(SchemaMapper::class),
			$dispatcher,
			$this->createMock(ContainerInterface::class),
			$this->createMock(OrganisationMapper::class),
			$this->createMock(IUserSession::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(IAppConfig::class),
			$this->createMock(LoggerInterface::class)
		) extends RegisterMapper {
			/**
			 * Return the stored row mapped through the real entity.
			 *
			 * @param IQueryBuilder $query Ignored.
			 *
			 * @return Entity The stored register.
			 */
			protected function findEntity(IQueryBuilder $query): Entity {
				return Register::fromRow(RegisterMapperUpdateEventTest::storedRow());
			}

			/**
			 * Access is not under test.
			 *
			 * @param string $action Ignored.
			 * @param string $entityType Ignored.
			 *
			 * @return void
			 */
			protected function verifyRbacPermission(string $action, string $entityType): void {
			}

			/**
			 * Access is not under test.
			 *
			 * @param Entity $entity Ignored.
			 *
			 * @return void
			 */
			protected function verifyOrganisationAccess(Entity $entity): void {
			}
		};
	}//end mapper()

	/**
	 * The stored row, for the anonymous mapper.
	 *
	 * @return array<string, mixed> The row.
	 */
	public static function storedRow(): array {
		return self::STORED_ROW;
	}//end storedRow()

	/**
	 * Build the register an import hands to update(): the stored register with
	 * every field written again from the app's register file.
	 *
	 * @return Register The re-imported register.
	 */
	private function reimported(): Register {
		$register = Register::fromRow(self::STORED_ROW);
		$register->setTitle('Pipelinq CRM Register');
		$register->setDescription('Client Relationship Management register.');
		$register->setVersion('1.0.0');
		// An import writes the schema ids it resolved, which may differ in type
		// from what the row decoded to; both mean the same three schemas.
		$register->setSchemas(['28', '29', '30']);
		$register->setUpdated(new \DateTime());

		return $register;
	}//end reimported()

	/**
	 * A re-import that changes nothing dispatches no RegisterUpdatedEvent, so no
	 * "Register updated" activity and no "Register was updated" popup follow.
	 *
	 * @return void
	 */
	public function testUnchangedSaveDispatchesNoEvent(): void {
		$this->mapper()->update($this->reimported());

		$this->assertSame([], $this->dispatched);
	}//end testUnchangedSaveDispatchesNoEvent()

	/**
	 * A real change still dispatches exactly one RegisterUpdatedEvent carrying
	 * the old and the new register.
	 *
	 * @return void
	 */
	public function testTitleChangeDispatchesOneEvent(): void {
		$register = $this->reimported();
		$register->setTitle('Pipelinq CRM');

		$this->mapper()->update($register);

		$this->assertCount(1, $this->dispatched);
		$event = $this->dispatched[0];
		$this->assertInstanceOf(RegisterUpdatedEvent::class, $event);
		$this->assertSame('Pipelinq CRM', $event->getNewRegister()->getTitle());
		$this->assertSame('Pipelinq CRM Register', $event->getOldRegister()->getTitle());
	}//end testTitleChangeDispatchesOneEvent()

	/**
	 * A register that gains a schema has changed.
	 *
	 * @return void
	 */
	public function testAddedSchemaDispatchesOneEvent(): void {
		$register = $this->reimported();
		$register->setSchemas([28, 29, 30, 31]);

		$this->mapper()->update($register);

		$this->assertCount(1, $this->dispatched);
	}//end testAddedSchemaDispatchesOneEvent()
}//end class
