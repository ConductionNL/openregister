<?php

/**
 * Behaviour tests for AppRegisterImportRunner: choosing the app's own forced entry point or OpenRegister's import.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
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

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\AppHost\Service\RegisterDocumentLoaderInterface;
use OCA\OpenRegister\Kernel\Contracts\AppContainerFinderInterface;
use OCA\OpenRegister\Service\AppConfigurationImporterInterface;
use OCA\OpenRegister\Service\AppRegisterImportRunner;
use OCP\App\IAppManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\AppRegisterImportRunner
 * @uses   \OCA\OpenRegister\Service\RegisterImportRoute
 */
class AppRegisterImportRunnerBehaviourTest extends TestCase
{
    private const SETTINGS = 'OCA\\Demo\\Service\\SettingsService';

    /** @var IAppManager&MockObject */
    private IAppManager $apps;

    /** @var AppContainerFinderInterface&MockObject */
    private AppContainerFinderInterface $containers;

    /** @var AppConfigurationImporterInterface&MockObject */
    private AppConfigurationImporterInterface $importer;

    /** @var RegisterDocumentLoaderInterface&MockObject */
    private RegisterDocumentLoaderInterface $documents;

    private string $appDir = '';

    protected function setUp(): void
    {
        $this->apps       = $this->createMock(IAppManager::class);
        $this->containers = $this->createMock(AppContainerFinderInterface::class);
        $this->importer   = $this->createMock(AppConfigurationImporterInterface::class);
        $this->documents  = $this->createMock(RegisterDocumentLoaderInterface::class);
        $this->appDir     = sys_get_temp_dir().'/or-runner-'.bin2hex(random_bytes(4));
        mkdir($this->appDir.'/lib/Settings/register.d', 0777, true);
    }//end setUp()

    protected function tearDown(): void
    {
        array_map('unlink', (array) glob($this->appDir.'/lib/Settings/register.d/*'));
        rmdir($this->appDir.'/lib/Settings/register.d');
        rmdir($this->appDir.'/lib/Settings');
        rmdir($this->appDir.'/lib');
        rmdir($this->appDir);
    }//end tearDown()

    private function runner(): AppRegisterImportRunner
    {
        return new AppRegisterImportRunner($this->apps, $this->containers, $this->importer, $this->documents);
    }//end runner()

    private function appWithService(mixed $service): void
    {
        $this->apps->method('getAppInfo')->with('demo')->willReturn(['namespace' => 'Demo']);
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->with(self::SETTINGS)->willReturn(true);
        $container->method('get')->with(self::SETTINGS)->willReturn($service);
        $this->containers->method('find')->with('demo')->willReturn($container);
    }//end appWithService()

    private function openRegisterImports(?array $data, array|RuntimeException $result): void
    {
        $this->documents->method('load')->with('demo')->willReturn([$data, '1.2.0']);
        $this->apps->method('getAppPath')->willReturn($this->appDir);
        if ($result instanceof RuntimeException) {
            $this->importer->method('importFromApp')->willThrowException($result);
            return;
        }

        $this->importer->method('importFromApp')->willReturn($result);
    }//end openRegisterImports()

    public function testDocumentCountsTheRegisterFragmentsOfTheApp(): void
    {
        touch($this->appDir.'/lib/Settings/register.d/a.json');
        touch($this->appDir.'/lib/Settings/register.d/b.json');
        touch($this->appDir.'/lib/Settings/register.d/notes.txt');
        $this->documents->method('load')->willReturn([['registers' => []], '2.0.0']);
        $this->apps->method('getAppPath')->with('demo')->willReturn($this->appDir);

        $this->assertSame(
            ['data' => ['registers' => []], 'version' => '2.0.0', 'fragments' => 2],
            $this->runner()->document('demo')
        );
    }//end testDocumentCountsTheRegisterFragmentsOfTheApp()

    public function testDocumentCountsNoFragmentsForAnAppWithoutAPath(): void
    {
        $this->documents->method('load')->willReturn([null, '']);
        $this->apps->method('getAppPath')->willThrowException(new RuntimeException('not installed'));

        $this->assertSame(['data' => null, 'version' => '', 'fragments' => 0], $this->runner()->document('demo'));
    }//end testDocumentCountsNoFragmentsForAnAppWithoutAPath()

