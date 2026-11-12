<?php

/**
 * Golden test for the entity and mapper methods several lib/Db classes share.
 *
 * Pins findAll() of the log and destruction-list mappers, deleteByObject() of
 * the case-item, read-state and watcher mappers, the ISO timestamps of
 * Organisation, Configuration, Application and View, the status vocabulary check,
 * setAuthorization() of Application and Organisation and hydrate() of Task
 * and CaseItem.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Db
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db;

use DateTime;
use OCA\OpenRegister\Db\Application;
use OCA\OpenRegister\Db\CaseItem;
use OCA\OpenRegister\Db\CaseItemMapper;
use OCA\OpenRegister\Db\Configuration;
use OCA\OpenRegister\Db\ContentReport;
use OCA\OpenRegister\Db\EndpointLogMapper;
use OCA\OpenRegister\Db\ObjectReadStateMapper;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\ProcessingPurpose;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\Verwerkingsactiviteit;
use OCA\OpenRegister\Notifications\Db\WatcherMapper;
use OCA\OpenRegister\Db\WebhookLogMapper;
use OCA\OpenRegister\Search\Db\View;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * @covers \OCA\OpenRegister\Db\Application
 * @covers \OCA\OpenRegister\Db\CaseItem
 * @covers \OCA\OpenRegister\Db\CaseItemMapper
 * @covers \OCA\OpenRegister\Db\Configuration
 * @covers \OCA\OpenRegister\Db\ContentReport
 * @covers \OCA\OpenRegister\Db\EndpointLogMapper
 * @covers \OCA\OpenRegister\Db\ObjectReadStateMapper
 * @covers \OCA\OpenRegister\Db\Organisation
 * @covers \OCA\OpenRegister\Db\ProcessingPurpose
 * @covers \OCA\OpenRegister\Db\Task
 * @covers \OCA\OpenRegister\Db\Verwerkingsactiviteit
 * @covers \OCA\OpenRegister\Notifications\Db\WatcherMapper
 * @covers \OCA\OpenRegister\Db\WebhookLogMapper
 * @covers \OCA\OpenRegister\Search\Db\View
 * @uses   \OCA\OpenRegister\Db\EndpointLog
 * @uses   \OCA\OpenRegister\Db\WebhookLog
 * @uses   \OCA\OpenRegister\Db\QueryPage
 * @uses \OCA\OpenRegister\Db\TypedFieldsJson
 */
class SharedDbMethodsGoldenTest extends TestCase {
	use RecordingQueryBuilderTrait;

