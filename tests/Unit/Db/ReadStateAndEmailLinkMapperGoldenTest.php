<?php

/**
 * Golden test for ObjectReadStateMapper's seen writes and reads, and the
 * EmailLinkMapper message lookup.
 *
 * Records every query-builder call and the outcome of markSeen() (refresh,
 * insert, a lost insert race with and without a winner), uuidsForUser() over
 * its scope filters and served rows, and findByObjectAccountMessageUid() with
 * and without a message uid, for none, one and several rows. Re-record with
 * UPDATE_GOLDEN=1.
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
use DateTimeInterface;
use DateTimeZone;
use OCA\OpenRegister\Db\EmailLink;
use OCA\OpenRegister\Db\EmailLinkMapper;
use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use OCA\OpenRegister\Db\MailMessageKey;
use OCA\OpenRegister\Db\ObjectReadState;
use OCA\OpenRegister\Db\ObjectReadStateMapper;
use OCP\AppFramework\Db\Entity;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Db\ObjectReadStateMapper
 * @covers \OCA\OpenRegister\Db\EmailLinkMapper
 */
final class ReadStateAndEmailLinkMapperGoldenTest extends TestCase {
	use RecordingQueryBuilderTrait;

	private const FIXTURE = __DIR__ . '/fixtures/read-state-email-link-mapper-golden.json';

	/**
	 * markSeen() cases: arguments, rows served, and whether insert() fails.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: array<int, array<int, array<string, mixed>>>, 2: bool}>
	 */
	private static function seenCases(): array {
		$at = new DateTime('2026-03-04T05:06:07+00:00', new DateTimeZone('UTC'));
		$base = ['userId' => 'alice', 'objectUuid' => 'o-1', 'seenAt' => $at];
		$row = [
			'id' => 4, 'user_id' => 'alice', 'object_uuid' => 'o-1', 'register' => 'r-old', 'schema' => 's-old',
			'last_seen_at' => '2026-01-01T00:00:00+00:00', 'sub_seen' => '{"files":"2026-01-01T00:00:00+00:00"}',
		];
		$bare = ['id' => 5, 'user_id' => 'alice', 'object_uuid' => 'o-1', 'register' => null, 'schema' => null, 'sub_seen' => null];
		return [
			'refresh, nothing else' => [$base, [[$row]], false],
			'refresh, scope and sub seen' => [$base + ['register' => 'r-new', 'schema' => 's-new', 'subSeen' => ['messages' => 'm1', 'files' => 'f2']], [[$row]], false],
			'refresh, empty scope' => [$base + ['register' => '', 'schema' => '', 'subSeen' => []], [[$row]], false],
			'refresh, no stored sub seen' => [$base + ['subSeen' => ['notes' => 'n1']], [[$bare]], false],
			'refresh, only register' => [$base + ['register' => 'r-only'], [[$row]], false],
			'refresh, only schema' => [$base + ['schema' => 's-only'], [[$row]], false],
			'insert, nothing else' => [$base, [[]], false],
			'insert, everything' => [$base + ['register' => 'r', 'schema' => 's', 'subSeen' => ['files' => 'f']], [[]], false],
			'insert, empty scope' => [$base + ['register' => '', 'schema' => ''], [[]], false],
			'insert lost the race to a winner' => [$base + ['register' => 'r'], [[], [$row]], true],
			'insert lost the race, no winner' => [$base, [[], []], true],
		];
	}//end seenCases()

	/**
	 * uuidsForUser() cases: arguments and rows served.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: array<int, array<int, array<string, mixed>>>}>
	 */
	private static function uuidCases(): array {
		$rows = [['object_uuid' => 'a'], ['object_uuid' => ''], ['object_uuid' => 'b'], ['object_uuid' => 'a'], ['other' => 'x'], ['object_uuid' => null]];
		return [
			'user only' => [['userId' => 'alice'], [$rows]],
			'register' => [['userId' => 'alice', 'register' => 'r1'], [[]]],
			'schema' => [['userId' => 'alice', 'schema' => 's1'], [[]]],
			'both and a limit' => [['userId' => 'bob', 'register' => 'r1', 'schema' => 's1', 'limit' => 3], [[['object_uuid' => 'z']]]],
			'empty scope' => [['userId' => 'bob', 'register' => '', 'schema' => ''], [[]]],
		];
	}//end uuidCases()

	/**
	 * findByObjectAccountMessageUid() cases: the message uid and rows served.
	 *
	 * @return array<string, array{0: string|null, 1: array<int, array<int, array<string, mixed>>>}>
	 */
	private static function emailCases(): array {
		$link = ['id' => 2, 'object_uuid' => 'o-1', 'mail_account_id' => 3, 'mail_message_id' => 9, 'mail_message_uid' => 'u9'];
		return [
			'no uid, none' => [null, [[]]],
			'no uid, one' => [null, [[$link]]],
			'no uid, several' => [null, [[$link, $link]]],
			'uid, none' => ['u9', [[]]],
			'uid, one' => ['u9', [[$link]]],
			'uid, several' => ['u9', [[$link, $link]]],
			'empty uid, one' => ['', [[$link]]],
		];
	}//end emailCases()

