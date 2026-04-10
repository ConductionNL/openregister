<?php

declare(strict_types=1);

namespace Unit\BackgroundJob;

use Exception;
use OCA\OpenRegister\BackgroundJob\HookRetryJob;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Webhook\CloudEventFormatter;
use OCA\OpenRegister\Service\WorkflowEngineRegistry;
use OCA\OpenRegister\WorkflowEngine\WorkflowResult;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class HookRetryJobTest extends TestCase
{
    private HookRetryJob $job;
    private ITimeFactory&MockObject $timeFactory;
    private MagicMapper&MockObject $objectEntityMapper;
    private SchemaMapper&MockObject $schemaMapper;
    private WorkflowEngineRegistry&MockObject $engineRegistry;
    private CloudEventFormatter&MockObject $cloudEventFormatter;
    private IJobList&MockObject $jobList;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->timeFactory = $this->createMock(ITimeFactory::class);
        $this->objectEntityMapper = $this->createMock(MagicMapper::class);
        $this->schemaMapper = $this->createMock(SchemaMapper::class);
        $this->engineRegistry = $this->createMock(WorkflowEngineRegistry::class);
        $this->cloudEventFormatter = $this->createMock(CloudEventFormatter::class);
        $this->jobList = $this->createMock(IJobList::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->job = new HookRetryJob(
            $this->timeFactory,
            $this->objectEntityMapper,
            $this->schemaMapper,
            $this->engineRegistry,
            $this->cloudEventFormatter,
            $this->jobList,
            $this->logger,
        );
    }

    public function testRunWithMissingArgumentsLogsError(): void
    {
        $this->logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('Missing required arguments'));

        $this->objectEntityMapper->expects($this->never())
            ->method('find');

        $method = new \ReflectionMethod($this->job, 'run');
        $method->setAccessible(true);
        $method->invoke($this->job, []);
    }

    public function testRunWithMissingObjectIdLogsError(): void
    {
        $this->logger->expects($this->atLeastOnce())
            ->method('error')
            ->with($this->stringContains('Missing required arguments'));

        $method = new \ReflectionMethod($this->job, 'run');
        $method->setAccessible(true);
        $method->invoke($this->job, ['schemaId' => 1, 'hook' => ['id' => 'test']]);
    }

    public function testRunWithObjectLoadFailureLogsError(): void
    {
        $this->objectEntityMapper->expects($this->once())
            ->method('find')
            ->willThrowException(new Exception('Object not found'));

        $this->logger->expects($this->atLeastOnce())
            ->method('error')
            ->with($this->stringContains('Could not load object or schema'));

        $method = new \ReflectionMethod($this->job, 'run');
        $method->setAccessible(true);
        $method->invoke($this->job, [
            'objectId' => 'test-uuid',
            'schemaId' => 1,
            'hook' => ['id' => 'hook-1', 'engine' => 'n8n', 'workflowId' => 'wf-1'],
        ]);
    }

    public function testRunMaxRetriesReachedLogsErrorAndStops(): void
    {
        $object = new ObjectEntity();
        $schema = new Schema();

        $this->objectEntityMapper->method('find')->willReturn($object);
        $this->schemaMapper->method('find')->willReturn($schema);
        $this->engineRegistry->method('getEnginesByType')
            ->willThrowException(new Exception('Engine unavailable'));

        $this->jobList->expects($this->never())
            ->method('add');

        $method = new \ReflectionMethod($this->job, 'run');
        $method->setAccessible(true);
        $method->invoke($this->job, [
            'objectId' => 'test-uuid',
            'schemaId' => 1,
            'hook' => ['id' => 'hook-1', 'engine' => 'n8n', 'workflowId' => 'wf-1'],
            'attempt' => 5,
        ]);
    }

    public function testRunReQueuesOnFailureBelowMaxRetries(): void
    {
        $object = new ObjectEntity();
        $schema = new Schema();

        $this->objectEntityMapper->method('find')->willReturn($object);
        $this->schemaMapper->method('find')->willReturn($schema);
        $this->engineRegistry->method('getEnginesByType')
            ->willThrowException(new Exception('Engine unavailable'));

        $this->jobList->expects($this->once())
            ->method('add')
            ->with(
                HookRetryJob::class,
                $this->callback(function ($arg) {
                    return $arg['attempt'] === 3;
                })
            );

        $method = new \ReflectionMethod($this->job, 'run');
        $method->setAccessible(true);
        $method->invoke($this->job, [
            'objectId' => 'test-uuid',
            'schemaId' => 1,
            'hook' => ['id' => 'hook-1', 'engine' => 'n8n', 'workflowId' => 'wf-1'],
            'attempt' => 2,
        ]);
    }
}
