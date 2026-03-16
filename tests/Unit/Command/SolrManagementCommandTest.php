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

    /** @var IndexService&MockObject */
    private IndexService $solrService;

    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logger     = $this->createMock(LoggerInterface::class);
        $this->solrService = $this->createMock(IndexService::class);
        $this->command    = new SolrManagementCommand($this->logger, $this->solrService);
    }

    /**
     * Run the command with given arguments and return [exit-code, output].
     */
    private function execute(array $args): array
    {
        $input  = new ArrayInput($args, $this->command->getDefinition());
        $output = new BufferedOutput();
        $code   = $this->command->run($input, $output);
        return [$code, $output->fetch()];
    }

    // =========================================================================
    // Configuration & metadata
    // =========================================================================

    public function testCommandName(): void
    {
        $this->assertSame('openregister:solr:manage', $this->command->getName());
    }

    public function testCommandHasActionArgument(): void
    {
        $definition = $this->command->getDefinition();
        $this->assertTrue($definition->hasArgument('action'));
        $this->assertTrue($definition->getArgument('action')->isRequired());
    }

    public function testCommandHasForceOption(): void
    {
        $this->assertTrue($this->command->getDefinition()->hasOption('force'));
    }

    public function testCommandHasCommitOption(): void
    {
        $this->assertTrue($this->command->getDefinition()->hasOption('commit'));
    }

    public function testCommandHasTenantCollectionOption(): void
    {
        $this->assertTrue($this->command->getDefinition()->hasOption('tenant-collection'));
    }

    // =========================================================================
    // SOLR availability gate
    // =========================================================================

    public function testSolrUnavailableReturnsFailure(): void
    {
        $this->solrService->method('isAvailable')->willReturn(false);

        [$code, $output] = $this->execute(['action' => 'setup']);

        $this->assertSame(Command::FAILURE, $code);
        $this->assertStringContainsString('not available', $output);
    }

    public function testSolrUnavailableOutputsSuggestion(): void
    {
        $this->solrService->method('isAvailable')->willReturn(false);

        [, $output] = $this->execute(['action' => 'health']);

        $this->assertStringContainsString('admin settings', $output);
    }

    // =========================================================================
    // Invalid action
    // =========================================================================

    public function testInvalidActionReturnsFailure(): void
    {
        $this->solrService->method('isAvailable')->willReturn(true);

        [$code] = $this->execute(['action' => 'nonexistent']);

        $this->assertSame(Command::FAILURE, $code);
    }

    public function testInvalidActionOutputsAvailableActions(): void
    {
        $this->solrService->method('isAvailable')->willReturn(true);

        [, $output] = $this->execute(['action' => 'fly-to-the-moon']);

        $this->assertStringContainsString('setup', $output);
        $this->assertStringContainsString('optimize', $output);
    }

    // =========================================================================
    // setup action
    // =========================================================================

    public function testSetupConnectionFailureReturnsFailure(): void
    {
        $this->solrService->method('isAvailable')->willReturn(true);
        $this->solrService->method('testConnection')->willReturn([
            'success' => false,
            'message' => 'Timeout',
        ]);

        [$code] = $this->execute(['action' => 'setup']);

        $this->assertSame(Command::FAILURE, $code);
    }

    public function testSetupConnectionFailureOutputsError(): void
    {
        $this->solrService->method('isAvailable')->willReturn(true);
        $this->solrService->method('testConnection')->willReturn([
            'success' => false,
            'message' => 'Timeout',
        ]);

        [, $output] = $this->execute(['action' => 'setup']);

        $this->assertStringContainsString('Timeout', $output);
    }

    public function testSetupExceptionReturnsFailure(): void
    {
        $this->solrService->method('isAvailable')->willReturn(true);
        $this->solrService->method('testConnection')
            ->willThrowException(new \Exception('Unexpected crash'));

        [$code] = $this->execute(['action' => 'setup']);

        $this->assertSame(Command::FAILURE, $code);
    }

    // =========================================================================
    // optimize action
    // =========================================================================

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

    public function testOptimizeWithCommitCallsCommit(): void
    {
        $this->solrService->method('isAvailable')->willReturn(true);
        $this->solrService->method('optimize')->willReturn(true);

        $this->solrService->expects($this->atLeastOnce())
            ->method('commit')
            ->willReturn(true);

        [$code] = $this->execute(['action' => 'optimize', '--commit' => true]);

        $this->assertSame(Command::SUCCESS, $code);
    }

    public function testOptimizeWithCommitFailedCommitStillSucceeds(): void
    {
        $this->solrService->method('isAvailable')->willReturn(true);
        $this->solrService->method('optimize')->willReturn(true);
        $this->solrService->method('commit')->willReturn(false);

        [$code, $output] = $this->execute(['action' => 'optimize', '--commit' => true]);

        // Optimize itself succeeded; command returns success even if commit warns.
        $this->assertSame(Command::SUCCESS, $code);
    }

    public function testOptimizeWithoutCommitDoesNotCallCommit(): void
    {
        $this->solrService->method('isAvailable')->willReturn(true);
        $this->solrService->method('optimize')->willReturn(true);

        $this->solrService->expects($this->never())->method('commit');

        $this->execute(['action' => 'optimize']);
    }

    public function testOptimizeExceptionReturnsFailure(): void
    {
        $this->solrService->method('isAvailable')->willReturn(true);
        $this->solrService->method('optimize')
            ->willThrowException(new \Exception('SOLR down'));

        [$code] = $this->execute(['action' => 'optimize']);

        $this->assertSame(Command::FAILURE, $code);
    }

    public function testOptimizeOutputsExecutionTime(): void
    {
        $this->solrService->method('isAvailable')->willReturn(true);
        $this->solrService->method('optimize')->willReturn(true);

        [, $output] = $this->execute(['action' => 'optimize']);

        $this->assertStringContainsString('ms', $output);
    }

    // =========================================================================
    // warm action
    // =========================================================================

    public function testWarmAllQueriesSucceedReturnsSuccess(): void
    {
        $this->solrService->method('isAvailable')->willReturn(true);
        $this->solrService->method('searchObjects')
            ->willReturn(['success' => true, 'total' => 10, 'execution_time_ms' => 5]);

        [$code] = $this->execute(['action' => 'warm']);

        $this->assertSame(Command::SUCCESS, $code);
    }

    public function testWarmOneQueryFailsReturnsFailure(): void
    {
        $this->solrService->method('isAvailable')->willReturn(true);

        $callCount = 0;
        $this->solrService->method('searchObjects')
            ->willReturnCallback(static function () use (&$callCount): array {
                $callCount++;
                return ['success' => $callCount !== 2];
            });

        [$code] = $this->execute(['action' => 'warm']);

        $this->assertSame(Command::FAILURE, $code);
    }

    public function testWarmExceptionReturnsFailure(): void
    {
        $this->solrService->method('isAvailable')->willReturn(true);
        $this->solrService->method('searchObjects')
            ->willThrowException(new \Exception('Search error'));

        [$code] = $this->execute(['action' => 'warm']);

        $this->assertSame(Command::FAILURE, $code);
    }

    public function testWarmOutputsQueryDescriptions(): void
    {
        $this->solrService->method('isAvailable')->willReturn(true);
        $this->solrService->method('searchObjects')
            ->willReturn(['success' => true]);

        [, $output] = $this->execute(['action' => 'warm']);

        $this->assertStringContainsString('All documents sample', $output);
    }

    // =========================================================================
    // health action
    // =========================================================================

    public function testHealthCheckSuccess(): void
    {
        $this->solrService->method('isAvailable')->willReturn(true);
        $this->solrService->method('testConnection')->willReturn([
            'success' => true,
            'details' => [
                'response_time_ms' => 12,
                'solr_version'     => '9.4.0',
                'mode'             => 'cloud',
            ],
        ]);
        $this->solrService->method('getDocumentCount')->willReturn(42);
        $this->solrService->method('searchObjects')->willReturn([
            'success'          => true,
            'execution_time_ms' => 5,
            'total'            => 42,
        ]);
        $this->solrService->method('getStats')->willReturn([
            'searches' => 100,
            'indexes'  => 50,
            'deletes'  => 5,
            'errors'   => 0,
        ]);

        [$code] = $this->execute(['action' => 'health']);

        $this->assertSame(Command::SUCCESS, $code);
    }

    public function testHealthCheckWithConnectionFailureReturnsFailure(): void
    {
        $this->solrService->method('isAvailable')->willReturn(true);
        $this->solrService->method('testConnection')->willReturn([
            'success' => false,
            'message' => 'Connection refused',
        ]);
        $this->solrService->method('ensureTenantCollection')->willReturn([]);
        $this->solrService->method('getDocumentCount')->willReturn(0);
        $this->solrService->method('searchObjects')->willReturn([
            'success' => true,
            'execution_time_ms' => 2,
            'total' => 0,
        ]);
        $this->solrService->method('getStats')->willReturn([
            'searches' => 0,
            'indexes'  => 0,
            'deletes'  => 0,
            'errors'   => 0,
        ]);

        [$code] = $this->execute(['action' => 'health']);

        $this->assertSame(Command::FAILURE, $code);
    }

    public function testHealthCheckWithTenantCollectionFailureReturnsFailure(): void
    {
        $this->solrService->method('isAvailable')->willReturn(true);
        $this->solrService->method('testConnection')->willReturn([
            'success' => true,
            'details' => [
                'response_time_ms' => 10,
                'solr_version'     => '9.0',
                'mode'             => 'standalone',
            ],
        ]);
        $this->solrService->method('ensureTenantCollection')
            ->willThrowException(new \Exception('Collection missing'));
        $this->solrService->method('searchObjects')->willReturn([
            'success'           => true,
            'execution_time_ms' => 3,
            'total'             => 0,
        ]);
        $this->solrService->method('getStats')->willReturn([
            'searches' => 0,
            'indexes'  => 0,
            'deletes'  => 0,
            'errors'   => 0,
        ]);

        [$code] = $this->execute(['action' => 'health']);

        $this->assertSame(Command::FAILURE, $code);
    }

    public function testHealthCheckWithSearchFailureReturnsFailure(): void
    {
        $this->solrService->method('isAvailable')->willReturn(true);
        $this->solrService->method('testConnection')->willReturn([
            'success' => true,
            'details' => [
                'response_time_ms' => 5,
                'solr_version'     => '9.4',
                'mode'             => 'cloud',
            ],
        ]);
        $this->solrService->method('getDocumentCount')->willReturn(0);
        $this->solrService->method('searchObjects')->willReturn([
            'success' => false,
            'error'   => 'Query parse error',
        ]);
        $this->solrService->method('getStats')->willReturn([
            'searches' => 0,
            'indexes'  => 0,
            'deletes'  => 0,
            'errors'   => 1,
        ]);

        [$code] = $this->execute(['action' => 'health']);

        $this->assertSame(Command::FAILURE, $code);
    }

    public function testHealthCheckExceptionReturnsFailure(): void
    {
        $this->solrService->method('isAvailable')->willReturn(true);
        $this->solrService->method('testConnection')
            ->willThrowException(new \Exception('Network error'));

        [$code] = $this->execute(['action' => 'health']);

        $this->assertSame(Command::FAILURE, $code);
    }

    public function testHealthCheckOutputsStats(): void
    {
        $this->solrService->method('isAvailable')->willReturn(true);
        $this->solrService->method('testConnection')->willReturn([
            'success' => true,
            'details' => [
                'response_time_ms' => 8,
                'solr_version'     => '9.4.0',
                'mode'             => 'cloud',
            ],
        ]);
        $this->solrService->method('getDocumentCount')->willReturn(99);
        $this->solrService->method('searchObjects')->willReturn([
            'success'           => true,
            'execution_time_ms' => 3,
            'total'             => 99,
        ]);
        $this->solrService->method('getStats')->willReturn([
            'searches' => 200,
            'indexes'  => 100,
            'deletes'  => 10,
            'errors'   => 2,
        ]);

        [, $output] = $this->execute(['action' => 'health']);

        $this->assertStringContainsString('200', $output);
        $this->assertStringContainsString('100', $output);
    }

    // =========================================================================
    // schema-check action
    // =========================================================================

    public function testSchemaCheckWithDocumentsReturnsSuccess(): void
    {
        $this->solrService->method('isAvailable')->willReturn(true);
        $this->solrService->method('searchObjects')->willReturn([
            'success' => true,
            'data'    => [
                ['id' => '1', 'uuid' => 'abc', 'slug' => 'test', 'name' => 'Test'],
            ],
        ]);

        [$code] = $this->execute(['action' => 'schema-check']);

        $this->assertSame(Command::SUCCESS, $code);
    }

    public function testSchemaCheckWithNoDocumentsReturnsSuccess(): void
    {
        $this->solrService->method('isAvailable')->willReturn(true);
        $this->solrService->method('searchObjects')->willReturn([
            'success' => true,
            'data'    => [],
        ]);

        [$code] = $this->execute(['action' => 'schema-check']);

        $this->assertSame(Command::SUCCESS, $code);
    }

    public function testSchemaCheckOutputsMissingFieldsWarning(): void
    {
        $this->solrService->method('isAvailable')->willReturn(true);
        // Document lacks 'uuid', 'slug', etc.
        $this->solrService->method('searchObjects')->willReturn([
            'success' => true,
            'data'    => [['id' => '1', 'name' => 'Test']],
        ]);

        [, $output] = $this->execute(['action' => 'schema-check']);

        $this->assertStringContainsString('Missing fields', $output);
    }

    public function testSchemaCheckWithSearchFailurePrintsWarning(): void
    {
        $this->solrService->method('isAvailable')->willReturn(true);
        $this->solrService->method('searchObjects')->willReturn([
            'success' => false,
        ]);

        [, $output] = $this->execute(['action' => 'schema-check']);

        $this->assertStringContainsString('No documents available', $output);
    }

    public function testSchemaCheckExceptionReturnsFailure(): void
    {
        $this->solrService->method('isAvailable')->willReturn(true);
        $this->solrService->method('searchObjects')
            ->willThrowException(new \Exception('SOLR unavailable'));

        [$code] = $this->execute(['action' => 'schema-check']);

        $this->assertSame(Command::FAILURE, $code);
    }

    // =========================================================================
    // clear action
    // =========================================================================

    public function testClearWithoutForceReturnsFailure(): void
    {
        $this->solrService->method('isAvailable')->willReturn(true);

        [$code] = $this->execute(['action' => 'clear']);

        $this->assertSame(Command::FAILURE, $code);
    }

    public function testClearWithoutForceOutputsSafetyMessage(): void
    {
        $this->solrService->method('isAvailable')->willReturn(true);

        [, $output] = $this->execute(['action' => 'clear']);

        $this->assertStringContainsString('--force', $output);
    }

    public function testClearWithForceCallsServiceAndSucceeds(): void
    {
        $this->solrService->method('isAvailable')->willReturn(true);
        $this->solrService->expects($this->once())
            ->method('clearIndex')
            ->willReturn(['success' => true]);

        [$code] = $this->execute(['action' => 'clear', '--force' => true]);

        $this->assertSame(Command::SUCCESS, $code);
    }

    public function testClearWithForceServiceReturnsFalseGivesFailure(): void
    {
        $this->solrService->method('isAvailable')->willReturn(true);
        $this->solrService->method('clearIndex')
            ->willReturn(['success' => false]);

        [$code] = $this->execute(['action' => 'clear', '--force' => true]);

        $this->assertSame(Command::FAILURE, $code);
    }

    public function testClearWithForceServiceReturnsNoSuccessKeyGivesFailure(): void
    {
        $this->solrService->method('isAvailable')->willReturn(true);
        $this->solrService->method('clearIndex')
            ->willReturn([]);

        [$code] = $this->execute(['action' => 'clear', '--force' => true]);

        $this->assertSame(Command::FAILURE, $code);
    }

    public function testClearExceptionReturnsFailure(): void
    {
        $this->solrService->method('isAvailable')->willReturn(true);
        $this->solrService->method('clearIndex')
            ->willThrowException(new \Exception('Delete failed'));

        [$code] = $this->execute(['action' => 'clear', '--force' => true]);

        $this->assertSame(Command::FAILURE, $code);
    }

    // =========================================================================
    // stats action
    // =========================================================================

    public function testStatsAvailableReturnsSuccess(): void
    {
        $this->solrService->method('isAvailable')->willReturn(true);
        $this->solrService->method('getDashboardStats')->willReturn([
            'available' => true,
            'backend'   => [
                'searches' => 100,
                'indexes'  => 50,
                'deletes'  => 5,
                'errors'   => 0,
            ],
        ]);

        [$code] = $this->execute(['action' => 'stats']);

        $this->assertSame(Command::SUCCESS, $code);
    }

    public function testStatsUnavailableReturnsFailure(): void
    {
        $this->solrService->method('isAvailable')->willReturn(true);
        $this->solrService->method('getDashboardStats')->willReturn([
            'available' => false,
            'error'     => 'Backend not configured',
        ]);

        [$code] = $this->execute(['action' => 'stats']);

        $this->assertSame(Command::FAILURE, $code);
    }

    public function testStatsOutputsBackendNumbers(): void
    {
        $this->solrService->method('isAvailable')->willReturn(true);
        $this->solrService->method('getDashboardStats')->willReturn([
            'available' => true,
            'backend'   => [
                'searches'    => 999,
                'indexes'     => 888,
                'deletes'     => 77,
                'errors'      => 6,
                'search_time' => 1.234,
                'index_time'  => 0.567,
            ],
        ]);

        [, $output] = $this->execute(['action' => 'stats']);

        $this->assertStringContainsString('999', $output);
        $this->assertStringContainsString('888', $output);
    }

    public function testStatsWithFileSectionOutputsFileStats(): void
    {
        $this->solrService->method('isAvailable')->willReturn(true);
        $this->solrService->method('getDashboardStats')->willReturn([
            'available' => true,
            'files'     => ['total' => 50, 'indexed' => 48],
        ]);

        [, $output] = $this->execute(['action' => 'stats']);

        $this->assertStringContainsString('File Statistics', $output);
    }

    public function testStatsWithChunkSectionOutputsChunkStats(): void
    {
        $this->solrService->method('isAvailable')->willReturn(true);
        $this->solrService->method('getDashboardStats')->willReturn([
            'available' => true,
            'chunks'    => ['total' => 200, 'indexed' => 195],
        ]);

        [, $output] = $this->execute(['action' => 'stats']);

        $this->assertStringContainsString('Chunk Statistics', $output);
    }

    public function testStatsExceptionReturnsFailure(): void
    {
        $this->solrService->method('isAvailable')->willReturn(true);
        $this->solrService->method('getDashboardStats')
            ->willThrowException(new \Exception('Stats unreachable'));

        [$code] = $this->execute(['action' => 'stats']);

        $this->assertSame(Command::FAILURE, $code);
    }

    public function testStatsWithSearchTimePrintsMilliseconds(): void
    {
        $this->solrService->method('isAvailable')->willReturn(true);
        $this->solrService->method('getDashboardStats')->willReturn([
            'available' => true,
            'backend'   => [
                'searches'    => 10,
                'indexes'     => 5,
                'deletes'     => 0,
                'errors'      => 0,
                'search_time' => 2.5,
            ],
        ]);

        [, $output] = $this->execute(['action' => 'stats']);

        // 2.5 seconds * 1000 = 2500ms
        $this->assertStringContainsString('2500', $output);
    }
}
