<?php

/**
 * ScheduledNotificationJobTest
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/notificatie-engine/tasks.md#task-5
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\BackgroundJob;

use OCA\OpenRegister\BackgroundJob\ScheduledNotificationJob;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Notification\AnnotationNotificationDispatcher;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for ScheduledNotificationJob.
 */
class ScheduledNotificationJobTest extends TestCase
{

    private ITimeFactory&MockObject $time;
    private SchemaMapper&MockObject $schemaMapper;
    private AnnotationNotificationDispatcher&MockObject $dispatcher;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->time         = $this->createMock(ITimeFactory::class);
        $this->schemaMapper = $this->createMock(SchemaMapper::class);
        $this->dispatcher   = $this->createMock(AnnotationNotificationDispatcher::class);
        $this->logger       = $this->createMock(LoggerInterface::class);
    }

    private function makeJob(): ScheduledNotificationJob
    {
        return new ScheduledNotificationJob(
            time: $this->time,
            schemaMapper: $this->schemaMapper,
            dispatcher: $this->dispatcher,
            logger: $this->logger
        );
    }

    private function makeSchema(array $configuration=[]): Schema&MockObject
    {
        $schema = $this->createMock(Schema::class);
        $schema->method('getId')->willReturn(1);
        $schema->method('getConfiguration')->willReturn($configuration);
        return $schema;
    }

    /**
     * Job runs without error when no schemas exist.
     */
    public function testRunsWithNoSchemas(): void
    {
        $this->schemaMapper->method('findAll')->willReturn([]);
        $this->dispatcher->expects(self::never())->method('dispatchScheduled');

        $job = $this->makeJob();
        $job->execute(jobList: $this->createMock(\OCP\BackgroundJob\IJobList::class));
    }

    /**
     * Schemas without notification config are skipped.
     */
    public function testSchemasWithoutNotificationConfigSkipped(): void
    {
        $schema = $this->makeSchema(configuration: []);
        $this->schemaMapper->method('findAll')->willReturn([$schema]);
        $this->dispatcher->expects(self::never())->method('dispatchScheduled');

        $job = $this->makeJob();
        $job->execute(jobList: $this->createMock(\OCP\BackgroundJob\IJobList::class));
    }

    /**
     * Schemas with non-scheduled rules are skipped.
     */
    public function testSchemasWithNonScheduledRulesSkipped(): void
    {
        $schema = $this->makeSchema(configuration: [
            'x-openregister-notifications' => [
                ['trigger' => 'created', 'name' => 'on-create', 'subject' => 'Created'],
            ],
        ]);
        $this->schemaMapper->method('findAll')->willReturn([$schema]);
        $this->dispatcher->expects(self::never())->method('dispatchScheduled');

        $job = $this->makeJob();
        $job->execute(jobList: $this->createMock(\OCP\BackgroundJob\IJobList::class));
    }

    /**
     * SchemaMapper exception is caught and logged.
     */
    public function testMapperExceptionIsCaughtAndLogged(): void
    {
        $this->schemaMapper->method('findAll')->willThrowException(new \RuntimeException('DB down'));
        $this->logger->expects(self::once())->method('error');

        $job = $this->makeJob();
        $job->execute(jobList: $this->createMock(\OCP\BackgroundJob\IJobList::class));
    }

    /**
     * Schema with null configuration is skipped gracefully.
     */
    public function testSchemaWithNullConfigurationSkipped(): void
    {
        $schema = $this->createMock(Schema::class);
        $schema->method('getId')->willReturn(1);
        $schema->method('getConfiguration')->willReturn(null);

        $this->schemaMapper->method('findAll')->willReturn([$schema]);
        $this->dispatcher->expects(self::never())->method('dispatchScheduled');

        $job = $this->makeJob();
        $job->execute(jobList: $this->createMock(\OCP\BackgroundJob\IJobList::class));
    }
}