    public function testAnAppWithoutNamespaceGoesThroughOpenRegister(): void
    {
        $this->apps->method('getAppInfo')->willReturn([]);
        $this->containers->expects($this->never())->method('find');
        $this->openRegisterImports(['registers' => ['x']], ['ok' => true]);
        $this->importer->expects($this->once())
            ->method('importFromApp')
            ->with('demo', ['registers' => ['x']], '1.2.0', true);

        $this->assertSame(
            [
                'success' => true,
                'path'    => 'openregister',
                'via'     => 'OpenRegister (lib/Settings/demo_register.json + register.d)',
                'message' => '',
            ],
            $this->runner()->run('demo')
        );
    }//end testAnAppWithoutNamespaceGoesThroughOpenRegister()

    public function testAnAppWithoutAContainerGoesThroughOpenRegister(): void
    {
        $this->apps->method('getAppInfo')->willReturn(['namespace' => 'Demo']);
        $this->containers->method('find')->willReturn(null);
        $this->openRegisterImports(['r' => 1], ['ok' => true]);

        $this->assertSame('openregister', $this->runner()->run('demo')['path']);
    }//end testAnAppWithoutAContainerGoesThroughOpenRegister()

    public function testAContainerWithoutTheServiceOrFailingToBuildItGoesThroughOpenRegister(): void
    {
        $this->apps->method('getAppInfo')->willReturn(['namespace' => 'Demo']);
        $without = $this->createMock(ContainerInterface::class);
        $without->method('has')->willReturn(false);
        $without->expects($this->never())->method('get');
        $broken = $this->createMock(ContainerInterface::class);
        $broken->method('has')->willReturn(true);
        $broken->method('get')->willThrowException(new RuntimeException('cannot build'));
        $this->containers->method('find')->willReturnOnConsecutiveCalls($without, $broken);
        $this->openRegisterImports(['r' => 1], ['ok' => true]);
        $runner = $this->runner();

        $this->assertSame('openregister', $runner->run('demo')['path']);
        $this->assertSame('openregister', $runner->run('demo')['path']);
    }//end testAContainerWithoutTheServiceOrFailingToBuildItGoesThroughOpenRegister()

    public function testANonObjectServiceGoesThroughOpenRegister(): void
    {
        $this->appWithService('not an object');
        $this->openRegisterImports(['r' => 1], ['ok' => true]);

        $this->assertSame('openregister', $this->runner()->run('demo')['path']);
    }//end testANonObjectServiceGoesThroughOpenRegister()

    public function testAServiceWithoutAnAcceptableEntryPointGoesThroughOpenRegister(): void
    {
        $service = new class {
            public array $calls = [];

            public function loadConfigurationForced(string $required): array
            {
                $this->calls[] = 'loadConfigurationForced';
                return [];
            }

            public function loadConfiguration(bool $other = false): array
            {
                $this->calls[] = 'loadConfiguration';
                return [];
            }

            public function loadSettings(): array
            {
                $this->calls[] = 'loadSettings';
                return [];
            }

            private function reloadConfiguration(): array
            {
                return [];
            }
        };
        $this->appWithService($service);
        $this->openRegisterImports(['r' => 1], ['ok' => true]);

        $this->assertSame('openregister', $this->runner()->run('demo')['path']);
        $this->assertSame([], $service->calls);
    }//end testAServiceWithoutAnAcceptableEntryPointGoesThroughOpenRegister()

    public function testAParameterlessForcedEntryPointIsCalledOnceWithoutArguments(): void
    {
        $service = new class {
            public array $calls = [];

            public function loadConfigurationForced(): array
            {
                $this->calls[] = func_get_args();
                return ['message' => 'Imported 3 schemas'];
            }

            public function loadConfiguration(bool $force = false): array
            {
                $this->calls[] = 'wrong entry point';
                return [];
            }
        };
        $this->appWithService($service);
        $this->importer->expects($this->never())->method('importFromApp');

        $this->assertSame(
            [
                'success' => true,
                'path'    => 'app',
                'via'     => self::SETTINGS.'::loadConfigurationForced()',
                'message' => 'Imported 3 schemas',
            ],
            $this->runner()->run('demo')
        );
        $this->assertSame([[]], $service->calls);
    }//end testAParameterlessForcedEntryPointIsCalledOnceWithoutArguments()

