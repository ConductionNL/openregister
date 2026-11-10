<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

namespace OCA\OpenRegister\Tests\Unit\Notifications\BackgroundJob;

use OCA\OpenRegister\Notifications\BackgroundJob\WebPushDispatchJob;
use OCA\OpenRegister\Notifications\Service\WebPush\WebPushService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins the exact Service-Worker payload built from a job argument.
 *
 * @covers \OCA\OpenRegister\Notifications\BackgroundJob\WebPushDispatchJob
 */
class WebPushDispatchJobPayloadCharacterisationTest extends TestCase
{

    private function payloadFor(array $argument): array
    {
        $captured = null;
        $service  = $this->createMock(WebPushService::class);
        $service->expects($this->once())->method('deliver')->willReturnCallback(
            function (string $uid, array $payload) use (&$captured): int {
                $captured = $payload;
                return 1;
            }
        );
        $job    = new WebPushDispatchJob($this->createMock(ITimeFactory::class), $service, $this->createMock(LoggerInterface::class));
        $method = new \ReflectionMethod($job, 'run');
        $method->invoke($job, array_merge(['uid' => 'alice'], $argument));
        return $captured;
    }

    public function testDefaults(): void
    {
        $this->assertSame(
            [
                'title'   => 'OpenRegister',
                'body'    => '',
                'icon'    => '/index.php/apps/openregister/webpush/icon/openregister',
                'badge'   => '/index.php/apps/openregister/webpush/badge/openregister',
                'tag'     => '',
                'actions' => [],
                'data'    => ['url' => '', 'actions' => []],
            ],
            $this->payloadFor([])
        );
    }

    public function testOriginAppIsUrlEncodedAndScalarsAreCast(): void
    {
        $payload = $this->payloadFor(['originApp' => 'my app/x', 'title' => 5, 'body' => 1.5, 'tag' => 0]);
        $this->assertSame('/index.php/apps/openregister/webpush/icon/my%20app%2Fx', $payload['icon']);
        $this->assertSame('/index.php/apps/openregister/webpush/badge/my%20app%2Fx', $payload['badge']);
        $this->assertSame(['5', '1.5', '0'], [$payload['title'], $payload['body'], $payload['tag']]);
    }

    /** @return array<string, array{0: mixed, 1: array, 2: array, 3: string}> */
    public static function actionsProvider(): array
    {
        return [
            'not a list'     => ['x', [], [], ''],
            'skips invalid'  => [
                ['a' => 'no', 'b' => ['label' => ['en' => 'L']], 'c' => ['url' => ''], 'd' => ['url' => '/d', 'label' => 'plain']],
                [['action' => 'action-d', 'title' => '']],
                ['action-d' => '/d'],
                '/d',
            ],
            'first wins'     => [
                [['url' => '/one', 'label' => ['nl' => 'Een']], ['url' => '/two', 'label' => ['en' => 'Two', 'nl' => 'Twee']]],
                [['action' => 'action-0', 'title' => 'Een'], ['action' => 'action-1', 'title' => 'Two']],
                ['action-0' => '/one', 'action-1' => '/two'],
                '/one',
            ],
            'primary wins'   => [
                [['url' => '/one'], ['url' => '/two', 'primary' => true], ['url' => '/three', 'primary' => 'yes']],
                [['action' => 'action-0', 'title' => ''], ['action' => 'action-1', 'title' => ''], ['action' => 'action-2', 'title' => '']],
                ['action-0' => '/one', 'action-1' => '/two', 'action-2' => '/three'],
                '/two',
            ],
            'last primary'   => [
                [['url' => '/one', 'primary' => true], ['url' => '/two', 'primary' => true]],
                [['action' => 'action-0', 'title' => ''], ['action' => 'action-1', 'title' => '']],
                ['action-0' => '/one', 'action-1' => '/two'],
                '/two',
            ],
            'empty labels'   => [
                [['url' => '/e', 'label' => []], ['url' => '/f', 'label' => ['de' => false]]],
                [['action' => 'action-0', 'title' => ''], ['action' => 'action-1', 'title' => '']],
                ['action-0' => '/e', 'action-1' => '/f'],
                '/e',
            ],
        ];
    }

    #[DataProvider('actionsProvider')]
    public function testActions(mixed $actions, array $buttons, array $urls, string $url): void
    {
        $payload = $this->payloadFor(['actions' => $actions]);
        $this->assertSame($buttons, $payload['actions']);
        $this->assertSame(['url' => $url, 'actions' => $urls], $payload['data']);
    }
}
