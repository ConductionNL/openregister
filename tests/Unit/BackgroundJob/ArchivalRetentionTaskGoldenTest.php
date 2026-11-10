<?php

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\BackgroundJob;

use OCA\OpenRegister\BackgroundJob\ArchivalRetentionTask;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\RetentionServiceInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;
use OCA\OpenRegister\BackgroundJob\Support\ArchivalRetentionSources;
use OCA\OpenRegister\BackgroundJob\Support\ArchivalRetentionPlatform;

/**
 * Golden test for ArchivalRetentionTask::run().
 *
 * Each case runs the task once over scripted registers, schemas and rows; the
 * recording holds every call to the logger, the object service, the magic
 * mapper, the hold check, the cursor and the notification manager. Compared
 * with fixtures/archival-retention-task-golden.json; set UPDATE_GOLDEN=1 to
 * rewrite it.
 *
 * @covers \OCA\OpenRegister\BackgroundJob\ArchivalRetentionTask
 * @uses   \OCA\OpenRegister\Service\Archival\RetentionEvaluator
 * @uses   \OCA\OpenRegister\Service\Archival\RetentionConditionEvaluator
 * @uses   \OCA\OpenRegister\BackgroundJob\ArchivalSweep
 * @uses   \OCA\OpenRegister\BackgroundJob\ArchivalRowExpiry
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 */
class ArchivalRetentionTaskGoldenTest extends TestCase
{

    private const FIXTURE = __DIR__.'/fixtures/archival-retention-task-golden.json';

    /**
     * @var list<array<string, mixed>>
     */
    private array $trace = [];

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function cases(): array
    {
        $old = '-400 days';
        $new = '-2 days';
        $mixed = [
            ['_uuid' => 'no-created'],
            ['_uuid' => 'garbage', '_created' => 'not a date'],
            ['_uuid' => 'live', '_created' => $new, 'kind' => 'x'],
            ['_created' => $old],
            ['_uuid' => 'unloadable', '_created' => $old],
            ['_uuid' => 'held', '_created' => $old],
            ['_uuid' => 'gone', '_created' => $old, 'kind' => 'y'],
            ['_uuid' => 'refused', '_created' => $old],
            ['_uuid' => 'broken', '_created' => $old],
        ];

        return [
            'every row branch'       => [['rows' => $mixed, 'users' => ['ann', 'bob']]],
            'held without group'     => [['rows' => [['_uuid' => 'held', '_created' => $old]], 'users' => null]],
            'notify throws'          => [['rows' => [['_uuid' => 'held', '_created' => $old]], 'users' => 'throw']],
            'no held rows'           => [['rows' => [['_uuid' => 'gone', '_created' => $old]], 'users' => ['ann']]],
            'table check throws'     => [['rows' => [], 'table' => 'throw']],
            'table missing'          => [['rows' => [], 'table' => false]],
            'query throws'           => [['rows' => 'throw']],
            'annotation unusable'    => [['rows' => [['_uuid' => 'a', '_created' => $old]], 'annotation' => ['retention' => []]]],
            'registers throw'        => [['registers' => 'throw']],
            'schema not loadable'    => [['schemas' => 'throw']],
            'schema not annotated'   => [['annotation' => 'none']],
            'register not an entity' => [['registers' => 'odd']],
            'rule on a row field'    => [[
                'rows'       => [['_uuid' => 'gone', '_created' => $old, 'code' => 1], ['_uuid' => 'other', '_created' => $old, 'code' => 5]],
                'annotation' => ['retention' => ['default' => 'P1Y', 'rules' => [['condition' => 'code < 3', 'retention' => 'P10Y']]]],
            ]],
        ];
    }//end cases()

