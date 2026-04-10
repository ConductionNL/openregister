<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Command;

use OCA\OpenRegister\Command\SolrManagementCommand;
use OCA\OpenRegister\Service\IndexService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use ReflectionClass;

class SolrManagementCommandDeepTest extends TestCase
{
    private SolrManagementCommand $command;
    private LoggerInterface|MockObject $logger;
    private IndexService|MockObject $solrService;
    private InputInterface|MockObject $input;
    private OutputInterface|MockObject $output;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logger = $this->createMock(LoggerInterface::class);
        $this->solrService = $this->createMock(IndexService::class);
        $this->input = $this->createMock(InputInterface::class);
        $this->output = $this->createMock(OutputInterface::class);

        $this->command = new SolrManagementCommand(
            $this->logger,
            $this->solrService
        );
    }

    private function executeCommand(string $action, bool $force = false, bool $commit = false): int
    {
        $this->input->method('getArgument')->willReturn($action);
        $this->input->method('getOption')->willReturnMap([
            ['force', $force],
            ['commit', $commit],
            ['tenant-collection', null],
        ]);
        $this->output->method('writeln')->willReturn(null);
        $this->output->method('write')->willReturn(null);

        $ref = new ReflectionClass(SolrManagementCommand::class);
        $method = $ref->getMethod('execute');
        $method->setAccessible(true);

        return $method->invoke($this->command, $this->input, $this->output);
    }

    public function testExecuteWithSolrUnavailable(): void
    {
        $this->solrService->method('isAvailable')->willReturn(false);

        $exitCode = $this->executeCommand('setup');

        $this->assertEquals(1, $exitCode);
    }

    public function testHandleInvalidAction(): void
    {
        $this->solrService->method('isAvailable')->willReturn(true);

        $exitCode = $this->executeCommand('invalid-action');

        $this->assertEquals(1, $exitCode);
    }

    public function testHandleOptimizeSuccess(): void
    {
        $this->solrService->method('isAvailable')->willReturn(true);
        $this->solrService->method('optimize')->willReturn(true);

        $exitCode = $this->executeCommand('optimize');

        $this->assertEquals(0, $exitCode);
    }

    public function testHandleOptimizeWithCommit(): void
    {
        $this->solrService->method('isAvailable')->willReturn(true);
        $this->solrService->method('optimize')->willReturn(true);
        $this->solrService->method('commit')->willReturn(true);

        $exitCode = $this->executeCommand('optimize', false, true);

        $this->assertEquals(0, $exitCode);
    }

    public function testHandleOptimizeFailure(): void
    {
        $this->solrService->method('isAvailable')->willReturn(true);
        $this->solrService->method('optimize')->willReturn(false);

        $exitCode = $this->executeCommand('optimize');

        $this->assertEquals(1, $exitCode);
    }

    public function testHandleClearWithoutForce(): void
    {
        $this->solrService->method('isAvailable')->willReturn(true);

        $exitCode = $this->executeCommand('clear', false);

        $this->assertEquals(1, $exitCode);
    }

    public function testHandleClearWithForceSuccess(): void
    {
        $this->solrService->method('isAvailable')->willReturn(true);
        $this->solrService->method('clearIndex')->willReturn(['success' => true]);

        $exitCode = $this->executeCommand('clear', true);

        $this->assertEquals(0, $exitCode);
    }

    public function testHandleClearWithForceFailure(): void
    {
        $this->solrService->method('isAvailable')->willReturn(true);
        $this->solrService->method('clearIndex')->willReturn(['success' => false]);

        $exitCode = $this->executeCommand('clear', true);

        $this->assertEquals(1, $exitCode);
    }

    public function testHandleStatsUnavailable(): void
    {
        $this->solrService->method('isAvailable')->willReturn(true);
        $this->solrService->method('getDashboardStats')->willReturn([
            'available' => false,
            'error' => 'Connection failed',
        ]);

        $exitCode = $this->executeCommand('stats');

        $this->assertEquals(1, $exitCode);
    }
}
