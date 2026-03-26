<?php

namespace Unit\Db;

use OCA\OpenRegister\Db\ScheduledWorkflow;
use PHPUnit\Framework\TestCase;

class ScheduledWorkflowTest extends TestCase
{
    private ScheduledWorkflow $entity;

    protected function setUp(): void
    {
        $this->entity = new ScheduledWorkflow();
    }

    public function testConstructorRegistersFieldTypes(): void
    {
        $fieldTypes = $this->entity->getFieldTypes();

        $this->assertSame('string', $fieldTypes['uuid']);
        $this->assertSame('string', $fieldTypes['name']);
        $this->assertSame('string', $fieldTypes['engine']);
        $this->assertSame('string', $fieldTypes['workflowId']);
        $this->assertSame('integer', $fieldTypes['registerId']);
        $this->assertSame('integer', $fieldTypes['schemaId']);
        $this->assertSame('integer', $fieldTypes['intervalSec']);
        $this->assertSame('boolean', $fieldTypes['enabled']);
        $this->assertSame('string', $fieldTypes['payload']);
        $this->assertSame('datetime', $fieldTypes['lastRun']);
        $this->assertSame('string', $fieldTypes['lastStatus']);
    }

    public function testDefaultValues(): void
    {
        $this->assertSame(86400, $this->entity->getIntervalSec());
        $this->assertTrue($this->entity->getEnabled());
        $this->assertNull($this->entity->getLastRun());
    }

    public function testHydrate(): void
    {
        $this->entity->hydrate([
            'name'        => 'Test Schedule',
            'engine'      => 'n8n',
            'workflowId'  => 'wf-123',
            'intervalSec' => 3600,
            'enabled'     => false,
        ]);

        $this->assertSame('Test Schedule', $this->entity->getName());
        $this->assertSame('n8n', $this->entity->getEngine());
        $this->assertSame('wf-123', $this->entity->getWorkflowId());
        $this->assertSame(3600, $this->entity->getIntervalSec());
        $this->assertFalse($this->entity->getEnabled());
    }

    public function testJsonSerializeDecodesPayload(): void
    {
        $this->entity->hydrate([
            'uuid'    => 'sched-1',
            'name'    => 'Test',
            'engine'  => 'n8n',
            'workflowId' => 'wf-1',
            'payload' => json_encode(['filter' => ['status' => 'active']]),
        ]);

        $json = $this->entity->jsonSerialize();

        $this->assertIsArray($json['payload']);
        $this->assertSame('active', $json['payload']['filter']['status']);
    }
}
