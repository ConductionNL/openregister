<?php

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\BackgroundJob;

use DateTime;
use OCA\OpenRegister\Notifications\BackgroundJob\ScheduledNotificationDelivery;
use OCA\OpenRegister\Notifications\BackgroundJob\ScheduledNotificationJob;
use OCA\OpenRegister\Notifications\BackgroundJob\ScheduledObjectSources;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Notifications\Db\NotificationDedupeState;
use OCA\OpenRegister\Notifications\Db\NotificationDedupeStateMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Notifications\Service\Notification\AnnotationNotificationDispatcher;
use OCA\OpenRegister\Notifications\Service\Notification\ScheduledFilterEvaluator;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Golden test for ScheduledNotificationJob::run().
 *
 * Each case runs the job once over scripted schemas, cache state, objects and
 * dedupe rows; the recording holds every call to the logger, the schema and
 * object mappers, the state cache, the app config, the dedupe mapper and the
 * dispatcher. Times are masked. Compared with
 * fixtures/scheduled-notification-job-golden.json; set UPDATE_GOLDEN=1 to
 * rewrite it.
 *
 * @covers \OCA\OpenRegister\Notifications\BackgroundJob\ScheduledNotificationJob
 * @uses   \OCA\OpenRegister\Notifications\Service\Notification\ScheduledFilterEvaluator
 * @uses   \OCA\OpenRegister\Notifications\Service\Notification\ScheduledFilterParser
 * @uses   \OCA\OpenRegister\Notifications\Service\Notification\ScheduledFilterPosition
 * @uses   \OCA\OpenRegister\Notifications\Service\Notification\ScheduledFilterGrammar
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Notifications\BackgroundJob\ScheduledFire
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Notifications\Db\NotificationDedupeState
 */
class ScheduledNotificationJobGoldenTest extends TestCase
{

    private const FIXTURE = __DIR__.'/fixtures/scheduled-notification-job-golden.json';

    /**
     * @var list<array<string, mixed>>
     */
    private array $trace = [];

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function cases(): array
    {
        $rules = [
            'skip-spec'  => 'not an array',
            'skip-type'  => ['trigger' => ['type' => 'created']],
            'skip-short' => ['trigger' => ['type' => 'scheduled', 'intervalSec' => 30]],
            'recent'     => ['trigger' => ['type' => 'scheduled', 'intervalSec' => 3600]],
            'remind'     => ['trigger' => ['type' => 'scheduled', 'intervalSec' => 60, 'filter' => ['status' => 'open']]],
        ];
        $objects = [
            ['uuid' => 'open-1', 'data' => ['status' => 'open', 'n' => 1]],
            ['uuid' => 'closed', 'data' => ['status' => 'closed']],
            ['uuid' => '', 'data' => ['status' => 'open']],
            ['uuid' => 'same', 'data' => ['status' => 'open']],
            ['uuid' => 'changed', 'data' => ['status' => 'open']],
            ['uuid' => 'touch-fails', 'data' => ['status' => 'open']],
            ['uuid' => 'dispatch-fails', 'data' => ['status' => 'open']],
            'not an entity',
        ];
        $watched = ['type' => 'scheduled', 'intervalSec' => 60, 'filter' => ['due' => ['operator' => 'withinNext', 'value' => 'P10Y'], 'x' => 'y', 7 => []]];
        $explicit = ['type' => 'scheduled', 'intervalSec' => 60, 'dedupeFields' => ['b', 'a', 'b', '', 3]];
        $many = array_map(static fn (int $i): array => ['uuid' => 'o'.$i, 'data' => []], range(1, 5003));

        return [
            'schema list throws'   => [['schemas' => 'throw']],
            'every rule and object' => [['rules' => $rules, 'objects' => $objects, 'cache' => ['sched:7:recent' => 'recent']]],
            'no cache'             => [['rules' => $rules, 'cache' => 'none']],
            'objects throw'        => [['rules' => ['remind' => $rules['remind']], 'objects' => 'throw']],
            'watched fields'       => [['rules' => ['w' => ['trigger' => $watched]], 'objects' => [['uuid' => 'same', 'data' => ['due' => '2030-01-01']]]]],
            'dedupe fields'        => [['rules' => ['e' => ['trigger' => $explicit]], 'objects' => [['uuid' => 'same', 'data' => ['a' => 1, 'b' => 2]]]]],
            'rotating window'      => [['rules' => ['r' => ['trigger' => ['type' => 'scheduled', 'intervalSec' => 60, 'filter' => ['status' => 'open']]]], 'objects' => $many, 'offset' => '4000']],
            'rotation start'       => [['rules' => ['r' => ['trigger' => ['type' => 'scheduled', 'intervalSec' => 60, 'filter' => ['status' => 'open']]]], 'objects' => $many, 'offset' => '-3', 'offsetThrows' => true]],
            'offset past the end'  => [['rules' => ['r' => ['trigger' => ['type' => 'scheduled', 'intervalSec' => 60, 'filter' => ['status' => 'open']]]], 'objects' => $many, 'offset' => '9000']],
            'older-than field'     => [['rules' => ['o' => ['trigger' => ['type' => 'scheduled', 'intervalSec' => 60, 'filter' => ['seen' => ['operator' => 'olderThan', 'value' => 'P1D']]]]], 'objects' => [['uuid' => 'same', 'data' => ['seen' => '2000-01-01']]]]],
            'retention off'        => [['rules' => [], 'retention' => '0']],
            'retention unreadable' => [['rules' => [], 'retention' => 'throw', 'cacheSetThrows' => true]],
            'no notifications'     => [['rules' => null]],
        ];
    }//end cases()

