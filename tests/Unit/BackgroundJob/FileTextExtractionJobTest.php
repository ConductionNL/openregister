<?php

declare(strict_types=1);

/**
 * FileTextExtractionJob Unit Test
 *
 * Tests the background job that extracts text from uploaded files asynchronously.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\BackgroundJob
 * @author   OpenRegister Team
 * @license  AGPL-3.0-or-later
 */

namespace OCA\OpenRegister\Tests\Unit\BackgroundJob;

use OCA\OpenRegister\BackgroundJob\FileTextExtractionJob;
use OCA\OpenRegister\Service\TextExtractionService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use ReflectionClass;

/**
 * Test class for FileTextExtractionJob
 *
 * @package OCA\OpenRegister\Tests\Unit\BackgroundJob
 */
class FileTextExtractionJobTest extends TestCase
{
    /**
     * @var TextExtractionService|MockObject
     */
    private $textExtractor;

    /**
     * @var LoggerInterface|MockObject
     */
    private $logger;

    /**
     * @var ITimeFactory|MockObject
     */
    private $timeFactory;

    /**
     * @var IAppConfig|MockObject
     */
    private $config;

    /**
     * @var FileTextExtractionJob
     */
    private $job;

    /**
     * Set up test dependencies
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->textExtractor = $this->createMock(TextExtractionService::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->timeFactory = $this->createMock(ITimeFactory::class);
        $this->config = $this->createMock(IAppConfig::class);

        $this->job = new FileTextExtractionJob(
            $this->timeFactory,
            $this->config,
            $this->logger,
            $this->textExtractor
        );
    }

    /**
     * Helper to invoke the protected run() method via reflection.
     *
     * @param mixed $argument The argument to pass to run()
     *
     * @return void
     */
    private function invokeRun(mixed $argument): void
    {
        $reflection = new ReflectionClass($this->job);
        $method = $reflection->getMethod('run');
        $method->setAccessible(true);
        $method->invoke($this->job, $argument);
    }

    /**
     * Helper to configure the config mock to enable file extraction.
     *
     * @return void
     */
    private function enableFileExtraction(): void
    {
        $this->config
            ->method('hasKey')
            ->with('openregister', 'fileManagement')
            ->willReturn(true);

        $this->config
            ->method('getValueString')
            ->with('openregister', 'fileManagement')
            ->willReturn(json_encode(['extractionScope' => 'all']));
    }

    /**
     * Test successful text extraction
     *
     * @return void
     */
    public function testSuccessfulTextExtraction(): void
    {
        $fileId = 123;
        $argument = ['file_id' => $fileId];

        $this->enableFileExtraction();

        // Mock successful extraction.
        $this->textExtractor
            ->expects($this->once())
            ->method('extractFile')
            ->with(fileId: $fileId, forceReExtract: false);

        // Expect success logging.
        $this->logger
            ->expects($this->atLeastOnce())
            ->method('info');

        // Run the job via reflection.
        $this->invokeRun($argument);
    }

    /**
     * Test extraction disabled in config
     *
     * @return void
     */
    public function testExtractionDisabledInConfig(): void
    {
        $argument = ['file_id' => 456];

        // Config says extraction is disabled.
        $this->config
            ->method('hasKey')
            ->with('openregister', 'fileManagement')
            ->willReturn(true);

        $this->config
            ->method('getValueString')
            ->with('openregister', 'fileManagement')
            ->willReturn(json_encode(['extractionScope' => 'none']));

        // Should NOT call extractFile.
        $this->textExtractor
            ->expects($this->never())
            ->method('extractFile');

        // Expect info logging that extraction is disabled.
        $this->logger
            ->expects($this->atLeastOnce())
            ->method('info');

        // Run the job via reflection.
        $this->invokeRun($argument);
    }

    /**
     * Test extraction when config key does not exist
     *
     * @return void
     */
    public function testExtractionWhenConfigKeyMissing(): void
    {
        $argument = ['file_id' => 456];

        // Config key does not exist.
        $this->config
            ->method('hasKey')
            ->with('openregister', 'fileManagement')
            ->willReturn(false);

        $this->config
            ->method('getValueString')
            ->willReturn('');

        // Should NOT call extractFile.
        $this->textExtractor
            ->expects($this->never())
            ->method('extractFile');

        // Expect info logging.
        $this->logger
            ->expects($this->atLeastOnce())
            ->method('info');

        // Run the job via reflection.
        $this->invokeRun($argument);
    }

    /**
     * Test exception handling
     *
     * @return void
     */
    public function testExceptionHandling(): void
    {
        $fileId = 999;
        $argument = ['file_id' => $fileId];
        $exceptionMessage = 'Database connection failed';

        $this->enableFileExtraction();

        // Mock exception during extraction.
        $this->textExtractor
            ->expects($this->once())
            ->method('extractFile')
            ->with(fileId: $fileId, forceReExtract: false)
            ->willThrowException(new \Exception($exceptionMessage));

        // Expect error logging.
        $this->logger
            ->expects($this->atLeastOnce())
            ->method('error');

        // Run the job (should not throw exception).
        $this->invokeRun($argument);
    }

    /**
     * Test missing file_id in arguments
     *
     * @return void
     */
    public function testMissingFileIdArgument(): void
    {
        $argument = []; // Missing file_id

        $this->enableFileExtraction();

        // Should NOT call extractFile.
        $this->textExtractor
            ->expects($this->never())
            ->method('extractFile');

        // Expect error logging.
        $this->logger
            ->expects($this->atLeastOnce())
            ->method('error');

        // Run the job.
        $this->invokeRun($argument);
    }
}
