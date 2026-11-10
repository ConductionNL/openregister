<?php

/**
 * Golden test for the notification mappers whose queries were folded onto shared helpers.
 *
 * Records every query-builder call and the outcome of each public method of the
 * dedupe, dispatch-log, history, timeline, broadcast, receipt, watcher and presence
 * mappers: row found, row missing, a lost insert race and a failing read. The
 * recipient- and user-scoped reads and writes are pinned here, so a refactor that
 * dropped or reordered one of their narrowing clauses fails this test. Timestamps
 * computed from "now" are masked. Re-record with UPDATE_GOLDEN=1.
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

use OCA\OpenRegister\Notifications\Db\DispatchIdempotencyKey;
use OCA\OpenRegister\Notifications\Db\HistoryDelivery;
use OCA\OpenRegister\Notifications\Db\HistoryEntry;
use OCA\OpenRegister\Notifications\Db\HistoryNotice;
use OCA\OpenRegister\Notifications\Db\HistorySubject;
use OCA\OpenRegister\Notifications\Db\HistoryTarget;
use OCA\OpenRegister\Notifications\Db\DedupeSighting;
use OCA\OpenRegister\Notifications\Db\NotificationDedupeKey;
use DateTime;
use OCA\OpenRegister\Notifications\Db\BroadcastMessage;
use OCA\OpenRegister\Notifications\Db\BroadcastWindow;
use OCA\OpenRegister\Notifications\Db\NotificationBroadcastMapper;
use OCA\OpenRegister\Notifications\Db\NotificationBroadcastReceiptMapper;
use OCA\OpenRegister\Notifications\Db\NotificationDedupeStateMapper;
use OCA\OpenRegister\Notifications\Db\NotificationDispatchLogMapper;
use OCA\OpenRegister\Notifications\Db\NotificationHistoryMapper;
use OCA\OpenRegister\Notifications\Db\ObjectPresenceMapper;
use OCA\OpenRegister\Notifications\Db\ReferencePatternMapper;
use OCA\OpenRegister\Notifications\Db\TextBlockMapper;
use OCA\OpenRegister\Notifications\Service\Notification\OutgoingBroadcast;
use OCA\OpenRegister\Notifications\Db\TimelineEntryMapper;
use OCA\OpenRegister\Notifications\Db\TimelineEntryScope;
use OCA\OpenRegister\Notifications\Db\TimelineSearch;
use OCA\OpenRegister\Notifications\Db\TimelineKindMapper;
use OCA\OpenRegister\Notifications\Db\Watcher;
use OCA\OpenRegister\Notifications\Db\WatcherMapper;
use OCA\OpenRegister\Notifications\Db\RecipientNotice;
use OCA\OpenRegister\Notifications\Db\TextBlockScope;
use OCA\OpenRegister\Tests\Unit\Db\RecordingQueryBuilderTrait;
use OCP\AppFramework\Db\Entity;
use OCP\DB\Exception as DbException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use OCA\OpenRegister\Db\ListQuery;

/**
 * @covers \OCA\OpenRegister\Notifications\Db\NotificationBroadcastMapper
 * @covers \OCA\OpenRegister\Notifications\Db\NotificationBroadcastReceiptMapper
 * @covers \OCA\OpenRegister\Notifications\Db\NotificationDedupeStateMapper
 * @covers \OCA\OpenRegister\Notifications\Db\NotificationDispatchLogMapper
 * @covers \OCA\OpenRegister\Notifications\Db\NotificationHistoryMapper
 * @covers \OCA\OpenRegister\Notifications\Db\ObjectPresenceMapper
 * @covers \OCA\OpenRegister\Notifications\Db\ReferencePatternMapper
 * @covers \OCA\OpenRegister\Notifications\Db\TextBlockMapper
 * @covers \OCA\OpenRegister\Notifications\Db\TimelineEntryMapper
 * @covers \OCA\OpenRegister\Notifications\Db\TimelineKindMapper
 * @covers \OCA\OpenRegister\Notifications\Db\WatcherMapper
 */
