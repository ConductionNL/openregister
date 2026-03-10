<?php

declare(strict_types=1);

namespace Unit\Command;

use OCA\OpenRegister\Command\SolrManagementCommand;
use OCA\OpenRegister\Service\IndexService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class SolrManagementCommandTest extends TestCase
{
    private SolrManagementCommand $command;
    private IndexService&MockObject $solrService;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->solrService = $this->createMock(IndexService::class);
        $this->command = new SolrManagementCommand($this->logger, $this->solrService);
    }

    private function execute(array $args): array
    {
        $input = new ArrayInput($args, $this->command->getDefinition());
        $output = new BufferedOutput();
        $code = $this->command->run($input, $output);
        return [$code, $output->fetch()];
    }

    public function testCommandName(): void
    {
        $this->assertSame('openregister:solr:manage', $this->command->getName());
    }

    public function testSolrUnavailableReturnsFailure(): void
    {
        $this->solrService->method('isAvailable')->willReturn(false);

        [$code] = $this->execute(['action' => 'setup']);
        $this->assertSame(Command::FAILURE, $code);
    }

    public function testInvalidActionReturnsFailure(): void
    {
        $this->solrService->method('isAvailable')->willReturn(true);

        [$code] = $this->execute(['action' => 'nonexistent']);
        $this->assertSame(Command::FAILURE, $code);
    }

    public function testClearWithoutForceReturnsFailure(): void
    {
        $this->solrService->method('isAvailable')->willReturn(true);

        [$code] = $this->execute(['action' => 'clear']);
        $this->assertSame(Command::FAILURE, $code);
    }

    public function testClearWithForceCallsService(): void
    {
        $this->solrService->method('isAvailable')->willReturn(true);
        $this->solrService->expects($this->once())
            ->method('clearIndex')
            ->willReturn(['success' => true]);

        [$code] = $this->execute(['action' => 'clear', '--force' => true]);
        $this->assertSame(Command::SUCCESS, $code);
    }

    public function testStatsReturnsSuccess(): void
    {
        $this->solrService->method('isAvailable')->willReturn(true);
        $this->solrService->method('getDashboardStats')
            ->willReturn([
                'available' => true,
                'backend' => [
                    'searches' => 100,
                    'indexes' => 50,
                    'deletes' => 5,
                    'errors' => 0,
                ],
            ]);

        [$code] = $this->execute(['action' => 'stats']);
        $this->assertSame(Command::SUCCESS, $code);
    }

    public function testStatsUnavailableReturnsFailure(): void
    {
        $this->solrService->method('isAvailable')->willReturn(true);
        $this->solrService->method('getDashboardStats')
            ->willReturn([
                'available' => false,
                'error' => 'Backend not configured',
            ]);

        [$code] = $this->execute(['action' => 'stats']);
        $this->assertSame(Command::FAILURE, $code);
    }

    public function testHealthCheckSuccess(): void
    {
        $this->solrService->method('isAvailable')->willReturn(true);
        $this->solrService->method('testConnection')->willReturn([
            'success' => true,
            'details' => [
                'response_time_ms' => 12,
                'solr_version' => '9.4.0',
                'mode' => 'cloud',
            ],
        ]);
        $this->solrService->method('getDocumentCount')->willReturn(42);
        $this->solrService->method('searchObjects')->willReturn([
            'success' => true,
            'execution_time_ms' => 5,
            'total' => 42,
        ]);
        $this->solrService->method('getStats')->willReturn([
            'searches' => 100,
            'indexes' => 50,
            'deletes' => 5,
            'errors' => 0,
        ]);

        [$code] = $this->execute(['action' => 'health']);
        $this->assertSame(Command::SUCCESS, $code);
    }

    public function testOptimizeSuccess(): void
    {
        $this->solrService->method('isAvailable')->willReturn(true);
        $this->solrService->expects($this->once())
            ->method('optimize')
            ->willReturn(true);

        [$code] = $this->execute(['action' => 'optimize']);
        $this->assertSame(Command::SUCCESS, $code);
    }

    public function testOptimizeFailureReturnsFailure(): void
    {
        $this->solrService->method('isAvailable')->willReturn(true);
        $this->solrService->method('optimize')->willReturn(false);

        [$code] = $this->execute(['action' => 'optimize']);
        $this->assertSame(Command::FAILURE, $code);
    }
}
