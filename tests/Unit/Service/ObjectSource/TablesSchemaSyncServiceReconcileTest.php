<?php

/**
 * The Tables schema reconciler's remaining decisions: creating the register
 * when absent, updating a managed schema in place without relinking it,
 * naming an untitled table, skipping unreadable and foreign schemas, keeping
 * a schema it failed to delete, and answering false when no register exists.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ObjectSource
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ObjectSource;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\ObjectSource\TablesColumnMapper;
use OCA\OpenRegister\Service\ObjectSource\TablesSchemaSyncService;
use OCA\OpenRegister\Service\ObjectSource\TablesUuidDeriver;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Reconcile tests for {@see TablesSchemaSyncService}.
 */
class TablesSchemaSyncServiceReconcileTest extends TestCase {

	/**
	 * The register mapper.
	 *
	 * @var RegisterMapperInterface&MockObject
	 */
	private RegisterMapperInterface&MockObject $registers;

	/**
	 * The schema mapper.
	 *
	 * @var SchemaMapperInterface&MockObject
	 */
	private SchemaMapperInterface&MockObject $schemas;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The service under test.
	 *
	 * @var TablesSchemaSyncService
	 */
	private TablesSchemaSyncService $service;

	/**
	 * Build the reconciler over mocked mappers and the real column mapper.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->registers = $this->createMock(RegisterMapperInterface::class);
		$this->schemas = $this->createMock(SchemaMapperInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->service = new TablesSchemaSyncService(
			registerMapper: $this->registers,
			schemaMapper: $this->schemas,
			columnMapper: new TablesColumnMapper(uuidDeriver: new TablesUuidDeriver(), logger: new NullLogger()),
			logger: $this->logger
		);
	}//end setUp()

	/**
	 * The tables register.
	 *
	 * @param array<int, int|string> $schemaIds Linked schemas.
	 *
	 * @return Register The register.
	 */
	private function register(array $schemaIds = []): Register {
		$register = new Register();
		$register->setId(30);
		$register->setSlug('tables');
		$register->setSchemas($schemaIds);

		return $register;
	}//end register()

	/**
	 * A schema declaring a tables object source.
	 *
	 * @param int $id The schema id.
	 * @param int $tableId The table it projects.
	 * @param string $provider The declaring provider.
	 *
	 * @return Schema The schema.
	 */
	private function schema(int $id, int $tableId, string $provider = 'tables'): Schema {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setSlug('nc-x-t' . $tableId);
		$schema->setConfiguration(
			['x-openregister-object-source' => ['provider' => $provider, 'readOnly' => true, 'config' => ['tableId' => $tableId, 'managed' => true]]]
		);

		return $schema;
	}//end schema()

	/**
	 * With no register yet, reconcile creates it and links a newly seeded
	 * schema; an untitled table's schema is titled by its slug.
	 *
	 * @return void
	 */
	public function testReconcileCreatesTheRegisterAndTitlesAnUntitledTableBySlug(): void {
		$register = $this->register();
		$this->registers->method('find')->willThrowException(new DoesNotExistException('none'));
		$this->registers->expects($this->once())
			->method('createFromArray')
			->with($this->callback(static fn (array $object): bool => $object['slug'] === 'tables' && $object['schemas'] === [] && $object['application'] === 'tables'))
			->willReturn($register);
		$this->schemas->method('find')->willThrowException(new DoesNotExistException('none'));
		$created = $this->schema(id: 81, tableId: 4);
		$this->schemas->expects($this->once())
			->method('createFromArray')
			->with($this->callback(static fn (array $payload): bool => $payload['title'] === 'nc-table-t4' && $payload['slug'] === 'nc-table-t4'))
			->willReturn($created);
		$this->registers->expects($this->once())->method('update')->with($register);

		$stats = $this->service->reconcile(tables: [['id' => 4, 'title' => '', 'columns' => []]]);

		$this->assertSame(['seeded' => 1, 'retired' => 0, 'skipped' => 0], $stats);
		$this->assertSame([81], $register->getSchemas());
	}//end testReconcileCreatesTheRegisterAndTitlesAnUntitledTableBySlug()

	/**
	 * A managed schema is updated in place, and one already linked is not
	 * linked again.
	 *
	 * @return void
	 */
	public function testAManagedSchemaIsUpdatedInPlaceWithoutRelinking(): void {
		$existing = $this->schema(id: 81, tableId: 4);
		$register = $this->register(schemaIds: [81]);
		$this->registers->method('find')->willReturn($register);
		$this->schemas->method('find')->willReturn($existing);
		$this->schemas->expects($this->never())->method('createFromArray');
		$this->schemas->expects($this->once())
			->method('updateFromArray')
			->with(81, $this->callback(static fn (array $payload): bool => $payload['title'] === 'Orders' && $payload['slug'] === 'nc-orders-t4'));
		$this->registers->expects($this->never())->method('update');
		$this->schemas->expects($this->never())->method('delete');

		$stats = $this->service->reconcile(tables: [['id' => 4, 'title' => 'Orders', 'columns' => []]]);

		$this->assertSame(['seeded' => 1, 'retired' => 0, 'skipped' => 0], $stats);
	}//end testAManagedSchemaIsUpdatedInPlaceWithoutRelinking()

	/**
	 * Retirement skips a linked schema that cannot be read and one another
	 * provider declares; a managed schema whose delete fails stays linked.
	 *
	 * @return void
	 */
	public function testRetirementSkipsUnreadableAndForeignSchemasAndKeepsAFailedDelete(): void {
		$register = $this->register(schemaIds: ['gone', 82, 83]);
		$this->registers->method('find')->willReturn($register);
		$stuck = $this->schema(id: 83, tableId: 9);
		$this->schemas->method('find')->willReturnCallback(
			fn (string|int $ref): Schema => match ($ref) {
				'gone' => throw new DoesNotExistException('gone'),
				82 => $this->schema(id: 82, tableId: 8, provider: 'deck'),
				83 => $stuck,
			}
		);
		$this->schemas->expects($this->once())->method('delete')->with($stuck)->willThrowException(new RuntimeException('locked'));
		$this->logger->expects($this->once())->method('warning')->with('[ObjectSource:tables] could not retire schema nc-x-t9: locked');
		$this->registers->expects($this->never())->method('update');

		$this->assertSame(['seeded' => 0, 'retired' => 0, 'skipped' => 0], $this->service->reconcile(tables: []));
		$this->assertSame(['gone', 82, 83], $register->getSchemas());
	}//end testRetirementSkipsUnreadableAndForeignSchemasAndKeepsAFailedDelete()

	/**
	 * Without a tables register, a single-table retirement retires nothing
	 * and no table has a managed schema.
	 *
	 * @return void
	 */
	public function testWithoutARegisterNothingIsRetiredOrManaged(): void {
		$this->registers->method('find')->willThrowException(new DoesNotExistException('none'));
		$this->schemas->expects($this->never())->method('delete');

		$this->assertFalse($this->service->retireByTableId(tableId: 4));
		$this->assertFalse($this->service->hasManagedSchemaForTableId(tableId: 4));
	}//end testWithoutARegisterNothingIsRetiredOrManaged()
}//end class