	public function testOutcomesMatchGolden(): void {
		$actual = [];
		foreach (self::seenCases() as $name => [$args, $rows, $failInsert]) {
			$actual['markSeen / ' . $name] = $this->outcome(mapper: $this->readStateMapper(rows: $rows, failInsert: $failInsert), method: 'markSeen', args: ['sighting' => self::sightingOf(args: $args)]);
		}

		foreach (self::uuidCases() as $name => [$args, $rows]) {
			$args = ['userId' => $args['userId'], 'query' => new ListQuery(limit: ($args['limit'] ?? ObjectReadStateMapper::SEEN_SET_LIMIT), filters: array_diff_key($args, ['userId' => 1, 'limit' => 1]))];
			$actual['uuidsForUser / ' . $name] = $this->outcome(mapper: $this->readStateMapper(rows: $rows, failInsert: false), method: 'uuidsForUser', args: $args);
		}

		foreach (self::emailCases() as $name => [$uid, $rows]) {
			$args = ['objectUuid' => 'o-1', 'message' => new MailMessageKey(mailAccountId: 3, mailMessageId: 9, mailMessageUid: $uid)];
			$actual['findByObjectAccountMessageUid / ' . $name] = $this->outcome(mapper: new EmailLinkMapper($this->connection(rows: $rows)), method: 'findByObjectAccountMessageUid', args: $args);
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . "\n");
		}

		$expected = json_decode((string)file_get_contents(self::FIXTURE), true);
		$this->assertSame($expected, json_decode((string)json_encode($actual), true));
	}//end testOutcomesMatchGolden()

	public function testALostRaceWithoutAWinnerRethrowsTheInsertFailure(): void {
		$mapper = $this->readStateMapper(rows: [[], []], failInsert: true);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('unique violation');
		$mapper->markSeen(sighting: self::sightingOf(args: ['userId' => 'alice', 'objectUuid' => 'o-1', 'seenAt' => new DateTime('2026-03-04T05:06:07+00:00')]));
	}//end testALostRaceWithoutAWinnerRethrowsTheInsertFailure()

	/**
	 * The sighting a case describes, built the way the callers build it.
	 *
	 * @param array<string, mixed> $args userId, objectUuid and optionally register, schema, subSeen and seenAt.
	 *
	 * @return ObjectReadState The unsaved sighting.
	 */
	private static function sightingOf(array $args): ObjectReadState {
		$sighting = new ObjectReadState();
		$sighting->setUserId($args['userId']);
		$sighting->setObjectUuid($args['objectUuid']);
		$sighting->setRegister($args['register'] ?? null);
		$sighting->setSchema($args['schema'] ?? null);
		$sighting->setLastSeenAt($args['seenAt'] ?? null);
		if (array_key_exists('subSeen', $args) === true) {
			$sighting->setSubSeen($args['subSeen']);
		}

		return $sighting;
	}//end sightingOf()

	/**
	 * A connection whose builders record and serve the given rows.
	 *
	 * @param array<int, array<int, array<string, mixed>>> $rows Rows per executeQuery().
	 *
	 * @return IDBConnection The connection.
	 */
	private function connection(array $rows): IDBConnection {
		$this->queryLog = [];
		$this->builderCount = 0;
		$this->parameterCount = 0;
		$this->queuedRows = $rows;

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->recordingBuilder());
		return $db;
	}//end connection()

	/**
	 * A read-state mapper over a recording connection, optionally failing insert().
	 *
	 * @param array<int, array<int, array<string, mixed>>> $rows Rows per executeQuery().
	 * @param bool $failInsert Whether insert() throws.
	 *
	 * @return ObjectReadStateMapper The mapper.
	 */
	private function readStateMapper(array $rows, bool $failInsert): ObjectReadStateMapper {
		$db = $this->connection(rows: $rows);
		$log = &$this->queryLog;
		return new class($db, $failInsert, $log) extends ObjectReadStateMapper {
			/**
			 * @param array<int, string> $log
			 */
			public function __construct(
				IDBConnection $db,
				private readonly bool $failInsert,
				private array &$log,
			) {
				parent::__construct($db);
			}

			public function insert(Entity $entity): Entity {
				$this->log[] = 'insert(' . implode(',', array_keys($entity->getUpdatedFields())) . ')';
				if ($this->failInsert === true) {
					throw new RuntimeException('unique violation');
				}

				return $entity;
			}

			public function update(Entity $entity): Entity {
				$this->log[] = 'update(' . implode(',', array_keys($entity->getUpdatedFields())) . ')';
				return $entity;
			}
		};
	}//end readStateMapper()

	/**
	 * Call one mapper method and record its outcome.
	 *
	 * @param object $mapper The mapper.
	 * @param string $method The method.
	 * @param array<string, mixed> $args Named arguments.
	 *
	 * @return array<string, mixed> The result and the query log.
	 */
	private function outcome(object $mapper, string $method, array $args): array {
		$outcome = [];
		try {
			$outcome['result'] = $this->render(value: $mapper->$method(...$args));
		} catch (\Throwable $e) {
			$outcome['thrown'] = get_class($e) . ': ' . $e->getMessage();
		}

		$outcome['queries'] = $this->queryLog;
		return $outcome;
	}//end outcome()

	/**
	 * A result as plain data.
	 *
	 * @param mixed $value The result.
	 *
	 * @return mixed The rendering.
	 */
	private function render(mixed $value): mixed {
		if ($value instanceof ObjectReadState) {
			return [
				'id' => $value->getId(),
				'userId' => $value->getUserId(),
				'objectUuid' => $value->getObjectUuid(),
				'register' => $value->getRegister(),
				'schema' => $value->getSchema(),
				'lastSeenAt' => $value->getLastSeenAt()?->format(DateTimeInterface::ATOM),
				'subSeen' => $value->getSubSeen(),
				'created' => $value->getCreated()?->format(DateTimeInterface::ATOM),
				'updatedFields' => array_keys($value->getUpdatedFields()),
			];
		}

		if ($value instanceof EmailLink) {
			return ['id' => $value->getId(), 'mailMessageUid' => $value->getMailMessageUid()];
		}

		return $value;
	}//end render()
}//end class
