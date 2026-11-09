<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Mcp;

use OCA\OpenRegister\Mcp\IMcpToolProvider;
use OCA\OpenRegister\Mcp\PerAppMcpProviderProbe;
use OCP\App\IAppManager;
use OCP\ICacheFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Pins the info.xml cases of the candidate list and the first-match-wins
 * order of the cold probe.
 *
 * @covers \OCA\OpenRegister\Mcp\PerAppMcpProviderProbe
 */
class PerAppMcpProviderProbeGoldenTest extends TestCase
{

    private string $appDir = '';

    /**
     * Remove the temporary app directory.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        if ($this->appDir !== '') {
            @unlink($this->appDir . '/appinfo/info.xml');
            @rmdir($this->appDir . '/appinfo');
            @rmdir($this->appDir);
        }

        parent::tearDown();
    }//end tearDown()

    /**
     * Info.xml bodies that add no third candidate.
     *
     * @return array<string, array{0: string|null}>
     */
    public static function noThirdCandidate(): array
    {
        return [
            'same namespace'  => ['<info><namespace>Pipelinq</namespace></info>'],
            'empty namespace' => ['<info><namespace></namespace></info>'],
            'no namespace'    => ['<info><name>x</name></info>'],
            'malformed xml'   => ['<info><namespace>Broken'],
            'empty file'      => [''],
            'no info.xml'     => [null],
        ];
    }//end noThirdCandidate()

    /**
     * Only a differing, non-empty declared namespace adds a third candidate.
     *
     * @param string|null $infoXml The info.xml body, or null for no file.
     *
     * @return void
     */
    #[DataProvider('noThirdCandidate')]
    public function testNoThirdCandidate(?string $infoXml): void
    {
        $this->appDir = sys_get_temp_dir() . '/or-mcp-probe-golden-' . bin2hex(random_bytes(6));
        mkdir($this->appDir . '/appinfo', 0777, true);
        if ($infoXml !== null) {
            file_put_contents($this->appDir . '/appinfo/info.xml', $infoXml);
        }

        $appManager = $this->createMock(IAppManager::class);
        $appManager->method('getAppPath')->willReturn($this->appDir);

        $method = new ReflectionMethod(PerAppMcpProviderProbe::class, 'buildMcpProviderCandidates');
        $this->assertSame(
            ['OCA\\OpenRegister\\Mcp\\IMcpToolProvider::pipelinq', 'OCA\\Pipelinq\\Mcp\\PipelinqToolProvider'],
            $method->invoke(new PerAppMcpProviderProbe(), 'pipelinq', $appManager)
        );
    }//end testNoThirdCandidate()

    /**
     * The cold probe stops at an app's first resolving candidate, in app order.
     *
     * @return void
     */
    public function testColdProbeStopsAtTheFirstResolvingCandidate(): void
    {
        $first = $this->createMock(IMcpToolProvider::class);
        $other = $this->createMock(IMcpToolProvider::class);
        $probe = new class ($first, $other) extends PerAppMcpProviderProbe {
            /** @var list<string> */
            public array $tried = [];

            public function __construct(private IMcpToolProvider $first, private IMcpToolProvider $other)
            {
            }

            protected function buildMcpProviderCandidates(string $appId, $appManager): array
            {
                return [$appId . ':a', $appId . ':b', $appId . ':c'];
            }

            protected function tryResolveMcpProviderCandidate(ContainerInterface $container, LoggerInterface $logger, string $appId, string $key): ?IMcpToolProvider
            {
                $this->tried[] = $key;
                return match ($key) {
                    'one:b' => $this->first,
                    'two:a' => $this->other,
                    default => null,
                };
            }
        };

        $apps = $this->createMock(IAppManager::class);
        $apps->method('getInstalledApps')->willReturn(['one', 'two', 'three']);
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(
            fn (string $id) => match ($id) {
                'OCP\App\IAppManager' => $apps,
                ICacheFactory::class  => throw new \RuntimeException('no cache'),
            }
        );

        $providers = [];
        $probe->collectPerAppMcpProviders($container, $this->createMock(LoggerInterface::class), $providers);

        $this->assertSame([$first, $other], $providers);
        $this->assertSame(['one:a', 'one:b', 'two:a', 'three:a', 'three:b', 'three:c'], $probe->tried);
    }//end testColdProbeStopsAtTheFirstResolvingCandidate()
}//end class
