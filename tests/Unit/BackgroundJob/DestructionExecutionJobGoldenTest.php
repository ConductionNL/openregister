<?php

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\BackgroundJob;

use OCA\OpenRegister\BackgroundJob\DestructionExecutionJob;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Object\DeleteObject;
use OCA\OpenRegister\Service\Object\RetentionDestruction;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\RetentionServiceInterface;
use OCA\OpenRegister\Service\Settings\ObjectRetentionHandler;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
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

/**
 * Golden test for DestructionExecutionJob::run().
 *
 * Each case runs the job once over a scripted destruction list; the recording
 * holds every container lookup and every call to the logger, the object
 * mapper, the hold check, the audit trail, the delete, the certificate save
 * and the notification manager. Timestamps are masked. Compared with
 * fixtures/destruction-execution-job-golden.json; set UPDATE_GOLDEN=1 to
 * rewrite it.
 *
 * @covers \OCA\OpenRegister\BackgroundJob\DestructionExecutionJob
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Service\Object\RetentionDestruction
 * @uses   \OCA\OpenRegister\Db\AuditTrail
 * @uses   \OCA\OpenRegister\BackgroundJob\DestructionListRun
 */
class DestructionExecutionJobGoldenTest extends TestCase
{

    private const FIXTURE = __DIR__.'/fixtures/destruction-execution-job-golden.json';

    /**
     * @var list<array<string, mixed>>
     */
    private array $trace = [];

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function cases(): array
    {
        $entries = [
            ['classification' => 'A'],
            ['uuid' => 'retain', 'decision' => 'retain'],
            ['uuid' => 'transfer', 'decision' => 'transfer'],
            ['uuid' => 'unreadable'],
            ['uuid' => 'held'],
            ['uuid' => 'one', 'classification' => '1.1', 'decision' => 'destroy'],
            ['uuid' => 'undeletable'],
            ['uuid' => 'two'],
        ];
        $archival = ['destructionBatchSize' => 4, 'archivalRegister' => 5, 'destructionListSchema' => 6];

        return [
            'no list uuid'          => [['argument' => []]],
            'list not found'        => [['list' => 'none']],
            'list lookup throws'    => [['list' => 'throw']],
            'list not approved'     => [['list' => ['status' => 'draft']]],
            'every entry branch'    => [['entries' => $entries, 'settings' => $archival, 'users' => ['ann', 'bob']]],
            'default batch, no cert' => [['entries' => [['uuid' => 'one'], ['uuid' => 'two']], 'settings' => []]],
            'cert save throws'      => [['entries' => [['uuid' => 'one']], 'settings' => $archival, 'cert' => 'throw']],
            'held without group'    => [['entries' => [['uuid' => 'held']], 'settings' => [], 'users' => null]],
            'notify throws'         => [['entries' => [['uuid' => 'held']], 'settings' => [], 'users' => 'throw']],
            'settings throw'        => [['settings' => 'throw']],
            'empty list'            => [['entries' => [], 'settings' => ['archivalRegister' => 5]]],
            'default batch of 50'   => [['entries' => array_fill(0, 51, ['decision' => 'retain']), 'settings' => []]],
            'default batch, full'   => [['entries' => array_fill(0, 50, ['decision' => 'retain']), 'settings' => []]],
        ];
    }//end cases()