class A26NotificationMappersGoldenTest extends TestCase {
	use RecordingQueryBuilderTrait;

	private const FIXTURE = __DIR__ . '/fixtures/a26-notification-mappers-golden.json';

	private const ROW = ['id' => 7];

	private const COUNT_ROW = ['watcher_count' => 3];

	private const ROWS_TWO = [
		['object_uuid' => 'o-1', 'notify' => null, 'watcher_count' => 2, 'status' => 'sent', 'status_count' => 5, 'rule_key' => 'r1'],
		['object_uuid' => '', 'notify' => 't', 'watcher_count' => 1, 'status' => '', 'status_count' => 1, 'rule_key' => 'r2'],
	];

	public function testMapperQueriesAndOutcomesMatchGolden(): void {
		$actual = [];
		foreach ($this->cases() as $name => [$factory, $method, $args, $rows, $failing]) {
			$actual[$name] = $this->outcome(factory: $factory, method: $method, args: $args, rows: $rows, failing: $failing);
		}

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

	/**
	 * @return array<string, array{0: callable, 1: string, 2: array, 3: array, 4: bool}>
	 */
	private function cases(): array {
		$at = new DateTime('2026-01-02 03:04:05');
		$plain = fn (string $class): callable => fn (IDBConnection $db): object => new $class($db);
		return array_merge(
			$this->dedupeCases(at: $at, plain: $plain),
			$this->historyCases(at: $at, plain: $plain),
			$this->timelineCases(plain: $plain),
			$this->broadcastCases(at: $at, plain: $plain),
			$this->watcherCases(at: $at, plain: $plain),
		);
	}//end cases()

	private function dedupeCases(DateTime $at, callable $plain): array {
		$dedupe = $plain(NotificationDedupeStateMapper::class);
		$log = $plain(NotificationDispatchLogMapper::class);
		$racing = fn (bool $unique): callable => fn (IDBConnection $db): object => new class ($db, $unique) extends NotificationDedupeStateMapper {
			public function __construct(IDBConnection $db, private readonly bool $unique) {
				parent::__construct($db);
			}

			public function insert(Entity $entity): Entity {
				throw A26NotificationMappersGoldenTest::dbFailure(unique: $this->unique);
			}
		};
		$logRacing = fn (bool $unique): callable => fn (IDBConnection $db): object => new class ($db, $unique) extends NotificationDispatchLogMapper {
			public function __construct(IDBConnection $db, private readonly bool $unique) {
				parent::__construct($db);
			}

			public function insert(Entity $entity): Entity {
				throw A26NotificationMappersGoldenTest::dbFailure(unique: $this->unique);
			}
		};
		return [
			'dedupe findOne found'          => [$dedupe, 'findByKey', [new NotificationDedupeKey(2, 'r', 'o-1')], [[self::ROW]], false],
			'dedupe findOne missing'        => [$dedupe, 'findByKey', [new NotificationDedupeKey(2, 'r', 'o-1')], [[]], false],
			'dedupe findOne failure'        => [$dedupe, 'findByKey', [new NotificationDedupeKey(2, 'r', 'o-1')], [], true],
			'dedupe upsert existing'        => [$dedupe, 'upsert', [new NotificationDedupeKey(2, 'r', 'o-1'), new DedupeSighting('fp', $at, false)], [[self::ROW]], false],
			'dedupe upsert existing sent'   => [$dedupe, 'upsert', [new NotificationDedupeKey(2, 'r', 'o-1'), new DedupeSighting('fp', $at, true)], [[self::ROW]], false],
			'dedupe upsert insert'          => [$dedupe, 'upsert', [new NotificationDedupeKey(2, 'r', 'o-1'), new DedupeSighting('fp', $at, false)], [[]], false],
			'dedupe upsert race reread'     => [$racing(true), 'upsert', [new NotificationDedupeKey(2, 'r', 'o-1'), new DedupeSighting('fp', $at, true)], [[], [self::ROW]], false],
			'dedupe upsert race no row'     => [$racing(true), 'upsert', [new NotificationDedupeKey(2, 'r', 'o-1'), new DedupeSighting('fp', $at, true)], [[], []], false],
			'dedupe upsert other failure'   => [$racing(false), 'upsert', [new NotificationDedupeKey(2, 'r', 'o-1'), new DedupeSighting('fp', $at, true)], [[]], false],
			'dedupe deleteByObject'         => [$dedupe, 'deleteByObject', ['o-1'], [], false],
			'dedupe deleteByObject failure' => [$dedupe, 'deleteByObject', ['o-1'], [], true],
			'dedupe deleteByRule'           => [$dedupe, 'deleteByRule', [2, 'r'], [], false],
			'dedupe deleteByRule failure'   => [$dedupe, 'deleteByRule', [2, 'r'], [], true],
			'dedupe deleteSeenBefore'       => [$dedupe, 'deleteSeenBefore', [$at], [], false],
			'dedupe deleteSeenBefore fail'  => [$dedupe, 'deleteSeenBefore', [$at], [], true],
			'dedupe listRuleKeys'           => [$dedupe, 'listRuleKeysForSchema', [2], [self::ROWS_TWO], false],
			'dedupe listRuleKeys failure'   => [$dedupe, 'listRuleKeysForSchema', [2], [], true],
			'log isDuplicate yes'           => [$log, 'isDuplicate', [new DispatchIdempotencyKey('n', 'k'), 60], [[['c' => 1]]], false],
			'log isDuplicate no'            => [$log, 'isDuplicate', [new DispatchIdempotencyKey('n', 'k')], [[['c' => 0]]], false],
			'log record'                    => [$log, 'record', ['n', 'k'], [], false],
			'log record duplicate'          => [$logRacing(true), 'record', ['n', 'k'], [], false],
			'log record other failure'      => [$logRacing(false), 'record', ['n', 'k'], [], false],
			'log pruneExpired'              => [$log, 'pruneExpired', [60], [], false],
			'log pruneExpired failure'      => [$log, 'pruneExpired', [], [], true],
		];
	}//end dedupeCases()

	public static function dbFailure(bool $unique): DbException {
		return new class ($unique) extends DbException {
			public function __construct(private readonly bool $unique) {
				parent::__construct('duplicate key');
			}

			public function getReason(): ?int {
				if ($this->unique === true) {
					return DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION;
				}

				return null;
			}
		};
	}//end dbFailure()

	private function historyCases(DateTime $at, callable $plain): array {
		$history = $plain(NotificationHistoryMapper::class);
		$filters = [
			'ruleId' => 'r', 'channel' => '', 'recipient' => 'alice', 'status' => null,
			'eventId' => 'e', 'unreadOnly' => 'true', 'includeArchived' => '1',
		];
		$snoozed = ['schemaId' => 3, 'includeSnoozed' => 'yes'];
		return [
			'history record'                 => [
				$history, 'record', [new HistoryEntry(delivery: new HistoryDelivery('r', 'email', 'alice', 'sent'), target: new HistoryTarget(), notice: new HistoryNotice())], [], false,
			],
			'history record subject'         => [
				$history, 'record', [
					new HistoryEntry(
						delivery: new HistoryDelivery('r', 'email', 'alice', 'sent'),
						target: new HistoryTarget('1', '2', 'o-1', 'e-1'),
						notice: new HistoryNotice('subj', null, 'nl'),
						about: new HistorySubject('task', 't-1')
					),
				], [], false,
			],
			'history findFiltered'           => [$history, 'findFiltered', [new ListQuery(limit: 10, offset: 5, filters: $filters), $at], [[self::ROW]], false],
			'history findFiltered snoozed'   => [$history, 'findFiltered', [ListQuery::unbounded(filters: $snoozed)], [[]], false],
			'history countByStatus'          => [$history, 'countByStatus', [], [self::ROWS_TWO], false],
			'history countByStatus since'    => [$history, 'countByStatus', [$at], [[]], false],
			'history countFiltered'          => [$history, 'countFiltered', [$filters, $at], [[['c' => 6]]], false],
			'history countFiltered snoozed'  => [$history, 'countFiltered', [$snoozed, $at], [[['c' => 0]]], false],
			'history markRead'               => [$history, 'markRead', [new \OCA\OpenRegister\Notifications\Db\RecipientNotice(7, 'alice'), $at], [], false],
			'history markReadForSubject'     => [$history, 'markReadForSubject', ['alice', 'o-1', 's-1', $at], [], false],
			'history markReadForSubject all' => [$history, 'markReadForSubject', ['alice', 'o-1', '', $at], [], false],
			'history snooze'                 => [$history, 'snooze', [new RecipientNotice(7, 'alice'), $at], [], false],
			'history archive'                => [$history, 'archive', [new RecipientNotice(7, 'alice'), $at], [], false],
			'history archiveByObject'        => [$history, 'archiveByObject', ['o-1', $at], [], false],
			'history findOwn found'          => [$history, 'findOwn', [7, 'alice'], [[self::ROW]], false],
			'history findOwn missing'        => [$history, 'findOwn', [7, 'alice'], [[]], false],
			'history findOwn failure'        => [$history, 'findOwn', [7, 'alice'], [], true],
		];
	}//end historyCases()

	private function timelineCases(callable $plain): array {
		$entries = $plain(TimelineEntryMapper::class);
		$kinds = $plain(TimelineKindMapper::class);
		$blocks = $plain(TextBlockMapper::class);
		$patterns = $plain(ReferencePatternMapper::class);
		return [
			'entry findByUuid found'         => [$entries, 'findByUuid', ['u-1'], [[self::ROW]], false],
			'entry findByUuid missing'       => [$entries, 'findByUuid', ['u-1'], [[]], false],
			'entry findByComment found'      => [$entries, 'findByComment', [12], [[self::ROW]], false],
			'entry findByComment missing'    => [$entries, 'findByComment', [12], [[]], false],
			'entry findForObject'            => [$entries, 'findForObject', ['o-1'], [[self::ROW]], false],
			'entry findForObject public'     => [$entries, 'findForObject', ['o-1', 'public', 5, 10], [[]], false],
			'entry search'                   => [$entries, 'searchEntries', [new TimelineSearch('50%_off', new TimelineEntryScope())], [[self::ROW]], false],
			'entry search narrowed'          => [$entries, 'searchEntries', [new TimelineSearch('x', new TimelineEntryScope(['o-1', 'o-2'], 'call', 900), 'public')], [[]], false],
			'entry search no objects'        => [$entries, 'searchEntries', [new TimelineSearch('x', new TimelineEntryScope([]))], [], false],
			'entry search low limit'         => [$entries, 'searchEntries', [new TimelineSearch('x', new TimelineEntryScope(null, null, 0))], [[]], false],
			'entry followUps'                => [$entries, 'findOpenFollowUps', [new TimelineEntryScope()], [[self::ROW]], false],
			'entry followUps narrowed'       => [$entries, 'findOpenFollowUps', [new TimelineEntryScope(['o-1'], 'call', 3)], [[]], false],
			'entry followUps no objects'     => [$entries, 'findOpenFollowUps', [new TimelineEntryScope([])], [], false],
			'entry deleteForObject'          => [$entries, 'deleteForObject', ['o-1'], [], false],
			'entry deleteForComment'         => [$entries, 'deleteForComment', [12], [], false],
			'kind findBySlug found'          => [$kinds, 'findBySlug', ['s'], [[self::ROW]], false],
			'kind findBySlug missing'        => [$kinds, 'findBySlug', ['s'], [[]], false],
			'kind findAll'                   => [$kinds, 'findAll', [], [[self::ROW]], false],
			'kind findAll scoped'            => [$kinds, 'findAll', ['reg', 'sch'], [[]], false],
			'kind findAll schema only'       => [$kinds, 'findAll', [null, 'sch'], [[]], false],
			'block findBySlug found'         => [$blocks, 'findBySlug', ['s'], [[self::ROW]], false],
			'block findBySlug missing'       => [$blocks, 'findBySlug', ['s'], [[]], false],
			'block findInScope'              => [$blocks, 'findInScope', [new TextBlockScope()], [[self::ROW]], false],
			'block findInScope groups'       => [$blocks, 'findInScope', [new TextBlockScope('reg', 'sch', ['g1', 'g2'])], [[]], false],
			'block findInScope register'     => [$blocks, 'findInScope', [new TextBlockScope('reg', null, [])], [[]], false],
			'pattern findBySlug found'       => [$patterns, 'findBySlug', ['s'], [[self::ROW]], false],
			'pattern findBySlug missing'     => [$patterns, 'findBySlug', ['s'], [[]], false],
			'pattern findAll'                => [$patterns, 'findAll', [], [[self::ROW]], false],
			'pattern findAll enabled'        => [$patterns, 'findAll', [true], [[]], false],
		];
	}//end timelineCases()

	private function broadcastCases(DateTime $at, callable $plain): array {
		$broadcasts = $plain(NotificationBroadcastMapper::class);
		$receipts = $plain(NotificationBroadcastReceiptMapper::class);
		$racing = fn (IDBConnection $db): object => new class ($db) extends NotificationBroadcastReceiptMapper {
			public function insert(Entity $entity): Entity {
				throw new RuntimeException('duplicate key');
			}
		};
		return [
			'broadcast record'               => [$broadcasts, 'record', ['u-1', new OutgoingBroadcast(message: new BroadcastMessage(subject: 'Hi', body: null), sender: 'admin', window: new BroadcastWindow(startsAt: $at, endsAt: $at))], [], false],
			'broadcast findByUuid found'     => [$broadcasts, 'findByUuid', ['u-1'], [[self::ROW]], false],
			'broadcast findByUuid missing'   => [$broadcasts, 'findByUuid', ['u-1'], [[]], false],
			'broadcast findAll'              => [$broadcasts, 'findAllBroadcasts', [], [[self::ROW]], false],
			'broadcast findAll paged'        => [$broadcasts, 'findAllBroadcasts', [5, 10], [[]], false],
			'broadcast findUnseenFor'        => [$broadcasts, 'findUnseenFor', ['alice', $at], [[self::ROW]], false],
			'broadcast deleteByUuid'         => [$broadcasts, 'deleteByUuid', ['u-1'], [], false],
			'receipt acknowledge existing'   => [$receipts, 'acknowledge', ['u-1', 'alice'], [[self::ROW]], false],
			'receipt acknowledge insert'     => [$receipts, 'acknowledge', ['u-1', 'alice'], [[]], false],
			'receipt acknowledge race'       => [$racing, 'acknowledge', ['u-1', 'alice'], [[], [self::ROW]], false],
			'receipt acknowledge race lost'  => [$racing, 'acknowledge', ['u-1', 'alice'], [[], []], false],
			'receipt find missing'           => [$receipts, 'find', ['u-1', 'alice'], [[]], false],
			'receipt countFor'               => [$receipts, 'countFor', ['u-1'], [[['c' => 2]]], false],
		];
	}//end broadcastCases()

	private function watcherCases(DateTime $at, callable $plain): array {
		$watchers = $plain(WatcherMapper::class);
		$presence = $plain(ObjectPresenceMapper::class);
		$racing = fn (IDBConnection $db): object => new class ($db) extends WatcherMapper {
			public function insert(Entity $entity): Entity {
				throw new RuntimeException('duplicate key');
			}

			public function update(Entity $entity): Entity {
				return $entity;
			}
		};
		return [
			'watcher findOne found'          => [$watchers, 'findOne', ['alice', 'o-1'], [[self::ROW]], false],
			'watcher findOne missing'        => [$watchers, 'findOne', ['alice', 'o-1'], [[]], false],
			'watcher findByObject'           => [$watchers, 'findByObject', ['o-1'], [[self::ROW]], false],
			'watcher findNotifying'          => [$watchers, 'findNotifyingByObject', ['o-1'], [[self::ROW]], false],
			'watcher notifyMapForUser'       => [$watchers, 'notifyMapForUser', ['alice'], [self::ROWS_TWO], false],
			'watcher countsByObject'         => [$watchers, 'countsByObject', [10], [self::ROWS_TWO], false],
			'watcher countForObject'         => [$watchers, 'countForObject', ['o-1'], [[self::COUNT_ROW]], false],
			'watcher countForObject none'    => [$watchers, 'countForObject', ['o-1'], [[]], false],
			'watcher subscribe existing'     => [$watchers, 'subscribe', [self::follow('alice', 'o-1')], [[self::ROW]], false],
			'watcher subscribe mute'         => [$watchers, 'subscribe', [self::follow('alice', 'o-1'), false], [[self::ROW]], false],
			'watcher subscribe insert'       => [$watchers, 'subscribe', [self::follow('alice', 'o-1', 'reg', 'sch'), null], [[]], false],
			'watcher subscribe race'         => [$racing, 'subscribe', [self::follow('alice', 'o-1'), false], [[], [self::ROW]], false],
			'watcher subscribe race lost'    => [$racing, 'subscribe', [self::follow('alice', 'o-1')], [[], []], false],
			'watcher unsubscribe'            => [$watchers, 'unsubscribe', ['alice', 'o-1'], [], false],
			'watcher deleteByObject'         => [$watchers, 'deleteByObject', ['o-1'], [], false],
			'presence findOne found'         => [$presence, 'findOne', ['alice', 'o-1'], [[self::ROW]], false],
			'presence findOne missing'       => [$presence, 'findOne', ['alice', 'o-1'], [[]], false],
			'presence findPresent'           => [$presence, 'findPresent', ['o-1', $at], [[self::ROW]], false],
			'presence removeOne'             => [$presence, 'removeOne', ['alice', 'o-1'], [], false],
			'presence findStale'             => [$presence, 'findStale', [$at, 20], [[self::ROW]], false],
			'presence pruneStale'            => [$presence, 'pruneStale', [$at], [], false],
		];
	}//end watcherCases()

	private function outcome(callable $factory, string $method, array $args, array $rows, bool $failing): array {
		$this->queryLog = [];
		$this->builderCount = 0;
		$this->parameterCount = 0;
		$this->queuedRows = $rows;
		$db = $this->createMock(IDBConnection::class);
		$db->method('escapeLikeParameter')->willReturnCallback(static fn (string $value): string => addcslashes($value, '\\_%'));
		$db->method('getQueryBuilder')->willReturnCallback(
			function () use ($failing): IQueryBuilder {
				if ($failing === true) {
					throw new RuntimeException('database down');
				}

				return $this->recordingBuilder();
			}
		);

		$mapper = $factory($db);
		$outcome = [];
		try {
			$outcome['result'] = $this->render(value: $mapper->$method(...$args));
		} catch (\Throwable $e) {
			$outcome['thrown'] = get_class($e) . ': ' . $e->getMessage();
			if (str_contains($outcome['thrown'], '@anonymous') === true) {
				$outcome['thrown'] = get_parent_class($e) . ' (anonymous): ' . $e->getMessage();
			}
		}

		$outcome['queries'] = array_map(
			static fn (string $line): string => (string) preg_replace('/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', '<timestamp>', $line),
			$this->queryLog
		);
		return $outcome;
	}//end outcome()

	private function render(mixed $value): mixed {
		if (is_array($value) === true) {
			return array_map(fn (mixed $item): mixed => $this->render(value: $item), $value);
		}

		if ($value instanceof Entity === true) {
			$class = get_class($value);
			if (str_contains($class, '@anonymous') === true) {
				$class = get_parent_class($value);
			}

			$fields = $value->getUpdatedFields();
			ksort($fields);
			return $class . '#' . var_export($value->getId(), true) . ' updated:' . implode(',', array_keys($fields));
		}

		return $value;
	}//end render()

	/**
	 * An unsaved follow, built the way the mapper's callers build one.
	 */
	private static function follow(string $userId, string $objectUuid, ?string $register = null, ?string $schema = null): Watcher {
		$follow = new Watcher();
		$follow->setUserId($userId);
		$follow->setObjectUuid($objectUuid);
		$follow->setRegister($register);
		$follow->setSchema($schema);
		return $follow;
	}//end follow()
}//end class
