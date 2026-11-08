<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Notifications\Controller;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Notifications\Db\TimelineEntry;
use OCA\OpenRegister\Notifications\Service\Timeline\EntryPin;
use OCA\OpenRegister\Notifications\Service\Timeline\ReferenceService;
use OCA\OpenRegister\Notifications\Service\Timeline\TimelineEntrySearchService;
use OCA\OpenRegister\Notifications\Service\Timeline\TimelineEntryService;
use OCA\OpenRegister\Notifications\Service\Timeline\TimelinePermissionException;
use OCA\OpenRegister\Notifications\Service\Timeline\TimelineValidationException;
use OCA\OpenRegister\Notifications\Service\Timeline\TimelineWriteService;
use OCA\OpenRegister\Notifications\Service\TimelineVisibilityService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\TimelineEntriesControllerFactory;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Golden: status, body and collaborator call order of the per-object timeline routes,
 * for every allowed and refused path.
 *
 * @covers \OCA\OpenRegister\Notifications\Controller\TimelineEntriesController
 */
class TimelineEntriesControllerGoldenTest extends TestCase
{

    /**
     * Every collaborator call, in order.
     *
     * @var array<int, string>
     */
    private array $log = [];

    /**
     * An entry.
     *
     * @return TimelineEntry The entry.
     */
    private static function entry(string $uuid, string $visibility, ?string $rawSource = null): TimelineEntry
    {
        $entry = new TimelineEntry();
        $entry->setUuid($uuid);
        $entry->setObjectUuid('case-1');
        $entry->setVisibility($visibility);
        $entry->setRawSource($rawSource);
        return $entry;
    }