    #[DataProvider('cases')]
    public function testRunMatchesGolden(array $case): void
    {
        $factory = $this->createMock(ICacheFactory::class);
        $factory->method('createDistributed')->willReturnCallback(
            function () use ($case): ICache {
                if (($case['cache'] ?? []) === 'none') {
                    throw new RuntimeException('no cache');
                }

                return $this->cache($case);
            }
        );

        $job = new ScheduledNotificationJob(
            $this->createMock(ITimeFactory::class),
            new ScheduledObjectSources($this->schemas($case), $this->objects($case), new ScheduledFilterEvaluator()),
            new ScheduledNotificationDelivery($this->dispatcher(), $this->dedupe(), $this->appConfig($case), $this->logger()),
            $factory
        );
        $run = new ReflectionMethod($job, 'run');
        $run->invoke($job, null);

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

    private function schemas(array $case): SchemaMapperInterface
    {
        $mapper = $this->createMock(SchemaMapperInterface::class);
        $mapper->method('findAll')->willReturnCallback(
            static function () use ($case): array {
                if (($case['schemas'] ?? null) === 'throw') {
                    throw new RuntimeException('schemas down');
                }

                $schema = new Schema();
                $schema->setId(7);
                $rules = ($case['rules'] ?? null);
                $schema->setConfiguration($rules === null ? [] : ['x-openregister-notifications' => $rules]);
                return ['not a schema', $schema];
            }
        );
        return $mapper;
    }//end schemas()

    private function objects(array $case): MagicMapperInterface
    {
        $mapper = $this->createMock(MagicMapperInterface::class);
        $mapper->method('findBySchema')->willReturnCallback(
            function (...$args) use ($case): array {
                $this->record('findBySchema', [$args[0]]);
                if (($case['objects'] ?? []) === 'throw') {
                    throw new RuntimeException('objects down');
                }

                $objects = [];
                foreach (($case['objects'] ?? []) as $spec) {
                    if (is_array($spec) === false) {
                        $objects[] = $spec;
                        continue;
                    }

                    $object = new ObjectEntity();
                    $object->setUuid($spec['uuid']);
                    $object->setObject($spec['data']);
                    $objects[] = $object;
                }

                return $objects;
            }
        );
        return $mapper;
    }//end objects()

    private function cache(array $case): ICache
    {
        $state = ($case['cache'] ?? []);
        $cache = $this->createMock(ICache::class);
        $cache->method('get')->willReturnCallback(
            function (string $key) use ($state) {
                $this->record('cacheGet', [$key]);
                if (($state[$key] ?? null) === 'recent') {
                    return time();
                }

                return null;
            }
        );
        $cache->method('set')->willReturnCallback(
            function (string $key, $value, int $ttl) use ($case): bool {
                $this->record('cacheSet', [$key, is_int($value), $ttl]);
                if (($case['cacheSetThrows'] ?? false) === true) {
                    throw new RuntimeException('cache down');
                }

                return true;
            }
        );
        return $cache;
    }//end cache()

    private function appConfig(array $case): IAppConfig
    {
        $config = $this->createMock(IAppConfig::class);
        $config->method('getValueString')->willReturnCallback(
            function (string $app, string $key, string $default='') use ($case): string {
                $this->record('getValueString', [$app, $key, $default]);
                if (str_starts_with($key, 'sched_offset:') === true) {
                    return ($case['offset'] ?? $default);
                }

                if (($case['retention'] ?? null) === 'throw') {
                    throw new RuntimeException('config down');
                }

                return ($case['retention'] ?? $default);
            }
        );
        $config->method('setValueString')->willReturnCallback(
            function (string $app, string $key, string $value) use ($case): bool {
                $this->record('setValueString', [$app, $key, $value]);
                if (($case['offsetThrows'] ?? false) === true) {
                    throw new RuntimeException('config read-only');
                }

                return true;
            }
        );
        return $config;
    }//end appConfig()

    private function dedupe(): NotificationDedupeStateMapper
    {
        $mapper = $this->createMock(NotificationDedupeStateMapper::class);
        $mapper->method('findOne')->willReturnCallback(
            function (int $schemaId, string $ruleKey, string $objectUuid): ?NotificationDedupeState {
                $this->record('findOne', [$schemaId, $ruleKey, $objectUuid]);
                if (in_array($objectUuid, ['same', 'changed', 'touch-fails'], true) === false) {
                    return null;
                }

                $state = new NotificationDedupeState();
                $state->setFingerprint($objectUuid === 'changed' ? 'old' : $this->fingerprintFor($ruleKey));
                return $state;
            }
        );
        $mapper->method('upsert')->willReturnCallback(
            function (int $schemaId, string $ruleKey, string $objectUuid, string $fingerprint, DateTime $now, bool $dispatched): NotificationDedupeState {
                $this->record('upsert', [$schemaId, $ruleKey, $objectUuid, $fingerprint, $dispatched]);
                if ($objectUuid === 'touch-fails') {
                    throw new RuntimeException('touch failed');
                }

                return new NotificationDedupeState();
            }
        );
        $mapper->method('deleteSeenBefore')->willReturnCallback(
            function (DateTime $cutoff): int {
                $this->record('deleteSeenBefore', [(int) round((time() - $cutoff->getTimestamp()) / 86400)]);
                return 0;
            }
        );
        return $mapper;
    }//end dedupe()

    private function fingerprintFor(string $ruleKey): string
    {
        $fingerprints = [
            'w' => sha1('{"due":"2030-01-01"}'),
            'e' => sha1('{"a":1,"b":2}'),
        ];
        return ($fingerprints[$ruleKey] ?? sha1('constant'));
    }//end fingerprintFor()

    private function dispatcher(): AnnotationNotificationDispatcher
    {
        $dispatcher = $this->createMock(AnnotationNotificationDispatcher::class);
        $dispatcher->method('dispatch')->willReturnCallback(
            function (ObjectEntity $object, string $trigger, array $context): void {
                $this->record('dispatch', [$object->getUuid(), $trigger, $context]);
                if ($object->getUuid() === 'dispatch-fails') {
                    throw new RuntimeException('dispatch failed');
                }
            }
        );
        return $dispatcher;
    }//end dispatcher()

    private function logger(): LoggerInterface
    {
        $logger = $this->createMock(LoggerInterface::class);
        foreach (['error', 'warning', 'info', 'debug'] as $level) {
            $logger->method($level)->willReturnCallback(
                function (string $message, array $context=[]) use ($level): void {
                    $this->record($level, [$message, $context]);
                }
            );
        }

        return $logger;
    }//end logger()
}//end class
