<?php

/**
 * The revert query runs against the audit table the migrations define (#4161).
 *
 * `AuditTrailMapper::findByObjectUntil()` filtered on a column `object_id` the
 * table never had, so every revert answered 500. The unit tests stayed green
 * because every one of them mocked either the query builder or the mapper, and
 * a mock accepts any column name. These tests build the table by running the
 * app's own migrations into SQLite and execute the mapper's query as SQL.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/content-versioning/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Tests\Support\MigratedSqliteDatabase;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\OpenRegister\Db\AuditTrailMapper
 * @covers \OCA\OpenRegister\Db\AuditTrailPayloadHelper
 */
class AuditTrailMapperRevertQueryTest extends TestCase {
	private const OBJECT = '11111111-1111-4111-8111-111111111111';

	private const OTHER = '22222222-2222-4222-8222-222222222222';

	private MigratedSqliteDatabase $database;

	private MagicMapper $magicMapper;

	private AuditTrailMapper $mapper;

	protected function setUp(): void {
		$this->database = new MigratedSqliteDatabase($this, ['openregister_audit_trails']);
		$this->magicMapper = $this->createMock(MagicMapper::class);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			fn (string $id) => $id === MagicMapper::class ? $this->magicMapper : null
		);

		$this->mapper = new AuditTrailMapper(
			db: $this->database->idbConnection(),
			container: $container,
			userSession: $this->createMock(IUserSession::class),
			request: $this->createMock(IRequest::class),
			logger: new NullLogger()
		);

		// The object was created, then edited twice; another object was edited in between.
		$this->row(id: 1, uuid: self::OBJECT, action: 'create', version: '0.0.1', created: '2026-09-28 10:00:00', changed: [
			'title' => ['old' => null, 'new' => 'first'],
		]);
		$this->row(id: 2, uuid: self::OBJECT, action: 'update', version: '0.0.2', created: '2026-09-28 10:05:00', changed: [
			'title' => ['old' => 'first', 'new' => 'second'],
			'note' => ['old' => null, 'new' => 'added later'],
		]);
		$this->row(id: 3, uuid: self::OTHER, action: 'update', version: '0.0.9', created: '2026-09-28 10:06:00', changed: [
			'title' => ['old' => 'x', 'new' => 'y'],
		]);
		$this->row(id: 4, uuid: self::OBJECT, action: 'update', version: '0.0.3', created: '2026-09-28 10:10:00', changed: [
			'title' => ['old' => 'second', 'new' => 'third'],
		]);
	}//end setUp()

	/**
	 * The query runs at all: it names only columns the migrations create.
	 */
	public function testFindByObjectUntilRunsAgainstTheMigratedTable(): void {
		$this->assertSame([4, 2, 1], $this->ids($this->mapper->findByObjectUntil(objectUuid: self::OBJECT)));
	}//end testFindByObjectUntilRunsAgainstTheMigratedTable()

	/**
	 * Reverting to an audit entry undoes only this object's later entries.
	 *
	 * The id arrives as an integer from a JSON body.
	 */
	public function testUntilAnAuditTrailIdReturnsOnlyThisObjectsLaterEntries(): void {
		$this->assertSame([4, 2], $this->ids($this->mapper->findByObjectUntil(objectUuid: self::OBJECT, until: 1)));
		$this->assertSame([4], $this->ids($this->mapper->findByObjectUntil(objectUuid: self::OBJECT, until: '2')));
	}//end testUntilAnAuditTrailIdReturnsOnlyThisObjectsLaterEntries()

	/**
	 * Reverting to a version undoes the entries made after that version.
	 */
	public function testUntilAVersionReturnsTheEntriesAfterIt(): void {
		$this->assertSame([4, 2], $this->ids($this->mapper->findByObjectUntil(objectUuid: self::OBJECT, until: '0.0.1')));
		$this->assertSame([4], $this->ids($this->mapper->findByObjectUntil(objectUuid: self::OBJECT, until: '0.0.2')));
	}//end testUntilAVersionReturnsTheEntriesAfterIt()

	/**
	 * A revert to the create entry restores the data as it was created.
	 */
	public function testRevertObjectRestoresTheDataOfTheChosenEntry(): void {
		$object = new ObjectEntity();
		$object->setUuid(self::OBJECT);
		$object->setVersion('0.0.3');
		$object->setObject(['title' => 'third', 'note' => 'added later']);
		$this->magicMapper->method('find')->willReturn($object);

		$reverted = $this->mapper->revertObject(identifier: self::OBJECT, until: 1);

		$this->assertSame('first', $reverted->getObject()['title']);
		// The property the later edit added is gone again.
		$this->assertArrayNotHasKey('note', $reverted->getObject());
		$this->assertSame('0.0.4', $reverted->getVersion());
		// The current object is untouched: the revert works on a clone.
		$this->assertSame('third', $object->getObject()['title']);
	}//end testRevertObjectRestoresTheDataOfTheChosenEntry()

	/**
	 * Insert one audit row.
	 *
	 * @param int    $id      Row id.
	 * @param string $uuid    Object UUID.
	 * @param string $action  Action.
	 * @param string $version Object version after the change.
	 * @param string $created Timestamp.
	 * @param array  $changed Change set.
	 *
	 * @return void
	 */
	private function row(int $id, string $uuid, string $action, string $version, string $created, array $changed): void {
		$this->database->insert(
			'openregister_audit_trails',
			[
				'id' => $id,
				'uuid' => sprintf('aaaaaaaa-aaaa-4aaa-8aaa-%012d', $id),
				'object' => 7,
				'object_uuid' => $uuid,
				'action' => $action,
				'version' => $version,
				'created' => $created,
				'changed' => json_encode($changed),
			]
		);
	}//end row()

	/**
	 * The ids of the returned entries, in order.
	 *
	 * @param AuditTrail[] $entries The entries.
	 *
	 * @return int[]
	 */
	private function ids(array $entries): array {
		return array_map(fn (AuditTrail $entry): int => (int) $entry->getId(), $entries);
	}//end ids()
}//end class