    /**
     * The controller over one scenario.
     *
     * @param array<string, mixed> $scenario The knobs, see the providers.
     *
     * @return \OCA\OpenRegister\Notifications\Controller\TimelineEntriesController The controller.
     */
    private function controller(array $scenario)
    {
        $scenario += [
            'params' => [], 'objects' => ['case-1' => true], 'manage' => false, 'entry' => 'internal',
            'fail' => null, 'source' => null,
        ];

        $request = $this->createMock(IRequest::class);
        $request->method('getParams')->willReturnCallback(
            function () use ($scenario): array {
                $this->log[] = 'getParams';
                return $scenario['params'];
            }
        );

        $current = '';
        $objects = $this->createMock(ObjectService::class);
        foreach (['setRegister', 'setSchema', 'setObject'] as $setter) {
            $objects->method($setter)->willReturnCallback(
                function ($value) use ($setter, $objects, &$current) {
                    $this->log[] = $setter . ':' . $value;
                    if ($setter === 'setObject') {
                        $current = (string)$value;
                    }

                    return $objects;
                }
            );
        }

        $objects->method('getObject')->willReturnCallback(
            function () use ($scenario, &$current): ?ObjectEntity {
                $this->log[] = 'getObject';
                $found = ($scenario['objects'][$current] ?? null);
                if ($found === 'throws') {
                    throw new RuntimeException('gone');
                }

                if ($found !== true) {
                    return null;
                }

                $object = new ObjectEntity();
                $object->setUuid($current);
                return $object;
            }
        );

        $fail = function (string $step) use ($scenario): void {
            if (($scenario['fail'][0] ?? null) !== $step) {
                return;
            }

            throw match ($scenario['fail'][1]) {
                'permission' => new TimelinePermissionException('not allowed'),
                'validation' => new TimelineValidationException(['channel' => 'This field is required']),
                default => new RuntimeException('database down'),
            };
        };

        $entries = $this->createMock(TimelineEntryService::class);
        $entries->method('listForObject')->willReturnCallback(
            function (ObjectEntity $object, ?string $visibility, int $limit, int $offset) use ($fail): array {
                $this->log[] = 'listForObject:' . $object->getUuid() . ':' . var_export($visibility, true) . ':' . $limit . ':' . $offset;
                $fail('list');
                return [self::entry('e1', 'public'), self::entry('e2', 'internal')];
            }
        );
        $entries->method('get')->willReturnCallback(
            function (ObjectEntity $object, string $entryUuid) use ($scenario): ?TimelineEntry {
                $this->log[] = 'get:' . $entryUuid;
                if ($scenario['entry'] === null) {
                    return null;
                }

                return self::entry($entryUuid, $scenario['entry'], $scenario['source']);
            }
        );
        $entries->method('pin')->willReturnCallback(
            function (ObjectEntity $object, EntryPin $pin) use ($fail): TimelineEntry {
                $this->log[] = 'pin:' . var_export($pin->pinned, true);
                $fail('pin');
                $entry = clone $pin->entry;
                $entry->setPinned($pin->pinned);
                return $entry;
            }
        );
        $entries->method('closeFollowUp')->willReturnCallback(
            function (ObjectEntity $object, TimelineEntry $entry) use ($fail): TimelineEntry {
                $this->log[] = 'closeFollowUp:' . var_export($entry->getPinned(), true);
                $fail('close');
                return $entry;
            }
        );
        $entries->method('sourceFor')->willReturnCallback(
            function (TimelineEntry $entry): array {
                $this->log[] = 'sourceFor';
                return ['source' => $entry->getRawSource(), 'headers' => []];
            }
        );

        $writer = $this->createMock(TimelineWriteService::class);
        $writer->method('write')->willReturnCallback(
            function (ObjectEntity $object, array $data) use ($fail): TimelineEntry {
                $this->log[] = 'write:' . $object->getUuid() . ':' . json_encode($data);
                $fail('write');
                return self::entry('new', 'internal');
            }
        );
        $writer->method('writeToMany')->willReturnCallback(
            function (array $objects, array $data) use ($fail): array {
                $this->log[] = 'writeToMany:' . implode(',', array_map(static fn ($o) => $o->getUuid(), $objects)) . ':' . json_encode($data);
                $fail('write');
                return [self::entry('n1', 'internal'), self::entry('n2', 'internal')];
            }
        );

        $references = $this->createMock(ReferenceService::class);
        $references->method('forEntry')->willReturnCallback(
            function (string $entryUuid): array {
                $this->log[] = 'forEntry:' . $entryUuid;
                return [];
            }
        );

        $visibility = $this->createMock(TimelineVisibilityService::class);
        $visibility->method('normalise')->willReturnCallback(
            static fn (?string $value): string => $value === 'public' ? 'public' : 'internal'
        );
        $visibility->method('mayManage')->willReturnCallback(
            function () use ($scenario): bool {
                $this->log[] = 'mayManage';
                return $scenario['manage'];
            }
        );
        $visibility->method('effectiveFilter')->willReturnCallback(
            function (?ObjectEntity $object, ?string $requested) use ($scenario): ?string {
                $this->log[] = 'effectiveFilter:' . var_export($requested, true);
                return $scenario['manage'] === true ? $requested : 'public';
            }
        );

        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('error')->willReturnCallback(
            function (string $message): void {
                $this->log[] = 'error:' . $message;
            }
        );

        return TimelineEntriesControllerFactory::create(
            'openregister',
            $request,
            $objects,
            $entries,
            $writer,
            $this->createMock(TimelineEntrySearchService::class),
            $references,
            $visibility,
            $logger
        );
    }

    /**
     * The status and a comparable body.
     *
     * @return array{0: int, 1: mixed} Status and body.
     */
    private static function answer($response): array
    {
        $data = $response->getData();
        if (isset($data['results']) === true) {
            $data['results'] = array_column($data['results'], 'id');
        } elseif (isset($data['id']) === true) {
            $data = array_intersect_key($data, array_flip(['id', 'pinned', 'references']));
        }

        return [$response->getStatus(), $data];
    }

