<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Notifications\Controller;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Notifications\Controller\ObjectWatchersController;
use OCA\OpenRegister\Notifications\Controller\Support\ObjectWatchersControllerServices;
use OCA\OpenRegister\Notifications\Db\Watcher;
use OCA\OpenRegister\Service\Interaction\AddressedObject;
use OCA\OpenRegister\Service\Interaction\WatcherServiceInterface;
use OCP\IRequest;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Golden: status, body and call order of every watcher route, allowed and refused.
 *
 * @covers \OCA\OpenRegister\Notifications\Controller\ObjectWatchersController
 */
class ObjectWatchersControllerGoldenTest extends TestCase
{

    /**
     * Every collaborator call, in order.
     *
     * @var array<int, string>
     */
    private array $log = [];

    /**
     * A watcher row.
     *
     * @return Watcher The row.
     */
    private static function row(string $userId): Watcher
    {
        $watcher = new Watcher();
        $watcher->setUserId($userId);
        $watcher->setObjectUuid('uuid-a');
        $watcher->setNotify(false);
        return $watcher;
    }

    /**
     * The controller over one scenario.
     *
     * @param array<string, mixed> $scenario object (found|null|throws), fail (null|refused|boom), notify, known.
     *
     * @return ObjectWatchersController The controller.
     */
    private function controller(array $scenario): ObjectWatchersController
    {
        $scenario += ['object' => 'found', 'fail' => null, 'notify' => null, 'known' => true];

        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturnCallback(
            function (string $key) use ($scenario) {
                $this->log[] = 'getParam:' . $key;
                return $scenario['notify'];
            }
        );

        $objects = $this->createMock(ObjectOperationsInterface::class);
        foreach (['setRegister', 'setSchema', 'setObject'] as $setter) {
            $objects->method($setter)->willReturnCallback(
                function ($value) use ($setter, $objects) {
                    $this->log[] = $setter . ':' . $value;
                    return $objects;
                }
            );
        }

        $objects->method('getObject')->willReturnCallback(
            function () use ($scenario): ?ObjectEntity {
                $this->log[] = 'getObject';
                if ($scenario['object'] === 'throws') {
                    throw new RuntimeException('gone');
                }

                return $scenario['object'] === 'found' ? new ObjectEntity() : null;
            }
        );

        $fail = function (string $what) use ($scenario): void {
            $this->log[] = $what;
            if ($scenario['fail'] === 'refused') {
                throw new NotAuthorizedException('Not yours');
            }

            if ($scenario['fail'] === 'boom') {
                throw new RuntimeException('database down');
            }
        };

        $watchers = $this->createMock(WatcherServiceInterface::class);
        $watchers->method('watch')->willReturnCallback(
            function (AddressedObject $target, ?bool $notify) use ($fail): Watcher {
                $fail('watch:' . $target->register . ':' . $target->schema . ':' . var_export($notify, true));
                return self::row('me');
            }
        );
        $watchers->method('unwatch')->willReturnCallback(
            function () use ($fail): bool {
                $fail('unwatch');
                return true;
            }
        );
        $watchers->method('listWatchers')->willReturnCallback(
            function () use ($fail): array {
                $fail('listWatchers');
                return [self::row('alice'), self::row('bob')];
            }
        );
        $watchers->method('addWatcher')->willReturnCallback(
            function (AddressedObject $target, string $userId) use ($fail): Watcher {
                $fail('addWatcher:' . $userId . ':' . $target->register . ':' . $target->schema);
                return self::row($userId);
            }
        );
        $watchers->method('removeWatcher')->willReturnCallback(
            function (ObjectEntity $object, string $userId) use ($fail): bool {
                $fail('removeWatcher:' . $userId);
                return true;
            }
        );

        $users = $this->createMock(IUserManager::class);
        $users->method('userExists')->willReturnCallback(
            function (string $uid) use ($scenario): bool {
                $this->log[] = 'userExists:' . $uid;
                return $scenario['known'];
            }
        );

        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('error')->willReturnCallback(
            function (string $message): void {
                $this->log[] = 'error:' . $message;
            }
        );

        return new ObjectWatchersController(
            $request,
            new ObjectWatchersControllerServices(objectService: $objects, watchers: $watchers, userManager: $users, logger: $logger)
        );
    }

