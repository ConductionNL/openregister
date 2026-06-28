<?php

/**
 * Unit tests for the declarative flow engine (FlowActionService).
 *
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace Unit\Service\Flow;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\CalendarEventService;
use OCA\OpenRegister\Service\Flow\FlowActionService;
use OCP\IConfig;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class FlowActionServiceTest extends TestCase
{
    private FlowActionService $service;
    private $schemaMapper;
    private $calendar;
    private $mailer;
    private $config;
    private $logger;

    protected function setUp(): void
    {
        $this->schemaMapper = $this->createMock(SchemaMapper::class);
        $this->calendar     = $this->createMock(CalendarEventService::class);
        $this->mailer       = $this->createMock(IMailer::class);
        $this->config       = $this->createMock(IConfig::class);
        $this->logger       = $this->createMock(LoggerInterface::class);

        $this->config->method('getSystemValue')->willReturnCallback(
            fn ($key, $default = null) => $default
        );
        $this->mailer->method('createMessage')->willReturn($this->createMock(IMessage::class));

        $this->service = new FlowActionService(
            $this->schemaMapper,
            $this->calendar,
            $this->mailer,
            $this->config,
            $this->logger
        );
    }

    /**
     * Build an ObjectEntity mock with the given data + identity.
     *
     * @param array<string,mixed> $data The object's data.
     */
    private function object(array $data, string $name = 'Rex'): ObjectEntity
    {
        // A real entity — ObjectEntity's getters are magic (__call), so they
        // cannot be stubbed on a mock; set fields and let the getters read them.
        $object = new ObjectEntity();
        $object->setRegister('1');
        $object->setSchema('10');
        $object->setUuid('uuid-1');
        $object->setName($name);
        $object->setObject($data);
        return $object;
    }

    /**
     * Stub the schema lookup to return a schema carrying the given flows.
     *
     * @param array<int,array> $flows The x-openregister-flows config.
     */
    private function schemaWithFlows(array $flows): void
    {
        $schema = $this->createMock(Schema::class);
        $schema->method('getConfiguration')->willReturn(['x-openregister-flows' => $flows]);
        $this->schemaMapper->method('find')->willReturn($schema);
    }

    public function testCreatedFlowRunsCalendarAndEmailWithInterpolation(): void
    {
        $this->schemaWithFlows([
            [
                'name'    => 'new-pet',
                'trigger' => 'created',
                'actions' => [
                    ['type' => 'calendar-event', 'summary' => 'Inspect {{name}}', 'description' => 'A {{species}}'],
                    ['type' => 'email', 'to' => 'vet@example.test', 'subject' => 'New {{name}}', 'body' => 'hi'],
                ],
            ],
        ]);

        // Calendar event receives the interpolated summary/description.
        $this->calendar->expects($this->once())
            ->method('createEvent')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->callback(function (array $data): bool {
                    return $data['summary'] === 'Inspect Rex'
                        && $data['description'] === 'A Dog';
                })
            );

        // Email is dispatched.
        $this->mailer->expects($this->once())->method('send');

        $this->service->run($this->object(['name' => 'Rex', 'species' => 'Dog']), 'created');
    }

    public function testTriggerMismatchRunsNothing(): void
    {
        $this->schemaWithFlows([
            ['name' => 'x', 'trigger' => 'created', 'actions' => [['type' => 'email', 'to' => 'a@b.test']]],
        ]);
        $this->calendar->expects($this->never())->method('createEvent');
        $this->mailer->expects($this->never())->method('send');

        $this->service->run($this->object(['name' => 'Rex']), 'updated');
    }

    public function testNoFlowsRunsNothing(): void
    {
        $schema = $this->createMock(Schema::class);
        $schema->method('getConfiguration')->willReturn([]);
        $this->schemaMapper->method('find')->willReturn($schema);
        $this->calendar->expects($this->never())->method('createEvent');
        $this->mailer->expects($this->never())->method('send');

        $this->service->run($this->object(['name' => 'Rex']), 'created');
    }

    public function testCalendarFailureDoesNotBlockEmailAndNeverThrows(): void
    {
        $this->schemaWithFlows([
            [
                'name'    => 'x',
                'trigger' => 'created',
                'actions' => [
                    ['type' => 'calendar-event', 'summary' => 'boom'],
                    ['type' => 'email', 'to' => 'vet@example.test'],
                ],
            ],
        ]);
        $this->calendar->method('createEvent')->willThrowException(new \RuntimeException('calendar down'));
        // The email still runs despite the calendar action throwing.
        $this->mailer->expects($this->once())->method('send');

        // Must not throw into the save path.
        $this->service->run($this->object(['name' => 'Rex']), 'created');
        $this->addToAssertionCount(1);
    }

    public function testUnknownActionTypeIsSkippedAndLogged(): void
    {
        $this->schemaWithFlows([
            ['name' => 'x', 'trigger' => 'created', 'actions' => [['type' => 'sms', 'to' => '+31600000000']]],
        ]);
        $this->calendar->expects($this->never())->method('createEvent');
        $this->mailer->expects($this->never())->method('send');
        $this->logger->expects($this->atLeastOnce())->method('warning');

        $this->service->run($this->object(['name' => 'Rex']), 'created');
    }

    public function testEmailWithEmptyRecipientIsSkipped(): void
    {
        $this->schemaWithFlows([
            ['name' => 'x', 'trigger' => 'created', 'actions' => [['type' => 'email', 'to' => '', 'subject' => 's']]],
        ]);
        $this->mailer->expects($this->never())->method('send');

        $this->service->run($this->object(['name' => 'Rex']), 'created');
    }
}