    #[DataProvider('cases')]
    public function testRunMatchesGolden(array $case): void
    {
        $job = new DestructionExecutionJob($this->createMock(ITimeFactory::class), $this->container($case));
        $run = new ReflectionMethod($job, 'run');
        $run->invoke($job, ($case['argument'] ?? ['destructionListUuid' => 'list-1']));

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

    private static function mask(array $data): array
    {
        foreach (['executingAt', 'executedAt'] as $key) {
            if (isset($data[$key]) === true) {
                $data[$key] = 'T';
            }
        }

        return $data;
    }//end mask()

    private function container(array $case): ContainerInterface
    {
        $services = [
            LoggerInterface::class           => $this->logger(),
            RetentionServiceInterface::class => $this->retention(),
            ObjectRetentionHandler::class    => $this->settings($case),
            MagicMapperInterface::class      => $this->mapper($case),
            AuditTrailMapperInterface::class => $this->audit(),
            DeleteObject::class              => $this->deleter(),
            SaveObject::class                => $this->saver($case),
            INotificationManager::class      => $this->notifications(),
            IGroupManager::class             => $this->groupManager($case),
        ];
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(
            function (string $id) use ($services): object {
                $this->record('get', [substr($id, (int) strrpos($id, '\\') + 1)]);
                return $services[$id];
            }
        );
        return $container;
    }//end container()

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

    private function retention(): RetentionServiceInterface
    {
        $service = $this->createMock(RetentionServiceInterface::class);
        $service->method('hasActiveLegalHold')->willReturnCallback(
            function (ObjectEntity $object): bool {
                $this->record('hasActiveLegalHold', [$object->getUuid()]);
                return $object->getUuid() === 'held';
            }
        );
        $service->method('generateDestructionCertificate')->willReturnCallback(
            function (array $list, int $count, string $at): array {
                $this->record('generateDestructionCertificate', [self::mask($list), $count, $at === '' ? '' : 'T']);
                return ['certificate' => $count];
            }
        );
        return $service;
    }//end retention()

    private function settings(array $case): ObjectRetentionHandler
    {
        $handler = $this->createMock(ObjectRetentionHandler::class);
        $settings = ($case['settings'] ?? []);
        $handler->method('getArchivalSettingsOnly')->willReturnCallback(
            static function () use ($settings): array {
                if ($settings === 'throw') {
                    throw new RuntimeException('settings down');
                }

                return $settings;
            }
        );
        return $handler;
    }//end settings()

    private function mapper(array $case): MagicMapperInterface
    {
        $list = new ObjectEntity();
        $list->setUuid('list-1');
        $listData = ($case['list'] ?? []);
        if (is_array($listData) === true) {
            $listData += [
                'status'    => 'approved',
                'objects'   => ($case['entries'] ?? [['uuid' => 'one']]),
                'approvals' => [['userId' => 'u1'], ['userId' => 'u2'], ['other' => 'x']],
            ];
            $list->setObject($listData);
        }

        $mapper = $this->createMock(MagicMapperInterface::class);
        $mapper->method('find')->willReturnCallback(
            function ($uuid, ...$rest) use ($case, $list): ObjectEntity {
                $this->record('find', [$uuid, ...array_slice($rest, 0, 5)]);
                return $this->found($uuid, $case, $list);
            }
        );
        $mapper->method('update')->willReturnCallback(
            function (ObjectEntity $entity): ObjectEntity {
                $this->record('update', [$entity->getUuid(), self::mask($entity->getObject())]);
                return $entity;
            }
        );
        return $mapper;
    }//end mapper()

    private function found(string $uuid, array $case, ObjectEntity $list): ObjectEntity
    {
        if ($uuid === 'list-1') {
            if (($case['list'] ?? null) === 'throw') {
                throw new RuntimeException('mapper down');
            }

            if (($case['list'] ?? null) === 'none') {
                throw new DoesNotExistException('no list');
            }

            return $list;
        }

        if ($uuid === 'unreadable') {
            throw new RuntimeException('cannot read');
        }

        $object = new ObjectEntity();
        $object->setUuid($uuid);
        $object->setRegister('5');
        $object->setSchema('9');
        $object->setRetention(['archiefnominatie' => 'vernietigen']);
        return $object;
    }//end found()

    private function audit(): AuditTrailMapperInterface
    {
        $mapper = $this->createMock(AuditTrailMapperInterface::class);
        $mapper->method('createAuditTrailEntry')->willReturnCallback(
            function (ObjectEntity $object, string $action, array $context=[]): AuditTrail {
                $this->record('audit', [$object->getUuid(), $object->getRetention(), $action, $context]);
                return new AuditTrail();
            }
        );
        return $mapper;
    }//end audit()

    private function deleter(): DeleteObject
    {
        $deleter = $this->createMock(DeleteObject::class);
        $deleter->method('deleteObject')->willReturnCallback(
            function (...$args): bool {
                $this->record('deleteObject', array_slice($args, 0, 6));
                $this->assertTrue((new RetentionDestruction())->isRunning(), 'a destruction is not stopped by a lock or the archive');
                if ($args[2] === 'undeletable') {
                    throw new RuntimeException('delete refused');
                }

                return true;
            }
        );
        return $deleter;
    }//end deleter()

    private function saver(array $case): SaveObject
    {
        $saver = $this->createMock(SaveObject::class);
        $saver->method('saveObject')->willReturnCallback(
            function (...$args) use ($case): ObjectEntity {
                $this->record('saveObject', array_slice($args, 0, 9));
                if (($case['cert'] ?? null) === 'throw') {
                    throw new RuntimeException('cert refused');
                }

                $cert = new ObjectEntity();
                $cert->setUuid('cert-1');
                return $cert;
            }
        );
        return $saver;
    }//end saver()

    private function notifications(): INotificationManager
    {
        $manager = $this->createMock(INotificationManager::class);
        $manager->method('createNotification')->willReturnCallback(fn (): INotification => $this->notification());
        $manager->method('notify')->willReturnCallback(
            function (): void {
                $this->record('notify', []);
            }
        );
        return $manager;
    }//end notifications()

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

    private function groupManager(array $case): IGroupManager
    {
        $users = ($case['users'] ?? ['ann']);
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
        return $groups;
    }//end groupManager()
}//end class