    /** @return array<string, array<int, mixed>> */
    public static function routeProvider(): array
    {
        $path    = ['setRegister:zaken', 'setSchema:zaak', 'setObject:case-1', 'getObject'];
        $missing = [404, ['message' => 'Object not found']];
        $gone    = [404, ['message' => 'Entry not found']];
        $failed  = [500, ['message' => 'The timeline could not be read']];
        $cases   = [];
        foreach (['index', 'create', 'show', 'update', 'source'] as $route) {
            $cases[$route . ' object null']   = [$route, ['objects' => []], $missing, $path];
            $cases[$route . ' object throws'] = [$route, ['objects' => ['case-1' => 'throws']], $missing, $path];
        }

        foreach (['show', 'update', 'source'] as $route) {
            $cases[$route . ' entry not on object'] = [$route, ['entry' => null], $gone, array_merge($path, ['get:entry-a'])];
        }

        $entry = array_merge($path, ['get:entry-a']);
        $data  = '{"message":"m","register":"zaken","schema":"zaak"}';
        return $cases + [
            'index reader'          => ['index', ['params' => ['visibility' => 'internal', 'limit' => '5', 'offset' => '2']], [200, ['results' => ['e1', 'e2'], 'total' => 2, 'visibility' => 'public', 'canManage' => false]], array_merge($path, ['getParams', "effectiveFilter:'internal'", "listForObject:case-1:'public':5:2", 'mayManage'])],
            'index manager'         => ['index', ['manage' => true, 'params' => ['visibility' => ['x']]], [200, ['results' => ['e1', 'e2'], 'total' => 2, 'visibility' => null, 'canManage' => true]], array_merge($path, ['getParams', 'effectiveFilter:NULL', 'listForObject:case-1:NULL:50:0', 'mayManage'])],
            'index failure'         => ['index', ['fail' => ['list', 'boom']], $failed, array_merge($path, ['getParams', 'effectiveFilter:NULL', "listForObject:case-1:'public':50:0", 'error:[TimelineEntriesController] index failed'])],
            'create one'            => ['create', ['params' => ['message' => 'm']], [201, ['id' => 'new', 'pinned' => false]], array_merge($path, ['getParams', 'write:case-1:' . $data])],
            'create many'           => ['create', ['objects' => ['case-1' => true, 'case-2' => true], 'params' => ['message' => 'm', 'relatedObjects' => [['register' => 'zaken', 'schema' => 'zaak', 'id' => 'case-2']]]], [201, ['results' => ['n1', 'n2']]], array_merge($path, ['getParams', 'setRegister:zaken', 'setSchema:zaak', 'setObject:case-2', 'getObject', 'writeToMany:case-1,case-2:{"message":"m","relatedObjects":[{"register":"zaken","schema":"zaak","id":"case-2"}],"register":"zaken","schema":"zaak"}'])],
            'create related scalar' => ['create', ['params' => ['message' => 'm', 'relatedObjects' => ['case-2']]], [400, ['message' => 'Each related object names its register, schema and id']], array_merge($path, ['getParams'])],
            'create related empty'  => ['create', ['params' => ['message' => 'm', 'relatedObjects' => [['id' => 'case-2']]]], [404, ['message' => 'Related object case-2 could not be reached']], array_merge($path, ['getParams'])],
            'create related gone'   => ['create', ['params' => ['message' => 'm', 'relatedObjects' => [['register' => 'zaken', 'schema' => 'zaak', 'id' => 'case-9']]]], [404, ['message' => 'Related object case-9 could not be reached']], array_merge($path, ['getParams', 'setRegister:zaken', 'setSchema:zaak', 'setObject:case-9', 'getObject'])],
            'create related ignored' => ['create', ['params' => ['message' => 'm', 'relatedObjects' => 'case-2']], [201, ['id' => 'new', 'pinned' => false]], array_merge($path, ['getParams', 'write:case-1:{"message":"m","relatedObjects":"case-2","register":"zaken","schema":"zaak"}'])],
            'create invalid'        => ['create', ['params' => ['message' => 'm'], 'fail' => ['write', 'validation']], [400, ['message' => 'This field is required', 'errors' => ['channel' => 'This field is required']]], array_merge($path, ['getParams', 'write:case-1:' . $data])],
            'create failure'        => ['create', ['params' => ['message' => 'm'], 'fail' => ['write', 'boom']], $failed, array_merge($path, ['getParams', 'write:case-1:' . $data, 'error:[TimelineEntriesController] create failed'])],
            'show public'           => ['show', ['entry' => 'public'], [200, ['id' => 'entry-a', 'pinned' => false, 'references' => []]], array_merge($entry, ['forEntry:entry-a'])],
            'show internal manager' => ['show', ['manage' => true], [200, ['id' => 'entry-a', 'pinned' => false, 'references' => []]], array_merge($entry, ['mayManage', 'forEntry:entry-a'])],
            'show internal reader'  => ['show', [], $gone, array_merge($entry, ['mayManage'])],
            'update nothing'        => ['update', [], [200, ['id' => 'entry-a', 'pinned' => false]], array_merge($entry, ['getParams'])],
            'update pin'            => ['update', ['params' => ['pinned' => true]], [200, ['id' => 'entry-a', 'pinned' => true]], array_merge($entry, ['getParams', 'pin:true'])],
            'update unpin string'   => ['update', ['params' => ['pinned' => 'false']], [200, ['id' => 'entry-a', 'pinned' => false]], array_merge($entry, ['getParams', 'pin:false'])],
            'update pin and close'  => ['update', ['params' => ['pinned' => 1, 'followUp' => 'done']], [200, ['id' => 'entry-a', 'pinned' => true]], array_merge($entry, ['getParams', 'pin:true', 'closeFollowUp:true'])],
            'update close other'    => ['update', ['params' => ['followUp' => 'open']], [200, ['id' => 'entry-a', 'pinned' => false]], array_merge($entry, ['getParams'])],
            'update refused'        => ['update', ['params' => ['pinned' => true], 'fail' => ['pin', 'permission']], [403, ['message' => 'not allowed']], array_merge($entry, ['getParams', 'pin:true'])],
            'update invalid'        => ['update', ['params' => ['followUp' => 'done'], 'fail' => ['close', 'validation']], [400, ['message' => 'This field is required', 'errors' => ['channel' => 'This field is required']]], array_merge($entry, ['getParams', 'closeFollowUp:false'])],
            'update failure'        => ['update', ['params' => ['followUp' => 'done'], 'fail' => ['close', 'boom']], $failed, array_merge($entry, ['getParams', 'closeFollowUp:false', 'error:[TimelineEntriesController] update failed'])],
            'source public'         => ['source', ['entry' => 'public', 'source' => 'RAW'], [200, ['source' => 'RAW', 'headers' => []]], array_merge($entry, ['sourceFor'])],
            'source none'           => ['source', ['entry' => 'public'], [404, ['message' => 'This entry was not made from an inbound message']], array_merge($entry, ['sourceFor'])],
            'source internal reader' => ['source', ['source' => 'RAW'], $gone, array_merge($entry, ['mayManage'])],
            'source internal manager' => ['source', ['manage' => true, 'source' => 'RAW'], [200, ['source' => 'RAW', 'headers' => []]], array_merge($entry, ['mayManage', 'sourceFor'])],
        ];
    }

    #[DataProvider('routeProvider')]
    public function testRoute(string $route, array $scenario, array $answer, array $log): void
    {
        $controller = $this->controller($scenario);
        $response   = match ($route) {
            'index', 'create' => $controller->$route('zaken', 'zaak', 'case-1'),
            default => $controller->$route('zaken', 'zaak', 'case-1', 'entry-a'),
        };

        $this->assertSame([$answer, $log], [self::answer($response), $this->log]);
    }

    public function testAnEmptyPathPartIsNotFoundWithoutALookup(): void
    {
        $response = $this->controller([])->index('', 'zaak', 'case-1');

        $this->assertSame([404, ['message' => 'Object not found']], self::answer($response));
        $this->assertSame([], $this->log);
    }
}