    /** @return array<string, array{0: string, 1: array<string, mixed>, 2: int, 3: mixed, 4: array<int, string>}> */
    public static function routeProvider(): array
    {
        $path    = ['setRegister:r', 'setSchema:s', 'setObject:o', 'getObject'];
        $me      = ['userId' => 'me', 'objectUuid' => 'uuid-a', 'notify' => false];
        $refused = ['message' => 'Not yours'];
        $error   = ['message' => 'Could not complete the watcher request'];
        $missing = ['message' => 'Object not found'];
        $cases   = [];
        foreach (['watch', 'unwatch', 'index', 'add', 'remove'] as $route) {
            $cases[$route . ' lookup throws']  = [$route, ['object' => 'throws'], 404, $missing, $path];
            $cases[$route . ' lookup is null'] = [$route, ['object' => null], 404, $missing, $path];
        }

        $watch = array_merge($path, ['getParam:notify', 'watch:r:s:NULL']);
        $add   = array_merge($path, ['userExists:bob', 'addWatcher:bob:r:s']);
        return $cases + [
            'watch'              => ['watch', [], 200, $me, $watch],
            'watch notify off'   => ['watch', ['notify' => 'false'], 200, $me, array_merge($path, ['getParam:notify', 'watch:r:s:false'])],
            'watch notify typo'  => ['watch', ['notify' => 'maybe'], 200, $me, array_merge($path, ['getParam:notify', 'watch:r:s:NULL'])],
            'watch refused'      => ['watch', ['fail' => 'refused'], 403, $refused, $watch],
            'watch boom'         => ['watch', ['fail' => 'boom'], 500, $error, array_merge($watch, ['error:[ObjectWatchersController] watch: database down'])],
            'unwatch'            => ['unwatch', [], 204, [], array_merge($path, ['unwatch'])],
            'unwatch refused'    => ['unwatch', ['fail' => 'refused'], 403, $refused, array_merge($path, ['unwatch'])],
            'unwatch boom'       => ['unwatch', ['fail' => 'boom'], 500, $error, array_merge($path, ['unwatch', 'error:[ObjectWatchersController] unwatch: database down'])],
            'index'              => ['index', [], 200, ['results' => [['userId' => 'alice', 'objectUuid' => 'uuid-a'], ['userId' => 'bob', 'objectUuid' => 'uuid-a']], 'total' => 2], array_merge($path, ['listWatchers'])],
            'index refused'      => ['index', ['fail' => 'refused'], 403, $refused, array_merge($path, ['listWatchers'])],
            'index boom'         => ['index', ['fail' => 'boom'], 500, $error, array_merge($path, ['listWatchers', 'error:[ObjectWatchersController] index: database down'])],
            'add'                => ['add', [], 200, ['userId' => 'bob', 'objectUuid' => 'uuid-a', 'notify' => false], $add],
            'add unknown user'   => ['add', ['known' => false], 400, ['message' => 'Unknown user'], array_merge($path, ['userExists:bob'])],
            'add refused'        => ['add', ['fail' => 'refused'], 403, $refused, $add],
            'add boom'           => ['add', ['fail' => 'boom'], 500, $error, array_merge($add, ['error:[ObjectWatchersController] add: database down'])],
            'remove'             => ['remove', [], 204, [], array_merge($path, ['removeWatcher:bob'])],
            'remove refused'     => ['remove', ['fail' => 'refused'], 403, $refused, array_merge($path, ['removeWatcher:bob'])],
            'remove boom'        => ['remove', ['fail' => 'boom'], 500, $error, array_merge($path, ['removeWatcher:bob', 'error:[ObjectWatchersController] remove: database down'])],
        ];
    }

    #[DataProvider('routeProvider')]
    public function testRoute(string $route, array $scenario, int $status, mixed $body, array $log): void
    {
        $controller = $this->controller($scenario);
        $response   = match ($route) {
            'add', 'remove' => $controller->$route('r', 's', 'o', 'bob'),
            default => $controller->$route('r', 's', 'o'),
        };

        $data = $response->getData();
        $keep = ['userId', 'objectUuid', 'notify'];
        if (isset($data['userId']) === true) {
            $data = array_intersect_key($data, array_flip($keep));
        }

        if (isset($data['results']) === true) {
            $data['results'] = array_map(static fn (array $row): array => array_intersect_key($row, array_flip($keep)), $data['results']);
        }

        $this->assertSame([$status, $body, $log], [$response->getStatus(), $data, $this->log]);
    }
}