    public function testAForceParameterIsPassedByNameAsTrue(): void
    {
        $service = new class {
            public array $calls = [];

            public function loadSettings(bool $force = false, bool $dryRun = true): array
            {
                $this->calls[] = [$force, $dryRun];
                return ['success' => true];
            }
        };
        $this->appWithService($service);

        $result = $this->runner()->run('demo');

        $this->assertSame([[true, true]], $service->calls);
        $this->assertSame(self::SETTINGS.'::loadSettings(force: true)', $result['via']);
        $this->assertTrue($result['success']);
        $this->assertSame('', $result['message']);
    }//end testAForceParameterIsPassedByNameAsTrue()

    /**
     * @return array<string, array{0: mixed, 1: string}>
     */
    public static function failingOutcomeProvider(): array
    {
        return [
            'no array'        => ['done', 'The app returned no result.'],
            'success false'   => [['success' => false, 'message' => 'Schema invalid'], 'Schema invalid'],
            'error is shown'  => [['error' => 'Disk full'], 'Disk full'],
            'message wins'    => [['success' => true, 'error' => 'E', 'message' => 'M'], 'M'],
        ];
    }//end failingOutcomeProvider()

    #[DataProvider('failingOutcomeProvider')]
    public function testAnAppOutcomeThatSignalsFailureIsReportedAsFailed(mixed $outcome, string $message): void
    {
        $service = new class($outcome) {
            public function __construct(private readonly mixed $outcome)
            {
            }

            public function reloadConfiguration(): mixed
            {
                return $this->outcome;
            }
        };
        $this->appWithService($service);

        $result = $this->runner()->run('demo');

        $this->assertSame(['success' => false, 'path' => 'app', 'message' => $message], array_diff_key($result, ['via' => 1]));
    }//end testAnAppOutcomeThatSignalsFailureIsReportedAsFailed()

    public function testAThrowingAppEntryPointIsReportedAsFailed(): void
    {
        $service = new class {
            public function reloadConfiguration(): array
            {
                throw new RuntimeException('app import crashed');
            }
        };
        $this->appWithService($service);

        $result = $this->runner()->run('demo');

        $this->assertFalse($result['success']);
        $this->assertSame('app import crashed', $result['message']);
    }//end testAThrowingAppEntryPointIsReportedAsFailed()

    public function testOpenRegisterRefusesAnAppWithoutARegisterDocument(): void
    {
        $this->apps->method('getAppInfo')->willReturn([]);
        $this->openRegisterImports(null, ['ok' => true]);
        $this->importer->expects($this->never())->method('importFromApp');

        $result = $this->runner()->run('demo');

        $this->assertFalse($result['success']);
        $this->assertSame('demo ships no lib/Settings/demo_register.json.', $result['message']);
    }//end testOpenRegisterRefusesAnAppWithoutARegisterDocument()

    public function testOpenRegisterReportsAFailingOrEmptyImport(): void
    {
        $this->apps->method('getAppInfo')->willReturn([]);
        $this->openRegisterImports(['r' => 1], new RuntimeException('schema clash'));
        $this->assertSame(['success' => false, 'message' => 'schema clash'], array_intersect_key($this->runner()->run('demo'), ['success' => 1, 'message' => 1]));
    }//end testOpenRegisterReportsAFailingOrEmptyImport()

    public function testOpenRegisterReportsAnEmptyImportResultAsFailed(): void
    {
        $this->apps->method('getAppInfo')->willReturn([]);
        $this->openRegisterImports(['r' => 1], []);

        $this->assertSame(
            ['success' => false, 'message' => 'The import returned an empty result.'],
            array_intersect_key($this->runner()->run('demo'), ['success' => 1, 'message' => 1])
        );
    }//end testOpenRegisterReportsAnEmptyImportResultAsFailed()
}//end class
