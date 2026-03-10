<?php

declare(strict_types=1);

namespace Unit\BackgroundJob;

use OCA\OpenRegister\BackgroundJob\ObjectTextExtractionJob;
use OCA\OpenRegister\Service\TextExtractionService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ObjectTextExtractionJobTest extends TestCase
{
    private ObjectTextExtractionJob $job;
    private IAppConfig&MockObject $config;
    private LoggerInterface&MockObject $logger;
    private TextExtractionService&MockObject $textExtractor;

    protected function setUp(): void
    {
        parent::setUp();
        $timeFactory = $this->createMock(ITimeFactory::class);
        $this->config = $this->createMock(IAppConfig::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->textExtractor = $this->createMock(TextExtractionService::class);

        $this->job = new ObjectTextExtractionJob(
            $timeFactory,
            $this->config,
            $this->logger,
            $this->textExtractor,
        );
    }

    private function runJob(array $argument): void
    {
        $reflection = new \ReflectionClass($this->job);
        $method = $reflection->getMethod('run');
        $method->setAccessible(true);
        $method->invoke($this->job, $argument);
    }

    public function testExtractionDisabledReturnsEarly(): void
    {
        $this->config->method('getValueString')
            ->willReturn('{"objectExtractionMode":"none"}');

        $this->textExtractor->expects($this->never())->method('extractObject');

        $this->runJob(['object_id' => 42]);
    }

    public function testMissingObjectIdLogsError(): void
    {
        $this->config->method('getValueString')->willReturn('{}');

        $this->logger->expects($this->atLeastOnce())->method('error');
        $this->textExtractor->expects($this->never())->method('extractObject');

        $this->runJob([]);
    }

    public function testSuccessfulExtraction(): void
    {
        $this->config->method('getValueString')->willReturn('{}');

        $this->textExtractor->expects($this->once())
            ->method('extractObject')
            ->with(42, false);

        $this->runJob(['object_id' => 42]);
    }

    public function testExtractionExceptionLogsError(): void
    {
        $this->config->method('getValueString')->willReturn('{}');

        $this->textExtractor->method('extractObject')
            ->willThrowException(new \Exception('Extraction failed'));

        $this->logger->expects($this->atLeastOnce())->method('error');

        $this->runJob(['object_id' => 42]);
    }

    public function testDefaultExtractionModeIsBackground(): void
    {
        // When objectExtractionMode is not set, default is 'background' (not 'none')
        $this->config->method('getValueString')->willReturn('{}');

        $this->textExtractor->expects($this->once())->method('extractObject');

        $this->runJob(['object_id' => 42]);
    }
}
