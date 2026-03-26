<?php

namespace Unit\Db;

use DateTime;
use OCA\OpenRegister\Db\WorkflowExecution;
use PHPUnit\Framework\TestCase;

class WorkflowExecutionTest extends TestCase
{
    private WorkflowExecution $entity;

    protected function setUp(): void
    {
        $this->entity = new WorkflowExecution();
    }

    public function testConstructorRegistersFieldTypes(): void
    {
        $fieldTypes = $this->entity->getFieldTypes();

        $this->assertSame('string', $fieldTypes['uuid']);
        $this->assertSame('string', $fieldTypes['hookId']);
        $this->assertSame('string', $fieldTypes['eventType']);
        $this->assertSame('string', $fieldTypes['objectUuid']);
        $this->assertSame('integer', $fieldTypes['schemaId']);
        $this->assertSame('integer', $fieldTypes['registerId']);
        $this->assertSame('string', $fieldTypes['engine']);
        $this->assertSame('string', $fieldTypes['workflowId']);
        $this->assertSame('string', $fieldTypes['mode']);
        $this->assertSame('string', $fieldTypes['status']);
        $this->assertSame('integer', $fieldTypes['durationMs']);
        $this->assertSame('string', $fieldTypes['errors']);
        $this->assertSame('string', $fieldTypes['metadata']);
        $this->assertSame('string', $fieldTypes['payload']);
        $this->assertSame('datetime', $fieldTypes['executedAt']);
    }

    public function testDefaultValues(): void
    {
        $this->assertNull($this->entity->getUuid());
        $this->assertNull($this->entity->getHookId());
        $this->assertSame('sync', $this->entity->getMode());
        $this->assertSame(0, $this->entity->getDurationMs());
        $this->assertNull($this->entity->getErrors());
    }

    public function testHydrate(): void
    {
        $data = [
            'uuid'       => 'test-uuid',
            'hookId'     => 'validate-kvk',
            'eventType'  => 'creating',
            'objectUuid' => 'obj-123',
            'schemaId'   => 12,
            'registerId' => 5,
            'engine'     => 'n8n',
            'workflowId' => 'kvk-validator',
            'mode'       => 'async',
            'status'     => 'approved',
            'durationMs' => 45,
        ];

        $this->entity->hydrate($data);

        $this->assertSame('test-uuid', $this->entity->getUuid());
        $this->assertSame('validate-kvk', $this->entity->getHookId());
        $this->assertSame('creating', $this->entity->getEventType());
        $this->assertSame('obj-123', $this->entity->getObjectUuid());
        $this->assertSame(12, $this->entity->getSchemaId());
        $this->assertSame(5, $this->entity->getRegisterId());
        $this->assertSame('n8n', $this->entity->getEngine());
        $this->assertSame('kvk-validator', $this->entity->getWorkflowId());
        $this->assertSame('async', $this->entity->getMode());
        $this->assertSame('approved', $this->entity->getStatus());
        $this->assertSame(45, $this->entity->getDurationMs());
    }

    public function testJsonSerializeDecodesJsonFields(): void
    {
        $this->entity->hydrate([
            'uuid'       => 'exec-1',
            'hookId'     => 'hook1',
            'eventType'  => 'creating',
            'objectUuid' => 'obj-1',
            'engine'     => 'n8n',
            'workflowId' => 'wf-1',
            'status'     => 'error',
            'errors'     => json_encode([['message' => 'timeout']]),
            'metadata'   => json_encode(['key' => 'value']),
        ]);

        $json = $this->entity->jsonSerialize();

        $this->assertSame('exec-1', $json['uuid']);
        $this->assertIsArray($json['errors']);
        $this->assertSame('timeout', $json['errors'][0]['message']);
        $this->assertIsArray($json['metadata']);
        $this->assertSame('value', $json['metadata']['key']);
    }

    public function testJsonSerializeNullJsonFieldsReturnNull(): void
    {
        $this->entity->hydrate([
            'uuid'       => 'exec-2',
            'hookId'     => 'hook1',
            'eventType'  => 'creating',
            'objectUuid' => 'obj-2',
            'engine'     => 'n8n',
            'workflowId' => 'wf-1',
            'status'     => 'approved',
        ]);

        $json = $this->entity->jsonSerialize();

        $this->assertNull($json['errors']);
        $this->assertNull($json['metadata']);
        $this->assertNull($json['payload']);
    }
}
