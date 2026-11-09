<?php

/**
 * Golden outcomes of FileLockHandler: who may lock, unlock and force-unlock, and the cache TTL a lock is written with.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\File
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\File;

use OCA\OpenRegister\Service\File\FileSession;
use Exception;
use OCA\OpenRegister\Service\File\FileLockHandler;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The acting user is switched through a variable, so the outcomes do not depend
 * on how often the handler reads the session.
 *
 * @covers \OCA\OpenRegister\Service\File\FileLockHandler
 */
class FileLockHandlerOutcomeGoldenTest extends TestCase
{
    private string $actor = 'owner';

    /** @var array<string, array{mixed, int}> */
    private array $store = [];

    private function handler(): FileLockHandler
    {
        $test  = $this;
        $cache = $this->createMock(ICache::class);
        $cache->method('get')->willReturnCallback(fn ($key) => ($this->store[$key][0] ?? null));
        $cache->method('set')->willReturnCallback(function ($key, $value, $ttl = 0): bool {
            $this->store[$key] = [$value, $ttl];
            return true;
        });
        $cache->method('remove')->willReturnCallback(function ($key): bool {
            unset($this->store[$key]);
            return true;
        });
        $factory = $this->createMock(ICacheFactory::class);
        $factory->method('createDistributed')->willReturn($cache);

        $session = $this->createMock(IUserSession::class);
        $session->method('getUser')->willReturnCallback(static function () use ($test): IUser {
            $user = $test->createMock(IUser::class);
            $user->method('getUID')->willReturn($test->actor());
            return $user;
        });
        $groups = $this->createMock(IGroupManager::class);
        $groups->method('isAdmin')->willReturnCallback(static fn (string $uid): bool => $uid === 'admin');

        return new FileLockHandler($factory, new FileSession($session, $groups, $this->createMock(LoggerInterface::class)));
    }

    public function actor(): string
    {
        return $this->actor;
    }

    /**
     * Each case: who unlocks, whether forced, expected outcome.
     *
     * @return array<string, array{string, bool, string}>
     */
    public static function unlockCases(): array
    {
        return [
            'owner unlocks'                  => ['owner', false, 'unlocked'],
            'other user refused'             => ['other', false, 'Only the lock owner or an admin can unlock this file'],
            'admin without force refused'    => ['admin', false, 'Only the lock owner or an admin can unlock this file'],
            'admin forces'                   => ['admin', true, 'unlocked'],
            'other user forcing refused'     => ['other', true, 'Only administrators can force-unlock files'],
            'owner forcing, not admin, refused' => ['owner', true, 'Only administrators can force-unlock files'],
        ];
    }

    #[DataProvider('unlockCases')]
    public function testUnlockOutcome(string $actor, bool $force, string $expected): void
    {
        $handler = $this->handler();
        $handler->lockFile(7);

        $this->actor = $actor;
        $outcome     = 'unlocked';
        try {
            $handler->unlockFile(7, $force);
        } catch (Exception $e) {
            $outcome = $e->getMessage();
        }

        $this->assertSame($expected, $outcome);
        $this->assertSame($expected !== 'unlocked', $handler->isLocked(7));
    }

    public function testLockByAnotherUserIsRefusedAndOwnerRefreshes(): void
    {
        $handler = $this->handler();
        $handler->lockFile(8, 5);

        $this->actor = 'other';
        try {
            $handler->lockFile(8);
            $this->fail('A second user must not take the lock.');
        } catch (Exception $e) {
            $this->assertSame('File is locked by owner', $e->getMessage());
        }

        $this->actor = 'owner';
        $result      = $handler->lockFile(8, 10);
        $this->assertTrue($result['locked']);
        $this->assertSame('owner', $result['lockedBy']);
        $this->assertSame(600, $this->ttlOf(8));
    }

    public function testLockIsWrittenWithTheTtlInSeconds(): void
    {
        $this->handler()->lockFile(9, 3);
        $this->assertSame(180, $this->ttlOf(9));

        $this->handler()->lockFile(10);
        $this->assertSame(1800, $this->ttlOf(10));
    }

    public function testNoUserCannotLock(): void
    {
        $session = $this->createMock(IUserSession::class);
        $session->method('getUser')->willReturn(null);
        $handler = new FileLockHandler(
            $this->createMock(ICacheFactory::class),
            new FileSession($session, $this->createMock(IGroupManager::class), $this->createMock(LoggerInterface::class))
        );

        $this->expectExceptionMessage('No user logged in');
        $handler->lockFile(11);
    }

    private function ttlOf(int $fileId): int
    {
        return (int)($this->store['openregister:file-lock:' . $fileId][1] ?? -1);
    }
}
