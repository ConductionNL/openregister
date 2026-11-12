<?php

/**
 * Golden test for the read-state and queued-notification mappers.
 *
 * Records every query-builder call and the outcome of each public method of
 * NotificationReadStateMapper and QueuedNotificationMapper: row found, row
 * missing, a lost insert race and a failing read. Re-record with UPDATE_GOLDEN=1.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Notifications\Db
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Notifications\Db;

use OCA\OpenRegister\Notifications\Db\NotificationReadStateEntity;
use OCA\OpenRegister\Notifications\Db\NotificationReadStateMapper;
use OCA\OpenRegister\Notifications\Db\QueuedNotificationMapper;
use OCA\OpenRegister\Tests\Unit\Db\RecordingQueryBuilderTrait;
use OCP\AppFramework\Db\Entity;
use OCP\DB\Exception as DbException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Notifications\Db\NotificationReadStateMapper
 * @covers \OCA\OpenRegister\Notifications\Db\QueuedNotificationMapper
 * @uses   \OCA\OpenRegister\Notifications\Db\NotificationReadStateEntity
 * @uses   \OCA\OpenRegister\Notifications\Db\QueuedNotification
 */
class NotificationMappersGoldenTest extends TestCase {
	use RecordingQueryBuilderTrait;

	private const FIXTURE = __DIR__ . '/fixtures/notification-mappers-golden.json';

	private const READ_ROW = ['id' => 4, 'user_id' => 'alice', 'notification_id' => 'n-1', 'read_at' => '2026-01-02 03:04:05'];

	private const QUEUED_ROW = ['id' => 9, 'schema_id' => 2, 'rule_key' => 'r', 'recipient' => 'bob', 'reason' => 'quiet', 'object_uuid' => 'o-1', 'payload' => '{}', 'created_at' => '2026-01-02 03:04:05'];

	public function testMapperQueriesAndOutcomesMatchGolden(): void {
		$actual = [
			'markRead found'         => $this->readState(call: ['markRead', [[self::READ_ROW]], false]),
			'markRead inserts'       => $this->readState(call: ['markRead', [[]], false]),
			'markRead lost race'     => $this->readState(call: ['markRead', [[], [self::READ_ROW]], true]),
			'markRead race no row'   => $this->readState(call: ['markRead', [[], []], true]),
			'markUnread'             => $this->readState(call: ['markUnread', [], false]),
			'isRead found'           => $this->readState(call: ['isRead', [[self::READ_ROW]], false]),
			'isRead missing'         => $this->readState(call: ['isRead', [[]], false]),
			'findAll rows'           => $this->queued(call: ['findAll', [[self::QUEUED_ROW, self::QUEUED_ROW]], false]),
			'findAll read failure'   => $this->queued(call: ['findAll', [], true]),
			'findById found'         => $this->queued(call: ['findById', [[self::QUEUED_ROW]], false]),
			'findById missing'       => $this->queued(call: ['findById', [[]], false]),
			'findById read failure'  => $this->queued(call: ['findById', [], true]),
			'deleteById'             => $this->queued(call: ['deleteById', [], false]),
			'deleteById failure'     => $this->queued(call: ['deleteById', [], true]),
		];

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);
		foreach ($expected as $name => $outcome) {
			$this->assertSame($outcome, $actual[$name] ?? null, $name);
		}

		$this->assertSame(array_keys($expected), array_keys($actual));
	}//end testMapperQueriesAndOutcomesMatchGolden()

	private function readState(array $call): array {
		[$method, $rows, $insertFails] = $call;
		$db = $this->database(rows: $rows, failing: false);
		$mapper = new NotificationReadStateMapper($db);
		if ($insertFails === true) {
			$mapper = new class ($db) extends NotificationReadStateMapper {
				public function insert(Entity $entity): Entity {
					throw new DbException('duplicate key');
				}
			};
		}

		return $this->outcome(fn (): mixed => $mapper->$method('alice', 'n-1'));
	}//end readState()

	private function queued(array $call): array {
		[$method, $rows, $failing] = $call;
		$mapper = new QueuedNotificationMapper($this->database(rows: $rows, failing: $failing));
		$args = $method === 'findAll' ? [] : [9];

		return $this->outcome(fn (): mixed => $mapper->$method(...$args));
	}//end queued()

	private function database(array $rows, bool $failing): IDBConnection {
		$this->queryLog = [];
		$this->builderCount = 0;
		$this->parameterCount = 0;
		$this->queuedRows = $rows;
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(
			function () use ($failing): IQueryBuilder {
				if ($failing === true) {
					throw new RuntimeException('database down');
				}

				return $this->recordingBuilder();
			}
		);

		return $db;
	}//end database()

	private function outcome(callable $act): array {
		$outcome = [];
		try {
			$outcome['result'] = $this->render(value: $act());
		} catch (\Throwable $e) {
			$outcome['thrown'] = get_class($e) . ': ' . $e->getMessage();
		}

		$outcome['queries'] = $this->queryLog;
		return $outcome;
	}//end outcome()

	private function render(mixed $value): mixed {
		if (is_array($value) === true) {
			return array_map(fn (mixed $item): mixed => $this->render(value: $item), $value);
		}

		if ($value instanceof NotificationReadStateEntity === true) {
			return ['id' => $value->getId(), 'userId' => $value->getUserId(), 'notificationId' => $value->getNotificationId(), 'hasReadAt' => $value->getReadAt() !== null];
		}

		if ($value instanceof Entity === true) {
			return get_class($value) . '#' . $value->getId();
		}

		return $value;
	}//end render()
}//end class