	private function connection(): IDBConnection {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn () => $this->recordingBuilder());
		return $db;
	}//end connection()

	/**
	 * @return array<string, array{class-string}>
	 */
	public static function findAllMappers(): array {
		return [
			'endpoint log'     => [EndpointLogMapper::class],
			'webhook log'      => [WebhookLogMapper::class],
		];
	}//end findAllMappers()

	/**
	 * @param class-string $class The mapper.
	 *
	 * @return void
	 *
	 * @dataProvider findAllMappers
	 */
	public function testFindAllOrdersNewestFirstAndPages(string $class): void {
		$mapper = new $class($this->connection());
		$this->queuedRows = [[], [['id' => 4]]];

		$this->assertSame([], $mapper->findAll());
		$found = $mapper->findAll(limit: 5, offset: 10);

		$this->assertCount(1, $found);
		$this->assertSame(4, $found[0]->getId());
		$this->assertSame(
			[
				'#1 select(*)',
				'#1 from(' . $this->tableOf($mapper) . ', NULL)',
				'#1 orderBy(created, DESC)',
				'#1 executeQuery()',
				'#2 select(*)',
				'#2 from(' . $this->tableOf($mapper) . ', NULL)',
				'#2 orderBy(created, DESC)',
				'#2 setMaxResults(5)',
				'#2 setFirstResult(10)',
				'#2 executeQuery()',
			],
			$this->queryLog
		);
	}//end testFindAllOrdersNewestFirstAndPages()

	/**
	 * @return array<string, array{class-string}>
	 */
	public static function objectMappers(): array {
		return [
			'case item'  => [CaseItemMapper::class],
			'read state' => [ObjectReadStateMapper::class],
			'watcher'    => [WatcherMapper::class],
		];
	}//end objectMappers()

	/**
	 * @param class-string $class The mapper.
	 *
	 * @return void
	 *
	 * @dataProvider objectMappers
	 */
	public function testDeleteByObjectRemovesTheRowsOfOneObject(string $class): void {
		$mapper = new $class($this->connection());

		$this->assertSame(1, $mapper->deleteByObject('obj-1'));
		$this->assertSame(
			[
				'#1 delete(' . $this->tableOf($mapper) . ', NULL)',
				'#1 param :p1 = obj-1, 2',
				'#1 expr.eq(object_uuid, :p1, NULL)',
				'#1 where(eq(object_uuid, :p1, NULL))',
				'#1 executeStatement()',
			],
			$this->queryLog
		);
	}//end testDeleteByObjectRemovesTheRowsOfOneObject()

	public function testTimestampsAreIsoOrNull(): void {
		foreach ([new Organisation(), new Configuration(), new Application(), new View()] as $entity) {
			$created = new ReflectionMethod($entity, 'getCreatedFormatted');
			$updated = new ReflectionMethod($entity, 'getUpdatedFormatted');
			$this->assertNull($created->invoke($entity));
			$this->assertNull($updated->invoke($entity));

			$entity->setCreated(new DateTime('2026-01-02T03:04:05+00:00'));
			$entity->setUpdated(new DateTime('2026-06-07T08:09:10+02:00'));
			$this->assertSame('2026-01-02T03:04:05+00:00', $created->invoke($entity));
			$this->assertSame('2026-06-07T08:09:10+02:00', $updated->invoke($entity));
		}
	}//end testTimestampsAreIsoOrNull()

	public function testStatusVocabularyAcceptsOnlyItsOwnStatuses(): void {
		$verdicts = [];
		foreach ([ContentReport::class, ProcessingPurpose::class, Verwerkingsactiviteit::class] as $class) {
			foreach ([null, '', 'open', 'upheld', 'active', 'retired', 'concept', 'archived', 'Open', 'unknown'] as $status) {
				$verdicts[] = $class::isValidStatus($status);
			}
		}

		$this->assertSame(
			[
				false, false, true, true, false, false, false, false, false, false,
				false, false, false, false, true, true, false, false, false, false,
				false, false, false, false, false, false, true, true, false, false,
			],
			$verdicts
		);
	}//end testStatusVocabularyAcceptsOnlyItsOwnStatuses()

	public function testSetAuthorizationDecodesJsonAndFallsBackToTheDefault(): void {
		foreach ([new Application(), new Organisation()] as $entity) {
			$default = $entity->getAuthorization();

			$this->assertSame($entity, $entity->setAuthorization('{"read":["admin"]}'));
			$this->assertSame(['read' => ['admin']], $entity->getAuthorization());
			$this->assertArrayHasKey('authorization', $entity->getUpdatedFields());

			$entity->setAuthorization('{not json');
			$this->assertSame($default, $entity->getAuthorization());

			$entity->setAuthorization('"a string"');
			$this->assertSame($default, $entity->getAuthorization());

			$entity->setAuthorization(['update' => ['x']]);
			$this->assertSame(['update' => ['x']], $entity->getAuthorization());

			$entity->setAuthorization(null);
			$this->assertSame($default, $entity->getAuthorization());
		}
	}//end testSetAuthorizationDecodesJsonAndFallsBackToTheDefault()

	public function testHydrateSetsKnownFieldsExceptTheId(): void {
		$task = (new Task())->hydrate(['id' => 9, 'title' => 'Check', 'kind' => 'user', 'nope' => 1]);
		$this->assertNull($task->getId());
		$this->assertSame('Check', $task->getTitle());
		$this->assertSame('user', $task->getKind());
		$this->assertSame(['title' => true, 'kind' => true], $task->getUpdatedFields());

		$item = (new CaseItem())->hydrate(['id' => 9, 'name' => 'Item', 'registerId' => 3, 'nope' => 1]);
		$this->assertNull($item->getId());
		$this->assertSame('Item', $item->getName());
		$this->assertSame(3, $item->getRegisterId());
		$this->assertSame(['name' => true, 'registerId' => true], $item->getUpdatedFields());
	}//end testHydrateSetsKnownFieldsExceptTheId()

	private function tableOf(object $mapper): string {
		return $mapper->getTableName();
	}//end tableOf()
}//end class