    #[DataProvider('cases')]
    public function testRunMatchesGolden(array $case): void
    {
        $task = new ArchivalRetentionTask(
            $this->createMock(ITimeFactory::class),
            new ArchivalRetentionSources($this->db($case), $this->registers($case), $this->schemas($case), $this->magic($case)),
            new ArchivalRetentionPlatform($this->objects(), $this->holds(), $this->container($case), $this->logger())
        );
        $run = new ReflectionMethod($task, 'run');
        $run->invoke($task, null);

        $name = (string) $this->dataName();
        $all = is_file(self::FIXTURE) === true ? json_decode((string) file_get_contents(self::FIXTURE), true) : [];
        if (getenv('UPDATE_GOLDEN') === '1') {
            $all[$name] = $this->trace;
            ksort($all);
            file_put_contents(self::FIXTURE, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        }

        $this->assertArrayHasKey($name, $all);
        $this->assertSame($all[$name], $this->trace);
    }//end testRunMatchesGolden()

    private function record(string $call, array $args): void
    {
        $this->trace[] = [$call => $args];
    }//end record()

    private function db(array $case): IDBConnection
    {
        $rows = ($case['rows'] ?? []);
        $cursor = $this->createMock(IResult::class);
        $sequence = [];
        if (is_array($rows) === true) {
            foreach ($rows as $row) {
                if (isset($row['_created']) === true && str_starts_with($row['_created'], '-') === true) {
                    $row['_created'] = (new \DateTimeImmutable())->modify($row['_created'])->format('Y-m-d H:i:s');
                }

                $sequence[] = $row;
            }
        }

        $sequence[] = false;
        $cursor->method('fetch')->willReturnOnConsecutiveCalls(...$sequence);
        $cursor->method('closeCursor')->willReturnCallback(
            function (): bool {
                $this->record('closeCursor', []);
                return true;
            }
        );

        $qb = $this->createMock(IQueryBuilder::class);
        $qb->method('select')->willReturnCallback(
            function (...$args) use ($qb): IQueryBuilder {
                $this->record('select', $args);
                return $qb;
            }
        );
        $qb->method('from')->willReturnCallback(
            function (...$args) use ($qb): IQueryBuilder {
                $this->record('from', $args);
                return $qb;
            }
        );
        $qb->method('executeQuery')->willReturnCallback(
            static function () use ($rows, $cursor): IResult {
                if ($rows === 'throw') {
                    throw new RuntimeException('query down');
                }

                return $cursor;
            }
        );

        $db = $this->createMock(IDBConnection::class);
        $db->method('getQueryBuilder')->willReturn($qb);
        return $db;
    }//end db()

    private function registers(array $case): RegisterMapperInterface
    {
        $mapper = $this->createMock(RegisterMapperInterface::class);
        $mode = ($case['registers'] ?? 'one');
        $mapper->method('findAll')->willReturnCallback(
            static function () use ($mode): array {
                if ($mode === 'throw') {
                    throw new RuntimeException('registers down');
                }

                $register = new Register();
                $register->setId(3);
                $register->setSchemas([7]);
                return $mode === 'odd' ? [new \stdClass(), $register] : [$register];
            }
        );
        return $mapper;
    }//end registers()

    private function schemas(array $case): SchemaMapperInterface
    {
        $mapper = $this->createMock(SchemaMapperInterface::class);
        $mapper->method('find')->willReturnCallback(
            function (...$args) use ($case): Schema {
                $this->record('schemaFind', $args);
                if (($case['schemas'] ?? null) === 'throw') {
                    throw new RuntimeException('schema gone');
                }

                $annotation = ($case['annotation'] ?? ['retention' => ['default' => 'P1Y']]);
                $schema = new Schema();
                $schema->setId(7);
                $schema->setSlug('ledger');
                $schema->setConfiguration($annotation === 'none' ? [] : ['x-openregister-archival' => $annotation]);
                return $schema;
            }
        );
        return $mapper;
    }//end schemas()

    private function magic(array $case): MagicMapperInterface
    {
        $mapper = $this->createMock(MagicMapperInterface::class);
        $table = ($case['table'] ?? true);
        $mapper->method('tableExistsForRegisterSchema')->willReturnCallback(
            function (Register $register, Schema $schema) use ($table): bool {
                $this->record('tableExists', [$register->getId(), $schema->getId()]);
                if ($table === 'throw') {
                    throw new RuntimeException('table check down');
                }

                return $table;
            }
        );
        $mapper->method('find')->willReturnCallback(
            function ($uuid, $register, $schema, ...$rest): ObjectEntity {
                $this->record('magicFind', [$uuid, $register->getId(), $schema->getId(), $rest]);
                if ($uuid === 'unloadable') {
                    throw new RuntimeException('cannot load');
                }

                $object = new ObjectEntity();
                $object->setUuid($uuid);
                return $object;
            }
        );
        return $mapper;
    }//end magic()

    private function objects(): ObjectOperationsInterface
    {
        $service = $this->createMock(ObjectOperationsInterface::class);
        $service->method('setRegister')->willReturnCallback(
            function ($register) use ($service): ObjectOperationsInterface {
                $this->record('setRegister', [$register->getId()]);
                return $service;
            }
        );
        $service->method('setSchema')->willReturnCallback(
            function ($schema) use ($service): ObjectOperationsInterface {
                $this->record('setSchema', [$schema->getId()]);
                return $service;
            }
        );
        $service->method('deleteObject')->willReturnCallback(
            function (...$args): bool {
                $this->record('deleteObject', array_slice($args, 0, 6));
                if ($args[0] === 'broken') {
                    throw new RuntimeException('delete failed');
                }

                return $args[0] !== 'refused';
            }
        );
        return $service;
    }//end objects()

    private function holds(): RetentionServiceInterface
    {
        $service = $this->createMock(RetentionServiceInterface::class);
        $service->method('hasActiveLegalHold')->willReturnCallback(
            function (ObjectEntity $object): bool {
                $this->record('hasActiveLegalHold', [$object->getUuid()]);
                return $object->getUuid() === 'held';
            }
        );
        return $service;
    }//end holds()

    private function container(array $case): ContainerInterface
    {
        $users = ($case['users'] ?? null);
        $groups = $this->createMock(IGroupManager::class);
        $groups->method('get')->willReturnCallback(
            function (string $gid) use ($users): ?IGroup {
                $this->record('groupGet', [$gid]);
                if ($users === 'throw') {
                    throw new RuntimeException('groups down');
                }

                if ($users === null) {
                    return null;
                }

                $members = [];
                foreach ($users as $uid) {
                    $user = $this->createMock(IUser::class);
                    $user->method('getUID')->willReturn($uid);
                    $members[] = $user;
                }

                $group = $this->createMock(IGroup::class);
                $group->method('getUsers')->willReturn($members);
                return $group;
            }
        );

        $notifications = $this->createMock(INotificationManager::class);
        $notifications->method('createNotification')->willReturnCallback(fn (): INotification => $this->notification());
        $notifications->method('notify')->willReturnCallback(
            function (): void {
                $this->record('notify', []);
            }
        );

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(
            fn (string $id): object => $id === IGroupManager::class ? $groups : $notifications
        );
        return $container;
    }//end container()

    private function notification(): INotification
    {
        $notification = $this->createMock(INotification::class);
        foreach (['setApp', 'setUser', 'setObject', 'setSubject'] as $method) {
            $notification->method($method)->willReturnCallback(
                function (...$args) use ($method, $notification): INotification {
                    $this->record($method, $args);
                    return $notification;
                }
            );
        }

        $notification->method('setDateTime')->willReturnSelf();
        return $notification;
    }//end notification()

    private function logger(): LoggerInterface
    {
        $logger = $this->createMock(LoggerInterface::class);
        foreach (['error', 'warning', 'info', 'debug'] as $level) {
            $logger->method($level)->willReturnCallback(
                function (string $message, array $context=[]) use ($level): void {
                    if (isset($context['exception']) === true) {
                        $context['exception'] = $context['exception']->getMessage();
                    }

                    $this->record($level, [$message, $context]);
                }
            );
        }

        return $logger;
    }//end logger()
}//end class
