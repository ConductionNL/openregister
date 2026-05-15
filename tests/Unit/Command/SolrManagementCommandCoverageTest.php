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

/**
 * Coverage tests for SolrManagementCommand — targets uncovered branches.
 */
class SolrManagementCommandCoverageTest extends TestCase
{
    private SolrManagementCommand $command;

    /** @var IndexService&MockObject */
    private IndexService $solrService;

    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->solrService = $this->createMock(IndexService::class);
        $this->command = new SolrManagementCommand($this->logger, $this->solrService);
    }

    /**
     * Run the command with given arguments and return [exit-code, output].
     */
    private function execute(array $args): array
    {
        $input = new ArrayInput($args, $this->command->getDefinition());
        $output = new BufferedOutput();
        $code = $this->command->run($input, $output);
        return [$code, $output->fetch()];
    }

    // =========================================================================
    // handleSetup — connection failure with details
    // =========================================================================

    public function testSetupConnectionFailsWithDetails(): void
    {
        $this->solrService->method('isAvailable')->willReturn(true);
        $this->solrService->method('testConnection')->willReturn([
            'success' => false,
            'message' => 'Connection refused',
        ]);

        [$code, $out] = $this->execute(['action' => 'setup']);

        $this->assertEquals(Command::FAILURE, $code);
        $this->assertStringContainsString('Connection refused', $out);
    }

    public function testSetupExceptionPath(): void
    {
        $this->solrService->method('isAvailable')->willReturn(true);
        $this->solrService->method('testConnection')
            ->willThrowException(new \Exception('Fatal SOLR error'));

        [$code, $out] = $this->execute(['action' => 'setup']);

        $this->assertEquals(Command::FAILURE, $code);
        $this->assertStringContainsString('Fatal SOLR error', $out);
    }

    // =========================================================================
    // handleOptimize — commit success and failure
    // =========================================================================

    public function testOptimizeWithCommitSuccess(): void
    {
        $this->solrService->method('isAvailable')->willReturn(true);
        $this->solrService->method('optimize')->willReturn(true);
        $this->solrService->method('commit')->willReturn(true);

        [$code, $out] = $this->execute(['action' => 'optimize', '--commit' => true]);

        $this->assertEquals(Command::SUCCESS, $code);
        $this->assertStringContainsString('optimization completed', $out);
        $this->assertStringContainsString('committed successfully', $out);
    }

    public function testOptimizeWithCommitFailure(): void
    {
        $this->solrService->method('isAvailable')->willReturn(true);
        $this->solrService->method('optimize')->willReturn(true);
        $this->solrService->method('commit')->willReturn(false);

        [$code, $out] = $this->execute(['action' => 'optimize', '--commit' => true]);

        $this->assertEquals(Command::SUCCESS, $code);
        $this->assertStringContainsString('Commit failed', $out);
    }

    public function testOptimizeExceptionPath(): void
    {
        $this->solrService->method('isAvailable')->willReturn(true);
        $this->solrService->method('optimize')
            ->willThrowException(new \Exception('SOLR timeout'));

        [$code, $out] = $this->execute(['action' => 'optimize']);

        $this->assertEquals(Command::FAILURE, $code);
        $this->assertStringContainsString('SOLR timeout', $out);
    }

    public function testOptimizeFailureReturn(): void
    {
        $this->solrService->method('isAvailable')->willReturn(true);
        $this->solrService->method('optimize')->willReturn(false);

        [$code, $out] = $this->execute(['action' => 'optimize']);

        $this->assertEquals(Command::FAILURE, $code);
        $this->assertStringContainsString('optimization failed', $out);
    }

    // =========================================================================
    // handleWarm — partial failures
    // =========================================================================

    public function testWarmPartialFailure(): void
    {
        $this->solrService->method('isAvailable')->willReturn(true);

        $callCount = 0;
        $this->solrService->method('searchObjects')
            ->willReturnCallback(function () use (&$callCount) {
                $callCount++;
                if ($callCount === 2) {
                    return ['success' => false];
                }
                return ['success' => true];
            });

        [$code, $out] = $this->execute(['action' => 'warm']);

        $this->assertEquals(Command::FAILURE, $code);
        $this->assertStringContainsString('1/2', $out);
    }

    public function testWarmExceptionPath(): void
    {
        $this->solrService->method('isAvailable')->willReturn(true);
        $this->solrService->method('searchObjects')
            ->willThrowException(new \Exception('Connection lost'));

        [$code, $out] = $this->execute(['action' => 'warm']);

        $this->assertEquals(Command::FAILURE, $code);
        $this->assertStringContainsString('Connection lost', $out);
    }

    // =========================================================================
    // handleHealth — all pass
    // =========================================================================

    public function testHealthAllPass(): void
    {
        $this->solrService->method('isAvailable')->willReturn(true);
        $this->solrService->method('testConnection')->willReturn([
            'success' => true,
            'details' => [
                'solr_version' => '9.4.1',
                'mode' => 'cloud',
                'response_time_ms' => 10,
            ],
        ]);
        $this->solrService->method('getDocumentCount')->willReturn(100);
        $this->solrService->method('searchObjects')->willReturn([
            'success' => true,
            'execution_time_ms' => 5,
            'total' => 100,
        ]);
        $this->solrService->method('getStats')->willReturn([
            'searches' => 50,
            'indexes' => 10,
            'deletes' => 2,
            'errors' => 0,
        ]);

        [$code, $out] = $this->execute(['action' => 'health']);

        $this->assertEquals(Command::SUCCESS, $code);
        $this->assertStringContainsString('All health checks passed', $out);
    }

    public function testHealthExceptionPath(): void
    {
        $this->solrService->method('isAvailable')->willReturn(true);
        $this->solrService->method('testConnection')
            ->willThrowException(new \Exception('Network error'));

        [$code, $out] = $this->execute(['action' => 'health']);

        $this->assertEquals(Command::FAILURE, $code);
        $this->assertStringContainsString('Network error', $out);
    }

    // =========================================================================
    // handleStats — with timing metrics
    // =========================================================================

    public function testStatsWithTimingMetrics(): void
    {
        $this->solrService->method('isAvailable')->willReturn(true);
        $this->solrService->method('getDashboardStats')->willReturn([
            'available' => true,
            'backend' => [
                'searches' => 100,
                'indexes' => 50,
                'deletes' => 5,
                'errors' => 1,
                'search_time' => 0.5,
                'index_time' => 1.2,
            ],
        ]);

        [$code, $out] = $this->execute(['action' => 'stats']);

        $this->assertEquals(Command::SUCCESS, $code);
        $this->assertStringContainsString('500ms', $out);
        $this->assertStringContainsString('1200ms', $out);
    }

    public function testStatsWithFileAndChunkSections(): void
    {
        $this->solrService->method('isAvailable')->willReturn(true);
        $this->solrService->method('getDashboardStats')->willReturn([
            'available' => true,
            'backend' => [
                'searches' => 10,
                'indexes' => 5,
                'deletes' => 0,
                'errors' => 0,
            ],
            'files' => [
                'total' => 200,
                'indexed' => 150,
            ],
            'chunks' => [
                'total' => 1000,
                'pending' => 50,
            ],
        ]);

        [$code, $out] = $this->execute(['action' => 'stats']);

        $this->assertEquals(Command::SUCCESS, $code);
        $this->assertStringContainsString('File Statistics', $out);
        $this->assertStringContainsString('Chunk Statistics', $out);
    }

    // =========================================================================
    // handleClear — success with null success key
    // =========================================================================

    public function testClearSuccessWithNullSuccessKey(): void
    {
        $this->solrService->method('isAvailable')->willReturn(true);
        $this->solrService->method('clearIndex')->willReturn([]);

        [$code, $out] = $this->execute(['action' => 'clear', '--force' => true]);

        $this->assertEquals(Command::FAILURE, $code);
    }

    // =========================================================================
    // handleSchemaCheck — with extra fields
    // =========================================================================

    public function testSchemaCheckWithExtraFields(): void
    {
        $this->solrService->method('isAvailable')->willReturn(true);
        $this->solrService->method('searchObjects')->willReturn([
            'success' => true,
            'data' => [[
                'id' => '1',
                'uuid' => 'abc',
                'slug' => 'test',
                'name' => 'Test',
                'description' => 'A test',
                'summary' => 'Sum',
                'image' => '',
                'uri' => '',
                'version' => '1.0',
                'register_id' => 1,
                'schema_id' => 1,
                'organisation_id' => 1,
                'created' => '2024-01-01',
                'updated' => '2024-01-01',
                'published' => null,
                'depublished' => null,
                'tenant_id' => 'default',
                '_text_' => 'test',
                'custom_field_1' => 'extra',
                'custom_field_2' => 'extra2',
            ]],
        ]);

        [$code, $out] = $this->execute(['action' => 'schema-check']);

        $this->assertEquals(Command::SUCCESS, $code);
        $this->assertStringContainsString('All expected fields are available', $out);
        $this->assertStringContainsString('Additional fields', $out);
    }

    public function testSchemaCheckExceptionPath(): void
    {
        $this->solrService->method('isAvailable')->willReturn(true);
        $this->solrService->method('searchObjects')
            ->willThrowException(new \Exception('Search failed'));

        [$code, $out] = $this->execute(['action' => 'schema-check']);

        $this->assertEquals(Command::FAILURE, $code);
        $this->assertStringContainsString('Search failed', $out);
    }
}
